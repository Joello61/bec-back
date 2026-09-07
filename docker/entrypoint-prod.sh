#!/bin/sh
set -e

# config/jwt/*.pem est gitignoré (clé privée, jamais versionnée) et l'image de production
# n'exécute jamais "composer install" avec les scripts du bundle ("--no-scripts", stage
# "build" du Dockerfile) - sans cette génération, aucune paire de clés n'existe jamais dans
# un conteneur de production, et toute route passant par le firewall JWT échoue
# silencieusement (login, refresh, tout endpoint authentifié).
#
# Écrit sur le volume nommé "jwt_keys" (Phase D2, docker-compose.prod.yml), qui persiste
# au-delà d'un redéploiement (contrairement au système de fichiers de l'image, recréé à
# chaque nouvelle image) - "--skip-if-exists" ne régénère donc jamais une paire déjà
# générée par un déploiement précédent. Ce volume ne doit être monté que sur "backend",
# jamais sur "worker"/"scheduler" en parallèle (deux entrypoints concurrents
# corromperaient la clé).
php bin/console lexik:jwt:generate-keypair --skip-if-exists

exec "$@"
