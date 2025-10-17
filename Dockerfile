# =============================================================================
# 🧰 STAGE 1 : BUILDER — Compilation et préparation du code Symfony
# =============================================================================
FROM php:8.2-cli-alpine AS builder

WORKDIR /app

# -------------------------------------------------------------------------
# 1️⃣ Installer les dépendances système et extensions PHP nécessaires
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
# 2️⃣ Copier uniquement les fichiers nécessaires à Composer (cache efficace)
# -------------------------------------------------------------------------
COPY composer.json composer.lock symfony.lock* ./

# -------------------------------------------------------------------------
# 3️⃣ Copier le binaire Composer depuis l’image officielle
# -------------------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# -------------------------------------------------------------------------
# 4️⃣ Installer les dépendances PHP sans les dev
# -------------------------------------------------------------------------
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader

# -------------------------------------------------------------------------
# 5️⃣ Copier le reste du code de l’application
# -------------------------------------------------------------------------
COPY . .

# -------------------------------------------------------------------------
# 6️⃣ Définir les variables d’environnement Symfony
# -------------------------------------------------------------------------
ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV APP_SECRET=dummy_secret

# -------------------------------------------------------------------------
# 7️⃣ Générer l’autoloader optimisé et les clés JWT
# -------------------------------------------------------------------------
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && mkdir -p config/jwt \
 && php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true

# -------------------------------------------------------------------------
# 8️⃣ Créer un .env.local temporaire pour le build (empêche l’erreur .env)
# -------------------------------------------------------------------------
RUN echo "APP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET=dummy_secret" > .env.local

# -------------------------------------------------------------------------
# 9️⃣ Précompiler le cache Symfony (sans lire .env)
# -------------------------------------------------------------------------
RUN php bin/console cache:clear --env=prod --no-warmup \
 && php bin/console cache:warmup --env=prod

# =============================================================================
# 🚀 STAGE 2 : RUNTIME — Image de production finale et légère
# =============================================================================
FROM php:8.2-cli-alpine

WORKDIR /var/www/html

# -------------------------------------------------------------------------
# 1️⃣ Installer uniquement les bibliothèques nécessaires à l’exécution
# -------------------------------------------------------------------------
RUN apk add --no-cache \
    bash \
    icu-libs \
    postgresql-libs \
    libxml2 \
    oniguruma \
    libzip

# -------------------------------------------------------------------------
# 2️⃣ Copier les extensions PHP et la configuration du builder
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
# 4️⃣ Copier uniquement le code compilé depuis le builder
# -------------------------------------------------------------------------
COPY --from=builder --chown=www-data:www-data /app ./

# -------------------------------------------------------------------------
# 5️⃣ Préparer les répertoires d’exécution
# -------------------------------------------------------------------------
RUN mkdir -p var/cache var/log public/uploads \
    && chmod -R 777 var public/uploads

# -------------------------------------------------------------------------
# 6️⃣ Exposer le port HTTP (par défaut 3040)
# -------------------------------------------------------------------------
EXPOSE 3040

# -------------------------------------------------------------------------
# 7️⃣ Commande d’exécution (serveur web ou worker)
# -------------------------------------------------------------------------
CMD if [ "$RUN_MODE" = "worker" ]; then \
        php bin/console messenger:consume async --limit=10 --memory-limit=256M --time-limit=3600 -vv; \
    else \
        php -S 0.0.0.0:3040 -t public; \
    fi
