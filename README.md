# Клинический компас

Цифровой ассистент врача для ведения пациентов с тревожно-депрессивной симптоматикой.

## Требования

- Docker с Compose v2 (Docker Desktop на macOS или Docker Engine на Linux).
- `make`.

PHP, Composer и Node.js локально не нужны: всё запускается в контейнерах.

## Запуск

```sh
make init
```

Команда создаёт `.env` из `.env.example`, собирает образы, ставит зависимости Composer и npm, генерирует ключ приложения, применяет миграции с сидами и поднимает окружение. Дальше окружение запускается командой `make up`.

## Адреса

| Сервис | Адрес |
|---|---|
| Приложение | http://localhost:8080 |
| Vite (dev server) | http://localhost:5173 |
| PostgreSQL | `localhost:54320`, логин и пароль из `.env` (`DB_USERNAME`, `DB_PASSWORD`) |

Порты публикуются только на `127.0.0.1`. Если порт занят, поменяйте `APP_PORT`, `VITE_PORT` или `FORWARD_DB_PORT` в `.env` (вместе с `APP_PORT` поправьте `APP_URL`) и выполните `make up`.

## Команды

| Команда | Что делает |
|---|---|
| `make init` | Первый запуск |
| `make up` | Запустить окружение и дождаться готовности сервисов |
| `make down` | Остановить окружение |
| `make build` | Пересобрать образы |
| `make logs` | Логи всех сервисов, `make logs s=php` для одного |
| `make ps` | Состояние сервисов |
| `make shell` | Shell в контейнере php |
| `make artisan c="route:list"` | Команда artisan |
| `make composer c="require vendor/package"` | Команда composer |
| `make npm c="install package"` | Команда npm |
| `make migrate` | Применить миграции |
| `make fresh` | Пересоздать БД и заполнить демо-данными |
| `make test` | Тесты, `make test c="--filter=Login"` для части тестов |
| `make lint` | Проверки стиля и статический анализ |
| `make fix` | Автоисправление стиля |
| `make psql` | Консоль PostgreSQL |

## Окружение

| Сервис | Что делает |
|---|---|
| `nginx` | Отдаёт `public/` и передаёт PHP-запросы в `php` |
| `php` | php-fpm 8.5 |
| `node` | Vite с HMR |
| `postgres` | PostgreSQL 18: база приложения и база `testing` для тестов |
| `queue` | Обработчик очереди (`queue:listen`, подхватывает изменения кода) |
| `scheduler` | Планировщик (`schedule:work`) |

Очередь, кэш и сессии хранятся в PostgreSQL. Контейнеры работают от пользователя хоста, поэтому созданные в них файлы принадлежат вам.

### Xdebug

1. В `.env` поставьте `XDEBUG=true` и выполните `make build`, затем `make up`.
2. Задайте режим, например `XDEBUG_MODE=debug`, и снова выполните `make up`.
3. Отладка включается триггером (`XDEBUG_TRIGGER` или расширение браузера), клиент на порту 9003. Для PhpStorm имя сервера `clinical-compass`, путь в контейнере `/var/www/html`.
