# Private subnets only: no internet gateway and no NAT gateway (about 32 USD
# per month each, more than the rest of the stack together).
#
# Lambda reaches RDS inside the VPC. Logs and EMF metrics do not need a route
# out of the subnet: the Lambda service ships stdout/stderr to CloudWatch on
# its own. The trade-off: the functions cannot call AWS APIs or the internet
# (no SQS, no Secrets Manager at runtime); see the README.

resource "aws_vpc" "main" {
  cidr_block           = var.vpc_cidr
  enable_dns_support   = true
  enable_dns_hostnames = true

  tags = {
    Name = local.name
  }
}

resource "aws_subnet" "private" {
  count = length(local.azs)

  vpc_id            = aws_vpc.main.id
  availability_zone = local.azs[count.index]
  cidr_block        = cidrsubnet(var.vpc_cidr, 8, count.index)

  tags = {
    Name = "${local.name}-private-${local.azs[count.index]}"
  }
}

# Strip the allow-all rules of the VPC's default security group.
resource "aws_default_security_group" "default" {
  vpc_id = aws_vpc.main.id
}

resource "aws_security_group" "lambda" {
  name        = "${local.name}-lambda"
  description = "Lambda functions: outbound to the database only"
  vpc_id      = aws_vpc.main.id
}

resource "aws_security_group" "database" {
  name        = "${local.name}-database"
  description = "RDS MySQL: inbound from the Lambda functions only"
  vpc_id      = aws_vpc.main.id
}

resource "aws_vpc_security_group_egress_rule" "lambda_to_database" {
  security_group_id            = aws_security_group.lambda.id
  referenced_security_group_id = aws_security_group.database.id
  ip_protocol                  = "tcp"
  from_port                    = 3306
  to_port                      = 3306
  description                  = "MySQL"
}

resource "aws_vpc_security_group_ingress_rule" "database_from_lambda" {
  security_group_id            = aws_security_group.database.id
  referenced_security_group_id = aws_security_group.lambda.id
  ip_protocol                  = "tcp"
  from_port                    = 3306
  to_port                      = 3306
  description                  = "MySQL from Lambda"
}
