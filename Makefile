SHELL := /bin/sh

.PHONY: up down install migrate seed test lint build reset backup-dev config

up:
	docker compose up -d --build

down:
	docker compose down

install:
	docker compose run --rm api composer install
	docker compose run --rm web npm ci
	docker compose run --rm document-worker pip install -r requirements.lock

migrate:
	docker compose exec api php artisan migrate --force

seed:
	docker compose exec api php artisan db:seed --force

test:
	docker compose exec api php artisan test --compact
	docker compose exec web npm test -- --run
	docker compose exec document-worker pytest -q

lint:
	docker compose exec api vendor/bin/pint --format agent
	docker compose exec web npm run lint
	docker compose exec document-worker ruff check .

build:
	docker compose exec web npm run build

config:
	docker compose config --quiet

reset:
	docker compose exec api php artisan migrate:fresh --seed

backup-dev:
	powershell -NoProfile -ExecutionPolicy Bypass -File infra/scripts/backup.ps1 -ComposeFile docker-compose.yml
