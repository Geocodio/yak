#!/usr/bin/env bash
set -e

if [ ! -f /data/database.sqlite ]; then
    touch /data/database.sqlite
fi

chown -R www-data:www-data /data /app/storage /app/bootstrap/cache
chmod -R g+w /data /app/storage

# Ensure yak user owns its home directory contents
chown -R yak:yak /home/yak/repos /home/yak/.claude

# Restore .claude.json from backup if missing (it lives outside the mounted
# .claude/ volume and is lost on container recreation)
if [ ! -f /home/yak/.claude.json ] && [ -d /home/yak/.claude/backups ]; then
    latest_backup=$(ls -t /home/yak/.claude/backups/.claude.json.backup.* 2>/dev/null | head -1)
    if [ -n "$latest_backup" ]; then
        cp "$latest_backup" /home/yak/.claude.json
        chown yak:yak /home/yak/.claude.json
    fi
fi

# Remove any .env so Laravel reads from environment variables directly
rm -f /app/.env

php artisan migrate --force --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
