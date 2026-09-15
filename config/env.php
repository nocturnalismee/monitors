<?php
declare(strict_types=1);

if (!defined('MONITORS_BOOTSTRAPPED')) {
    define('MONITORS_BOOTSTRAPPED', true);
}

/**
 * Local configuration loader.
 * Config values are resolved in priority order: environment variables first,
 * then entries from config/local.php, then defaults provided by the caller.
 */

/** @var array<string, string> $MONITORS_LOCAL_CONFIG */
$GLOBALS['MONITORS_LOCAL_CONFIG'] = [];
$localConfigFile = __DIR__ . '/local.php';
if (is_readable($localConfigFile)) {
    try {
        $loaded = require $localConfigFile;
        if (is_array($loaded)) {
            $GLOBALS['MONITORS_LOCAL_CONFIG'] = array_map(static fn ($v): string => (string) $v, $loaded);
        }
    } catch (\Throwable $e) {
        error_log('Warning: Failed to load config/local.php: ' . $e->getMessage());
    }
} elseif (is_file($localConfigFile)) {
    error_log('Warning: config/local.php exists but is not readable (permission denied): ' . $localConfigFile . '. Using environment variables only. Fix with: chmod 644 ' . $localConfigFile);
}

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string) $value;
    }

    $local = $GLOBALS['MONITORS_LOCAL_CONFIG'] ?? [];
    if (array_key_exists($key, $local) && $local[$key] !== '') {
        return (string) $local[$key];
    }

    return $default;
}
