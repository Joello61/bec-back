# --------------------------------------------------------------------------
# Étape 1 : Builder l’application PHP + extensions nécessaires
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
COPY composer.json composer.lock ./

# Installer les dépendances PHP (prod only)
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --no-cache \
    && composer dump-autoload --no-dev --optimize

# Copier le reste du projet
COPY . .

# --------------------------------------------------------------------------
# Étape 2 : Image finale PHP-FPM Alpine
# --------------------------------------------------------------------------
FROM php:8.2-fpm-alpine AS symfony

# Copier les extensions compilées depuis le stage build
COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions

# Copier l'application et les dépendances
WORKDIR /var/www/html
COPY --from=build /app ./

# Créer les dossiers critiques
RUN mkdir -p var/cache var/log public/uploads

# Créer un utilisateur dédié pour la sécurité
RUN addgroup -g 1000 appgroup && adduser -u 1000 -G appgroup -D appuser \
    && chown -R appuser:appgroup var public/uploads

# Définir l'utilisateur non-root
USER appuser

# --------------------------------------------------------------------------
# Correction critique pour éviter le 502
# PHP-FPM doit écouter sur 0.0.0.0:9000 pour que CapRover/Nginx accède au container
# --------------------------------------------------------------------------
USER root
RUN sed -i 's/listen = .*/listen = 0.0.0.0:3040/' /usr/local/etc/php-fpm.d/www.conf
USER appuser

# Exposer le port PHP-FPM pour CapRover
EXPOSE 3040

# Healthcheck pour CapRover
HEALTHCHECK --interval=30s --timeout=5s CMD wget -qO- http://localhost:3040 || exit 1

# Lancer PHP-FPM
CMD ["php-fpm", "-F"]
