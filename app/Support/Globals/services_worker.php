<?php
declare(strict_types=1);

use App\Services\WorkerService;

function worker_health_read(string $workerName): array
{
    return WorkerService::worker_health_read($workerName);
}

function worker_health_write(string $workerName, array $data): void
{
    WorkerService::worker_health_write($workerName, $data);
}

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

function worker_lock_path(string $lockName): string
{
    return WorkerService::worker_lock_path($lockName);
}

function &worker_lock_registry(): array
{
    return WorkerService::worker_lock_registry();
}

function worker_remember_lock_path(mixed $lockFp, string $path): void
{
    WorkerService::worker_remember_lock_path($lockFp, $path);
}

function worker_lock_path_for_fp(mixed $lockFp): ?string
{
    return WorkerService::worker_lock_path_for_fp($lockFp);
}

function worker_forget_lock_path(mixed $lockFp): void
{
    WorkerService::worker_forget_lock_path($lockFp);
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
