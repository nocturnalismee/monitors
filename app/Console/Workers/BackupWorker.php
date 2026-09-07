<?php
declare(strict_types=1);

namespace App\Console\Workers;

/**
 * Nightly mysqldump backup with 30-day retention.
 *
 * Writes gzipped dumps to storage/backups (never committed: storage/*
 * is git-ignored). Password is passed via MYSQL_PWD env, never argv,
 * so it does not leak through `ps`. Verification is PHP-side
 * (gzip magic bytes + minimum size) so no gzip binary is required.
 */
final class BackupWorker
{
    public const RETENTION_DAYS_DEFAULT = 30;

    public function run(): int
    {
        $workerName = 'db_backup';

        $lockFp = worker_acquire_lock('backup', 7200);
        if ($lockFp === null) {
            echo "db_backup already running\n";
            return 0;
        }

        worker_mark_run_start($workerName);

        try {
            $dir = SERVMON_BASE_DIR . '/storage/backups';
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create backup dir: ' . $dir);
            }

            $file = $dir . '/servmon-' . date('Ymd-His') . '.sql.gz';
            $this->dump($file);
            $this->verify($file);

            $days = (int) setting_get('backup_retention_days');
            if ($days < 1) {
                $days = self::RETENTION_DAYS_DEFAULT;
            }
            $pruned = self::pruneBackups($dir, $days);

            worker_mark_run_success($workerName);
            echo 'db_backup completed: file=' . basename($file)
                . ' size=' . filesize($file)
                . ' pruned=' . $pruned . PHP_EOL;
            return 0;
        } catch (\Throwable $e) {
            worker_mark_run_failure($workerName, substr($e->getMessage(), 0, 500));
            fwrite(STDERR, 'db_backup failed: ' . $e->getMessage() . PHP_EOL);
            return 1;
        } finally {
            worker_release_lock($lockFp);
        }
    }

    private function dump(string $file): void
    {
        $cmd = 'mysqldump'
            . ' --host=' . escapeshellarg(DB_HOST)
            . ' --port=' . escapeshellarg((string) DB_PORT)
            . ' --user=' . escapeshellarg(DB_USER)
            . ' --single-transaction --skip-lock-tables --routines --events'
            . ' ' . escapeshellarg(DB_NAME)
            . ' 2>' . escapeshellarg($file . '.err')
            . ' | gzip > ' . escapeshellarg($file);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = ['MYSQL_PWD' => DB_PASS, 'PATH' => (string) getenv('PATH')];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot start mysqldump pipeline');
        }
        foreach ($pipes as $p) {
            fclose($p);
        }
        $exit = proc_close($proc);
        @unlink($file . '.err');
        if ($exit !== 0) {
            @unlink($file);
            if ($exit === 127) {
                throw new \RuntimeException('mysqldump binary not found in PATH');
            }
            throw new \RuntimeException('mysqldump failed with exit code ' . $exit);
        }
    }

    private function verify(string $file): void
    {
        clearstatcache(true, $file);
        $size = filesize($file);
        if ($size === false || $size < 1024) {
            @unlink($file);
            throw new \RuntimeException('Backup too small (' . var_export($size, true) . ' bytes), discarded');
        }
        $head = file_get_contents($file, false, null, 0, 2);
        if ($head !== "\x1f\x8b") {
            @unlink($file);
            throw new \RuntimeException('Backup is not valid gzip data, discarded');
        }
    }

    /**
     * Delete servmon-*.sql.gz files older than $days. Returns deleted count.
     */
    public static function pruneBackups(string $dir, int $days): int
    {
        $cutoff = time() - max(1, $days) * 86400;
        $deleted = 0;
        foreach ((array) glob($dir . '/servmon-*.sql.gz') as $path) {
            if (is_file($path) && filemtime($path) !== false && filemtime($path) < $cutoff) {
                if (@unlink($path)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }
}
