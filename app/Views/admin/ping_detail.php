<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Ping Monitor Detail</h1>
            <p class="page-subtitle">Detailed status, latency history, and operational actions.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('ping')) ?>">Back to List</a>
            <?php if ($canManageMonitors): ?>
                <a class="btn btn-outline-info" href="<?= e(app_url('ping/' . $id . '/edit')) ?>">Edit</a>
                <form class="d-inline" method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="action" value="run_now">
                    <button class="btn btn-outline-success" type="submit" data-submit-loading data-loading-text="Running...">Run Now</button>
                </form>
                <form class="d-inline" method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <button class="btn btn-outline-warning" type="submit" data-submit-loading data-loading-text="Updating..."><?= (int) ($monitor['active'] ?? 0) === 1 ? 'Pause' : 'Enable' ?></button>
                </form>
                <form class="d-inline" method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn-outline-danger" type="submit" data-confirm="Delete this ping monitor and all history?" data-submit-loading data-loading-text="Deleting...">Delete</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="row g-3 summary-grid ping-detail-summary-grid" data-ui-section>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-total p-3 h-100">
                <div class="summary-card-head"><span class="summary-card-label">Name</span><i class="ti ti-bookmark summary-card-icon"></i></div>
                <div class="summary-card-value" style="font-size:1rem;line-height:1.35;" data-ping-name><?= e((string) $monitor['name']) ?></div>
                <div class="summary-card-subtitle" data-ping-target>Target: <?= e((string) ($monitor['target'] ?? '-')) ?></div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-online p-3 h-100">
                <div class="summary-card-head"><span class="summary-card-label">Current Status</span><i class="ti ti-activity summary-card-icon"></i></div>
                <div class="summary-card-value"><span class="badge <?= e($statusBadgeClass) ?> text-uppercase" data-ping-status><?= e($displayStatus) ?></span></div>
                <div class="summary-card-subtitle" data-ping-last-change>Last change: <?= e((string) ($monitor['last_change_at'] ?? '-')) ?></div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-pending p-3 h-100">
                <div class="summary-card-head"><span class="summary-card-label">Latency</span><i class="ti ti-dashboard summary-card-icon"></i></div>
                <div class="summary-card-value" data-ping-latency><?= isset($monitor['last_latency_ms']) ? e(number_format((float) $monitor['last_latency_ms'], 2)) . ' ms' : '-' ?></div>
                <div class="summary-card-subtitle" data-ping-last-check>Last check: <?= e((string) ($monitor['last_checked_at'] ?? '-')) ?></div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-down p-3 h-100">
                <div class="summary-card-head"><span class="summary-card-label">Failure Count</span><i class="ti ti-alert-triangle summary-card-icon"></i></div>
                <div class="summary-card-value" data-ping-failures><?= e((string) $failureCount30d) ?></div>
                <div class="summary-card-subtitle" data-ping-threshold>30d DOWN · Alert: <?= e((string) ((int) ($monitor['failure_threshold'] ?? 2))) ?> consecutive</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-online p-3 h-100">
                <div class="summary-card-head"><span class="summary-card-label">Uptime</span><i class="ti ti-chart-line summary-card-icon"></i></div>
                <div class="summary-card-value" data-ping-uptime><?= $uptimePercent === null ? '-' : e(number_format($uptimePercent, 2) . '%') ?></div>
                <div class="summary-card-subtitle" data-ping-uptime-detail><?= e((string) $uptimeUpChecks) ?>/<?= e((string) $uptimeTotalChecks) ?> checks in <?= e($range) ?></div>
            </div>
        </div>
    </section>

    <?php if (!empty($monitor['last_error'])): ?>
        <div class="alert alert-warning mb-0" data-ping-last-error>Last error: <?= e((string) $monitor['last_error']) ?></div>
    <?php else: ?>
        <div class="alert alert-warning mb-0" data-ping-last-error hidden></div>
    <?php endif; ?>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Latency &amp; Availability History</h2>
            <form method="get" action="<?= e(app_url('ping/' . $id)) ?>" class="d-flex gap-2" id="pingRangeForm">
                <select class="form-select form-select-sm" name="range" id="pingRangeSelect" aria-label="Filter history range">
                    <option value="5m" <?= $range === '5m' ? 'selected' : '' ?>>5 Minutes</option>
                    <option value="30m" <?= $range === '30m' ? 'selected' : '' ?>>30 Minutes</option>
                    <option value="24h" <?= $range === '24h' ? 'selected' : '' ?>>24 Hours</option>
                    <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>7 Days</option>
                    <option value="30d" <?= $range === '30d' ? 'selected' : '' ?>>30 Days</option>
                </select>
                <noscript><button class="btn btn-soft btn-sm" type="submit">Filter</button></noscript>
            </form>
        </div>
        <div class="card-body">
            <div id="pingHistoryChart" class="chart-container-lg"></div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Recent Checks</h2>
            <small class="text-secondary" data-ping-updated></small>
        </div>
        <div class="table-responsive table-shell">
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th>Checked At</th>
                    <th>Status</th>
                    <th>Latency</th>
                    <th>Error</th>
                </tr>
                </thead>
                <tbody data-ping-recent-body>
                <?php if (empty($historyPageRows)): ?>
                    <tr><td colspan="4" class="table-empty">No checks in selected range.</td></tr>
                <?php endif; ?>
                <?php foreach ($historyPageRows as $row): ?>
                    <?php $rowStatus = (string) ($row['status'] ?? 'down'); ?>
                    <tr>
                        <td><?= e((string) ($row['checked_at'] ?? '-')) ?></td>
                        <td><span class="badge <?= e($rowStatus === 'up' ? 'badge-online' : 'badge-down') ?> text-uppercase"><?= e($rowStatus) ?></span></td>
                        <td class="font-mono"><?= isset($row['latency_ms']) ? e(number_format((float) $row['latency_ms'], 2)) . ' ms' : '-' ?></td>
                        <td><?= e((string) ($row['error_message'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-surface-2 border-soft d-flex justify-content-between align-items-center">
                <small class="text-secondary">
                    Showing <?= e((string) ($offset + 1)) ?>-<?= e((string) min($offset + $perPage, $totalHistoryRows)) ?> of <?= e((string) $totalHistoryRows) ?> checks
                </small>
                <nav aria-label="Recent checks pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(app_url('ping/' . $id . '?range=' . rawurlencode($range) . '&page=' . max(1, $page - 1))) ?>">Previous</a>
                        </li>
                        <li class="page-item active" aria-current="page">
                            <span class="page-link"><?= e((string) $page) ?> / <?= e((string) $totalPages) ?></span>
                        </li>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(app_url('ping/' . $id . '?range=' . rawurlencode($range) . '&page=' . min($totalPages, $page + 1))) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </section>
</main>
<script<?= csp_nonce_attr() ?>>window.SERVMON_PING_HISTORY = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script<?= csp_nonce_attr() ?>>window.SERVMON_PING_DETAIL = <?= json_encode(['endpoint' => app_url('api/ping-detail'), 'id' => $id, 'range' => $range, 'page' => $page], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= e(asset_url('assets/js/ping-detail.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
