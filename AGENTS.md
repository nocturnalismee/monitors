# servmon v2 — Agent Guide (restrukturisasi)

Versi ini adalah restrukturisasi servmon. JANGAN ubah root legacy `D:\php-server-monitoring\`. Semua pekerjaan di direktori ini (`monitoring-v2/`).

## Stack
- **PHP 8.2+**, vanilla, tanpa Composer/autoloader pihak ketiga
- **MySQL 8.0** / MariaDB; PDO via `db()`/`db_one()`/`db_all()`/`db_exec()` (lihat `app/Support/Globals/database.php`)
- **Redis opsional** (no-op bila `REDIS_ENABLED != 1`)

## Bootstrap chain
- `config/bootstrap.php` → load `config/env.php` (loader `env()`, membaca `config/local.php` lalu env var), definisikan konstanta, set timezone, header keamanan, session. Lalu register autoloader + `app/Support/functions.php`.
- `config/local.php` dihasilkan installer `public/install.php`. Env var menang atas `local.php`. Tidak ada `.env`.
- Guard `SERVMON_BOOTSTRAPPED` mencegah double-bootstrap. Semua file wajib `require config/bootstrap.php` lebih dulu.

## Autoload
- Autoloader custom: `app/Support/Autoloader.php` (`register()`), map `App\` → `app/`. Dipanggil dari bootstrap.
- CLI shim: `migrate.php` → `App\Console\MigrateCommand`, `reset-password.php` → `App\Console\ResetPasswordCommand`, `workers/*.php` → `App\Console\Workers\*`.

## Routing & URL
- Front controller: `public/index.php` → `App\Http\Kernel` → `App\Http\Router`. Dev server: `php -S 127.0.0.1:8000 -t public public/router.php`.
- Rute didefinisikan di `routes/*.php` (di-glob otomatis), format: `['methods' => [...], 'pattern' => '/...', 'handler' => [Controller::class, 'method'], 'middleware' => ['auth'|'admin'|'guest'|'csrf']]`. Placeholder `{id}`.
- Clean URL kontrak: `/dashboard`, `/servers[/add|/{id}|/{id}/edit|/{id}/setup]`, `/ping[/add|/{id}|/{id}/edit]`, `/disk-health[/{id}]`, `/ip-reputation[/add|/{id}|/{id}/edit]`, `/alerts`, `/audit-logs`, `/export[/download/{id}]`, `/settings`, `/login`, `/logout`, `/status`, `/`. API: `/api/push`, `/api/push-disk`, `/api/status`, `/api/alerts`, `/api/events`, `/api/health`, `/api/time`, `/api/public-alerts`, `/api/ip-reputation`.
- URL legacy (`/admin/*.php`, `/auth/*.php`, `/api/*.php`, `/public.php`, `/index.php`) di-301 ke clean URL via `routes/web_legacy.php`. Jangan menambah file `.php` baru di `public/`.

## HTTP layer
- `App\Http\Request` (method, path, query, body, headers, params), `App\Http\Response` (`json()`, `redirect()`, `html()`, `text()`).
- Middleware di `app/Http/Middleware/` (`Auth`, `Admin`, `Guest`, `Csrf`, `Registry`).
- Controller mengembalikan `Response`; pastikan selalu ada return.

## Views & helpers
- `App\Support\View::render($template, $data, $layout)` — `app/Views/<template>.php` + layout `app/Views/layouts/<layout>.php`.
- Global helpers di `app/Support/functions.php` (load semua file `app/Support/Globals/*.php`):
  - `e()`, `app_url()`, `route()`, `asset_url()`, `redirect()`, `is_post()`, `old()`, `flash_set()`/`flash_get_all()`
  - `db()`, `db_one()`, `db_all()`, `db_exec()`, `json_response()`
  - auth: `require_login()`, `require_role()`, `has_role()`, `current_user()`
  - security: `csrf_input()`, `csrf_validate()`, `get_client_ip()`
  - settings, cache, rate limiter, retention, worker health, IP reputation, notifikasi, export.

## DB & path (perbedaan vs root legacy)
- Schema canonical: `database/schema.sql` (selalu merged). Migrasi: `database/migrations/`, run via `php migrate.php`.
- Config: `config/local.php` (vs legacy `includes/local.php`).
- Storage: `storage/{logs,exports,cache,rate-limit}` (vs legacy `logs/`, dll).
- `servers.latest_metric_id` optimization column, token hash `token_hash CHAR(64)`, settings terenkripsi `ENC:` prefix dengan `APP_KEY`.

## API auth
- Push: header `X-Server-Token` (64-char hex). Optional signing HMAC-SHA256: `X-Server-Timestamp` + `X-Server-Signature` (`sha256(timestamp.payload)`).

## Workers (cron)
```
* * * * * php workers/alert-check.php
* * * * * php workers/alert-delivery.php
* * * * * php workers/export-worker.php
* * * * * php workers/ping-check.php
*/5 * * * * php workers/ip-reputation-check.php
30 0 * * * php workers/partition-maintain.php
30 2 * * * php workers/disk-cleanup.php
0 2 * * * php workers/disk-rollup.php
0 2 * * * php workers/rollup.php
0 3 * * * php workers/cleanup.php
```
Worker memakai `flock()` dan melapor ke `worker_health`.

## Rate limiting
File-based: `storage/rate-limit/` (legacy: temp dir). Gunakan helper rate limiter dari `app/Support/Globals/services_rate_limiter.php`.

## Test
```bash
php tests/helpers_test.php
php tests/schema_consistency_test.php
php tests/architecture_test.php
# Smoke butuh server: BASE_URL=http://127.0.0.1:8000 bash tests/api_smoke.sh
# AuthZ: BASE_URL=http://127.0.0.1:8000 bash tests/authz_matrix.sh
# Windows: pwsh tests/smoke.ps1
```
Gunakan `declare(strict_types=1)` di semua file PHP, named PDO parameter (`:name`), dan jangan menambah dependensi luar.
