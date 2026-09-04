<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Ping Monitor</h1>
            <p class="page-subtitle">Monitor availability and latency for IP and domain targets.</p>
        </div>
        <?php if ($canManageMonitors): ?>
            <div class="toolbar-actions">
                <button type="button" class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#pingTerminalModal" title="Ping Terminal" aria-label="Ping Terminal">
                    <i class="ti ti-terminal" aria-hidden="true"></i>
                </button>
                <a href="<?= e(app_url('ping/add')) ?>" class="btn btn-info"><i class="ti ti-plus me-1"></i>Add Ping</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="row g-3 summary-grid" data-ui-section>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-total p-3">
                <div class="summary-card-head"><span class="summary-card-label">Total</span><i class="ti ti-radar summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) $summary['total']) ?></div>
                <div class="summary-card-subtitle">Configured targets</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-online p-3">
                <div class="summary-card-head"><span class="summary-card-label">Up</span><i class="ti ti-arrow-up-circle summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) $summary['up']) ?></div>
                <div class="summary-card-subtitle">Reachable</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-down p-3">
                <div class="summary-card-head"><span class="summary-card-label">Down</span><i class="ti ti-alert-triangle summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) $summary['down']) ?></div>
                <div class="summary-card-subtitle">Unreachable</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-pending p-3">
                <div class="summary-card-head"><span class="summary-card-label">Pending/Paused</span><i class="ti ti-history summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) ($summary['pending'] + $summary['paused'])) ?></div>
                <div class="summary-card-subtitle">Pending <?= e((string) $summary['pending']) ?> | Paused <?= e((string) $summary['paused']) ?></div>
            </div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4 col-lg-3">
                    <label class="form-label" for="filter-search">Search</label>
                    <?php
                    $mode = 'inline';
                    $name = 'q';
                    $value = (string) ($q ?? '');
                    $placeholder = 'name or target';
                    $inputId = 'filter-search';
                    $inputAttrs = '';
                    $wrapClass = 'admin-search-wrap';
                    require SERVMON_BASE_DIR . '/app/Views/partials/admin_filter_bar.php';
                    ?>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label" for="filter-status">Status</label>
                    <select class="form-select" name="status" id="filter-status">
                        <?php foreach (['all', 'up', 'down', 'pending', 'paused'] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $statusFilter === $opt ? 'selected' : '' ?>><?= e(strtoupper($opt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label" for="filter-type">Target Type</label>
                    <select class="form-select" name="type" id="filter-type">
                        <?php foreach (['all', 'domain', 'ip', 'url'] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $typeFilter === $opt ? 'selected' : '' ?>><?= e(strtoupper($opt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label" for="filter-method">Method</label>
                    <select class="form-select" name="method" id="filter-method">
                        <?php foreach (['all', 'icmp', 'http'] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $methodFilter === $opt ? 'selected' : '' ?>><?= e(strtoupper($opt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-12 col-lg-1 d-flex align-items-end">
                    <button class="btn btn-info w-100" type="submit">Filter</button>
                </div>
            </form>
        </div>

        <div class="table-responsive table-shell ping-table-shell ping-table-responsive" data-ui-table>
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Target</th>
                    <th>Method</th>
                    <th>Interval</th>
                    <th>Timeout</th>
                    <th>Fail Threshold</th>
                    <th>Status</th>
                    <th>Uptime</th>
                    <th>Latency</th>
                    <th>Last Check</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="11" class="table-empty">
                        <div class="table-empty-inner">
                            <span>No ping monitors found.</span>
                            <?php if ($canManageMonitors): ?>
                                <a href="<?= e(app_url('ping/add')) ?>" class="btn btn-sm btn-info mt-2"><i class="ti ti-plus me-1" aria-hidden="true"></i>Add your first ping monitor</a>
                            <?php endif; ?>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $status = ping_display_status((string) ($row['last_status'] ?? 'unknown'), (int) ($row['active'] ?? 0)); ?>
                    <?php
                    $monitorId = (int) ($row['id'] ?? 0);
                    $uptimeBars = $uptimeBarsByMonitor[$monitorId] ?? array_fill(0, $uptimePoints, ['status' => 'pending', 'checked_at' => null]);
                    $uptimeStats = $uptimeStatsByMonitor[$monitorId] ?? ['up' => 0, 'total' => 0, 'percent' => null];
                    ?>
                    <tr>
                        <td><?= e((string) $row['name']) ?></td>
                        <td>
                            <div class="fw-semibold"><?= e((string) $row['target']) ?></div>
                            <small class="text-secondary text-uppercase"><?= e((string) $row['target_type']) ?></small>
                        </td>
                        <td><span class="badge text-bg-secondary text-uppercase"><?= e((string) ($row['check_method'] ?? 'icmp')) ?></span></td>
                        <td><?= e((string) ((int) ($row['check_interval_seconds'] ?? 60))) ?>s</td>
                        <td><?= e((string) ((int) ($row['timeout_seconds'] ?? 2))) ?>s</td>
                        <td><?= e((string) ((int) ($row['failure_threshold'] ?? 2))) ?></td>
                        <td><span class="badge <?= e($pingStatusBadgeClass($status)) ?> text-uppercase"><?= e($status) ?></span></td>
                        <td>
                            <div class="font-mono fw-semibold">
                                <?= $uptimeStats['percent'] === null ? '-' : e(number_format((float) $uptimeStats['percent'], 2) . '%') ?>
                            </div>
                            <div class="ping-uptime-strip" aria-label="Last <?= e((string) $uptimePoints) ?> checks">
                                <?php foreach ($uptimeBars as $segment): ?>
                                    <?php
                                    $segmentStatus = (string) ($segment['status'] ?? 'pending');
                                    $segmentClass = $segmentStatus === 'up'
                                        ? 'is-up'
                                        : ($segmentStatus === 'down' ? 'is-down' : 'is-pending');
                                    $segmentCheckedAt = $segment['checked_at'] ?? null;
                                    $segmentTitle = $segmentCheckedAt !== null && $segmentCheckedAt !== ''
                                        ? ('Status: ' . strtoupper($segmentStatus) . ' | ' . (string) $segmentCheckedAt)
                                        : 'Status: PENDING';
                                    ?>
                                    <span class="ping-uptime-segment <?= e($segmentClass) ?>" title="<?= e($segmentTitle) ?>" aria-hidden="true"></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td><?= isset($row['last_latency_ms']) ? e(number_format((float) $row['last_latency_ms'], 2)) . ' ms' : '-' ?></td>
                        <td><?= e((string) ($row['last_checked_at'] ?? '-')) ?></td>
                        <td class="text-end">
                            <div class="dropdown d-inline-block">
                                <button
                                    class="btn btn-sm btn-outline-light"
                                    type="button"
                                    data-bs-toggle="dropdown"
                                    data-bs-auto-close="outside"
                                    aria-expanded="false"
                                    aria-label="Actions"
                                >
                                    <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="<?= e(app_url('ping/' . (int) $row['id'])) ?>">
                                            <i class="ti ti-eye me-2" aria-hidden="true"></i>Details
                                        </a>
                                    </li>
                                    <?php if ($canManageMonitors): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= e(app_url('ping/' . (int) $row['id'] . '/edit')) ?>">
                                                <i class="ti ti-pencil me-2" aria-hidden="true"></i>Edit
                                            </a>
                                        </li>
                                        <li>
                                            <form method="post" class="m-0">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="monitor_id" value="<?= e((string) $row['id']) ?>">
                                                <button class="dropdown-item text-warning" type="submit" data-submit-loading data-loading-text="Updating...">
                                                    <i class="ti ti-player-pause me-2" aria-hidden="true"></i><?= (int) ($row['active'] ?? 0) === 1 ? 'Pause' : 'Enable' ?>
                                                </button>
                                            </form>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="post" class="m-0">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="monitor_id" value="<?= e((string) $row['id']) ?>">
                                                <button class="dropdown-item text-danger" type="submit" data-confirm="Delete this ping monitor and all history?" data-submit-loading data-loading-text="Deleting...">
                                                    <i class="ti ti-trash me-2" aria-hidden="true"></i>Delete
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<script<?= csp_nonce_attr() ?>>window.SERVMON_PING_AUTO_REFRESH_MS = 15000;</script>
<script src="<?= e(asset_url('assets/js/ping-refresh.js')) ?>"></script>

<div class="modal fade" id="pingTerminalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content terminal-window">
            <div class="terminal-titlebar">
                <span class="terminal-dot terminal-dot-red" aria-hidden="true"></span>
                <span class="terminal-dot terminal-dot-yellow" aria-hidden="true"></span>
                <span class="terminal-dot terminal-dot-green" aria-hidden="true"></span>
                <span class="terminal-title">app@monitors: ~</span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="terminal-body" id="pingTerminalOutput" role="log" aria-live="polite"></div>
            <div class="terminal-inputline">
                <span class="terminal-prompt" aria-hidden="true">app@monitors:~$</span>
                <input type="text" id="pingTerminalCmd" class="terminal-input" placeholder="ping 1.1.1.1" autocomplete="off" spellcheck="false" aria-label="ping command">
            </div>
            <div class="terminal-hint">Supported: ping &lt;host&gt; &nbsp;|&nbsp; ping -c &lt;1-10&gt; &lt;host&gt; &nbsp;|&nbsp; ping -t &lt;1-10&gt; &lt;host&gt;</div>
        </div>
    </div>
</div>

<script<?= csp_nonce_attr() ?>>window.SERVMON_CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= e(asset_url('assets/js/ping-terminal.js')) ?>"></script>
