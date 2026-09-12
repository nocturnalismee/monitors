<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title"><?= e((string) $server['name']) ?></h1>
            <ul class="page-subtitle server-meta-list" aria-label="Server metadata">
                <?php foreach ($serverMetaItems as $meta): ?>
                    <li class="server-meta-chip">
                        <span class="server-meta-key"><?= e((string) $meta['label']) ?></span>
                        <span class="server-meta-value"><?= e((string) $meta['value']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="toolbar-actions align-items-center toolbar-actions-end">
            <span class="detail-live-state" data-detail-live-state aria-live="polite">
                <span class="detail-live-dot" aria-hidden="true"></span>Live
            </span>
            <span class="badge <?= e('badge-' . $status) ?> text-uppercase"><?= e($status) ?></span>
            <a class="btn btn-soft btn-sm" href="<?= e(app_url('disk-health/' . (int) $server['id'])) ?>"><i class="ti ti-disc me-1"></i>Disk Health</a>
            <?php if (has_role('admin')): ?>
                <a class="btn btn-soft btn-sm" href="<?= e(app_url('servers/' . (int) $server['id'] . '/setup')) ?>" title="Agent setup instructions"><i class="ti ti-terminal me-1"></i>Setup</a>
                <a class="btn btn-soft btn-sm" href="<?= e(app_url('servers/' . (int) $server['id'] . '/edit')) ?>"><i class="ti ti-edit me-1"></i>Edit</a>
            <?php endif; ?>
        </div>
    </section>
    <?php if ((int) ($server['maintenance_mode'] ?? 0) === 1): ?>
        <div class="alert alert-warning py-2">
            Maintenance mode is active. <?= e(maintenance_display_text($server)) ?>.
        </div>
    <?php endif; ?>

    <section class="row g-3" data-ui-section>
        <div class="col-md-3">
            <div class="card card-neon p-3 h-100 server-stat-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="text-secondary small">Uptime</div>
                    <i class="ti ti-history stat-icon"></i>
                </div>
                <div class="h5 mb-0 stat-value" data-server-uptime><?= e(formatUptime((int) ($server['uptime'] ?? 0))) ?></div>
                <div class="small text-secondary stat-meta">Agent: <?= e((string) ($server['agent_mode'] ?? 'push')) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-neon p-3 h-100 server-stat-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="text-secondary small">CPU Load</div>
                    <i class="ti ti-cpu stat-icon"></i>
                </div>
                <div class="h5 mb-0 stat-value" data-server-cpu><?= e(number_format((float) ($server['cpu_load'] ?? 0), 2)) ?></div>
                <div class="small text-secondary mt-2 cpu-summary-grid">
                    <div class="d-flex justify-content-between"><span>High</span><span id="cpuLoadHigh"><?= e(number_format((float) ($server['cpu_load'] ?? 0), 2)) ?></span></div>
                    <div class="d-flex justify-content-between"><span>Low</span><span id="cpuLoadLow"><?= e(number_format((float) ($server['cpu_load'] ?? 0), 2)) ?></span></div>
                    <div class="d-flex justify-content-between"><span>Daily Avg</span><span id="cpuLoadDailyAvg"><?= e(number_format((float) ($server['cpu_load'] ?? 0), 2)) ?></span></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-neon p-3 h-100 server-stat-card">
                <?php $queueTotalTop = (int) ($server['mail_queue_total'] ?? 0); ?>
                <?php
                if ($queueTotalTop >= $mailQueueCriticalThreshold) {
                    $queueState = 'Danger';
                    $queueClass = 'text-danger';
                } elseif ($queueTotalTop >= $mailQueueWarnThreshold) {
                    $queueState = 'Warning';
                    $queueClass = 'text-warning';
                } else {
                    $queueState = 'Normal';
                    $queueClass = 'text-success';
                }
                ?>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="text-secondary small">Mail Queue</div>
                    <i class="ti ti-mail stat-icon"></i>
                </div>
                <div class="h5 mb-0 stat-value <?= e($queueClass) ?>" data-server-mail-queue><?= e((string) $queueTotalTop) ?> emails</div>
                <div class="small text-secondary stat-meta" data-server-mail-meta><?= e((string) ($server['mail_mta'] ?? 'none')) ?> | <?= e($queueState) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-neon p-3 h-100 server-stat-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="text-secondary small">Last Seen</div>
                    <i class="ti ti-calendar-check stat-icon"></i>
                </div>
                <div class="h5 mb-0 stat-value" data-server-last-seen><?= e((string) ($server['last_seen'] ?? '-')) ?></div>
                <div class="small text-secondary stat-meta">State: <span data-server-state><?= e(strtoupper($status)) ?></span></div>
            </div>
        </div>
    </section>

    <section class="card card-neon p-3" data-ui-section>
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <?php $ramPctSafe = max(0, min(100, (float) $ramPct)); ?>
                <?php $ramTone = $ramPctSafe >= 80 ? 'critical' : ($ramPctSafe > 60 ? 'warning' : 'ok'); ?>
                <div class="usage-ring-block">
                    <h2 class="h6 mb-3">RAM</h2>
                    <div class="usage-ring-layout">
                        <div class="usage-ring usage-ring-<?= e($ramTone) ?>" data-server-ram-ring style="--sv-pct: <?= e(number_format($ramPctSafe, 1, '.', '')) ?>;" role="img" aria-label="RAM usage <?= e(number_format($ramPctSafe, 1)) ?> percent">
                            <div class="usage-ring-inner">
                                <span class="usage-ring-value" data-server-ram-value><?= e(number_format($ramPctSafe, 1)) ?>%</span>
                            </div>
                        </div>
                        <div class="usage-ring-meta">
                            <div class="usage-ring-title">Memory Utilization</div>
                            <div class="usage-ring-desc" data-server-ram-meta><?= e(formatBytes((int) ($server['ram_used'] ?? 0))) ?> / <?= e(formatBytes((int) ($server['ram_total'] ?? 0))) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <?php $hddPctSafe = max(0, min(100, (float) $hddPct)); ?>
                <?php $hddTone = $hddPctSafe >= 80 ? 'critical' : ($hddPctSafe > 60 ? 'warning' : 'ok'); ?>
                <div class="usage-ring-block">
                    <h2 class="h6 mb-3">Disk</h2>
                    <div class="usage-ring-layout">
                        <div class="usage-ring usage-ring-<?= e($hddTone) ?>" data-server-disk-ring style="--sv-pct: <?= e(number_format($hddPctSafe, 1, '.', '')) ?>;" role="img" aria-label="Disk usage <?= e(number_format($hddPctSafe, 1)) ?> percent">
                            <div class="usage-ring-inner">
                                <span class="usage-ring-value" data-server-disk-value><?= e(number_format($hddPctSafe, 1)) ?>%</span>
                            </div>
                        </div>
                        <div class="usage-ring-meta">
                            <div class="usage-ring-title">Disk Utilization</div>
                            <div class="usage-ring-desc" data-server-disk-meta><?= e(formatBytes((int) ($server['hdd_used'] ?? 0))) ?> / <?= e(formatBytes((int) ($server['hdd_total'] ?? 0))) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0">Service Status</h2>
        </div>
        <div class="table-responsive table-shell" data-ui-table>
            <table class="table monitors-table mb-0">
                <thead>
                <tr>
                    <th>Group</th>
                    <th>Service</th>
                    <th>Unit</th>
                    <th>Status</th>
                    <th>Updated At</th>
                </tr>
                </thead>
                <tbody data-server-services>
                <?php if (empty($services)): ?>
                    <tr><td colspan="5" class="table-empty">No service data yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($services as $svc): ?>
                    <?php
                    $svcStatus = (string) ($svc['last_status'] ?? 'unknown');
                    $svcClass = match ($svcStatus) {
                        'up' => 'badge-online',
                        'down' => 'badge-down',
                        default => 'badge-pending',
                    };
                    ?>
                    <tr>
                        <td><?= e((string) ($svc['service_group'] ?? '-')) ?></td>
                        <td><?= e((string) ($svc['service_key'] ?? '-')) ?></td>
                        <td><code><?= e((string) ($svc['unit_name'] ?? '-')) ?></code></td>
                        <td><span class="badge <?= e($svcClass) ?> text-uppercase"><?= e($svcStatus) ?></span></td>
                        <td><?= e((string) ($svc['updated_at'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Historical Charts</h2>
            <div class="d-flex flex-wrap gap-2">
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-soft" data-range="5m">5m</button>
                    <button type="button" class="btn btn-soft active" data-range="30m">30m</button>
                    <button type="button" class="btn btn-soft" data-range="24h">24h</button>
                    <button type="button" class="btn btn-soft" data-range="7d">7d</button>
                    <button type="button" class="btn btn-soft" data-range="30d">30d</button>
                </div>
                <button type="button" class="btn btn-soft btn-sm" data-reset-zoom>Reset Zoom</button>
            </div>
        </div>
        <div class="card-body">
            <p class="small text-secondary mb-3">Tip: use mouse wheel to zoom and drag inside the plot to pan, then click <strong>Reset Zoom</strong>.</p>
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card bg-surface border-soft p-3 chart-panel">
                        <div class="chart-panel-head">
                            <h3 class="h6 mb-0">RAM Usage</h3>
                            <button type="button" class="btn btn-icon btn-soft chart-pause-btn" data-chart-pause aria-pressed="false" title="Pause updates" aria-label="Pause live chart updates"><i class="ti ti-player-pause" aria-hidden="true"></i></button>
                        </div>
                        <div class="chart-stage">
                            <div class="chart-skeleton" data-chart-skeleton><span class="visually-hidden">Loading chart data…</span></div>
                            <div id="ramHistoryChart" class="chart-container"></div>
                            <span class="visually-hidden" data-chart-summary="ram"></span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card bg-surface border-soft p-3 chart-panel">
                        <div class="chart-panel-head">
                            <h3 class="h6 mb-0">Disk Usage</h3>
                            <button type="button" class="btn btn-icon btn-soft chart-pause-btn" data-chart-pause aria-pressed="false" title="Pause updates" aria-label="Pause live chart updates"><i class="ti ti-player-pause" aria-hidden="true"></i></button>
                        </div>
                        <div class="chart-stage">
                            <div class="chart-skeleton" data-chart-skeleton><span class="visually-hidden">Loading chart data…</span></div>
                            <div id="diskHistoryChart" class="chart-container"></div>
                            <span class="visually-hidden" data-chart-summary="disk"></span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card bg-surface border-soft p-3 chart-panel">
                        <div class="chart-panel-head">
                            <h3 class="h6 mb-0">CPU Load</h3>
                            <button type="button" class="btn btn-icon btn-soft chart-pause-btn" data-chart-pause aria-pressed="false" title="Pause updates" aria-label="Pause live chart updates"><i class="ti ti-player-pause" aria-hidden="true"></i></button>
                        </div>
                        <div class="chart-stage">
                            <div class="chart-skeleton" data-chart-skeleton><span class="visually-hidden">Loading chart data…</span></div>
                            <div id="cpuHistoryChart" class="chart-container"></div>
                            <span class="visually-hidden" data-chart-summary="cpu"></span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card bg-surface border-soft p-3 chart-panel">
                        <div class="chart-panel-head">
                            <h3 class="h6 mb-0">Network In/Out</h3>
                            <button type="button" class="btn btn-icon btn-soft chart-pause-btn" data-chart-pause aria-pressed="false" title="Pause updates" aria-label="Pause live chart updates"><i class="ti ti-player-pause" aria-hidden="true"></i></button>
                        </div>
                        <div class="chart-stage">
                            <div class="chart-skeleton" data-chart-skeleton><span class="visually-hidden">Loading chart data…</span></div>
                            <div id="networkHistoryChart" class="chart-container"></div>
                            <span class="visually-hidden" data-chart-summary="network"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>
<script<?= csp_nonce_attr() ?>>
window.MONITORS_HISTORY_BOOTSTRAP = <?= json_encode($historyBootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MONITORS_SERVER_STATUS_ENDPOINT = <?= json_encode(app_url('api/status?id=' . $id), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MONITORS_CPU_THRESHOLDS = <?= json_encode(['warn' => (float) ($cpuWarnThreshold ?? 2), 'critical' => (float) ($cpuCriticalThreshold ?? 4)]) ?>;
</script>
<script src="<?= e(asset_url('assets/js/detail.js')) ?>"></script>
  <script<?= csp_nonce_attr() ?>>
  const baseHistoryEndpoint = <?= json_encode(app_url('api/status?id=' . $id . '&points=1200'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  if (typeof bootstrapHistory === 'function' && Array.isArray(window.MONITORS_HISTORY_BOOTSTRAP) && window.MONITORS_HISTORY_BOOTSTRAP.length > 0) {
    bootstrapHistory(window.MONITORS_HISTORY_BOOTSTRAP);
  }
  let activeRange = "30m";
  const initialHistoryEndpoint = <?= json_encode($historyEndpoint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  document.querySelectorAll("[data-range]").forEach((button) => {
    button.addEventListener("click", () => {
      activeRange = button.dataset.range;
      document.querySelectorAll("[data-range]").forEach((btn) => btn.classList.remove("active"));
      button.classList.add("active");
      if (typeof loadHistory === 'function') {
        loadHistory(`${baseHistoryEndpoint}&history=${activeRange}`);
      }
    });
  });
  window.addEventListener("load", () => {
    if (typeof loadHistory === 'function') {
      loadHistory(initialHistoryEndpoint);
    }
  }, { once: true });
  const stopDetailHistory = Monitors.startPoller(() => {
    if (areChartsPaused() || document.hidden) return Promise.resolve();
    return loadHistory(baseHistoryEndpoint + "&history=" + activeRange);
  }, {baseMs:30000, maxMs:120000});
  const stopDetailStatus = Monitors.startPoller(refreshServerDetailStatus, {baseMs:30000, maxMs:120000});
  window.monitorsDetailHistoryStop = stopDetailHistory;
  window.monitorsDetailStatusStop = stopDetailStatus;
</script>
