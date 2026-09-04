<?php
declare(strict_types=1);

/**
 * Router script for PHP's built-in web server.
 * Usage: php -S 127.0.0.1:8000 -t monitoring-v2/public monitoring-v2/public/router.php
 * Returns false for existing static files so they are served directly;
 * otherwise delegates to the front controller.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
return true;
