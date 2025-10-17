# =============================================================================
# Stage 1: Builder
# =============================================================================
FROM php:8.2-cli-alpine AS builder
WORKDIR /app

# Build deps & PHP extensions
RUN apk add --no-cache \
    bash icu-dev postgresql-dev libxml2-dev oniguruma-dev libzip-dev git unzip \
 && docker-php-ext-install intl pdo pdo_pgsql opcache zip

# Composer binaire
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dépendances PHP (pas de scripts pour éviter d’exécuter du code qui lirait .env)
COPY composer.json composer.lock symfony.lock* ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader

# Code
COPY . .

# Désactive la lecture des .env pendant le build et force prod
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_SECRET=dummy_secret \
    APP_RUNTIME_OPTIONS={"disable_dotenv":true,"env":"prod","debug":false}

# Autoloader + clés JWT + warmup cache
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && mkdir -p config/jwt \
 && php bin/console lexik:jwt:generate-keypair --skip-if-exists --env=prod 2>/dev/null || true \
 && php bin/console cache:clear --env=prod --no-warmup \
 && php bin/console cache:warmup --env=prod

# =============================================================================
# Stage 2: Runtime
# =============================================================================
FROM php:8.2-cli-alpine
WORKDIR /var/www/html

RUN apk add --no-cache bash icu-libs postgresql-libs libxml2 oniguruma libzip

COPY --from=builder /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions

RUN { \
      echo 'memory_limit=256M'; \
      echo 'max_execution_time=60'; \
      echo 'date.timezone=Europe/Paris'; \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=256'; \
      echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/php-prod.ini

COPY --from=builder --chown=www-data:www-data /app ./

RUN mkdir -p var/cache var/log public/uploads \
 && chmod -R 777 var public/uploads

EXPOSE 3040
CMD if [ "$RUN_MODE" = "worker" ]; then \
      php bin/console messenger:consume async --limit=10 --memory-limit=256M --time-limit=3600 -vv; \
    else \
      php -S 0.0.0.0:3040 -t public; \
    fi
