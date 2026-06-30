#!/usr/bin/env pwsh
# IARTNET Development Environment - Start Services
# Usage: .\scripts\ps1\dev-up.ps1

$ErrorActionPreference = "Stop"

Write-Output "Starting IARTNET Docker environment..."

$composeFile = "infra/docker/docker-compose.yml"
if (-not (Test-Path $composeFile)) {
	Write-Output "ERROR: docker-compose.yml not found at $composeFile"
	exit 1
}

# Check if .env exists, if not, copy from .env.example
if (-not (Test-Path ".env")) {
	if (Test-Path ".env.example") {
		Write-Output "Creating .env from .env.example..."
		Copy-Item ".env.example" ".env"
		Write-Output "WARNING: Please edit .env and set your passwords before continuing!"
	} else {
		Write-Output "WARNING: .env.example not found. Using defaults."
	}
}

# Check if Docker daemon is running (use docker ps as it's more reliable)
Write-Output "Checking Docker daemon..."
$ErrorActionPreference = "SilentlyContinue"
$null = docker ps 2>&1
$ErrorActionPreference = "Stop"
if ($LASTEXITCODE -ne 0) {
	Write-Output "ERROR: Docker daemon is not running!"
	Write-Output ""
	Write-Output "Please start Docker Desktop and wait for it to fully initialize."
	Write-Output "You can verify Docker is running with: docker ps"
	exit 1
}

# Start services
Write-Output "Starting Docker Compose services..."
docker compose -f $composeFile up -d

if ($LASTEXITCODE -ne 0) {
	Write-Output "ERROR: Failed to start Docker services"
	Write-Output "Make sure Docker Desktop is running and try again."
	exit 1
}

# Wait for PostgreSQL to be ready
Write-Output "Waiting for PostgreSQL to be ready..."
$maxAttempts = 30
$attempt = 0
$ready = $false

while ($attempt -lt $maxAttempts -and -not $ready) {
	Start-Sleep -Seconds 2
	$null = docker exec iartnet-db pg_isready -U iartnet 2>&1
	if ($LASTEXITCODE -eq 0) {
		$ready = $true
		Write-Output "OK: PostgreSQL is ready!"
	} else {
		$attempt++
		Write-Output "  Attempt $attempt/$maxAttempts..."
	}
}

if (-not $ready) {
	Write-Output "ERROR: PostgreSQL failed to become ready after $maxAttempts attempts"
	exit 1
}

Write-Output "OK: IARTNET development environment is ready!"
Write-Output "   Database: localhost:5432"
Write-Output "   Redis: localhost:6379"
