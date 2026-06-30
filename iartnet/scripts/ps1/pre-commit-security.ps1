#!/usr/bin/env pwsh
# Pre-commit Security Scan with Trivy
# Usage: .\scripts\ps1\pre-commit-security.ps1
# This script should be run before committing to check for security vulnerabilities

$ErrorActionPreference = "Stop"

Write-Output "Running pre-commit security scan with Trivy..."

# Check if Trivy is installed
$trivyInstalled = Get-Command trivy -ErrorAction SilentlyContinue
if (-not $trivyInstalled) {
    Write-Output "WARNING: Trivy is not installed. Installing via winget or chocolatey..."

    # Try winget first (Windows 11/10 with App Installer)
    $wingetInstalled = Get-Command winget -ErrorAction SilentlyContinue
    if ($wingetInstalled) {
        Write-Output "Installing Trivy via winget..."
        winget install aquasecurity.trivy
    } else {
        # Try chocolatey
        $chocoInstalled = Get-Command choco -ErrorAction SilentlyContinue
        if ($chocoInstalled) {
            Write-Output "Installing Trivy via Chocolatey..."
            choco install trivy -y
        } else {
            Write-Output "ERROR: Trivy is not installed and no package manager found."
            Write-Output "Please install Trivy manually from: https://github.com/aquasecurity/trivy/releases"
            Write-Output "Or use: scoop install trivy"
            exit 1
        }
    }

    # Verify installation
    $trivyInstalled = Get-Command trivy -ErrorAction SilentlyContinue
    if (-not $trivyInstalled) {
        Write-Output "ERROR: Trivy installation failed. Please install manually."
        exit 1
    }
}

Write-Output "OK: Trivy is available"

# Scan filesystem for vulnerabilities
Write-Output ""
Write-Output "Scanning filesystem for vulnerabilities..."
$fsExitCode = 0
trivy fs --severity CRITICAL,HIGH --exit-code 1 --no-progress . 2>&1 | Tee-Object -Variable fsOutput
$fsExitCode = $LASTEXITCODE

# Scan repository for vulnerabilities
Write-Output ""
Write-Output "Scanning repository for vulnerabilities..."
$repoExitCode = 0
trivy repo --severity CRITICAL,HIGH --exit-code 1 --no-progress . 2>&1 | Tee-Object -Variable repoOutput
$repoExitCode = $LASTEXITCODE

# Scan Dockerfiles if they exist
$dockerFiles = Get-ChildItem -Path "infra/docker" -Filter "Dockerfile*" -Recurse -ErrorAction SilentlyContinue
if ($dockerFiles) {
    Write-Output ""
    Write-Output "Scanning Dockerfiles for vulnerabilities..."
    foreach ($dockerFile in $dockerFiles) {
        Write-Output "  Scanning: $($dockerFile.FullName)"
        trivy fs --severity CRITICAL,HIGH --exit-code 1 --no-progress $dockerFile.DirectoryName 2>&1 | Out-Null
    }
}

# Summary
Write-Output ""
Write-Output "Security Scan Summary:"
if ($fsExitCode -eq 0 -and $repoExitCode -eq 0) {
    Write-Output "OK: No CRITICAL or HIGH severity vulnerabilities found!"
    Write-Output "You can proceed with your commit."
    exit 0
} else {
    Write-Output "ERROR: CRITICAL or HIGH severity vulnerabilities detected!"
    Write-Output "Please review the output above and fix vulnerabilities before committing."
    Write-Output ""
    Write-Output "To see full details, run:"
    Write-Output "  trivy fs --severity CRITICAL,HIGH ."
    exit 1
}
