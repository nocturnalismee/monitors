<?php
declare(strict_types=1);

$workerChecks = [
    [
        'name' => 'alert_check',
        'health' => (string) ($alertWorkerHealth['health'] ?? 'unknown'),
        'last_success' => (string) ($alertWorkerHealth['last_success_at'] ?? 'never'),
        'cron' => $alertCronCmd,
    ],
    [
        'name' => 'retention_cleanup',
        'health' => (string) ($retentionWorkerHealth['health'] ?? 'unknown'),
        'last_success' => (string) ($retentionWorkerHealth['last_success_at'] ?? 'never'),
        'cron' => $retentionCronCmd,
    ],
    [
        'name' => 'disk_history_rollup',
        'health' => (string) ($diskRollupWorkerHealth['health'] ?? 'unknown'),
        'last_success' => (string) ($diskRollupWorkerHealth['last_success_at'] ?? 'never'),
        'cron' => $diskRollupCronCmd,
    ],
    [
        'name' => 'ping_check',
        'health' => (string) ($pingWorkerHealth['health'] ?? 'unknown'),
        'last_success' => (string) ($pingWorkerHealth['last_success_at'] ?? 'never'),
        'cron' => $pingCronCmd,
    ],
    [
        'name' => 'ip_reputation_check',
        'health' => (string) ($ipRepWorkerHealth['health'] ?? 'unknown'),
        'last_success' => (string) ($ipRepWorkerHealth['last_success_at'] ?? 'never'),
        'cron' => $ipRepCronCmd,
    ],
    [
        'name' => 'rollup_metrics',
        'health' => (string) ($rollupWorkerHealth['health'] ?? 'unknown'),
        'last_success' => (string) ($rollupWorkerHealth['last_success_at'] ?? 'never'),
        'cron' => $rollupCronCmd,
    ],
    [
        'name' => 'disk_retention_cleanup',
        'health' => (string) ($diskCleanupWorkerHealth['health'] ?? 'unknown'),
        'last_success' => (string) ($diskCleanupWorkerHealth['last_success_at'] ?? 'never'),
        'cron' => $diskCleanupCronCmd,
    ],
    [
        'name' => 'partition_maintain',
        'health' => (string) ($partitionMaintainWorkerHealth['health'] ?? 'unknown'),
        'last_success' => (string) ($partitionMaintainWorkerHealth['last_success_at'] ?? 'never'),
        'cron' => $partitionMaintainCronCmd,
    ],
];

