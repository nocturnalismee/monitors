<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Edit Ping Monitor</h1>
            <p class="page-subtitle">Update ping target, interval, timeout, and status behavior.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('ping/' . $id)) ?>">Back to Detail</a>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0">Monitor Configuration</h2>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <div class="col-md-6">
                    <label class="form-label" for="edit-ping-name">Name</label>
                    <input class="form-control" name="name" id="edit-ping-name" value="<?= e((string) $monitor['name']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="edit-ping-check-method">Check Method</label>
                    <select class="form-select" name="check_method" id="edit-ping-check-method">
                        <option value="icmp" <?= (string) ($monitor['check_method'] ?? 'icmp') === 'icmp' ? 'selected' : '' ?>>ICMP Ping</option>
                        <option value="http" <?= (string) ($monitor['check_method'] ?? 'icmp') === 'http' ? 'selected' : '' ?>>HTTP Check</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="edit-ping-target-type">Target Type</label>
                    <select class="form-select" name="target_type" id="edit-ping-target-type">
                        <option value="domain" <?= (string) $monitor['target_type'] === 'domain' ? 'selected' : '' ?>>Domain</option>
                        <option value="ip" <?= (string) $monitor['target_type'] === 'ip' ? 'selected' : '' ?>>IP</option>
                        <option value="url" <?= (string) $monitor['target_type'] === 'url' ? 'selected' : '' ?>>URL</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label d-block">Active</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="active" id="edit-ping-active" <?= (int) ($monitor['active'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="edit-ping-active">Enable checks</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="edit-ping-target">Target</label>
                    <input class="form-control" name="target" id="edit-ping-target" value="<?= e((string) $monitor['target']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="edit-ping-check-interval-seconds">Interval (seconds)</label>
                    <input class="form-control" type="number" min="30" max="3600" name="check_interval_seconds" id="edit-ping-check-interval-seconds" value="<?= e((string) ((int) ($monitor['check_interval_seconds'] ?? 60))) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="edit-ping-timeout-seconds">Timeout (seconds)</label>
                    <input class="form-control" type="number" min="1" max="10" name="timeout_seconds" id="edit-ping-timeout-seconds" value="<?= e((string) ((int) ($monitor['timeout_seconds'] ?? 2))) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="edit-ping-failure-threshold">Failure Threshold</label>
                    <input class="form-control" type="number" min="1" max="10" name="failure_threshold" id="edit-ping-failure-threshold" value="<?= e((string) ((int) ($monitor['failure_threshold'] ?? 2))) ?>" required>
                </div>
                <div class="col-12 settings-actions d-flex gap-2 flex-wrap">
                    <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Saving...">Save Changes</button>
                    <a class="btn btn-soft" href="<?= e(app_url('ping/' . $id)) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
