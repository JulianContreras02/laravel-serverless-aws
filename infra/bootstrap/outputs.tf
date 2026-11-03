output "deploy_role_arn" {
  description = "GitHub variable AWS_DEPLOY_ROLE_ARN."
  value       = aws_iam_role.deploy.arn
}

output "state_bucket" {
  description = "GitHub variable TF_STATE_BUCKET."
  value       = aws_s3_bucket.state.bucket
}

output "permissions_boundary_arn" {
  description = "Boundary the application stack attaches to the roles it creates."
  value       = aws_iam_policy.workload_boundary.arn
}
