<?php
declare(strict_types=1);

namespace App\Support;

use Redis;
use Throwable;

final class Cache
{
    private static ?Redis $client = null;
    private static bool $initialized = false;

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
        if (!$redis) {
            return null;
        }
        $value = $redis->get(self::key($key));
        if ($value === false) {
            return null;
        }
        return json_decode((string) $value, true);
    }

    public static function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $redis = self::client();
        if (!$redis || $ttlSeconds <= 0) {
            return;
        }
        $redis->setex(self::key($key), $ttlSeconds, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function delete(string $key): void
    {
        $redis = self::client();
        if (!$redis) {
            return;
        }
        $redis->del(self::key($key));
    }

    public static function deletePattern(string $pattern): void
    {
        $redis = self::client();
        if (!$redis) {
            return;
        }

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
        $redis = self::client();
        if (!$redis) {
            return 0;
        }
        $v = $redis->get(self::key('ver:' . $name));
        return $v === false ? 0 : (int) $v;
    }

    /**
     * Invalidate everything keyed under $name in O(1): keys embed the version,
     * so bumping it orphans the old ones until their own TTL expires.
     */
    public static function bumpVersion(string $name): void
    {
        $redis = self::client();
        if (!$redis) {
            return;
        }
        $key = self::key('ver:' . $name);
        $redis->incr($key);
        $redis->expire($key, 86400);
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
}
