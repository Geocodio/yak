#!/usr/bin/env bash
set -e

# /data is used for artifacts — owned by www-data only (yak must NOT write here)
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

# www-data (queue worker) needs to write git credentials and config in /home/yak
chown yak:yak /home/yak
chmod 770 /home/yak

# Allow yak to use the Docker socket (for docker-compose in repos)
if [ -S /var/run/docker.sock ]; then
    DOCKER_GID=$(stat -c '%g' /var/run/docker.sock)
    if ! getent group docker > /dev/null 2>&1; then
        groupadd -g "$DOCKER_GID" docker
    fi
    usermod -aG docker yak 2>/dev/null || true
fi

# Pre-create log files so supervisor doesn't create them as root
touch /app/storage/logs/yak-claude-worker.log \
      /app/storage/logs/yak-claude-worker-error.log \
      /app/storage/logs/default-worker.log \
      /app/storage/logs/scheduler.log \
      /app/storage/logs/yak.log
chown www-data:www-data /app/storage/logs/*.log

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

exec "$@"
