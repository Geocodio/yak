#!/usr/bin/env bash
set -e

mkdir -p /etc/nginx/ssl

if [ ! -f /etc/nginx/ssl/self-signed.crt ]; then
    openssl req -x509 -nodes -days 3650 \
        -newkey rsa:2048 \
        -keyout /etc/nginx/ssl/self-signed.key \
        -out /etc/nginx/ssl/self-signed.crt \
        -subj "/CN=yak"
fi

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
