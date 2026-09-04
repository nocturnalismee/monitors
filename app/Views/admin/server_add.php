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
                <?php
                $values = [
                    'name' => (string) old('name'),
                    'location' => (string) old('location'),
                    'host' => (string) old('host'),
                    'type' => (string) old('type'),
                    'provider' => (string) old('provider'),
                    'label' => (string) old('label'),
                ];
                $idPrefix = 'server';
                require SERVMON_BASE_DIR . '/app/Views/partials/server_identity_form.php';
                ?>
                <div class="col-12 settings-actions d-flex flex-wrap gap-2">
                    <button class="btn btn-info" type="submit" data-submit-loading data-loading-text="Saving...">Save</button>
                    <a class="btn btn-soft" href="<?= e(app_url('servers')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
