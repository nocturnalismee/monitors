<?php
declare(strict_types=1);

/**
 * Fleet aggregate series used by the dashboard hero chart.
 *
 * Verifies the query shape (raw slice + 5-minute rollup slice, disjoint
 * seams), that every bind is an integer, and that the aggregation really
 * averages across active servers instead of summing per-server rows.
 */

require __DIR__ . '/../config/bootstrap.php';

use App\Controllers\Api\StatusController;

$failures = [];
function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
        echo "FAIL: {$message}\n";
    }
}

$ranges = ['30m', '6h', '24h', '7d'];

foreach ($ranges as $range) {
    $built = StatusController::fleetHistoryQuery($range);
    $sql = $built['sql'];
    check(substr_count($sql, '{$points}') === 1, "{$range}: exactly one points placeholder");
    check(str_contains($sql, 'ORDER BY ts ASC'), "{$range}: output ordered ascending");
    check(str_contains($sql, 'ORDER BY ts DESC'), "{$range}: newest buckets selected first");
    check((bool) preg_match('/FROM metrics\s/', $sql), "{$range}: reads raw metrics");
    check(str_contains($sql, 'COUNT(DISTINCT server_id)'), "{$range}: reports contributing servers");
    check(str_contains($sql, 'active = 1'), "{$range}: restricted to active servers");
    check($built['points'] >= 12 && $built['points'] <= 500, "{$range}: sane points cap");

    $names = array_keys($built['params']);
    check(count($names) === count(array_unique($names)), "{$range}: unique parameter names");
    foreach ($built['params'] as $name => [$value, $type]) {
        check($type === PDO::PARAM_INT, "{$range}: {$name} bound as integer");
        check(is_int($value), "{$range}: {$name} value is an integer");
    }
}

// 6h/24h/7d must reach into the rollup when the raw window is short.
$pdo = db();
$pdo->beginTransaction();
try {
    settings_save_many(['metrics_raw_hours' => '1']);
    foreach (['6h', '24h', '7d'] as $range) {
        $sql = StatusController::fleetHistoryQuery($range)['sql'];
        check(str_contains($sql, 'FROM metrics_history'), "{$range}: falls back to the 5-minute rollup");
        check(substr_count($sql, 'UNION ALL') === 1, "{$range}: one raw + one rollup slice");
        check(str_contains($sql, 'bucket_seconds = 300'), "{$range}: rollup slice reads bucket 300");
    }
    $shortSql = StatusController::fleetHistoryQuery('30m')['sql'];
    check(!str_contains($shortSql, 'metrics_history'), '30m: stays on raw metrics only');

    // Aggregation semantics: two active servers, one inactive, one minute.
    db_exec("INSERT INTO servers (name, active) VALUES ('fleet-test-inactive', 0)");
    $inactiveId = (int) db_one("SELECT id FROM servers WHERE name = 'fleet-test-inactive'")['id'];
    $base = time() - 120;
    $rows = [
        [1, 4.0, 50], [1, 2.0, 50],
        [2, 6.0, 90], [2, 0.0, 10],
        [$inactiveId, 100.0, 100],
    ];
    foreach ($rows as [$sid, $cpu, $ramPct]) {
        db_exec(
            'INSERT INTO metrics (server_id, recorded_at, uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load, network_in_bps, network_out_bps, mail_queue_total, panel_profile)
             VALUES (:sid, :ts, 100, 1000, :ram_used, 1000, 500, :cpu, 0, 0, 0, "generic")',
            [
                ':sid' => $sid,
                ':ts' => date('Y-m-d H:i:s', $base),
                ':ram_used' => (int) ($ramPct * 10),
                ':cpu' => $cpu,
            ]
        );
    }

    $built = StatusController::fleetHistoryQuery('30m');
    $sql = str_replace('{$points}', (string) $built['points'], $built['sql']);
    $stmt = $pdo->prepare($sql);
    foreach ($built['params'] as $name => [$value, $type]) {
        $stmt->bindValue($name, $value, $type);
    }
    $stmt->execute();
    $fetched = $stmt->fetchAll();

    $bucket = null;
    foreach ($fetched as $row) {
        if ((int) $row['ts'] === (int) (floor($base / 60) * 60)) {
            $bucket = $row;
            break;
        }
    }
    check($bucket !== null, 'aggregate: bucket for the fixture minute exists');
    if ($bucket !== null) {
        check((int) $bucket['servers'] === 2, 'aggregate: inactive servers are excluded (servers = 2)');
        // CPU: (4 + 2 + 6 + 0) / 4 = 3.0 — the inactive server's 100 must not leak in.
        check(abs((float) $bucket['cpu_load'] - 3.0) < 0.01, 'aggregate: CPU is averaged across active rows only');
        // RAM: (50 + 50 + 90 + 10) / 4 = 50%.
        check(abs((float) $bucket['ram_pct'] - 50.0) < 0.05, 'aggregate: RAM percentage is averaged');
        check(abs((float) $bucket['hdd_pct'] - 50.0) < 0.05, 'aggregate: disk percentage is averaged');
        check(is_numeric($bucket['ts']), 'aggregate: every row exposes an epoch ts');
    }
} finally {
    $pdo->rollBack();
    settings_save_many(['metrics_raw_hours' => '24']);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " fleet history assertion(s) failed\n");
    exit(1);
}

echo "fleet_history_test passed\n";