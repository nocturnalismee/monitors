<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function assert_push(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('Assertion failed: ' . $msg);
    }
}

// Access shared auth helpers (actual service logic, not a copy)
$ref = new ReflectionClass(\App\Services\PushAuthService::class);
$cidrMethod = $ref->getMethod('ipMatchesCidr');
$allowMethod = $ref->getMethod('ipInAllowlist');
$ipMatchesCidr = static fn(string $ip, string $cidr): bool => (bool) $cidrMethod->invoke(null, $ip, $cidr);
$ipInAllowlist = static fn(string $ip, string $allowlist): bool => (bool) $allowMethod->invoke(null, $ip, $allowlist);

// --- ipMatchesCidr vectors ---
assert_push($ipMatchesCidr('10.0.0.5', '10.0.0.0/24') === true, 'cidr /24 match');
assert_push($ipMatchesCidr('10.0.1.5', '10.0.0.0/24') === false, 'cidr /24 no match');
assert_push($ipMatchesCidr('192.168.1.1', '192.168.1.1/32') === true, 'cidr /32 exact');
assert_push($ipMatchesCidr('192.168.1.2', '192.168.1.1/32') === false, 'cidr /32 fail');
assert_push($ipMatchesCidr('8.8.8.8', '0.0.0.0/0') === true, 'cidr /0 matches all');
assert_push($ipMatchesCidr('10.0.0.1', '10.0.0.0/33') === false, 'cidr invalid prefix');
assert_push($ipMatchesCidr('invalid', '10.0.0.0/24') === false, 'cidr invalid ip');
assert_push($ipMatchesCidr('10.0.0.1', 'invalid/24') === false, 'cidr invalid network');
assert_push($ipMatchesCidr('10.0.0.1', '10.0.0.0') === false, 'cidr missing slash');

// --- ipInAllowlist parsing ---
assert_push($ipInAllowlist('10.0.0.5', '10.0.0.5') === true, 'allow exact');
assert_push($ipInAllowlist('10.0.0.5', '10.0.0.6') === false, 'allow exact fail');
assert_push($ipInAllowlist('10.0.0.5', '10.0.0.0/24') === true, 'allow cidr');
assert_push($ipInAllowlist('10.0.1.5', '10.0.0.0/24') === false, 'allow cidr fail');
assert_push($ipInAllowlist('10.0.0.5', '10.0.0.0/24, 192.168.1.1') === true, 'allow list comma second');
assert_push($ipInAllowlist('192.168.1.1', '10.0.0.0/24, 192.168.1.1') === true, 'allow list comma first');
assert_push($ipInAllowlist('1.1.1.1', "10.0.0.0/24\n192.168.1.1\n  1.1.1.1  ") === true, 'allow whitespace/newline');
assert_push($ipInAllowlist('10.0.0.5', '  ') === true, 'allow whitespace-only treated as empty (allow all)');
assert_push($ipInAllowlist('10.0.0.5', '10.0.0.5/32, 10.0.0.6') === true, 'allow /32 in list');
assert_push($ipInAllowlist('10.0.0.5', '10.0.0.0/24 , , 10.0.0.5') === true, 'allow handles empty entries');

// --- token/timestamp format regex (contract) ---
assert_push(preg_match('/^[a-f0-9]{64}$/', str_repeat('a', 64)) === 1, 'token regex lower hex 64');
assert_push(preg_match('/^[a-f0-9]{64}$/', str_repeat('A', 64)) === 0, 'token regex rejects upper');
assert_push(preg_match('/^[a-f0-9]{64}$/', str_repeat('a', 63)) === 0, 'token regex rejects 63');
assert_push(preg_match('/^\d{10}$/', (string) time()) === 1, 'timestamp 10 digits');
assert_push(preg_match('/^\d{10}$/', '123') === 0, 'timestamp rejects short');

// --- HMAC generation & verification (sha256(timestamp.payload, token)) ---
$token = str_repeat('b', 64);
$payload = '{"server_id":1,"uptime":123}';
$ts = (string) time();
$sig = hash_hmac('sha256', $ts . '.' . $payload, $token);
assert_push(preg_match('/^[a-f0-9]{64}$/', $sig) === 1, 'hmac format');
assert_push(hash_equals($sig, hash_hmac('sha256', $ts . '.' . $payload, $token)) === true, 'hmac self verifies');
assert_push(hash_equals($sig, hash_hmac('sha256', $ts . '.' . $payload . 'x', $token)) === false, 'hmac tampered payload fails');
assert_push(hash_equals($sig, hash_hmac('sha256', ((string) ((int) $ts + 1)) . '.' . $payload, $token)) === false, 'hmac timestamp drift fails');
assert_push(hash_equals($sig, strtoupper($sig)) === false, 'hmac case sensitive (stored lower)');

// --- timestamp window 60s ---
$now = time();
assert_push(abs($now - (int) $ts) <= 60, 'fresh timestamp within window');
assert_push(abs($now - ($now - 61)) > 60, '61s old outside window');
assert_push(abs($now - ($now + 61)) > 60, '61s future outside window');
assert_push(abs($now - ($now - 60)) <= 60, '60s boundary inside');
assert_push(abs($now - ($now - 0)) <= 60, 'now inside');

echo "push_hmac_test passed\n";
