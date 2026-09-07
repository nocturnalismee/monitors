<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
try {
    db()->query('SELECT 1');
} catch (\Throwable $e) {
    echo "SKIP retention_purge (no DB)\n";
    exit(0);
}
$cutoff = '2000-01-01 00:00:00'; // nothing is this old: purges must delete 0
$ip = \App\Services\RetentionService::purge_ip_reputation_checks($cutoff);
if ($ip !== 0) { echo "FAIL ip_rep expected 0 got " . var_export($ip, true) . "\n"; exit(1); }
echo "PASS ip_rep purge 0\n";
$dead = \App\Services\RetentionService::purge_dead_delivery_queue($cutoff);
if ($dead !== 0) { echo "FAIL dead queue expected 0 got " . var_export($dead, true) . "\n"; exit(1); }
echo "PASS dead queue purge 0\n";
$exp = \App\Services\RetentionService::purge_export_jobs($cutoff);
if ($exp !== ['rows' => 0, 'files' => 0]) { echo "FAIL export purge got " . json_encode($exp) . "\n"; exit(1); }
echo "PASS export purge 0\n";
echo "ALL PASS retention_purge\n";
