data "aws_caller_identity" "current" {}

locals {
  account_id = data.aws_caller_identity.current.account_id
  region     = var.aws_region
  prefix     = var.project

  state_bucket  = "${var.project}-tfstate-${local.account_id}"
  boundary_name = "${var.project}-workload-boundary"
  boundary_arn  = "arn:aws:iam::${local.account_id}:policy/${local.boundary_name}"
  oidc_host     = "token.actions.githubusercontent.com"

  github_oidc_provider_arn = var.create_github_oidc_provider ? aws_iam_openid_connect_provider.github[0].arn : data.aws_iam_openid_connect_provider.github[0].arn
}

# -----------------------------------------------------------------------------
# Remote state: versioned, encrypted, private. Locking uses S3 conditional
# writes (use_lockfile), so no DynamoDB table is needed.
# -----------------------------------------------------------------------------

resource "aws_s3_bucket" "state" {
  bucket = local.state_bucket

  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_s3_bucket_versioning" "state" {
  bucket = aws_s3_bucket.state.id

  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "state" {
  bucket = aws_s3_bucket.state.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "state" {
  bucket                  = aws_s3_bucket.state.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_ownership_controls" "state" {
  bucket = aws_s3_bucket.state.id

  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

data "aws_iam_policy_document" "state_bucket" {
  statement {
    sid       = "DenyInsecureTransport"
    effect    = "Deny"
    actions   = ["s3:*"]
    resources = [aws_s3_bucket.state.arn, "${aws_s3_bucket.state.arn}/*"]

    principals {
      type        = "*"
      identifiers = ["*"]
    }

    condition {
      test     = "Bool"
      variable = "aws:SecureTransport"
      values   = ["false"]
    }
  }
}

resource "aws_s3_bucket_policy" "state" {
  bucket = aws_s3_bucket.state.id
  policy = data.aws_iam_policy_document.state_bucket.json
}

# Old state versions are only needed for a while after a bad apply.
resource "aws_s3_bucket_lifecycle_configuration" "state" {
  bucket = aws_s3_bucket.state.id

  rule {
    id     = "expire-old-state-versions"
    status = "Enabled"

    filter {}

    noncurrent_version_expiration {
      noncurrent_days = 90
    }
  }
}

# -----------------------------------------------------------------------------
# GitHub OIDC: the workflow exchanges its short-lived GitHub token for
# temporary AWS credentials. No access keys are stored anywhere.
# -----------------------------------------------------------------------------

resource "aws_iam_openid_connect_provider" "github" {
  count = var.create_github_oidc_provider ? 1 : 0

  url            = "https://${local.oidc_host}"
  client_id_list = ["sts.amazonaws.com"]
}

data "aws_iam_openid_connect_provider" "github" {
  count = var.create_github_oidc_provider ? 0 : 1

  url = "https://${local.oidc_host}"
}

data "aws_iam_policy_document" "deploy_trust" {
  statement {
    actions = ["sts:AssumeRoleWithWebIdentity"]

    principals {
      type        = "Federated"
      identifiers = [local.github_oidc_provider_arn]
    }

    condition {
      test     = "StringEquals"
      variable = "${local.oidc_host}:aud"
      values   = ["sts.amazonaws.com"]
    }

    # Only jobs of this repository running in the protected environment.
    # Branches, forks and pull requests get a different "sub" and are refused.
    condition {
      test     = "StringEquals"
      variable = "${local.oidc_host}:sub"
      values   = ["repo:${var.github_repository}:environment:${var.github_environment}"]
    }
  }
}

resource "aws_iam_role" "deploy" {
  name                 = "${local.prefix}-github-deploy"
  description          = "Assumed by GitHub Actions (OIDC) to deploy ${var.github_repository}"
  assume_role_policy   = data.aws_iam_policy_document.deploy_trust.json
  max_session_duration = 3600
}

# -----------------------------------------------------------------------------
# Permissions boundary for every role the deploy role creates (Lambda and
# Scheduler execution roles). Without it, a role that can create roles and
# pass them to Lambda could create an administrator role and escalate.
# -----------------------------------------------------------------------------

data "aws_iam_policy_document" "workload_boundary" {
  statement {
    sid = "WriteOwnLogs"
    actions = [
      "logs:CreateLogStream",
      "logs:PutLogEvents",
    ]
    resources = ["arn:aws:logs:${local.region}:${local.account_id}:log-group:/aws/lambda/${local.prefix}-*"]
  }

  statement {
    sid = "LambdaVpcNetworking"
    actions = [
      "ec2:CreateNetworkInterface",
      "ec2:DeleteNetworkInterface",
      "ec2:DescribeNetworkInterfaces",
      "ec2:DescribeSubnets",
      "ec2:DescribeSecurityGroups",
      "ec2:DescribeVpcs",
      "ec2:AssignPrivateIpAddresses",
      "ec2:UnassignPrivateIpAddresses",
    ]
    resources = ["*"]
  }

  statement {
    sid       = "InvokeOwnFunctions"
    actions   = ["lambda:InvokeFunction"]
    resources = ["arn:aws:lambda:${local.region}:${local.account_id}:function:${local.prefix}-*"]
  }
}

resource "aws_iam_policy" "workload_boundary" {
  name        = local.boundary_name
  description = "Maximum permissions of the roles created by the ${local.prefix} deploy role"
  policy      = data.aws_iam_policy_document.workload_boundary.json
}

# -----------------------------------------------------------------------------
# What the deploy role may do: manage resources named "<project>-*" in one
# region. Actions that AWS does not scope by resource use "*" with a region
# condition.
# -----------------------------------------------------------------------------

data "aws_iam_policy_document" "deploy" {
  statement {
    sid       = "TerraformStateBucket"
    actions   = ["s3:ListBucket"]
    resources = [aws_s3_bucket.state.arn]
  }

  statement {
    sid       = "TerraformStateObjects"
    actions   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
    resources = ["${aws_s3_bucket.state.arn}/${local.prefix}/*"]
  }

  statement {
    sid       = "LambdaFunctions"
    actions   = ["lambda:*"]
    resources = ["arn:aws:lambda:${local.region}:${local.account_id}:function:${local.prefix}-*"]
  }

  statement {
    sid       = "BrefLayers"
    actions   = ["lambda:GetLayerVersion"]
    resources = ["arn:aws:lambda:${local.region}:873528684822:layer:*"]
  }

  statement {
    sid     = "HttpApi"
    actions = ["apigateway:GET", "apigateway:POST", "apigateway:PUT", "apigateway:PATCH", "apigateway:DELETE", "apigateway:TagResource", "apigateway:UntagResource"]
    resources = [
      "arn:aws:apigateway:${local.region}::/apis",
      "arn:aws:apigateway:${local.region}::/apis/*",
      "arn:aws:apigateway:${local.region}::/tags/*",
    ]
  }

  statement {
    sid = "WorkloadRolesWithBoundary"
    actions = [
      "iam:CreateRole",
      "iam:PutRolePolicy",
      "iam:AttachRolePolicy",
      "iam:DetachRolePolicy",
      "iam:PutRolePermissionsBoundary",
    ]
    resources = ["arn:aws:iam::${local.account_id}:role/${local.prefix}-*"]

    condition {
      test     = "StringEquals"
      variable = "iam:PermissionsBoundary"
      values   = [local.boundary_arn]
    }
  }

  statement {
    sid = "WorkloadRolesManage"
    actions = [
      "iam:GetRole",
      "iam:GetRolePolicy",
      "iam:ListRolePolicies",
      "iam:ListAttachedRolePolicies",
      "iam:ListInstanceProfilesForRole",
      "iam:UpdateRole",
      "iam:UpdateRoleDescription",
      "iam:UpdateAssumeRolePolicy",
      "iam:DeleteRolePolicy",
      "iam:DeleteRole",
      "iam:TagRole",
      "iam:UntagRole",
      "iam:ListRoleTags",
    ]
    resources = ["arn:aws:iam::${local.account_id}:role/${local.prefix}-*"]
  }

  statement {
    sid       = "PassWorkloadRoles"
    actions   = ["iam:PassRole"]
    resources = ["arn:aws:iam::${local.account_id}:role/${local.prefix}-*"]

    condition {
      test     = "StringEquals"
      variable = "iam:PassedToService"
      values   = ["lambda.amazonaws.com", "scheduler.amazonaws.com"]
    }
  }

  statement {
    sid       = "ReadPolicies"
    actions   = ["iam:GetPolicy", "iam:GetPolicyVersion"]
    resources = ["*"]
  }

  statement {
    sid       = "ServiceLinkedRoles"
    actions   = ["iam:CreateServiceLinkedRole"]
    resources = ["*"]

    condition {
      test     = "StringEquals"
      variable = "iam:AWSServiceName"
      values   = ["rds.amazonaws.com", "ops.apigateway.amazonaws.com"]
    }
  }

  statement {
    sid = "Networking"
    actions = [
      "ec2:CreateVpc", "ec2:DeleteVpc", "ec2:ModifyVpcAttribute",
      "ec2:CreateSubnet", "ec2:DeleteSubnet", "ec2:ModifySubnetAttribute",
      "ec2:CreateSecurityGroup", "ec2:DeleteSecurityGroup",
      "ec2:AuthorizeSecurityGroupIngress", "ec2:AuthorizeSecurityGroupEgress",
      "ec2:RevokeSecurityGroupIngress", "ec2:RevokeSecurityGroupEgress",
      "ec2:ModifySecurityGroupRules", "ec2:UpdateSecurityGroupRuleDescriptionsIngress",
      "ec2:UpdateSecurityGroupRuleDescriptionsEgress",
      "ec2:CreateTags", "ec2:DeleteTags", "ec2:Describe*",
    ]
    resources = ["*"]

    condition {
      test     = "StringEquals"
      variable = "aws:RequestedRegion"
      values   = [local.region]
    }
  }

  statement {
    sid     = "Database"
    actions = ["rds:*"]
    resources = [
      "arn:aws:rds:${local.region}:${local.account_id}:db:${local.prefix}-*",
      "arn:aws:rds:${local.region}:${local.account_id}:subgrp:${local.prefix}-*",
      "arn:aws:rds:${local.region}:${local.account_id}:pg:${local.prefix}-*",
      "arn:aws:rds:${local.region}:${local.account_id}:snapshot:${local.prefix}-*",
    ]
  }

  statement {
    sid       = "DatabaseRead"
    actions   = ["rds:Describe*", "rds:ListTagsForResource"]
    resources = ["*"]
  }

  statement {
    sid       = "DatabaseEncryptionWithAwsManagedKey"
    actions   = ["kms:DescribeKey", "kms:CreateGrant"]
    resources = ["*"]

    condition {
      test     = "StringEquals"
      variable = "kms:ViaService"
      values   = ["rds.${local.region}.amazonaws.com"]
    }
  }

  statement {
    sid = "LogGroups"
    actions = [
      "logs:CreateLogGroup", "logs:DeleteLogGroup",
      "logs:PutRetentionPolicy", "logs:DeleteRetentionPolicy",
      "logs:TagResource", "logs:UntagResource", "logs:ListTagsForResource",
      "logs:TagLogGroup", "logs:ListTagsLogGroup",
    ]
    resources = [
      "arn:aws:logs:${local.region}:${local.account_id}:log-group:/aws/lambda/${local.prefix}-*",
      "arn:aws:logs:${local.region}:${local.account_id}:log-group:/aws/apigateway/${local.prefix}-*",
      "arn:aws:logs:${local.region}:${local.account_id}:log-group:/aws/rds/instance/${local.prefix}-*",
    ]
  }

  # API Gateway access logging and log-group listing are not resource-scoped.
  statement {
    sid = "LogDelivery"
    actions = [
      "logs:DescribeLogGroups",
      "logs:CreateLogDelivery", "logs:GetLogDelivery", "logs:UpdateLogDelivery",
      "logs:DeleteLogDelivery", "logs:ListLogDeliveries",
      "logs:PutResourcePolicy", "logs:DescribeResourcePolicies",
    ]
    resources = ["*"]
  }

  statement {
    sid = "AlarmsAndDashboard"
    actions = [
      "cloudwatch:PutMetricAlarm", "cloudwatch:DeleteAlarms", "cloudwatch:DescribeAlarms",
      "cloudwatch:TagResource", "cloudwatch:UntagResource", "cloudwatch:ListTagsForResource",
      "cloudwatch:PutDashboard", "cloudwatch:GetDashboard", "cloudwatch:DeleteDashboards",
    ]
    resources = [
      "arn:aws:cloudwatch:${local.region}:${local.account_id}:alarm:${local.prefix}-*",
      "arn:aws:cloudwatch::${local.account_id}:dashboard/${local.prefix}-*",
    ]
  }

  statement {
    sid       = "AlarmTopic"
    actions   = ["sns:*"]
    resources = ["arn:aws:sns:${local.region}:${local.account_id}:${local.prefix}-*"]
  }

  statement {
    sid       = "Schedules"
    actions   = ["scheduler:*"]
    resources = ["arn:aws:scheduler:${local.region}:${local.account_id}:schedule/default/${local.prefix}-*"]
  }

  statement {
    sid       = "Identity"
    actions   = ["sts:GetCallerIdentity"]
    resources = ["*"]
  }
}

resource "aws_iam_role_policy" "deploy" {
  name   = "deploy-${local.prefix}"
  role   = aws_iam_role.deploy.id
  policy = data.aws_iam_policy_document.deploy.json
}