$unhealthyWorkers = array_values(array_filter($workerChecks, static function (array $w): bool {
    return $w['health'] !== 'ok';
}));
$hasWorkerError = false;
foreach ($unhealthyWorkers as $uw) {
    if ($uw['health'] === 'error') {
        $hasWorkerError = true;
        break;
    }
}
?>
<main id="main-content" class="container py-4 admin-page admin-shell">
    <?php if (!empty($unhealthyWorkers)): ?>
        <div class="worker-health-banner <?= $hasWorkerError ? 'has-error' : '' ?>">
            <div class="worker-health-header">
                <div class="worker-health-title">
                    <i class="ti <?= $hasWorkerError ? 'ti-alert-octagon text-danger' : 'ti-alert-triangle text-warning' ?> fs-5" aria-hidden="true"></i>
                    <span><strong><?= count($unhealthyWorkers) ?> background worker<?= count($unhealthyWorkers) > 1 ? 's' : '' ?></strong> need attention</span>
                    <span class="badge <?= $hasWorkerError ? 'bg-danger' : 'bg-warning text-dark' ?> rounded-pill ms-1">
                        <?= $hasWorkerError ? 'Action Required' : 'Notice' ?>
                    </span>
                </div>
                <button type="button" class="btn btn-sm btn-soft worker-health-toggle" data-bs-toggle="collapse" data-bs-target="#workerHealthCollapse" aria-expanded="false" aria-controls="workerHealthCollapse">
                    <i class="ti ti-chevron-down me-1"></i>View Details
                </button>
            </div>
            <div class="collapse worker-health-details" id="workerHealthCollapse">
                <div class="worker-health-grid">
                    <?php foreach ($unhealthyWorkers as $uw): ?>
                        <div class="worker-health-item">
                            <div class="worker-health-item-head">
                                <strong><code><?= e($uw['name']) ?></code></strong>
                                <span class="badge <?= $uw['health'] === 'error' ? 'badge-down' : 'badge-pending' ?> text-uppercase"><?= e($uw['health']) ?></span>
                            </div>
                            <div class="text-secondary small">Last success: <?= e($uw['last_success']) ?></div>
                            <div class="worker-health-cmd">Cron: <code><?= e($uw['cron']) ?></code></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php if (has_role('admin') && ($installerPresent ?? false) && !($installerLocked ?? false)): ?>
        <div class="worker-health-banner has-error" role="alert">
            <div class="worker-health-header">
                <div class="worker-health-title">
                    <i class="ti ti-shield-lock fs-5 text-danger" aria-hidden="true"></i>
                    <span><strong>Installer still accessible</strong> — remove <code>public/install.php</code> or create <code>config/.installer-locked</code> to disable it</span>
                    <span class="badge bg-danger rounded-pill ms-1">Action Required</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Dashboard Monitoring</h1>
            <p class="page-subtitle">Summary of server health, real-time telemetry, and incident alerts.</p>
        </div>
        <div class="toolbar-actions">
            <div class="dashboard-search-wrap">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input class="form-control" type="search" placeholder="Search servers..." aria-label="Search servers" data-dashboard-search autocomplete="off">
                <button type="button" class="dashboard-search-clear" data-dashboard-search-clear aria-label="Clear search" title="Clear search"><i class="ti ti-x"></i></button>
            </div>
            <label class="visually-hidden" for="dashboardStatusFilter">Filter server status</label>
            <select class="form-select form-select-sm w-auto" id="dashboardStatusFilter" data-dashboard-filter>
                <option value="all">All statuses</option>
                <option value="online">Online</option>
                <option value="down">Down</option>
                <option value="pending">Pending</option>
            </select>
            <div class="notification-center" data-notification-center>
                <button type="button" class="btn btn-outline-warning notification-toggle" data-notification-toggle aria-expanded="false" aria-controls="notificationPanel" title="Open notifications">
                    <i class="ti ti-bell" aria-hidden="true"></i>
                    <span class="visually-hidden">Notifications</span>
                    <span class="notification-count d-none" data-notification-count aria-live="polite">0</span>
                </button>
                <div class="notification-panel d-none" id="notificationPanel" data-notification-panel role="dialog" aria-modal="true" aria-labelledby="notificationPanelTitle" tabindex="-1">
                    <div class="notification-panel-head">
                        <div>
                            <strong id="notificationPanelTitle">Notifications</strong>
                            <span class="small text-secondary" data-notification-summary>Loading…</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-link" data-notification-read-all>Mark all as read</button>
                    </div>
                    <div class="notification-list" data-notification-list></div>
                    <div class="notification-panel-foot">
                        <a href="<?= e(app_url('alerts')) ?>" class="btn btn-sm btn-outline-info w-100">View all alerts</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-3 summary-grid" data-ui-section>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-total is-clickable is-active-filter p-3" data-summary-filter="all" role="button" tabindex="0" aria-label="Show all servers">
                <div class="summary-card-head">
                    <span class="summary-card-label">Total Servers</span>
                    <i class="ti ti-server-2 summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-admin-total><?= e((string) ($summary['total_servers'] ?? 0)) ?></div>
                <div class="summary-card-subtitle">Monitored inventory</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-online is-clickable p-3" data-summary-filter="online" role="button" tabindex="0" aria-label="Filter online servers">
                <div class="summary-card-head">
                    <span class="summary-card-label">Online</span>
                    <i class="ti ti-arrow-up-circle summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-admin-online><?= e((string) $online) ?></div>
                <div class="summary-card-subtitle">Responding now</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-down is-clickable p-3" data-summary-filter="down" role="button" tabindex="0" aria-label="Filter down servers">
                <div class="summary-card-head">
                    <span class="summary-card-label">Down</span>
                    <i class="ti ti-alert-triangle summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-admin-down><?= e((string) $down) ?></div>
                <div class="summary-card-subtitle">Needs action</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-pending is-clickable p-3" data-summary-filter="pending" role="button" tabindex="0" aria-label="Filter pending servers">
                <div class="summary-card-head">
                    <span class="summary-card-label">Pending</span>
                    <i class="ti ti-history summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-admin-pending><?= e((string) $pending) ?></div>
                <div class="summary-card-subtitle">No recent update</div>
            </div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Monitoring Summary</h2>
            <div class="dashboard-summary-actions">
                <span class="dashboard-live-status" data-dashboard-stale aria-live="polite">
                    <i class="ti ti-circle-filled" aria-hidden="true"></i>
                    <span data-dashboard-live-label>Live</span><span class="dashboard-live-separator">|</span><time>Loading…</time>
                </span>
            </div>
        </div>
        <div class="table-responsive table-shell" data-ui-table>
            <div class="table-skeleton-overlay" aria-hidden="true"></div>
            <table class="table monitors-table dashboard-summary-table mb-0">
                <colgroup>
                    <col style="width: 9rem;">
                    <col style="width: 8.5rem;">
                    <col style="width: 6rem;">
                    <col style="width: 6rem;">
                    <col style="width: 19rem;">
                    <col style="width: 19rem;">
                    <col class="dashboard-col-panel" style="width: 6rem;">
                    <col style="width: 7.5rem;">
                    <col style="width: 10.5rem;">
                    <col style="width: 5.5rem;">
                    <col style="width: 7rem;">
                </colgroup>
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Location</th>
                    <th scope="col">Uptime</th>
                    <th scope="col" data-sort-key="cpu" aria-sort="none">
                        <button type="button" class="sort-btn" data-sort-trigger="cpu">CPU<i class="ti ti-arrows-sort sort-icon" aria-hidden="true"></i></button>
                        <span class="sparkline-legend" title="Sparkline color: teal normal, amber high, red critical" aria-hidden="true"><i style="--legend-color: var(--sv-accent)"></i><i style="--legend-color: var(--sv-warning)"></i><i style="--legend-color: var(--sv-danger)"></i></span>
                    </th>
                    <th scope="col" data-sort-key="ram" aria-sort="none">
                        <button type="button" class="sort-btn" data-sort-trigger="ram">RAM<i class="ti ti-arrows-sort sort-icon" aria-hidden="true"></i></button>
                    </th>
                    <th scope="col" data-sort-key="disk" aria-sort="none">
                        <button type="button" class="sort-btn" data-sort-trigger="disk">Disk<i class="ti ti-arrows-sort sort-icon" aria-hidden="true"></i></button>
                    </th>
                    <th scope="col" class="d-none d-xl-table-cell">Panel</th>
                    <th scope="col">Services</th>
                    <th scope="col">NET</th>
                    <th scope="col" data-sort-key="queue" aria-sort="none">
                        <button type="button" class="sort-btn" data-sort-trigger="queue">Queue<i class="ti ti-arrows-sort sort-icon" aria-hidden="true"></i></button>
                    </th>
                    <th scope="col">Status</th>
                </tr>
                </thead>
                <tbody data-server-table>
                <?php if (empty($rows)): ?>
                    <tr data-server-empty><td colspan="11" class="table-empty">
                        <div class="table-empty-inner">
                            <span>No server metrics available yet.</span>
                            <a href="<?= e(app_url('servers')) ?>" class="btn btn-sm btn-outline-info mt-2">Manage Servers</a>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <tr data-dashboard-filter-empty hidden><td colspan="11" class="table-empty">
                    <div class="table-empty-inner">
                        <span>No servers match the selected filter or search query.</span>
                        <button type="button" class="btn btn-sm btn-outline-light mt-2" data-dashboard-filter-reset><i class="ti ti-refresh me-1" aria-hidden="true"></i>Reset filter</button>
                    </div>
                </td></tr>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $sid = (int) ($row['id'] ?? 0);
                    $status = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes);
                    $ramPct = calculateUsagePercent((int) ($row['ram_used'] ?? 0), (int) ($row['ram_total'] ?? 0));
                    $hddPct = calculateUsagePercent((int) ($row['hdd_used'] ?? 0), (int) ($row['hdd_total'] ?? 0));
                    $serviceSummary = $serviceSummaryByServer[$sid] ?? ['up' => 0, 'down' => 0, 'unknown' => 0];
                    $serviceUp = max(0, (int) ($serviceSummary['up'] ?? 0));
                    $serviceDown = max(0, (int) ($serviceSummary['down'] ?? 0));
                    $serviceUnknown = max(0, (int) ($serviceSummary['unknown'] ?? 0));
                    $totalServices = $serviceUp + $serviceDown + $serviceUnknown;
                    if ($status === 'down' && $totalServices > 0) {
                        $serviceUp = 0;
                        $serviceDown = $totalServices;
                        $serviceUnknown = 0;
                    }
                    $searchIndex = strtolower(implode(' ', array_filter([
                        $row['name'] ?? '',
                        $row['host'] ?? '',
                        $row['location'] ?? '',
                        $row['panel_profile'] ?? '',
                    ])));
                    ?>
                    <tr data-server-id="<?= e((string) $sid) ?>" data-server-status="<?= e($status) ?>" data-server-search="<?= e($searchIndex) ?>" data-detail-url="<?= e(app_url('servers/' . $sid)) ?>" class="dashboard-row-link" tabindex="0" role="link" aria-label="Open details for <?= e((string) $row['name']) ?>">
                        <td>
                            <span class="table-cell-truncate" title="<?= e((string) $row['name']) ?>">
                                <?= e((string) $row['name']) ?>
                            </span>
                        </td>
                        <td><?= e($row['location'] ?? '-') ?></td>
                        <td class="font-mono"><?= e(formatUptimeCompact((int) ($row['uptime'] ?? 0))) ?></td>
                        <td>
                            <?php $cpuLoadVal = (float) ($row['cpu_load'] ?? 0); ?>
                            <?php $cpuSevClass = $cpuLoadVal > (float) ($cpuCriticalThreshold ?? 4) ? 'text-danger' : ($cpuLoadVal > (float) ($cpuWarnThreshold ?? 2) ? 'text-warning' : ''); ?>
                            <div class="cpu-cell">
                                <div class="cpu-value font-mono <?= e($cpuSevClass) ?>" title="<?= e($cpuSevClass !== '' ? 'CPU load exceeds threshold' : 'CPU load normal') ?>"><?= e(number_format($cpuLoadVal, 2)) ?></div>
                                <svg class="cpu-sparkline" width="60" height="18"><polyline fill="none" stroke="var(--sv-muted)" stroke-width="1.5" points="0,16.0 60,16.0"/></svg>
                            </div>
                        </td>
                        <td>
                            <div class="resource-cell">
                                <div class="resource-label">
                                    <span><?= e(formatBytes((int) ($row['ram_used'] ?? 0))) ?> / <?= e(formatBytes((int) ($row['ram_total'] ?? 0))) ?></span>
                                    <span class="font-mono"><?= e(number_format((float) $ramPct, 1)) ?>%</span>
                                </div>
                                <div class="progress resource-progress" role="progressbar" aria-label="RAM usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) round($ramPct, 1)) ?>">
                                    <div class="progress-bar resource-progress-bar <?= e($ramPct >= 80 ? 'is-critical' : ($ramPct > 60 ? 'is-warning' : 'is-ok')) ?>" style="<?= e('--target-width:' . number_format((float) $ramPct, 1, '.', '') . '%') ?>"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="resource-cell">
                                <div class="resource-label">
                                    <span><?= e(formatBytes((int) ($row['hdd_used'] ?? 0))) ?> / <?= e(formatBytes((int) ($row['hdd_total'] ?? 0))) ?></span>
                                    <span class="font-mono"><?= e(number_format((float) $hddPct, 1)) ?>%</span>
                                </div>
                                <div class="progress resource-progress" role="progressbar" aria-label="Disk usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) round($hddPct, 1)) ?>">
                                    <div class="progress-bar resource-progress-bar <?= e($hddPct >= 80 ? 'is-critical' : ($hddPct > 60 ? 'is-warning' : 'is-ok')) ?>" style="<?= e('--target-width:' . number_format((float) $hddPct, 1, '.', '') . '%') ?>"></div>
                                </div>
                            </div>
                        </td>
                        <td class="d-none d-xl-table-cell"><?= panel_brand_chip((string) ($row['panel_profile'] ?? 'generic')) ?></td>
                        <td>
                            <?php if ($serviceDown > 0 || $serviceUnknown > 0): ?>
                                <?php if ($serviceDown > 0): ?>
                                    <span class="text-danger fw-semibold me-2">
                                        <i class="ti ti-arrow-down-circle me-1" aria-label="down"></i><span class="font-mono"><?= e((string) $serviceDown) ?></span>
                                    </span>
                                <?php endif; ?>
                                <?php if ($serviceUnknown > 0): ?>
                                    <span class="text-warning fw-semibold">
                                        <i class="ti ti-help-circle me-1" aria-label="unknown"></i><span class="font-mono"><?= e((string) $serviceUnknown) ?></span>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-success fw-semibold">
                                    <i class="ti ti-arrow-up-circle me-1" aria-label="up"></i><span class="font-mono"><?= e((string) $serviceUp) ?></span>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="net-line"><i class="ti ti-arrow-down" aria-label="In"></i> <span class="font-mono"><?= e(formatNetworkBps((int) ($row['network_in_bps'] ?? 0))) ?></span></div>
                            <div class="net-line text-secondary"><i class="ti ti-arrow-up" aria-label="Out"></i> <span class="font-mono"><?= e(formatNetworkBps((int) ($row['network_out_bps'] ?? 0))) ?></span></div>
                        </td>
                        <?php $mailQueue = max(0, (int) ($row['mail_queue_total'] ?? 0)); ?>
                        <td class="font-mono"><?= e((string) $mailQueue) ?></td>
                        <td><span class="badge <?= e('badge-' . $status) ?> text-uppercase"><?= e($status) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!empty($showAllLink)): ?>
                    <tr><td colspan="11" class="text-center py-2">
                        <a href="<?= e(app_url('servers')) ?>" class="btn btn-sm btn-outline-light">View all servers <i class="ti ti-arrow-right ms-1" aria-hidden="true"></i></a>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>
<script<?= csp_nonce_attr() ?>>
window.MONITORS_API_STATUS = <?= json_encode(app_url('api/status?include_inactive=1'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MONITORS_API_STREAM = <?= json_encode(app_url('api/stream'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MONITORS_SERVERS_LIST = <?= json_encode(app_url('servers'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MONITORS_ADMIN_DETAIL_BASE = <?= json_encode(app_url('servers/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MONITORS_API_ALERTS = <?= json_encode(app_url('api/alerts'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MONITORS_CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MONITORS_USER_ROLE = <?= json_encode((string) (current_user()['role'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MONITORS_CPU_THRESHOLDS = <?= json_encode(['warn' => (float) ($cpuWarnThreshold ?? 2), 'critical' => (float) ($cpuCriticalThreshold ?? 4)]) ?>;
window.MONITORS_PANEL_BRANDS = <?= json_encode(panel_brands_for_js(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= e(asset_url('assets/js/dashboard.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/notification-center.js')) ?>"></script>
