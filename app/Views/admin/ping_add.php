<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Add Ping Monitor</h1>
            <p class="page-subtitle">Create target checks for domain or IP with custom interval.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('ping')) ?>">Back to Ping Monitor</a>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0">Monitor Configuration</h2>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_input() ?>
                <div class="col-md-6">
                    <label class="form-label" for="ping-name">Name</label>
                    <input class="form-control" name="name" id="ping-name" value="<?= e(old('name')) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="ping-target-type">Target Type</label>
                    <select class="form-select" name="target_type" id="ping-target-type">
                        <option value="domain" <?= old('target_type', 'domain') === 'domain' ? 'selected' : '' ?>>Domain</option>
                        <option value="ip" <?= old('target_type') === 'ip' ? 'selected' : '' ?>>IP</option>
                        <option value="url" <?= old('target_type') === 'url' ? 'selected' : '' ?>>URL</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="ping-check-method">Check Method</label>
                    <select class="form-select" name="check_method" id="ping-check-method">
                        <option value="icmp" <?= old('check_method', 'icmp') === 'icmp' ? 'selected' : '' ?>>ICMP Ping</option>
                        <option value="http" <?= old('check_method') === 'http' ? 'selected' : '' ?>>HTTP Check</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label d-block">Active</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="active" id="ping-active" <?= old('active', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ping-active">Enable checks immediately</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="ping-target">Target</label>
                    <input class="form-control" name="target" id="ping-target" placeholder="ICMP: example.com / 203.0.113.10 | HTTP: https://example.com/health" value="<?= e(old('target')) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="ping-check-interval-seconds">Interval (seconds)</label>
                    <input class="form-control" type="number" min="30" max="3600" name="check_interval_seconds" id="ping-check-interval-seconds" value="<?= e(old('check_interval_seconds', '60')) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="ping-timeout-seconds">Timeout (seconds)</label>
                    <input class="form-control" type="number" min="1" max="10" name="timeout_seconds" id="ping-timeout-seconds" value="<?= e(old('timeout_seconds', '2')) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="ping-failure-threshold">Failure Threshold</label>
                    <input class="form-control" type="number" min="1" max="10" name="failure_threshold" id="ping-failure-threshold" value="<?= e(old('failure_threshold', '2')) ?>" required>
                </div>
                <div class="col-12 settings-actions d-flex gap-2 flex-wrap">
                    <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Saving...">Save</button>
                    <a class="btn btn-soft" href="<?= e(app_url('ping')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
