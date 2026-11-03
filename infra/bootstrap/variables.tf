variable "aws_region" {
  description = "Region of the application stack."
  type        = string
  default     = "us-east-1"
}

variable "project" {
  description = "Name prefix of every resource the deploy role may manage."
  type        = string
  default     = "shortlinks"
}

variable "github_repository" {
  description = "GitHub repository (owner/name) allowed to assume the deploy role."
  type        = string
  default     = "JulianContreras02/laravel-serverless-aws"
}

variable "github_environment" {
  description = "GitHub environment the deploy job must run in. Protect it with required reviewers."
  type        = string
  default     = "production"
}

variable "create_github_oidc_provider" {
  description = "An AWS account has at most one GitHub OIDC provider. Set to false if it already exists."
  type        = bool
  default     = true
}
