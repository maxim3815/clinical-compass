.DEFAULT_GOAL := help

# Контейнеры работают от пользователя хоста, чтобы файлы в проекте принадлежали ему.
export UID := $(shell id -u)
export GID := $(shell id -g)

COMPOSE := docker compose
RUN := $(COMPOSE) run --rm
PHP := $(RUN) php
ARTISAN := $(PHP) php artisan

.PHONY: help init up down build logs ps shell artisan composer npm migrate fresh test lint fix psql

help: ## Список команд
	@grep -E '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  make %-10s %s\n", $$1, $$2}'

init: ## Первый запуск: .env, образы, зависимости, ключ, миграции с сидами, запуск
	@[ -f .env ] || cp .env.example .env
	$(COMPOSE) build
	$(RUN) --no-deps php composer install
	$(RUN) --no-deps node npm ci
	@grep -q '^APP_KEY=.' .env || $(RUN) --no-deps php php artisan key:generate
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
	$(RUN) --no-deps php composer $(c)

npm: ## Команда npm: make npm c="install package"
	$(RUN) --no-deps node npm $(c)

migrate: ## Применить миграции
	$(ARTISAN) migrate

fresh: ## Пересоздать БД и заполнить демо-данными
	$(ARTISAN) migrate:fresh --seed

test: ## Тесты (make test c="--filter=Login")
	$(ARTISAN) test $(c)

lint: ## Проверки стиля и статический анализ
	@echo "Линтеры ещё не настроены."

fix: ## Автоисправление стиля
	@echo "Автоисправление ещё не настроено."

psql: ## Консоль PostgreSQL
	$(COMPOSE) exec postgres sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'
