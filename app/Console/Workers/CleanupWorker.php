<?php
declare(strict_types=1);

namespace App\Console\Workers;

final class CleanupWorker
{
    public function run(): int
    {
        $workerName = 'retention_cleanup';

        $lockFp = worker_acquire_lock('cleanup', 7200);
        if ($lockFp === null) {
            echo "retention_cleanup already running\n";
            return 0;
        }

        worker_mark_run_start($workerName);

        try {
            $days = max(1, (int) setting_get('retention_days'));
            $result = run_core_retention_cleanup($days);
            api_rate_limit_cleanup();

            worker_mark_run_success($workerName);
            echo 'retention_cleanup completed: '
                . 'cutoff=' . ($result['cutoff_at'] ?? '-')
                . ', '
                . 'metrics_raw_cutoff=' . ($result['metrics_cutoff_at'] ?? '-')
                . ', '
                . 'history_cutoff=' . ($result['history_cutoff_at'] ?? '-')
                . ', '
                . 'service_metrics=' . ($result['service_metrics_deleted'] ?? 0)
                . ', '
                . 'metrics=' . $result['metrics_deleted']
                . ', metrics_history=' . ($result['metrics_history_deleted'] ?? 0)
                . ', alerts=' . $result['alerts_deleted']
                . ', attempts=' . $result['attempts_deleted']
                . ', audits=' . ($result['audits_deleted'] ?? 0)
                . PHP_EOL;
            return 0;
        } catch (\Throwable $e) {
            worker_mark_run_failure($workerName, $e->getMessage());
            fwrite(STDERR, "retention_cleanup failed: " . $e->getMessage() . PHP_EOL);
            return 1;
        } finally {
            worker_release_lock($lockFp);
        }
    }
}
