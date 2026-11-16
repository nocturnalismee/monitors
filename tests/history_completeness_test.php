<?php
declare(strict_types=1);

/**
 * History chart completeness tests.
 *
 * Guards the "history chart looks truncated / incomplete" bug class:
 *  - same-second ties must not be dropped by `ORDER BY recorded_at DESC LIMIT`
 *    (several pushes can land inside one second at second precision),
 *  - the 24h window must fall back to the 5-minute rollup for data older than
 *    `metrics_raw_hours` instead of showing a blank left edge,
 *  - every row carries an UTC epoch timestamp so charts do not shift for
 *    viewers in other time zones,
 *  - the detail page bootstrap and the API agree on ordering (both newest).
 *
 * Runs in-process against the live database inside a rolled-back transaction.
 */

require_once __DIR__ . '/../config/bootstrap.php';

use App\Controllers\Api\StatusController;

$failures = [];

function check_history(bool $condition, string $label): void
{
    if ($condition) {
        echo 'PASS ' . $label . PHP_EOL;
    } else {
        echo 'FAIL ' . $label . PHP_EOL;
        $GLOBALS['failures'][] = $label;
    }
}

function run_history(string $range, int $serverId, int $points = 100): array
{
    ['sql' => $sql, 'params' => $params] = StatusController::historyQuery($range, $serverId);
    $sql = str_replace('{$points}', (string) $points, $sql);
    $stmt = db()->prepare($sql);
    $bind = [];
    foreach ($params as $name => [$value, $type]) {
        // Plain scalars: PDOStatement::execute() binds them natively, which is
        // what the controllers do, and it keeps integer/string types intact
        // for native prepares.
        $bind[ltrim($name, ':')] = $value;
    }
    $stmt->execute($bind);
    return $stmt->fetchAll();
}

$pdo = db();
$serverRow = db_one('SELECT id FROM servers ORDER BY id LIMIT 1');
if ($serverRow === null) {
    echo 'SKIP history completeness (no servers)' . PHP_EOL;
    exit(0);
}
$sid = (int) $serverRow['id'];

