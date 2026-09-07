<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Export Data</h1>
            <p class="page-subtitle">Download alerts, metrics, service checks, and audit logs in CSV or JSON.</p>
        </div>
    </section>
    <section class="card card-neon mb-3" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">Large Export Queue</div>
        <div class="card-body">
            <p class="text-muted mb-3">Use the queue for large datasets to prevent browser request timeouts. The worker processes up to two jobs per cycle.</p>
            <form method="post" class="row g-2 align-items-end">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="queue_export">
                <div class="col-md-4">
                    <label class="form-label" for="queue-export-type">Dataset</label>
                    <select id="queue-export-type" class="form-select" name="type">
                        <option value="alerts">Alert Logs</option>
                        <option value="metrics">Metrics</option>
                        <option value="services">Service Metrics</option>
                        <option value="audits">Audit Logs</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="queue-export-format">Format</label>
                    <select id="queue-export-format" class="form-select" name="format">
                        <option value="csv">CSV</option>
                        <option value="json">JSON</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary" type="submit" data-submit-loading data-loading-text="Queuing...">Queue Large Export</button>
                </div>
            </form>
        </div>
    </section>
    <section class="card card-neon mb-3" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">Recent Export Jobs</div>
        <div class="table-responsive">
            <table class="table servmon-table table-hover mb-0 align-middle">
                <thead><tr><th>ID</th><th>Dataset</th><th>Format</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($exportJobs as $job): ?>
                    <tr>
                        <td>#<?= e((string) $job['id']) ?></td>
                        <td><?= e((string) $job['export_type']) ?></td>
                        <td><?= e(strtoupper((string) $job['export_format'])) ?></td>
                        <td><span class="badge badge-severity badge-severity-<?= e($job['status'] === 'completed' ? 'success' : ($job['status'] === 'failed' ? 'danger' : 'info')) ?>"><?= e((string) $job['status']) ?></span></td>
                        <td><?= e((string) $job['created_at']) ?></td>
                        <td><?php if ($job['status'] === 'completed'): ?><a class="btn btn-sm btn-outline-info" href="<?= e(app_url('export/download/' . (int) $job['id'])) ?>">Download</a><?php elseif ($job['status'] === 'failed'): ?><?= e((string) ($job['error_message'] ?? 'Failed')) ?><?php else: ?>Waiting for worker<?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$exportJobs): ?><tr><td colspan="6" class="text-muted">No asynchronous exports yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="row g-3" data-ui-section>
        <div class="col-lg-6">
            <div class="card card-neon">
                <div class="card-header bg-surface-2 border-soft">Export Alert Logs</div>
                <div class="card-body">
                    <form method="get" class="row g-2">
                        <input type="hidden" name="type" value="alerts">
                        <div class="col-md-4">
                            <label class="form-label">Format</label>
                            <select class="form-select" name="format">
                                <option value="csv">CSV</option>
                                <option value="json">JSON</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Severity</label>
                            <select class="form-select" name="severity">
                                <option value="">All</option>
                                <option value="info">info</option>
                                <option value="warning">warning</option>
                                <option value="danger">danger</option>
                                <option value="success">success</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Server</label>
                            <select class="form-select" name="server_id">
                                <option value="0">All</option>
                                <?php foreach ($servers as $server): ?>
                                    <option value="<?= e((string) $server['id']) ?>"><?= e((string) $server['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Preparing...">Download Alert Logs</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card card-neon">
                <div class="card-header bg-surface-2 border-soft">Export Metrics</div>
                <div class="card-body">
                    <form method="get" class="row g-2">
                        <input type="hidden" name="type" value="metrics">
                        <div class="col-md-4">
                            <label class="form-label">Format</label>
                            <select class="form-select" name="format">
                                <option value="csv">CSV</option>
                                <option value="json">JSON</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Range</label>
                            <select class="form-select" name="history">
                                <option value="24h">24h</option>
                                <option value="7d">7d</option>
                                <option value="30d">30d</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Server</label>
                            <select class="form-select" name="server_id">
                                <option value="0">All</option>
                                <?php foreach ($servers as $server): ?>
                                    <option value="<?= e((string) $server['id']) ?>"><?= e((string) $server['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Preparing...">Download Metrics</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card card-neon">
                <div class="card-header bg-surface-2 border-soft">Export Service Metrics</div>
                <div class="card-body">
                    <form method="get" class="row g-2">
                        <input type="hidden" name="type" value="services">
                        <div class="col-md-4">
                            <label class="form-label">Format</label>
                            <select class="form-select" name="format">
                                <option value="csv">CSV</option>
                                <option value="json">JSON</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Range</label>
                            <select class="form-select" name="history">
                                <option value="24h">24h</option>
                                <option value="7d">7d</option>
                                <option value="30d">30d</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Server</label>
                            <select class="form-select" name="server_id">
                                <option value="0">All</option>
                                <?php foreach ($servers as $server): ?>
                                    <option value="<?= e((string) $server['id']) ?>"><?= e((string) $server['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Preparing...">Download Service Metrics</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card card-neon">
                <div class="card-header bg-surface-2 border-soft">Export Admin Audit Logs</div>
                <div class="card-body">
                    <form method="get" class="row g-2">
                        <input type="hidden" name="type" value="audits">
                        <div class="col-md-4">
                            <label class="form-label">Format</label>
                            <select class="form-select" name="format">
                                <option value="csv">CSV</option>
                                <option value="json">JSON</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">User</label>
                            <select class="form-select" name="user_id">
                                <option value="0">All</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= e((string) $u['id']) ?>"><?= e((string) $u['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Action Type</label>
                            <input class="form-control" name="action_type" placeholder="optional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date From</label>
                            <input class="form-control" type="date" name="date_from">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date To</label>
                            <input class="form-control" type="date" name="date_to">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Preparing...">Download Audit Logs</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
