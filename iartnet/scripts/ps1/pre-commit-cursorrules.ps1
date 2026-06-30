#!/usr/bin/env pwsh
# Pre-commit validation based on .cursorrules
# Verifica che i file in commit rispettino le regole definite in .cursorrules

$ErrorActionPreference = "Stop"

Write-Output "=========================================="
Write-Output "Pre-Commit CursorRules Validation"
Write-Output "=========================================="
Write-Output ""

# Get staged files
$stagedFiles = git diff --cached --name-only --diff-filter=ACM

if ($stagedFiles.Count -eq 0) {
    Write-Output "No files staged for commit."
    exit 0
}

$errors = @()
$warnings = @()

# Filter code files (exclude documentation)
$codeFiles = $stagedFiles | Where-Object {
    ($_ -match '\.(php|js|ts|vue|sql|sh|bash)$' -or $_ -match 'scripts/(bash|ps1)/') -and
    $_ -notmatch '(README|CHANGELOG|\.md|docs/)'
}

if ($codeFiles.Count -eq 0) {
    Write-Output "OK: No code files to validate."
    exit 0
}

Write-Output "Validating $($codeFiles.Count) code file(s) against .cursorrules..."
Write-Output ""

foreach ($file in $codeFiles) {
    if (-not (Test-Path $file)) {
        continue
    }

    $content = Get-Content $file -Raw
    $lines = Get-Content $file

    # PHP Files Validation
    if ($file -match '\.php$') {
        # Exclude files that shouldn't have strict_types:
        # - Entry points (index.php, bootstrap files)
        # - Route files
        # - Blade templates
        $excludePatterns = @(
            'index\.php$',
            'bootstrap/',
            'routes/',
            '\.blade\.php$'
        )
        $shouldCheckStrictTypes = $true
        foreach ($pattern in $excludePatterns) {
            if ($file -match $pattern) {
                $shouldCheckStrictTypes = $false
                break
            }
        }

        # 1. Check for strict_types declaration (only for non-excluded files)
        if ($shouldCheckStrictTypes -and $content -notmatch 'declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;') {
            $errors += "$file : Missing 'declare(strict_types=1);' at the top of the file"
        }

        # 2. Check for hardcoded secrets (basic check)
        if ($content -match '(password|secret|api_key|token)\s*=\s*["'']\w+["'']') {
            $warnings += "$file : Potential hardcoded secret detected (review manually)"
        }

        # 3. Check SQL keywords (should be uppercase in migrations)
        if ($file -match 'migrations.*\.php$') {
            $sqlKeywords = @('select', 'insert', 'update', 'delete', 'create', 'alter', 'drop', 'table', 'from', 'where', 'join', 'inner', 'left', 'right', 'outer', 'on', 'group', 'order', 'by', 'having', 'limit', 'offset', 'as', 'and', 'or', 'not', 'in', 'exists', 'null', 'is', 'distinct', 'count', 'sum', 'avg', 'max', 'min')
            foreach ($keyword in $sqlKeywords) {
                if ($content -match "(?i)\b$keyword\b" -and $content -notmatch "(?i)\b$keyword\b.*--.*ignore") {
                    # Check if it's in a SQL string context
                    if ($content -match "['\`"]\s*(?i)$keyword") {
                        $warnings += "$file : SQL keyword '$keyword' should be UPPERCASE (rule: keyword SQL in MAIUSCOLO)"
                    }
                }
            }
        }

        # 4. Check for PSR-12 compliance (basic checks)
        # Check for trailing whitespace
        for ($i = 0; $i -lt $lines.Count; $i++) {
            if ($lines[$i] -match '\s+$') {
                $errors += "$file : Line $($i+1) has trailing whitespace (PSR-12 violation)"
            }
        }

        # Check for proper file ending (exactly one newline)
        # Check if file ends with 2+ newlines
        $lastBytes = [System.IO.File]::ReadAllBytes($file) | Select-Object -Last 2
        if ($lastBytes.Count -eq 2 -and $lastBytes[0] -eq 10 -and $lastBytes[1] -eq 10) {
            $errors += "$file : File must end with exactly one newline, found 2+ (PSR-12 violation)"
        } elseif ($lastBytes.Count -eq 1 -and $lastBytes[0] -ne 10) {
            $errors += "$file : File must end with exactly one newline (PSR-12 violation)"
        } elseif ($lastBytes.Count -eq 0) {
            $errors += "$file : File must end with exactly one newline (PSR-12 violation)"
        }
    }

    # JavaScript/TypeScript Files Validation
    if ($file -match '\.(js|ts|vue)$') {
        # Check for TypeScript usage in .ts/.vue files
        if ($file -match '\.(ts|vue)$' -and $content -notmatch '<script\s+setup\s+lang=["'']ts["'']') {
            if ($content -match '<script') {
                $warnings += "$file : Vue 3 files should use '<script setup lang=""ts"">' (TypeScript)"
            }
        }

        # Check for PascalCase components
        if ($file -match '\.vue$') {
            $componentName = [System.IO.Path]::GetFileNameWithoutExtension($file)
            if ($componentName -cne $componentName) {
                $warnings += "$file : Component file name should be PascalCase (found: $componentName)"
            }
        }
    }

    # SQL Files Validation
    if ($file -match '\.sql$') {
        # Check SQL keywords are uppercase
        $sqlKeywords = @('select', 'insert', 'update', 'delete', 'create', 'alter', 'drop', 'table', 'from', 'where')
        foreach ($keyword in $sqlKeywords) {
            if ($content -match "(?i)\b$keyword\b" -and $content -notmatch "\b$keyword\b") {
                $errors += "$file : SQL keyword '$keyword' must be UPPERCASE (rule: keyword SQL in MAIUSCOLO)"
            }
        }
    }

    # PowerShell Files Validation
    if ($file -match '\.ps1$' -or $file -match 'scripts/ps1/') {
        # Check for trailing whitespace
        for ($i = 0; $i -lt $lines.Count; $i++) {
            if ($lines[$i] -match '\s+$') {
                $errors += "$file : Line $($i+1) has trailing whitespace (PSAvoidTrailingWhitespace)"
            }
        }

        # Check for BOM encoding (warning, not error)
        $bytes = [System.IO.File]::ReadAllBytes($file)
        if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
            # File has BOM, which is OK
        } elseif ($content -match '[^\x00-\x7F]') {
            # File contains non-ASCII characters but no BOM
            $warnings += "$file : File contains non-ASCII characters but missing BOM encoding (PSUseBOMForUnicodeEncodedFile)"
        }
    }

    # Bash/Shell Files Validation
    if ($file -match '\.(sh|bash)$' -or $file -match 'scripts/bash/') {
        # Check for BOM encoding (bash files should NOT have BOM)
        $bytes = [System.IO.File]::ReadAllBytes($file)
        if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
            $errors += "$file : File contains UTF-8 BOM. Bash files should NOT have BOM (shellcheck SC1082)"
        }
    }
    # Note: shfmt requires TAB characters, not spaces, so we don't check for tabs
    # The linter (shfmt) will format files correctly with tabs
}

# Report results
Write-Output ""

if ($errors.Count -gt 0) {
    Write-Output "ERRORS FOUND ($($errors.Count)):"
    foreach ($error in $errors) {
        Write-Output "  Ã¢ÂÅ’ $error"
    }
    Write-Output ""
}

if ($warnings.Count -gt 0) {
    Write-Output "WARNINGS ($($warnings.Count)):"
    foreach ($warning in $warnings) {
        Write-Output "  Ã¢Å¡Â Ã¯Â¸Â  $warning"
    }
    Write-Output ""
}

if ($errors.Count -gt 0) {
    Write-Output "=========================================="
    Write-Output "ERROR: CursorRules validation failed!"
    Write-Output "Please fix the errors above before committing."
    Write-Output ""
    Write-Output "To skip this check (not recommended):"
    Write-Output "  git commit --no-verify"
    Write-Output "=========================================="
    exit 1
}

if ($warnings.Count -gt 0) {
    Write-Output "WARNING: Some issues were found but are not blocking."
    Write-Output "Please review the warnings above."
    Write-Output ""
}

Write-Output "OK: CursorRules validation passed!"
Write-Output "=========================================="
exit 0
