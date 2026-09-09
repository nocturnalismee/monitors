#!/bin/sh
# servmon container entrypoint:
# 1. Seed the persisted config volume on first start (keeps config/local.php
#    across image rebuilds).
# 2. Ensure storage/ dirs exist and are writable by www-data.
# 3. Install the worker cron schedule and start cron in the background.
# 4. Hand over to apache2-foreground.
set -e

if [ ! -f /var/www/html/config/bootstrap.php ]; then
    cp -a /var/www/html/config.dist/. /var/www/html/config/
fi

mkdir -p /var/www/html/storage/backups \
         /var/www/html/storage/cache \
         /var/www/html/storage/exports \
         /var/www/html/storage/logs \
         /var/www/html/storage/rate-limit
chown -R www-data:www-data /var/www/html/storage /var/www/html/config
chmod 0750 /var/www/html/storage/backups || true

# Worker schedule (mirrors the documented cron list; backup runs 01:15 daily
# with 30-day retention). Cron does not inherit container env, so the values
# below are baked in from the environment at container start.
BIN=/usr/local/bin/php
APP=/var/www/html
cat > /etc/cron.d/servmon <<EOF
APP_ENV=${APP_ENV:-production}
APP_TZ=${APP_TZ:-Asia/Jakarta}
APP_KEY=${APP_KEY:-}
DB_HOST=${DB_HOST:-db}
DB_PORT=${DB_PORT:-3306}
DB_NAME=${DB_NAME:-servmon}
DB_USER=${DB_USER:-servmon}
DB_PASS=${DB_PASS:-changeme}
REDIS_ENABLED=${REDIS_ENABLED:-1}
REDIS_HOST=${REDIS_HOST:-redis}
REDIS_PORT=${REDIS_PORT:-6379}
REDIS_PASSWORD=${REDIS_PASSWORD:-}
* * * * * www-data ${BIN} ${APP}/workers/alert-check.php > /proc/1/fd/1 2>&1
* * * * * www-data ${BIN} ${APP}/workers/alert-delivery.php > /proc/1/fd/1 2>&1
* * * * * www-data ${BIN} ${APP}/workers/export-worker.php > /proc/1/fd/1 2>&1
* * * * * www-data ${BIN} ${APP}/workers/ping-check.php > /proc/1/fd/1 2>&1
*/5 * * * * www-data ${BIN} ${APP}/workers/ip-reputation-check.php > /proc/1/fd/1 2>&1
30 0 * * * www-data ${BIN} ${APP}/workers/partition-maintain.php > /proc/1/fd/1 2>&1
30 2 * * * www-data ${BIN} ${APP}/workers/disk-cleanup.php > /proc/1/fd/1 2>&1
0 2 * * * www-data ${BIN} ${APP}/workers/disk-rollup.php > /proc/1/fd/1 2>&1
0 2 * * * www-data ${BIN} ${APP}/workers/rollup.php > /proc/1/fd/1 2>&1
0 3 * * * www-data ${BIN} ${APP}/workers/cleanup.php > /proc/1/fd/1 2>&1
15 1 * * * www-data ${BIN} ${APP}/workers/backup.php > /proc/1/fd/1 2>&1
EOF
chmod 0644 /etc/cron.d/servmon

service cron start

exec "$@"
