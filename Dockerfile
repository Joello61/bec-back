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

# Install PHP dependencies (leverage cache)
COPY composer.json composer.lock symfony.lock* ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist

# Copy application
COPY . .

# Generate autoloader
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# Copy .env.dist to .env if it doesn't exist, so commands don't fail
RUN cp .env.dist .env || true

# Generate JWT keys
RUN mkdir -p config/jwt \
    && php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true

# Warm up cache with dummy env vars for build time
# CORRECTION : Les variables doivent être définies pour CHAQUE commande de la chaîne
RUN APP_SECRET=dummysecretforthebuild \
    TRUSTED_PROXIES=127.0.0.1 \
    APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear \
    && APP_SECRET=dummysecretforthebuild \
       TRUSTED_PROXIES=127.0.0.1 \
       APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup

# =============================================================================
# Stage 2: Production
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

# Copy PHP extensions from builder
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

# Copy application files from builder with correct owner
COPY --from=builder --chown=www-data:www-data /app ./

# AMÉLIORATION SÉCURITÉ : On s'assure que www-data peut écrire, sans utiliser 777
# Les répertoires sont déjà créés dans l'étape précédente, on ajuste juste les permissions
RUN chown -R www-data:www-data var public/uploads

# Switch to non-root user for security
USER www-data

# Expose port
EXPOSE 3040

# ==================== CMD avec support multi-modes ====================
# RUN_MODE:
#   - "web" (défaut) : Serveur web PHP built-in
#   - "worker" : Worker Messenger pour les tâches async
#   - "scheduler" : Worker Scheduler pour les tâches planifiées (cron)
CMD if [ "$RUN_MODE" = "worker" ]; then \
        echo "🔄 Démarrage du Worker Messenger (async)..."; \
        php bin/console messenger:consume async --limit=100 --memory-limit=256M --time-limit=3600 -vv; \
    elif [ "$RUN_MODE" = "scheduler" ]; then \
        echo "⏰ Démarrage du Worker Scheduler (expiration)..."; \
        php bin/console messenger:consume scheduler_expiration --limit=50 --memory-limit=128M --time-limit=7200 -vv; \
    else \
        echo "🌐 Démarrage du serveur web sur le port 3040..."; \
        php -S 0.0.0.0:3040 -t public; \
    fi

