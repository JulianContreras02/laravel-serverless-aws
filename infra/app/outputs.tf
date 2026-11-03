output "api_url" {
  description = "Base URL of the API and of the short links."
  value       = aws_apigatewayv2_api.http.api_endpoint
}

output "web_function_name" {
  value = aws_lambda_function.web.function_name
}

output "artisan_function_name" {
  description = "Invoke with a JSON string payload, e.g. '\"migrate --force\"'."
  value       = aws_lambda_function.console.function_name
}

output "database_endpoint" {
  value = aws_db_instance.main.address
}

output "dashboard_url" {
  value = "https://${var.aws_region}.console.aws.amazon.com/cloudwatch/home?region=${var.aws_region}#dashboards/dashboard/${aws_cloudwatch_dashboard.main.dashboard_name}"
}

output "api_token" {
  description = "Bearer token of the management API: terraform output -raw api_token"
  value       = random_password.api_token.result
  sensitive   = true
}
