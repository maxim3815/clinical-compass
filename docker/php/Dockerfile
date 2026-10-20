# syntax=docker/dockerfile:1

FROM composer:2 AS composer

FROM node:24-trixie-slim AS node

FROM php:8.5-fpm-trixie AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# libfcgi-bin нужен для healthcheck php-fpm (cgi-fcgi), procps для healthcheck воркеров (pgrep).
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libfcgi-bin procps unzip; \
    rm -rf /var/lib/apt/lists/*; \
    install-php-extensions bcmath intl pcntl pdo_pgsql sockets zip

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Пул описан целиком в zz-app.conf. Стандартный www.conf задаёт user/group,
# которые не нужны процессу, запущенному не от root.
RUN rm /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/www.conf.default

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

FROM base AS dev

ARG UID=1000
ARG GID=1000
ARG XDEBUG=false

# safe.directory: в Docker Desktop корень смонтированного кода виден как принадлежащий root,
# и без исключения git (а за ним composer) отказывается работать с репозиторием.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git; \
    rm -rf /var/lib/apt/lists/*; \
    git config --system --add safe.directory /var/www/html; \
    if [ "${XDEBUG}" = "true" ]; then install-php-extensions xdebug; fi; \
    cp "${PHP_INI_DIR}/php.ini-development" "${PHP_INI_DIR}/php.ini"

# Node нужен рядом с PHP: Vite-плагин Wayfinder вызывает php artisan wayfinder:generate.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN set -eux; \
    ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm; \
    ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx; \
    node --version; \
    npm --version

# npm закреплён версией Node из образа, предложение обновиться только засоряет вывод.
ENV NPM_CONFIG_UPDATE_NOTIFIER=false

COPY docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
COPY docker/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

# Пользователь с UID/GID хоста, чтобы файлы в смонтированном коде принадлежали разработчику.
# Группа или пользователь с таким ID уже могут быть в образе (например, GID 20 на macOS).
RUN set -eux; \
    getent group "${GID}" >/dev/null || groupadd --gid "${GID}" app; \
    getent passwd "${UID}" >/dev/null || useradd --no-log-init --uid "${UID}" --gid "${GID}" --create-home --shell /bin/bash app

USER ${UID}:${GID}

# Версия Chromium должна совпадать с пакетом playwright из package-lock.json. Версия
# вынесена в отдельный файл, чтобы Chromium перекачивался только при смене версии
# playwright, а не при любом изменении lock-файла.
FROM node AS playwright-version

RUN --mount=type=bind,source=package-lock.json,target=/tmp/package-lock.json \
    node -p "require('/tmp/package-lock.json').packages['node_modules/playwright'].version" > /playwright-version

# Браузерные тесты: dev плюс Chromium для Playwright. Отдельный stage, чтобы dev-образ не тяжелел.
FROM dev AS test

ARG UID=1000
ARG GID=1000

ENV PLAYWRIGHT_BROWSERS_PATH=/opt/playwright

USER root

COPY --from=playwright-version /playwright-version /tmp/playwright-version

RUN set -eux; \
    npx --yes "playwright@$(cat /tmp/playwright-version)" install --with-deps --only-shell chromium; \
    rm -rf /var/lib/apt/lists/* /root/.npm

USER ${UID}:${GID}

# Продакшен: три образа с кодом внутри (compose.prod.yaml). prod — php-fpm, он же запускает
# очередь, планировщик и Reverb; prod-nginx — nginx со статикой; prod-ssr — серверная
# отрисовка страниц на Node.

# Зависимости Composer и сборка фронтенда. Результат от процессора не зависит, поэтому stage
# работает на платформе сборки: образ под amd64 на Mac с arm64 собирается без эмуляции Node.
# Расширений из base здесь не нужно: artisan для Wayfinder обходится стандартными.
FROM --platform=$BUILDPLATFORM node:24-trixie-slim AS build-node

FROM --platform=$BUILDPLATFORM php:8.5-cli-trixie AS build

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=build-node /usr/local/bin/node /usr/local/bin/node
COPY --from=build-node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    NPM_CONFIG_UPDATE_NOTIFIER=false

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .

# Адрес Reverb для браузера вшивается в скрипты при сборке.
ARG VITE_APP_NAME
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME

# Каталоги storage в контекст сборки не попадают (.dockerignore), а artisan без них не работает.
RUN set -eux; \
    mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; \
    composer dump-autoload --no-dev --optimize --no-interaction; \
    npm run build:ssr; \
    rm -rf node_modules

FROM base AS prod

RUN cp "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini"

COPY docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/php/php-fpm.d/zz-prod.conf /usr/local/etc/php-fpm.d/zz-prod.conf
COPY docker/php/prod-entrypoint.sh /usr/local/bin/prod-entrypoint

COPY --from=build /app /var/www/html

# Код принадлежит root и только читается, писать процессы могут лишь в storage и bootstrap/cache.
RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data

ENTRYPOINT ["prod-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.30-alpine AS prod-nginx

COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/public /var/www/html/public

FROM node AS prod-ssr

ENV NODE_ENV=production \
    NPM_CONFIG_UPDATE_NOTIFIER=false

WORKDIR /app

# Серверной сборке нужны пакеты из dependencies (vue, reka-ui и другие), Vite и плагины — нет.
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --omit=optional --ignore-scripts --no-audit --no-fund && npm cache clean --force

COPY --from=build /app/bootstrap/ssr bootstrap/ssr

USER node

EXPOSE 13714

CMD ["node", "bootstrap/ssr/app.js"]
