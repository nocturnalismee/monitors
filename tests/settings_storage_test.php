<?php
declare(strict_types=1);
require __DIR__ . '/../config/bootstrap.php';
$svc = new App\Services\Settings\StorageStatsService();
$stats = $svc->collect();
assert(isset($stats['table_size']) && isset($stats['is_partitioned']), 'missing keys');
echo "PASS: StorageStatsService collect\n";
