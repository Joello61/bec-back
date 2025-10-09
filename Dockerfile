# --------------------------------------------------------------------------
# Étape 1 : Builder l’application PHP avec Composer
# --------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Copier uniquement les fichiers de dépendances pour accélérer le build
COPY composer.json composer.lock ./

# Installer les dépendances sans les outils de dev
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --no-cache

# Copier le reste du projet
COPY . .

# Optimiser l'autoload pour la production
RUN composer dump-autoload --no-dev --optimize

# --------------------------------------------------------------------------
# Étape 2 : Image finale PHP-FPM Alpine
# --------------------------------------------------------------------------
FROM php:8.2-fpm-alpine AS symfony

# Installer les dépendances système nécessaires à Symfony
RUN apk add --no-cache bash libpq icu-dev \
    && docker-php-ext-install intl pdo pdo_pgsql opcache

# Activer OPCache pour la production
RUN docker-php-ext-enable opcache

# Copier l'application depuis l'étape précédente
WORKDIR /var/www/html
COPY --from=vendor /app ./

# Créer les dossiers critiques
RUN mkdir -p var/cache var/log public/uploads

# Créer un utilisateur dédié pour plus de sécurité
RUN addgroup -g 1000 appgroup && adduser -u 1000 -G appgroup -D appuser \
    && chown -R appuser:appgroup var public/uploads

# Définir l'utilisateur non-root
USER appuser

# --------------------------------------------------------------------------
# Correction critique pour éviter le 502 :
# PHP-FPM doit écouter sur 0.0.0.0:9000 pour que CapRover/Nginx accède au container
# --------------------------------------------------------------------------
USER root
RUN sed -i 's/listen = .*/listen = 0.0.0.0:9000/' /usr/local/etc/php-fpm.d/www.conf
USER appuser

# Exposer le port PHP-FPM pour CapRover
EXPOSE 9000

# Healthcheck pour CapRover
HEALTHCHECK --interval=30s --timeout=5s CMD wget -qO- http://localhost:9000 || exit 1

# Lancer PHP-FPM
CMD ["php-fpm"]
