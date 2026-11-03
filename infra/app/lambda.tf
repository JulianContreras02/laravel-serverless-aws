# Laravel's APP_KEY: 32 random bytes, base64-encoded.
resource "random_bytes" "app_key" {
  length = 32
}

resource "random_password" "api_token" {
  length  = 48
  special = false
}

locals {
  # Secrets end up in the Terraform state (encrypted S3, restricted access) and
  # in the Lambda configuration (encrypted at rest with KMS). Reading them from
  # Secrets Manager at runtime would need a VPC endpoint (~7 USD/month per AZ).
  app_environment = {
    APP_NAME  = "Shortlinks"
    APP_ENV   = local.metrics_environment
    APP_DEBUG = "false"
    APP_KEY   = "base64:${random_bytes.app_key.base64}"
    APP_URL   = aws_apigatewayv2_api.http.api_endpoint
    LOG_LEVEL = "info"

    DB_CONNECTION = "mysql"
    DB_HOST       = aws_db_instance.main.address
    DB_PORT       = tostring(aws_db_instance.main.port)
    DB_DATABASE   = aws_db_instance.main.db_name
    DB_USERNAME   = aws_db_instance.main.username
    DB_PASSWORD   = random_password.db.result
    # Packaged by scripts/package-lambda.sh: TLS with certificate verification.
    MYSQL_ATTR_SSL_CA = "/var/task/rds-ca-bundle.pem"

    # In-memory and /tmp caches are per instance; the database is shared.
    CACHE_STORE      = "database"
    SESSION_DRIVER   = "array"
    QUEUE_CONNECTION = "sync"

    API_TOKEN            = random_password.api_token.result
    CLICK_RETENTION_DAYS = tostring(var.click_retention_days)
    METRICS_NAMESPACE    = local.metrics_namespace

    # Bref's anonymous usage ping is UDP to the internet, which this VPC has no route to.
    BREF_PING_DISABLE = "1"
  }

  vpc_config = {
    subnet_ids         = aws_subnet.private[*].id
    security_group_ids = [aws_security_group.lambda.id]
  }
}

# --- Execution role ---------------------------------------------------------

data "aws_iam_policy_document" "lambda_assume" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["lambda.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "lambda" {
  name                 = "${local.name}-lambda"
  assume_role_policy   = data.aws_iam_policy_document.lambda_assume.json
  permissions_boundary = local.permissions_boundary_arn
}

# Create and delete the network interfaces that place the functions in the VPC.
resource "aws_iam_role_policy_attachment" "lambda_vpc" {
  role       = aws_iam_role.lambda.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSLambdaENIManagementAccess"
}

data "aws_iam_policy_document" "lambda_logs" {
  statement {
    actions = ["logs:CreateLogStream", "logs:PutLogEvents"]
    resources = [
      "${aws_cloudwatch_log_group.web.arn}:*",
      "${aws_cloudwatch_log_group.console.arn}:*",
    ]
  }
}

resource "aws_iam_role_policy" "lambda_logs" {
  name   = "logs"
  role   = aws_iam_role.lambda.id
  policy = data.aws_iam_policy_document.lambda_logs.json
}

# --- HTTP function (PHP-FPM) -------------------------------------------------

resource "aws_cloudwatch_log_group" "web" {
  name              = "/aws/lambda/${local.name}-web"
  retention_in_days = var.log_retention_days
}

resource "aws_lambda_function" "web" {
  function_name = "${local.name}-web"
  description   = "Laravel HTTP API (Bref FPM runtime)"
  role          = aws_iam_role.lambda.arn

  runtime       = "provided.al2023"
  handler       = "public/index.php"
  architectures = [var.lambda_architecture]
  layers        = [local.bref_layer_arn]

  filename         = var.lambda_package_path
  source_code_hash = filebase64sha256(var.lambda_package_path)

  memory_size = var.web_memory_mb
  # Below API Gateway's 30-second integration limit, so the gateway never times out first.
  timeout                        = 28
  reserved_concurrent_executions = var.web_reserved_concurrency

  environment {
    variables = merge(local.app_environment, { BREF_RUNTIME = "fpm" })
  }

  vpc_config {
    subnet_ids         = local.vpc_config.subnet_ids
    security_group_ids = local.vpc_config.security_group_ids
  }

  logging_config {
    log_format = "Text"
    log_group  = aws_cloudwatch_log_group.web.name
  }

  depends_on = [
    aws_iam_role_policy_attachment.lambda_vpc,
    aws_iam_role_policy.lambda_logs,
  ]
}

# --- Artisan function (scheduler and one-off commands such as migrations) --

resource "aws_cloudwatch_log_group" "console" {
  name              = "/aws/lambda/${local.name}-artisan"
  retention_in_days = var.log_retention_days
}

resource "aws_lambda_function" "console" {
  function_name = "${local.name}-artisan"
  description   = "Laravel Artisan (Bref console runtime): schedule:run, migrations"
  role          = aws_iam_role.lambda.arn

  runtime       = "provided.al2023"
  handler       = "artisan"
  architectures = [var.lambda_architecture]
  layers        = [local.bref_layer_arn]

  filename         = var.lambda_package_path
  source_code_hash = filebase64sha256(var.lambda_package_path)

  memory_size = var.console_memory_mb
  timeout     = 300

  environment {
    variables = merge(local.app_environment, { BREF_RUNTIME = "console" })
  }

  vpc_config {
    subnet_ids         = local.vpc_config.subnet_ids
    security_group_ids = local.vpc_config.security_group_ids
  }

  logging_config {
    log_format = "Text"
    log_group  = aws_cloudwatch_log_group.console.name
  }

  depends_on = [
    aws_iam_role_policy_attachment.lambda_vpc,
    aws_iam_role_policy.lambda_logs,
  ]
}

# The scheduler invokes asynchronously, and Lambda retries failed async
# invocations twice by default. The next minute's run is the retry.
resource "aws_lambda_function_event_invoke_config" "console" {
  function_name                = aws_lambda_function.console.function_name
  maximum_retry_attempts       = 0
  maximum_event_age_in_seconds = 60
}
