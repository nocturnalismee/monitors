<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$tmp = sys_get_temp_dir() . '/monitors-backup-test-' . getmypid();
@mkdir($tmp, 0770, true);
$new = $tmp . '/monitors-20261120-010000.sql.gz';
$old = $tmp . '/monitors-20200101-010000.sql.gz';
file_put_contents($new, 'x');
file_put_contents($old, 'x');
touch($new);
touch($old, time() - 40 * 86400);

$deleted = \App\Console\Workers\BackupWorker::pruneBackups($tmp, 30);
if ($deleted !== 1 || !is_file($new) || is_file($old)) {
    fwrite(STDERR, "FAIL prune old backup\n");
    exit(1);
}
echo "PASS prune old backup\n";

$deleted = \App\Console\Workers\BackupWorker::pruneBackups($tmp, 30);
if ($deleted !== 0) {
    fwrite(STDERR, "FAIL prune idempotent\n");
    exit(1);
}
echo "PASS prune idempotent\n";

@unlink($new);
@rmdir($tmp);
echo "ALL PASS backup_worker\n";
