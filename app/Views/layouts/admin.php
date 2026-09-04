<?php
declare(strict_types=1);
$isAdminPage = true;
$isPublicPage = false;
require __DIR__ . '/head.php';
require __DIR__ . '/nav.php';
require __DIR__ . '/flash.php';
echo $content;
require __DIR__ . '/footer.php';
