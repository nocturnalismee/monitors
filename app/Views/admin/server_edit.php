<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Edit Server</h1>
            <p class="page-subtitle">Update connectivity, notification, and maintenance configuration.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('servers')) ?>">Back to Servers</a>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0">Server Configuration</h2>
        </div>
        <div class="card-body">
            <form method="post" action="<?= e(app_url('servers/' . $id . '/edit')) ?>" class="row g-3">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <?php
                $values = [
                    'name' => (string) ($server['name'] ?? ''),
                    'location' => (string) ($server['location'] ?? ''),
                    'host' => (string) ($server['host'] ?? ''),
                    'type' => (string) ($server['type'] ?? ''),
                    'provider' => (string) ($server['provider'] ?? ''),
                    'label' => (string) ($server['label'] ?? ''),
                ];
                $idPrefix = 'edit-server';
                $providerPlaceholder = 'e.g. AWS, DigitalOcean, Proxmox';
                $labelPlaceholder = 'e.g. web-prod, mail';
                require SERVMON_BASE_DIR . '/app/Views/partials/server_identity_form.php';
                ?>
                <div class="col-md-6">
                    <label class="form-label" for="edit-server-notify-email">Notify Email</label>
                    <input class="form-control" name="notify_email" id="edit-server-notify-email" value="<?= e((string) ($server['notify_email'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="edit-server-push-allowed-ips">Push Allowed IPs (optional)</label>
                    <textarea class="form-control" rows="2" name="push_allowed_ips" id="edit-server-push-allowed-ips" placeholder="e.g. 10.0.0.5, 203.0.113.0/24"><?= e((string) ($server['push_allowed_ips'] ?? '')) ?></textarea>
                    <div class="form-text">Empty = all IPs allowed. Supports exact IPs and IPv4/IPv6 CIDR.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label d-block">Maintenance Mode</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="maintenance_mode" id="edit-server-maintenance-mode" <?= (int) ($server['maintenance_mode'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="edit-server-maintenance-mode">Enable maintenance (suppress alerts)</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="edit-server-maintenance-until">Maintenance Until (optional)</label>
                    <?php
                    $maintenanceUntilValue = '';
                    if (!empty($server['maintenance_until'])) {
                        $mt = strtotime((string) $server['maintenance_until']);
                        if ($mt !== false) {
                            $maintenanceUntilValue = date('Y-m-d\TH:i', $mt);
                        }
                    }
                    ?>
                    <input class="form-control" type="datetime-local" name="maintenance_until" id="edit-server-maintenance-until" value="<?= e($maintenanceUntilValue) ?>">
                </div>
                <div class="col-12 settings-actions d-flex flex-wrap gap-2">
                    <button class="btn btn-info" type="submit" name="action" value="save" data-submit-loading data-loading-text="Saving...">Save Changes</button>
                    <button class="btn btn-outline-warning" type="submit" name="action" value="regen_token" data-confirm="Regenerate this server token?" data-submit-loading data-loading-text="Generating...">Regenerate Token</button>
                    <a class="btn btn-soft" href="<?= e(app_url('servers')) ?>">Back</a>
                </div>
            </form>
        </div>
    </section>
    <section class="card card-neon" data-ui-section>
        <div class="card-body">
            <h2 class="h6 text-secondary">Current Token</h2>
            <?php if ($displayToken !== ''): ?>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <code class="d-block text-break" id="server-token-display"><?= e(substr((string) $displayToken, 0, 8)) ?>...<?= e(substr((string) $displayToken, -4)) ?></code>
                <code class="d-none text-break" id="server-token-full"><?= e((string) $displayToken) ?></code>
                <button class="btn btn-sm btn-soft" type="button" id="server-token-toggle">Reveal Token</button>
            </div>
            <?php else: ?>
            <p class="text-secondary small mb-0">Token is stored as a hash. Use the <strong>Regenerate Token</strong> button to create a new token (it will be shown here).</p>
            <?php endif; ?>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
