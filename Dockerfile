# Étape 1 : builder l’application PHP (Base : composer)
FROM composer:2 AS vendor

WORKDIR /app

# Copier seulement les fichiers de dépendances pour accélérer le build
COPY composer.json composer.lock ./
# Installation des dépendances sans les outils de développement (prod only)
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist

# Copier le reste du projet
COPY . .

# Optimiser l'autoload
RUN composer dump-autoload --no-dev --optimize


# --------------------------------------------------------------------------
# Étape 2 : image finale (Base : php-fpm-alpine)
# --------------------------------------------------------------------------
FROM php:8.2-fpm-alpine AS symfony

# Installer les dépendances système et les extensions PHP nécessaires à Symfony
RUN apk add --no-cache bash git unzip libpq-dev icu-dev \
    && docker-php-ext-install intl pdo pdo_pgsql opcache

WORKDIR /var/www/html

# Copier les fichiers du projet et les dépendances vendor depuis l'étape précédente
COPY --from=vendor /app ./

# Créer les dossiers nécessaires (cache, logs, uploads)
RUN mkdir -p var/cache var/log public/uploads

# ********** CORRECTION CRITIQUE DES PERMISSIONS **********
# Définir l'utilisateur www-data comme propriétaire des dossiers critiques.
# C'est l'utilisateur qui exécute PHP-FPM et doit écrire le cache et les uploads.
RUN chown -R www-data:www-data var public/uploads

# Définir l'utilisateur www-data par défaut
USER www-data

# Exposer le port PHP-FPM (doit correspondre au port 9000 configuré dans CapCaptain)
EXPOSE 9000

# Lancer PHP-FPM
CMD ["php-fpm"]
