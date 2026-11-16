<?php
declare(strict_types=1);
/** @var string $title */

$uiSettings = settings_get_all();
$brandingFaviconRaw = trim((string) ($uiSettings['branding_favicon_url'] ?? ''));
$brandingFaviconUrl = '';
if ($brandingFaviconRaw !== '') {
    if (preg_match('/^(https?:)?\/\//i', $brandingFaviconRaw) === 1 || str_starts_with($brandingFaviconRaw, 'data:')) {
        $brandingFaviconUrl = $brandingFaviconRaw;
    } else {
        $brandingFaviconUrl = app_url(ltrim($brandingFaviconRaw, '/'));
    }
}
$sidebarCollapsedCookie = (string) ($_COOKIE['monitors_sidebar_collapsed'] ?? '');
$bodyClasses = ['monitors-body'];
if (defined('MONITORS_PUBLIC_VIEW') && MONITORS_PUBLIC_VIEW === true) {
    $bodyClasses[] = 'public-status-page';
}
if ($sidebarCollapsedCookie === '1') {
    $bodyClasses[] = 'sidebar-collapsed';
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? APP_NAME) ?></title>
    <?php if ($brandingFaviconUrl !== ''): ?>
        <link rel="icon" href="<?= e($brandingFaviconUrl) ?>">
        <link rel="shortcut icon" href="<?= e($brandingFaviconUrl) ?>">
    <?php endif; ?>
    <link href="<?= e(asset_url('assets/css/fonts.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('assets/vendor/bootstrap/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('assets/vendor/tabler-icons/tabler-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('assets/css/tokens.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('assets/css/components.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('assets/css/app.css')) ?>" rel="stylesheet">
    <script<?= csp_nonce_attr() ?>>
      (function () {
        try {
          var stored = localStorage.getItem('monitors_theme');
          var systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
          var theme = stored === 'dark' || stored === 'light' ? stored : (systemDark ? 'dark' : 'light');
          document.documentElement.setAttribute('data-bs-theme', theme);
        } catch (e) {}
      })();
    </script>
    <?php if (!empty($loadEcharts)): ?>
    <?php /* Only pages that draw charts load the ~1 MB engine. */ ?>
    <script src="<?= e(asset_url('assets/vendor/echarts/echarts.min.js')) ?>" defer></script>
    <?php endif; ?>
    <script src="<?= e(asset_url('assets/js/common.js')) ?>"></script>
</head>
<body class="<?= e(implode(' ', $bodyClasses)) ?>">
<a href="#main-content" class="visually-hidden-focusable">Skip to main content</a>
