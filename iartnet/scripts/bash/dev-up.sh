#!/bin/bash
# IARTNET Development Environment - Start Services
# Usage: ./scripts/bash/dev-up.sh

set -e

echo "Starting IARTNET Docker environment..."

COMPOSE_FILE="infra/docker/docker-compose.yml"
if [ ! -f "$COMPOSE_FILE" ]; then
	echo "ERROR: docker-compose.yml not found at $COMPOSE_FILE" >&2
	exit 1
fi

# Check if .env exists, if not, copy from .env.example
if [ ! -f ".env" ]; then
	if [ -f ".env.example" ]; then
		echo "Creating .env from .env.example..."
		cp ".env.example" ".env"
		echo "WARNING: Please edit .env and set your passwords before continuing!"
	else
		echo "WARNING: .env.example not found. Using defaults."
	fi
fi

# Start services
echo "Starting Docker Compose services..."
docker compose -f "$COMPOSE_FILE" up -d

# Wait for PostgreSQL to be ready
echo "Waiting for PostgreSQL to be ready..."
MAX_ATTEMPTS=30
ATTEMPT=0
READY=0

while [ $ATTEMPT -lt $MAX_ATTEMPTS ] && [ $READY -eq 0 ]; do
	sleep 2
	if docker exec iartnet-db pg_isready -U iartnet >/dev/null 2>&1; then
		READY=1
		echo "OK: PostgreSQL is ready!"
	else
		ATTEMPT=$((ATTEMPT + 1))
		echo "  Attempt $ATTEMPT/$MAX_ATTEMPTS..."
	fi
done

if [ $READY -eq 0 ]; then
	echo "ERROR: PostgreSQL failed to become ready after $MAX_ATTEMPTS attempts" >&2
	exit 1
fi

echo "OK: IARTNET development environment is ready!"
echo "   Database: localhost:5432"
echo "   Redis: localhost:6379"
