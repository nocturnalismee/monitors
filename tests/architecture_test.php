<?php
declare(strict_types=1);

function assert_architecture(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function source_text(string $relativePath): string
{
    $path = __DIR__ . '/../' . $relativePath;
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Failed to read ' . $relativePath);
    }
    return $content;
}

$push = source_text('app/Controllers/Api/PushController.php');
assert_architecture(str_contains($push, 'beginTransaction()'), 'push ingest must start a transaction');
assert_architecture(str_contains($push, '->commit()'), 'push ingest must commit a transaction');
assert_architecture(str_contains($push, 'persistServiceStates'), 'push must batch service writes outside the metric transaction');
assert_architecture(!str_contains($push, 'Failed to persist service metrics'), 'service write failure must be best-effort, never fail an already-committed ingest');

$alerts = source_text('app/Services/AlertService.php');
assert_architecture(str_contains($alerts, 'alert_delivery_queue'), 'alerts must enqueue delivery work');
assert_architecture(is_file(__DIR__ . '/../app/Console/Workers/AlertDeliveryWorker.php'), 'alert delivery worker must exist');

$schema = source_text('database/schema.sql');
assert_architecture(str_contains($schema, 'token_hash CHAR(64)'), 'schema must include hashed server tokens');
assert_architecture(str_contains($schema, 'alert_delivery_queue'), 'schema must include alert delivery queue');
assert_architecture(str_contains($schema, 'export_jobs'), 'schema must include asynchronous export jobs');
assert_architecture(is_file(__DIR__ . '/../app/Console/Workers/ExportWorker.php'), 'export worker must exist');
assert_architecture(str_contains(source_text('app/Controllers/Api/EventsController.php'), 'text/event-stream'), 'alert SSE endpoint must exist');
assert_architecture(str_contains(source_text('app/Controllers/Admin/ExportDownloadController.php'), 'realpath(ExportService::export_job_dir())'), 'export downloads must be constrained to the export directory');
assert_architecture(!str_contains(source_text('app/Services/WorkerService.php'), '@unlink($lockFile)'), 'worker locks must not unlink potentially active flock files');

$serverDetailView = source_text('app/Views/admin/server_detail.php');
assert_architecture(!str_contains($serverDetailView, 'e($historyEndpoint)'), 'history API URLs embedded in JavaScript must not be HTML-escaped');
assert_architecture(str_contains($serverDetailView, 'const initialHistoryEndpoint = <?= json_encode($historyEndpoint'), 'history API URLs embedded in JavaScript must use JSON encoding');

$pushDisk = source_text('app/Controllers/Api/PushDiskController.php');
assert_architecture(str_contains($pushDisk, 'PushAuthService::authenticate'), 'disk push must share the central ingest auth preamble');
$pushAuth = source_text('app/Services/PushAuthService.php');
assert_architecture(str_contains($pushAuth, 'token_hash'), 'shared ingest auth must support hashed server tokens');
assert_architecture(str_contains($pushAuth, 'ipInAllowlist'), 'shared ingest auth must enforce the IP allowlist');

$serverAdd = source_text('app/Controllers/Admin/ServerAddController.php');
assert_architecture(!str_contains($serverAdd, "':token'"), 'server creation must not reference a plaintext token parameter');
assert_architecture(str_contains($serverAdd, 'token_hash'), 'server creation must store hashed server tokens');
assert_architecture(!str_contains($push, 'OR token'), 'push lookup must use token_hash only, no plaintext fallback');

$pushApi = source_text('app/Controllers/Api/PushController.php');
assert_architecture(str_contains($pushApi, "'cpanel' => ['apache', 'nginx'"), 'cpanel push profile must allow nginx service detection');

$bootstrap = source_text('config/bootstrap.php');
assert_architecture(str_contains($bootstrap, 'SERVMON_CSP_NONCE'), 'csp nonce must be generated per request');
assert_architecture(str_contains($bootstrap, "'nonce-"), 'csp header must carry the nonce for inline scripts');

$alertLogsAdmin = source_text('app/Controllers/Admin/AlertLogsController.php');
assert_architecture(str_contains($alertLogsAdmin, "require_role('admin')"), 'alert log mutations must be admin-only (viewers read-only)');
$alertsApi = source_text('app/Controllers/Api/AlertsApiController.php');
assert_architecture(str_contains($alertsApi, "require_role('admin')"), 'alert api mutations must be admin-only (viewers read-only)');
$alertLogsView = source_text('app/Views/admin/alert_logs.php');
assert_architecture(str_contains($alertLogsView, "has_role('admin')"), 'alert log mutation buttons must be hidden from viewers');

$securityGlobals = source_text('app/Support/Globals/security.php');
assert_architecture(str_contains($securityGlobals, 'function installer_still_present'), 'installer presence helper must exist');
assert_architecture(str_contains($securityGlobals, 'function local_config_perms'), 'local config permission helper must exist');

echo "architecture_test passed\n";
