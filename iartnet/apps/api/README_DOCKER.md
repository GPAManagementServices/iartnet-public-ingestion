<!-- file: /opt/Ingestion/iartnet/apps/api/README_DOCKER.md -->
# IARTNET API — Docker Compose (Laravel 12 + Filament 4 + Postgres 18 + Redis)

## Dove sono i file
- Compose: `/opt/Ingestion/iartnet/infra/docker/api/compose/docker-compose.yml`
- App: `/opt/Ingestion/iartnet/apps/api`

## Prerequisiti
- Docker Engine + Docker Compose v2
- Porta host default: `8088` (configurabile con `HTTP_PORT`)

## Avvio rapido (stage/dev)
1) Crea `.env` (se non esiste):
```bash
cd /opt/Ingestion/iartnet/apps/api
cp -n .env.docker.example .env
