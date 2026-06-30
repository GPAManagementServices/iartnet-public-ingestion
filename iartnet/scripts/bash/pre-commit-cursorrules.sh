#!/bin/bash
# Pre-commit validation based on .cursorrules
# Verifica che i file in commit rispettino le regole definite in .cursorrules

set -e

echo "=========================================="
echo "Pre-Commit CursorRules Validation"
echo "=========================================="
echo ""

# Get staged files
STAGED_FILES=$(git diff --cached --name-only --diff-filter=ACM)

if [ -z "$STAGED_FILES" ]; then
	echo "No files staged for commit."
	exit 0
fi

ERRORS=()
WARNINGS=()

# Filter code files (exclude documentation)
CODE_FILES=$(echo "$STAGED_FILES" | grep -E '\.(php|js|ts|vue|sql|sh|bash)$|scripts/(bash|ps1)/' | grep -vE '(README|CHANGELOG|\.md|docs/)' || true)

if [ -z "$CODE_FILES" ]; then
	echo "OK: No code files to validate."
	exit 0
fi

FILE_COUNT=$(echo "$CODE_FILES" | wc -l | tr -d ' ')
echo "Validating $FILE_COUNT code file(s) against .cursorrules..."
echo ""

while IFS= read -r file; do
	if [ ! -f "$file" ]; then
		continue
	fi

	# PHP Files Validation
	if [[ "$file" == *.php ]]; then
		# Exclude files that shouldn't have strict_types:
		# - Entry points (index.php, bootstrap files)
		# - Route files
		# - Blade templates
		SHOULD_CHECK_STRICT_TYPES=true
		if [[ "$file" == *"/index.php" ]] ||
			[[ "$file" == *"/bootstrap/"* ]] ||
			[[ "$file" == *"/routes/"* ]] ||
			[[ "$file" == *.blade.php ]]; then
			SHOULD_CHECK_STRICT_TYPES=false
		fi

		# 1. Check for strict_types declaration (only for non-excluded files)
		if [ "$SHOULD_CHECK_STRICT_TYPES" = true ]; then
			if ! grep -qE 'declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;' "$file"; then
				ERRORS+=("$file : Missing 'declare(strict_types=1);' at the top of the file")
			fi
		fi

		# 2. Check for hardcoded secrets (basic check)
		if grep -qE '(password|secret|api_key|token)\s*=\s*["'\'']\w+["'\'']' "$file"; then
			WARNINGS+=("$file : Potential hardcoded secret detected (review manually)")
		fi

		# 3. Check for trailing whitespace
		LINE_NUM=1
		while IFS= read -r line; do
			if [[ "$line" =~ [[:space:]]+$ ]]; then
				ERRORS+=("$file : Line $LINE_NUM has trailing whitespace (PSR-12 violation)")
			fi
			((LINE_NUM++))
		done <"$file"

		# 4. Check file ending (exactly one newline)
		if [ -s "$file" ]; then
			# Check last 2 bytes to detect multiple newlines
			LAST_TWO_BYTES=$(tail -c 2 "$file" | od -An -tx1 | tr -d ' \n')
			LAST_BYTE=$(tail -c 1 "$file" | od -An -tx1 | tr -d ' \n')

			# Check if file ends with 2 newlines (0a0a)
			if [ "$LAST_TWO_BYTES" = "0a0a" ]; then
				ERRORS+=("$file : File must end with exactly one newline, found 2+ (PSR-12 violation)")
			elif [ -z "$LAST_BYTE" ] || [ "$LAST_BYTE" != "0a" ]; then
				ERRORS+=("$file : File must end with exactly one newline (PSR-12 violation)")
			fi
		fi
	fi

	# JavaScript/TypeScript Files Validation
	if [[ "$file" == *.js ]] || [[ "$file" == *.ts ]] || [[ "$file" == *.vue ]]; then
		# Check for TypeScript usage in .ts/.vue files
		if [[ "$file" == *.ts ]] || [[ "$file" == *.vue ]]; then
			if grep -q '<script' "$file" 2>/dev/null; then
				if ! grep -qE '<script\s+setup\s+lang=["'\'']ts["'\'']' "$file"; then
					WARNINGS+=("$file : Vue 3 files should use '<script setup lang=\"ts\">' (TypeScript)")
				fi
			fi
		fi

		# Check for PascalCase components
		if [[ "$file" == *.vue ]]; then
			COMPONENT_NAME=$(basename "$file" .vue)
			if [[ ! "$COMPONENT_NAME" =~ ^[A-Z] ]]; then
				WARNINGS+=("$file : Component file name should be PascalCase (found: $COMPONENT_NAME)")
			fi
		fi
	fi

	# SQL Files Validation
	if [[ "$file" == *.sql ]]; then
		# Check SQL keywords are uppercase
		SQL_KEYWORDS=("select" "insert" "update" "delete" "create" "alter" "drop" "table" "from" "where")
		for keyword in "${SQL_KEYWORDS[@]}"; do
			if grep -qiE "\b$keyword\b" "$file" && ! grep -qE "\b${keyword^^}\b" "$file"; then
				ERRORS+=("$file : SQL keyword '$keyword' must be UPPERCASE (rule: keyword SQL in MAIUSCOLO)")
			fi
		done
	fi

	# PowerShell Files Validation
	if [[ "$file" == *.ps1 ]] || [[ "$file" == scripts/ps1/* ]]; then
		# Check for trailing whitespace
		LINE_NUM=1
		while IFS= read -r line; do
			if [[ "$line" =~ [[:space:]]+$ ]]; then
				ERRORS+=("$file : Line $LINE_NUM has trailing whitespace (PSAvoidTrailingWhitespace)")
			fi
			((LINE_NUM++))
		done <"$file"

		# Check for BOM encoding (warning, not error)
		# Read first 3 bytes
		if [ -s "$file" ]; then
			FIRST_THREE_BYTES=$(head -c 3 "$file" | od -An -tx1 | tr -d ' \n')
			# Check if file contains non-ASCII but no BOM (EF BB BF)
			if [ "$FIRST_THREE_BYTES" != "efbbbf" ]; then
				# Check if file contains non-ASCII characters
				if grep -q '[^[:print:][:space:]]' "$file" 2>/dev/null || file "$file" | grep -q "UTF-8" 2>/dev/null; then
					# This is a basic check - PowerShell linter will catch it more accurately
					WARNINGS+=("$file : File may contain non-ASCII characters but missing BOM encoding (PSUseBOMForUnicodeEncodedFile)")
				fi
			fi
		fi
	fi

	# Bash/Shell Files Validation
	if [[ "$file" == *.sh ]] || [[ "$file" == *.bash ]] || [[ "$file" == scripts/bash/* ]]; then
		# Check for BOM encoding (bash files should NOT have BOM)
		if [ -s "$file" ]; then
			FIRST_THREE_BYTES=$(head -c 3 "$file" | od -An -tx1 | tr -d ' \n')
			if [ "$FIRST_THREE_BYTES" = "efbbbf" ]; then
				ERRORS+=("$file : File contains UTF-8 BOM. Bash files should NOT have BOM (shellcheck SC1082)")
			fi
		fi
	fi

	# Note: shfmt requires TAB characters, not spaces, so we don't check for tabs
	# The linter (shfmt) will format files correctly with tabs
done <<<"$CODE_FILES"

# Report results
echo ""

if [ ${#ERRORS[@]} -gt 0 ]; then
	echo "ERRORS FOUND (${#ERRORS[@]}):"
	for error in "${ERRORS[@]}"; do
		echo "  âŒ $error"
	done
	echo ""
fi

if [ ${#WARNINGS[@]} -gt 0 ]; then
	echo "WARNINGS (${#WARNINGS[@]}):"
	for warning in "${WARNINGS[@]}"; do
		echo "  âš ï¸  $warning"
	done
	echo ""
fi

if [ ${#ERRORS[@]} -gt 0 ]; then
	echo "=========================================="
	echo "ERROR: CursorRules validation failed!"
	echo "Please fix the errors above before committing."
	echo ""
	echo "To skip this check (not recommended):"
	echo "  git commit --no-verify"
	echo "=========================================="
	exit 1
fi

if [ ${#WARNINGS[@]} -gt 0 ]; then
	echo "WARNING: Some issues were found but are not blocking."
	echo "Please review the warnings above."
	echo ""
fi

echo "OK: CursorRules validation passed!"
echo "=========================================="
exit 0
