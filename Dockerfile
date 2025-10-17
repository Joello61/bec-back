# =============================================================================
# 🏗️ STAGE 1 : BUILDER
# =============================================================================
FROM php:8.2-cli-alpine AS builder

WORKDIR /app

# -----------------------------------------------------------------------------
# 1️⃣ Installer les dépendances système et extensions PHP nécessaires
# -----------------------------------------------------------------------------
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

# -----------------------------------------------------------------------------
# 2️⃣ Ajouter Composer (depuis l'image officielle)
# -----------------------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# -----------------------------------------------------------------------------
# 3️⃣ Copier les fichiers nécessaires pour installer les dépendances
# -----------------------------------------------------------------------------
COPY composer.json composer.lock symfony.lock* ./

# -----------------------------------------------------------------------------
# 4️⃣ Installer les dépendances PHP (sans dev, sans scripts)
# -----------------------------------------------------------------------------
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader

# -----------------------------------------------------------------------------
# 5️⃣ Copier tout le code source de l'application
# -----------------------------------------------------------------------------
COPY . .

# -----------------------------------------------------------------------------
# 6️⃣ Définir les variables d'environnement pour le build
#    -> On désactive le chargement automatique du .env pendant le build
# -----------------------------------------------------------------------------
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_SECRET=dummy_secret \
    APP_RUNTIME_OPTIONS='{"disable_dotenv":true,"env":"prod","debug":false}'

# -----------------------------------------------------------------------------
# 7️⃣ Générer autoloader, clés JWT et cache Symfony
# -----------------------------------------------------------------------------
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && mkdir -p config/jwt \
 && php bin/console lexik:jwt:generate-keypair --skip-if-exists --env=prod 2>/dev/null || true \
 && php bin/console cache:clear --env=prod --no-warmup \
 && php bin/console cache:warmup --env=prod

# =============================================================================
# 🚀 STAGE 2 : RUNTIME (production)
# =============================================================================
FROM php:8.2-cli-alpine

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# 1️⃣ Installer les librairies nécessaires à l'exécution
# -----------------------------------------------------------------------------
RUN apk add --no-cache \
    bash \
    icu-libs \
    postgresql-libs \
    libxml2 \
    oniguruma \
    libzip

# -----------------------------------------------------------------------------
# 2️⃣ Copier les extensions PHP depuis le builder
# -----------------------------------------------------------------------------
COPY --from=builder /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions

# -----------------------------------------------------------------------------
# 3️⃣ Configurer PHP pour la production
# -----------------------------------------------------------------------------
RUN { \
      echo 'memory_limit = 256M'; \
      echo 'max_execution_time = 60'; \
      echo 'date.timezone = Europe/Paris'; \
      echo 'opcache.enable = 1'; \
      echo 'opcache.memory_consumption = 256'; \
      echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/php-prod.ini

# -----------------------------------------------------------------------------
# 4️⃣ Copier l'application depuis le builder
# -----------------------------------------------------------------------------
COPY --from=builder --chown=www-data:www-data /app ./

# -----------------------------------------------------------------------------
# 5️⃣ Préparer les dossiers nécessaires
# -----------------------------------------------------------------------------
RUN mkdir -p var/cache var/log public/uploads \
 && chmod -R 777 var public/uploads

# -----------------------------------------------------------------------------
# 6️⃣ Exposer le port (par défaut CapRover mappe sur 80)
# -----------------------------------------------------------------------------
EXPOSE 3040

# -----------------------------------------------------------------------------
# 7️⃣ Commande par défaut : PHP server ou worker
# -----------------------------------------------------------------------------
CMD if [ "$RUN_MODE" = "worker" ]; then \
      php bin/console messenger:consume async --limit=10 --memory-limit=256M --time-limit=3600 -vv; \
    else \
      php -S 0.0.0.0:3040 -t public; \
    fi
