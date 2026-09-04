<?php
declare(strict_types=1);

namespace App\Console\Workers;

final class AlertCheckWorker
{
    public function run(): int
    {
        $workerName = 'alert_check';

        $lockFp = worker_acquire_lock('alert-check', 300);
        if ($lockFp === null) {
            echo "alert_check already running\n";
            return 0;
        }

        worker_mark_run_start($workerName);

        try {
            evaluate_down_recovery_alerts();
            evaluate_current_service_down_alerts();
            evaluate_current_ping_down_alerts();

            $rows = db_all(
                'SELECT s.id, s.name,
                        m.mail_queue_total, m.cpu_load, m.ram_used, m.ram_total, m.hdd_used, m.hdd_total
                 FROM servers s' . latest_metric_join_sql('s', 'm') . '
                 WHERE s.active = 1'
            );

            foreach ($rows as $row) {
                worker_heartbeat_lock($lockFp);
                if ($row['ram_total'] === null) {
                    continue;
                }
                evaluate_server_threshold_alerts(
                    ['id' => (int) $row['id'], 'name' => (string) $row['name']],
                    [
                        'mail_queue_total' => (int) ($row['mail_queue_total'] ?? 0),
                        'cpu_load' => (float) ($row['cpu_load'] ?? 0),
                        'ram_used' => (int) ($row['ram_used'] ?? 0),
                        'ram_total' => (int) ($row['ram_total'] ?? 0),
                        'hdd_used' => (int) ($row['hdd_used'] ?? 0),
                        'hdd_total' => (int) ($row['hdd_total'] ?? 0),
                    ]
                );
            }

            worker_mark_run_success($workerName);
            echo "alert_check completed\n";
            return 0;
        } catch (\Throwable $e) {
            worker_mark_run_failure($workerName, $e->getMessage());
            fwrite(STDERR, "alert_check failed: " . $e->getMessage() . PHP_EOL);
            return 1;
        } finally {
            worker_release_lock($lockFp);
        }
    }
}
