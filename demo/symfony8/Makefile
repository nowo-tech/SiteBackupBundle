# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
# Bash: /bin/sh + Docker Desktop CLI symlink on WSL can yield "Permission denied" (Error 127).
SHELL := /bin/bash
COMPOSE ?= $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")

.PHONY: help up down build install shell logs test cache-clear update-bundle ensure-up verify

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../..)
SERVICE_PHP := php

help:
	@echo "Symfony 8 Demo - Site Backup Mode Bundle"
	@echo ""
	@echo "  make up            Start FrankenPHP demo"
	@echo "  make down          Stop demo"
	@echo "  make test          Run PHPUnit smoke tests"
	@echo "  make verify        HTTP healthcheck on PORT"
	@echo "  make update-bundle Sync path-repo bundle + clear cache"
	@echo ""

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	$(COMPOSE) up -d --build
	@echo "Waiting for MySQL + PHP..."
	@sleep 5
	@echo "Installing dependencies..."
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction
	@# Give FrankenPHP time to finish waiting for vendor and boot workers
	@sleep 3
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

ensure-up:
	@if ! $(COMPOSE) exec -T php true 2>/dev/null; then \
		echo "Starting demo container..."; \
		$(COMPOSE) up -d; \
		sleep 3; \
	fi

down:
	$(COMPOSE) down

build:
	$(COMPOSE) build --no-cache

install: ensure-up
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	$(COMPOSE) exec php sh

logs:
	$(COMPOSE) logs -f

test: ensure-up update-bundle
	$(COMPOSE) exec -T -e APP_ENV=test php php bin/phpunit

cache-clear: ensure-up
	$(COMPOSE) exec -T php php bin/console cache:clear

# REQ-DEMO-007
update-bundle: ensure-up
	$(COMPOSE) exec -T php composer update nowo-tech/site-backup-bundle --no-interaction
	$(COMPOSE) exec -T php php bin/console cache:clear --env=dev

verify: ensure-up
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8056; \
	code=$$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/" 2>/dev/null || echo "000"); \
	if [ "$$code" != "200" ] && [ "$$code" != "302" ]; then echo "Healthcheck failed: $$code"; exit 1; fi; \
	echo "Healthcheck OK ($$code)"

# REQ-MAKE-008: update-deps
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-update-deps.mk
