# ---- 1. composer závislosti ----
FROM serversideup/php:8.4-cli AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---- 2. build frontend assetov ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- 3. aplikácia ----
FROM serversideup/php:8.4-fpm-nginx
# migrácie musia bežať pri každom deploy, inak sa nový kód pozrie na starú schému
ENV AUTORUN_ENABLED=true
ENV AUTORUN_LARAVEL_MIGRATION=true
ENV AUTORUN_LARAVEL_MIGRATION_ISOLATION=true
ENV AUTORUN_LARAVEL_STORAGE_LINK=false
ENV AUTORUN_LARAVEL_CONFIG_CACHE=false
ENV AUTORUN_LARAVEL_ROUTE_CACHE=false
ENV AUTORUN_LARAVEL_VIEW_CACHE=false
ENV AUTORUN_LARAVEL_EVENT_CACHE=false
ENV SSL_MODE=off
USER root
RUN install-php-extensions gd exif
USER www-data
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev