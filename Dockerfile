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

# Docker CLI — needed for docker-compose stop in preflight/cleanup and agent tasks
RUN install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
    && chmod a+r /etc/apt/keyrings/docker.asc \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get update && apt-get install -y --no-install-recommends \
        nodejs \
        docker-ce-cli \
        docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/*

RUN npm install -g @anthropic-ai/claude-code agent-browser

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN useradd -m -s /bin/bash yak \
    && mkdir -p /home/yak/repos /home/yak/.claude \
        /home/yak/.cache /home/yak/.config/chromium \
        /home/yak/.local/share/pki/nssdb \
    && chown -R yak:yak /home/yak

# Allow www-data to drop privileges to yak user for Claude Code execution
RUN echo 'www-data ALL=(root) NOPASSWD: /usr/sbin/runuser' > /etc/sudoers.d/yak-sandbox \
    && chmod 440 /etc/sudoers.d/yak-sandbox

ENV HOME=/home/yak
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

RUN usermod -aG www-data yak

RUN mkdir -p /data \
    && chown -R www-data:www-data /data \
    && chmod -R 775 /data

EXPOSE 80

VOLUME ["/home/yak/repos", "/data", "/home/yak/.claude"]

ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/yak.conf"]
