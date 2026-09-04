<?php
declare(strict_types=1);

if (!defined('SERVMON_BOOTSTRAPPED')) {
    define('SERVMON_BOOTSTRAPPED', true);
}

/**
 * Local configuration loader.
 * Config values are resolved in priority order: environment variables first,
 * then entries from config/local.php, then defaults provided by the caller.
 */

/** @var array<string, string> $SERVMON_LOCAL_CONFIG */
$GLOBALS['SERVMON_LOCAL_CONFIG'] = [];
$localConfigFile = __DIR__ . '/local.php';
if (is_file($localConfigFile)) {
    $loaded = require $localConfigFile;
    if (is_array($loaded)) {
        $GLOBALS['SERVMON_LOCAL_CONFIG'] = array_map(static fn ($v): string => (string) $v, $loaded);
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string) $value;
    }

    $local = $GLOBALS['SERVMON_LOCAL_CONFIG'] ?? [];
    if (array_key_exists($key, $local) && $local[$key] !== '') {
        return (string) $local[$key];
    }

    return $default;
}
