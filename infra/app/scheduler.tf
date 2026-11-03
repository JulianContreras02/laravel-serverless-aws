# The legacy crontab, replaced: EventBridge Scheduler runs Laravel's
# `schedule:run` every minute and routes/console.php decides what is due.

data "aws_iam_policy_document" "scheduler_assume" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["scheduler.amazonaws.com"]
    }

    condition {
      test     = "StringEquals"
      variable = "aws:SourceAccount"
      values   = [local.account_id]
    }
  }
}

resource "aws_iam_role" "scheduler" {
  name                 = "${local.name}-scheduler"
  assume_role_policy   = data.aws_iam_policy_document.scheduler_assume.json
  permissions_boundary = local.permissions_boundary_arn
}

data "aws_iam_policy_document" "scheduler_invoke" {
  statement {
    actions   = ["lambda:InvokeFunction"]
    resources = [aws_lambda_function.console.arn]
  }
}

resource "aws_iam_role_policy" "scheduler_invoke" {
  name   = "invoke-artisan"
  role   = aws_iam_role.scheduler.id
  policy = data.aws_iam_policy_document.scheduler_invoke.json
}

resource "aws_scheduler_schedule" "schedule_run" {
  name                = "${local.name}-schedule-run"
  description         = "php artisan schedule:run"
  schedule_expression = "rate(1 minute)"

  flexible_time_window {
    mode = "OFF"
  }

  target {
    arn      = aws_lambda_function.console.arn
    role_arn = aws_iam_role.scheduler.arn
    # Bref's console runtime takes the Artisan arguments as a JSON string.
    input = jsonencode("schedule:run")

    retry_policy {
      maximum_retry_attempts = 0
    }
  }
}
