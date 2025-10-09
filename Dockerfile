# --------------------------------------------------------------------------
# Étape 1 : Builder l'application PHP + extensions nécessaires
# --------------------------------------------------------------------------
FROM php:8.2-fpm-alpine AS build

WORKDIR /app

# Installer les dépendances système pour PHP et Symfony
RUN apk add --no-cache \
    bash \
    icu-dev \
    postgresql-dev \
    libxml2-dev \
    oniguruma-dev \
    git \
    unzip \
    && docker-php-ext-install intl pdo pdo_pgsql opcache

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copier uniquement les fichiers de dépendances
COPY composer.json composer.lock symfony.lock ./

# Installer les dépendances PHP (prod only)
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative

# Copier le reste du projet
COPY . .

# Générer les clés JWT (si tu utilises LexikJWTAuthenticationBundle)
RUN mkdir -p config/jwt \
    && php bin/console lexik:jwt:generate-keypair --skip-if-exists || true

# Warm-up du cache Symfony
RUN APP_ENV=prod php bin/console cache:clear \
    && APP_ENV=prod php bin/console cache:warmup

# --------------------------------------------------------------------------
# Étape 2 : Image finale PHP-FPM Alpine
# --------------------------------------------------------------------------
FROM php:8.2-fpm-alpine AS symfony

# Installer uniquement les dépendances runtime (pas les -dev)
RUN apk add --no-cache \
    icu-libs \
    postgresql-libs \
    libxml2 \
    oniguruma \
    fcgi

# Copier les extensions compilées depuis le stage build
COPY --from=build /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions

# Configuration OPcache optimisée pour production
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.save_comments=1'; \
        echo 'opcache.enable_cli=0'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

# Configuration PHP-FPM pour écouter sur 0.0.0.0:3040
RUN sed -i 's/listen = .*/listen = 0.0.0.0:3040/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/;pm.status_path = .*/pm.status_path = \/status/' /usr/local/etc/php-fpm.d/www.conf

# Copier l'application depuis le build
WORKDIR /var/www/html
COPY --from=build --chown=www-data:www-data /app ./

# Créer les dossiers critiques avec bonnes permissions
RUN mkdir -p var/cache var/log public/uploads \
    && chown -R www-data:www-data var public/uploads

# Utiliser l'utilisateur www-data (déjà existant dans l'image PHP-FPM)
USER www-data

# Exposer le port PHP-FPM
EXPOSE 3040

# Healthcheck pour CapRover (vérifie que PHP-FPM répond)
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD SCRIPT_NAME=/status SCRIPT_FILENAME=/status REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:3040 || exit 1

# Lancer PHP-FPM en foreground
CMD ["php-fpm", "-F"]
