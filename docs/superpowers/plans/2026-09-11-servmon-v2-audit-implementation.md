# servmon v2 — Audit Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all P0 (critical) and P1 (high) security/architecture issues identified in the audit.

**Architecture:** Incremental fixes — no structural changes. Each task is self-contained, modifies at most 2-3 files, and is independently testable.

**Tech Stack:** PHP 8.2+, MySQL 8.0/MariaDB, vanilla (no Composer)

**Spec:** `docs/superpowers/specs/2026-09-11-servmon-v2-audit-design.md`

## Global Constraints
- `declare(strict_types=1)` in all PHP files
- Named PDO parameters (`:name`)
- All routes in `routes/*.php`
- No new `.php` files in `public/`
- `e()` for HTML output, `json_encode()` for JS context
- Follow existing code style (static services, global helper functions)

---

### Task 1: Fix `public/router.php` path traversal

**Files:**
- Modify: `public/router.php`

**Problem:** `$file = __DIR__ . $path` where `$path` is from `parse_url(REQUEST_URI)` — can contain `..` path traversal, serving files outside `public/`.

- [ ] **Step 1: Read current file**

Read `public/router.php` (already read above — 19 lines).

- [ ] **Step 2: Add path normalization**

```php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
// Normalize to prevent path traversal — reject any path containing '..'
if (str_contains($path, '..')) {
    http_response_code(400);
    echo 'Bad Request';
    return true;
}
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
return true;
```

- [ ] **Step 3: Verify syntax**

```bash
php -l public/router.php
```

- [ ] **Step 4: Commit**

```bash
git add public/router.php
git commit -m "fix(security): normalize path in router.php to prevent path traversal"
```

---

### Task 2: Auto-delete `public/install.php` after successful install

**Files:**
- Modify: `public/install.php`

**Problem:** `install.php` is left accessible post-install. If attacker has filesystem write access (LFI/etc), they can delete `local.php` and re-install.

- [ ] **Step 1: Add auto-delete after successful install**

After the success block (line ~629, after `$success = true`), add:

```php
// Auto-remove installer on successful fresh install for security
if ($success && $lastOperation === 'install') {
    $installPath = __FILE__;
    if (is_file($installPath)) {
        @unlink($installPath);
        $summary[] = ['step' => 'cleanup', 'message' => 'Installer auto-deleted for security.'];
    }
}
```

Place this right after line 630 (`$lastOperation = 'install';`).

Also update the success message (line 894) from:
```php
<div class="mt-2">If this is a production server, remove or rename `public/install.php` after setup.</div>
```
to:
```php
<div class="mt-2">Installer has been auto-deleted. Please verify `config/local.php` is not world-readable.</div>
```

- [ ] **Step 2: Verify syntax**

```bash
php -l public/install.php
```

- [ ] **Step 3: Commit**

```bash
git add public/install.php
git commit -m "fix(security): auto-delete install.php after successful install"
```

---

### Task 3: Session regeneration after login

**Files:**
- Modify: `app/Services/AuthService.php`

**Problem:** `AuthService::loginUser()` already calls `session_regenerate_id(true)` at line 120. This is already correct. Verify this is the case and confirm.

**Verification:** Line 120 of `AuthService.php` already contains `session_regenerate_id(true)`. No change needed.

- [ ] **Step 1: Verify session_regenerate_id exists**

Confirm line 120 in `app/Services/AuthService.php`:
```php
public static function loginUser(array $user): void
{
    session_regenerate_id(true);
```
✅ Already present. No fix needed.

- [ ] **Step 2: No changes — mark as done**

```bash
git commit --allow-empty -m "chore(audit): verify session_regenerate_id already in place after login"
```

---

### Task 4: Wrap all PushController writes in a single transaction

**Files:**
- Modify: `app/Controllers/Api/PushController.php`

**Problem:** The `beginTransaction()` at line 265 wraps metric INSERT but `latest_metric_id` UPDATE (line 284) is inside a nested try/catch that can roll back. Service writes (lines 350-386) are inside the same transaction but the `latest_metric_id` update at line 282-296 can roll back independently, leaving metric_id pointer orphaned.

