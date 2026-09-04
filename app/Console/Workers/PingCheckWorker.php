<?php
declare(strict_types=1);

namespace App\Console\Workers;

final class PingCheckWorker
{
    public function run(): int
    {
        $workerName = 'ping_check';

        $lockFp = worker_acquire_lock('ping-check', 300);
        if ($lockFp === null) {
            echo "ping_check already running\n";
            return 0;
        }

        worker_mark_run_start($workerName);

        try {
            $dueMonitors = ping_due_monitors(1000);
            $checked = 0;
            $changed = 0;

            foreach ($dueMonitors as $monitor) {
                worker_heartbeat_lock($lockFp);
                $monitorId = (int) ($monitor['id'] ?? 0);
                if ($monitorId <= 0) {
                    continue;
                }

                $probe = ping_probe_target(
                    (string) ($monitor['target'] ?? ''),
                    max(1, (int) ($monitor['timeout_seconds'] ?? 2)),
                    (string) ($monitor['check_method'] ?? 'icmp')
                );
                $transition = ping_record_check(
                    $monitorId,
                    $probe,
                    max(1, (int) ($monitor['failure_threshold'] ?? 2))
                );

                if (($transition['changed'] ?? false) === true) {
                    $changed++;
                    evaluate_ping_monitor_transition_alert($monitor, $transition, $probe);
                }

                $checked++;
            }

            if ($checked > 0) {
                invalidate_ping_cache();
            }

            worker_mark_run_success($workerName);
            echo 'ping_check completed. checked=' . $checked . ' changed=' . $changed . PHP_EOL;
            return 0;
        } catch (\Throwable $e) {
            worker_mark_run_failure($workerName, $e->getMessage());
            fwrite(STDERR, 'ping_check failed: ' . $e->getMessage() . PHP_EOL);
            return 1;
        } finally {
            worker_release_lock($lockFp);
        }
    }
}
