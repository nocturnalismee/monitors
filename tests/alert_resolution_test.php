<?php
declare(strict_types=1);

/**
 * Alert resolution fast-path test.
 *
 * The alert-check worker evaluates every server x resource each minute. When
 * nothing is open for a condition the resolver must not issue an UPDATE at
 * all, and after it does resolve an incident the in-process index must know
 * the incident is gone (so a second pass is a no-op).
 */

require_once __DIR__ . '/../config/bootstrap.php';

use App\Services\AlertService;

$pdo = db();
$pdo->beginTransaction();

$failures = 0;

function check(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        echo "PASS {$message}\n";
    } else {
        $failures++;
        echo "FAIL {$message}\n";
    }
}

try {
    $server = db_one('SELECT id FROM servers ORDER BY id LIMIT 1');
    check($server !== null, 'a server row exists for the fixture');
    $serverId = (int) ($server['id'] ?? 0);

    // Clear anything open for this fixture pair so the snapshot is predictable.
    db_exec(
        "DELETE FROM alert_logs WHERE server_id = :sid AND alert_type = 'cpu_high'",
        [':sid' => $serverId]
    );

    // 1. Nothing open -> resolver returns 0 without touching the row.
    AlertService::resetOpenAlertIndex();
    $first = AlertService::resolveConditionAlerts($serverId, ['cpu_high']);
    check($first === 0, 'no open incident -> no rows resolved');

    // 2. Plant an open incident and prove it is resolved exactly once.
    db_exec(
        "INSERT INTO alert_logs (server_id, alert_type, severity, title, message, context_json, sent_email, sent_telegram, status, created_at)
         VALUES (:sid, 'cpu_high', 'warning', 'fixture', 'fixture', '{}', 0, 0, 'active', NOW())",
        [':sid' => $serverId]
    );
    AlertService::resetOpenAlertIndex();
    $resolved = AlertService::resolveConditionAlerts($serverId, ['cpu_high']);
    check($resolved === 1, 'open incident is resolved (got ' . $resolved . ')');

    // 3. The index now knows the incident is closed: a second pass is a no-op
    //    even though the UPDATE would otherwise still be issued.
    $second = AlertService::resolveConditionAlerts($serverId, ['cpu_high']);
    check($second === 0, 'second pass inside the same process does not re-resolve');

    $row = db_one(
        "SELECT status FROM alert_logs WHERE server_id = :sid AND alert_type = 'cpu_high' ORDER BY id DESC LIMIT 1",
        [':sid' => $serverId]
    );
    check(($row['status'] ?? '') === 'resolved', 'incident row is marked resolved');

    // 4. Unrelated alert types must not be resolved by a condition check.
    db_exec(
        "INSERT INTO alert_logs (server_id, alert_type, severity, title, message, context_json, sent_email, sent_telegram, status, created_at)
         VALUES (:sid, 'server_down', 'danger', 'fixture', 'fixture', '{}', 0, 0, 'active', NOW())",
        [':sid' => $serverId]
    );
    AlertService::resetOpenAlertIndex();
    $other = AlertService::resolveConditionAlerts($serverId, ['cpu_critical']);
    check($other === 0, 'resolving an unrelated condition leaves other incidents untouched');
    $down = db_one(
        "SELECT status FROM alert_logs WHERE server_id = :sid AND alert_type = 'server_down' ORDER BY id DESC LIMIT 1",
        [':sid' => $serverId]
    );
    check(($down['status'] ?? '') === 'active', 'server_down incident stays active');
} catch (Throwable $e) {
    $failures++;
    echo 'FAIL exception: ' . $e->getMessage() . "\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    AlertService::resetOpenAlertIndex();
}

if ($failures > 0) {
    echo "\n{$failures} FAILURE(S)\n";
    exit(1);
}

echo "\nALL PASS alert_resolution_test\n";