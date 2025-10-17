#!/bin/sh
set -e

echo "🚀 Running Symfony initialization..."

# Wait for database if needed (optionnel)
# until nc -z "$DB_HOST" 5432; do
#   echo "Waiting for PostgreSQL..."
#   sleep 2
# done

# Generate JWT keys if not present
php bin/console lexik:jwt:generate-keypair --skip-if-exists || true

# Clear & warmup cache (with real env vars provided by CapRover)
php bin/console cache:clear --no-warmup || true
php bin/console cache:warmup || true

# If RUN_MODE=worker → messenger; else → start web server
if [ "$RUN_MODE" = "worker" ]; then
    echo "🧵 Running Messenger worker..."
    exec php bin/console messenger:consume async --limit=10 --memory-limit=256M --time-limit=3600 -vv
else
    echo "🌐 Starting PHP built-in server..."
    exec "$@"
fi
