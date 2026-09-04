<?php
declare(strict_types=1);

namespace App\Console\Workers;

final class ExportWorker
{
    public function run(): int
    {
        $workerName = 'export_worker';
        $lockFp = worker_acquire_lock('export-worker', 3600);
        if ($lockFp === null) return 0;
        worker_mark_run_start($workerName);

        try {
            // Recover jobs left in 'running' by a crashed worker. Because this block
            // runs only while we own the lock, any 'running' job older than the
            // threshold can only belong to a dead/hung process, never to a live one.
            db_exec(
                "UPDATE export_jobs
                 SET status = 'queued', started_at = NULL, error_message = NULL
                 WHERE status = 'running'
                   AND started_at IS NOT NULL
                   AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                []
            );

            $jobs = db_all(
                "SELECT id, export_type, export_format FROM export_jobs
                 WHERE status = 'queued' AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY id ASC LIMIT 2"
            );
            foreach ($jobs as $job) {
                worker_heartbeat_lock($lockFp);
                $id = (int) $job['id'];
                $claim = db()->prepare(
                    "UPDATE export_jobs SET status = 'running', started_at = NOW() WHERE id = :id AND status = 'queued'"
                );
                $claim->execute([':id' => $id]);
                if ($claim->rowCount() !== 1) {
                    continue; // Another worker claimed this job first.
                }
                $heartbeat = static fn() => worker_heartbeat_lock($lockFp);
                try {
                    export_job_run($job, $heartbeat);
                } catch (\Throwable $e) {
                    db_exec("UPDATE export_jobs SET status = 'failed', error_message = :error, completed_at = NOW() WHERE id = :id", [':error' => mb_substr($e->getMessage(), 0, 500), ':id' => $id]);
                }
            }
            db_exec("UPDATE export_jobs SET status = 'expired' WHERE status IN ('queued','completed') AND expires_at IS NOT NULL AND expires_at <= NOW()", []);
            worker_mark_run_success($workerName);
            return 0;
        } catch (\Throwable $e) {
            worker_mark_run_failure($workerName, $e->getMessage());
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        } finally {
            worker_release_lock($lockFp);
        }
    }
}
