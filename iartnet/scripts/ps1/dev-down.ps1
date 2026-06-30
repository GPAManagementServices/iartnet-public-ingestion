#!/usr/bin/env pwsh
# IARTNET Development Environment - Stop Services
# Usage: .\scripts\ps1\dev-down.ps1

$ErrorActionPreference = "Stop"

Write-Output "Stopping IARTNET Docker environment..."

$composeFile = "infra/docker/docker-compose.yml"
if (-not (Test-Path $composeFile)) {
	Write-Output "ERROR: docker-compose.yml not found at $composeFile"
	exit 1
}

docker compose -f $composeFile down

if ($LASTEXITCODE -ne 0) {
	Write-Output "ERROR: Failed to stop Docker services"
	exit 1
}

Write-Output "OK: IARTNET services stopped"
