# --------------------------------------------------------------------------
# Étape 1 : Builder l'application PHP + extensions nécessaires
# --------------------------------------------------------------------------
FROM php:8.2-fpm-alpine AS build

WORKDIR /app

RUN apk add --no-cache \
    bash \
    icu-dev \
    postgresql-dev \
    libxml2-dev \
    oniguruma-dev \
    git \
    unzip \
    && docker-php-ext-install intl pdo pdo_pgsql opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock symfony.lock* ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

RUN test -f src/Kernel.php || (echo "❌ src/Kernel.php manquant!" && exit 1)

RUN mkdir -p config/jwt \
    && php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true

RUN APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --no-warmup \
    && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup

# --------------------------------------------------------------------------
# Étape 2 : Image finale PHP-FPM Alpine
# --------------------------------------------------------------------------
FROM php:8.2-fpm-alpine AS symfony

RUN apk add --no-cache \
    icu-libs \
    postgresql-libs \
    libxml2 \
    oniguruma \
    fcgi \
    bash

COPY --from=build /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.save_comments=1'; \
        echo 'opcache.enable_cli=0'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

RUN { \
        echo 'memory_limit=256M'; \
        echo 'max_execution_time=60'; \
        echo 'upload_max_filesize=10M'; \
        echo 'post_max_size=10M'; \
        echo 'date.timezone=Europe/Paris'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/php-prod.ini

RUN echo "[www]" > /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "listen = 0.0.0.0:3040" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm = dynamic" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm.max_children = 20" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm.start_servers = 2" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm.min_spare_servers = 1" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm.max_spare_servers = 3" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "pm.status_path = /status" >> /usr/local/etc/php-fpm.d/zz-custom.conf \
    && echo "ping.path = /ping" >> /usr/local/etc/php-fpm.d/zz-custom.conf

WORKDIR /var/www/html
COPY --from=build --chown=www-data:www-data /app ./

RUN mkdir -p var/cache var/log public/uploads \
    && chown -R www-data:www-data var public/uploads \
    && chmod -R 775 var public/uploads

USER www-data

EXPOSE 3040

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD SCRIPT_NAME=/ping \
        SCRIPT_FILENAME=/ping \
        REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect 127.0.0.1:3040 || exit 1

CMD ["php-fpm", "-F"]
