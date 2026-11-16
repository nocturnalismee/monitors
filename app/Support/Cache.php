<?php
declare(strict_types=1);

namespace App\Support;

use Redis;
use Throwable;

/**
 * Cache facade with two backends.
 *
 * Redis is used when enabled and reachable. Otherwise (the default for a
 * small install) a file-backed store under storage/cache takes over, so the
 * expensive read paths (fleet status, alert counts, history) stay cached
 * instead of silently doing nothing. Both backends speak the same
 * get/set/delete/deletePattern/version API.
 */
final class Cache
{
    private static ?Redis $client = null;
    private static bool $initialized = false;

    private static ?string $fileDir = null;
    private static bool $fileDirResolved = false;

    public static function client(): ?Redis
    {
        if (self::$initialized) {
            return self::$client;
        }
        self::$initialized = true;

        if (!REDIS_ENABLED || !class_exists('Redis')) {
            return null;
        }

        try {
            $redis = new Redis();
            $ok = $redis->connect(REDIS_HOST, REDIS_PORT, 2.5);
            if (!$ok) {
                return null;
            }
            if (REDIS_PASSWORD !== '') {
                $redis->auth(REDIS_PASSWORD);
            }
            if (REDIS_DB > 0) {
                $redis->select(REDIS_DB);
            }
            self::$client = $redis;
            return self::$client;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function key(string $suffix): string
    {
        return REDIS_PREFIX . $suffix;
    }

    public static function get(string $key): mixed
    {
        $redis = self::client();
        if ($redis) {
            $value = $redis->get(self::key($key));
            if ($value === false) {
                return null;
            }
            return json_decode((string) $value, true);
        }
        return self::fileGet(self::key($key));
    }

    public static function set(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }
        $redis = self::client();
        if ($redis) {
            $redis->setex(self::key($key), $ttlSeconds, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }
        self::fileSet(self::key($key), $value, $ttlSeconds);
    }

    public static function delete(string $key): void
    {
        $redis = self::client();
        if ($redis) {
            $redis->del(self::key($key));
            return;
        }
        self::fileDelete(self::key($key));
    }

    public static function deletePattern(string $pattern): void
    {
        $redis = self::client();
        if ($redis) {
            $it = null;
            $fullPattern = self::key($pattern);
            while (($keys = $redis->scan($it, $fullPattern, 100)) !== false) {
                if (!empty($keys)) {
                    $redis->del($keys);
                }
                if ($it === 0) {
                    break;
                }
            }
            return;
        }
        self::fileDeletePattern(self::key($pattern));
    }

    public static function ttl(string $settingKey, int $fallback): int
    {
        $v = (int) setting_get($settingKey);
        if ($v <= 0) {
            return $fallback;
        }
        return $v;
    }

    /**
     * Read a monotonic version counter (0 when absent). Counter keys expire
     * after 24h; every cache key carrying a version has a TTL far below that,
     * so a reset-to-0 can only collide with already-expired keys.
     */
    public static function version(string $name): int
    {
        $key = self::key('ver:' . $name);
        $redis = self::client();
        if ($redis) {
            $v = $redis->get($key);
            return $v === false ? 0 : (int) $v;
        }
        return (int) (self::fileGet($key) ?? 0);
    }

    /**
     * Invalidate everything keyed under $name in O(1): keys embed the version,
     * so bumping it orphans the old ones until their own TTL expires.
     */
    public static function bumpVersion(string $name): void
    {
        $key = self::key('ver:' . $name);
        $redis = self::client();
        if ($redis) {
            $redis->incr($key);
            $redis->expire($key, 86400);
            return;
        }
        $next = ((int) (self::fileGet($key) ?? 0)) + 1;
        self::fileSet($key, $next, 86400);
    }

    /**
     * Suffix embedding both the global and the per-server status version, so
     * callers build self-invalidating cache keys without any SCAN.
     */
    public static function statusVersionSuffix(?int $serverId = null): string
    {
        $suffix = '.v' . self::version('status:gver');
        if ($serverId !== null) {
            $suffix .= '.v' . self::version('status:sver:' . $serverId);
        }
        return $suffix;
    }

    public static function invalidateStatus(?int $serverId = null): void
    {
        self::delete('status:list');
        self::delete('status:list:active');
        self::delete('status:list:all');
        if ($serverId !== null) {
            self::bumpVersion('status:sver:' . $serverId);
        } else {
            self::bumpVersion('status:gver');
        }
    }

    public static function invalidateAlerts(): void
    {
        self::deletePattern('alert:*');
    }

    public static function invalidatePing(?int $monitorId = null): void
    {
        self::deletePattern('ping:list:*');
        if ($monitorId !== null) {
            self::delete('ping:single:' . $monitorId);
            self::deletePattern('ping:history:' . $monitorId . ':*');
        } else {
            self::deletePattern('ping:single:*');
            self::deletePattern('ping:history:*');
        }
    }

    // ── File backend ────────────────────────────────────────────────────

    private static function fileDir(): ?string
    {
        if (self::$fileDirResolved) {
            return self::$fileDir;
        }
        self::$fileDirResolved = true;
        if (!defined('MONITORS_BASE_DIR')) {
            return null;
        }
        $dir = rtrim((string) MONITORS_BASE_DIR, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }
        self::$fileDir = $dir;
        return self::$fileDir;
    }

    private static function filePath(string $key): ?string
    {
        $dir = self::fileDir();
        if ($dir === null) {
            return null;
        }
        return $dir . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    }

    private static function fileGet(string $key): mixed
    {
        $path = self::filePath($key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            @unlink($path);
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('v', $data)) {
            @unlink($path);
            return null;
        }
        if ((int) ($data['e'] ?? 0) < time()) {
            @unlink($path);
            return null;
        }
        return $data['v'];
    }

    private static function fileSet(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }
        $path = self::filePath($key);
        if ($path === null) {
            return;
        }
        $payload = json_encode(
            ['k' => $key, 'e' => time() + $ttlSeconds, 'v' => $value],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) {
            return;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    private static function fileDelete(string $key): void
    {
        $path = self::filePath($key);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private static function fileDeletePattern(string $pattern): void
    {
        $dir = self::fileDir();
        if ($dir === null) {
            return;
        }
        $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        $files = @glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($files)) {
            return;
        }
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['k'])) {
                @unlink($file);
                continue;
            }
            if ((int) ($data['e'] ?? 0) < time()) {
                @unlink($file);
                continue;
            }
            if (preg_match($regex, (string) $data['k']) === 1) {
                @unlink($file);
            }
        }
    }
}
