resource "aws_sns_topic" "alarms" {
  name = "${local.name}-alarms"
}

resource "aws_sns_topic_subscription" "alarm_email" {
  count = var.alarm_email == "" ? 0 : 1

  topic_arn = aws_sns_topic.alarms.arn
  protocol  = "email"
  endpoint  = var.alarm_email
}

locals {
  api_dimensions = {
    ApiId = aws_apigatewayv2_api.http.id
    Stage = aws_apigatewayv2_stage.default.name
  }

  # Eight alarms: CloudWatch includes ten standard alarms per month for free.
  alarms = {
    "api-5xx" = {
      description = "The API returned 5xx responses."
      namespace   = "AWS/ApiGateway"
      metric      = "5xx"
      dimensions  = local.api_dimensions
      statistic   = "Sum"
      threshold   = 5
      periods     = 1
    }
    "api-latency-p95" = {
      description = "p95 latency above 1 s for 15 minutes."
      namespace   = "AWS/ApiGateway"
      metric      = "Latency"
      dimensions  = local.api_dimensions
      statistic   = "p95"
      threshold   = 1000
      periods     = 3
    }
    "web-throttles" = {
      description = "The HTTP function hit its concurrency limit."
      namespace   = "AWS/Lambda"
      metric      = "Throttles"
      dimensions  = { FunctionName = aws_lambda_function.web.function_name }
      statistic   = "Sum"
      threshold   = 1
      periods     = 1
    }
    "artisan-errors" = {
      description = "A scheduled or one-off Artisan invocation failed or timed out."
      namespace   = "AWS/Lambda"
      metric      = "Errors"
      dimensions  = { FunctionName = aws_lambda_function.console.function_name }
      statistic   = "Sum"
      threshold   = 1
      periods     = 1
    }
    "clicks-dropped" = {
      description = "Redirects succeeded but their clicks could not be stored."
      namespace   = local.metrics_namespace
      metric      = "ClickDropped"
      dimensions  = { Environment = local.metrics_environment }
      statistic   = "Sum"
      threshold   = 1
      periods     = 1
    }
    "db-cpu" = {
      description = "Database CPU above 80% for 15 minutes."
      namespace   = "AWS/RDS"
      metric      = "CPUUtilization"
      dimensions  = { DBInstanceIdentifier = aws_db_instance.main.identifier }
      statistic   = "Average"
      threshold   = 80
      periods     = 3
    }
  }
}

resource "aws_cloudwatch_metric_alarm" "this" {
  for_each = local.alarms

  alarm_name          = "${local.name}-${each.key}"
  alarm_description   = each.value.description
  namespace           = each.value.namespace
  metric_name         = each.value.metric
  dimensions          = each.value.dimensions
  statistic           = startswith(each.value.statistic, "p") ? null : each.value.statistic
  extended_statistic  = startswith(each.value.statistic, "p") ? each.value.statistic : null
  period              = 300
  evaluation_periods  = each.value.periods
  datapoints_to_alarm = each.value.periods
  threshold           = each.value.threshold
  comparison_operator = "GreaterThanOrEqualToThreshold"
  treat_missing_data  = "notBreaching"
  alarm_actions       = [aws_sns_topic.alarms.arn]
  ok_actions          = [aws_sns_topic.alarms.arn]
}

resource "aws_cloudwatch_metric_alarm" "db_free_storage" {
  alarm_name          = "${local.name}-db-free-storage"
  alarm_description   = "Less than 2 GB of database storage left."
  namespace           = "AWS/RDS"
  metric_name         = "FreeStorageSpace"
  dimensions          = { DBInstanceIdentifier = aws_db_instance.main.identifier }
  statistic           = "Minimum"
  period              = 300
  evaluation_periods  = 1
  threshold           = 2 * 1024 * 1024 * 1024
  comparison_operator = "LessThanThreshold"
  treat_missing_data  = "breaching"
  alarm_actions       = [aws_sns_topic.alarms.arn]
  ok_actions          = [aws_sns_topic.alarms.arn]
}

