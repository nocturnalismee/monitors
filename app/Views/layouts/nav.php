<?php
declare(strict_types=1);
$activeNav = $activeNav ?? '';
$user = current_user();
$isAdminUser = has_role('admin');
$activeAlertCount = 0;
try {
    $activeAlertRow = db_one("SELECT COUNT(*) AS total FROM alert_logs WHERE status = 'active'");
    $activeAlertCount = (int) ($activeAlertRow['total'] ?? 0);
} catch (Throwable) {
    $activeAlertCount = 0;
}
$uiSettings = settings_get_all();
$brandingLogoRaw = trim((string) ($uiSettings['branding_logo_url'] ?? ''));
$brandingLogoUrl = '';
if ($brandingLogoRaw !== '') {
    if (preg_match('/^(https?:)?\/\//i', $brandingLogoRaw) === 1 || str_starts_with($brandingLogoRaw, 'data:')) {
        $brandingLogoUrl = $brandingLogoRaw;
    } else {
        $brandingLogoUrl = app_url(ltrim($brandingLogoRaw, '/'));
    }
}
?>
<aside class="servmon-sidebar" id="servmonSidebar">
    <div class="servmon-sidebar-brand">
        <a class="text-decoration-none fw-bold fs-5 servmon-brand-link" href="<?= e(app_url('dashboard')) ?>">
            <?php if ($brandingLogoUrl !== ''): ?>
                <img class="servmon-brand-logo" src="<?= e($brandingLogoUrl) ?>" alt="<?= e(APP_NAME) ?> logo">
        <?php else: ?>
            <i class="ti ti-server text-cyan"></i>
            <?php endif; ?>
            <span class="sidebar-label"><?= e(APP_NAME) ?></span>
        </a>
    </div>
    <nav class="nav flex-column p-3 gap-1 servmon-sidebar-nav">
        <button
            type="button"
            class="btn btn-sm btn-outline-secondary d-none d-md-inline-flex sidebar-toggle-btn sidebar-nav-toggle"
            data-sidebar-toggle-desktop
            title="Collapse sidebar"
            aria-label="Collapse sidebar"
        >
            <i class="ti ti-chevron-left" data-sidebar-toggle-icon></i>
        </button>

        <div class="sidebar-section-title">
            <span class="sidebar-label">MONITORING</span>
        </div>
        <a class="nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="<?= e(app_url('dashboard')) ?>">
            <i class="ti ti-dashboard me-2"></i><span class="sidebar-label">Dashboard</span>
        </a>
        <a class="nav-link <?= $activeNav === 'servers' ? 'active' : '' ?>" href="<?= e(app_url('servers')) ?>">
            <i class="ti ti-server me-2"></i><span class="sidebar-label">Servers</span>
        </a>
        <a class="nav-link <?= $activeNav === 'disk_health' ? 'active' : '' ?>" href="<?= e(app_url('disk-health')) ?>">
            <i class="ti ti-device-desktop-analytics me-2"></i><span class="sidebar-label">Disk Health</span>
        </a>
        <a class="nav-link <?= $activeNav === 'ping' ? 'active' : '' ?>" href="<?= e(app_url('ping')) ?>">
            <i class="ti ti-radar me-2"></i><span class="sidebar-label">Ping Monitor</span>
        </a>
        <a class="nav-link <?= $activeNav === 'ip_reputation' ? 'active' : '' ?>" href="<?= e(app_url('ip-reputation')) ?>">
            <i class="ti ti-shield-lock me-2"></i><span class="sidebar-label">IP Reputation</span>
        </a>

        <div class="sidebar-section-title mt-2">
            <span class="sidebar-label">INCIDENTS & LOGS</span>
        </div>
        <a class="nav-link <?= $activeNav === 'alerts' ? 'active' : '' ?>" href="<?= e(app_url('alerts')) ?>">
            <i class="ti ti-bell me-2"></i><span class="sidebar-label">Alert Logs</span><?php if ($activeAlertCount > 0): ?><span class="nav-alert-count" aria-label="<?= e((string) $activeAlertCount) ?> active alerts"><?= e($activeAlertCount > 99 ? '99+' : (string) $activeAlertCount) ?></span><?php endif; ?>
        </a>
        <?php if ($isAdminUser): ?>
            <a class="nav-link <?= $activeNav === 'audit' ? 'active' : '' ?>" href="<?= e(app_url('audit-logs')) ?>">
                <i class="ti ti-shield-check me-2"></i><span class="sidebar-label">Audit Logs</span>
            </a>
        <?php endif; ?>

        <div class="sidebar-section-title mt-2">
            <span class="sidebar-label">MANAGEMENT</span>
        </div>
        <?php if ($isAdminUser): ?>
            <a class="nav-link <?= $activeNav === 'export' ? 'active' : '' ?>" href="<?= e(app_url('export')) ?>">
                <i class="ti ti-download me-2"></i><span class="sidebar-label">Export</span>
            </a>
            <a class="nav-link <?= $activeNav === 'settings' ? 'active' : '' ?>" href="<?= e(app_url('settings')) ?>">
                <i class="ti ti-settings me-2"></i><span class="sidebar-label">Settings</span>
            </a>
        <?php endif; ?>
        <a class="nav-link" href="<?= e(app_url('status')) ?>" target="_blank" rel="noopener">
            <i class="ti ti-world me-2"></i><span class="sidebar-label d-inline-flex align-items-center justify-content-between flex-fill">Public Status <i class="ti ti-external-link opacity-75" style="font-size: 0.82rem;" aria-hidden="true"></i></span>
        </a>
    </nav>
    <div class="servmon-sidebar-footer">
        <div class="sidebar-user-row">
            <div class="sidebar-user-meta" title="<?= e($user['username'] ?? '') ?>">
                <i class="ti ti-user-circle" aria-hidden="true"></i>
                <span class="sidebar-label sidebar-user-name"><?= e($user['username'] ?? '') ?></span>
            </div>
            <div class="sidebar-user-actions">
                <button type="button" class="btn btn-soft sidebar-user-action" data-theme-toggle title="Dark Mode" aria-label="Dark Mode">
                    <i class="ti ti-moon-2" data-theme-toggle-icon aria-hidden="true"></i>
                </button>
                <form method="post" action="<?= e(app_url('logout')) ?>" class="m-0">
                    <?= csrf_input() ?>
                    <button class="btn btn-outline-light sidebar-user-action" type="submit" title="Logout" aria-label="Logout">
                        <i class="ti ti-logout-2" aria-hidden="true"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
<button type="button" class="btn btn-info sidebar-mobile-toggle d-md-none" data-sidebar-toggle-mobile aria-expanded="false" aria-controls="servmonSidebar">
    <i class="ti ti-layout-sidebar"></i>
</button>
<div class="sidebar-overlay d-md-none" data-sidebar-overlay></div>
