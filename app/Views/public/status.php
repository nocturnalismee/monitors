<main id="main-content" class="container py-4">
    <header class="mb-2 py-1">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
            <div>
                <h1 class="h3 mb-1 d-flex align-items-center gap-2">
                    <?php if ($brandingLogoUrl !== ''): ?>
                        <img class="monitors-brand-logo" src="<?= e($brandingLogoUrl) ?>" alt="<?= e(APP_NAME) ?> logo">
                    <?php endif; ?>
                    <span><?= e(APP_NAME) ?></span>
                </h1>
                <p class="text-secondary mb-0">A Lightweight server monitoring system</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-info" type="button" data-theme-toggle title="Toggle Theme" aria-label="Toggle Theme">
                    <i class="ti ti-contrast-2"></i>
                </button>
            </div>
        </div>
    </header>

    <div class="row g-3 mb-4 summary-grid">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-total p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Total Servers</span>
                    <i class="ti ti-server-2 summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-public-total><?= e((string) $total) ?></div>
                <div class="summary-card-subtitle">Monitored hosts</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-online p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Online</span>
                    <i class="ti ti-arrow-up-circle summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-public-online><?= e((string) $online) ?></div>
                <div class="summary-card-subtitle">Healthy status</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-down p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Down</span>
                    <i class="ti ti-alert-triangle summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-public-down><?= e((string) $down) ?></div>
                <div class="summary-card-subtitle">Needs attention</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-pending p-3">
                <div class="summary-card-head">
                    <span class="summary-card-label">Pending</span>
                    <i class="ti ti-history summary-card-icon" aria-hidden="true"></i>
                </div>
                <div class="summary-card-value" data-public-pending><?= e((string) $pending) ?></div>
                <div class="summary-card-subtitle">Awaiting check-in</div>
            </div>
        </div>
    </div>

    <div class="card card-neon">
        <div class="public-table-toolbar d-md-none">
            <label class="visually-hidden" for="publicSortSelect">Sort servers</label>
            <select id="publicSortSelect" class="form-select form-select-sm public-sort-select" data-public-sort-select>
                <option value="default">Sort: Default</option>
                <option value="cpu-desc">CPU (highest)</option>
                <option value="cpu-asc">CPU (lowest)</option>
                <option value="ram-desc">RAM (highest)</option>
                <option value="ram-asc">RAM (lowest)</option>
                <option value="disk-desc">Disk (highest)</option>
                <option value="disk-asc">Disk (lowest)</option>
                <option value="queue-desc">Queue (highest)</option>
                <option value="queue-asc">Queue (lowest)</option>
            </select>
        </div>
        <div class="table-responsive public-table-shell">
            <table class="table monitors-table public-summary-table mb-0">
                <colgroup>
                    <col class="public-col-name">
                    <col class="public-col-location">
                    <col class="public-col-status">
                    <col class="public-col-uptime">
                    <col class="public-col-resource">
                    <col class="public-col-resource">
                    <col class="public-col-cpu">
                    <col class="public-col-panel">
                    <col class="public-col-services">
                    <col class="public-col-net">
                    <col class="public-col-queue">
                </colgroup>
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Location</th>
                    <th scope="col">Status</th>
                    <th scope="col">Uptime</th>
                    <th scope="col" data-sort-key="ram" aria-sort="none">
                        <button type="button" class="sort-btn" data-sort-trigger="ram">RAM<i class="ti ti-arrows-sort sort-icon" aria-hidden="true"></i></button>
                    </th>
                    <th scope="col" data-sort-key="disk" aria-sort="none">
                        <button type="button" class="sort-btn" data-sort-trigger="disk">Disk<i class="ti ti-arrows-sort sort-icon" aria-hidden="true"></i></button>
                    </th>
                    <th scope="col" data-sort-key="cpu" aria-sort="none">
                        <button type="button" class="sort-btn" data-sort-trigger="cpu">CPU<i class="ti ti-arrows-sort sort-icon" aria-hidden="true"></i></button>
                    </th>
                    <th scope="col">Panel</th>
                    <th scope="col">Services</th>
                    <th scope="col">NET IN/OUT</th>
                    <th scope="col" data-sort-key="queue" aria-sort="none">
                        <button type="button" class="sort-btn" data-sort-trigger="queue">QUEUE<i class="ti ti-arrows-sort sort-icon" aria-hidden="true"></i></button>
                    </th>
                </tr>
                </thead>
                <tbody data-public-server-table>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $status = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes);
                    $ramUsed = max(0, (int) ($row['ram_used'] ?? 0));
                    $ramTotal = max(0, (int) ($row['ram_total'] ?? 0));
                    $ramPct = calculateUsagePercent($ramUsed, $ramTotal);
                    $diskUsed = max(0, (int) ($row['hdd_used'] ?? 0));
                    $diskTotal = max(0, (int) ($row['hdd_total'] ?? 0));
                    $diskPct = calculateUsagePercent($diskUsed, $diskTotal);
                    $sid = (int) ($row['id'] ?? 0);
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
                    ?>
                    <tr>
                        <td data-label="Name">
                            <span class="table-cell-truncate" title="<?= e((string) $row['name']) ?>">
                                <?= e((string) $row['name']) ?>
                            </span>
                        </td>
                        <td data-label="Location"><span class="public-location"><?= e($row['location'] ?? '-') ?></span></td>
                        <td data-label="Status"><span class="badge <?= e('badge-' . $status) ?> text-uppercase"><?= e($status) ?></span></td>
                        <td class="font-mono" data-label="Uptime"><?= e(formatUptimeCompact((int) ($row['uptime'] ?? 0))) ?></td>
                        <td data-label="RAM">
                            <div class="resource-cell">
                                <div class="resource-label">
                                    <span><?= e(formatBytes($ramUsed)) ?> / <?= e(formatBytes($ramTotal)) ?></span>
                                    <span class="font-mono"><?= e(number_format($ramPct, 1)) ?>%</span>
                                </div>
                                <div class="progress resource-progress" role="progressbar" aria-label="RAM usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) round($ramPct, 1)) ?>">
                                    <div class="progress-bar resource-progress-bar <?= e($ramPct >= 80 ? 'is-critical' : ($ramPct > 60 ? 'is-warning' : 'is-ok')) ?>" style="<?= e('--target-width:' . number_format($ramPct, 1, '.', '') . '%') ?>"></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="Disk">
                            <div class="resource-cell">
                                <div class="resource-label">
                                    <span><?= e(formatBytes($diskUsed)) ?> / <?= e(formatBytes($diskTotal)) ?></span>
                                    <span class="font-mono"><?= e(number_format($diskPct, 1)) ?>%</span>
                                </div>
                                <div class="progress resource-progress" role="progressbar" aria-label="Disk usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) round($diskPct, 1)) ?>">
                                    <div class="progress-bar resource-progress-bar <?= e($diskPct >= 80 ? 'is-critical' : ($diskPct > 60 ? 'is-warning' : 'is-ok')) ?>" style="<?= e('--target-width:' . number_format($diskPct, 1, '.', '') . '%') ?>"></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="CPU">
                            <?php $cpuLoadVal = (float) ($row['cpu_load'] ?? 0); ?>
                            <?php $cpuSevClass = $cpuLoadVal > (float) ($cpuCriticalThreshold ?? 4) ? 'text-danger' : ($cpuLoadVal > (float) ($cpuWarnThreshold ?? 2) ? 'text-warning' : ''); ?>
                            <div class="cpu-cell">
                                <div class="cpu-value font-mono <?= e($cpuSevClass) ?>" title="<?= e($cpuSevClass !== '' ? 'CPU load exceeds threshold' : 'CPU load normal') ?>"><?= e(number_format($cpuLoadVal, 2)) ?></div>
                                <svg class="cpu-sparkline" width="60" height="18"><polyline fill="none" stroke="var(--sv-muted)" stroke-width="1.5" points="0,16.0 60,16.0"/></svg>
                            </div>
                        </td>
                        <td data-label="Panel"><?= panel_brand_chip((string) ($row['panel_profile'] ?? 'generic'), 'public-panel-chip') ?></td>
                        <td data-label="Services">
                            <div class="service-summary">
                                <?php if ($serviceDown > 0 || $serviceUnknown > 0): ?>
                                    <?php if ($serviceDown > 0): ?>
                                        <span class="service-state text-danger">
                                            <i class="ti ti-arrow-down-circle" aria-label="down"></i><span class="font-mono"><?= e((string) $serviceDown) ?></span>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($serviceUnknown > 0): ?>
                                        <span class="service-state text-warning">
                                            <i class="ti ti-help-circle" aria-label="unknown"></i><span class="font-mono"><?= e((string) $serviceUnknown) ?></span>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="service-state text-success">
                                        <i class="ti ti-arrow-up-circle" aria-label="up"></i><span class="font-mono"><?= e((string) $serviceUp) ?></span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td data-label="NET">
                            <div class="net-line"><i class="ti ti-arrow-down" aria-label="In"></i><span class="font-mono"><?= e(formatNetworkBps((int) ($row['network_in_bps'] ?? 0))) ?></span></div>
                            <div class="net-line text-secondary"><i class="ti ti-arrow-up" aria-label="Out"></i><span class="font-mono"><?= e(formatNetworkBps((int) ($row['network_out_bps'] ?? 0))) ?></span></div>
                        </td>
                        <?php $mailQueue = max(0, (int) ($row['mail_queue_total'] ?? 0)); ?>
                        <td class="font-mono" data-label="Queue"><?= e((string) $mailQueue) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<script<?= csp_nonce_attr() ?>>window.MONITORS_API_STATUS = <?= json_encode(app_url('api/status'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script<?= csp_nonce_attr() ?>>window.MONITORS_CPU_THRESHOLDS = <?= json_encode(['warn' => (float) ($cpuWarnThreshold ?? 2), 'critical' => (float) ($cpuCriticalThreshold ?? 4)]) ?>;</script>
<script<?= csp_nonce_attr() ?>>window.MONITORS_PANEL_BRANDS = <?= json_encode(panel_brands_for_js(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= e(asset_url('assets/js/public.js')) ?>"></script>
