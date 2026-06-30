#!/bin/bash
# IARTNET Development Environment - Stop Services
# Usage: ./scripts/bash/dev-down.sh

set -e

echo "Stopping IARTNET Docker environment..."

COMPOSE_FILE="infra/docker/docker-compose.yml"
if [ ! -f "$COMPOSE_FILE" ]; then
	echo "ERROR: docker-compose.yml not found at $COMPOSE_FILE" >&2
	exit 1
fi

docker compose -f "$COMPOSE_FILE" down

echo "OK: IARTNET services stopped"
