#!/usr/bin/env pwsh
# Setup pre-commit hook for security scanning and linting
# Usage: .\scripts\ps1\setup-pre-commit.ps1

$ErrorActionPreference = "Stop"

Write-Output "Setting up pre-commit hook (security + linting)..."

$hookTarget = ".git\hooks\pre-commit"

# Check if we're in a git repository
if (-not (Test-Path ".git")) {
    Write-Output "ERROR: Not in a git repository!"
    exit 1
}

# The hook is already in .git/hooks, verify it's up to date
if (Test-Path $hookTarget) {
	Write-Output "OK: Pre-commit hook exists"
	Write-Output "Verifying hook includes linting..."

	$hookContent = Get-Content $hookTarget -Raw
	if ($hookContent -match "pre-commit-cursorrules") {
		Write-Output "OK: Hook already includes CursorRules validation"
	} else {
		Write-Output "WARNING: Hook exists but doesn't include CursorRules validation"
		Write-Output "The hook will be updated to include CursorRules validation..."
		# Hook will be updated below
	}
} else {
	Write-Output "Creating pre-commit hook..."
}

# Create/Update the hook with security + CursorRules validation + linting
$hookContent = @"
#!/bin/sh
# Git pre-commit hook for security scanning, CursorRules validation and linting
# This hook runs:
# 1. Trivy security scan
# 2. CursorRules validation (based on .cursorrules)
# 3. Local linters

# Get the directory of the hook script
HOOK_DIR="`$(cd "`$(dirname "`$0")" && pwd)"
REPO_ROOT="`$(cd "`$HOOK_DIR/../.." && pwd)"

# Check if we're in a git repository
if [ ! -d "`$REPO_ROOT/.git" ]; then
	exit 0
fi

echo "=========================================="
echo "Pre-Commit Checks"
echo "=========================================="
echo ""

# 1. Security Scan with Trivy
if [ -f "`$REPO_ROOT/scripts/bash/pre-commit-security.sh" ]; then
	chmod +x "`$REPO_ROOT/scripts/bash/pre-commit-security.sh"
	"`$REPO_ROOT/scripts/bash/pre-commit-security.sh"
	EXIT_CODE=`$?

	if [ `$EXIT_CODE -ne 0 ]; then
		echo ""
		echo "ERROR: Pre-commit security check failed!"
		echo "Commit aborted. Please fix the vulnerabilities and try again."
		echo ""
		echo "To skip this check (not recommended), use:"
		echo "  git commit --no-verify"
		exit 1
	fi
fi

# 2. CursorRules Validation
if [ -f "`$REPO_ROOT/scripts/bash/pre-commit-cursorrules.sh" ]; then
	chmod +x "`$REPO_ROOT/scripts/bash/pre-commit-cursorrules.sh"
	"`$REPO_ROOT/scripts/bash/pre-commit-cursorrules.sh"
	EXIT_CODE=`$?

	if [ `$EXIT_CODE -ne 0 ]; then
		echo ""
		echo "ERROR: Pre-commit CursorRules validation failed!"
		echo "Commit aborted. Please fix the errors and try again."
		echo ""
		echo "To skip this check (not recommended), use:"
		echo "  git commit --no-verify"
		exit 1
	fi
fi

# 3. Local Linting
if [ -f "`$REPO_ROOT/scripts/bash/lint-local.sh" ]; then
	chmod +x "`$REPO_ROOT/scripts/bash/lint-local.sh"
	"`$REPO_ROOT/scripts/bash/lint-local.sh"
	EXIT_CODE=`$?

	if [ `$EXIT_CODE -ne 0 ]; then
		echo ""
		echo "ERROR: Pre-commit linting failed!"
		echo "Commit aborted. Please fix the linting errors and try again."
		echo ""
		echo "To skip this check (not recommended), use:"
		echo "  git commit --no-verify"
		exit 1
	fi
fi

echo ""
echo "OK: All pre-commit checks passed!"
exit 0
"@

$hookContent | Out-File -FilePath $hookTarget -Encoding ASCII -NoNewline
Write-Output "OK: Pre-commit hook created/updated"

# Make scripts executable (for WSL/Git Bash)
Write-Output "Making scripts executable..."
if (Test-Path "scripts\bash\pre-commit-security.sh") {
	Write-Output "  - pre-commit-security.sh"
}
if (Test-Path "scripts\bash\pre-commit-cursorrules.sh") {
	Write-Output "  - pre-commit-cursorrules.sh"
}
if (Test-Path "scripts\bash\lint-local.sh") {
	Write-Output "  - lint-local.sh"
}
Write-Output "  Note: On Windows, scripts will be executable in Git Bash/WSL"

# Check installed linting tools
Write-Output ""
Write-Output "Checking installed linting tools..."

$toolsInstalled = @()
if (Get-Command shfmt -ErrorAction SilentlyContinue) {
	$toolsInstalled += "shfmt"
}
if (Get-Command markdownlint -ErrorAction SilentlyContinue) {
	$toolsInstalled += "markdownlint"
}
if (Get-Command hadolint -ErrorAction SilentlyContinue) {
	$toolsInstalled += "hadolint"
}
if (Get-Module -ListAvailable -Name PSScriptAnalyzer) {
	$toolsInstalled += "PSScriptAnalyzer"
}
if (Get-Command yamllint -ErrorAction SilentlyContinue) {
	$toolsInstalled += "yamllint"
}

if ($toolsInstalled.Count -gt 0) {
	Write-Output "  Installed: $($toolsInstalled -join ', ')"
} else {
	Write-Output "  WARNING: No linting tools installed locally"
	Write-Output "  Linting will be skipped locally but checked on GitHub Actions"
	Write-Output ""
	Write-Output "  To install tools, run:"
	Write-Output "    scoop install shfmt hadolint"
	Write-Output "    npm install -g markdownlint-cli"
	Write-Output "    Install-Module -Name PSScriptAnalyzer -Scope CurrentUser"
}

Write-Output ""
Write-Output "OK: Pre-commit hook setup complete!"
Write-Output "The hook will now run:"
Write-Output "  1. Trivy security scan"
Write-Output "  2. CursorRules validation (based on .cursorrules)"
Write-Output "  3. Local linting (if tools are installed)"
Write-Output ""
Write-Output "To skip the hook (not recommended), use: git commit --no-verify"

