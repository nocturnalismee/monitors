<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title"><i class="ti ti-disc me-2 text-info" aria-hidden="true"></i>Disk Health</h1>
            <p class="page-subtitle">Disk condition summary per server. Click a row to open detail.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft btn-sm" href="<?= e(app_url('agents/agent-disk-health.sh')) ?>" target="_blank">
                <i class="ti ti-download me-1" aria-hidden="true"></i>Download Agent
            </a>
            <?php if ($includeInactive): ?>
                <a class="btn btn-outline-info btn-sm" href="<?= e(app_url('disk-health')) ?>">
                    <i class="ti ti-filter me-1" aria-hidden="true"></i>Active Only
                </a>
            <?php else: ?>
                <a class="btn btn-outline-info btn-sm" href="<?= e(app_url('disk-health?include_inactive=1')) ?>">
                    <i class="ti ti-filter-cog me-1" aria-hidden="true"></i>Include Inactive
                </a>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($loadError !== ''): ?>
        <div class="alert alert-warning mb-0"><?= e($loadError) ?></div>
    <?php elseif (empty($summaryRows)): ?>
        <div class="alert alert-info mb-0">
            No disk health data yet. Install and run <code>agents/agent-disk-health.sh</code> on target servers
            with <code>SERVER_TOKEN</code>, <code>SERVER_ID</code>, and <code>MASTER_URL=/api/push-disk</code>.
        </div>
    <?php endif; ?>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0"><i class="ti ti-table me-2 text-info" aria-hidden="true"></i>Disk Health Summary</h2>
            <small class="text-secondary">
                <i class="ti ti-server me-1" aria-hidden="true"></i><?= e((string) count($summaryRows)) ?> server(s)
            </small>
        </div>
        <div class="table-responsive table-shell" data-ui-table>
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th>Server</th>
                    <th>Primary Disk</th>
                    <th>Disk Count</th>
                    <th>Avg Health</th>
                    <th>Avg POT</th>
                    <th>Avg TBW</th>
                    <th>Updated At</th>
                </tr>
                </thead>
                <tbody data-disk-health-table>
                <?php if (empty($summaryRows)): ?>
                    <tr><td colspan="7" class="table-empty">No disk health data yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($summaryRows as $row): ?>
                    <?php
                    $serverId = (int) ($row['server_id'] ?? 0);
                    $diskCount = (int) ($row['disk_count_int'] ?? 0);
                    $diskLabel = (string) ($row['disk_label'] ?? '-');
                    $detailUrl = (string) ($row['detail_url'] ?? '');
                    ?>
                    <tr
                        <?php if ($detailUrl !== ''): ?>
                            class="dashboard-row-link"
                            data-detail-url="<?= e($detailUrl) ?>"
                            tabindex="0"
                            role="link"
                            aria-label="Open disk health details for <?= e((string) ($row['server_name'] ?? 'server')) ?>"
                        <?php endif; ?>
                    >
                        <td><?= e((string) ($row['server_name'] ?? '-')) ?></td>
                        <td><?= e($diskLabel) ?></td>
                        <td class="font-mono"><?= e((string) $diskCount) ?></td>
                        <td class="font-mono"><?= e((string) ($row['health_pct'] ?? '-')) ?></td>
                        <td class="font-mono"><?= e((string) ($row['power_on_time'] ?? '-')) ?></td>
                        <td class="font-mono"><?= e((string) ($row['tbw'] ?? '-')) ?></td>
                        <td><?= e((string) ($row['last_update'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script<?= csp_nonce_attr() ?>>
document.addEventListener("DOMContentLoaded", function () {
  window.ServMon.wireRowNavigation("[data-disk-health-table]");
});
</script>
<script<?= csp_nonce_attr() ?>>window.SERVMON_AUTO_REFRESH_MS = 30000;</script>
<script src="<?= e(asset_url('assets/js/auto-refresh.js')) ?>"></script>
