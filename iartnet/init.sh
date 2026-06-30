#!/bin/bash
# file: init.sh
set -e

echo "Avvio ambiente Docker IARTNET..."

# Avvia i container definiti in infra
docker compose -f infra/docker/docker-compose.yml up -d

# Attesa che PostgreSQL sia pronto
echo "Attesa database PostgreSQL LTS..."
until docker exec iartnet-db pg_isready -U iartnet -d iartnet_master >/dev/null 2>&1; do
	sleep 2
done

echo "Ambiente pronto. Database attivo su porta 5432."
