<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Server Management</h1>
            <p class="page-subtitle">Manage inventory, status visibility, and operational actions.</p>
        </div>
        <?php if ($canManageServers): ?>
            <div class="toolbar-actions">
                <a href="<?= e(app_url('servers/add')) ?>" class="btn btn-info"><i class="ti ti-plus me-1"></i>Add Server</a>
            </div>
        <?php endif; ?>
    </section>
    <?php if (!$canManageServers): ?>
        <div class="alert alert-info">Viewer mode: server changes are restricted to admin users.</div>
    <?php endif; ?>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <h2 class="h6 mb-0">Servers</h2>
            <span class="text-secondary small" data-server-result-count></span>
        </div>
        <div class="server-list-toolbar" role="search">
            <div class="server-list-search">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input class="form-control" type="search" placeholder="Search name, host, location, provider..." aria-label="Search servers" data-server-search>
            </div>
            <select class="form-select server-status-filter" aria-label="Filter server status" data-server-status-filter>
                <option value="all">All statuses</option>
                <option value="online">Online</option>
                <option value="down">Down</option>
                <option value="pending">Pending</option>
            </select>
            <button class="btn btn-outline-light" type="button" data-server-filter-reset>
                <i class="ti ti-refresh me-1" aria-hidden="true"></i>Reset
            </button>
        </div>
        <?php if ($canManageServers): ?>
        <div class="bulk-action-bar" data-bulk-bar hidden>
            <span class="small text-secondary" data-bulk-count>0 selected</span>
            <form method="post" data-server-bulk-form class="d-flex flex-wrap gap-2">
                <?= csrf_input() ?>
                <div data-bulk-ids hidden></div>
                <button type="submit" name="action" value="batch_enable" class="btn btn-sm btn-soft" data-bulk-submit>
                    <i class="ti ti-player-play me-1" aria-hidden="true"></i>Enable
                </button>
                <button type="submit" name="action" value="batch_disable" class="btn btn-sm btn-soft text-warning" data-bulk-submit>
                    <i class="ti ti-player-pause me-1" aria-hidden="true"></i>Disable
                </button>
                <button type="submit" name="action" value="batch_delete" class="btn btn-sm btn-soft text-danger" data-bulk-submit>
                    <i class="ti ti-trash me-1" aria-hidden="true"></i>Delete
                </button>
            </form>
        </div>
        <?php endif; ?>
        <div class="table-responsive table-shell ping-table-shell ping-table-responsive" data-ui-table>
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <?php if ($canManageServers): ?>
                    <th class="servmon-checkbox-cell"><input type="checkbox" class="form-check-input" data-server-checkall aria-label="Select all servers"></th>
                    <?php endif; ?>
                    <th>Name</th>
                    <th>Host</th>
                    <th>Location</th>
                    <th>Provider</th>
                    <th>Label</th>
                    <th>Type</th>
                    <th>Panel</th>
                    <th>Service Summary</th>
                    <th>Maintenance</th>
                    <th>Status</th>
                    <th>Last Seen</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr data-server-empty><td colspan="<?= $canManageServers ? 13 : 12 ?>" class="table-empty">
                        <div class="table-empty-inner">
                            <span>No servers available yet.</span>
                            <?php if ($canManageServers): ?>
                                <a href="<?= e(app_url('servers/add')) ?>" class="btn btn-sm btn-info mt-2"><i class="ti ti-plus me-1" aria-hidden="true"></i>Add your first server</a>
                            <?php endif; ?>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <tr data-server-filter-empty hidden><td colspan="<?= $canManageServers ? 13 : 12 ?>" class="table-empty">
                    <div class="table-empty-inner">
                        <span>No servers match the current filter.</span>
                        <button type="button" class="btn btn-sm btn-outline-light mt-2" data-server-filter-reset><i class="ti ti-refresh me-1" aria-hidden="true"></i>Reset filters</button>
                    </div>
                </td></tr>
                <?php foreach ($rows as $row): ?>
                    <?php $status = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes); ?>
                    <tr data-server-row data-server-status="<?= e($status) ?>" data-server-search="<?= e(strtolower(implode(' ', array_map(static fn ($value): string => (string) ($value ?? ''), [$row['name'], $row['host'], $row['location'], $row['provider'], $row['label'], $row['type'], $row['panel_profile']])) )) ?>">
                        <?php if ($canManageServers): ?>
                        <td class="servmon-checkbox-cell">
                            <input type="checkbox" class="form-check-input" name="server_ids[]" value="<?= e((string) $row['id']) ?>" data-server-checkbox aria-label="Select <?= e((string) $row['name']) ?>">
                        </td>
                        <?php endif; ?>
                        <td>
                            <span class="table-cell-truncate" title="<?= e((string) $row['name']) ?>">
                                <?= e((string) $row['name']) ?>
                            </span>
                        </td>
                        <td><?= e($row['host'] ?? '-') ?></td>
                        <td><?= e($row['location'] ?? '-') ?></td>
                        <td><?= e($row['provider'] ?? '-') ?></td>
                        <td><?= e($row['label'] ?? '-') ?></td>
                        <td><?= e($row['type'] ?? '-') ?></td>
                        <td><code><?= e((string) ($row['panel_profile'] ?? 'generic')) ?></code></td>
                        <?php
                        $upCount = max(0, (int) ($row['service_up_count'] ?? 0));
                        $downCount = max(0, (int) ($row['service_down_count'] ?? 0));
                        $unknownCount = max(0, (int) ($row['service_unknown_count'] ?? 0));
                        $totalServices = $upCount + $downCount + $unknownCount;
                        if ($status === 'down' && $totalServices > 0) {
                            $upCount = 0;
                            $downCount = $totalServices;
                            $unknownCount = 0;
                        }
                        ?>
                        <td>
                            <?php if ($downCount > 0 || $unknownCount > 0): ?>
                                <?php if ($downCount > 0): ?>
                                    <span class="text-danger fw-semibold me-2"><i class="ti ti-arrow-down-circle me-1" aria-label="down"></i><?= e((string) $downCount) ?></span>
                                <?php endif; ?>
                                <?php if ($unknownCount > 0): ?>
                                    <span class="text-warning fw-semibold"><i class="ti ti-help-circle me-1" aria-label="unknown"></i><?= e((string) $unknownCount) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-success fw-semibold"><i class="ti ti-arrow-up-circle me-1" aria-label="up"></i><?= e((string) $upCount) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(maintenance_display_text($row)) ?></td>
                        <td><span class="badge <?= e('badge-' . $status) ?> text-uppercase"><?= e($status) ?></span></td>
                        <td class="font-mono"><?= e($row['last_seen'] ?? '-') ?></td>
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
                                        <a class="dropdown-item" href="<?= e(app_url('servers/' . (int) $row['id'])) ?>">
                                            <i class="ti ti-eye me-2" aria-hidden="true"></i>Details
                                        </a>
                                    </li>
                                    <?php if ($canManageServers): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= e(app_url('servers/' . (int) $row['id'] . '/edit')) ?>">
                                                <i class="ti ti-pencil me-2" aria-hidden="true"></i>Edit
                                            </a>
                                        </li>
                                        <li>
                                            <form method="post" class="m-0">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="server_id" value="<?= e((string) $row['id']) ?>">
                                                <button class="dropdown-item text-warning" type="submit" data-submit-loading data-loading-text="Updating...">
                                                    <i class="ti ti-player-pause me-2" aria-hidden="true"></i><?= (int) $row['active'] === 1 ? 'Disable' : 'Enable' ?>
                                                </button>
                                            </form>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="post" class="m-0">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="server_id" value="<?= e((string) $row['id']) ?>">
                                                <button class="dropdown-item text-danger" type="submit" data-confirm="Delete this server and all related metrics?" data-submit-loading data-loading-text="Deleting...">
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
        <div class="server-list-footer">
            <span class="text-secondary small" data-server-filter-summary></span>
            <nav aria-label="Server pagination">
                <ul class="pagination pagination-sm mb-0" data-server-pagination></ul>
            </nav>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/servers.js')) ?>"></script>
