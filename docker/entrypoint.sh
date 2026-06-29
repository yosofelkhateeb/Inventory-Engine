#!/usr/bin/env bash
# Container entrypoint for the Inventory Engine demo deploy.
# Runs once at container start, then execs supervisord to manage nginx + php-fpm.
set -euo pipefail

cd /var/www/html

DB_PATH="${DB_DATABASE:-/data/database.sqlite}"
SEED_MARKER="/data/.seeded"

mkdir -p "$(dirname "$DB_PATH")"

# /data must be www-data-writable so SQLite can create its journal/WAL
# files alongside the database file. Without this, session writes fail
# silently and login returns 419 (CSRF mismatch from a fresh session
# every request).
chown www-data:www-data "$(dirname "$DB_PATH")"
chmod 775 "$(dirname "$DB_PATH")"

if [ ! -f "$DB_PATH" ]; then
    echo "[entrypoint] creating empty SQLite database at $DB_PATH"
    touch "$DB_PATH"
fi
chown www-data:www-data "$DB_PATH"
chmod 664 "$DB_PATH"

# Persistent storage on the Fly volume — survives deploys and machine restarts.
# Uploads, forecasting tmp + reports go here so client-touched data persists.
mkdir -p /data/storage/app/forecasting/tmp \
         /data/storage/app/forecasting/reports \
         /data/storage/app/ingestion/uploads

for sub in forecasting ingestion; do
    rm -rf "storage/app/${sub}"
    ln -sf "/data/storage/app/${sub}" "storage/app/${sub}"
done

chown -R www-data:www-data /data/storage storage bootstrap/cache

# Refresh package discovery (composer was run with --no-scripts at build time
# because APP_KEY was not yet present).
php artisan package:discover --ansi || true

# Run migrations on every boot — they're idempotent.
echo "[entrypoint] running migrations"
php artisan migrate --force --no-interaction

# Seed only on the very first boot. Subsequent restarts skip seeding so any data
# the client touched (new promotions, status transitions, etc.) survives.
if [ ! -f "$SEED_MARKER" ]; then
    echo "[entrypoint] seeding demo data (first boot)"
    php artisan db:seed --force --no-interaction
    touch "$SEED_MARKER"
else
    echo "[entrypoint] seed marker present, skipping seeders"
fi

# Storage symlink for public/storage -> storage/app/public.
php artisan storage:link --force || true

echo "[entrypoint] handing off to supervisord"
exec "$@"
