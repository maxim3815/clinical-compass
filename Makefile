.DEFAULT_GOAL := help

# Контейнеры работают от пользователя хоста, чтобы файлы в проекте принадлежали ему.
export UID := $(shell id -u)
export GID := $(shell id -g)

COMPOSE := docker compose
RUN := $(COMPOSE) run --rm
PHP := $(RUN) php
# Команды, которым не нужна база: без запуска postgres.
PHP_ONLY := $(RUN) --no-deps php
NODE := $(RUN) --no-deps node
ARTISAN := $(PHP) php artisan

.PHONY: help init up down build logs ps shell artisan composer npm migrate fresh test test-fast lint fix psql

help: ## Список команд
	@grep -E '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  make %-10s %s\n", $$1, $$2}'

init: ## Первый запуск: .env, образы, зависимости, ключ, миграции с сидами, запуск
	@[ -f .env ] || cp .env.example .env
	$(COMPOSE) build
	$(PHP_ONLY) composer install
	$(NODE) npm ci
	@grep -q '^APP_KEY=.' .env || $(PHP_ONLY) php artisan key:generate
	@grep -q '^VAPID_PUBLIC_KEY=.' .env || $(PHP_ONLY) php artisan webpush:vapid
	$(ARTISAN) migrate --seed
	$(COMPOSE) up -d --wait

up: ## Запустить окружение и дождаться готовности сервисов
	$(COMPOSE) up -d --wait

down: ## Остановить окружение
	$(COMPOSE) down

build: ## Пересобрать образы
	$(COMPOSE) build

logs: ## Логи сервисов (make logs s=php для одного сервиса)
	$(COMPOSE) logs -f --tail=100 $(s)

ps: ## Состояние сервисов
	$(COMPOSE) ps

shell: ## Shell в контейнере php
	$(PHP) bash

artisan: ## Команда artisan: make artisan c="route:list"
	$(ARTISAN) $(c)

composer: ## Команда composer: make composer c="require vendor/package"
	$(PHP_ONLY) composer $(c)

npm: ## Команда npm: make npm c="install package"
	$(NODE) npm $(c)

migrate: ## Применить миграции
	$(ARTISAN) migrate

fresh: ## Пересоздать БД и заполнить демо-данными
	$(ARTISAN) migrate:fresh --seed

test: ## Все тесты, включая браузерные (make test c="--filter=Login")
	$(NODE) npm run build
	$(COMPOSE) build tests
	$(RUN) tests php artisan test $(c)

test-fast: ## Тесты без браузерных (make test-fast c="--filter=Login")
	$(ARTISAN) test --testsuite=Unit,Feature,Concurrency $(c)

lint: ## Проверки стиля, типов и статический анализ
	$(PHP_ONLY) vendor/bin/pint --parallel --test
	$(PHP_ONLY) vendor/bin/phpstan analyse --memory-limit=1G
	$(NODE) npm run check
	$(NODE) npm run types:check

fix: ## Автоисправление стиля
	$(PHP_ONLY) vendor/bin/pint --parallel
	$(NODE) npm run check:fix

psql: ## Консоль PostgreSQL
	$(COMPOSE) exec postgres sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'
