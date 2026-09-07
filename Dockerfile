# syntax=docker/dockerfile:1
#
# Image Docker du backend Symfony (PHP 8.3).
#
# nginx n'est plus embarqué dans cette image (contrairement à la version
# précédente, orientée CapRover) : il tourne en service à part (bec-infra en
# dev, Phase D2 en prod), cette image ne fait tourner que PHP-FPM. Patron
# repris de factu_sentinel/backend/Dockerfile (stack identique), adapté à
# Cobage (sans ext-redis : Messenger utilise le transport Doctrine, pas Redis).
#
# PHP 8.3, pas 8.2 : composer.json déclare "php": ">=8.2" mais composer.lock
# verrouille phpunit/phpunit ^12.3 (et ses dépendances internes sebastian/*)
# qui exigent PHP >=8.3 - vérifié en Phase D0 en tentant un "composer install"
# complet (avec dev) sur une image 8.2, échec net ; "--no-dev" seul s'installe
# sans problème sur 8.2 (le code de production n'est pas concerné, uniquement
# l'outillage de test). 8.3 satisfait toujours ">=8.2" et aligne l'image sur
# ce qui a en réalité été testé jusqu'ici (PHP 8.4 côté machine hôte) - à
# refléter dans CLAUDE.md (tableau de stack, actuellement "PHP 8.2+").
#
# Quatre cibles ("--target") :
#   dev   - utilisée par bec-infra/docker-compose.yml, code source monté en volume
#   build - installe les dépendances de production, sert de base à "prod"
#   prod  - image finale, PHP-FPM seul, utilisateur non-root

FROM php:8.3-fpm-alpine AS base

WORKDIR /app

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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---------------------------------------------------------------------------
FROM base AS dev

COPY composer.json composer.lock symfony.lock* ./
RUN composer install --no-scripts --no-progress --prefer-dist

# Le code source réel est monté en volume par bec-infra/docker-compose.yml en
# développement ; cette copie garantit néanmoins que l'image reste utilisable
# seule.
COPY . .

COPY docker/entrypoint-dev.sh /usr/local/bin/docker-entrypoint-dev.sh
COPY docker/entrypoint-worker-dev.sh /usr/local/bin/docker-entrypoint-worker-dev.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-dev.sh /usr/local/bin/docker-entrypoint-worker-dev.sh

ENTRYPOINT ["docker-entrypoint-dev.sh"]
EXPOSE 9000

# Sans ceci, bec-infra/docker-compose.yml ne peut pas exprimer "attendre que
# php-fpm écoute réellement" (juste "le conteneur a démarré") - constaté à la
# vérification de la Phase D0 : nginx (démarrage quasi instantané) tentait de
# router vers backend avant la fin de composer install/génération JWT dans
# l'entrypoint, provoquant un 502 "Connection refused" pendant les premières
# secondes d'un démarrage à froid complet (docker compose down && up).
HEALTHCHECK --interval=5s --timeout=3s --start-period=60s --retries=5 \
    CMD php -r '$fp=@fsockopen("127.0.0.1",9000,$errno,$errstr,2);if(!$fp){exit(1);}fclose($fp);exit(0);'

CMD ["php-fpm"]

# ---------------------------------------------------------------------------
FROM base AS build

COPY composer.json composer.lock symfony.lock* ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# .env est gitignoré (jamais présent dans le contexte de build) - sans ce
# fallback, "bin/console" n'a aucune configuration pour démarrer le kernel et
# le warmup de cache ci-dessous échoue. Les valeurs réelles de production
# seront injectées par variables d'environnement (Phase D3, Infisical), jamais
# par ce fichier - il ne sert qu'à amorcer ce warmup au moment du build.
RUN cp .env.dist .env || true

RUN mkdir -p config/jwt \
    && php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true

RUN APP_SECRET=dummysecretforthebuild \
    TRUSTED_PROXIES=127.0.0.1 \
    APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear \
    && APP_SECRET=dummysecretforthebuild \
       TRUSTED_PROXIES=127.0.0.1 \
       APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup

# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS prod

WORKDIR /app

RUN apk add --no-cache \
    icu-libs \
    postgresql-libs \
    libxml2 \
    oniguruma \
    libzip

COPY --from=build /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/
COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions

RUN { \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 10'; \
        echo 'date.timezone = Europe/Paris'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 256'; \
        echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/php-prod.ini

RUN addgroup -g 1000 app \
    && adduser -D -G app -u 1000 app

COPY --from=build --chown=app:app /app ./

# "var/", "public/uploads/" et "config/jwt/" sont des points de montage
# potentiels de volumes nommés en production (Phase D2) - créés et possédés
# par "app" avant le "USER app" ci-dessous : un volume nommé fraîchement monté
# hérite sinon de la propriété root, empêchant "app" d'y écrire (même piège
# que factu_sentinel, Phase 18 - "var/storage/documents").
RUN mkdir -p var/cache var/log public/uploads config/jwt \
    && chown -R app:app var public/uploads config/jwt

COPY docker/entrypoint-prod.sh /usr/local/bin/docker-entrypoint-prod.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-prod.sh

USER app

ENTRYPOINT ["docker-entrypoint-prod.sh"]

EXPOSE 9000

# Liveness faible mais sans dépendance supplémentaire (pas de wget/curl dans
# cette image) : prouve seulement que php-fpm accepte des connexions TCP sur
# son port FastCGI, pas qu'il répond correctement à une vraie requête. Le
# signal de santé de bout en bout (nginx -> ce conteneur -> PostgreSQL) sera
# porté par le HEALTHCHECK du service nginx sur /api/health (Phase D2), pas
# celui-ci - nginx n'est plus embarqué dans cette image.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r '$fp=@fsockopen("127.0.0.1",9000,$errno,$errstr,2);if(!$fp){exit(1);}fclose($fp);exit(0);'

CMD ["php-fpm"]
