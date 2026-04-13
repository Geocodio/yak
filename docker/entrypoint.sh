#!/usr/bin/env bash
set -e

if [ ! -f /data/database.sqlite ]; then
    touch /data/database.sqlite
fi

chown -R www-data:www-data /data /app/bootstrap/cache
chown -R www-data:www-data /app/storage
chmod -R g+w /data /app/storage

# Add www-data to yak group so log files created by either user are writable by both
usermod -aG yak www-data 2>/dev/null || true
usermod -aG www-data yak 2>/dev/null || true

# Ensure log files are group-writable regardless of which process created them
chmod -R 664 /app/storage/logs/*.log 2>/dev/null || true

# Ensure yak user owns its home directory contents
chown -R yak:yak /home/yak/repos /home/yak/.claude

# Remove any .env so Laravel reads from environment variables directly
rm -f /app/.env

php artisan migrate --force --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
