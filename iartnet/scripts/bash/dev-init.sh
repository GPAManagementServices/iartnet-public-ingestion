#!/bin/bash
# IARTNET Development Environment - Initialize
# Usage: ./scripts/bash/dev-init.sh

set -e

echo "Initializing IARTNET development environment..."

# Check prerequisites
echo "Checking prerequisites..."

if ! command -v docker &>/dev/null; then
	echo "ERROR: Docker is not installed or not in PATH" >&2
	exit 1
fi

if ! command -v docker-compose &>/dev/null && ! docker compose version &>/dev/null; then
	echo "ERROR: Docker Compose is not installed or not in PATH" >&2
	exit 1
fi

echo "OK: Docker and Docker Compose are available"

# Create .env from .env.example if needed
if [ ! -f ".env" ]; then
	if [ -f ".env.example" ]; then
		echo "Creating .env from .env.example..."
		cp ".env.example" ".env"
		echo "WARNING: IMPORTANT - Edit .env and set secure passwords!"
	else
		echo "WARNING: .env.example not found"
	fi
else
	echo "INFO: .env already exists, skipping..."
fi

# Start services
echo "Starting Docker services..."
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
"$SCRIPT_DIR/dev-up.sh"

# Check if Laravel API exists
if [ -f "apps/api/composer.json" ]; then
	echo "Laravel API detected. Next steps:"
	echo "  1. cd apps/api"
	echo "  2. composer install"
	echo "  3. php artisan key:generate"
	echo "  4. php artisan migrate"
fi

# Check if Nuxt Web exists
if [ -f "apps/web/package.json" ]; then
	echo "Nuxt Web detected. Next steps:"
	echo "  1. cd apps/web"
	echo "  2. npm install"
	echo "  3. npm run dev"
fi

echo "Initialization complete!"
