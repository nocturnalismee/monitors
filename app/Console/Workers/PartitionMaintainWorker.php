<?php
declare(strict_types=1);

namespace App\Console\Workers;

final class PartitionMaintainWorker
{
    public function run(): int
    {
        $workerName = 'partition_maintain';

        $lockFp = worker_acquire_lock('partition-maintain', 7200);
        if ($lockFp === null) {
            echo "partition_maintain already running\n";
            return 0;
        }

        worker_mark_run_start($workerName);

        try {
            if (!metrics_is_partitioned()) {
                servmon_log_info('metrics is not partitioned, skipping partition maintenance', 'partition_maintain');
                worker_mark_run_success($workerName);
                echo "partition_maintain skipped: metrics not partitioned" . PHP_EOL;
                return 0;
            }

            $existing = metrics_partitions();
            $existingBounds = array_values($existing);

            $today = date('Y-m-d');
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $maxBound = $existingBounds === [] ? '' : max($existingBounds);

            if ($existingBounds === []) {
                $start = self::firstBoundAfterPmin();
            } elseif ($maxBound >= $tomorrow) {
                $start = date('Y-m-d', strtotime($maxBound . ' + 1 day'));
            } else {
                $start = date('Y-m-d', strtotime($maxBound . ' + 1 day'));
            }
            $end = date('Y-m-d', strtotime('+31 days'));

            $newBounds = [];
            $count = 0;
            for ($bound = $start; $bound <= $end; $bound = date('Y-m-d', strtotime($bound . ' + 1 day'))) {
                if ($count >= 366) {
                    break;
                }
                if (in_array($bound, $existingBounds, true)) {
                    continue;
                }
                if ($maxBound !== '' && $bound <= $maxBound) {
                    continue;
                }
                $newBounds[] = $bound;
                $count++;
            }

            if ($newBounds !== []) {
                $hasPmax = (int) db_one(
                    "SELECT COUNT(*) AS c FROM information_schema.PARTITIONS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metrics' AND PARTITION_NAME = 'pmax'"
                )['c'] > 0;

                $defs = [];
                foreach ($newBounds as $bound) {
                    $defs[] = "PARTITION p" . str_replace('-', '', $bound) . " VALUES LESS THAN ('{$bound}')";
                }

                if ($hasPmax) {
                    $defs[] = 'PARTITION pmax VALUES LESS THAN (MAXVALUE)';
                    db_exec('ALTER TABLE metrics REORGANIZE PARTITION pmax INTO (' . implode(', ', $defs) . ')');
                } else {
                    db_exec('ALTER TABLE metrics ADD PARTITION (' . implode(', ', $defs) . ')');
                }
            }

            $added = count($newBounds);

            servmon_log_info("Partition maintenance done: partitions_added={$added}", 'partition_maintain');
            worker_mark_run_success($workerName);

            echo "partition_maintain completed: start={$start}, end={$end}, added={$added}" . PHP_EOL;
            return 0;
        } catch (\Throwable $e) {
            worker_mark_run_failure($workerName, $e->getMessage());
            fwrite(STDERR, "partition_maintain failed: " . $e->getMessage() . PHP_EOL);
            return 1;
        } finally {
            worker_release_lock($lockFp);
        }
    }

    private static function firstBoundAfterPmin(): string
    {
        $pmin = db_one(
            "SELECT PARTITION_DESCRIPTION AS pdesc
             FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metrics' AND PARTITION_NAME = 'pmin'"
        );
        $desc = $pmin['pdesc'] ?? null;
        if (is_string($desc) && $desc !== '') {
            $desc = trim($desc, "'");
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desc)) {
                return date('Y-m-d', strtotime($desc . ' + 1 day'));
            }
        }
        return date('Y-m-d', strtotime('+1 day'));
    }
}
