# Keandalan & Keamanan (C Full) — Design Spec

**Tanggal:** 2026-09-04
**Scope:** Security hardening (TRUST_PROXY, allowlist IPv6, RateLimiter, CSP, CSRF, install), Observability keandalan (queue depth, worker health TTL unify, flap/dead-letter visibility), SLO & metrics latency (availability 99.9% 30d, error budget & burn-rate, p50/p95 ingest lag on-read, partition lag indicator)
**Status:** Approved — C Full (B + error budget & burn-rate + partition lag)
**Constraints Global (dari AGENTS.md):**
- PHP 8.2+ vanilla tanpa Composer/autoloader pihak ketiga; `App\` → `app/` via `app/Support/Autoloader.php`
- `declare(strict_types=1)` di semua file PHP
- Named PDO parameter `:name`, helper `db() / db_one() / db_all() / db_exec()` (`app/Support/Globals/database.php`)
- Tidak menambah `public/*.php` baru; clean URL via `routes/*.php` + front `public/index.php` → `Kernel` → `Router`
- Bootstrap `config/bootstrap.php` → `config/env.php` → `config/local.php` + env var menang; `SERVMON_BOOTSTRAPPED` guard
- Settings terenkripsi `ENC:` + `APP_KEY`; Redis opsional no-op
- `storage/{logs,exports,cache,rate-limit}` canonical
- Verifikasi via `php -l`, `php tests/helpers_test.php`, `architecture_test.php`, `schema_consistency_test.php`, `php -S` smoke

---

## 1. Konteks & Temuan Audit

### 1.1 Security (explore 1 — `ses_f92e2184affe`)
- `config/bootstrap.php:46-53` `TRUST_PROXY_HEADERS=1` trust `X-Forwarded-Proto` dari IP mana pun + `str_contains('https')` longgar → spoof `$isHttps` → `Secure` cookie HoS + HSTS via HTTP.
- `app/Support/Globals/core.php:191` `get_client_ip()` sudah gate `TRUSTED_PROXIES` (default `127.0.0.1,::1`) — bootstrap tidak share logic.
- `ServerEditController.php:161-184` `push_allowed_ips` split `[\s,]+` validasi `FILTER_FLAG_IPV4` + `ip2long` saja → IPv6 client selalu fail `PushController:68` `ipInAllowlist`; tanpa panjang max (TEXT) + tanpa canonicalize + `0.0.0.0/0` allow-all tanpa warning.
- `ServerAddController.php:78` & `ServerEditController.php:37` plaintext token di `$_SESSION['servmon_new_server_token_'.$id]` tidak pernah `unset` setelah display; legacy `token` column tetap readable.
- `app/Support/RateLimiter.php:25-31` fail-open `fopen===false` atau `flock` gagal → bypass limit; `api_rate_check` tanpa validasi `maxPerMinute`.
- Header `config/bootstrap.php:55-65` tanpa `Content-Security-Policy`; `Cache-Control: no-store` global mengganggu aset publik.
- `app/Http/Middleware/Csrf.php` + controller manual `csrf_validate` double-guard redirect divergen (middleware `303` dashboard/referer vs controller `servers/add`); `Referer` open-redirect same-host controllable; token per-session tanpa rotasi.
- `public/install.php:408` `@chmod 0600` suppressed di Windows; `APP_URL` tanpa `filter_var URL`; installer tidak auto-disable.

### 1.2 Worker & Observability (explore 2 — `ses_f92dfc949ffe`)
- 10 shim `workers/*.php:1-10` → `App\Console\Workers\*::run()` dengan `WorkerService.php:135-174` `flock` never-unlink (by design) + `worker_heartbeat_lock`.
- `WorkerService.php:10-70` `worker_health (worker_name PK, last_state enum running/ok/error, last_started_at/success_at/failure_at, last_error 500)`; `worker_health_status(name,staleSec)` → `ok|stale|unknown|error` via `age=now-last_success_at`.
- Flap: `AlertService.php:327` `flapSuppress 5m` + `cooldown 30m` + ping `failure_threshold 2`; deadband agent `deadband_cpu 0.5` etc.
- Dead-letter: `AlertDeliveryWorker.php:20-64` `attempts<5` fixed 5m backoff, after 5 tetap `delivered_at NULL` = implicit DLQ; `ExportWorker.php:19-25` recovery `running>1h → queued` + `failed`/`expired` 24h.
- Queue depth hanya via SQL `COUNT(*) WHERE delivered_at IS NULL` & `export_jobs status=queued` — **belum diexpose** di `/api/health` atau banner.
- Ingestion `PushController.php:265-410` sync TX `INSERT metrics NOW() + UPDATE servers {latest_metric_id,last_seen_at}` atomic; `last_seen = COALESCE(s.last_seen_at,m.recorded_at)` via `latest_metric_join_sql`; `serverStatusFromLastSeen(last_seen,alert_down_minutes)` threshold default 5m vs fallback `2`.
- Stale thresholds: `HealthController.php:47-56`, `DashboardController.php:68-75`, `CronWorkerService.php:33-42` → `alert_check 180, ping_check 300, alert_delivery 300, export_worker 300, ip_rep 21600, retention/rollup/disk/partition 129600`; **mismatch** `HealthController rollup_metrics 7200` vs `CronWorkerService 129600`.
- Dashboard `dashboard.js:94-109` `updateStaleHint` + SSE `/api/stream?limit=50` `pausePoller` on open; banner `dashboard.php:54-95` hanya liveness, tanpa depth/latency.

### 1.3 SLO / Metrics (explore 3 — `ses_f92dd71d0ffe`)
- Push tanpa queue, latency = DB+Redis RTT; HMAC `X-Server-Timestamp` 60s drift; rate 600/min.
- Partition `metrics PARTITION BY RANGE COLUMNS(recorded_at) (pmin <'2026-08-01',pmax MAXVALUE)`; `PartitionMaintainWorker` daily `REORGANIZE pmax` 31d ahead `pYYYYMMDD`; `RetentionService.php:115-141` `DROP PARTITION` proteksi `latest_metric_id`.
- Thresholds `AlertService.php:211` `mail 50/100, cpu 2/4, ram 85/95, disk 90/97, down 5m`; ping `failure_threshold 2`.
- Dashboard hanya `Total/Online/Down/Pending`; **nol** `availability %`, `error budget`, `burn-rate`, `p99 push`, `partition lag`. `StatusController` tidak hitung `SUM(down)/window`.
- `tests/architecture_test.php` hanya assert push TX & `token_hash CHAR(64)` — SLO gap bukan regresi.

---

## 2. Keputusan Produk (jawaban 3 pertanyaan)

1. **SLO Full:** p50/p95 ingest lag + error budget 99.9% 30d + burn-rate + partition lag. **Tanpa kolom baru** — on-read agregat dari `metrics`/`metrics_history` bucket 300s.
2. **TRUST_PROXY strict:** split `,` + trim + `=== 'https'` lowercase strict + gate `REMOTE_ADDR ∈ TRUSTED_PROXIES` (share logic `get_client_ip`). Operator wajib set `TRUSTED_PROXIES` jika pakai proxy. HSTS hanya saat `$isHttps` real.
3. **Observability placement:** queue depth di `/api/health` `checks.queue_depth` + dashboard banner badge; ingest p50/p95 on-read (PHP sort, tanpa `ingest_lag_ms` kolom); SLO `availability = online_buckets/total_buckets` bucket 300s; partition lag via `StorageStatsService` (`oldest/newest partition`, `partition_count`, `is_partitioned`) ke `/api/health`.

---

## 3. Pendekatan (2 opsi + 1 berat — rekomendasi: #2)

### Opsi 1 — Monolitik (cepat, coupling tinggi)
Tambah logika inline di `HealthController`, `DashboardController`, `config/bootstrap.php` tanpa service baru. Queue `COUNT(*)` inline, SLO hitung inline di controller, proxy fix inline. **Pro:** 1-2 file. **Kontra:** duplikasi `StorageStatsService` & `CronWorkerService`, sulit test; controller fat kembali.

### Opsi 2 — Modular Services (REKOMENDASI — ringan, testable, preservasi)
Ekstrak 3 service baru di `app/Services/` + 1 helper proxy, wiring tipis di controller, tanpa migrasi. SLO on-read (PHP percentile) dengan cap scan 2000 buckets/limit. Queue depth cached 15s via `cache_get/set`. Unifikasi TTL via konstanta tunggal.

### Opsi 3 — Infra Berat (presisi historis, butuh migrasi)
Tambah `metrics.ingest_lag_ms`, `metrics_history` histogram table, cron agregasi SLO per jam, Redis ring histogram. **Pro:** p95 historis akurat. **Kontra:** migrasi `ALTER PARTITION BY RANGE` mahal, backfill, schema drift, overkill untuk 99.9% 30d.

**Trade-off ringkas:** #2 mencapai Full SLO tanpa migrasi, menjaga `declare(strict_types)` & `db_*` helper, testable via unit tanpa DB (inject `time()`/`db` mock). #1 mengulang utang `SettingsController` fat; #3 layak follow-up setelah 30d data.

---

## 4. Arsitektur (Opsi 2)

```
config/bootstrap.php --trust--> ProxyTrustService::isHttpsFromRequest():bool
        |                                ^
        +--> get_client_ip() <------------+ (share TRUSTED_PROXIES parsing)

app/Controllers/Api/HealthController --uses--> QueueDepthService::collect():array
        |                                    SloService::availability(window):array
        |                                    IngestLagService::percentiles(window):array
        |                                    StorageStatsService::collect():array (existing)
        |                                    CronWorkerService::workerStatuses():array (existing)
        +--> JSON {status, checks: {workers, queue_depth, slo, ingest_lag, partitions}}

app/Controllers/Admin/DashboardController --uses--> sama, pass ke View banner
app/Views/admin/dashboard.php --render--> worker-health-banner + queue badge + SLO card (availability 7d/30d, budget remaining, burn-rate, p95, partition lag)

app/Controllers/Admin/ServerEditController --uses--> PushAllowlistValidator::validate(string):array{valid,bool,error,normalized}
app/Support/RateLimiter --fix--> fail-closed + maxPerMinute guard + CSP header di bootstrap
```

**Tidak ada migrasi schema pada fase ini.** Jika on-read SLO terbukti berat (>200ms), follow-up tambah kolom `ingest_lag_ms` & job rollup.

---

## 5. Komponen & Interface

### 5.1 `App\Services\Security\ProxyTrustService` (baru)
- **File:** `app/Services/Security/ProxyTrustService.php`
- **Interface:**
  ```php
  namespace App\Services\Security;
  final class ProxyTrustService {
    public static function isHttps(array $server, string $trustedProxiesCsv): bool; // strict === 'https' per token split , + trim lower
    public static function parseTrustedProxies(string $csv): array; // -> string[] trimmed non-empty
    public static function isTrustedProxy(string $remoteAddr, array $trustedList): bool;
  }
  ```
- **Konsumsi:** `config/bootstrap.php:46-53` & `app/Support/Globals/core.php:196-242` `get_client_ip()` — keduanya delegasi ke service agar single source of truth.
- **Hasil:** `Secure` cookie & `HSTS` hanya jika `HTTPS|| (TRUST_PROXY_HEADERS=1 && isTrustedProxy && proto===https)`. `Cache-Control` tidak global untuk aset publik (preserve `no-store` hanya untuk HTML).
- **Test:** `tests/proxy_trust_test.php` — spoof `X-Forwarded-Proto: xhttpsy` → false, `https, http` → true (first https), `REMOTE_ADDR` not in list → false.

### 5.2 `App\Services\Security\PushAllowlistValidator` (baru, ekstrak dari ServerEdit)
- **File:** `app/Services/Security/PushAllowlistValidator.php`
- **Interface:**
  ```php
  final class PushAllowlistValidator {
    /** @return array{valid:bool, error:?string, normalized:?string, entries:string[]} */
    public static function validate(string $raw): array;
    public static function isIpv4Cidr(string $entry): bool;
  }
  ```
- **Aturan:** split `[\s,]+`, kosong→ valid normalized `''`; tiap entry IPv4 `FILTER_VALIDATE_IP|IPV4` atau `ip/prefix` (network IPv4 valid + prefix `0-32` ctype_digit); panjang total `<=2000` chars; `0.0.0.0/0` izinkan tapi warning (return normalized tanpa error, caller log); deduplicate preserve order; output normalized `implode(', ', entries)`.
- **Konsumsi:** `ServerEditController.php:100-106`, `ServerAddController` jika allowlist ditambah di create, `PushController.php:68` `ipInAllowlist` tambah IPv6 branch (jika entry IPv6 valid, validate via `FILTER_FLAG_IPV6` — forward-compat).
- **Test:** `tests/push_allowlist_test.php` 8 kasus: mix space/comma, duplicate, `/33` fail, `2001:db8::1` pass setelah IPv6.

### 5.3 `App\Support\RateLimiter` hardening
- **File:** `app/Support/RateLimiter.php:18-67`
- **Perubahan:** `api_rate_check(string $key,int $maxPerMinute):bool` — `fopen===false` → `return false` (fail-closed) + log `error_log`; `flock` gagal → `return false`; `maxPerMinute<=0` → `return false` (deny) atau clamp `1`; `maxPerMinute>10000` clamp; existing `md5(endpoint:ip)` + `storage/rate-limit/*.dat` tetap.
- **Header:** `app/Support/RateLimiter.php:60` tambah `X-RateLimit-Limit/Remaining` opsional (non-breaking).
- **Test:** `tests/rate_limiter_test.php` — disk full mock, negative max.

### 5.4 Security bootstrap headers + install
- **File:** `config/bootstrap.php:55-65` — tambah `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' ...` minimal tanpa break inline `dashboard.js`/`detail.js` (whitelist `self` + maybe `'unsafe-inline'` untuk CSS inline legacy, lalu iterasi nonce). `X-Frame-Options SAMEORIGIN` preserve + `frame-ancestors 'self'`. HSTS `max-age=31536000; includeSubDomains` hanya jika `ProxyTrustService::isHttps` true. Session `ini_set('session.use_strict_mode','1')`.
- **File:** `public/install.php:594-614` — validasi `APP_URL` via `filter_var FILTER_VALIDATE_URL` + host non-empty; `@chmod` verifikasi `fileperms & 0777 === 0600` else warning; `TRUSTED_PROXIES` field di installer.

### 5.5 `App\Services\Reliability\QueueDepthService` (baru)
- **File:** `app/Services/Reliability/QueueDepthService.php`
- **Interface:**
  ```php
  namespace App\Services\Reliability;
  final class QueueDepthService {
    /** @return array{alert_delivery_queue:int, export_jobs_queued:int, export_jobs_running:int} */
    public function collect(): array;
  }
  ```
- **Query:** `SELECT COUNT(*) FROM alert_delivery_queue WHERE delivered_at IS NULL AND attempts<5` (idx `delivered_at,available_at`); `SELECT COUNT(*) FROM export_jobs WHERE status='queued'` dan `status='running'`. Cache 15s `cache_get('queue_depth', fn)` atau `status_cache`.
- **Konsumsi:** `HealthController.php:47-93` `checks.queue_depth`, `DashboardController.php:68-95` banner badge, test via `db_*` mock.

### 5.6 Unifikasi Worker TTL
- **File:** `app/Services/Settings/CronWorkerService.php:30-43` — jadikan konstanta tunggal `WORKER_TTL` map; `HealthController.php:50` ganti `7200` → `129600` untuk `rollup_metrics` (daily `0 2 * * *` → 36h staleness benar). DashboardController sudah via service.
- **Fix:** `HealthController` import `CronWorkerService` map, tidak hardcode TTL.

### 5.7 `App\Services\Slo\SloService` + `IngestLagService` (baru)
- **File:** `app/Services/Slo/SloService.php`
  ```php
  namespace App\Services\Slo;
  final class SloService {
    /** @param int $windowDays 7|30; @return array{availability_pct:float, total_buckets:int, online_buckets:int, downtime_minutes:int, error_budget_pct:float, budget_remaining_pct:float, burn_rate:float} */
    public function availability(int $windowDays, int $onlineThresholdMinutes): array;
  }
  ```
  - **Source:** bucket 300s dari `metrics_history` (`bucket IN (300)`) union `metrics` raw untuk window tail; `total_buckets = windowDays*24*60/5`; `online_buckets = COUNT(DISTINCT bucket WHERE has metric)` per server agregat? Untuk global SLO: `total = servers * buckets`, `online = sum over servers`. Atau per-server then avg. Spesifikasi: global system SLO = `online_buckets / total_buckets` across all active servers (`servers.is_active=1`). Query `SELECT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics_history WHERE bucket=300 AND recorded_at>=cutoff` + `metrics` fallback, deduplicate.
  - **Error budget:** untuk 99.9% → `budget = 0.1% * windowMinutes = 43.2m (30d)`, `remaining = budget - downtime`, `burn_rate = downtime / (windowDays*24*60) / (0.001)` atau `downtime/budget`.

- **File:** `app/Services/Slo/IngestLagService.php`
  ```php
  final class IngestLagService {
    /** @return array{p50_ms:?int, p95_ms:?int, count:int, window_days:int} */
    public function percentiles(int $windowDays): array;
  }
  ```
  - **Source on-read:** `SELECT TIMESTAMPDIFF(MICROSECOND, recorded_at, NOW())/1000` tidak; karena `recorded_at=NOW()` server time, lag ≈ `NOW() - recorded_at` saat select (lag staleness) bukan push lag. Untuk push lag tanpa kolom, hitung `GREATEST(0, UNIX_TIMESTAMP(NOW())-UNIX_TIMESTAMP(recorded_at))*1000` across recent metrics (last 2000 rows). Sort PHP `percentile`. Cap 2000 rows; jika butuh presisi, follow-up kolom `ingest_lag_ms`.
  - **Alternatif phase-1:** staleness percentile sebagai proxy ingest lag — didokumentasikan limitation.

- **File:** `app/Controllers/Api/HealthController.php` — tambah `checks.slo` & `checks.ingest_lag` & `checks.partitions` (dari `StorageStatsService`).

### 5.8 `App\Services\Settings\StorageStatsService` reuse (existing `app/Services/Settings/StorageStatsService.php:1-37`)
- Sudah fix alias `DATA_LENGTH AS data_length`. Tambah `partition_lag_days = DATEDIFF(NOW(), newest_partition)` dan `is_stale = lag>2`.

---

## 6. Data Flow

1. **Request → bootstrap:** `ProxyTrustService::isHttps($_SERVER, env('TRUSTED_PROXIES'))` → `$isHttps` → session cookie `secure`, HSTS.
2. **Admin save server:** `PushAllowlistValidator::validate($_POST['push_allowed_ips'])` → error flash atau `db_exec` normalized.
3. **Push ingest:** `PushController` `allowlist` check now IPv4+IPv6 via validator; `RateLimiter::api_rate_check` fail-closed.
4. **Health poll:** `GET /api/health` → `WorkerService::worker_health_status` (TTL unified) + `QueueDepthService::collect()` + `SloService::availability(30, alert_down_minutes)` + `IngestLagService::percentiles(1)` + `StorageStatsService::collect()` → JSON `{status: ok|degraded, checks: {...}}` rate 30/min.
5. **Dashboard:** `DashboardController` same services → View `dashboard.php` render banner `worker-health-banner` + `queue-depth` + `slo-card` (7d/30d availability, budget, burn-rate, p95, partition lag). JS `dashboard.js` `updateStaleHint` tetap, tapi tambah `fetch /api/health` via `startPoller` 30s.

---

## 7. Error Handling & Edge Cases

- **ProxyTrust:** `TRUSTED_PROXIES=''` → only loopback treated? default `127.0.0.1,::1` else empty → no trust. `X-Forwarded-Proto: https, http` → first token `https`? Spec split all, any `https` → true? Strict: if any token `===https` → true (since proto list). Logika: `foreach token strict https → true`.
- **Allowlist:** empty → allow all? Existing `PushController:68` jika `push_allowed_ips==''` → allow all. Preserve.
- **RateLimiter:** disk full → 429? fail-closed returns false → `api_rate_limit_exceeded` 429; existing callers treat false as limit exceeded (correct). Log.
- **QueueDepth:** DB error → catch `Throwable` return `['alert_delivery_queue'=>0,...,'error'=>msg]` mirip StorageStats.
- **SloService:** no metrics yet → `availability_pct=null` + `total_buckets = window*288` fallback; `burn_rate=0`.
- **IngestLag:** `TIMESTAMPDIFF` negative? clamp 0.
- **Partition:** `is_partitioned=false` → `partition_lag=null`.

---

## 8. Testing Strategy

- **Unit tanpa DB (mock db_* via function_exists + override atau via integration dengan real DB):**
  - `tests/proxy_trust_test.php` — 6 kasus spoof, list parsing, trusted gate.
  - `tests/push_allowlist_test.php` — 10 kasus mixed delimiters, duplicate, prefix bounds, length, IPv6, 0.0.0.0/0.
  - `tests/rate_limiter_test.php` — fail-closed, clamp.
  - `tests/queue_depth_test.php` — requires DB: create temp `alert_delivery_queue` counts.
  - `tests/slo_service_test.php` — availability math: 30d buckets, downtime, budget, burn-rate (pure PHP calc, no DB).
  - `tests/ingest_lag_test.php` — percentile sort.
- **Integration:** `php tests/helpers_test.php`, `architecture_test.php`, `schema_consistency_test.php`, `polling_visibility_test.php`, `settings_storage_test.php` tetap pass.
- **Manual smoke:** `BASE_URL=http://127.0.0.1:8010 bash tests/api_smoke.sh` check `/api/health` includes `queue_depth,slo,ingest_lag,partitions`; `curl -H "X-Forwarded-Proto: https" http://127.0.0.1:8010/api/health` dari non-trusted IP → tidak jadi https.

