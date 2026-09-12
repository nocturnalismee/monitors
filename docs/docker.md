# Running monitors with Docker

Full-stack setup: `app` (PHP 8.2 + Apache + cron workers), `db` (MySQL 8.0),
`redis` (optional cache). No PHP/MySQL installation needed on the host.

## Quick start

```bash
# 1. Set secrets (used by both the app and MySQL containers)
export DB_PASSWORD="ganti-dengan-password-kuat"
export DB_ROOT_PASSWORD="ganti-juga"
export APP_KEY="$(php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;')"

# 2. Build and start
docker compose up -d --build

# 3. Open the web installer and follow the steps.
#    Database host: db, port: 3306, name: monitors, user: monitors,
#    password: the DB_PASSWORD above.
http://localhost:8010/install.php
```

After installing, open `http://localhost:8010/login`.

## How it works

- Web root is `public/`; clean URLs work via the bundled `.htaccess`.
- All 11 workers (alerts, ping, rollup, cleanup, backup, …) run from cron
  **inside the `app` container** — no host cron needed. Logs go to
  `docker compose logs app`.
- Nightly DB backup (`01:15`, 30-day retention) lands in the
  `monitors_storage` volume under `storage/backups/`.
- `config/local.php` (written by the installer) lives in the
  `monitors_config` volume, so it survives `docker compose up --build`.
- Behind a reverse proxy (Nginx/Cloudflare), set `TRUSTED_PROXIES` in the
  `app` environment to the proxy IP/CIDR and adjust `APP_URL` to the public
  URL.

## Useful commands

```bash
docker compose logs -f app        # web + worker output
docker compose exec app php workers/backup.php   # manual backup
docker compose exec db mysql -umonitors -p monitors # db shell
docker compose down               # stop (volumes kept)
docker compose down -v             # stop AND delete all data
```

## Updating

```bash
git pull
docker compose up -d --build
```

Database schema changes apply via the installer/migrations as usual; volumes
keep your data.
