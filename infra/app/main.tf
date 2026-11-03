data "aws_caller_identity" "current" {}

data "aws_availability_zones" "available" {
  state = "available"
}

locals {
  name       = "${var.project}-${var.environment}"
  account_id = data.aws_caller_identity.current.account_id

  # RDS needs subnets in two AZs, even for a single-AZ instance.
  azs = slice(data.aws_availability_zones.available.names, 0, 2)

  permissions_boundary_arn = "arn:aws:iam::${local.account_id}:policy/${var.permissions_boundary_name}"

  # Bref 3 publishes one layer per PHP version; BREF_RUNTIME picks fpm or console.
  bref_layer_arn = format(
    "arn:aws:lambda:%s:873528684822:layer:%sphp-84:%d",
    var.aws_region,
    var.lambda_architecture == "arm64" ? "arm-" : "",
    var.bref_layer_version,
  )

  # EMF dimension value, also used by the alarms and the dashboard.
  metrics_namespace   = "Shortlinks"
  metrics_environment = "production"
}
