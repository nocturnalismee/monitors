<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use App\Services\PingService;

function assert_ping(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('Assertion failed: ' . $msg);
    }
}

// --- normalize ---
assert_ping(PingService::ping_normalize_target("  1.1.1.1 \n") === '1.1.1.1', 'normalize trims');
assert_ping(PingService::ping_normalize_target_type(null) === 'domain', 'type null -> domain');
assert_ping(PingService::ping_normalize_target_type('IP') === 'ip', 'type case insensitive ip');
assert_ping(PingService::ping_normalize_target_type('URL') === 'url', 'type url');
assert_ping(PingService::ping_normalize_target_type('bad') === 'domain', 'type fallback domain');
assert_ping(PingService::ping_normalize_check_method(null) === 'icmp', 'method null -> icmp');
assert_ping(PingService::ping_normalize_check_method('HTTP') === 'http', 'method http case');
assert_ping(PingService::ping_normalize_check_method('bad') === 'icmp', 'method fallback icmp');

// --- display status ---
assert_ping(PingService::ping_display_status('up', 1) === 'up', 'display up active');
assert_ping(PingService::ping_display_status('down', 1) === 'down', 'display down active');
assert_ping(PingService::ping_display_status('unknown', 1) === 'pending', 'display unknown -> pending');
assert_ping(PingService::ping_display_status('up', 0) === 'paused', 'display paused when inactive');
assert_ping(PingService::ping_display_status(null, 1) === 'pending', 'display null -> pending');
assert_ping(PingService::ping_display_status('UP', 1) === 'up', 'display case insensitive');
assert_ping(PingService::ping_display_status(' paused ', 1) === 'pending', 'display trims unknown value');

// --- validate target ---
assert_ping(PingService::ping_validate_target('8.8.8.8', 'ip', 'icmp') === true, 'validate ip good');
assert_ping(PingService::ping_validate_target('999.999.999.999', 'ip', 'icmp') === false, 'validate ip bad');
assert_ping(PingService::ping_validate_target('example.com', 'domain', 'icmp') === true, 'validate domain good');
assert_ping(PingService::ping_validate_target('bad_domain', 'domain', 'icmp') === false, 'validate domain bad underscore');
assert_ping(PingService::ping_validate_target('', 'domain', 'icmp') === false, 'validate empty fail');
assert_ping(PingService::ping_validate_target(str_repeat('a', 254), 'domain', 'icmp') === false, 'validate domain too long');
assert_ping(PingService::ping_validate_target('https://example.com/health', 'domain', 'http') === true, 'validate http url https');
assert_ping(PingService::ping_validate_target('http://example.com', 'domain', 'http') === true, 'validate http url http');
assert_ping(PingService::ping_validate_target('ftp://example.com', 'domain', 'http') === false, 'validate http rejects ftp');
assert_ping(PingService::ping_validate_target('example.com', 'domain', 'http') === false, 'validate http requires url');
assert_ping(PingService::ping_validate_target('https://example.com', 'ip', 'http') === true, 'validate http ignores targetType, checks url');

// --- probe command bounds ---
$cmd1 = PingService::ping_probe_command('1.1.1.1', 2);
assert_ping(str_contains($cmd1, escapeshellarg('1.1.1.1')), 'probe cmd contains target');
assert_ping(str_contains($cmd1, '1.1.1.1') || str_contains($cmd1, escapeshellarg('1.1.1.1')), 'probe cmd target present');

// timeout clamping 1..10
$cmdLow = PingService::ping_probe_command('1.1.1.1', 0);
$cmdHigh = PingService::ping_probe_command('1.1.1.1', 99);
if (PHP_OS_FAMILY === 'Windows') {
    assert_ping(str_contains($cmdLow, '-w 1000'), 'probe clamp low windows 1000ms');
    assert_ping(str_contains($cmdHigh, '-w 10000'), 'probe clamp high windows 10000ms');
} else {
    assert_ping(str_contains($cmdLow, '-W 1'), 'probe clamp low linux 1s');
    assert_ping(str_contains($cmdHigh, '-W 10'), 'probe clamp high linux 10s');
}

// injection safety: target is escaped
$evil = '1.1.1.1; rm -rf /';
$cmdEvil = PingService::ping_probe_command($evil, 2);
assert_ping(str_contains($cmdEvil, escapeshellarg($evil)), 'probe escapeshellarg protects injection');
// quoted form still contains '; rm' inside the quoted arg — ensure it is escaped, not raw unquoted.
// Verify the evil string appears only inside the escaped single-quoted arg.
$quoted = escapeshellarg($evil);
assert_ping(substr_count($cmdEvil, $quoted) === 1, 'probe exactly one escaped arg');
$withoutQuoted = str_replace($quoted, '', $cmdEvil);
assert_ping(!str_contains($withoutQuoted, $evil), 'probe no unescaped evil outside quoted arg');

// terminal command bounds
$tCmd = PingService::ping_terminal_command('8.8.8.8', 5, 3);
assert_ping(str_contains($tCmd, escapeshellarg('8.8.8.8')), 'terminal cmd target');
if (PHP_OS_FAMILY === 'Windows') {
    assert_ping(str_contains($tCmd, '-n 5'), 'terminal count windows');
} else {
    assert_ping(str_contains($tCmd, '-c 5'), 'terminal count linux');
}

echo "ping_check_test passed\n";
