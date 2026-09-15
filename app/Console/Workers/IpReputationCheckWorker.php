<?php
declare(strict_types=1);

namespace App\Console\Workers;

final class IpReputationCheckWorker
{
    public function run(): int
    {
        $workerName = 'ip_reputation_check';

        $lockFp = worker_acquire_lock('ip-reputation-check', 600);
        if ($lockFp === null) {
            echo "ip_reputation_check already running\n";
            return 0;
        }

        worker_mark_run_start($workerName);
        set_time_limit(0);

        try {
            $dueTargets = ip_rep_due_targets(50);
            $checked = 0;
            $changed = 0;

            foreach ($dueTargets as $target) {
                worker_heartbeat_lock($lockFp);
                $targetId = (int) ($target['id'] ?? 0);
                $ipAddress = (string) ($target['ip_address'] ?? '');
                if ($targetId <= 0 || $ipAddress === '') {
                    continue;
                }
                try {
                    $prev = db_one('SELECT provider_results FROM ip_reputation_states WHERE target_id = :tid', [':tid' => $targetId]);
                    $prevProviders = json_decode((string) ($prev['provider_results'] ?? '{}'), true);
                    $prevProviders = is_array($prevProviders) ? $prevProviders : [];
                    $result = ip_rep_check_single($ipAddress, $prevProviders);
                    $transition = ip_rep_record_check($targetId, $result);

                    if (($transition['changed'] ?? false) === true) {
                        $changed++;
                        evaluate_ip_rep_transition_alert($target, $transition);
                    }

                    $checked++;
                } catch (\Throwable $e) {
                    monitors_log_error('IP reputation check failed for target ' . $targetId . ': ' . $e->getMessage(), 'ip_reputation');
                }

                // Throttle: VirusTotal allows 4 req/min, we wait 1 second between targets
                if ($checked < count($dueTargets)) {
                    usleep(1_000_000);
                }
            }

            if ($checked > 0) {
                invalidate_ip_rep_cache();
            }

            worker_mark_run_success($workerName);
            echo 'ip_reputation_check completed. checked=' . $checked . ' changed=' . $changed . PHP_EOL;
            return 0;
        } catch (\Throwable $e) {
            worker_mark_run_failure($workerName, $e->getMessage());
            fwrite(STDERR, 'ip_reputation_check failed: ' . $e->getMessage() . PHP_EOL);
            return 1;
        } finally {
            worker_release_lock($lockFp);
        }
    }
}