**Fix:** Ensure `latest_metric_id` update uses the same `$ingestPdo` and that any rollback in the service section also rolls back the metric insert. Currently the flow is:

1. beginTransaction (line 265)
2. INSERT metrics (line 268)
3. UPDATE servers.latest_metric_id (line 284) — **inside try/catch with its own rollBack**
4. INSERT service_metrics + server_service_states (lines 350-386) — **inside try/catch with its own rollBack**
5. commit (line 400)

The issue is: if step 4 fails, step 3 already executed and committed nothing (it's in same transaction). Actually, since all steps share `$ingestPdo`, the rollBack in step 3 or 4 undoes all changes including step 2. The current code is actually correct for rollback behavior.

However, the `commit()` at line 400 could throw. Fix: wrap the commit in the same try/catch as step 4 to ensure service write failure also prevents the metric commit.

**Simpler fix:** Move `commit()` inside the service-write try block, and if no service writes exist, commit after the `latest_metric_id` update. This eliminates the orphan window.

- [ ] **Step 1: Restructure transaction commit**

Replace the current flow (lines 265-407) with:

```php
$ingestPdo = db();
$ingestPdo->beginTransaction();
try {
    // Insert metric
    db_exec(
        'INSERT INTO metrics (' . implode(', ', $metricColumns) . ', recorded_at) VALUES (' . implode(', ', $metricPlaceholders) . ', NOW())',
        $metricParams
    );
    $metricId = (int) db()->lastInsertId();

    // Update latest_metric_id pointer
    if ($metricId > 0 && db_column_exists('servers', 'latest_metric_id')) {
        $lastSeenSql = db_column_exists('servers', 'last_seen_at') ? ', last_seen_at = NOW()' : '';
        db_exec('UPDATE servers SET latest_metric_id = :mid' . $lastSeenSql . ' WHERE id = :sid', [
            ':mid' => $metricId,
            ':sid' => $serverId,
        ]);
    }

    // Process services
    $serviceTransitions = [];
    if ($metricId > 0 && !empty($services) && self::dbTableExists('service_metrics') && self::dbTableExists('server_service_states')) {
        // ... existing service processing logic (lines 307-387) ...
        // (copy the entire block from lines 299-397, minus the separate try/catch)
        $prevRows = db_all(/*...*/);
        // ... etc ...
    } elseif ($metricId > 0 && !empty($services)) {
        error_log('push.php service tables missing, skip service write server_id=' . $serverId);
    }

    $ingestPdo->commit();
} catch (Throwable $e) {
    if ($ingestPdo->inTransaction()) {
        $ingestPdo->rollBack();
    }
    error_log('push.php ingest failed server_id=' . $serverId . ' error=' . $e->getMessage());
    json_response(['error' => 'Failed to persist metrics'], 500);
}
```

The key change: single try/catch wrapping ALL writes (metric, latest_metric_id, services) + single commit at the end inside the try block.

- [ ] **Step 2: Verify syntax**

```bash
php -l app/Controllers/Api/PushController.php
```

- [ ] **Step 3: Commit**

```bash
git add app/Controllers/Api/PushController.php
git commit -m "fix(transaction): single atomic transaction for push ingest (metric + service + pointer)"
```

---

### Task 5: Add pagination to dashboard and server list

**Files:**
- Modify: `app/Controllers/Admin/DashboardController.php`
- Modify: `app/Controllers/Admin/ServersController.php`

**Problem:** Both controllers load ALL servers without LIMIT. For 20-100 servers this is acceptable but the pattern is wrong.

**Fix for dashboard:** Keep current behavior (no pagination — dashboard needs all servers for summary counts). Add a comment noting this is intentional for the dashboard view.

**Fix for server list:** Add pagination (20 per page).

- [ ] **Step 1: Add pagination to ServersController**

In `app/Controllers/Admin/ServersController.php`, after `require_login()`:

```php
$page = max(1, (int) ($request->query('page') ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
```

Replace line 139's `$rows = db_all(...)` with:

```php
$totalResult = db_one('SELECT COUNT(*) AS total FROM servers');
$totalServers = (int) ($totalResult['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalServers / $perPage));

$rows = db_all(
    'SELECT s.id, s.name, s.location, s.provider, s.label, s.host, s.type, s.agent_mode, s.active, s.maintenance_mode, s.maintenance_until,
            COALESCE(s.last_seen_at, m.recorded_at) AS last_seen, m.cpu_load, m.panel_profile,
            COALESCE(ss.up_count, 0) AS service_up_count,
            COALESCE(ss.down_count, 0) AS service_down_count,
            COALESCE(ss.unknown_count, 0) AS service_unknown_count
     FROM servers s' . latest_metric_join_sql('s', 'm') . '
     LEFT JOIN (
         SELECT server_id, SUM(last_status = "up") AS up_count, SUM(last_status = "down") AS down_count, SUM(last_status = "unknown") AS unknown_count
         FROM server_service_states
         GROUP BY server_id
     ) ss ON ss.server_id = s.id
     ORDER BY s.created_at DESC
     LIMIT :limit OFFSET :offset',
    [':limit' => $perPage, ':offset' => $offset]
);
```

Add pagination vars to template data:
```php
$data = [
    'rows' => $rows,
    'canManageServers' => $canManageServers,
    'statusOnlineMinutes' => $statusOnlineMinutes,
    'title' => APP_NAME . ' - Server Management',
    'activeNav' => 'servers',
    'currentPage' => $page,
    'totalPages' => $totalPages,
];
```

- [ ] **Step 2: Add pagination controls to the view**

Read and edit `app/Views/admin/servers.php` to add pagination links at the bottom:

```php
<?php if (($totalPages ?? 1) > 1): ?>
<nav class="mt-3" aria-label="Server pagination">
    <ul class="pagination pagination-sm justify-content-center">
        <?php for ($i = 1; $i <= ($totalPages ?? 1); $i++): ?>
            <li class="page-item <?= ($currentPage ?? 1) === $i ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>
```

- [ ] **Step 3: Add comment to DashboardController**

In `app/Controllers/Admin/DashboardController.php`, add a comment above the query:

```php
// No pagination — dashboard shows all servers for summary + health overview
```

- [ ] **Step 4: Verify syntax**

```bash
php -l app/Controllers/Admin/DashboardController.php
php -l app/Controllers/Admin/ServersController.php
php -l app/Views/admin/servers.php
```

- [ ] **Step 5: Commit**

```bash
git add app/Controllers/Admin/DashboardController.php app/Controllers/Admin/ServersController.php app/Views/admin/servers.php
git commit -m "feat(perf): add pagination to server management list (20 per page)"
```

---

### Task 6: Cache `db_column_exists()` results

**Files:**
- Check: `app/Repositories/Database.php`

**Verification:** `Database::$columnCache` already exists (line 16) and `columnExists()` already uses it (line 91). This is already correct.

- [ ] **Step 1: Verify caching is already implemented**

Check `app/Repositories/Database.php` lines 88-108. The `$columnCache` static array is already populated on first call and reused. ✅

- [ ] **Step 2: No changes — mark as done**

```bash
git commit --allow-empty -m "chore(audit): verify db_column_exists caching already in Database::columnExists()"
```

---

### Task 7: Set `config/local.php` permission to 0640

**Files:**
- Modify: `public/install.php` (the `writeLocalConfig` function)

**Problem:** `writeLocalConfig()` uses 0644 by default (world-readable), with 0640 fallback. Change default to 0640.

- [ ] **Step 1: Change default permission from 0644 to 0640**

In `public/install.php`, function `writeLocalConfig()` (line 400-416):

Change:
```php
if (!@chmod($localPath, 0644)) {
    @chmod($localPath, 0640);
}
```
To:
```php
@chmod($localPath, 0640);
```

Also update the fallback error message from:
```php
throw new RuntimeException('config/local.php written but not readable (permission denied). Run: chmod 644 ' . $localPath);
```
To:
```php
throw new RuntimeException('config/local.php written but not readable (permission denied). Run: chmod 640 ' . $localPath . ' and verify web server user is in the group that owns the file.');
```

And update the comment (lines 408-409) from:
```php
// Use 0644 for compatibility with AaPanel/BT where web (www) and CLI (root) run as different users.
// 0600 would cause "Permission denied" when CLI tries to read file created by web and vice versa.
```
To:
```php
// Use 0640 so web server user and group can read, others cannot.
// On AaPanel/BT, ensure web user (www) is in the group owning config/local.php.
```

- [ ] **Step 2: Verify syntax**

```bash
php -l public/install.php
```

- [ ] **Step 3: Commit**

```bash
git add public/install.php
git commit -m "fix(security): change config/local.php default permission to 0640"
```

---

### Task 8: Exponential backoff for login rate limiting

**Files:**
- Modify: `app/Services/AuthService.php`

**Problem:** Current rate limiting is a fixed window (5 attempts in 5 minutes). Add exponential backoff: after 5 failures, lockout 1 minute, doubling each subsequent attempt.

- [ ] **Step 1: Add lockout calculation to `isIpRateLimited()`**

Replace the current `isIpRateLimited()` (lines 148-165):

```php
public static function isIpRateLimited(string $ip): bool
{
    $windowMinutes = LOGIN_WINDOW_MINUTES;
    $cutoff = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

    Database::exec(
        'DELETE FROM login_attempts WHERE attempted_at <= :cutoff',
        [':cutoff' => $cutoff]
    );

    $attempt = Database::one(
        'SELECT COUNT(*) AS total, MIN(attempted_at) AS first_attempt
         FROM login_attempts
         WHERE ip_address = :ip
         AND attempted_at > :cutoff',
        [':ip' => $ip, ':cutoff' => $cutoff]
    );

    $total = (int) ($attempt['total'] ?? 0);
    if ($total < LOGIN_MAX_ATTEMPTS) {
        return false;
    }

    // Exponential backoff: after max attempts, lockout doubles per extra attempt
    $extraAttempts = $total - LOGIN_MAX_ATTEMPTS;
    $lockoutMinutes = min(60, (1 << $extraAttempts)); // 1, 2, 4, 8, 16, 32, max 60
    $firstAttempt = (string) ($attempt['first_attempt'] ?? '');
    $firstTs = strtotime($firstAttempt);
    if ($firstTs === false) {
        return true;
    }
    $lockoutEnd = $firstTs + ($windowMinutes * 60) + ($lockoutMinutes * 60);
    return time() < $lockoutEnd;
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l app/Services/AuthService.php
```

- [ ] **Step 3: Run existing tests**

```bash
php tests/helpers_test.php
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/AuthService.php
git commit -m "fix(security): add exponential backoff to login rate limiting"
```

---

### Task 9: Refactor AlertService — DRY threshold evaluation

**Files:**
- Modify: `app/Services/AlertService.php`

**Problem:** `evaluateServerThresholdAlerts()` has 4 near-identical blocks for mail queue, CPU, RAM, disk. Extract into a reusable helper.

- [ ] **Step 1: Add private helper method**

Add to `AlertService`:

```php
private static function evaluateThreshold(
    int $serverId,
    string $serverName,
    string $resourceLabel,
    string $warnAlertType,
    string $criticalAlertType,
    float $currentValue,
    float $warnThreshold,
    float $criticalThreshold
): void {
    if ($currentValue > $criticalThreshold) {
        create_alert(
            $serverId,
            $criticalAlertType,
            'danger',
            '[' . $serverName . '] ' . $resourceLabel . ' Critical',
            $resourceLabel . ' critical: ' . $currentValue . ' (threshold: ' . $criticalThreshold . ')',
            ['value' => $currentValue, 'threshold' => $criticalThreshold]
        );
    } elseif ($currentValue > $warnThreshold) {
        create_alert(
            $serverId,
            $warnAlertType,
            'warning',
            '[' . $serverName . '] ' . $resourceLabel . ' Warning',
            $resourceLabel . ' warning: ' . $currentValue . ' (threshold: ' . $warnThreshold . ')',
            ['value' => $currentValue, 'threshold' => $warnThreshold]
        );
    } else {
        resolveConditionAlerts($serverId, [$warnAlertType, $criticalAlertType]);
    }
}
```

- [ ] **Step 2: Replace the 4 blocks in `evaluateServerThresholdAlerts()`**

Replace the 4 inline blocks (mail queue, CPU, RAM, disk) with calls to `self::evaluateThreshold()`.

Original mail queue block:
```php
$mqCurrent = (int) ($metric['mail_queue_total'] ?? 0);
$mqWarn = max(0, (int) setting_get('threshold_mail_queue'));
$mqCritical = max($mqWarn, (int) setting_get('threshold_mail_queue_critical'));
if ($mqCurrent > $mqCritical) {
    // ... create critical alert
} elseif ($mqCurrent > $mqWarn) {
    // ... create warning alert
} else {
    resolveConditionAlerts($serverId, ['mail_queue', 'mail_queue_critical']);
}
```

Replace with:
```php
$mqCurrent = (int) ($metric['mail_queue_total'] ?? 0);
$mqWarn = max(0, (int) setting_get('threshold_mail_queue'));
$mqCritical = max($mqWarn, (int) setting_get('threshold_mail_queue_critical'));
self::evaluateThreshold(
    $serverId, $serverName, 'Mail Queue',
    'mail_queue', 'mail_queue_critical',
    (float) $mqCurrent, (float) $mqWarn, (float) $mqCritical
);
```

Do the same pattern for CPU, RAM, and disk blocks.

- [ ] **Step 3: Verify syntax**

```bash
php -l app/Services/AlertService.php
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/AlertService.php
git commit -m "refactor(alert): DRY threshold evaluation in evaluateServerThresholdAlerts"
```

---

### Task 10: Refactor SettingsController — extract action handlers

**Files:**
- Modify: `app/Controllers/Admin/SettingsController.php`

**Problem:** `index()` is 327 lines with inline if/elseif chains for each action. Extract each action to a private method.

- [ ] **Step 1: Read current controller fully**

Read the full file to understand all actions.

- [ ] **Step 2: Extract action methods**

Add private methods:

```php
private static function handleSaveSettings(Request $request, string $section): void
{
    // Copy existing save logic from index() for this section
}

private static function handleTestEmail(Request $request): void
{
    // Copy test email logic
}

private static function handleTestTelegram(Request $request): void
{
    // Copy test telegram logic
}

private static function handleRetention(Request $request, string $type): void
{
    // Copy retention run logic
}

private static function handleUserCreate(Request $request): void
{
    // Copy user creation logic
}

private static function handleUserUpdate(Request $request): void
{
    // Copy user update logic
}

private static function handleUserDelete(Request $request): void
{
    // Copy user delete logic
}
```

- [ ] **Step 3: Replace the if/elseif chain with method calls**

In `index()`, replace the action switch:
```php
$action = (string) ($request->input('action') ?? '');
$section = inferSectionFromPost($request);

if ($action === 'test_email') {
    self::handleTestEmail($request);
} elseif ($action === 'test_telegram') {
    self::handleTestTelegram($request);
} elseif ($action === 'run_retention') {
    self::handleRetention($request, 'core');
} elseif ($action === 'run_disk_retention') {
    self::handleRetention($request, 'disk');
} elseif ($action === 'user_create') {
    self::handleUserCreate($request);
} elseif ($action === 'user_update') {
    self::handleUserUpdate($request);
} elseif ($action === 'user_delete') {
    self::handleUserDelete($request);
} else {
    self::handleSaveSettings($request, $section);
}
```

- [ ] **Step 4: Verify syntax**

```bash
php -l app/Controllers/Admin/SettingsController.php
```

- [ ] **Step 5: Commit**

```bash
git add app/Controllers/Admin/SettingsController.php
git commit -m "refactor(settings): extract action handlers from index() god method"
```

---

### Task 11: Add foreign keys to database schema

**Files:**
- Modify: `database/migrations/` — create a new migration file

**Problem:** Tables like `metrics`, `alert_logs`, `service_metrics` have no FK constraints. Orphan data possible.

- [ ] **Step 1: Create migration file**

Create `database/migrations/20260911_add_foreign_keys.sql`:

```sql
-- Add foreign keys for data integrity
-- Safely skips if columns or referenced tables don't exist

SET @db = (SELECT DATABASE());

-- metrics -> servers
SET @exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'metrics' AND column_name = 'server_id');
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = @db AND table_name = 'metrics' AND constraint_type = 'FOREIGN KEY');
SET @sql = 'SELECT 1';
IF @exists > 0 AND @fk_exists = 0 THEN
    SET @sql = 'ALTER TABLE metrics ADD CONSTRAINT fk_metrics_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE';
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END IF;

-- alert_logs -> servers
SET @exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'alert_logs' AND column_name = 'server_id');
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = @db AND table_name = 'alert_logs' AND constraint_type = 'FOREIGN KEY');
IF @exists > 0 AND @fk_exists = 0 THEN
    SET @sql = 'ALTER TABLE alert_logs ADD CONSTRAINT fk_alert_logs_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE';
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END IF;

-- service_metrics -> servers
SET @exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'service_metrics' AND column_name = 'server_id');
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = @db AND table_name = 'service_metrics' AND constraint_type = 'FOREIGN KEY');
IF @exists > 0 AND @fk_exists = 0 THEN
    SET @sql = 'ALTER TABLE service_metrics ADD CONSTRAINT fk_service_metrics_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE';
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END IF;

-- server_service_states -> servers
SET @exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'server_service_states' AND column_name = 'server_id');
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = @db AND table_name = 'server_service_states' AND constraint_type = 'FOREIGN KEY');
IF @exists > 0 AND @fk_exists = 0 THEN
    SET @sql = 'ALTER TABLE server_service_states ADD CONSTRAINT fk_server_service_states_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE';
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END IF;

-- ping_checks -> ping_monitors
SET @exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'ping_checks' AND column_name = 'monitor_id');
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = @db AND table_name = 'ping_checks' AND constraint_type = 'FOREIGN KEY');
IF @exists > 0 AND @fk_exists = 0 THEN
    SET @sql = 'ALTER TABLE ping_checks ADD CONSTRAINT fk_ping_checks_monitor FOREIGN KEY (monitor_id) REFERENCES ping_monitors(id) ON DELETE CASCADE';
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END IF;

-- ping_monitor_states -> ping_monitors
SET @exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'ping_monitor_states' AND column_name = 'monitor_id');
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = @db AND table_name = 'ping_monitor_states' AND constraint_type = 'FOREIGN KEY');
IF @exists > 0 AND @fk_exists = 0 THEN
    SET @sql = 'ALTER TABLE ping_monitor_states ADD CONSTRAINT fk_ping_monitor_states_monitor FOREIGN KEY (monitor_id) REFERENCES ping_monitors(id) ON DELETE CASCADE';
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END IF;

-- disk_health_metrics -> servers
SET @exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'disk_health_metrics' AND column_name = 'server_id');
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = @db AND table_name = 'disk_health_metrics' AND constraint_type = 'FOREIGN KEY');
IF @exists > 0 AND @fk_exists = 0 THEN
    SET @sql = 'ALTER TABLE disk_health_metrics ADD CONSTRAINT fk_disk_health_metrics_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE';
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END IF;
```

- [ ] **Step 2: Verify with schema consistency test**

```bash
php tests/schema_consistency_test.php
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/20260911_add_foreign_keys.sql
git commit -m "feat(db): add foreign key constraints for referential integrity"
```

---

### Task 12: Run architecture test to verify no regressions

- [ ] **Step 1: Run architecture test**

```bash
php tests/architecture_test.php
```

Expected: All assertions pass (the changes should not break any architectural invariant).

- [ ] **Step 2: Run helpers test**

```bash
php tests/helpers_test.php
```

- [ ] **Step 3: Run schema consistency test**

```bash
php tests/schema_consistency_test.php
```
