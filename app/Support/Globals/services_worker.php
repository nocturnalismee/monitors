<?php
declare(strict_types=1);

use App\Services\WorkerService;

function worker_mark_run_start(string $workerName): void
{
    WorkerService::worker_mark_run_start($workerName);
}

function worker_mark_run_success(string $workerName): void
{
    WorkerService::worker_mark_run_success($workerName);
}

function worker_mark_run_failure(string $workerName, string $errorMessage): void
{
    WorkerService::worker_mark_run_failure($workerName, $errorMessage);
}

function worker_health_status(string $workerName, int $staleSeconds): array
{
    return WorkerService::worker_health_status($workerName, $staleSeconds);
}

function worker_acquire_lock(string $lockName, int $staleSeconds = 300): mixed
{
    return WorkerService::worker_acquire_lock($lockName, $staleSeconds);
}

function worker_heartbeat_lock(mixed $lockFp): void
{
    WorkerService::worker_heartbeat_lock($lockFp);
}

function worker_release_lock(mixed $lockFp): void
{
    WorkerService::worker_release_lock($lockFp);
}
