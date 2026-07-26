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
	docker-compose up -d --build
	@echo "Waiting for container to be ready..."
	@sleep 3
	@echo "Installing dependencies..."
	@docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction
	@# Give FrankenPHP time to finish waiting for vendor and boot workers
	@sleep 3
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

ensure-up:
	@if ! docker-compose exec -T php true 2>/dev/null; then \
		echo "Starting demo container..."; \
		docker-compose up -d; \
		sleep 3; \
	fi

down:
	docker-compose down

build:
	docker-compose build --no-cache

install: ensure-up
	docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	docker-compose exec php sh

logs:
	docker-compose logs -f

test: ensure-up update-bundle
	docker-compose exec -T -e APP_ENV=test php php bin/phpunit

cache-clear: ensure-up
	docker-compose exec -T php php bin/console cache:clear

# REQ-DEMO-007
update-bundle: ensure-up
	docker-compose exec -T php composer update nowo-tech/site-backup-bundle --no-interaction
	docker-compose exec -T php php bin/console cache:clear --env=dev

verify: ensure-up
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8056; \
	code=$$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/" 2>/dev/null || echo "000"); \
	if [ "$$code" != "200" ]; then echo "Healthcheck failed: $$code"; exit 1; fi; \
	echo "Healthcheck OK ($$code)"

# REQ-MAKE-008: update-deps
include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-update-deps.mk
