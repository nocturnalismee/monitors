# servmon v2 — Full Audit & Improvement Design

## Meta
- **Date:** 2026-09-11
- **Scope:** Full architectural audit, security hardening, performance optimization, code quality improvement
- **Scale:** 20-100 servers, 2-5 admin users
- **Phase:** Stabilization (post-restrukturisasi)

## Current Architecture (Summary)

Front controller (`public/index.php`) → Kernel → Router (route matching + middleware chain) → Controller → Services (static classes) → PDO/MySQL. Workers via CLI cron jobs with flock-based locking. Optional Redis for caching and real-time streaming.

## Findings by Domain

### Architecture & Routing
- No global middleware stack (each route defines middleware individually)
- `PushController::index()` — 467-line god method handling auth, validation, metric processing, alerts, Redis
- All service classes are static (not mockable, no DI)
- Hardcoded service allowlist in PushController
- Eager route loading (17 files globbed every request)

### Database & Schema
- Transaction scope inconsistency: `latest_metric_id` update outside the metric+service transaction
- No foreign keys — orphan data when servers are deleted
- `db_column_exists()` queries `information_schema` uncached, called repeatedly
- `metrics_history` not partitioned — DELETE batch instead of TRUNCATE/DROP
- No LIMIT on full-table scans in alert workers

### Security
- **CRITICAL:** `public/router.php` — `__DIR__ . $path` without normalization (path traversal on dev server)
- **CRITICAL:** `public/install.php` accessible post-install (no auto-delete)
- **CRITICAL:** No `session_regenerate_id(true)` after login (session fixation)
- `config/local.php` permission 0644 — world-readable DB credentials and APP_KEY
- CSRF token exposed in JS global (`SERVMON_CSRF_TOKEN`)
- No exponential backoff on login rate limiting
- Settings encryption (`ENC:` prefix) compromised if APP_KEY leaked

### Performance & Workers
- No pagination on dashboard or server list
- History query uses `DATE_SUB(NOW(), ...)` — uncacheable
- Alert workers query full tables sequentially every minute
- Cleanup uses DELETE batch instead of partition operations
- Worker heartbeat lock files never cleaned up
- Redis connection failure silently ignored

### Code Quality & Testing
- Low test coverage: 5 unit test files, no AlertService/AuthService/PushController tests
- Architecture test uses `str_contains()` — fragile
- Static service classes not mockable
- No integration tests with real database
- No CI/CD pipeline

## Priority Action Items

### P0 — Critical (Immediate)
1. Fix `router.php` path traversal
2. Auto-delete `install.php` after successful installation
3. Regenerate session ID after login
4. Wrap all PushController writes in single transaction

### P1 — High (This Sprint)
5. Add pagination to dashboard and server list
6. Refactor `PushController::index()` — extract auth, validation, processor
7. Cache `db_column_exists()` results
8. Set `config/local.php` permission to 0640

### P2 — Medium (This Sprint)
9. Refactor `AlertService::evaluateServerThresholdAlerts()` — DRY threshold blocks
10. Refactor `SettingsController::index()` — extract action handlers
11. Add foreign keys between related tables
12. Implement exponential backoff login rate limiting

### P3 — Low (Backlog)
13. Partition `metrics_history` + replace DELETE batch with TRUNCATE/DROP
14. Setup CI (GitHub Actions)
15. Add unit tests for AlertService, AuthService, PushController validation
16. Refactor static services to instance classes for testability

## Implementation Constraints
- PHP 8.2+, vanilla, no Composer/autoloader pihak ketiga
- MySQL 8.0 / MariaDB
- Redis opsional (no-op fallback)
- `declare(strict_types=1)` di semua file
- Named PDO parameter (`:name`)
- All routes defined in `routes/*.php`
- No new `.php` files in `public/`
