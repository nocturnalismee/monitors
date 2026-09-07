<?php
declare(strict_types=1);

namespace App\Services;

final class WorkerService
{
    private static array $lockRegistry = [];

    public static function worker_health_read(string $workerName): array
    {
        $safe = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $workerName) ?? 'unknown', 0, 50);
        $row = db_one('SELECT * FROM worker_health WHERE worker_name = :name LIMIT 1', [':name' => $safe]);
        if ($row === null) {
            return [];
        }
        return $row;
    }

    public static function worker_health_write(string $workerName, array $data): void
    {
        $safe = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $workerName) ?? 'unknown', 0, 50);
        db_exec(
            'INSERT INTO worker_health (worker_name, last_state, last_started_at, last_success_at, last_failure_at, last_error)
             VALUES (:name, :state, :started, :success, :failure, :err)
             ON DUPLICATE KEY UPDATE
                 last_state = VALUES(last_state),
                 last_started_at = COALESCE(VALUES(last_started_at), last_started_at),
                 last_success_at = COALESCE(VALUES(last_success_at), last_success_at),
                 last_failure_at = COALESCE(VALUES(last_failure_at), last_failure_at),
                 last_error = COALESCE(VALUES(last_error), last_error)',
            [
                ':name' => $safe,
                ':state' => $data['last_state'] ?? 'running',
                ':started' => $data['last_started_at'] ?? null,
                ':success' => $data['last_success_at'] ?? null,
                ':failure' => $data['last_failure_at'] ?? null,
                ':err' => $data['last_error'] ?? null,
            ]
        );
    }

    public static function worker_mark_run_start(string $workerName): void
    {
        $now = date('Y-m-d H:i:s');
        self::worker_health_write($workerName, [
            'last_state' => 'running',
            'last_started_at' => $now,
        ]);
    }

    public static function worker_mark_run_success(string $workerName): void
    {
        $now = date('Y-m-d H:i:s');
        self::worker_health_write($workerName, [
            'last_state' => 'ok',
            'last_success_at' => $now,
            'last_error' => '',
        ]);
    }

    public static function worker_mark_run_failure(string $workerName, string $errorMessage): void
    {
        $now = date('Y-m-d H:i:s');
        self::worker_health_write($workerName, [
            'last_state' => 'error',
            'last_failure_at' => $now,
            'last_error' => mb_substr(trim($errorMessage), 0, 500),
        ]);
    }

    public static function worker_health_status(string $workerName, int $staleSeconds): array
    {
        $staleSeconds = max(30, $staleSeconds);
        $data = self::worker_health_read($workerName);

        $lastSuccessAt = (string) ($data['last_success_at'] ?? '');
        $lastState = (string) ($data['last_state'] ?? 'never');

        $ageSeconds = null;
        if ($lastSuccessAt !== '') {
            $ts = strtotime($lastSuccessAt);
            if ($ts !== false) {
                $ageSeconds = max(0, time() - $ts);
            }
        }

        $health = 'unknown';
        if ($lastState === 'error') {
            $health = 'error';
        } elseif ($ageSeconds !== null && $ageSeconds <= $staleSeconds) {
            $health = 'ok';
        } elseif ($ageSeconds !== null) {
            $health = 'stale';
        }

        return [
            'worker' => $workerName,
            'health' => $health,
            'stale_threshold_seconds' => $staleSeconds,
            'age_seconds' => $ageSeconds,
            'last_state' => $lastState,
            'last_started_at' => $data['last_started_at'] ?? null,
            'last_success_at' => $data['last_success_at'] ?? null,
            'last_failure_at' => $data['last_failure_at'] ?? null,
            'last_error' => $data['last_error'] ?? '',
        ];
    }

    public static function worker_lock_path(string $lockName): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'monitors-' . $lockName . '.lock';
    }

    public static function &worker_lock_registry(): array
    {
        return self::$lockRegistry;
    }

    public static function worker_remember_lock_path(mixed $lockFp, string $path): void
    {
        self::$lockRegistry[(int) $lockFp] = $path;
    }

    public static function worker_lock_path_for_fp(mixed $lockFp): ?string
    {
        return self::$lockRegistry[(int) $lockFp] ?? null;
    }

    public static function worker_forget_lock_path(mixed $lockFp): void
    {
        unset(self::$lockRegistry[(int) $lockFp]);
    }

    public static function worker_acquire_lock(string $lockName, int $staleSeconds = 300): mixed
    {
        $lockFile = self::worker_lock_path($lockName);

        // Never remove a stale-looking lock file here. On Unix, unlinking a
        // file does not release an existing flock; it only allows a second
        // process to create a new inode and acquire a separate lock while the
        // original worker is still running. flock() releases automatically
        // when the owning process exits, so the file itself needs no cleanup.
        $lockFp = @fopen($lockFile, 'c');
        if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            if ($lockFp !== false) {
                fclose($lockFp);
            }
            return null;
        }
        @touch($lockFile);
        self::worker_remember_lock_path($lockFp, $lockFile);
        return $lockFp;
    }

    public static function worker_heartbeat_lock(mixed $lockFp): void
    {
        if ($lockFp === null || $lockFp === false) {
            return;
        }
        $path = self::worker_lock_path_for_fp($lockFp);
        if ($path !== null) {
            @touch($path);
        }
    }

    public static function worker_release_lock(mixed $lockFp): void
    {
        if ($lockFp !== null && $lockFp !== false && is_resource($lockFp)) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }
        self::worker_forget_lock_path($lockFp);
    }
}
