# Étape 1 : builder l’application PHP
FROM composer:2 AS vendor

WORKDIR /app

# Copier seulement les fichiers de dépendances pour accélérer le build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist

# Copier le reste du projet
COPY . .

# Optimiser l'autoload
RUN composer dump-autoload --no-dev --optimize


# Étape 2 : image finale
FROM php:8.2-fpm-alpine AS symfony

# Installer les extensions nécessaires à Symfony
RUN apk add --no-cache bash git unzip libpq-dev icu-dev \
    && docker-php-ext-install intl pdo pdo_pgsql opcache

WORKDIR /var/www/html

# Copier les fichiers du projet et les dépendances vendor
COPY --from=vendor /app ./

# Créer le dossier var s'il n'existe pas et configurer les permissions
RUN mkdir -p var \
    && chown -R www-data:www-data var

# Exposer le port PHP-FPM
EXPOSE 9000

# Lancer PHP-FPM
CMD ["php-fpm"]
