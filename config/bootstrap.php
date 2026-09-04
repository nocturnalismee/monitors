<?php
declare(strict_types=1);

if (defined('SERVMON_BOOTSTRAPPED')) {
    return;
}

require_once __DIR__ . '/env.php';

if (!defined('SERVMON_BASE_DIR')) {
    define('SERVMON_BASE_DIR', dirname(__DIR__));
}

define('APP_NAME', env('APP_NAME', 'monitors'));
define('APP_ENV', env('APP_ENV', 'development'));
define('APP_URL', rtrim((string) env('APP_URL', ''), '/'));
define('APP_TZ', env('APP_TZ', 'Asia/Jakarta'));
define('APP_KEY', env('APP_KEY', ''));
define('TURNSTILE_SITE_KEY', env('TURNSTILE_SITE_KEY', ''));
define('TURNSTILE_SECRET_KEY', env('TURNSTILE_SECRET_KEY', ''));
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'servmon'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('REDIS_ENABLED', env('REDIS_ENABLED', '0') === '1');
define('REDIS_HOST', env('REDIS_HOST', '127.0.0.1'));
define('REDIS_PORT', (int) env('REDIS_PORT', '6379'));
define('REDIS_PASSWORD', env('REDIS_PASSWORD', ''));
define('REDIS_DB', (int) env('REDIS_DB', '0'));
define('REDIS_PREFIX', env('REDIS_PREFIX', 'servmon:'));
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_WINDOW_MINUTES', 5);
define('STATUS_ONLINE_MINUTES', 2);

if (APP_ENV === 'production') {
    error_reporting(E_ALL & ~E_NOTICE);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

date_default_timezone_set(APP_TZ);

if (PHP_SAPI !== 'cli') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (!$isHttps && env('TRUST_PROXY_HEADERS', '0') === '1') {
        $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($proto === 'https' || str_contains($proto, 'https')) {
            $isHttps = true;
        }
    }

    if (!headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name('servmon_session');
        session_start();
    }
}

require_once SERVMON_BASE_DIR . '/app/Support/Autoloader.php';
\App\Support\Autoloader::register();
require_once SERVMON_BASE_DIR . '/app/Support/functions.php';
