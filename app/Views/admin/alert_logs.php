<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Alert Logs</h1>
            <p class="page-subtitle">Review incident events, severity, and delivery channel status.</p>
        </div>
        <div class="toolbar-actions">
            <form method="post" class="d-inline-flex">
                <?= csrf_input() ?>
                <button class="btn btn-outline-success btn-sm" type="submit" name="action" value="acknowledge_all" data-submit-loading data-loading-text="Updating...">
                    <i class="ti ti-checks me-1" aria-hidden="true"></i>Mark all as read
                </button>
            </form>
            <?php
            $q = http_build_query([
                'alert_type' => $filterType,
                'severity' => $filterSeverity,
                'status' => $filterStatus,
                'server_id' => $filterServerId,
            ]);
            ?>
            <a href="<?= e(app_url('export?type=alerts&format=csv&' . $q)) ?>" class="btn btn-outline-success btn-sm">Export CSV</a>
            <a href="<?= e(app_url('export?type=alerts&format=json&' . $q)) ?>" class="btn btn-outline-info btn-sm">Export JSON</a>
        </div>
    </section>

    <?php $filterSearchVal = (string) ($filterSearch ?? ''); ?>
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div class="alert-chip-group">
            <?php $qSuffix = $filterSearchVal !== '' ? '&q=' . urlencode($filterSearchVal) : ''; ?>
            <a href="<?= e(app_url('alerts' . ($filterSearchVal !== '' ? '?q=' . urlencode($filterSearchVal) : ''))) ?>" class="alert-chip-btn <?= ($filterStatus === '' && $filterSeverity === '') ? 'active' : '' ?>">All Alerts</a>
            <a href="<?= e(app_url('alerts?status=active' . $qSuffix)) ?>" class="alert-chip-btn <?= ($filterStatus === 'active' && $filterSeverity === '') ? 'active' : '' ?>"><span class="alert-pulsing-dot me-1"></span>Active Incidents</a>
            <a href="<?= e(app_url('alerts?severity=danger' . $qSuffix)) ?>" class="alert-chip-btn text-danger <?= $filterSeverity === 'danger' ? 'active' : '' ?>"><i class="ti ti-flame me-1"></i>Danger</a>
            <a href="<?= e(app_url('alerts?severity=warning' . $qSuffix)) ?>" class="alert-chip-btn text-warning <?= $filterSeverity === 'warning' ? 'active' : '' ?>"><i class="ti ti-alert-triangle me-1"></i>Warning</a>
            <a href="<?= e(app_url('alerts?status=acknowledged' . $qSuffix)) ?>" class="alert-chip-btn <?= $filterStatus === 'acknowledged' ? 'active' : '' ?>">Acknowledged</a>
            <a href="<?= e(app_url('alerts?status=resolved' . $qSuffix)) ?>" class="alert-chip-btn <?= $filterStatus === 'resolved' ? 'active' : '' ?>">Resolved</a>
        </div>
        <form method="get" class="alert-search-form" role="search" aria-label="Search alerts">
            <?php if ($filterType !== ''): ?><input type="hidden" name="type" value="<?= e($filterType) ?>"><?php endif; ?>
            <?php if ($filterSeverity !== ''): ?><input type="hidden" name="severity" value="<?= e($filterSeverity) ?>"><?php endif; ?>
            <?php if ($filterStatus !== ''): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
            <?php if ($filterServerId > 0): ?><input type="hidden" name="server_id" value="<?= e((string) $filterServerId) ?>"><?php endif; ?>
            <div class="alert-search-wrap">
                <i class="ti ti-search alert-search-icon" aria-hidden="true"></i>
                <input type="search" name="q" value="<?= e($filterSearchVal) ?>" placeholder="Search title, server, type..." class="form-control form-control-sm alert-search-input" aria-label="Search alerts">
                <?php if ($filterSearchVal !== ''): ?>
                    <a href="<?= e(app_url('alerts?' . http_build_query(array_filter(['type' => $filterType, 'severity' => $filterSeverity, 'status' => $filterStatus, 'server_id' => $filterServerId ?: null])))) ?>" class="alert-search-clear" aria-label="Clear search"><i class="ti ti-x"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <section class="card card-neon" data-ui-section>
        <div class="table-responsive table-shell ping-table-shell ping-table-responsive alert-table-shell" data-ui-table>
            <table class="table servmon-table alert-log-table mb-0">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Time</th>
                    <th>Server</th>
                    <th>Type</th>
                    <th>Severity</th>
                    <th>Status</th>
                    <th>Title</th>
                    <th>Channel</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="table-empty">No alert data yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $sev = (string) $row['severity'];
                    $sevClass = match ($sev) {
                        'danger' => 'badge-severity badge-severity-danger',
                        'warning' => 'badge-severity badge-severity-warning',
                        'success' => 'badge-severity badge-severity-success',
                        default => 'badge-severity badge-severity-info',
                    };
                    $contextJson = (string) ($row['context_json'] ?? '');
                    $rowStatus = (string) ($row['status'] ?? 'active');
                    $isStale = $rowStatus === 'active' && strtotime((string) ($row['created_at'] ?? 'now')) < (time() - 86400);
                    $statusLabel = $isStale ? 'Stale' : match ($rowStatus) {
                        'acknowledged' => 'Acknowledged',
                        'resolved' => 'Resolved',
                        'silenced' => 'Snoozed',
                        default => 'New',
                    };
                    $statusTone = $isStale ? 'badge-severity-warning' : match ($rowStatus) {
                        'resolved' => 'badge-severity-success',
                        'acknowledged' => 'badge-severity-info',
                        'silenced' => 'badge-severity-warning',
                        default => 'badge-severity-danger',
                    };
                    ?>
                    <tr>
                        <td class="font-mono" data-label="ID"><?= e((string) $row['id']) ?></td>
                        <td class="font-mono" data-label="Time"><?= e((string) $row['created_at']) ?></td>
                        <td data-label="Server"><?= e((string) ($row['server_name'] ?? 'global')) ?></td>
                        <td data-label="Type"><code><?= e((string) $row['alert_type']) ?></code></td>
                        <td data-label="Severity"><span class="badge <?= e($sevClass) ?> text-uppercase"><?= e($sev) ?></span></td>
                        <td data-label="Status"><span class="badge badge-severity <?= e($statusTone) ?> text-uppercase"><?= e($statusLabel) ?></span></td>
                        <td data-label="Title"><?= e((string) $row['title']) ?></td>
                        <td data-label="Channel">
                            <span class="badge badge-channel <?= (int) $row['sent_email'] === 1 ? 'badge-channel-on' : 'badge-channel-off' ?>">Email</span>
                            <span class="badge badge-channel <?= (int) $row['sent_telegram'] === 1 ? 'badge-channel-on' : 'badge-channel-off' ?>">Telegram</span>
                        </td>
                        <td class="text-end alert-actions-cell" data-label="Actions">
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-sm btn-outline-light" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Alert actions">
                                    <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#alertDetailModal" data-alert-title="<?= e((string) $row['title']) ?>" data-alert-message="<?= e((string) $row['message']) ?>" data-alert-context="<?= e($contextJson) ?>">
                                            <i class="ti ti-eye me-2" aria-hidden="true"></i>View details
                                        </button>
                                    </li>
                                    <?php if ($rowStatus !== 'resolved'): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="post" class="m-0">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="alert_id" value="<?= e((string) $row['id']) ?>">
                                                <button class="dropdown-item text-info" name="action" value="acknowledge" type="submit" data-submit-loading data-loading-text="Updating...">
                                                    <i class="ti ti-check me-2" aria-hidden="true"></i>Acknowledge
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="post" class="m-0">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="alert_id" value="<?= e((string) $row['id']) ?>">
                                                <input type="hidden" name="snooze_minutes" value="60">
                                                <button class="dropdown-item text-warning" name="action" value="silence" type="submit" data-submit-loading data-loading-text="Updating...">
                                                    <i class="ti ti-bell-off me-2" aria-hidden="true"></i>Snooze 1 hour
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="post" class="m-0">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="alert_id" value="<?= e((string) $row['id']) ?>">
                                                <input type="hidden" name="snooze_minutes" value="240">
                                                <button class="dropdown-item text-warning" name="action" value="silence" type="submit" data-submit-loading data-loading-text="Updating...">
                                                    <i class="ti ti-bell-off me-2" aria-hidden="true"></i>Snooze 4 hours
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="post" class="m-0">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="alert_id" value="<?= e((string) $row['id']) ?>">
                                                <input type="hidden" name="snooze_minutes" value="1440">
                                                <button class="dropdown-item text-warning" name="action" value="silence" type="submit" data-submit-loading data-loading-text="Updating...">
                                                    <i class="ti ti-bell-off me-2" aria-hidden="true"></i>Snooze 24 hours
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="post" class="m-0">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="alert_id" value="<?= e((string) $row['id']) ?>">
                                                <button class="dropdown-item text-success" name="action" value="resolve" type="submit" data-submit-loading data-loading-text="Updating...">
                                                    <i class="ti ti-checks me-2" aria-hidden="true"></i>Resolve
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

        <div class="card-footer bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <div class="text-secondary small">Total: <?= e((string) $total) ?> logs</div>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $qParam = isset($filterSearchVal) ? (string) $filterSearchVal : (string) ($filterSearch ?? '');
                    $base = app_url('alerts?type=' . urlencode($filterType) . '&severity=' . urlencode($filterSeverity) . '&status=' . urlencode($filterStatus) . '&server_id=' . $filterServerId . '&q=' . urlencode($qParam) . '&page=');
                    ?>
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($base . max(1, $page - 1)) ?>">Prev</a>
                    </li>
                    <li class="page-item disabled"><span class="page-link"><?= e((string) $page) ?>/<?= e((string) $totalPages) ?></span></li>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($base . min($totalPages, $page + 1)) ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    </section>
</main>

<div class="modal fade" id="alertDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-surface border-soft">
            <div class="modal-header">
                <h5 class="modal-title" id="alertDetailTitle">Alert Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3" id="alertDetailMessage"></p>
                <h6>Context JSON</h6>
                <pre class="bg-surface-2 rounded p-3 border-soft mb-0"><code id="alertDetailContext">{}</code></pre>
            </div>
        </div>
    </div>
</div>

<script>
  const modal = document.getElementById('alertDetailModal');
  if (modal) {
    modal.addEventListener('show.bs.modal', (event) => {
      const btn = event.relatedTarget;
      const title = btn.getAttribute('data-alert-title') || 'Alert Details';
      const message = btn.getAttribute('data-alert-message') || '';
      const contextRaw = btn.getAttribute('data-alert-context') || '{}';
      let contextPretty = contextRaw;
      try {
        contextPretty = JSON.stringify(JSON.parse(contextRaw), null, 2);
      } catch (e) {}
      document.getElementById('alertDetailTitle').textContent = title;
      document.getElementById('alertDetailMessage').textContent = message;
      document.getElementById('alertDetailContext').textContent = contextPretty;
    });
  }
</script>
