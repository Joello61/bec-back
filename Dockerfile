# =============================================================================
# Stage 1: Builder
# =============================================================================
FROM php:8.2-fpm-alpine AS builder

WORKDIR /app

# Install build dependencies
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

# Install PHP dependencies (cache leveraged)
COPY composer.json composer.lock symfony.lock* ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist

# Copy application
COPY . .

# Generate optimized autoloader
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# Copy .env.dist to .env if missing
RUN cp .env.dist .env || true

# Generate JWT keys
RUN mkdir -p config/jwt \
    && php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true

# Warm up Symfony cache for production
RUN APP_SECRET=dummysecretforthebuild \
    TRUSTED_PROXIES=127.0.0.1 \
    APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear \
    && APP_SECRET=dummysecretforthebuild \
       TRUSTED_PROXIES=127.0.0.1 \
       APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup

# =============================================================================
# Stage 2: Production
# =============================================================================
FROM php:8.2-fpm-alpine

WORKDIR /var/www/html

# Install runtime dependencies: nginx + supervisor + libs
RUN apk add --no-cache \
    bash \
    nginx \
    supervisor \
    icu-libs \
    postgresql-libs \
    libxml2 \
    oniguruma \
    libzip

# Copy PHP extensions and INI files from builder
COPY --from=builder /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions

# Configure PHP for production (optimized)
RUN { \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 10'; \
        echo 'date.timezone = Europe/Paris'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 256'; \
        echo 'opcache.validate_timestamps = 0'; \
        echo 'opcache.preload = /var/www/html/config/preload.php'; \
        echo 'realpath_cache_size = 4096k'; \
        echo 'realpath_cache_ttl = 600'; \
    } > /usr/local/etc/php/conf.d/php-prod.ini

# Copy application from builder
COPY --from=builder --chown=www-data:www-data /app ./

# Copy Nginx and Supervisor configs
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

# Create necessary directories
RUN mkdir -p /run/php /run/nginx var/cache var/log public/uploads \
    && chown -R www-data:www-data var public/uploads /run/php /run/nginx

# Expose HTTP port (CapRover expects 80)
EXPOSE 80

# Healthcheck – only for web mode
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD \
    if [ "$RUN_MODE" = "web" ] || [ -z "$RUN_MODE" ]; then wget -qO- http://127.0.0.1/health || exit 1; fi

# ==================== CMD avec support multi-modes ====================
# RUN_MODE:
#   - "web" (default): Nginx + PHP-FPM
#   - "worker": Messenger async tasks
#   - "scheduler": Cron-like worker
CMD if [ "$RUN_MODE" = "worker" ]; then \
        echo "🔄 Démarrage du Worker Messenger (async)..."; \
        php bin/console messenger:consume async --limit=100 --memory-limit=256M --time-limit=3600 -vv; \
    elif [ "$RUN_MODE" = "scheduler" ]; then \
        echo "⏰ Démarrage du Worker Scheduler (expiration)..."; \
        php bin/console messenger:consume scheduler_expiration --limit=50 --memory-limit=128M --time-limit=7200 -vv; \
    else \
        echo "🌐 Démarrage de Nginx + PHP-FPM..."; \
        /usr/bin/supervisord -c /etc/supervisord.conf; \
    fi
