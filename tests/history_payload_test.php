<?php
declare(strict_types=1);

/**
 * History query contract tests (no DB required).
 *
 * Guards the D1 rewrite of Api\StatusController::historyQuery():
 *  - every SQL placeholder has a matching bind param and vice versa, and
 *    no placeholder repeats (native prepares reject duplicates — the
 *    F3-5/HY093 regression class),
 *  - 7d/30d read DISJOINT data slices (no double-counted windows),
 *  - the raw `metrics` table is only scanned for the bounded fresh tail,
 *  - the payload shape (column labels) is unchanged for chart consumers.
 */

require_once __DIR__ . '/../config/bootstrap.php';

use App\Controllers\Api\StatusController;

$failures = [];

function check(bool $condition, string $label): void
{
    if ($condition) {
        echo 'PASS ' . $label . PHP_EOL;
    } else {
        echo 'FAIL ' . $label . PHP_EOL;
        $GLOBALS['failures'][] = $label;
    }
}

/**
 * Extract named placeholders from SQL, ignoring quoted literals
 * (DATE_FORMAT masks contain "%H:%i" which must not count).
 * @return array<string, int> placeholder => occurrence count
 */
function sqlPlaceholders(string $sql): array
{
    $unquoted = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/', "''", $sql);
    $found = [];
    preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', (string) $unquoted, $m);
    foreach ($m[0] as $name) {
        $found[$name] = ($found[$name] ?? 0) + 1;
    }
    return $found;
}

foreach (['5m', '30m', '24h', '7d', '30d', 'bogus-range'] as $range) {
    $built = StatusController::historyQuery($range, 1);
    $sql = (string) ($built['sql'] ?? '');
    $params = is_array($built['params'] ?? null) ? $built['params'] : [];

    check($sql !== '' && $params !== [], $range . ': returns sql + params');

    // Placeholder <-> param parity (order-insensitive), no duplicates,
    // no orphan binds. Native prepares (MariaDB) reject all three.
    $placeholders = sqlPlaceholders($sql);
    $sqlNames = array_keys($placeholders);
    $paramNames = array_keys($params);
    sort($sqlNames);
    sort($paramNames);
    check($sqlNames === $paramNames, $range . ': placeholders match params exactly');
    check(max($placeholders ?: [0]) === 1, $range . ': no duplicate placeholders (HY093-safe)');
    check(count(array_diff_key($params, $placeholders)) === 0, $range . ': no orphan bind params');

    check(substr_count($sql, 'LIMIT {$points}') === 1, $range . ': single {$points} LIMIT marker');
    check(str_contains($sql, 'ORDER BY recorded_at ASC'), $range . ': rows ordered ascending');

    if (in_array($range, ['5m', '30m', '24h', 'bogus-range'], true)) {
        // Short ranges select bare columns straight from `metrics`.
        check(str_contains($sql, 'uptime, ram_total, ram_used, hdd_total, hdd_used,'), $range . ': payload columns (bare select)');
        check(str_contains($sql, 'network_in_bps, network_out_bps,'), $range . ': network columns');
        check(str_contains($sql, 'mail_mta, mail_queue_total'), $range . ': mail columns');
        $expected = $range === '5m' ? 'INTERVAL 5 MINUTE' : ($range === '30m' ? 'INTERVAL 30 MINUTE' : 'INTERVAL 1440 MINUTE');
        check(str_contains($sql, $expected), $range . ': bounded to ' . $expected);
        check(!str_contains($sql, 'metrics_history'), $range . ': raw-only (no history tables)');
        check(!str_contains($sql, 'UNION'), $range . ': no union needed');
    } else {
        // Multi-slice ranges label the outer aggregate columns explicitly.
        foreach (
            [
                'AS uptime',
                'AS ram_total',
                'AS ram_used',
                'AS hdd_total',
                'AS hdd_used',
                'AS cpu_load',
                'AS network_in_bps',
                'AS network_out_bps',
                'AS mail_mta',
                'AS mail_queue_total',
            ] as $columnLabel
        ) {
            check(str_contains($sql, $columnLabel), $range . ': payload column ' . $columnLabel);
        }
    }

    if ($range === '7d') {
        check(substr_count($sql, 'UNION ALL') === 1, '7d: two disjoint slices');
        check(str_contains($sql, 'bucket_seconds = 300'), '7d: reads the 5-minute rollup level');
        check(str_contains($sql, '< :boundary_7d_hist') && str_contains($sql, '>= :boundary_7d_raw'), '7d: boundary splits history vs raw (no overlap)');
        // The fixed 7-day window belongs to the history slice only; the raw
        // slice must be bounded by the boundary param instead.
        check(substr_count($sql, 'INTERVAL 7 DAY') === 1, '7d: fixed window appears exactly once');
        check(
            ($pos = strpos($sql, 'INTERVAL 7 DAY')) !== false
            && ($posFirstUnion = strpos($sql, 'UNION ALL')) !== false
            && $pos < $posFirstUnion,
            '7d: fixed window is on the history slice, not raw'
        );
    }

    if ($range === '30d') {
        check(substr_count($sql, 'UNION ALL') === 2, '30d: three disjoint slices');
        check(str_contains($sql, 'bucket_seconds = 3600'), '30d: reads the hourly rollup level');
        check(str_contains($sql, 'bucket_seconds = 300'), '30d: re-buckets the 5-minute level');
        check(str_contains($sql, '< :boundary_5m_30d_hist') && str_contains($sql, '>= :boundary_5m_30d_raw'), '30d: 3600/300 seam disjoint');
        check(str_contains($sql, '< :boundary_raw_30d_hist') && str_contains($sql, '>= :boundary_raw_30d_next'), '30d: history/raw seam disjoint');
        check(preg_match('/INTERVAL 30 DAY\)\s*\n\s*AND recorded_at < :boundary_5m_30d_hist/', $sql) === 1, '30d: hourly slice capped at the 5m boundary');
    }

    // Boundary params must be past datetime strings; server ids bound as ints.
    foreach ($params as $name => [$value, $type]) {
        if (str_starts_with($name, ':boundary')) {
            check(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1, $range . ': ' . $name . ' is a datetime string');
            $ts = strtotime((string) $value);
            check($ts !== false && $ts > 0 && $ts < time(), $range . ': ' . $name . ' is in the past');
            check($type === \PDO::PARAM_STR, $range . ': ' . $name . ' bound as string');
        } else {
            check($type === \PDO::PARAM_INT && (int) $value === 1, $range . ': ' . $name . ' bound as int');
        }
    }
}

