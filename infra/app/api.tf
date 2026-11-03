# HTTP API (not REST API): about 70% cheaper per request and enough for a
# proxy to one function. Throttling here is also the cost guard.

resource "aws_apigatewayv2_api" "http" {
  name          = local.name
  protocol_type = "HTTP"
  description   = "Short links API and redirects"
}

resource "aws_apigatewayv2_integration" "web" {
  api_id                 = aws_apigatewayv2_api.http.id
  integration_type       = "AWS_PROXY"
  integration_uri        = aws_lambda_function.web.invoke_arn
  payload_format_version = "2.0"
  timeout_milliseconds   = 29000
}

resource "aws_apigatewayv2_route" "default" {
  api_id    = aws_apigatewayv2_api.http.id
  route_key = "$default"
  target    = "integrations/${aws_apigatewayv2_integration.web.id}"
}

resource "aws_cloudwatch_log_group" "api_access" {
  name              = "/aws/apigateway/${local.name}"
  retention_in_days = var.log_retention_days
}

resource "aws_apigatewayv2_stage" "default" {
  api_id      = aws_apigatewayv2_api.http.id
  name        = "$default"
  auto_deploy = true

  default_route_settings {
    throttling_rate_limit  = var.api_throttling_rate_limit
    throttling_burst_limit = var.api_throttling_burst_limit
  }

  # JSON access logs. requestId is the same id the application logs and
  # returns as X-Request-Id. Client IPs are deliberately not logged.
  access_log_settings {
    destination_arn = aws_cloudwatch_log_group.api_access.arn
    format = jsonencode({
      requestId          = "$context.requestId"
      time               = "$context.requestTime"
      method             = "$context.httpMethod"
      path               = "$context.path"
      status             = "$context.status"
      latencyMs          = "$context.responseLatency"
      integrationMs      = "$context.integrationLatency"
      integrationStatus  = "$context.integrationStatus"
      integrationError   = "$context.integrationErrorMessage"
      responseLength     = "$context.responseLength"
      userAgent          = "$context.identity.userAgent"
      throttlingOrErrors = "$context.error.message"
    })
  }
}

resource "aws_lambda_permission" "api" {
  statement_id  = "AllowHttpApiInvoke"
  action        = "lambda:InvokeFunction"
  function_name = aws_lambda_function.web.function_name
  principal     = "apigateway.amazonaws.com"
  source_arn    = "${aws_apigatewayv2_api.http.execution_arn}/*"
}
