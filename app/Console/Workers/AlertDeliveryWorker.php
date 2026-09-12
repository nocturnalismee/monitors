<?php
declare(strict_types=1);

namespace App\Console\Workers;

final class AlertDeliveryWorker
{
    public function run(): int
    {
        $workerName = 'alert_delivery';
        $lockFp = worker_acquire_lock('alert-delivery', 300);
        if ($lockFp === null) {
            echo "alert_delivery already running\n";
            return 0;
        }

        worker_mark_run_start($workerName);

        try {
            $rows = db_all(
                'SELECT q.id, q.alert_id, q.channel, q.attempts,
                        a.title, a.message
                 FROM alert_delivery_queue q
                 INNER JOIN alert_logs a ON a.id = q.alert_id
                 WHERE q.delivered_at IS NULL
                   AND q.available_at <= NOW()
                   AND q.attempts < 5
                 ORDER BY q.id ASC
                 LIMIT 100'
            );

            $settings = settings_get_all();
            $processed = 0;
            $delivered = 0;

            foreach ($rows as $row) {
                worker_heartbeat_lock($lockFp);
                $queueId = (int) ($row['id'] ?? 0);
                $channel = (string) ($row['channel'] ?? '');
                $title = (string) ($row['title'] ?? 'monitors alert');
                $message = (string) ($row['message'] ?? '');
                if ($queueId <= 0 || !in_array($channel, ['email', 'telegram'], true)) {
                    continue;
                }

                // Atomic claim: only the worker whose UPDATE wins (rowCount 1)
                // owns this row. The 10-minute lease prevents a concurrent
                // worker on another host from double-sending while we call
                // the provider. Crash-after-send may still duplicate once
                // the lease expires — providers are at-least-once.
                $claimed = db_exec_count(
                    "UPDATE alert_delivery_queue
                      SET available_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
                          last_error = 'claimed by delivery worker'
                      WHERE id = :id
                        AND delivered_at IS NULL
                        AND attempts < 5",
                    [':id' => $queueId]
                );
                if ($claimed !== 1) {
                    continue;
                }

                $ok = $channel === 'email'
                    ? notify_email($title, $message, $settings)
                    : notify_telegram("<b>{$title}</b>\n" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), $settings);
                $processed++;

                if ($ok) {
                    $delivered++;
                    $column = $channel === 'email' ? 'sent_email' : 'sent_telegram';
                    db_exec("UPDATE alert_delivery_queue SET delivered_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = :id", [':id' => $queueId]);
                    db_exec("UPDATE alert_logs SET {$column} = 1 WHERE id = :alert_id", [':alert_id' => (int) $row['alert_id']]);
                } else {
                    db_exec(
                        'UPDATE alert_delivery_queue
                         SET attempts = attempts + 1,
                             available_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE),
                             last_error = :error
                         WHERE id = :id',
                        [':error' => 'Notification provider returned failure', ':id' => $queueId]
                    );
                }
            }

            worker_mark_run_success($workerName);
            echo 'alert_delivery completed. processed=' . $processed . ' delivered=' . $delivered . PHP_EOL;
            return 0;
        } catch (\Throwable $e) {
            worker_mark_run_failure($workerName, $e->getMessage());
            fwrite(STDERR, 'alert_delivery failed: ' . $e->getMessage() . PHP_EOL);
            return 1;
        } finally {
            worker_release_lock($lockFp);
        }
    }
}
