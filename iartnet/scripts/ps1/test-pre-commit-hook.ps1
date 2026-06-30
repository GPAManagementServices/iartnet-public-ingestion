#!/usr/bin/env pwsh
# Test rapido del pre-commit hook
# Verifica solo la configurazione, senza eseguire i linter (veloce)

$ErrorActionPreference = "Stop"

Write-Output "=========================================="
Write-Output "Test Pre-Commit Hook (Quick Check)"
Write-Output "=========================================="
Write-Output ""

$errors = 0
$warnings = 0

# 1. Verifica che il hook esista
Write-Output "[1/4] Verificando pre-commit hook..."
if (Test-Path ".git/hooks/pre-commit") {
	Write-Output "  OK: Pre-commit hook exists"
} else {
	Write-Output "  ERROR: Pre-commit hook not found!"
	Write-Output "  Run: .\scripts\ps1\setup-pre-commit.ps1"
	$errors++
}
Write-Output ""

# 2. Verifica che gli script esistano
Write-Output "[2/4] Verificando script necessari..."
$scripts = @(
	"scripts/bash/pre-commit-security.sh",
	"scripts/bash/lint-local.sh"
)
foreach ($script in $scripts) {
	if (Test-Path $script) {
		Write-Output "  OK: $script exists"
	} else {
		Write-Output "  ERROR: $script not found!"
		$errors++
	}
}
Write-Output ""

# 3. Verifica struttura hook (solo lettura, no esecuzione)
Write-Output "[3/4] Verificando struttura hook..."
if (Test-Path ".git/hooks/pre-commit") {
	$hookContent = Get-Content ".git/hooks/pre-commit" -Raw -ErrorAction SilentlyContinue
	if ($hookContent) {
		if ($hookContent -match "pre-commit-security") {
			Write-Output "  OK: Hook includes security scan"
		} else {
			Write-Output "  WARNING: Hook may not include security scan"
			$warnings++
		}

		if ($hookContent -match "lint-local") {
			Write-Output "  OK: Hook includes linting"
		} else {
			Write-Output "  WARNING: Hook may not include linting"
			$warnings++
		}
	} else {
		Write-Output "  ERROR: Cannot read hook content"
		$errors++
	}
} else {
	Write-Output "  SKIP: Hook not found"
}
Write-Output ""

# 4. Verifica tool disponibili (solo check, no esecuzione)
Write-Output "[4/4] Verificando tool disponibili (opzionali)..."
$tools = @{
	"trivy" = "Security scanning"
	"shfmt" = "Bash formatting"
	"markdownlint" = "Markdown linting"
	"hadolint" = "Dockerfile linting"
	"yamllint" = "YAML linting"
}
$installedTools = @()
foreach ($tool in $tools.Keys) {
	if (Get-Command $tool -ErrorAction SilentlyContinue) {
		Write-Output "  OK: $tool ($($tools[$tool]))"
		$installedTools += $tool
	} else {
		Write-Output "  SKIP: $tool not installed"
	}
}

# Verifica PSScriptAnalyzer
if (Get-Module -ListAvailable -Name PSScriptAnalyzer) {
	Write-Output "  OK: PSScriptAnalyzer (PowerShell linting)"
	$installedTools += "PSScriptAnalyzer"
} else {
	Write-Output "  SKIP: PSScriptAnalyzer not installed"
}
Write-Output ""

# Summary
Write-Output "=========================================="
Write-Output "Test Summary:"
Write-Output "  Errors: $errors"
Write-Output "  Warnings: $warnings"
Write-Output "  Tools installed: $($installedTools.Count)/$($tools.Count + 1)"
Write-Output "=========================================="
Write-Output ""

if ($errors -gt 0) {
	Write-Output "FAILED: Fix errors before using pre-commit hook!"
	exit 1
} else {
	Write-Output "SUCCESS: Pre-commit hook is configured correctly!"
	if ($warnings -gt 0) {
		Write-Output ""
		Write-Output "NOTE: Some warnings detected. Review hook configuration."
	}
	if ($installedTools.Count -eq 0) {
		Write-Output ""
		Write-Output "NOTE: No linting tools installed locally."
		Write-Output "      Linting will be checked on GitHub Actions only."
	}
	Write-Output ""
	Write-Output "The hook will run automatically on: git commit"
	exit 0
}