// ---------------------------------------------------------------------------
// Runtime verification against the live database (skipped gracefully when
// the DB is down or has no active servers). Inserts synthetic rows inside a
// transaction and rolls back, so nothing is persisted.
// ---------------------------------------------------------------------------
try {
    $pdo = db();
    $serverRow = db_one('SELECT id FROM servers WHERE active = 1 ORDER BY id LIMIT 1');
    $sid = (int) ($serverRow['id'] ?? 0);
    if ($sid < 1) {
        echo 'SKIP runtime (no active servers)' . PHP_EOL;
    } else {
        $pdo->beginTransaction();
        try {
            $rawIns = $pdo->prepare(
                'INSERT INTO metrics (server_id, recorded_at, uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load, network_in_bps, network_out_bps, mail_mta, mail_queue_total)
                 VALUES (?, NOW() - INTERVAL ? MINUTE, 100, 2048, ?, 50000, 25000, ?, 100000, 90000, 0, 3)'
            );
            $rawIns->execute([$sid, 9, 1024, 1.25]);
            $rawIns->execute([$sid, 1, 1026, 1.75]);

            $hist300 = $pdo->prepare(
                'INSERT INTO metrics_history (server_id, bucket_seconds, recorded_at, uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load, network_in_bps, network_out_bps, mail_mta, mail_queue_total, panel_profile)
                 VALUES (?, 300, FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(NOW() - INTERVAL ? MINUTE) / 300) * 300), 100, 2048, 1000, 50000, 25000, 2.5, 80000, 70000, 1, 2, "generic")'
            );
            // Two 300-buckets ~26h/25h ago (7d history slice AND 30d middle slice).
            $hist300->execute([$sid, 1560]);
            $hist300->execute([$sid, 1505]);
            // One 300-bucket 13 days ago (30d middle slice only).
            $hist300->execute([$sid, 13 * 1440 + 30]);

            // One 3600-bucket 20 days ago (30d oldest slice only).
            $pdo->prepare(
                'INSERT INTO metrics_history (server_id, bucket_seconds, recorded_at, uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load, network_in_bps, network_out_bps, mail_mta, mail_queue_total, panel_profile)
                 VALUES (?, 3600, FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(NOW() - INTERVAL ? MINUTE) / 3600) * 3600), 99, 2048, 900, 50000, 25000, 3.5, 60000, 50000, 1, 1, "generic")'
            )->execute([$sid, 20 * 1440]);

            // Expected row counts: 5m -> raw tail only; 7d -> 2 history buckets
            // + 2 raw buckets; 30d -> 1 hourly bucket + 1 re-bucketed 300 group
            // + 2 middle buckets + 1 raw bucket.
            $expected = ['5m' => 1, '7d' => 4, '30d' => 5];
            foreach ($expected as $range => $expectedRows) {
                $built = StatusController::historyQuery($range, $sid);
                $stmt = $pdo->prepare(str_replace('{$points}', '2000', $built['sql']));
                foreach ($built['params'] as $name => [$value, $type]) {
                    $stmt->bindValue($name, $value, $type);
                }
                $stmt->execute();
                $rows = $stmt->fetchAll();
                check(count($rows) === $expectedRows, 'runtime ' . $range . ': returns ' . $expectedRows . ' bucketed rows (got ' . count($rows) . ')');
                if ($rows !== []) {
                    $first = $rows[0];
                    check(
                        isset($first['recorded_at'], $first['uptime'], $first['ram_used'], $first['cpu_load'], $first['mail_queue_total']),
                        'runtime ' . $range . ': payload columns present'
                    );
                    // Ascending order contract.
                    $times = array_column($rows, 'recorded_at');
                    $sorted = $times;
                    sort($sorted);
                    check($times === $sorted, 'runtime ' . $range . ': rows ascending');
                }
            }
        } finally {
            $pdo->rollBack();
        }
        check(true, 'runtime: transaction rolled back cleanly');
    }
} catch (Throwable $dbError) {
    echo 'SKIP runtime (db unavailable): ' . $dbError->getMessage() . PHP_EOL;
}

if ($failures !== []) {
    echo 'FAILED: ' . count($failures) . ' check(s)' . PHP_EOL;
    exit(1);
}

echo 'ALL PASS history_payload' . PHP_EOL;
