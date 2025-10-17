# =============================================================================
# 🧰 STAGE 1 : BUILDER — Compile et prépare le code Symfony pour la prod
# =============================================================================
FROM php:8.2-cli-alpine AS builder

WORKDIR /app

# -------------------------------------------------------------------------
# 1️⃣ Installer les dépendances système et extensions PHP
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
# 2️⃣ Copier uniquement les fichiers nécessaires à Composer
# -------------------------------------------------------------------------
COPY composer.json composer.lock symfony.lock* ./

# -------------------------------------------------------------------------
# 3️⃣ Copier le binaire Composer depuis l’image officielle
# -------------------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# -------------------------------------------------------------------------
# 4️⃣ Installer les dépendances PHP (sans dev)
# -------------------------------------------------------------------------
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader

# -------------------------------------------------------------------------
# 5️⃣ Copier le reste du projet
# -------------------------------------------------------------------------
COPY . .

# -------------------------------------------------------------------------
# 6️⃣ Créer un .env.local minimal avant toute commande Symfony (clé de voûte !)
# -------------------------------------------------------------------------
RUN echo "APP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET=dummy_secret\nDATABASE_URL=sqlite:///var/data.db" > .env.local

# -------------------------------------------------------------------------
# 7️⃣ Indiquer à Symfony Runtime qu’il ne doit PAS lire .env
# -------------------------------------------------------------------------
ENV APP_RUNTIME_DOTENV_VARS=APP_ENV,APP_DEBUG,APP_SECRET,DATABASE_URL

# -------------------------------------------------------------------------
# 8️⃣ Générer autoload, clés JWT, et précompiler le cache Symfony
# -------------------------------------------------------------------------
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && mkdir -p config/jwt \
 && php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true \
 && php bin/console cache:clear --env=prod --no-warmup \
 && php bin/console cache:warmup --env=prod

# =============================================================================
# 🚀 STAGE 2 : RUNTIME — Image finale légère pour CapRover
# =============================================================================
FROM php:8.2-cli-alpine

WORKDIR /var/www/html

# -------------------------------------------------------------------------
# 1️⃣ Installer uniquement les libs nécessaires à l'exécution
# -------------------------------------------------------------------------
RUN apk add --no-cache \
    bash \
    icu-libs \
    postgresql-libs \
    libxml2 \
    oniguruma \
    libzip

# -------------------------------------------------------------------------
# 2️⃣ Copier les extensions PHP et la config
# -------------------------------------------------------------------------
COPY --from=builder /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions

# -------------------------------------------------------------------------
# 3️⃣ Configurer PHP pour la production
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
# 4️⃣ Copier le code précompilé depuis le builder
# -------------------------------------------------------------------------
COPY --from=builder --chown=www-data:www-data /app ./

# -------------------------------------------------------------------------
# 5️⃣ Créer les dossiers nécessaires (cache, logs, uploads)
# -------------------------------------------------------------------------
RUN mkdir -p var/cache var/log public/uploads \
    && chmod -R 777 var public/uploads

# -------------------------------------------------------------------------
# 6️⃣ Exposer le port HTTP pour CapRover
# -------------------------------------------------------------------------
EXPOSE 3040

# -------------------------------------------------------------------------
# 7️⃣ Lancer selon le rôle (serveur web ou worker Messenger)
# -------------------------------------------------------------------------
CMD if [ "$RUN_MODE" = "worker" ]; then \
        php bin/console messenger:consume async --limit=10 --memory-limit=256M --time-limit=3600 -vv; \
    else \
        php -S 0.0.0.0:3040 -t public; \
    fi
