<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title"><i class="ti ti-disc me-2 text-info" aria-hidden="true"></i>Disk Health Detail</h1>
            <ul class="page-subtitle server-meta-list mb-0" aria-label="Server metadata">
                <li class="server-meta-chip">
                    <span class="server-meta-key">Server</span>
                    <span class="server-meta-value"><?= e((string) ($server['name'] ?? '-')) ?></span>
                </li>
                <li class="server-meta-chip">
                    <span class="server-meta-key">Host</span>
                    <span class="server-meta-value"><?= e((string) ($server['host'] ?? '-')) ?></span>
                </li>
                <li class="server-meta-chip">
                    <span class="server-meta-key">Location</span>
                    <span class="server-meta-value"><?= e((string) ($server['location'] ?? '-')) ?></span>
                </li>
            </ul>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft btn-sm" href="<?= e(app_url('disk-health')) ?>">
                <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Back to Disk Health
            </a>
            <a class="btn btn-outline-info btn-sm" href="<?= e(app_url('servers/' . (int) $server['id'])) ?>">
                <i class="ti ti-server-2 me-1" aria-hidden="true"></i>Server Detail
            </a>
        </div>
    </section>

    <?php if ($diskHealthLoadError !== ''): ?>
        <div class="alert alert-warning mb-0"><?= e($diskHealthLoadError) ?></div>
    <?php endif; ?>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0"><i class="ti ti-table me-2 text-info" aria-hidden="true"></i>Disk Health Detail</h2>
            <small class="text-secondary">
                <i class="ti ti-device-imac me-1" aria-hidden="true"></i><?= e((string) count($diskHealthRows)) ?> disk(s)
            </small>
        </div>
        <div class="table-responsive table-shell" data-ui-table>
            <table class="table monitors-table mb-0">
                <thead>
                <tr>
                    <th>Device</th>
                    <th>Model</th>
                    <th>Serial</th>
                    <th>Health</th>
                    <th>Score</th>
                    <th>Temp</th>
                    <th>POT</th>
                    <th>TBW</th>
                    <th>Updated At</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($diskHealthRows)): ?>
                    <tr><td colspan="9" class="table-empty">No disk health data yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($diskHealthRows as $disk): ?>
                    <?php
                    $healthStatus = strtolower(trim((string) ($disk['health_status'] ?? 'unknown')));
                    $healthBadgeClass = (string) ($disk['health_badge_class'] ?? 'badge-severity badge-severity-info');
                    $devicePrimary = (string) ($disk['device_primary'] ?? '-');
                    $deviceMeta = (string) ($disk['device_meta'] ?? '');
                    ?>
                    <tr>
                        <td>
                            <code><?= e($devicePrimary) ?></code>
                            <?php if ($deviceMeta !== ''): ?>
                                <div class="small text-secondary mt-1"><?= e($deviceMeta) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) ($disk['model'] ?? '-')) ?></td>
                        <td><?= e((string) ($disk['serial'] ?? '-')) ?></td>
                        <td><span class="badge <?= e($healthBadgeClass) ?> text-uppercase"><?= e($healthStatus) ?></span></td>
                        <td class="font-mono"><?= e((string) ($disk['health_pct'] ?? '-')) ?></td>
                        <td class="font-mono"><?= e((string) ($disk['temperature_text'] ?? '-')) ?></td>
                        <td class="font-mono"><?= e((string) ($disk['power_on_time'] ?? '-')) ?></td>
                        <td class="font-mono"><?= e((string) ($disk['tbw'] ?? '-')) ?></td>
                        <td><?= e((string) ($disk['updated_at'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script<?= csp_nonce_attr() ?>>window.MONITORS_AUTO_REFRESH_MS = 30000;</script>
<script src="<?= e(asset_url('assets/js/auto-refresh.js')) ?>"></script>
