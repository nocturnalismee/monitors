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
assert_architecture(str_contains($alerts, 'db_exec_count('), 'resolveConditionAlerts must use db_exec_count (db_exec returns bool, which would fake a resolved incident and emit phantom recoveries)');

$cache = source_text('app/Support/Cache.php');
assert_architecture(str_contains($cache, 'bumpVersion(\'status:sver:'), 'status invalidation must bump per-server version counters');
assert_architecture(!preg_match('/deletePattern\([\'"]status:/', $cache), 'status invalidation must not SCAN keyspace (exact deletes + version bump only)');

$liveStream = source_text('app/Controllers/Api/LiveStreamController.php');
assert_architecture(str_contains($liveStream, '$sentMaxByServer'), 'SSE ring path must keep a per-server cursor');
assert_architecture(!str_contains($liveStream, "lRange(cache_key('ring:' . \$sid), 0, -1)"), 'SSE ring path must not rescan the full ring every tick');

$maintenance = source_text('app/Services/MaintenanceService.php');
assert_architecture(str_contains($maintenance, 'private static array $memo'), 'maintenance check must memoize per process (hot path: push + per-row worker loops)');
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
assert_architecture(str_contains($bootstrap, 'MONITORS_CSP_NONCE'), 'csp nonce must be generated per request');
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

// Security / HTTP contract (packet A).
$authMiddleware = source_text('app/Http/Middleware/Auth.php');
assert_architecture(str_contains($authMiddleware, 'sessionExpiryResponse'), 'auth middleware must enforce session expiry, not only is_logged_in()');
assert_architecture(!str_contains($authMiddleware, 'Response::redirect(app_url(\'login\'), 302)'), 'auth middleware must redirect with 303, not 302');
$authService = source_text('app/Services/AuthService.php');
assert_architecture(str_contains($authService, 'dummyHash'), 'login must equalise timing for unknown usernames (dummy password_verify)');
assert_architecture(str_contains($authService, 'isUsernameRateLimited'), 'login must throttle per username, not only per IP');
$loginController = source_text('app/Controllers/Auth/LoginController.php');
assert_architecture(str_contains($loginController, 'attempt_login('), 'login must use the constant-time credential check');
assert_architecture(!str_contains($loginController, 'password_verify('), 'login controller must not call password_verify directly');

$router = source_text('app/Http/Router.php');
assert_architecture(str_contains($router, 'Method Not Allowed'), 'router must answer 405 for known paths with the wrong verb');
assert_architecture(str_contains($router, "'Allow'"), '405 responses must carry an Allow header');
assert_architecture(str_contains($router, "=== 'HEAD'"), 'router must serve HEAD through the GET handler');

$requestLayer = source_text('app/Http/Request.php');
assert_architecture(str_contains($requestLayer, 'php://input'), 'request must parse JSON bodies');
assert_architecture(str_contains($requestLayer, 'rawBody'), 'request must expose the raw body for signature verification');

// Assets are self-hosted: no third-party CDN may be referenced at runtime.
$runtimeSources = [
    'app/Views/layouts/head.php',
    'app/Views/layouts/footer.php',
    'public/install.php',
    'config/bootstrap.php',
];
foreach ($runtimeSources as $sourcePath) {
    assert_architecture(
        !str_contains(source_text($sourcePath), 'cdn.jsdelivr.net'),
        $sourcePath . ' must not load assets from a third-party CDN'
    );
}
assert_architecture(
    str_contains(source_text('app/Controllers/Admin/PingTerminalController.php'), 'x-csrf-token'),
    'ping terminal must accept the CSRF token from a header, not the query string'
);
assert_architecture(
    !str_contains(source_text('public/assets/js/ping-terminal.js'), "params.set('token'"),
    'ping terminal client must not put the CSRF token in the query string'
);
assert_architecture(
    str_contains(source_text('app/Views/layouts/head.php'), 'assets/vendor/bootstrap/bootstrap.min.css'),
    'head layout must load the vendored Bootstrap stylesheet'
);

