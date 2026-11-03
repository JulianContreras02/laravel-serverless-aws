variable "project" {
  description = "Name prefix of every resource. Must match the bootstrap stack."
  type        = string
  default     = "shortlinks"
}

variable "environment" {
  description = "Environment name, used in resource names."
  type        = string
  default     = "prod"
}

variable "aws_region" {
  type    = string
  default = "us-east-1"
}

# --- Lambda ---------------------------------------------------------------

variable "lambda_package_path" {
  description = "Zip built by scripts/package-lambda.sh."
  type        = string
  default     = "../../build/lambda.zip"
}

variable "bref_layer_version" {
  description = "Bref php-84 layer version. The deploy workflow reads it from vendor/bref/bref/layers.json, so it always matches the Bref version in composer.lock."
  type        = number
}

variable "lambda_architecture" {
  description = "arm64 (Graviton) costs about 20% less per GB-second than x86_64."
  type        = string
  default     = "arm64"

  validation {
    condition     = contains(["arm64", "x86_64"], var.lambda_architecture)
    error_message = "lambda_architecture must be arm64 or x86_64."
  }
}

variable "web_memory_mb" {
  description = "Memory of the HTTP function. Lambda allocates CPU in proportion to memory."
  type        = number
  default     = 1024
}

variable "console_memory_mb" {
  description = "Memory of the Artisan function (scheduler and migrations)."
  type        = number
  default     = 512
}

variable "web_reserved_concurrency" {
  description = "Caps concurrent HTTP executions so a traffic spike cannot exhaust the database connections. -1 means no reservation (new AWS accounts often have a concurrency quota of 10, which cannot be reserved)."
  type        = number
  default     = -1
}

variable "permissions_boundary_name" {
  description = "Boundary created by the bootstrap stack; the deploy role can only create roles that carry it."
  type        = string
  default     = "shortlinks-workload-boundary"
}

# --- API Gateway ----------------------------------------------------------

variable "api_throttling_rate_limit" {
  description = "Steady-state requests per second across the API. Also a cost guard against floods."
  type        = number
  default     = 50
}

variable "api_throttling_burst_limit" {
  type    = number
  default = 100
}

# --- Database -------------------------------------------------------------

variable "db_instance_class" {
  description = "db.t4g.micro is free-tier eligible."
  type        = string
  default     = "db.t4g.micro"
}

variable "db_engine_version" {
  description = "Major version only: RDS applies minor versions automatically."
  type        = string
  default     = "8.4"
}

variable "db_allocated_storage_gb" {
  description = "20 GB of gp3 is the minimum and is free-tier eligible."
  type        = number
  default     = 20
}

variable "db_backup_retention_days" {
  description = "Automated backups up to the database size are free. Some free-plan accounts only accept 1."
  type        = number
  default     = 7
}

variable "db_deletion_protection" {
  description = "Disable before running terraform destroy."
  type        = bool
  default     = true
}

# --- Application and operations ------------------------------------------

variable "click_retention_days" {
  description = "Raw clicks are pruned after this many days; daily aggregates are kept."
  type        = number
  default     = 90
}

variable "log_retention_days" {
  description = "CloudWatch Logs retention. Logs never expire by default, which slowly adds cost."
  type        = number
  default     = 14
}

variable "alarm_email" {
  description = "Optional e-mail subscribed to the alarm topic (the address must confirm the subscription)."
  type        = string
  default     = ""
  # Keeps the address out of plan output, which is public in a public repository's Actions logs.
  sensitive = true
}

variable "vpc_cidr" {
  type    = string
  default = "10.20.0.0/16"
}
