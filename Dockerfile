# syntax=docker/dockerfile:1.7

# ----------------------------------------------------------------------------
# Stage 1: Build the frontend (Vue / Inertia / Tailwind via Vite).
# ----------------------------------------------------------------------------
FROM node:22-alpine AS frontend
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js tsconfig.json ./
COPY resources ./resources
COPY public ./public

RUN npm run build


# ----------------------------------------------------------------------------
# Stage 2: Install Composer dependencies (no dev, no scripts).
# Scripts (package:discover) are run in the entrypoint so APP_KEY is available.
# ----------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./
COPY artisan ./
COPY bootstrap ./bootstrap
COPY config ./config
COPY app ./app
COPY database ./database
COPY routes ./routes

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --ignore-platform-req=ext-pcntl


# ----------------------------------------------------------------------------
# Stage 3: Final runtime image.
# PHP 8.3 + Python 3.11 + Nginx + Supervisor in a single Debian-based image.
# Debian (not Alpine) so Python scientific wheels install without recompiling.
# ----------------------------------------------------------------------------
FROM php:8.3-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive

# System packages: nginx, supervisor, python, build deps for PHP extensions.
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    python3 \
    python3-pip \
    python3-venv \
    libsqlite3-dev \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libicu-dev \
    libxml2-dev \
    libgomp1 \
    git \
    unzip \
    ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# PHP extensions used by Laravel + Horizon + Sanctum + this project.
RUN docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_sqlite \
    bcmath \
    mbstring \
    zip \
    intl \
    opcache \
    pcntl

# Python scientific deps for the forecasting subprocess.
# --break-system-packages: Debian 12 marks system Python as externally managed,
# which is the right default for end-user systems but irrelevant in a container.
COPY python/forecasting/requirements.txt /tmp/requirements.txt
RUN pip3 install --no-cache-dir --break-system-packages -r /tmp/requirements.txt \
 && rm /tmp/requirements.txt

# Composer binary copied from the official Composer image for the entrypoint.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Application code (excludes per .dockerignore).
COPY --chown=www-data:www-data . .

# Vendor and built frontend from earlier stages.
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build

# Storage and bootstrap/cache must be writable by www-data.
RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/forecasting/tmp \
    storage/app/forecasting/reports \
    storage/app/ingestion/uploads \
    bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# Nginx, Supervisor, entrypoint.
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
 && rm -f /etc/nginx/sites-enabled/default \
 && ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Default env (Fly secrets override APP_KEY, APP_URL, anything else).
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    SESSION_DRIVER=database \
    SESSION_LIFETIME=120 \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    BROADCAST_CONNECTION=log \
    FILESYSTEM_DISK=local \
    MAIL_MAILER=log \
    PYTHON_BIN=python3 \
    CLIENT_COUNTRY_CODE=MY

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
