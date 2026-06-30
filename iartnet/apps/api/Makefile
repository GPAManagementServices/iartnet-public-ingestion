# file: /opt/Ingestion/iartnet/apps/api/Makefile
SHELL := /bin/bash
COMPOSE := docker compose -f /opt/Ingestion/iartnet/infra/docker/api/compose/docker-compose.yml
PROJECT_DIR := /opt/Ingestion/iartnet/apps/api

.PHONY: help up down build ps logs shell-app shell-db artisan first-run cache-clear test

help:
	@echo "Targets:"
	@echo "  up          Start stack"
	@echo "  down        Stop stack (keep volumes)"
	@echo "  build       Build app image"
	@echo "  ps          Show containers"
	@echo "  logs        Tail logs"
	@echo "  shell-app   Shell into app container"
	@echo "  shell-db    Shell into postgres container"
	@echo "  artisan     Run artisan (usage: make artisan cmd='about')"
	@echo "  first-run   Generate APP_KEY, storage:link, clear caches"
	@echo "  cache-clear Clear Laravel caches"
	@echo "  test        Run tests"

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

build:
	$(COMPOSE) build --no-cache app

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f --tail=200

shell-app:
	$(COMPOSE) exec app bash || $(COMPOSE) exec app sh

shell-db:
	$(COMPOSE) exec postgres bash || $(COMPOSE) exec postgres sh

artisan:
	@test -n "$(cmd)" || (echo "Missing cmd. Example: make artisan cmd='about'"; exit 1)
	$(COMPOSE) exec app php artisan $(cmd)

first-run:
	# Copia .env se manca
	@if [ ! -f "$(PROJECT_DIR)/.env" ]; then \
	  echo "Creating .env from .env.docker.example"; \
	  cp "$(PROJECT_DIR)/.env.docker.example" "$(PROJECT_DIR)/.env"; \
	fi
	$(COMPOSE) exec app php artisan key:generate --force || true
	$(COMPOSE) exec app php artisan storage:link || true
	$(COMPOSE) exec app php artisan config:clear || true
	$(COMPOSE) exec app php artisan cache:clear || true

cache-clear:
	$(COMPOSE) exec app php artisan config:clear || true
	$(COMPOSE) exec app php artisan cache:clear || true

test:
	$(COMPOSE) exec app php artisan test