// Dashboard hero chart (fleet time-series) and its UI affordances.
$statusController = source_text('app/Controllers/Api/StatusController.php');
assert_architecture(
    str_contains($statusController, 'fleet_history'),
    'status API must expose the aggregate fleet_history series'
);
assert_architecture(
    str_contains($statusController, 'function fleetHistoryQuery'),
    'fleet history must be built by a dedicated query builder'
);
assert_architecture(
    str_contains($statusController, "'last_seen_ts'"),
    'status list must expose an epoch last_seen_ts for time-zone safe freshness labels'
);
$dashboardView = source_text('app/Views/admin/dashboard.php');
assert_architecture(str_contains($dashboardView, 'data-fleet-chart'), 'dashboard must render the fleet chart card');
assert_architecture(
    str_contains($dashboardView, 'data-fleet-toggle'),
    'the fleet chart must expose a compact 48px summary toggle row'
);
assert_architecture(
    str_contains($dashboardView, 'fleet-chart-card is-collapsed'),
    'the fleet chart must start collapsed so the server table stays above the fold'
);
assert_architecture(
    str_contains($dashboardView, 'data-fleet-value="cpu"'),
    'the collapsed row must still show live CPU/RAM/disk numbers'
);
$summaryMarker = strpos($dashboardView, 'Monitoring Summary');
$fleetMarker = strpos($dashboardView, 'data-fleet-chart');
assert_architecture(
    $summaryMarker !== false && $fleetMarker !== false && $summaryMarker < $fleetMarker,
    'the fleet chart must sit below the Monitoring Summary table'
);
assert_architecture(
    str_contains($dashboardView, 'assets/js/fleet-chart.js'),
    'dashboard must load the fleet chart module'
);
assert_architecture(
    str_contains($dashboardView, 'data-echarts-src'),
    'the chart engine URL must be a lazy-load hint, not a page script tag'
);
assert_architecture(
    !str_contains(source_text('app/Controllers/Admin/DashboardController.php'), "'loadEcharts' => true"),
    'the dashboard must not block first paint on the chart engine'
);
$fleetJs = source_text('public/assets/js/fleet-chart.js');
assert_architecture(str_contains($fleetJs, 'IntersectionObserver'), 'the chart engine must load only when the card is visible');
assert_architecture(str_contains($fleetJs, 'fleet_history='), 'the chart must call the aggregate fleet endpoint');
assert_architecture(str_contains($fleetJs, 'monitors:theme-changed'), 'the chart must re-render on theme change');
assert_architecture(
    str_contains($fleetJs, 'monitors_fleet_chart_expanded'),
    'the fleet chart must remember the expanded preference'
);
assert_architecture(
    str_contains($fleetJs, 'chartStarted'),
    'the chart engine must load only on the first expand, not with the page'
);
$dashboardJs = source_text('public/assets/js/dashboard.js');
assert_architecture(str_contains($dashboardJs, 'updateRowFreshness'), 'dashboard rows must show a freshness label');
assert_architecture(str_contains($dashboardJs, 'monitors_table_density'), 'dashboard must persist the table density choice');
$sidebarJs = source_text('public/assets/js/sidebar.js');
assert_architecture(str_contains($sidebarJs, 'trapFocus'), 'mobile sidebar must trap keyboard focus');
assert_architecture(str_contains($sidebarJs, 'inert'), 'mobile sidebar must mark the page behind it inert');

// Push path and alert worker hot paths must not pay per-request metadata costs.
$pushController = source_text('app/Controllers/Api/PushController.php');
assert_architecture(
    !str_contains($pushController, "db_column_exists('metrics'"),
    'push ingest must not probe information_schema for canonical metric columns'
);
assert_architecture(
    !str_contains($pushController, 'json_response('),
    'push ingest must return a Response instead of exiting via json_response()'
);
$alertService = source_text('app/Services/AlertService.php');
assert_architecture(
    str_contains($alertService, 'openAlertIndex'),
    'alert resolution must skip no-op UPDATEs using the open-incident index'
);
assert_architecture(
    str_contains($alertService, 'markAlertClosed'),
    'alert resolution must keep the open-incident index in sync'
);

// PHP's built-in dev server is single-threaded: a held SSE stream starves
// every other request (assets, fonts, polling), which looked like missing
// icons in the dashboard.
assert_architecture(
    str_contains(source_text('app/Support/Globals/services.php'), 'function realtime_stream_enabled'),
    'realtime stream gating must be centralised in realtime_stream_enabled()'
);
assert_architecture(
    str_contains(source_text('app/Support/Globals/services.php'), "PHP_SAPI === 'cli-server'"),
    'the SSE stream must be disabled under PHP\'s single-threaded built-in server'
);
assert_architecture(
    str_contains(source_text('app/Views/admin/dashboard.php'), 'realtime_stream_enabled()'),
    'the dashboard must honour the realtime stream gate'
);
assert_architecture(
    str_contains(source_text('app/Views/layouts/footer.php'), '$alertStreamEndpoint'),
    'the alert toast stream must honour the realtime stream gate'
);
assert_architecture(
    str_contains(source_text('public/assets/js/dashboard.js'), '!window.MONITORS_API_STREAM'),
    'dashboard.js must fall back to polling when no stream endpoint is configured'
);

echo "architecture_test passed\n";
