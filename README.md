# ServMon v2

Restrukturisasi servmon: server monitoring self-hosted berbasis PHP 8.2+ dan MySQL 8.0 dengan clean URL, namespace `App\`, dan front controller.

## Overview
- Web root = `public/`, entry `public/index.php`, router `routes/*.php` (di-glob otomatis).
- Clean URL: `/dashboard`, `/servers`, `/ping`, `/disk-health`, `/ip-reputation`, `/alerts`, `/audit-logs`, `/export`, `/settings`, `/login`, `/logout`, `/status`, `/`.
- URL legacy (`/admin/*.php`, `/auth/*.php`, `/api/*.php`, `/public.php`, `/index.php`) di-301 ke clean URL.
- API: `/api/push`, `/api/push-disk`, `/api/status`, `/api/alerts`, `/api/events`, `/api/health`, `/api/time`, `/api/public-alerts`, `/api/ip-reputation`.

## Struktur direktori
- `public/` — web root (`index.php`, `router.php`, `.htaccess`, `install.php`, `assets/`)
- `routes/` — definisi rute (web_*.php, api_*.php, web_legacy.php)
- `config/` — `bootstrap.php` (chain bootstrap), `local.php` (hasil installer)
- `app/` — `Http/`, `Controllers/` (Admin, Api, Auth, Public), `Services/`, `Repositories/`, `Support/`, `Views/`, `Console/Workers/`
- `database/` — `schema.sql`, `seed.sql`, `migrations/`
- `storage/` — `logs/`, `exports/`, `cache/`, `rate-limit/`
- `workers/` — shim CLI worker
- `agents/` — agent push (bash) + `systemd/`
- `tests/` — unit & smoke test

## Requirement
- PHP 8.2+ (ext PDO, PDO_MySQL, openssl, mbstring)
- MySQL 8.0 / MariaDB
- Redis opsional (no-op bila dinonaktifkan)

## Instalasi
1. Buka `/install.php` di browser (menulis `config/local.php`, schema, seed, migrasi). Setelah sukses, hapus/rename `install.php`.
2. Alternatif manual: buat `config/local.php` yang me-return `array<string,string>` (kunci seperti `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_KEY`, `APP_ENV`) atau set env var; env var didahulukan.
3. Jalankan migrasi: `php migrate.php`.

## Menjalankan dev server
```
php -S 127.0.0.1:8000 -t public public/router.php
```
Seed user: `admin / admin123` (viewer: `support / admin123`).

## Produksi
- Set document root (docroot) ke `monitoring-v2/public`.
- Apache: `.htaccess` sudah tersedia (semua request non-file → `index.php`).
- Nginx: arahkan semua non-file ke `/index.php`; pastikan PHP-FPM dan Rewrite aktif.
- Set `APP_ENV=production` di env untuk menonaktifkan display_errors.

## Cron workers
Jalankan via cron; worker memakai `flock()` dan melapor ke `worker_health`.

| Worker | Jadwal |
| --- | --- |
| `workers/alert-check.php` | `* * * * *` |
| `workers/alert-delivery.php` | `* * * * *` |
| `workers/export-worker.php` | `* * * * *` |
| `workers/ping-check.php` | `* * * * *` |
| `workers/ip-reputation-check.php` | `*/5 * * * *` |
| `workers/partition-maintain.php` | `30 0 * * *` |
| `workers/disk-cleanup.php` | `30 2 * * *` |
| `workers/disk-rollup.php` | `0 2 * * *` |
| `workers/rollup.php` | `0 2 * * *` |
| `workers/cleanup.php` | `0 3 * * *` |

## Agent
- `agents/monitoring-agent.sh`, `agents/monitoring-agent-cpanel-mail.sh`, `agents/agent-old.sh`: push metrik umum.
- `agents/agent-disk-health.sh`: push disk health.
- **Semua konfigurasi agent v3 ada di `/etc/monitoring-agent.conf`** (salin dari `agents/systemd/monitoring-agent.conf.example`; isi `MASTER_URL`, `SERVER_TOKEN`, `SERVER_ID` — kunci lain opsional). Tidak perlu mengubah `monitoring-agent.sh`.
- Agent khusus cPanel email: `agents/monitoring-agent-cpanel-mail.sh` — konfigurasi di `/etc/monitoring-agent-cpanel-mail.conf` (salin dari `agents/systemd/monitoring-agent-cpanel-mail.conf.example`), jalankan via `agents/systemd/monitoring-agent-cpanel-email.timer`.
- Jalankan sebagai daemon real-time via `agents/systemd/monitoring-agent.service` (`systemctl enable --now monitoring-agent`), push default tiap 10 detik dengan deadband.
- `MASTER_URL` → `http://host/api/push` (umum) atau `http://host/api/push-disk` (disk health).
- `SERVER_TOKEN`: 64-char hex, harus sesuai token server di panel.
- Opsional signing HMAC-SHA256: header `X-Server-Timestamp` + `X-Server-Signature`.

## Migrasi
- `php migrate.php` — jalankan migrasi terbaru dari `database/migrations/`.
- Schema canonical selalu tergabung di `database/schema.sql`.

## Test
Tanpa server:
- `php tests/helpers_test.php`
- `php tests/schema_consistency_test.php`
- `php tests/architecture_test.php`

Butuh server berjalan (`php -S 127.0.0.1:8000 -t public public/router.php`):
- `BASE_URL=http://127.0.0.1:8000 bash tests/api_smoke.sh`
- `BASE_URL=http://127.0.0.1:8000 bash tests/authz_matrix.sh`
- Windows: `pwsh tests/smoke.ps1`
