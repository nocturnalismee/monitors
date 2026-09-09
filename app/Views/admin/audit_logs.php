<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Admin Audit Logs</h1>
            <p class="page-subtitle">Track privileged actions, actor identity, target, and request context.</p>
        </div>
        <div class="toolbar-actions">
            <?php
            $q = http_build_query([
                'action_type' => $filterAction,
                'user_id' => $filterUserId,
                'date_from' => $filterDateFrom,
                'date_to' => $filterDateTo,
            ]);
            ?>
            <a href="<?= e(app_url('export?type=audits&format=csv&' . $q)) ?>" class="btn btn-outline-success btn-sm">Export CSV</a>
            <a href="<?= e(app_url('export?type=audits&format=json&' . $q)) ?>" class="btn btn-outline-info btn-sm">Export JSON</a>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="filter-audit-action">Action Type</label>
                    <select class="form-select" name="action_type" id="filter-audit-action">
                        <option value="">All</option>
                        <?php foreach ($actionTypes as $row): ?>
                            <?php $action = (string) ($row['action_type'] ?? ''); ?>
                            <option value="<?= e($action) ?>" <?= $filterAction === $action ? 'selected' : '' ?>><?= e($action) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="filter-audit-user">User</label>
                    <select class="form-select" name="user_id" id="filter-audit-user">
                        <option value="0">All</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= e((string) $u['id']) ?>" <?= $filterUserId === (int) $u['id'] ? 'selected' : '' ?>><?= e((string) $u['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="filter-audit-from">From</label>
                    <input class="form-control" type="date" name="date_from" id="filter-audit-from" value="<?= e($filterDateFrom) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="filter-audit-to">To</label>
                    <input class="form-control" type="date" name="date_to" id="filter-audit-to" value="<?= e($filterDateTo) ?>">
                </div>
                <div class="col-md-2 d-flex gap-2 align-items-end">
                    <button class="btn btn-info w-100" type="submit">Filter</button>
                    <a class="btn btn-outline-light" href="<?= e(app_url('audit-logs')) ?>">Reset</a>
                </div>
            </form>
        </div>
    </section>

    <section class="card card-neon" data-ui-section data-bulk-select data-bulk-input-name="audit_ids[]" data-bulk-confirm="Delete {n} selected audit log(s)? This cannot be undone.">
        <div class="bulk-action-bar" data-bulk-bar hidden>
            <span class="small text-secondary" data-bulk-count>0 selected</span>
            <form method="post" data-bulk-form class="d-flex flex-wrap gap-2">
                <?= csrf_input() ?>
                <div data-bulk-ids hidden></div>
                <button type="submit" name="action" value="batch_delete" class="btn btn-sm btn-soft text-danger" data-bulk-submit>
                    <i class="ti ti-trash me-1" aria-hidden="true"></i>Delete
                </button>
            </form>
        </div>
        <div class="table-responsive table-shell" data-ui-table>
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th class="servmon-checkbox-cell"><input type="checkbox" class="form-check-input" data-bulk-checkall aria-label="Select all audit logs"></th>
                    <th>ID</th>
                    <th>Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Detail</th>
                    <th>Target</th>
                    <th>IP</th>
                    <th class="text-end">Context</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="table-empty">No audit data yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $targetText = trim((string) ($row['target_type'] ?? '')) !== '' ? ((string) $row['target_type'] . '#' . (string) ($row['target_id'] ?? '-')) : '-'; ?>
                    <tr>
                        <td class="servmon-checkbox-cell">
                            <input type="checkbox" class="form-check-input" name="audit_ids[]" value="<?= e((string) $row['id']) ?>" data-bulk-checkbox aria-label="Select audit log <?= e((string) $row['id']) ?>">
                        </td>
                        <td class="font-mono"><?= e((string) $row['id']) ?></td>
                        <td class="font-mono"><?= e((string) $row['created_at']) ?></td>
                        <td><?= e((string) ($row['username'] ?? 'system')) ?></td>
                        <td><code><?= e((string) $row['action_type']) ?></code></td>
                        <td><?= e((string) $row['action_detail']) ?></td>
                        <td><?= e($targetText) ?></td>
                        <td><?= e((string) ($row['ip_address'] ?? '-')) ?></td>
                        <td class="text-end">
                            <button
                                class="btn btn-sm btn-outline-info"
                                type="button"
                                data-bs-toggle="modal"
                                data-bs-target="#auditContextModal"
                                data-audit-context="<?= e((string) ($row['context_json'] ?? '{}')) ?>"
                            >
                                View
                            </button>
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
                    $base = app_url('audit-logs?action_type=' . urlencode($filterAction) . '&user_id=' . $filterUserId . '&date_from=' . urlencode($filterDateFrom) . '&date_to=' . urlencode($filterDateTo) . '&page=');
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

<div class="modal fade" id="auditContextModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-surface border-soft">
            <div class="modal-header">
                <h5 class="modal-title">Audit Context</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <pre class="bg-surface-2 rounded p-3 border-soft mb-0"><code id="auditContextContent">{}</code></pre>
            </div>
        </div>
    </div>
</div>

<script<?= csp_nonce_attr() ?>>
  const auditModal = document.getElementById('auditContextModal');
  if (auditModal) {
    auditModal.addEventListener('show.bs.modal', (event) => {
      const btn = event.relatedTarget;
      const contextRaw = btn.getAttribute('data-audit-context') || '{}';
      let contextPretty = contextRaw;
      try {
        contextPretty = JSON.stringify(JSON.parse(contextRaw), null, 2);
      } catch (e) {}
      document.getElementById('auditContextContent').textContent = contextPretty;
    });
  }
</script>
<script src="<?= e(asset_url('assets/js/bulk-select.js')) ?>"></script>
