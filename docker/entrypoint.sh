#!/usr/bin/env bash
set -e

if [ ! -f /data/database.sqlite ]; then
    touch /data/database.sqlite
fi

chown -R www-data:www-data /data /app/storage /app/bootstrap/cache
chmod -R g+w /data /app/storage

# Ensure yak user owns its home directory contents
chown -R yak:yak /home/yak/repos /home/yak/.claude

# Remove any .env so Laravel reads from environment variables directly
rm -f /app/.env

php artisan migrate --force --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
