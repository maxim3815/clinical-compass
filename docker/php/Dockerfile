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