// `metrics_history` is keyed by (server_id, bucket_seconds, recorded_at) and
// the rollup normally writes those buckets already. Delete them for real (the
// transaction is rolled back afterwards) or the fixture below collides on the
// unique key and only part of the expected rows exist.
$pdo->beginTransaction();
$pdo->exec('CREATE TEMPORARY TABLE tmp_history_backup AS SELECT * FROM metrics_history');
$pdo->exec('DELETE FROM metrics_history');
try {
    // Pin the raw window to a SHORT value so the 24h window actually falls
    // back to the 5-minute rollup for the older hours. Use the settings API
    // (not a raw UPDATE) because `metrics_raw_hours` is a *default*, not a
    // stored row, so a raw UPDATE matches nothing — and the settings API also
    // invalidates the SettingsService static cache so the pin is visible.
    settings_save_many(['metrics_raw_hours' => '2', 'metrics_5m_days' => '14']);
    $pdo->prepare('DELETE FROM metrics WHERE server_id = ?')->execute([$sid]);
    $pdo->prepare('DELETE FROM metrics_history WHERE server_id = ?')->execute([$sid]);

    $rawIns = $pdo->prepare(
        'INSERT INTO metrics (server_id, recorded_at, uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load, network_in_bps, network_out_bps, mail_mta, mail_queue_total, panel_profile)
         VALUES (?, FROM_UNIXTIME(?), ?, 2048, ?, 50000, 25000, ?, 100000, 90000, 1, 3, "generic")'
    );

    // --- 1. Same-second ties ------------------------------------------------
    // 40 rows inside the same minute (one every 1.5 s) with second precision
    // collisions, then LIMIT 10. A DESC-LIMIT keyed on the truncated label
    // would drop arbitrary rows of the boundary second; every returned row
    // must be distinct in time and the selection must be the newest 10.
    // The 40 rows must sit inside the 5-minute window measured from the
    // query's OWN NOW(), so generate them right before running the query
    // (fixed offsets drift out of the window while the loop runs).
    $base = time() - 20;
    $expectedSameSecond = [];
    for ($i = 0; $i < 40; $i++) {
        $ts = $base + intdiv($i, 2); // two rows per second
        $rawIns->execute([$sid, $ts, 1000 + $i, 1000 + $i, $i / 10]);
        $expectedSameSecond[$ts] = true;
    }
    $rows = run_history('5m', $sid, 10);
    check_history(count($rows) === 10, '5m: LIMIT 10 returns 10 rows despite same-second ties (got ' . count($rows) . ')');
    $seen = [];
    $tieFree = true;
    foreach ($rows as $row) {
        $label = (string) $row['recorded_at'];
        if (isset($seen[$label])) {
            $tieFree = false;
        }
        $seen[$label] = true;
    }
    check_history($tieFree, '5m: no duplicated second labels (ties are aggregated, not dropped)');
    $newest = (int) end($rows)['uptime'];
    check_history($newest >= 1030, '5m: LIMIT keeps the newest data (newest uptime ' . $newest . ' >= 1030)');

    // --- 2. 24h must reach into the rollup ---------------------------------
    $pdo->prepare('DELETE FROM metrics WHERE server_id = ?')->execute([$sid]);
    // Raw tail only ~2 h deep (two recent rows)…
    $rawIns->execute([$sid, time() - 1200, 2000, 1200, 0.15]);
    $rawIns->execute([$sid, time() - 60, 2001, 1200, 1.6]);
    // … plus 5-minute rollups covering 3 h .. 20 h ago. They start at 3 h so
    // every rollup row is clearly older than the hour-aligned raw seam
    // (now - metrics_raw_hours = now - 2 h) and lands in the rollup slice.
    $histIns = $pdo->prepare(
        'INSERT INTO metrics_history (server_id, bucket_seconds, recorded_at, uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load, network_in_bps, network_out_bps, mail_mta, mail_queue_total, panel_profile)
         VALUES (?, 300, FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(NOW() - INTERVAL ? MINUTE) / 300) * 300), ?, 2048, ?, 50000, 25000, ?, 100000, 90000, 1, 3, "generic")'
    );
    for ($minutes = 180; $minutes <= 1200; $minutes += 60) {
        $histIns->execute([$sid, $minutes, 3000 + $minutes, 1400, 2.0]);
    }
    $rows24 = run_history('24h', $sid, 2000);
    $oldest = $rows24 !== [] ? (int) $rows24[0]['uptime'] : 0;
    check_history($rows24 !== [], '24h: history rows returned');
    check_history(
        $oldest >= 3000,
        '24h: includes rollup rows older than metrics_raw_hours (oldest uptime ' . $oldest . ', threshold 3000)'
    );
    check_history(count($rows24) >= 10, '24h: merged raw + rollup rows (got ' . count($rows24) . ')');

    // --- 3. Epoch timestamps ------------------------------------------------
    check_history(isset($rows24[0]['ts']), '24h: rows expose an epoch timestamp (ts)');
    if (isset($rows24[0]['ts'])) {
        $ts = (int) $rows24[0]['ts'];
        $drift = abs($ts - strtotime((string) $rows24[0]['recorded_at']));
        check_history($drift <= 1, 'ts matches the formatted label within 1 s (drift ' . $drift . ')');
    }
    foreach (['5m', '30m', '7d', '30d'] as $range) {
        $sample = run_history($range, $sid, 100);
        if ($sample === []) {
            continue;
        }
        check_history(isset($sample[0]['ts']), $range . ': rows expose an epoch timestamp (ts)');
    }
} finally {
    $pdo->exec('DELETE FROM metrics_history');
    $pdo->exec('INSERT INTO metrics_history SELECT * FROM tmp_history_backup');
    $pdo->exec('DROP TEMPORARY TABLE tmp_history_backup');
    $pdo->rollBack();
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'FAILED: ' . count($failures) . ' check(s)' . PHP_EOL;
    exit(1);
}
echo 'history_completeness_test passed' . PHP_EOL;