# syntax=docker/dockerfile:1
FROM php:8.4-fpm AS base

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    sudo \
    unzip \
    zip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libsqlite3-dev \
    sqlite3 \
    nginx \
    supervisor \
    cron \
    chromium \
    ffmpeg \
    ca-certificates \
    gnupg \
    openssl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_sqlite \
    pdo_mysql \
    mbstring \
    xml \
    gd \
    zip \
    bcmath \
    pcntl \
    && rm -rf /var/lib/apt/lists/*

ENV CHROME_PATH=/usr/bin/chromium
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

# Incus client — used by the queue worker to manage sandbox containers
# on the host. The host's Incus Unix socket is mounted at runtime; the
# www-data user is added to the matching `incus-admin` group via
# entrypoint.sh so it can talk to the daemon.
RUN install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://pkgs.zabbly.com/key.asc -o /etc/apt/keyrings/zabbly.asc \
    && chmod a+r /etc/apt/keyrings/zabbly.asc \
    && echo "deb [signed-by=/etc/apt/keyrings/zabbly.asc] https://pkgs.zabbly.com/incus/stable $(. /etc/os-release && echo "$VERSION_CODENAME") main" > /etc/apt/sources.list.d/zabbly-incus.list

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get update && apt-get install -y --no-install-recommends \
        nodejs \
        incus-client \
    && rm -rf /var/lib/apt/lists/*

# Claude Code CLI is installed in two places:
#  - Inside each Incus sandbox (where the agent actually runs), and
#  - Here, on the yak app container, so the /skills dashboard page can
#    install/uninstall/update plugins. Skills written to /home/yak/.claude
#    are mounted from the host and pushed into each sandbox at create time.
RUN npm install -g @anthropic-ai/claude-code

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV HOME=/var/www
ENV CLAUDE_CONFIG_DIR=/home/yak/.claude

# ── Build frontend assets ────────────────────────────────────────────
FROM base AS build

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts

COPY package.json package-lock.json* ./
RUN npm ci --include=optional

COPY . .
RUN npm run build

# Install Remotion project deps for the video walkthrough renderer.
# Built inside the image so node_modules is Linux-native and carried
# through to the production stage via COPY --from=build.
RUN cd video && npm install --no-audit --no-fund

# ── Production image ─────────────────────────────────────────────────
FROM base AS production

WORKDIR /app

COPY --from=build /app /app

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-yak.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/yak.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

RUN mkdir -p /data \
    && chown -R www-data:www-data /data \
    && chmod -R 775 /data

# Claude config dir is mounted from the host at runtime; it holds the
# shared Claude Max session token that gets pushed into each sandbox.
RUN mkdir -p /home/yak/.claude

EXPOSE 80

VOLUME ["/data", "/home/yak/.claude"]

ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/yak.conf"]
