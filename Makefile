COMPOSE = docker compose
EXEC    = $(COMPOSE) exec store-api

.PHONY: up down build install migrate fresh seed test race race-py catalog-bulk logs shell

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

build:
	$(COMPOSE) build

install:
	$(EXEC) composer install
	$(EXEC) php artisan key:generate --force

migrate:
	$(EXEC) php artisan migrate --force

fresh:
	$(EXEC) php artisan migrate:fresh --seed --force

seed:
	$(EXEC) php artisan db:seed --force

catalog-bulk:
	$(EXEC) php artisan catalog:seed-bulk --count=5000 --keys=2

test:
	$(EXEC) php artisan test

race race-py:
	python tools/concurrency/race_test.py

logs:
	$(COMPOSE) logs -f store-api nginx

shell:
	$(EXEC) sh
