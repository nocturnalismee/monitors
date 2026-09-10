<?php
declare(strict_types=1);

namespace App\Support;

final class RateLimiter
{
    public static function api_rate_limit_dir(): string
    {
        $dir = SERVMON_BASE_DIR . '/storage/rate-limit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function api_rate_check(string $endpoint, string $ip, int $maxPerMinute = 60): bool
    {
        if ($maxPerMinute <= 0) {
            return false;
        }
        if ($maxPerMinute > 10000) {
            $maxPerMinute = 10000;
        }

        // Prefer Redis (atomic INCR, no LOCK_EX serialization) when available;
        // fall back to the file sliding window below.
        $redisResult = self::redisFixedWindow($endpoint, $ip, $maxPerMinute);
        if ($redisResult !== null) {
            return $redisResult;
        }

        $key = md5($endpoint . ':' . $ip);
        $file = self::api_rate_limit_dir() . DIRECTORY_SEPARATOR . $key . '.dat';
        $now = time();
        $windowStart = $now - 60;

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            error_log("RateLimiter fopen fail $file");
            return false;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            error_log("RateLimiter flock fail");
            return false;
        }

        $timestamps = [];
        $raw = stream_get_contents($fp);
        if ($raw !== false && $raw !== '') {
            $timestamps = array_filter(
                array_map('intval', explode("\n", trim($raw))),
                static fn(int $ts): bool => $ts > $windowStart
            );
        }

        if (count($timestamps) >= $maxPerMinute) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        $timestamps[] = $now;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, implode("\n", $timestamps));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return true;
    }

    /**
     * Redis fixed-window counter. Returns null when Redis is disabled or
     * unusable so the caller falls back to the file sliding window.
     */
    private static function redisFixedWindow(string $endpoint, string $ip, int $maxPerMinute): ?bool
    {
        try {
            $redis = Cache::client();
        } catch (\Throwable) {
            return null;
        }
        if ($redis === null) {
            return null;
        }
        try {
            $key = Cache::key('ratelimit:' . md5($endpoint . ':' . $ip));
            // Atomic first-hit: SET NX EX creates the window in one step.
            $created = $redis->set($key, 1, ['nx', 'ex' => 60]);
            if ($created) {
                return true;
            }
            $count = (int) $redis->incr($key);
            if ($redis->ttl($key) === -1) {
                $redis->expire($key, 60);
            }
            return $count <= $maxPerMinute;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function api_rate_limit_exceeded(): void
    {
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: 60');
        echo json_encode(['error' => 'Rate limit exceeded. Try again later.']);
        exit;
    }

    public static function api_rate_limit_cleanup(): void
    {
        $dir = self::api_rate_limit_dir();
        $cutoff = time() - 300;
        $files = @glob($dir . '/*.dat');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