---

## 9. Rollout & Kompatibilitas

- **Tidak ada migrasi** di phase 1; semua service on-read.
- **Env var baru/tweak:** `TRUSTED_PROXIES` (csv) dokumentasi di `config/local.php` contoh + `public/install.php` input; `TRUST_PROXY_HEADERS` tetap `0|1`.
- **Breaking:** jika user pakai proxy tanpa set `TRUSTED_PROXIES`, setelah fix `X-Forwarded-*` diabaikan → cookie `Secure` false di HTTP → login tetap work via HTTP, tapi HSTS tidak terkirim (lebih aman). Komunikasikan di `docs/ops/reverse-proxy.md` (opsional).
- **Performance:** `SloService` scan `metrics_history` up to 30d * 288 = 8640 buckets per server; dengan 10 server → 86k rows; limit `2000` per query + `DISTINCT` → <100ms di MySQL indexed `idx_server_recorded`. Cache 60s via `cache_get`.
- **Security headers:** CSP `default-src 'self'` + `style-src 'self' 'unsafe-inline'` minimal; `script-src 'self'` — inline legacy `detail.js` sudah ekstrak, `dashboard.php` inline `<script>` minimal bisa tambah nonce follow-up.

---

## 10. File Map (create/modify)

- **Create:**
  - `app/Services/Security/ProxyTrustService.php`
  - `app/Services/Security/PushAllowlistValidator.php`
  - `app/Services/Reliability/QueueDepthService.php`
  - `app/Services/Slo/SloService.php`
  - `app/Services/Slo/IngestLagService.php`
  - `tests/proxy_trust_test.php`, `tests/push_allowlist_test.php`, `tests/queue_depth_test.php`, `tests/slo_service_test.php`, `tests/ingest_lag_test.php`

