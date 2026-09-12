<?php
$exportTypeLabels = [
    'alerts' => 'Alert Logs',
    'metrics' => 'Metrics',
    'services' => 'Service Metrics',
    'audits' => 'Audit Logs',
];
?>
<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Export Data</h1>
            <p class="page-subtitle">Download alerts, metrics, service checks, and audit logs in CSV or JSON.</p>
        </div>
    </section>
    <section class="card card-neon mb-3" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0"><i class="ti ti-database-export me-1" aria-hidden="true"></i>Large Export Queue</h2>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Use the queue for large datasets to prevent browser request timeouts. The worker processes up to two jobs per cycle. Queued exports always contain the full dataset (list-page filters are not applied).</p>
            <form method="post" class="row g-2 align-items-end">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="queue_export">
                <div class="col-md-5">
                    <label class="form-label" for="queue-export-type">Dataset</label>
                    <select id="queue-export-type" class="form-select" name="type">
                        <option value="alerts">Alert Logs</option>
                        <option value="metrics">Metrics</option>
                        <option value="services">Service Metrics</option>
                        <option value="audits">Audit Logs</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="queue-export-format">Format</label>
                    <select id="queue-export-format" class="form-select" name="format">
                        <option value="csv">CSV</option>
                        <option value="json">JSON</option>
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-primary" type="submit" data-submit-loading data-loading-text="Queuing..."><i class="ti ti-plus me-1" aria-hidden="true"></i>Queue Large Export</button>
                </div>
            </form>
        </div>
    </section>
    <section class="card card-neon mb-3" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <h2 class="h6 mb-0"><i class="ti ti-history me-1" aria-hidden="true"></i>Recent Export Jobs</h2>
            <span class="text-secondary small"><?= count($exportJobs) ?> job<?= count($exportJobs) === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table monitors-table table-hover mb-0 align-middle">
                <thead><tr><th>ID</th><th>Dataset</th><th>Format</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($exportJobs as $job): ?>
                    <?php
                    $jobStatus = (string) ($job['status'] ?? '');
                    $jobBadge = $jobStatus === 'completed' ? 'success' : ($jobStatus === 'failed' ? 'danger' : 'info');
                    $jobType = (string) ($job['export_type'] ?? '');
                    ?>
                    <tr>
                        <td>#<?= e((string) $job['id']) ?></td>
                        <td><?= e($exportTypeLabels[$jobType] ?? $jobType) ?></td>
                        <td><span class="text-uppercase small"><?= e((string) ($job['export_format'] ?? '')) ?></span></td>
                        <td><span class="badge badge-severity badge-severity-<?= e($jobBadge) ?>"><?= e($jobStatus) ?></span></td>
                        <td><?= e((string) ($job['created_at'] ?? '')) ?></td>
                        <td>
                            <?php if ($jobStatus === 'completed'): ?>
                                <a class="btn btn-sm btn-outline-info" href="<?= e(app_url('export/download/' . (int) $job['id'])) ?>"><i class="ti ti-download me-1" aria-hidden="true"></i>Download</a>
                            <?php elseif ($jobStatus === 'failed'): ?>
                                <span class="text-muted small"><?= e((string) ($job['error_message'] ?? 'Failed')) ?></span>
                            <?php else: ?>
                                <span class="text-muted small"><i class="ti ti-clock me-1" aria-hidden="true"></i>Waiting for worker</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$exportJobs): ?>
                    <tr><td colspan="6" class="table-empty">
                        <div class="table-empty-inner">
                            <i class="ti ti-inbox fs-4" aria-hidden="true"></i>
                            <span>No asynchronous exports yet. Queue a large export above for big datasets.</span>
                        </div>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section data-ui-section aria-labelledby="quick-export-heading">
        <div class="d-flex align-items-baseline gap-2 mb-2 flex-wrap">
            <h2 id="quick-export-heading" class="h6 mb-0">Quick Download</h2>
            <span class="text-muted small">Instant download for smaller, filtered datasets.</span>
        </div>
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card card-neon h-100">
                    <div class="card-header bg-surface-2 border-soft">
                        <h3 class="h6 mb-0"><i class="ti ti-bell me-1" aria-hidden="true"></i>Export Alert Logs</h3>
                    </div>
                    <form method="get" class="d-flex flex-column flex-grow-1">
                        <input type="hidden" name="type" value="alerts">
                        <div class="card-body">
                            <p class="text-muted small mb-3">Severity and server filters apply to this instant download.</p>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-alerts-format">Format</label>
                                    <select id="dl-alerts-format" class="form-select" name="format">
                                        <option value="csv">CSV</option>
                                        <option value="json">JSON</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-alerts-severity">Severity</label>
                                    <select id="dl-alerts-severity" class="form-select" name="severity">
                                        <option value="">All</option>
                                        <option value="info">info</option>
                                        <option value="warning">warning</option>
                                        <option value="danger">danger</option>
                                        <option value="success">success</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-alerts-server">Server</label>
                                    <select id="dl-alerts-server" class="form-select" name="server_id">
                                        <option value="0">All</option>
                                        <?php foreach ($servers as $server): ?>
                                            <option value="<?= e((string) $server['id']) ?>"><?= e((string) $server['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 mt-auto">
                            <button class="btn btn-primary w-100" type="submit" data-submit-loading data-loading-text="Preparing..."><i class="ti ti-download me-1" aria-hidden="true"></i>Download Alert Logs</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card card-neon h-100">
                    <div class="card-header bg-surface-2 border-soft">
                        <h3 class="h6 mb-0"><i class="ti ti-chart-line me-1" aria-hidden="true"></i>Export Metrics</h3>
                    </div>
                    <form method="get" class="d-flex flex-column flex-grow-1">
                        <input type="hidden" name="type" value="metrics">
                        <div class="card-body">
                            <p class="text-muted small mb-3">CPU, memory, and disk usage for the selected range.</p>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-metrics-format">Format</label>
                                    <select id="dl-metrics-format" class="form-select" name="format">
                                        <option value="csv">CSV</option>
                                        <option value="json">JSON</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-metrics-range">Range</label>
                                    <select id="dl-metrics-range" class="form-select" name="history">
                                        <option value="24h">24h</option>
                                        <option value="7d">7d</option>
                                        <option value="30d">30d</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-metrics-server">Server</label>
                                    <select id="dl-metrics-server" class="form-select" name="server_id">
                                        <option value="0">All</option>
                                        <?php foreach ($servers as $server): ?>
                                            <option value="<?= e((string) $server['id']) ?>"><?= e((string) $server['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 mt-auto">
                            <button class="btn btn-primary w-100" type="submit" data-submit-loading data-loading-text="Preparing..."><i class="ti ti-download me-1" aria-hidden="true"></i>Download Metrics</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card card-neon h-100">
                    <div class="card-header bg-surface-2 border-soft">
                        <h3 class="h6 mb-0"><i class="ti ti-server me-1" aria-hidden="true"></i>Export Service Metrics</h3>
                    </div>
                    <form method="get" class="d-flex flex-column flex-grow-1">
                        <input type="hidden" name="type" value="services">
                        <div class="card-body">
                            <p class="text-muted small mb-3">HTTP and TCP service check results for the selected range.</p>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-services-format">Format</label>
                                    <select id="dl-services-format" class="form-select" name="format">
                                        <option value="csv">CSV</option>
                                        <option value="json">JSON</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-services-range">Range</label>
                                    <select id="dl-services-range" class="form-select" name="history">
                                        <option value="24h">24h</option>
                                        <option value="7d">7d</option>
                                        <option value="30d">30d</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-services-server">Server</label>
                                    <select id="dl-services-server" class="form-select" name="server_id">
                                        <option value="0">All</option>
                                        <?php foreach ($servers as $server): ?>
                                            <option value="<?= e((string) $server['id']) ?>"><?= e((string) $server['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 mt-auto">
                            <button class="btn btn-primary w-100" type="submit" data-submit-loading data-loading-text="Preparing..."><i class="ti ti-download me-1" aria-hidden="true"></i>Download Service Metrics</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card card-neon h-100">
                    <div class="card-header bg-surface-2 border-soft">
                        <h3 class="h6 mb-0"><i class="ti ti-shield-check me-1" aria-hidden="true"></i>Export Admin Audit Logs</h3>
                    </div>
                    <form method="get" class="d-flex flex-column flex-grow-1">
                        <input type="hidden" name="type" value="audits">
                        <div class="card-body">
                            <p class="text-muted small mb-3">Admin activity with optional user, action, and date filters.</p>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-audits-format">Format</label>
                                    <select id="dl-audits-format" class="form-select" name="format">
                                        <option value="csv">CSV</option>
                                        <option value="json">JSON</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-audits-user">User</label>
                                    <select id="dl-audits-user" class="form-select" name="user_id">
                                        <option value="0">All</option>
                                        <?php foreach ($users as $u): ?>
                                            <option value="<?= e((string) $u['id']) ?>"><?= e((string) $u['username']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="dl-audits-action">Action Type</label>
                                    <input id="dl-audits-action" class="form-control" name="action_type" placeholder="optional">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="dl-audits-from">Date From</label>
                                    <input id="dl-audits-from" class="form-control" type="date" name="date_from">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="dl-audits-to">Date To</label>
                                    <input id="dl-audits-to" class="form-control" type="date" name="date_to">
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 mt-auto">
                            <button class="btn btn-primary w-100" type="submit" data-submit-loading data-loading-text="Preparing..."><i class="ti ti-download me-1" aria-hidden="true"></i>Download Audit Logs</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
