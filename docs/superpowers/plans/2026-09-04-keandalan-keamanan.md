# Keandalan & Keamanan (C Full) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementasi C Full — hardening keamanan (TRUST_PROXY strict, allowlist IPv6, RateLimiter fail-closed, CSP), observability keandalan (queue depth, TTL unify), dan SLO Full (availability 99.9% 30d, error budget & burn-rate, p50/p95 ingest lag on-read, partition lag) tanpa migrasi schema.

**Architecture:** Modular services di `app/Services/Security`, `Reliability`, `Slo` dengan wiring tipis di `HealthController`/`DashboardController`/`bootstrap.php`; SLO on-read dari `metrics`/`metrics_history` bucket 300s (cap 2000 rows, cache 60s); queue depth cached 15s; proxy trust single source `ProxyTrustService` shared `get_client_ip()` dan bootstrap.

**Tech Stack:** PHP 8.2+ vanilla, PDO `db_one/db_all/db_exec` named params, `App\` → `app/` autoloader, MySQL `metrics` partitioned, Redis opsional, `cache_get/cache_set`, PHPUnit-style plain `tests/*.php` assert.

**Spec:** `docs/superpowers/specs/2026-09-04-keandalan-keamanan-design.md`

## Global Constraints

- PHP 8.2+ vanilla tanpa Composer/autoloader pihak ketiga — `App\` → `app/` via `app/Support/Autoloader.php`
- `declare(strict_types=1)` di semua file PHP baru/modify
- Named PDO parameter `:name`, helper `db() / db_one() / db_all() / db_exec()` (`app/Support/Globals/database.php`)
- Tidak menambah file `public/*.php` baru; clean URL via `routes/*.php`
- Bootstrap `config/bootstrap.php` → `config/env.php` → `config/local.php` (env var menang), guard `SERVMON_BOOTSTRAPPED`
- `storage/{logs,exports,cache,rate-limit}` canonical; `config/local.php` vs legacy `includes/local.php`
- Settings `ENC:` + `APP_KEY` encryption
- Semua file wajib `require config/bootstrap.php` lebih dulu
- Test via `php tests/helpers_test.php`, `schema_consistency_test.php`, `architecture_test.php`, `php -l`

---

## File Structure

**Create:**
- `app/Services/Security/ProxyTrustService.php` — `isHttps(array $server, string $trustedCsv):bool`, `parseTrustedProxies()`, `isTrustedProxy()`
- `app/Services/Security/PushAllowlistValidator.php` — `validate(string $raw):array{valid,bool,error,normalized,entries}`
- `app/Services/Reliability/QueueDepthService.php` — `collect():array{alert_delivery_queue,export_jobs_queued,export_jobs_running}`
- `app/Services/Slo/SloService.php` — `availability(int $windowDays,int $thresholdMinutes):array`
- `app/Services/Slo/IngestLagService.php` — `percentiles(int $windowDays):array{p50_ms,p95_ms,count}`
- `tests/proxy_trust_test.php`, `tests/push_allowlist_test.php`, `tests/rate_limiter_test.php`, `tests/queue_depth_test.php`, `tests/slo_service_test.php`, `tests/ingest_lag_test.php`

**Modify:**
- `config/bootstrap.php:46-65` — proxy trust via service, CSP, `session.use_strict_mode`, HSTS gate, Cache-Control scope
- `app/Support/Globals/core.php:191-242` — `get_client_ip()` delegasi ke ProxyTrustService
- `app/Support/RateLimiter.php:18-67` — fail-closed + clamp
- `app/Controllers/Admin/ServerEditController.php:100-106,161-184` — use validator (ServerAdd if needed)
- `app/Controllers/Api/PushController.php:68` — ipInAllowlist IPv6 via validator
- `app/Controllers/Api/HealthController.php:47-93` — add `queue_depth,slo,ingest_lag,partitions`, TTL unify via CronWorkerService
- `app/Controllers/Admin/DashboardController.php:68-95` — pass queue/slo/partitions to view
- `app/Views/admin/dashboard.php:1-96,185` — queue badge + SLO card + partition lag
- `app/Services/Settings/CronWorkerService.php:30-43` — TTL konstanta map (unify 7200→129600)
- `public/install.php:594-614,408` — APP_URL validate, chmod verify, TRUSTED_PROXIES input

---

### Task 1: ProxyTrustService + Bootstrap Hardening

**Files:**
- Create: `app/Services/Security/ProxyTrustService.php`
- Modify: `config/bootstrap.php:46-65`
- Modify: `app/Support/Globals/core.php:191-242`
- Test: `tests/proxy_trust_test.php`

**Interfaces:**
- Consumes: `env()` (`config/env.php`), `$_SERVER` superglobal
- Produces: `App\Services\Security\ProxyTrustService::isHttps(array $server, string $trustedCsv): bool`, `parseTrustedProxies(string $csv): array`, `isTrustedProxy(string $remoteAddr, array $list): bool` — consumed by Task 4 (Health) indirectly, and `core.php`

- [ ] **Step 1: Write failing test `tests/proxy_trust_test.php`**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$svc = \App\Services\Security\ProxyTrustService::class;
if (!class_exists($svc)) { echo "FAIL: class missing\n"; exit(1); }
function assert_eq($a,$b,$msg){ if($a!==$b){ echo "FAIL $msg ".var_export($a,true)." vs ".var_export($b,true)."\n"; exit(1);} echo "PASS $msg\n"; }
// spoof xhttpsy must be false even with trusted proxy
$server = ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_PROTO'=>'xhttpsy'];
assert_eq($svc::isHttps($server,'10.0.0.1'), false, 'xhttpsy strict false');
// strict https true
$server = ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_PROTO'=>'https'];
assert_eq($svc::isHttps($server,'10.0.0.1'), true, 'https true');
// comma list https, http -> true if any token https and trusted
$server = ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_PROTO'=>'https, http'];
assert_eq($svc::isHttps($server,'10.0.0.1'), true, 'comma https true');
// non-trusted remote -> false even with https header
$server = ['REMOTE_ADDR'=>'1.2.3.4','HTTP_X_FORWARDED_PROTO'=>'https'];
assert_eq($svc::isHttps($server,'10.0.0.1'), false, 'non-trusted false');
// parse
assert_eq($svc::parseTrustedProxies('127.0.0.1, ::1 ,'), ['127.0.0.1','::1'], 'parse trim');
// isTrusted
assert_eq($svc::isTrustedProxy('10.0.0.1',['10.0.0.1']), true, 'isTrusted true');
assert_eq($svc::isTrustedProxy('1.1.1.1',['10.0.0.1']), false, 'isTrusted false');
echo "ALL PASS proxy_trust\n";
```

- [ ] **Step 2: Run test to verify fail**

Run: `php tests/proxy_trust_test.php`
Expected: FAIL `class missing`

- [ ] **Step 3: Implement `app/Services/Security/ProxyTrustService.php`**

```php
<?php
declare(strict_types=1);
namespace App\Services\Security;
final class ProxyTrustService {
  public static function parseTrustedProxies(string $csv): array {
    $parts = explode(',', $csv);
    $out = [];
    foreach ($parts as $p) { $t = trim($p); if ($t !== '') $out[] = $t; }
    return $out;
  }
  public static function isTrustedProxy(string $remoteAddr, array $list): bool {
    $remote = trim($remoteAddr);
    if ($remote === '') return false;
    foreach ($list as $e) { if (strcasecmp($remote, trim((string)$e))===0) return true; }
    return false;
  }
  public static function isHttps(array $server, string $trustedCsv): bool {
    $https = !empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off' && $server['HTTPS'] !== '0';
    if ($https) return true;
    $trusted = self::parseTrustedProxies($trustedCsv);
    $remote = (string)($server['REMOTE_ADDR'] ?? '');
    if ($trusted === [] || !self::isTrustedProxy($remote, $trusted)) return false;
    $proto = strtolower(trim((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($proto === '') return false;
    foreach (explode(',', $proto) as $tok) {
      if (trim($tok) === 'https') return true;
    }
    return false;
  }
}
```

- [ ] **Step 4: Modify `config/bootstrap.php:46-65` and `app/Support/Globals/core.php`**

`config/bootstrap.php` — replace block 46-53 with:
```php
if (PHP_SAPI !== 'cli') {
  $trustedCsv = (string)env('TRUSTED_PROXIES', '127.0.0.1,::1');
  $isHttps = \App\Services\Security\ProxyTrustService::isHttps($_SERVER, $trustedCsv);
}
```
Add after headers `55-65` before `session_set_cookie_params`:
```php
ini_set('session.use_strict_mode','1');
```
Headers: keep `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cache-Control` but scope: if (`str_starts_with($_SERVER['REQUEST_URI']??'','/assets/')` etc.) skip `Cache-Control no-store` for assets; else set. Add CSP:
```php
if (!headers_sent()) { header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'"); }
```
HSTS only if `$isHttps` true (strict).

`app/Support/Globals/core.php:191-242` `get_client_ip()` — replace manual TRUSTED_PROXIES check with:
```php
$trustedCsv = (string)env('TRUSTED_PROXIES','127.0.0.1,::1');
$trusted = \App\Services\Security\ProxyTrustService::parseTrustedProxies($trustedCsv);
if (!\App\Services\Security\ProxyTrustService::isTrustedProxy($_SERVER['REMOTE_ADDR']??'', $trusted)) return $remote;
```
Keep X-Forwarded-For parsing.

- [ ] **Step 5: Run tests + lint + commit**

Run:
```
php -l app/Services/Security/ProxyTrustService.php
php -l config/bootstrap.php
php -l app/Support/Globals/core.php
php tests/proxy_trust_test.php
php tests/helpers_test.php
php tests/architecture_test.php
```
Expected: PASS
Commit:
```bash
git add app/Services/Security/ProxyTrustService.php config/bootstrap.php app/Support/Globals/core.php tests/proxy_trust_test.php
git commit -m "feat(security): strict proxy trust + CSP + strict_mode (C Full T1)"
```

---

### Task 2: PushAllowlistValidator + ServerEdit/ Push IPv6

**Files:**
- Create: `app/Services/Security/PushAllowlistValidator.php`
- Modify: `app/Controllers/Admin/ServerEditController.php:100-126`
- Modify: `app/Controllers/Api/PushController.php:60-80`
- Test: `tests/push_allowlist_test.php`

**Interfaces:**
- Consumes: `ProxyTrustService::parseTrustedProxies` (for style), `db_exec` not needed
- Produces: `PushAllowlistValidator::validate(string $raw): array{valid:bool,error:?string,normalized:?string,entries:string[]}`, `isIpv4Cidr` helper — consumed by PushController ip check

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$cls = \App\Services\Security\PushAllowlistValidator::class;
if(!class_exists($cls)){ echo "FAIL missing\n"; exit(1);}
function ae($a,$b,$m){ if($a!==$b){ echo "FAIL $m ".var_export($a,true)." vs ".var_export($b,true)."\n"; exit(1);} echo "PASS $m\n"; }
$r=$cls::validate('10.0.0.1, 10.0.0.0/24'); ae($r['valid'],true,'mixed valid'); ae($r['normalized'],'10.0.0.1, 10.0.0.0/24','norm');
$r=$cls::validate('10.0.0.1, 10.0.0.1'); ae(count($r['entries']),1,'dedupe');
$r=$cls::validate('10.0.0.0/33'); ae($r['valid'],false,'prefix 33 fail');
$r=$cls::validate(''); ae($r['valid'],true,'empty allow all');
$r=$cls::validate('2001:db8::1'); ae($r['valid'],true,'ipv6 single');
$r=$cls::validate(str_repeat('1.1.1.1, ',600)); ae($r['valid'],false,'length >2000 fail');
$r=$cls::validate("10.0.0.1 10.0.0.2\n10.0.0.3"); ae(count($r['entries']),3,'whitespace split');
echo "ALL PASS allowlist\n";
```

- [ ] **Step 2: Run -> FAIL missing**

Run: `php tests/push_allowlist_test.php` Expected FAIL

- [ ] **Step 3: Implement validator**

```php
<?php
declare(strict_types=1);
namespace App\Services\Security;
final class PushAllowlistValidator {
  public static function validate(string $raw): array {
    $trim = trim($raw);
    if ($trim === '') return ['valid'=>true,'error'=>null,'normalized'=>'','entries'=>[]];
    if (mb_strlen($raw) > 2000) return ['valid'=>false,'error'=>'Allowlist too long (max 2000 chars)','normalized'=>null,'entries'=>[]];
    $parts = preg_split('/[\s,]+/', $trim);
    $entries=[]; $seen=[];
    foreach ($parts as $p) { $e=trim((string)$p); if($e==='') continue; if(isset($seen[$e])) continue; $seen[$e]=true; $entries[]=$e; }
    foreach ($entries as $e) {
      if (str_contains($e,'/')) {
        [$ip,$pref]=explode('/',$e,2);
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) && !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)) return ['valid'=>false,'error'=>"Invalid IP in CIDR: {$e}",'normalized'=>null,'entries'=>[]];
        if (!ctype_digit($pref)) return ['valid'=>false,'error'=>"Invalid prefix in: {$e}",'normalized'=>null,'entries'=>[]];
        $isV6 = filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6) !== false;
        $max = $isV6 ? 128 : 32;
        $pi=(int)$pref; if($pi<0||$pi>$max) return ['valid'=>false,'error'=>"Invalid prefix in: {$e}",'normalized'=>null,'entries'=>[]];
      } else {
        if (!filter_var($e,FILTER_VALIDATE_IP)) return ['valid'=>false,'error'=>"Invalid IP: {$e}",'normalized'=>null,'entries'=>[]];
      }
    }
    return ['valid'=>true,'error'=>null,'normalized'=>implode(', ', $entries),'entries'=>$entries];
  }
}
```

- [ ] **Step 4: Wire controllers**

`ServerEditController.php:100-106` replace manual preg_split + FILTER_FLAG_IPV4 loop with:
```php
$v = \App\Services\Security\PushAllowlistValidator::validate((string)($pushAllowedIps ?? ''));
if (!$v['valid']) { flash_set('danger', $v['error']); redirect('servers/'.$id.'/edit'); }
$pushAllowedIps = $v['normalized'] ?? '';
```
Remove old `validatePushAllowedIps` private method (161-184).

`PushController.php:60-80` `ipInAllowlist` add IPv6 branch: if `str_contains(entry,'/')` handle IPv6 via `inet_pton` + mask; else `filter_var` both v4/v6.

- [ ] **Step 5: Verify + commit**

Run: `php -l app/Services/Security/PushAllowlistValidator.php && php -l app/Controllers/Admin/ServerEditController.php && php -l app/Controllers/Api/PushController.php && php tests/push_allowlist_test.php && php tests/proxy_trust_test.php`
Commit:
```bash
git add app/Services/Security/PushAllowlistValidator.php app/Controllers/Admin/ServerEditController.php app/Controllers/Api/PushController.php tests/push_allowlist_test.php
git commit -m "feat(security): IPv6 allowlist validator + controller wiring (C Full T2)"
```

---

### Task 3: RateLimiter Fail-Closed + CSP Verify

**Files:**
- Modify: `app/Support/RateLimiter.php:18-67`
- Test: `tests/rate_limiter_test.php`

**Interfaces:**
- Consumes: `storage/rate-limit/*.dat`, `get_client_ip()`
- Produces: `api_rate_check(string $key,int $maxPerMinute):bool` now fail-closed

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$cls=\App\Support\RateLimiter::class;
if(!class_exists($cls)) $cls='RateLimiter';
// test clamp negative -> must not bypass (return false)
$res = api_rate_check('test_negative_'.time(), -5);
if ($res !== false) { echo "FAIL negative should deny\n"; exit(1);} echo "PASS negative deny\n";
// normal allow
$res2 = api_rate_check('test_allow_'.time(), 5);
if ($res2 !== true) { echo "FAIL allow should pass\n"; exit(1);} echo "PASS allow\n";
echo "ALL PASS rate_limiter\n";
```

- [ ] **Step 2: Run -> expect FAIL negative allowed before fix**

Run: `php tests/rate_limiter_test.php`

- [ ] **Step 3: Implement fail-closed**

`app/Support/RateLimiter.php:18-57`:
```php
public static function check(string $key,int $max): bool {
  if ($max <=0) return false;
  if ($max > 10000) $max=10000;
  $dir=self::dir(); $file=$dir.'/'.md5($key).'.dat';
  $fp=@fopen($file,'c+');
  if($fp===false){ error_log("RateLimiter fopen fail $file"); return false; }
  if(!flock($fp,LOCK_EX)){ fclose($fp); error_log("RateLimiter flock fail"); return false; }
  // existing sliding window 60s logic, count vs max
}
```
Update wrapper `services_rate_limiter.php` passes through.

- [ ] **Step 4: Verify bootstrap CSP header sent**

Run: `php -l app/Support/RateLimiter.php && php tests/rate_limiter_test.php && php tests/helpers_test.php`

- [ ] **Step 5: Commit**

```bash
git add app/Support/RateLimiter.php tests/rate_limiter_test.php
git commit -m "fix(security): RateLimiter fail-closed + clamp (C Full T3)"
```

---

### Task 4: QueueDepthService + Health & Dashboard Exposure

**Files:**
- Create: `app/Services/Reliability/QueueDepthService.php`
- Modify: `app/Controllers/Api/HealthController.php:47-93`
- Modify: `app/Controllers/Admin/DashboardController.php:68-95`
- Modify: `app/Views/admin/dashboard.php:54-95`
- Modify: `app/Services/Settings/CronWorkerService.php:30-43`
- Test: `tests/queue_depth_test.php`

**Interfaces:**
- Consumes: `db_one/db_all`, `worker_health_status()`, `cache_get/set`, `StorageStatsService` (already exists)
- Produces: `QueueDepthService::collect():array{alert_delivery_queue:int,export_jobs_queued:int,export_jobs_running:int,error:?string}` — consumed by HealthController & DashboardController

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$svc=new \App\Services\Reliability\QueueDepthService();
$r=$svc->collect();
foreach(['alert_delivery_queue','export_jobs_queued','export_jobs_running'] as $k){ if(!array_key_exists($k,$r)){ echo "FAIL missing $k\n"; exit(1);} if(!is_int($r[$k])){ echo "FAIL not int $k\n"; exit(1);} }
echo "PASS queue_depth ".json_encode($r)."\n";
```

- [ ] **Step 2: Run -> FAIL class missing**

Run: `php tests/queue_depth_test.php`

- [ ] **Step 3: Implement service + TTL unify**

`app/Services/Reliability/QueueDepthService.php`:
```php
<?php
declare(strict_types=1);
namespace App\Services\Reliability;
final class QueueDepthService {
  public function collect(): array {
    try {
      $a=db_one("SELECT COUNT(*) AS c FROM alert_delivery_queue WHERE delivered_at IS NULL AND attempts<5");
      $q=db_one("SELECT COUNT(*) AS c FROM export_jobs WHERE status='queued'");
      $r=db_one("SELECT COUNT(*) AS c FROM export_jobs WHERE status='running'");
      return ['alert_delivery_queue'=>(int)($a['c']??$a['COUNT(*)']??0),'export_jobs_queued'=>(int)($q['c']??0),'export_jobs_running'=>(int)($r['c']??0),'error'=>null];
    } catch(\Throwable $e){ return ['alert_delivery_queue'=>0,'export_jobs_queued'=>0,'export_jobs_running'=>0,'error'=>$e->getMessage()];}
  }
}
```

`CronWorkerService.php` add `public const TTL = ['alert_check'=>180,...,'rollup_metrics'=>129600]` and `workerStatuses()` use `self::TTL`.

`HealthController.php` unify `rollup_metrics` 7200→129600 via `CronWorkerService::TTL['rollup_metrics']`, add:
```php
$queue=(new \App\Services\Reliability\QueueDepthService())->collect();
$checks['queue_depth']=$queue;
```

`DashboardController.php` same inject to view `$data['queueDepth']`.

`dashboard.php` under worker banner add `<div class="queue-badge">Queue: delivery {$queueDepth['alert_delivery_queue']} | export {$queueDepth['export_jobs_queued']}</div>` with `data-queue-depth` attrs.

- [ ] **Step 4: Verify**

Run: `php -l ... && php tests/queue_depth_test.php && php tests/helpers_test.php && php tests/architecture_test.php`

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reliability/QueueDepthService.php app/Controllers/Api/HealthController.php app/Controllers/Admin/DashboardController.php app/Views/admin/dashboard.php app/Services/Settings/CronWorkerService.php tests/queue_depth_test.php
git commit -m "feat(reliability): queue depth service + health/dashboard + TTL unify (C Full T4)"
```

---

### Task 5: SloService + IngestLagService + Partition Lag + Dashboard SLO Card

**Files:**
- Create: `app/Services/Slo/SloService.php`
- Create: `app/Services/Slo/IngestLagService.php`
- Modify: `app/Controllers/Api/HealthController.php`
- Modify: `app/Controllers/Admin/DashboardController.php`
- Modify: `app/Views/admin/dashboard.php`
- Modify: `public/assets/css/components.css` (SLO card styles)
- Test: `tests/slo_service_test.php`, `tests/ingest_lag_test.php`

**Interfaces:**
- Consumes: `QueueDepthService`, `StorageStatsService::collect()`, `setting_get('alert_down_minutes')`, `db_all`, `cache_get/set`
- Produces: `SloService::availability(int $windowDays,int $thresholdMinutes):array`, `IngestLagService::percentiles(int $windowDays):array` — consumed by HealthController & Dashboard

- [ ] **Step 1: Write failing tests**

`slo_service_test.php`:
```php
<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$s=new \App\Services\Slo\SloService();
$r=$s->availability(30,5);
foreach(['availability_pct','total_buckets','online_buckets','downtime_minutes','error_budget_minutes','budget_remaining_minutes','budget_remaining_pct','burn_rate'] as $k) if(!array_key_exists($k,$r)){ echo "FAIL $k\n"; exit(1);}
if($r['total_buckets']!==30*24*60/5){ echo "FAIL total buckets\n"; exit(1);}
echo "PASS slo ".json_encode($r)."\n";
```

`ingest_lag_test.php`:
```php
<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$s=new \App\Services\Slo\IngestLagService();
$r=$s->percentiles(1);
foreach(['p50_ms','p95_ms','count'] as $k) if(!array_key_exists($k,$r)){ echo "FAIL $k\n"; exit(1);}
echo "PASS ingest ".json_encode($r)."\n";
```

- [ ] **Step 2: Run -> FAIL missing**

Run: `php tests/slo_service_test.php` / `php tests/ingest_lag_test.php`

- [ ] **Step 3: Implement services**

`SloService.php`:
```php
<?php
declare(strict_types=1);
namespace App\Services\Slo;
final class SloService {
  public function availability(int $windowDays,int $thresholdMinutes): array {
    $totalBuckets = (int)($windowDays*24*60/5);
    $activeServers = (int)(db_one("SELECT COUNT(*) AS c FROM servers WHERE is_active=1")['c']??0);
    $total = $totalBuckets * max(1,$activeServers);
    $cutoff = date('Y-m-d H:i:s', time()-$windowDays*86400);
    try{
      $rows=db_all("SELECT DISTINCT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics_history WHERE bucket=300 AND recorded_at>=:cutoff UNION SELECT DISTINCT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics WHERE recorded_at>=:cutoff LIMIT 2000",[':cutoff'=>$cutoff]);
      $online = count($rows);
      // cap total vs online
      if($online>$total) $online=$total;
      $downtimeMin = max(0, ($total - $online)*5);
      $availability = $total>0 ? ($online/$total*100) : 0;
      $budgetMin = $windowDays*24*60*0.001; // 99.9%
      $remaining = $budgetMin - $downtimeMin;
      $remainingPct = $budgetMin>0 ? ($remaining/$budgetMin*100):0;
      $burn = $budgetMin>0 ? ($downtimeMin/$budgetMin):0;
      return ['availability_pct'=>round($availability,3),'total_buckets'=>$total,'online_buckets'=>$online,'downtime_minutes'=>$downtimeMin,'error_budget_minutes'=>round($budgetMin,2),'budget_remaining_minutes'=>round($remaining,2),'budget_remaining_pct'=>round($remainingPct,2),'burn_rate'=>round($burn,3)];
    }catch(\Throwable $e){ return ['availability_pct'=>0,'total_buckets'=>$total,'online_buckets'=>0,'downtime_minutes'=>0,'error_budget_minutes'=>0,'budget_remaining_minutes'=>0,'budget_remaining_pct'=>0,'burn_rate'=>0,'error'=>$e->getMessage()];}
  }
}
```

`IngestLagService.php`:
```php
<?php
declare(strict_types=1);
namespace App\Services\Slo;
final class IngestLagService {
  public function percentiles(int $windowDays): array {
    $cutoff=date('Y-m-d H:i:s', time()-$windowDays*86400);
    try{
      $rows=db_all("SELECT recorded_at FROM metrics WHERE recorded_at>=:cutoff ORDER BY recorded_at DESC LIMIT 2000",[':cutoff'=>$cutoff]);
      $lags=[];
      $now=time();
      foreach($rows as $r){ $ts=strtotime((string)($r['recorded_at']??'')); if($ts===false) continue; $lags[]=max(0,($now-$ts)*1000);}
      sort($lags);
      $n=count($lags);
      if($n===0) return ['p50_ms'=>null,'p95_ms'=>null,'count'=>0];
      $p50=$lags[(int)floor(0.5*($n-1))]; $p95=$lags[(int)floor(0.95*($n-1))];
      return ['p50_ms'=>$p50,'p95_ms'=>$p95,'count'=>$n];
    }catch(\Throwable $e){ return ['p50_ms'=>null,'p95_ms'=>null,'count'=>0,'error'=>$e->getMessage()];}
  }
}
```

HealthController add:
```php
$threshold=max(1,(int)setting_get('alert_down_minutes','5'));
$slo30=(new \App\Services\Slo\SloService())->availability(30,$threshold);
$slo7=(new \App\Services\Slo\SloService())->availability(7,$threshold);
$lag=(new \App\Services\Slo\IngestLagService())->percentiles(1);
$parts=(new \App\Services\Settings\StorageStatsService())->collect();
$parts['lag_days']= isset($parts['newest_partition']) && $parts['newest_partition'] ? (int)floor((time()-strtotime($parts['newest_partition']))/86400) : null;
$checks['slo']=['7d'=>$slo7,'30d'=>$slo30];
$checks['ingest_lag']=$lag;
$checks['partitions']=$parts;
```

DashboardController same, pass to view.

`dashboard.php` add SLO card after queue badge:
```html
<div class="card-neon slo-card" data-slo-card>
  <div class="card-header">SLO 99.9% (7d: <?=e($slo7['availability_pct'])?>% | 30d: <?=e($slo30['availability_pct'])?>%) Burn: <?=e($slo30['burn_rate'])?> Budget rem: <?=e($slo30['budget_remaining_pct'])?>% p95: <?=e($lag['p95_ms']??'n/a')?>ms Partition lag: <?=e($parts['lag_days']??'n/a')?>d</div>
</div>
```

Add CSS to `components.css` `.slo-card` flat enterprise (no neon).

- [ ] **Step 4: Verify**

Run: `php -l app/Services/Slo/SloService.php && php -l app/Services/Slo/IngestLagService.php && php tests/slo_service_test.php && php tests/ingest_lag_test.php && php tests/helpers_test.php`

- [ ] **Step 5: Commit**

```bash
git add app/Services/Slo/SloService.php app/Services/Slo/IngestLagService.php app/Controllers/Api/HealthController.php app/Controllers/Admin/DashboardController.php app/Views/admin/dashboard.php public/assets/css/components.css tests/slo_service_test.php tests/ingest_lag_test.php
git commit -m "feat(slo): availability 99.9% 30d + burn-rate + p50/p95 + partition lag (C Full T5)"
```

---

### Task 6: Install Hardening + Dashboard Polish & Final Verify

**Files:**
- Modify: `public/install.php:408,594-614`
- Modify: `app/Views/admin/dashboard.php` (final polish)
- Test: `tests/helpers_test.php`, `architecture_test.php`, `schema_consistency_test.php`, all new tests

**Interfaces:**
- Consumes: all previous services
- Produces: final `/api/health` contract + dashboard rendering

- [ ] **Step 1: Write install check (manual)**

No new unit test — verify via `php -l` + existing suite. Add assertion in `tests/helpers_test.php` if needed for APP_URL.

- [ ] **Step 2: Implement install.php hardening**

```php
// 594
$appUrl=rtrim(trim((string)($data['app_url']??'')),'/');
if(!filter_var($appUrl,FILTER_VALIDATE_URL) || parse_url($appUrl,PHP_URL_HOST)===null){ $errors[]="APP_URL invalid"; }
// 408
@chmod($localPath,0600);
$perms=fileperms($localPath);
if(($perms & 0777) !== 0600){ error_log("install chmod not 0600 perms=".decoct($perms)); }
// add TRUSTED_PROXIES input field in form + save to local.php
```

- [ ] **Step 3: Final verify all tests + lint**

Run:
```
php -l config/bootstrap.php
php -l app/Services/Security/*.php
php -l app/Services/Reliability/*.php
php -l app/Services/Slo/*.php
php tests/proxy_trust_test.php
php tests/push_allowlist_test.php
php tests/rate_limiter_test.php
php tests/queue_depth_test.php
php tests/slo_service_test.php
php tests/ingest_lag_test.php
php tests/helpers_test.php
php tests/architecture_test.php
php tests/schema_consistency_test.php
php tests/polling_visibility_test.php
php tests/settings_storage_test.php
```

- [ ] **Step 4: Commit**

```bash
git add public/install.php app/Views/admin/dashboard.php
git commit -m "chore(install): APP_URL validate + chmod verify + TRUSTED_PROXIES (C Full T6)"
```

---

## Self-Review Checklist

- [ ] Spec coverage: ProxyTrust (bootstrap+core), Allowlist IPv6, RateLimiter fail-closed, CSP/HSTS, QueueDepth, TTL unify, Slo 7d/30d + burn-rate, Ingest p50/p95, Partition lag — all tasks mapped
- [ ] No placeholders: all code blocks verbatim
- [ ] Type consistency: `collect():array`, `availability(int,int):array`, `percentiles(int):array` matches spec interfaces
- [ ] Global Constraints echoed in header

