#!/bin/sh
set -e

# Le volume nommé "backend_vendor" (bec-infra/docker-compose.yml) persiste au-delà d'un
# rebuild d'image : sans ce réinstall, vendor/ resterait figé sur l'état du build précédent
# dès qu'une dépendance change dans composer.json, avec une erreur "Class not found" au
# démarrage plutôt qu'une explication claire.
composer install --no-interaction --prefer-dist

# config/jwt/*.pem est gitignoré (clé privée, jamais versionnée) : sans cette génération,
# un checkout neuf (nouveau contributeur) n'a aucune paire de clés et toute route passant
# par le firewall JWT échoue silencieusement (login, refresh, tout endpoint authentifié) -
# --skip-if-exists la rend idempotente sans écraser une paire déjà générée.
php bin/console lexik:jwt:generate-keypair --skip-if-exists

# Les services "worker" et "scheduler" (bec-infra/docker-compose.yml) partagent la même
# image et le même bind-mount source que ce conteneur : ils n'ont pas besoin de refaire
# composer install/génération de clés JWT (dont ils n'ont de toute façon pas l'usage) -
# docker/entrypoint-worker-dev.sh attend ce marqueur avant de démarrer, plutôt que de
# risquer une double installation concurrente sur le même vendor/ partagé.
mkdir -p /app/var
touch /app/var/.backend-ready

exec "$@"
