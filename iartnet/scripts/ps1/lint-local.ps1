#!/usr/bin/env pwsh
# Local Linting Script - Esegue i linter prima del commit
# Usage: .\scripts\ps1\lint-local.ps1
#
# Installa i tool necessari:
#   scoop install shfmt hadolint
#   npm install -g markdownlint-cli
#   Install-Module -Name PSScriptAnalyzer -Scope CurrentUser

$ErrorActionPreference = "Stop"

Write-Output "=========================================="
Write-Output "Local Linting - Pre-Commit Validation"
Write-Output "=========================================="
Write-Output ""

$errors = 0
$warnings = 0

# 1. Bash/Shell Scripts - shfmt
Write-Output "[1/4] Checking Bash/Shell scripts with shfmt..."
if (Get-Command shfmt -ErrorAction SilentlyContinue) {
	$bashFiles = @(
		"init.sh"
	) + (Get-ChildItem -Path "scripts/bash/*.sh" -ErrorAction SilentlyContinue)
	foreach ($file in $bashFiles) {
		if ($file -is [System.IO.FileInfo]) {
			$filePath = $file.FullName
		} else {
			$filePath = $file
		}
		if (Test-Path $filePath) {
			$result = shfmt -d $filePath 2>&1
			if ($LASTEXITCODE -ne 0) {
				Write-Output "  ERROR: $(Split-Path -Leaf $filePath)"
				Write-Output $result
				$errors++
			}
		}
	}
	Write-Output "  OK: Bash scripts checked"
} else {
	Write-Output "  SKIP: shfmt not installed. Install with: scoop install shfmt"
	Write-Output "  (Installation optional - will be checked on GitHub Actions)"
}
Write-Output ""

# 2. Markdown - markdownlint
Write-Output "[2/4] Checking Markdown files with markdownlint..."
if (Get-Command markdownlint -ErrorAction SilentlyContinue) {
	$mdFiles = Get-ChildItem -Path "*.md", "docs/**/*.md" -Recurse -ErrorAction SilentlyContinue
	$mdResult = markdownlint $mdFiles.FullName 2>&1
	if ($LASTEXITCODE -ne 0) {
		Write-Output "  ERRORS found in Markdown files:"
		Write-Output $mdResult
		$errors++
	} else {
		Write-Output "  OK: Markdown files checked"
	}
} else {
	Write-Output "  SKIP: markdownlint not installed. Install with: npm install -g markdownlint-cli"
	Write-Output "  (Installation optional - will be checked on GitHub Actions)"
}
Write-Output ""

# 3. Dockerfile - hadolint
Write-Output "[3/4] Checking Dockerfiles with hadolint..."
if (Get-Command hadolint -ErrorAction SilentlyContinue) {
	$dockerfiles = Get-ChildItem -Path "infra/docker/Dockerfile*" -Recurse -ErrorAction SilentlyContinue
	foreach ($dockerfile in $dockerfiles) {
		$result = hadolint $dockerfile.FullName 2>&1
		if ($LASTEXITCODE -ne 0) {
			Write-Output "  ERROR: $($dockerfile.Name)"
			Write-Output $result
			$errors++
		}
	}
	if ($errors -eq 0) {
		Write-Output "  OK: Dockerfiles checked"
	}
} else {
	Write-Output "  SKIP: hadolint not installed. Install with: scoop install hadolint"
	Write-Output "  (Installation optional - will be checked on GitHub Actions)"
}
Write-Output ""

# 4. PowerShell - PSScriptAnalyzer
Write-Output "[4/4] Checking PowerShell scripts with PSScriptAnalyzer..."
if (Get-Module -ListAvailable -Name PSScriptAnalyzer) {
	$psFiles = Get-ChildItem -Path "scripts/ps1/*.ps1" -Recurse -ErrorAction SilentlyContinue
	foreach ($file in $psFiles) {
		$result = Invoke-ScriptAnalyzer -Path $file.FullName -Severity Error, Warning 2>&1
		if ($result) {
			Write-Output "  ISSUES in $($file.Name):"
			$result | ForEach-Object {
				Write-Output "    $($_.Severity): $($_.RuleName) - Line $($_.Line): $($_.Message)"
				if ($_.Severity -eq "Error") {
					$errors++
				} else {
					$warnings++
				}
			}
		}
	}
	if ($errors -eq 0 -and $warnings -eq 0) {
		Write-Output "  OK: PowerShell scripts checked"
	}
} else {
	Write-Output "  SKIP: PSScriptAnalyzer not installed. Install with: Install-Module -Name PSScriptAnalyzer -Scope CurrentUser"
	Write-Output "  (Installation optional - will be checked on GitHub Actions)"
}
Write-Output ""

# Summary
Write-Output "=========================================="
Write-Output "Summary:"
Write-Output "  Errors: $errors"
Write-Output "  Warnings: $warnings"
Write-Output "=========================================="
Write-Output ""

if ($errors -gt 0) {
	Write-Output "FAILED: Fix errors before committing!"
	Write-Output ""
	Write-Output "Quick fixes:"
	Write-Output "  - Bash: Run 'shfmt -w <file>' to auto-fix"
	Write-Output "  - Markdown: Run 'markdownlint -f <file>' to auto-fix"
	Write-Output "  - Dockerfile: Fix hadolint errors manually"
	Write-Output "  - PowerShell: Fix PSScriptAnalyzer errors manually"
	exit 1
} else {
	if ($warnings -gt 0) {
		Write-Output "SUCCESS: All installed linters passed!"
		Write-Output "NOTE: Some linters are not installed locally. They will be checked on GitHub Actions."
	} else {
		Write-Output "SUCCESS: All linters passed! You can commit safely."
	}
	exit 0
}
