# syntax=docker/dockerfile:1.7

# --- Stage 1 : dépendances PHP (prod uniquement) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev --no-scripts

# --- Stage 2 : runtime php-fpm non-root ---
FROM php:8.5-fpm-alpine AS runtime
RUN apk update && apk upgrade --no-cache && apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
    && apk del .build-deps \
    && rm -rf /tmp/* /usr/local/etc/php-fpm.d/*.conf \
    && addgroup -g 10001 -S appuser && adduser -u 10001 -S -G appuser -H -s /sbin/nologin appuser \
    && mkdir -p /app/var && chown appuser:appuser /app/var

COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app
COPY --from=vendor --chown=root:root /app /app
ENV APP_ENV=prod APP_DEBUG=0
USER appuser
EXPOSE 9000
HEALTHCHECK --interval=15s --timeout=3s --retries=3 CMD pidof php-fpm >/dev/null || exit 1
CMD ["php-fpm", "--nodaemonize"]
