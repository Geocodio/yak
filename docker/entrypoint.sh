#!/usr/bin/env bash
set -e

# /data is owned by www-data — that's where artifacts get written by the queue worker.
chown -R www-data:www-data /data
chmod -R 750 /data

chown -R www-data:www-data /app/bootstrap/cache /app/storage
chmod -R 775 /app/storage

# Claude Max config (shared across sandboxes). Mounted from host; the queue
# worker reads it as www-data and pushes it into each new sandbox container.
chown -R www-data:www-data /home/yak/.claude

# www-data's HOME is /var/www — ensure it owns the dotfile dirs it needs
# (incus client writes ~/.config/incus/, npm writes ~/.npm, etc.).
# Pre-create the incus config dir so the Docker HEALTHCHECK (which runs
# as root by default) can't beat us to it and leave a root-owned dir
# that www-data then can't read.
mkdir -p /var/www/.cache /var/www/.config/incus /var/www/.local /var/www/.npm
chown www-data:www-data /var/www
chown -R www-data:www-data /var/www/.cache /var/www/.config /var/www/.local /var/www/.npm 2>/dev/null || true

# Add www-data to the Incus group on the host so the worker can talk to the
# Incus Unix socket. The host's incus-admin GID is detected from the mounted
# socket so it survives Incus reinstalls/upgrades.
if [ -S /var/lib/incus/unix.socket ]; then
    INCUS_GID=$(stat -c '%g' /var/lib/incus/unix.socket)
    if ! getent group incus-admin > /dev/null 2>&1; then
        groupadd -g "$INCUS_GID" incus-admin
    fi
    usermod -aG incus-admin www-data 2>/dev/null || true
fi

# Pre-create log files so supervisor doesn't create them as root
touch /app/storage/logs/yak-claude-worker.log \
      /app/storage/logs/yak-claude-worker-error.log \
      /app/storage/logs/default-worker.log \
      /app/storage/logs/scheduler.log \
      /app/storage/logs/yak.log
chown www-data:www-data /app/storage/logs/*.log

# Restore Claude config if missing (lost on container restart since /home/yak is not a volume)
#
# Pick the newest backup that actually contains OAuth credentials. Claude CLI
# sometimes writes a 50-byte `{"firstStartTime": ...}` stub before it has
# credentials, and its rotation snapshots that stub too. Blindly picking
# "latest by mtime" has poisoned restores before (Apr 2026). Iterate newest-
# first and take the first one with an `oauthAccount` key.
if [ ! -f /home/yak/.claude.json ] && [ -d /home/yak/.claude/backups ]; then
    VALID_BACKUP=""
    for BACKUP in $(ls -t /home/yak/.claude/backups/.claude.json.backup.* 2>/dev/null); do
        if grep -q '"oauthAccount"' "$BACKUP" 2>/dev/null; then
            VALID_BACKUP="$BACKUP"
            break
        fi
    done
    if [ -n "$VALID_BACKUP" ]; then
        cp "$VALID_BACKUP" /home/yak/.claude.json
        chown www-data:www-data /home/yak/.claude.json
        echo "Restored Claude config from $VALID_BACKUP"
    else
        echo "WARNING: no usable Claude config backup found (all backups missing oauthAccount)"
    fi
fi

# Remove any .env so Laravel reads from environment variables directly
rm -f /app/.env

# Wait for MariaDB to be ready before running migrations
echo "Waiting for database..."
for i in $(seq 1 30); do
    php artisan db:monitor --databases=mariadb > /dev/null 2>&1 && break
    sleep 2
done

php artisan migrate --force --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

# Refresh the Claude Max OAuth token before workers start. The
# scheduled refresh runs hourly, but a deploy can leave the on-disk
# credentials file already 7+ hours old (the ~8h access-token TTL),
# which 401s the first task picked up post-boot. This walks the CLI's
# refresh path so the credentials file is fresh before supervisord
# brings workers online. Failures are non-fatal — worst case tasks
# fall back to the scheduled hourly refresh within 60 minutes.
echo "Refreshing Claude auth..."
runuser -u www-data -- php artisan yak:refresh-claude-auth \
    || echo "WARNING: startup Claude auth refresh failed — hourly scheduler will retry"

exec "$@"
