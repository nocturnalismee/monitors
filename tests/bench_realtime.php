<?php
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

const BENCH_TARGET_INSERT_MS = 5.0;
const BENCH_TARGET_SSE_TICK_MS = 200.0;

$count = (int) ($argv[1] ?? 1000);
if ($count < 10 || $count > 50000) {
    $count = 1000;
}

$serverRow = db_one('SELECT id FROM servers WHERE active = 1 ORDER BY id ASC LIMIT 1');
if ($serverRow === null) {
    fwrite(STDERR, "No active server in DB; bench requires at least one active server.\n");
    exit(1);
}
$serverId = (int) $serverRow['id'];

$startRow = db_one('SELECT COALESCE(MAX(id), 0) AS m FROM metrics');
$startId = (int) ($startRow['m'] ?? 0);

$pdo = db();

$insertTimes = [];
$pdo->beginTransaction();
$stmt = $pdo->prepare(
    'INSERT INTO metrics (server_id, uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load,
                          network_in_bps, network_out_bps, mail_mta, mail_queue_total, panel_profile, recorded_at)
     VALUES (:server_id, :uptime, :ram_total, :ram_used, :hdd_total, :hdd_used, :cpu_load,
             :in_bps, :out_bps, :mta, :queue, :panel, :recorded_at)'
);

$t0 = microtime(true);
for ($i = 0; $i < $count; $i++) {
    $s = microtime(true);
    $stmt->bindValue(':server_id', $serverId, PDO::PARAM_INT);
    $stmt->bindValue(':uptime', 100000 + $i, PDO::PARAM_INT);
    $stmt->bindValue(':ram_total', 8000000000, PDO::PARAM_INT);
    $stmt->bindValue(':ram_used', 4000000000 + $i, PDO::PARAM_INT);
    $stmt->bindValue(':hdd_total', 100000000000, PDO::PARAM_INT);
    $stmt->bindValue(':hdd_used', 50000000000 + $i, PDO::PARAM_INT);
    $stmt->bindValue(':cpu_load', 0.5 + (($i % 100) / 100), PDO::PARAM_STR);
    $stmt->bindValue(':in_bps', 1000 + $i, PDO::PARAM_INT);
    $stmt->bindValue(':out_bps', 2000 + $i, PDO::PARAM_INT);
    $stmt->bindValue(':mta', 'postfix', PDO::PARAM_STR);
    $stmt->bindValue(':queue', 1 + ($i % 50), PDO::PARAM_INT);
    $stmt->bindValue(':panel', 'generic', PDO::PARAM_STR);
    $stmt->bindValue(':recorded_at', date('Y-m-d H:i:s', time() - ($count - $i)), PDO::PARAM_STR);
    $stmt->execute();
    $insertTimes[] = (microtime(true) - $s) * 1000;
    if (($i + 1) % 500 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
    }
}
$pdo->commit();
$insertTotalMs = (microtime(true) - $t0) * 1000;

sort($insertTimes);
$n = count($insertTimes);
$avg = array_sum($insertTimes) / $n;
$median = $insertTimes[(int) floor($n / 2)];
$p95 = $insertTimes[(int) floor($n * 0.95)];

$sseStart = db_one('SELECT COALESCE(MAX(id), 0) AS m FROM metrics');
$sseSince = (int) ($sseStart['m'] ?? 0);

$sseTimes = [];
$sseStmt = $pdo->prepare(
    'SELECT m.id FROM metrics m INNER JOIN servers s ON s.id = m.server_id AND s.active = 1
     WHERE m.id > :since AND m.server_id = :sid ORDER BY m.id ASC LIMIT 50'
);
for ($i = 0; $i < 50; $i++) {
    $t = microtime(true);
    $sseStmt->bindValue(':since', $sseSince, PDO::PARAM_INT);
    $sseStmt->bindValue(':sid', $serverId, PDO::PARAM_INT);
    $sseStmt->execute();
    $sseStmt->fetchAll(PDO::FETCH_ASSOC);
    $sseTimes[] = (microtime(true) - $t) * 1000;
}
sort($sseTimes);
$sseAvg = array_sum($sseTimes) / count($sseTimes);
$sseMedian = $sseTimes[(int) floor(count($sseTimes) / 2)];

$redis = null;
if (REDIS_ENABLED === 1) {
    try {
        $redis = redis_client();
    } catch (Throwable $e) {
        $redis = null;
    }
}

$insertPass = $p95 < BENCH_TARGET_INSERT_MS;
$ssePass = $sseMedian < BENCH_TARGET_SSE_TICK_MS;

printf(
    "bench_realtime: %d inserts for server %d in %.1f ms (%.2f ins/s)%s",
    $count,
    $serverId,
    $insertTotalMs,
    ($count / $insertTotalMs) * 1000,
    PHP_EOL
);
printf(
    "  insert ms  avg=%.2f median=%.2f p95=%.2f  target<%.1f  -> %s%s",
    $avg,
    $median,
    $p95,
    BENCH_TARGET_INSERT_MS,
    $insertPass ? 'PASS' : 'FAIL',
    PHP_EOL
);
printf(
    "  sse tick ms avg=%.2f median=%.2f  target<%.1f  -> %s%s",
    $sseAvg,
    $sseMedian,
    BENCH_TARGET_SSE_TICK_MS,
    $ssePass ? 'PASS' : 'FAIL',
    PHP_EOL
);
if ($redis !== null) {
    $t = microtime(true);
    $redis->ping();
    printf("  redis ping=%.2f ms  -> connected%s", (microtime(true) - $t) * 1000, PHP_EOL);
} else {
    echo "  redis: disabled (REDIS_ENABLED=0) - publish_live will no-op\n";
}

db_exec('DELETE FROM metrics WHERE id > :startId', [':startId' => $startId]);
echo "  cleanup: deleted inserted benchmark rows\n";

exit($insertPass && $ssePass ? 0 : 1);
