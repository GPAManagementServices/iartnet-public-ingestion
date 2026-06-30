#!/bin/bash
# Local Linting Script - Esegue i linter prima del commit
# Usage: ./scripts/bash/lint-local.sh
#
# Installa i tool necessari:
#   brew install shfmt hadolint  # macOS
#   npm install -g markdownlint-cli
#   pip install yamllint

set -e

echo "=========================================="
echo "Local Linting - Pre-Commit Validation"
echo "=========================================="
echo ""

ERRORS=0
WARNINGS=0

# 1. Bash/Shell Scripts - shfmt
echo "[1/4] Checking Bash/Shell scripts with shfmt..."
if command -v shfmt &>/dev/null; then
	FILES=(
		"init.sh"
		"scripts/bash/*.sh"
	)
	for pattern in "${FILES[@]}"; do
		for file in $pattern; do
			if [ -f "$file" ]; then
				if ! shfmt -d "$file" 2>&1; then
					echo "  ERROR: $(basename "$file")"
					((ERRORS++))
				fi
			fi
		done
	done
	if [ $ERRORS -eq 0 ]; then
		echo "  OK: Bash scripts checked"
	fi
else
	echo "  SKIP: shfmt not installed. Install with: brew install shfmt"
	echo "  (Installation optional - will be checked on GitHub Actions)"
fi
echo ""

# 2. Markdown - markdownlint
# Skip Markdown linting - files are excluded in Super-Linter workflow
echo "[2/4] Checking Markdown files with markdownlint..."
echo "  SKIP: Markdown linting disabled (files excluded in Super-Linter)"
echo "  (Markdown files are excluded via FILTER_REGEX_EXCLUDE in .github/workflows/linter.yml)"
echo ""

# 3. Dockerfile - hadolint
echo "[3/4] Checking Dockerfiles with hadolint..."
if command -v hadolint &>/dev/null; then
	while IFS= read -r -d '' dockerfile; do
		if ! hadolint "$dockerfile" 2>&1; then
			echo "  ERROR: $(basename "$dockerfile")"
			((ERRORS++))
		fi
	done < <(find infra/docker -name "Dockerfile*" -type f -print0)
	if [ $ERRORS -eq 0 ]; then
		echo "  OK: Dockerfiles checked"
	fi
else
	echo "  SKIP: hadolint not installed. Install with: brew install hadolint"
	echo "  (Installation optional - will be checked on GitHub Actions)"
fi
echo ""

# 4. YAML - yamllint
echo "[4/4] Checking YAML files with yamllint..."
if command -v yamllint &>/dev/null; then
	if ! yamllint -d "{extends: default, rules: {line-length: {max: 120}}}" .github/workflows/*.yml .github/dependabot.yml infra/docker/docker-compose.yml 2>&1; then
		echo "  ERRORS found in YAML files"
		((ERRORS++))
	else
		echo "  OK: YAML files checked"
	fi
else
	echo "  SKIP: yamllint not installed. Install with: pip install yamllint"
	echo "  (Installation optional - will be checked on GitHub Actions)"
fi
echo ""

# Summary
echo "=========================================="
echo "Summary:"
echo "  Errors: $ERRORS"
echo "  Warnings: $WARNINGS"
echo "=========================================="
echo ""

if [ $ERRORS -gt 0 ]; then
	echo "FAILED: Fix errors before committing!"
	echo ""
	echo "Quick fixes:"
	echo "  - Bash: Run 'shfmt -w <file>' to auto-fix"
	echo "  - Markdown: Run 'markdownlint -f <file>' to auto-fix"
	echo "  - Dockerfile: Fix hadolint errors manually"
	echo "  - YAML: Fix yamllint errors manually"
	exit 1
else
	if [ $WARNINGS -gt 0 ]; then
		echo "SUCCESS: All installed linters passed!"
		echo "NOTE: Some linters are not installed locally. They will be checked on GitHub Actions."
	else
		echo "SUCCESS: All linters passed! You can commit safely."
	fi
	exit 0
fi
