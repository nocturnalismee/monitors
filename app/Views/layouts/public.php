<?php
declare(strict_types=1);
$isAdminPage = false;
$isPublicPage = true;
if (!defined('SERVMON_PUBLIC_VIEW')) {
    define('SERVMON_PUBLIC_VIEW', true);
}
require __DIR__ . '/head.php';
echo $content;
require __DIR__ . '/footer.php';
