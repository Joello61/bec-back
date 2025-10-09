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

# Copier les fichiers de configuration Composer ET Symfony
COPY composer.json composer.lock symfony.lock* ./

# Installer les dépendances PHP (prod only) SANS les scripts
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist

# Copier TOUT le code source maintenant
COPY . .

# Générer l'autoloader optimisé maintenant que src/ est présent
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# Vérifier que la classe Kernel existe bien
RUN test -f src/Kernel.php || (echo "❌ src/Kernel.php manquant!" && exit 1)

# Générer les clés JWT si LexikJWTAuthenticationBundle est installé
RUN mkdir -p config/jwt \
    && php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true

# Warm-up du cache Symfony en mode production
RUN APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --no-warmup \
    && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup

# --------------------------------------------------------------------------
# Étape 2 : Image finale PHP-FPM Alpine (optimisée pour production)
# --------------------------------------------------------------------------
FROM php:8.2-fpm-alpine AS symfony

# Installer uniquement les dépendances runtime (pas les -dev)
RUN apk add --no-cache \
    icu-libs \
    postgresql-libs \
    libxml2 \
    oniguruma \
    fcgi \
    bash

# Copier les extensions PHP compilées depuis le stage build
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

# Configuration PHP pour production
RUN { \
        echo 'memory_limit=256M'; \
        echo 'max_execution_time=60'; \
        echo 'upload_max_filesize=10M'; \
        echo 'post_max_size=10M'; \
        echo 'date.timezone=Europe/Paris'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/php-prod.ini

# Configuration PHP-FPM pour écouter sur 0.0.0.0:3040
RUN sed -i 's/listen = .*/listen = 0.0.0.0:3040/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/;pm.status_path = .*/pm.status_path = \/status/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/pm = .*/pm = dynamic/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/pm.max_children = .*/pm.max_children = 20/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/pm.start_servers = .*/pm.start_servers = 2/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/pm.min_spare_servers = .*/pm.min_spare_servers = 1/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/pm.max_spare_servers = .*/pm.max_spare_servers = 3/' /usr/local/etc/php-fpm.d/www.conf

# Copier l'application depuis le build avec les bonnes permissions
WORKDIR /var/www/html
COPY --from=build --chown=www-data:www-data /app ./

# Créer les dossiers critiques avec bonnes permissions
RUN mkdir -p var/cache var/log public/uploads \
    && chown -R www-data:www-data var public/uploads \
    && chmod -R 775 var public/uploads

# Utiliser l'utilisateur www-data (déjà existant dans l'image PHP-FPM)
USER www-data

# Exposer le port PHP-FPM
EXPOSE 3040

# Healthcheck pour CapRover (vérifie que PHP-FPM répond)
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD SCRIPT_NAME=/status \
        SCRIPT_FILENAME=/status \
        REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect 127.0.0.1:3040 || exit 1

# Lancer PHP-FPM en foreground
CMD ["php-fpm", "-F"]
