<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Add New Server</h1>
            <p class="page-subtitle">Register a server node with push-mode agent configuration.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('servers')) ?>">Back to Servers</a>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0">Server Identity</h2>
        </div>
        <div class="card-body">
            <form method="post" action="<?= e(app_url('servers/add')) ?>" class="row g-3">
                <?= csrf_input() ?>
                <div class="col-md-6">
                    <label class="form-label" for="server-name">Name</label>
                    <input class="form-control" name="name" id="server-name" value="<?= e(old('name')) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="server-location">Location</label>
                    <input class="form-control" name="location" id="server-location" value="<?= e(old('location')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="server-host">Host/IP</label>
                    <input class="form-control" name="host" id="server-host" value="<?= e(old('host')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="server-type">Type</label>
                    <input class="form-control" name="type" id="server-type" value="<?= e(old('type')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="server-provider">Provider (optional)</label>
                    <input class="form-control" name="provider" id="server-provider" value="<?= e(old('provider')) ?>" placeholder="DigitalOcean, Hetzner, AWS, etc.">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="server-label">Label (optional)</label>
                    <input class="form-control" name="label" id="server-label" value="<?= e(old('label')) ?>" placeholder="Production, Staging, Core API, etc.">
                </div>
                <div class="col-12 settings-actions d-flex flex-wrap gap-2">
                    <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Saving...">Save</button>
                    <a class="btn btn-soft" href="<?= e(app_url('servers')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
