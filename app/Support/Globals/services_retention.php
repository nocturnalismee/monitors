<?php
declare(strict_types=1);

use App\Services\RetentionService;

function retention_table_exists(string $table): bool
{
    return RetentionService::retention_table_exists($table);
}

function retention_batch_delete(string $table, string $column, string $cutoff, int $batchSize = 5000): int
{
    return RetentionService::retention_batch_delete($table, $column, $cutoff, $batchSize);
}

function metrics_is_partitioned(): bool
{
    return RetentionService::metrics_is_partitioned();
}

function metrics_partitions(): array
{
    return RetentionService::metrics_partitions();
}

function metrics_history_bucket_delete(int $bucketSeconds, string $cutoff, int $batchSize = 5000): int
{
    return RetentionService::metrics_history_bucket_delete($bucketSeconds, $cutoff, $batchSize);
}

function run_core_retention_cleanup(int $days): array
{
    return RetentionService::run_core_retention_cleanup($days);
}

function run_disk_retention_cleanup(int $days): array
{
    return RetentionService::run_disk_retention_cleanup($days);
}
