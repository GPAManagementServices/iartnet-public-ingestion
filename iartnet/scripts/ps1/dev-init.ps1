#!/usr/bin/env pwsh
# IARTNET Development Environment - Initialize
# Usage: .\scripts\ps1\dev-init.ps1

$ErrorActionPreference = "Stop"

Write-Output "Initializing IARTNET development environment..."

# Check prerequisites
Write-Output "Checking prerequisites..."

$dockerInstalled = Get-Command docker -ErrorAction SilentlyContinue
if (-not $dockerInstalled) {
	Write-Output "ERROR: Docker is not installed or not in PATH"
	Write-Output "Please install Docker Desktop from: https://www.docker.com/products/docker-desktop"
	exit 1
}

# Check if Docker daemon is accessible (use docker ps as it's more reliable)
$ErrorActionPreference = "SilentlyContinue"
$null = docker ps 2>&1
$ErrorActionPreference = "Stop"
if ($LASTEXITCODE -eq 0) {
	Write-Output "OK: Docker and Docker Compose are available and daemon is running"
} else {
	Write-Output "WARNING: Docker command found but daemon is not running"
	Write-Output "Please start Docker Desktop and wait for it to fully initialize."
	Write-Output "You can verify with: docker ps"
	Write-Output ""
	Write-Output "Attempting to continue anyway..."
}

# Create .env from .env.example if needed
if (-not (Test-Path ".env")) {
	if (Test-Path ".env.example") {
		Write-Output "Creating .env from .env.example..."
		Copy-Item ".env.example" ".env"
		Write-Output "WARNING: IMPORTANT - Edit .env and set secure passwords!"
	} else {
		Write-Output "WARNING: .env.example not found"
	}
} else {
	Write-Output "INFO: .env already exists, skipping..."
}

# Start services
Write-Output "Starting Docker services..."
$scriptPath = Split-Path -Parent $MyInvocation.MyCommand.Path
& "$scriptPath\dev-up.ps1"

if ($LASTEXITCODE -ne 0) {
	Write-Output "ERROR: Failed to initialize environment"
	exit 1
}

# Check if Laravel API exists
if (Test-Path "apps/api/composer.json") {
	Write-Output "Laravel API detected. Next steps:"
	Write-Output "  1. cd apps/api"
	Write-Output "  2. composer install"
	Write-Output "  3. php artisan key:generate"
	Write-Output "  4. php artisan migrate"
}

# Check if Nuxt Web exists
if (Test-Path "apps/web/package.json") {
	Write-Output "Nuxt Web detected. Next steps:"
	Write-Output "  1. cd apps/web"
	Write-Output "  2. npm install"
	Write-Output "  3. npm run dev"
}

Write-Output "Initialization complete!"
exit 0

