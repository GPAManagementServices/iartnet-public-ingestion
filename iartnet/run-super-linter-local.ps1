# Script per eseguire Super-Linter localmente
# Requisiti: Docker deve essere installato e in esecuzione

Write-Output "Running Super-Linter locally..."

docker run --rm \
  -e VALIDATE_ALL_CODEBASE=true \
  -e VALIDATE_PHP=true \
  -e VALIDATE_PHP_PHPCS=true \
  -e VALIDATE_PHP_STAN=true \
  -e PHP_PHPCS_STANDARD=PSR12 \
  -e VALIDATE_JAVASCRIPT=false \
  -e VALIDATE_TYPESCRIPT=false \
  -e VALIDATE_JSON=true \
  -e VALIDATE_YAML=true \
  -e VALIDATE_MARKDOWN=true \
  -e VALIDATE_XML=true \
  -e VALIDATE_DOCKER=true \
  -e VALIDATE_DOCKERFILE_HADOLINT=true \
  -e VALIDATE_POWERSHELL=true \
  -e VALIDATE_BASH=true \
  -e VALIDATE_SHELL_SHFMT=true \
  -e DISABLE_ERRORS=false \
  -e LOG_LEVEL=VERBOSE \
  -e OUTPUT_FORMAT=github-actions-logging \
  -v ${PWD}:/tmp/lint \
  -w /tmp/lint \
  ghcr.io/github/super-linter:v5.0.0
