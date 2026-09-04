<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <a href="<?= e(app_url('ip-reputation')) ?>" class="btn btn-sm btn-outline-secondary me-2"><i class="ti ti-arrow-left"></i></a>
            <h1 class="page-title d-inline-block mb-0 align-middle">Add IP Reputation Target</h1>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft"><h2 class="h6 mb-0">Target Details</h2></div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_input() ?>
                <div class="col-md-6">
                    <label class="form-label" for="ip_address">IP Address <span class="text-danger">*</span></label>
                    <input class="form-control font-mono" id="ip_address" name="ip_address" placeholder="e.g. 103.28.70.1" required>
                    <div class="form-text">IPv4 or IPv6 address</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="label">Label</label>
                    <input class="form-control" id="label" name="label" placeholder="e.g. Mail Server SG1">
                    <div class="form-text">Optional friendly name</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="server_id">Link to Server</label>
                    <select class="form-select" id="server_id" name="server_id">
                        <option value="0">— None —</option>
                        <?php foreach ($servers as $sv): ?>
                            <option value="<?= e((string) $sv['id']) ?>"><?= e((string) $sv['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Optional: associate with an existing server</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="check_interval_hours">Check Interval (hours)</label>
                    <input class="form-control" id="check_interval_hours" name="check_interval_hours" type="number" min="1" max="168" value="6">
                    <div class="form-text">How often to check reputation (1-168 hours)</div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="check_now" name="check_now" checked>
                        <label class="form-check-label" for="check_now">Check reputation immediately after adding</label>
                    </div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Adding..."><i class="ti ti-plus me-1"></i>Add Target</button>
                    <a href="<?= e(app_url('ip-reputation')) ?>" class="btn btn-soft">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
