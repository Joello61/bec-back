#!/bin/sh
set -e

# Les services "worker" (Messenger, transport async) et "scheduler" (transport
# scheduler_expiration) partagent la même image et le même bind-mount source que
# "backend" - ils ne refont jamais eux-mêmes composer install/génération de clés JWT,
# ils attendent simplement que "backend" ait fini sa propre installation
# (docker/entrypoint-dev.sh), sur le vendor/ et var/ partagés. Simple sleep-poll, pas de
# verrou de fichier entre conteneurs distincts (non fiable sur un bind-mount Docker).
READY_MARKER=/app/var/.backend-ready
ELAPSED=0
TIMEOUT=120

while [ ! -f "$READY_MARKER" ]; do
    if [ "$ELAPSED" -ge "$TIMEOUT" ]; then
        echo "worker/scheduler: timed out waiting for $READY_MARKER (backend setup never completed)" >&2
        exit 1
    fi
    sleep 1
    ELAPSED=$((ELAPSED + 1))
done

exec "$@"
