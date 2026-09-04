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
        $key = md5($endpoint . ':' . $ip);
        $file = self::api_rate_limit_dir() . DIRECTORY_SEPARATOR . $key . '.dat';
        $now = time();
        $windowStart = $now - 60;

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return true;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return true;
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
