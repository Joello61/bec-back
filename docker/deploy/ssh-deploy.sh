#!/usr/bin/env bash
#
# Invoqué par .github/workflows/deploy.yml (jamais manuellement en usage normal) - tire
# l'image déjà construite et poussée vers GHCR par le job "build-and-push", jamais une
# reconstruction sur le serveur cible ("l'image testée doit être exactement l'image
# déployée", patron factu_sentinel). Ne touche jamais au service "frontend" - Cobage a un
# pipeline de déploiement indépendant par application (bec-backend/bec-frontend), tous
# deux ciblant le même checkout `bec-infra` sur le serveur.
#
# Usage : ssh-deploy.sh <staging|production>
# Variables d'environnement attendues (fournies par deploy.yml via des secrets GitHub
# Environment, jamais en dur) :
#   SSH_PRIVATE_KEY, SSH_HOST, SSH_USER, DEPLOY_PATH, BACKEND_IMAGE,
#   INFISICAL_PROJECT_ID, INFISICAL_CLIENT_ID, INFISICAL_CLIENT_SECRET
#
# DEPLOY_PATH pointe vers le checkout du dépôt `bec-infra` sur le serveur (fichiers
# Compose/nginx), pas ce dépôt `bec-backend` - ce script ne fait que piloter ce checkout
# depuis l'extérieur (image à déployer + secrets), jamais y committer de code applicatif.
#
# Secrets applicatifs (POSTGRES_*, APP_SECRET, JWT_PASSPHRASE, R2_*, etc.) : jamais de
# fichier ".env.production"/".env.staging" sur le serveur (Phase D3) - injectés
# directement dans l'environnement du processus "docker compose" via "infisical run".
# INFISICAL_CLIENT_ID/SECRET sont ceux d'une identité machine Universal Auth dédiée à CET
# environnement (jamais partagée entre staging et production) - accès en lecture seule,
# limité à l'environnement Infisical correspondant.

set -euo pipefail

ENVIRONMENT="${1:?Usage: ssh-deploy.sh <staging|production>}"

: "${SSH_PRIVATE_KEY:?}"
: "${SSH_HOST:?}"
: "${SSH_USER:?}"
: "${DEPLOY_PATH:?}"
: "${BACKEND_IMAGE:?}"
: "${INFISICAL_PROJECT_ID:?}"
: "${INFISICAL_CLIENT_ID:?}"
: "${INFISICAL_CLIENT_SECRET:?}"

# Infisical tourne sur le même VPS que la cible de déploiement (accès humain via tunnel
# SSH uniquement) - ce déploiement s'exécute DEPUIS le serveur cible lui-même (SSH), donc
# l'atteint directement en local, jamais besoin du tunnel.
INFISICAL_API_URL='http://localhost:8081'

SSH_KEY_FILE="$(mktemp)"
KNOWN_HOSTS_FILE="$(mktemp)"
trap 'rm -f "$SSH_KEY_FILE" "$KNOWN_HOSTS_FILE"' EXIT

printf '%s\n' "$SSH_PRIVATE_KEY" > "$SSH_KEY_FILE"
chmod 600 "$SSH_KEY_FILE"

# ssh-keyscan à chaque exécution plutôt qu'une empreinte d'hôte figée en secret -
# confiance à la première utilisation par exécution de ce job (compromis documenté,
# patron factu_sentinel).
ssh-keyscan -H "$SSH_HOST" > "$KNOWN_HOSTS_FILE" 2>/dev/null

echo "Déploiement backend de ${ENVIRONMENT} - image ${BACKEND_IMAGE}"

# shellcheck disable=SC2087
ssh -i "$SSH_KEY_FILE" -o UserKnownHostsFile="$KNOWN_HOSTS_FILE" "$SSH_USER@$SSH_HOST" bash -s <<EOF
set -euo pipefail
cd "$DEPLOY_PATH"

