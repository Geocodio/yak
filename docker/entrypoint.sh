#!/usr/bin/env bash
set -e

if [ ! -f /data/database.sqlite ]; then
    touch /data/database.sqlite
fi

# /data is owned by www-data only — yak user must NOT write here
chown -R www-data:www-data /data
chmod -R 750 /data

chown -R www-data:www-data /app/bootstrap/cache /app/storage
chmod -R 775 /app/storage

# Ensure yak user owns its home directory contents
chown -R yak:yak /home/yak/repos /home/yak/.claude /home/yak/.cache /home/yak/.config

# Allow yak to access app logs and storage via group membership
usermod -aG www-data yak 2>/dev/null || true
# Allow www-data to read /home/yak/repos for artifact collection
usermod -aG yak www-data 2>/dev/null || true

# Allow www-data to traverse /home/yak for artifact serving
chmod 750 /home/yak

# Remove any .env so Laravel reads from environment variables directly
rm -f /app/.env

php artisan migrate --force --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
