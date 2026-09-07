<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once SERVMON_BASE_DIR . '/app/Support/functions.php';

function assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

assert_true(formatBytes(512) === '512 B', 'formatBytes bytes');
assert_true(formatBytes(1048576) === '1.00 MB', 'formatBytes MB');
assert_true(formatUptime(45) === '< 1 min', 'formatUptime sub-minute');
assert_true(formatUptime(90061) === '1 days, 1 hours, 1 min', 'formatUptime normal');
assert_true(calculateUsagePercent(5, 10) === 50.0, 'usage percent');

echo "helpers_test passed\n";
