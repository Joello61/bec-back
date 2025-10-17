# =============================================================================
# 🧰 STAGE 1 : BUILDER — Build de l’application et dépendances
# =============================================================================
FROM php:8.2-cli-alpine AS builder

WORKDIR /app

# -------------------------------------------------------------------------
# 1️⃣ Install system dependencies + PHP extensions
# -------------------------------------------------------------------------
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

# -------------------------------------------------------------------------
# 2️⃣ Copier uniquement les fichiers nécessaires pour Composer (cache layer)
# -------------------------------------------------------------------------
COPY composer.json composer.lock symfony.lock* ./

# -------------------------------------------------------------------------
# 3️⃣ Installer les dépendances PHP (sans dev, sans scripts)
# -------------------------------------------------------------------------
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader

# -------------------------------------------------------------------------
# 4️⃣ Copier le reste de l'application
# -------------------------------------------------------------------------
COPY . .

# -------------------------------------------------------------------------
# 5️⃣ Définir les variables d'environnement Symfony
# -------------------------------------------------------------------------
ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV APP_SECRET=dummy_secret

# -------------------------------------------------------------------------
# 6️⃣ Générer l’autoloader et la paire de clés JWT
# -------------------------------------------------------------------------
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && mkdir -p config/jwt \
 && php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true

# -------------------------------------------------------------------------
# 7️⃣ Préparer un .env.local temporaire pour le build
# -------------------------------------------------------------------------
RUN echo "APP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET=dummy_secret" > .env.local

# -------------------------------------------------------------------------
# 8️⃣ Précompiler le cache Symfony en prod sans .env
# -------------------------------------------------------------------------
RUN php bin/console cache:clear --env=prod --no-warmup \
 && php bin/console cache:warmup --env=prod

# =============================================================================
# 🚀 STAGE 2 : RUNTIME — Image finale ultra-légère
# =============================================================================
FROM php:8.2-cli-alpine

WORKDIR /var/www/html

# -------------------------------------------------------------------------
# 1️⃣ Install runtime dependencies (sans dev libs)
# -------------------------------------------------------------------------
RUN apk add --no-cache \
    bash \
    icu-libs \
    postgresql-libs \
    libxml2 \
    oniguruma \
    libzip

# -------------------------------------------------------------------------
# 2️⃣ Copier les extensions PHP et la config du builder
# -------------------------------------------------------------------------
COPY --from=builder /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions

# -------------------------------------------------------------------------
# 3️⃣ Configurer PHP pour la prod
# -------------------------------------------------------------------------
RUN { \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 60'; \
        echo 'date.timezone = Europe/Paris'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 256'; \
        echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/php-prod.ini

# -------------------------------------------------------------------------
# 4️⃣ Copier uniquement les fichiers utiles
# -------------------------------------------------------------------------
COPY --from=builder --chown=www-data:www-data /app ./

# -------------------------------------------------------------------------
# 5️⃣ Préparer les répertoires nécessaires
# -------------------------------------------------------------------------
RUN mkdir -p var/cache var/log public/uploads \
    && chmod -R 777 var public/uploads

# -------------------------------------------------------------------------
# 6️⃣ Exposer le port et définir la commande par défaut
# -------------------------------------------------------------------------
EXPOSE 3040

# Mode par défaut : serveur web interne (PHP built-in)
# Sinon, lancer un worker Messenger si RUN_MODE=worker
CMD if [ "$RUN_MODE" = "worker" ]; then \
        php bin/console messenger:consume async --limit=10 --memory-limit=256M --time-limit=3600 -vv; \
    else \
        php -S 0.0.0.0:3040 -t public; \
    fi
