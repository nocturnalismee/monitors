<?php
declare(strict_types=1);

use App\Services\ExportService;

function export_job_create(int $userId, string $type, string $format): int
{
    return ExportService::export_job_create($userId, $type, $format);
}

function export_job_run(array $job, ?callable $heartbeat = null): void
{
    ExportService::export_job_run($job, $heartbeat);
}
