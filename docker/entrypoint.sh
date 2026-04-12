#!/usr/bin/env bash
set -e

if [ ! -f /app/database/database.sqlite ]; then
    touch /app/database/database.sqlite
fi

chown -R www-data:www-data /app/database /app/storage /app/bootstrap/cache

if [ ! -f /app/.env ]; then
    cp /app/.env.example /app/.env
    php artisan key:generate --no-interaction
fi

php artisan migrate --force --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
