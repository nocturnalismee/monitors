<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use App\Services\ServerListService;
use App\Services\Validation\IpRepValidator;
use App\Services\Validation\PingValidator;
use App\Services\Validation\ServerValidator;

function assert_validation(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('Assertion failed: ' . $msg);
    }
}

// --- ServerValidator::checkIdentity ---
$validIdentity = [
    'name' => 'web-01', 'location' => 'Jakarta', 'host' => '10.0.0.5',
    'type' => 'vps', 'provider' => 'AWS', 'label' => 'prod',
];
assert_validation(ServerValidator::checkIdentity($validIdentity) === null, 'identity valid');
assert_validation(
    ServerValidator::checkIdentity(array_merge($validIdentity, ['name' => ''])) === 'Server name is required.',
    'identity empty name'
);
assert_validation(
    ServerValidator::checkIdentity(array_merge($validIdentity, ['name' => str_repeat('a', 101)])) === 'Server name must not exceed 100 characters.',
    'identity long name'
);
assert_validation(
    ServerValidator::checkIdentity(array_merge($validIdentity, ['type' => str_repeat('a', 51)])) === 'Type must not exceed 50 characters.',
    'identity long type'
);
$norm = ServerValidator::normalizeIdentity(['name' => '  web-01  ']);
assert_validation($norm['name'] === 'web-01' && $norm['label'] === '', 'identity normalize trims + defaults');

// --- ServerValidator::checkContact ---
assert_validation(ServerValidator::checkContact('', '') === null, 'contact empty ok');
assert_validation(ServerValidator::checkContact('ops@example.com', '') === null, 'contact valid email');
assert_validation(
    ServerValidator::checkContact('not-an-email', '') === 'Notify email is not a valid email address.',
    'contact bad email'
);
assert_validation(
    ServerValidator::checkContact(str_repeat('a', 251) . '@x.co', '') === 'Notify email must not exceed 255 characters.',
    'contact long email'
);
assert_validation(ServerValidator::checkContact('', '10.0.0.0/24') === null, 'contact valid allowlist');
assert_validation(ServerValidator::checkContact('', '10.0.0.0/33') !== null, 'contact bad allowlist rejected');

// --- PingValidator ---
$mon = PingValidator::normalizeMonitor(['name' => 'gw', 'target' => '8.8.8.8', 'target_type' => 'ip']);
assert_validation($mon['target_type'] === 'ip', 'ping target type kept');
$monHttp = PingValidator::normalizeMonitor(['name' => 'web', 'target' => 'https://example.com/health', 'check_method' => 'http']);
assert_validation($monHttp['target_type'] === 'url', 'ping http forces url type');
assert_validation($mon['check_interval_seconds'] === 60 && $mon['timeout_seconds'] === 2 && $mon['failure_threshold'] === 2, 'ping defaults');
assert_validation($mon['active'] === 0, 'ping active unchecked defaults 0');
$monActive = PingValidator::normalizeMonitor(['name' => 'gw', 'target' => '8.8.8.8', 'active' => '1']);
assert_validation($monActive['active'] === 1, 'ping active checked is 1');
$monClamp = PingValidator::normalizeMonitor(['name' => 'gw', 'target' => '8.8.8.8', 'check_interval_seconds' => 99999]);
assert_validation($monClamp['check_interval_seconds'] === 3600, 'ping interval clamped');
assert_validation(PingValidator::checkMonitor($mon) === null, 'ping valid monitor');
assert_validation(
    PingValidator::checkMonitor(array_merge($mon, ['name' => ''])) === 'Monitor name is required.',
    'ping empty name'
);
assert_validation(
    PingValidator::checkMonitor(array_merge($mon, ['target' => 'not a target!!!'])) === 'Invalid ping target for selected type.',
    'ping invalid target'
);

// --- IpRepValidator (normalize + format; dup check needs DB) ---
$tgt = IpRepValidator::normalizeTarget(['ip_address' => '  1.1.1.1 ', 'check_interval_hours' => 0]);
assert_validation($tgt['ip_address'] === '1.1.1.1' && $tgt['check_interval_hours'] === 1, 'iprep normalize');
assert_validation(IpRepValidator::checkTarget(['ip_address' => 'notaip'], null) === 'Invalid IP address format.', 'iprep bad format');

// Duplicate roundtrip on TEST-NET-3 address (cleaned up in finally).
$probeIp = '203.0.113.99';
db_exec("DELETE FROM ip_reputation_targets WHERE ip_address = :ip", [':ip' => $probeIp]);
try {
    assert_validation(IpRepValidator::checkTarget(['ip_address' => $probeIp], null) === null, 'iprep fresh ip ok');
    db_exec(
        "INSERT INTO ip_reputation_targets (ip_address, label, server_id, check_interval_hours) VALUES (:ip, NULL, NULL, 6)",
        [':ip' => $probeIp]
    );
    $dupId = (int) db()->lastInsertId();
    assert_validation(
        IpRepValidator::checkTarget(['ip_address' => $probeIp], null) === 'IP address ' . $probeIp . ' is already monitored.',
        'iprep duplicate on add'
    );
    assert_validation(
        IpRepValidator::checkTarget(['ip_address' => $probeIp], $dupId) === null,
        'iprep self-exclusion on edit'
    );
    assert_validation(
        IpRepValidator::checkTarget(['ip_address' => $probeIp], $dupId + 99999) === 'IP address ' . $probeIp . ' is already monitored by another target.',
        'iprep duplicate on edit'
    );
} finally {
    db_exec("DELETE FROM ip_reputation_targets WHERE ip_address = :ip", [':ip' => $probeIp]);
}

// --- ServerListService (pure) ---
assert_validation(ServerListService::cpuSeverityRank(5.0, 2.0, 4.0) === 2, 'rank critical');
assert_validation(ServerListService::cpuSeverityRank(3.0, 2.0, 4.0) === 1, 'rank warn');
assert_validation(ServerListService::cpuSeverityRank(1.0, 2.0, 4.0) === 0, 'rank ok');
$rows = [
    ['name' => 'b-ok', 'cpu_load' => 0.5],
    ['name' => 'a-crit', 'cpu_load' => 9.0],
    ['name' => 'c-warn', 'cpu_load' => 3.0],
];
$sorted = ServerListService::sortBySeverity($rows, 2.0, 4.0);
assert_validation(
    [$sorted[0]['name'], $sorted[1]['name'], $sorted[2]['name']] === ['a-crit', 'c-warn', 'b-ok'],
    'sort worst-first then name'
);
$counts = ServerListService::countByStatus([
    ['last_seen' => date('Y-m-d H:i:s'), 'active' => 1],
    ['last_seen' => '2000-01-01 00:00:00', 'active' => 1],
    ['last_seen' => null, 'active' => 1],
], 5);
assert_validation($counts === ['online' => 1, 'down' => 1, 'pending' => 1], 'count by status');

echo "ALL PASS validation\n";