- **Modify:**
  - `config/bootstrap.php` — proxy trust via service, CSP, strict_mode, HSTS gate
  - `app/Support/Globals/core.php` — `get_client_ip()` delegasi ke ProxyTrustService
  - `app/Support/RateLimiter.php` — fail-closed + clamp
  - `app/Controllers/Admin/ServerEditController.php` (+ `ServerAddController.php` if needed) — use validator
  - `app/Controllers/Api/PushController.php` — ipInAllowlist IPv6 support via validator
  - `app/Controllers/Api/HealthController.php` — tambah `queue_depth, slo, ingest_lag, partitions`, TTL unify
  - `app/Controllers/Admin/DashboardController.php` — pass queue/slo/partition ke view
  - `app/Views/admin/dashboard.php` — SLO card + queue badge + partition lag
  - `app/Services/Settings/CronWorkerService.php` — TTL map konstanta
  - `public/install.php` — APP_URL validate, chmod verify, TRUSTED_PROXIES input

---

## 11. Acceptance Criteria

- [ ] `curl -H "X-Forwarded-Proto: xhttpsy" /api/health` dari `127.0.0.1` tanpa trusted → tidak dianggap https (cookie Secure false, no HSTS)
- [ ] `curl -H "X-Forwarded-Proto: https, http" -H "X-Forwarded-For: 10.0.0.1"` dari `REMOTE_ADDR=trusted proxy` → https true
- [ ] `push_allowed_ips="10.0.0.1, 10.0.0.0/24, 2001:db8::1"` valid normalized; `"10.0.0.0/33"` → flash error
- [ ] `api_rate_check` dengan `fopen` fail → 429 (fail-closed)
- [ ] `GET /api/health` JSON contains `checks.queue_depth.{alert_delivery_queue,export_jobs_queued}`, `checks.slo.{availability_pct_7d,availability_pct_30d,budget_remaining_pct,burn_rate}`, `checks.ingest_lag.{p50_ms,p95_ms}`, `checks.partitions.{is_partitioned,partition_count,lag_days}`
- [ ] Dashboard banner menampilkan queue badge + SLO 7d/30d + burn-rate + partition lag
- [ ] Semua `php tests/*.php` pass; `php -l` no syntax error; `architecture_test.php` pass

---

## 12. Follow-up (out of scope phase 1)

- Kolom `metrics.ingest_lag_ms` + cron histogram jika on-read >200ms
- CSP nonce per-request (vs `unsafe-inline`)
- CSRF single-guard refactor (hapus manual `csrf_validate` di controller, sisakan middleware) — butuh audit `ServerAdd/Edit` flash path
- `token` plaintext column drop + forced migration job
