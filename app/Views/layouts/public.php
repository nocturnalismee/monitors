<?php
declare(strict_types=1);
$isAdminPage = false;
$isPublicPage = true;
if (!defined('MONITORS_PUBLIC_VIEW')) {
    define('MONITORS_PUBLIC_VIEW', true);
}
require __DIR__ . '/head.php';
echo $content;
require __DIR__ . '/footer.php';