# Dead man's switch: the rollup reports its duration every 5 minutes. If no
# datapoint arrives for 15 minutes, the scheduler or the command is broken,
# whatever the reason, including failures that raise no error at all.
resource "aws_cloudwatch_metric_alarm" "rollup_stalled" {
  alarm_name          = "${local.name}-rollup-stalled"
  alarm_description   = "stats:rollup has not reported for 15 minutes."
  namespace           = local.metrics_namespace
  metric_name         = "RollupDuration"
  dimensions          = { Environment = local.metrics_environment }
  statistic           = "SampleCount"
  period              = 900
  evaluation_periods  = 1
  threshold           = 1
  comparison_operator = "LessThanThreshold"
  treat_missing_data  = "breaching"
  alarm_actions       = [aws_sns_topic.alarms.arn]
  ok_actions          = [aws_sns_topic.alarms.arn]
}

resource "aws_cloudwatch_dashboard" "main" {
  dashboard_name = local.name

  dashboard_body = jsonencode({
    widgets = [
      {
        type = "metric", x = 0, y = 0, width = 12, height = 6
        properties = {
          title  = "API requests and errors"
          region = var.aws_region
          stat   = "Sum"
          period = 300
          metrics = [
            ["AWS/ApiGateway", "Count", "ApiId", local.api_dimensions.ApiId, "Stage", local.api_dimensions.Stage],
            [".", "4xx", ".", ".", ".", "."],
            [".", "5xx", ".", ".", ".", "."],
          ]
        }
      },
      {
        type = "metric", x = 12, y = 0, width = 12, height = 6
        properties = {
          title  = "API latency (ms)"
          region = var.aws_region
          period = 300
          metrics = [
            ["AWS/ApiGateway", "Latency", "ApiId", local.api_dimensions.ApiId, "Stage", local.api_dimensions.Stage, { stat = "p50" }],
            ["...", { stat = "p95" }],
            ["...", { stat = "p99" }],
          ]
        }
      },
      {
        type = "metric", x = 0, y = 6, width = 12, height = 6
        properties = {
          title  = "HTTP function"
          region = var.aws_region
          period = 300
          metrics = [
            ["AWS/Lambda", "Duration", "FunctionName", aws_lambda_function.web.function_name, { stat = "p95" }],
            [".", "ConcurrentExecutions", ".", ".", { stat = "Maximum", yAxis = "right" }],
            [".", "Errors", ".", ".", { stat = "Sum", yAxis = "right" }],
            [".", "Throttles", ".", ".", { stat = "Sum", yAxis = "right" }],
          ]
        }
      },
      {
        type = "metric", x = 12, y = 6, width = 12, height = 6
        properties = {
          title  = "Database"
          region = var.aws_region
          period = 300
          metrics = [
            ["AWS/RDS", "CPUUtilization", "DBInstanceIdentifier", aws_db_instance.main.identifier, { stat = "Average" }],
            [".", "DatabaseConnections", ".", ".", { stat = "Maximum", yAxis = "right" }],
          ]
        }
      },
      {
        type = "metric", x = 0, y = 12, width = 12, height = 6
        properties = {
          title  = "Business metrics (EMF)"
          region = var.aws_region
          stat   = "Sum"
          period = 300
          metrics = [
            [local.metrics_namespace, "RedirectServed", "Environment", local.metrics_environment],
            [".", "LinkCreated", ".", "."],
            [".", "ClickDropped", ".", "."],
          ]
        }
      },
      {
        type = "metric", x = 12, y = 12, width = 12, height = 6
        properties = {
          title  = "Rollup duration (ms)"
          region = var.aws_region
          period = 300
          metrics = [
            [local.metrics_namespace, "RollupDuration", "Environment", local.metrics_environment, { stat = "Maximum" }],
          ]
        }
      },
    ]
  })
}
