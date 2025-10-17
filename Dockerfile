# =============================================================================
# Stage 1: Builder
# =============================================================================
FROM php:8.2-cli-alpine AS builder

WORKDIR /app

# Install dependencies
RUN apk add --no-cache \
    bash \
    icu-dev \
    postgresql-dev \
    libxml2-dev \
    oniguruma-dev \
    libzip-dev \
    git \
    unzip \
    && docker-php-ext-install intl pdo pdo_pgsql opcache zip

# Copy composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP dependencies (no dev)
COPY composer.json composer.lock symfony.lock* ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist

# Copy application source
COPY . .

# Generate optimized autoloader
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# =============================================================================
# Stage 2: Runtime
# =============================================================================
FROM php:8.2-cli-alpine

WORKDIR /var/www/html

# Install runtime dependencies
RUN apk add --no-cache \
    bash \
    icu-libs \
    postgresql-libs \
    libxml2 \
    oniguruma \
    libzip

# Copy PHP extensions
COPY --from=builder /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions

# Configure PHP for production
RUN { \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 60'; \
        echo 'date.timezone = Europe/Paris'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 256'; \
        echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/php-prod.ini

# Copy application
COPY --from=builder --chown=www-data:www-data /app ./

# Create required directories
RUN mkdir -p var/cache var/log public/uploads \
    && chmod -R 777 var public/uploads

# Copy entrypoint
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 3040

ENTRYPOINT ["docker-entrypoint.sh"]

# Default CMD = run web server
CMD ["php", "-S", "0.0.0.0:3040", "-t", "public"]