# Synchronise le dépôt bec-infra (docker-compose.prod.yml, docker/nginx/*.template) au
# commit le plus récent de sa propre branche main - indépendant du SHA applicatif
# déployé ici (4 dépôts distincts, contrairement au monorepo factu_sentinel). Jamais
# affecté : les fichiers non suivis par Git (docker-compose.prod.traefik.yml).
echo "=== Synchronisation de bec-infra (main) ==="
git fetch origin main
git checkout --quiet origin/main

# "image-tags.env" (jamais versionné) persiste le tag d'image actuellement déployé de
# CHAQUE application - un déploiement backend ne doit jamais écraser le tag frontend
# actuellement en place, et réciproquement (chaque pipeline ne connaît que sa propre
# image). Créé vide au tout premier déploiement de ce serveur.
touch image-tags.env
# shellcheck disable=SC1091
source image-tags.env

export BACKEND_IMAGE="$BACKEND_IMAGE"
# FRONTEND_IMAGE peut être vide au tout premier déploiement (aucun déploiement frontend
# encore effectué sur ce serveur) - sans impact ici, "--no-deps" ci-dessous ne demande
# jamais à Compose d'instancier le service "frontend" à partir de cette valeur.
export FRONTEND_IMAGE="\${FRONTEND_IMAGE:-}"

cat > image-tags.env <<IMAGETAGS
BACKEND_IMAGE=\$BACKEND_IMAGE
FRONTEND_IMAGE=\$FRONTEND_IMAGE
IMAGETAGS

# "docker-compose.prod.observability.yml" ajoute nginx/backend/worker/scheduler au
# réseau partagé "observability-shared" - chargé UNIQUEMENT en production, jamais en
# staging (la fusion de listes Compose est strictement additive, aucun override ne peut
# retirer un réseau ajouté dans un fichier de base).
COMPOSE_FILES="-f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.prod.traefik.yml"
if [ "$ENVIRONMENT" = "production" ]; then
  COMPOSE_FILES="\$COMPOSE_FILES -f docker-compose.prod.observability.yml"
fi

export INFISICAL_API_URL="$INFISICAL_API_URL"
# Jamais "export VAR=\\\$(cmd)" en une seule ligne - préfixer une substitution de commande
# par "export" directement avale son code de sortie, "set -e" ne détecterait alors jamais
# un échec d'authentification Infisical (patron factu_sentinel, constaté en pratique).
INFISICAL_TOKEN="\$(infisical login --method=universal-auth --client-id="$INFISICAL_CLIENT_ID" --client-secret="$INFISICAL_CLIENT_SECRET" --silent --plain)"
export INFISICAL_TOKEN

echo "=== Récupération de l'image backend ==="
docker compose --env-file image-tags.env \$COMPOSE_FILES pull backend worker scheduler

echo "=== Démarrage des nouveaux conteneurs (backend/worker/scheduler uniquement) ==="
# "--no-deps" : ne recrée jamais "frontend"/"postgres"/"mercure"/"nginx" à partir de ce
# pipeline - seul le pipeline bec-frontend touche "frontend". "--remove-orphans" est
# volontairement omis ici (limité aux 3 services listés, jamais un "up" global qui
# purgerait un service retiré d'un fichier Compose - ce nettoyage reste la responsabilité
# d'un déploiement qui recharge l'ensemble de la stack, pas d'un déploiement partiel).
infisical run --projectId="$INFISICAL_PROJECT_ID" --env="$ENVIRONMENT" -- \
  docker compose --env-file image-tags.env \$COMPOSE_FILES up -d --no-deps backend worker scheduler

echo "=== Migrations de base de données ==="
# Étape distincte et explicite, après le démarrage des nouveaux conteneurs - jamais
# implicite dans un entrypoint. Chaque migration ajoutée au dépôt doit rester purement
# additive pour que cet ordre reste sûr. Jamais besoin de "infisical run" ici : "exec"
# s'exécute dans le conteneur "backend" déjà démarré, déjà porteur de son environnement
# depuis l'"up -d" précédent.
docker compose --env-file image-tags.env -f docker-compose.yml -f docker-compose.prod.yml exec -T backend php bin/console doctrine:migrations:migrate --no-interaction

echo "Déploiement backend terminé (${ENVIRONMENT})."
EOF
