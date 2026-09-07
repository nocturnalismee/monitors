<?php
declare(strict_types=1);

namespace App\Console\Workers;

final class RollupWorker
{
    public function run(): int
    {
        $workerName = 'rollup_metrics';

        $lockFp = worker_acquire_lock('rollup', 7200);
        if ($lockFp === null) {
            echo "rollup_metrics already running\n";
            return 0;
        }

        worker_mark_run_start($workerName);

        try {
            $pdo = db();

            $rawHours = max(1, (int) setting_get('metrics_raw_hours'));
            $days300 = max(1, (int) setting_get('metrics_5m_days'));
            $days3600 = max(1, (int) setting_get('metrics_1h_days'));

            $cutoffRaw = date('Y-m-d H:i:s', strtotime("-{$rawHours} hours"));
            $cutoff300 = date('Y-m-d H:i:s', strtotime("-{$days300} days"));
            $cutoff3600 = date('Y-m-d H:i:s', strtotime("-{$days3600} days"));

            servmon_log_info("Starting metrics rollup: raw<{$cutoffRaw}, 5m<{$cutoff300}, 1h<{$cutoff3600}", 'rollup');

            $historyExists = retention_table_exists('metrics_history');

            $levels = [
                300 => ['source' => 'metrics', 'source_bucket' => 0, 'cutoff' => $cutoffRaw],
                3600 => ['source' => 'metrics_history', 'source_bucket' => 300, 'cutoff' => $cutoff300],
                86400 => ['source' => 'metrics_history', 'source_bucket' => 3600, 'cutoff' => $cutoff3600],
            ];

            $aggregated = 0;
            $deleted = 0;
            foreach ($levels as $interval => $config) {
                if ($interval !== 300 && !$historyExists) {
                    continue;
                }

                $rows = self::aggregateLevel($pdo, (int) $interval, $config['source'], (int) $config['source_bucket'], $config['cutoff']);
                $aggregated += $rows;

                $deleteCutoff = date('Y-m-d H:i:s', strtotime($config['cutoff'] . ' - 1 hour'));
                if ($config['source'] === 'metrics') {
                    if (function_exists('metrics_is_partitioned') && metrics_is_partitioned()) {
                        // Partitioned table: raw expiry is owned by retention_drop_metrics_partitions()
                        // (DROP PARTITION). Row-DELETE here would race it and scan every partition.
                        servmon_log_info('Skipping metrics row-DELETE (partitioned; retention drops partitions)', 'rollup');
                    } else {
                        $deleted += retention_batch_delete('metrics', 'recorded_at', $deleteCutoff);
                    }
                } else {
                    $deleted += metrics_history_bucket_delete((int) $config['source_bucket'], $deleteCutoff);
                }
            }

            servmon_log_info("Rollup complete: aggregated={$aggregated}, source_deleted={$deleted}", 'rollup');
            worker_mark_run_success($workerName);

            echo "rollup_metrics completed: raw_cutoff={$cutoffRaw}, 5m_cutoff={$cutoff300}, 1h_cutoff={$cutoff3600}, aggregated={$aggregated}, source_deleted={$deleted}" . PHP_EOL;
            return 0;
        } catch (\Throwable $e) {
            worker_mark_run_failure($workerName, $e->getMessage());
            fwrite(STDERR, "rollup_metrics failed: " . $e->getMessage() . PHP_EOL);
            return 1;
        } finally {
            worker_release_lock($lockFp);
        }
    }

    private static function aggregateLevel(\PDO $pdo, int $interval, string $sourceTable, int $sourceBucket, string $cutoff): int
    {
        if (!in_array($interval, [300, 3600, 86400], true)) {
            throw new \RuntimeException('Invalid rollup interval');
        }
        if (!in_array($sourceTable, ['metrics', 'metrics_history'], true)) {
            throw new \RuntimeException('Invalid rollup source table');
        }

        $whereBucket = $sourceBucket > 0 ? 'AND bucket_seconds = :src_bucket' : '';
        $sql = "
            INSERT INTO metrics_history (
                server_id, bucket_seconds, recorded_at, uptime, ram_total, ram_used, hdd_total, hdd_used,
                cpu_load, network_in_bps, network_out_bps, mail_mta, mail_queue_total, panel_profile
            )
            SELECT
                server_id,
                {$interval},
                MIN(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / {$interval}) * {$interval})) AS recorded_at,
                MAX(uptime),
                MAX(ram_total),
                ROUND(AVG(ram_used)),
                MAX(hdd_total),
                ROUND(AVG(hdd_used)),
                ROUND(AVG(cpu_load), 4),
                ROUND(AVG(network_in_bps)),
                ROUND(AVG(network_out_bps)),
                MAX(mail_mta),
                ROUND(AVG(mail_queue_total)),
                MAX(panel_profile)
            FROM {$sourceTable}
            WHERE recorded_at < :cutoff {$whereBucket}
            GROUP BY server_id, FLOOR(UNIX_TIMESTAMP(recorded_at) / {$interval})
            ON DUPLICATE KEY UPDATE
                uptime = GREATEST(metrics_history.uptime, VALUES(uptime)),
                ram_total = GREATEST(metrics_history.ram_total, VALUES(ram_total)),
                ram_used = VALUES(ram_used),
                hdd_total = GREATEST(metrics_history.hdd_total, VALUES(hdd_total)),
                hdd_used = VALUES(hdd_used),
                cpu_load = VALUES(cpu_load),
                network_in_bps = VALUES(network_in_bps),
                network_out_bps = VALUES(network_out_bps),
                mail_mta = VALUES(mail_mta),
                mail_queue_total = VALUES(mail_queue_total),
                panel_profile = VALUES(panel_profile)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cutoff', $cutoff, \PDO::PARAM_STR);
        if ($sourceBucket > 0) {
            $stmt->bindValue(':src_bucket', $sourceBucket, \PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }
}
