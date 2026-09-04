<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\Database;
use App\Support\Cache;

final class SettingsService
{
    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'branding_logo_url' => '',
            'branding_favicon_url' => '',
            'alert_down_minutes' => '5',
            'alert_cooldown_minutes' => '30',
            'alert_service_status_enabled' => '1',
            'threshold_mail_queue' => '50',
            'threshold_mail_queue_critical' => '100',
            'threshold_cpu_load' => '2.00',
            'threshold_cpu_load_critical' => '4.00',
            'threshold_ram_pct' => '85',
            'threshold_ram_pct_critical' => '95',
            'threshold_disk_pct' => '90',
            'threshold_disk_pct_critical' => '97',
            'alert_service_flap_suppress_minutes' => '5',
            'alert_ping_enabled' => '1',
            'alert_sound_enabled' => '1',
            'alert_sound_volume' => '8',
            'cache_ttl_status_list' => '15',
            'cache_ttl_status_single' => '15',
            'cache_ttl_history_24h' => '30',
            'cache_ttl_history_7d' => '120',
            'cache_ttl_history_30d' => '180',
            'cache_ttl_alert_logs' => '20',
            'cache_ttl_disk_health_list' => '15',
            'cache_ttl_disk_health_single' => '10',
            'cache_ttl_disk_health_history' => '20',
            'disk_rollup_days' => '2',
            'disk_push_max_body_bytes' => '1048576',
            'disk_push_max_items' => '64',
            'public_alerts_redact_message' => '0',
            'session_idle_timeout_minutes' => '60',
            'session_absolute_timeout_minutes' => '480',
            'channel_email_enabled' => '0',
            'channel_telegram_enabled' => '0',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_secure' => 'tls',
            'smtp_from_email' => '',
            'smtp_from_name' => 'servmon',
            'smtp_to_email' => '',
            'telegram_bot_token' => '',
            'telegram_chat_id' => '',
            'telegram_thread_id' => '',
            'retention_days' => '30',
            'disk_retention_days' => '90',
            'agent_push_signature_required' => '1',
            'push_api_rate_per_minute' => '600',
            'realtime_push_interval_s' => '10',
            'deadband_enabled' => '1',
            'deadband_cpu' => '0.5',
            'deadband_network_bps' => '20000',
            'deadband_queue' => '1',
            'agent_force_interval_s' => '60',
            'metrics_raw_hours' => '24',
            'metrics_5m_days' => '14',
            'metrics_1h_days' => '90',
            'metrics_1d_days' => '730',
            'ip_rep_check_interval_hours' => '6',
            'ip_rep_alert_enabled' => '1',
            'ip_rep_abuseipdb_key' => '',
            'ip_rep_virustotal_key' => '',
            'ip_rep_ipinfo_key' => '',
            'cache_ttl_ip_rep_list' => '30',
            'cache_ttl_ip_rep_detail' => '15',
        ];
    }

    public static function isSensitive(string $key): bool
    {
        $sensitiveKeys = ['smtp_password', 'telegram_bot_token', 'ip_rep_abuseipdb_key', 'ip_rep_virustotal_key', 'ip_rep_ipinfo_key'];
        return in_array($key, $sensitiveKeys, true);
    }

    public static function encrypt(string $value): string
    {
        if ($value === '' || !defined('APP_KEY') || APP_KEY === '') {
            return $value;
        }
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', APP_KEY, OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false || $tag === '') {
            return $value;
        }
        return 'ENC2:' . base64_encode($iv . $tag . $encrypted);
    }

    public static function decrypt(string $value): string
    {
        if (!defined('APP_KEY') || APP_KEY === '') {
            return $value;
        }

        if (str_starts_with($value, 'ENC2:')) {
            $data = base64_decode(substr($value, 5), true);
            if ($data === false || strlen($data) < 28) {
                return '';
            }
            $iv = substr($data, 0, 12);
            $tag = substr($data, 12, 16);
            $encrypted = substr($data, 28);
            $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', APP_KEY, OPENSSL_RAW_DATA, $iv, $tag);
            return $decrypted !== false ? $decrypted : '';
        }

        if (!str_starts_with($value, 'ENC:')) {
            return $value;
        }
        $data = base64_decode(substr($value, 4), true);
        if ($data === false) {
            return '';
        }
        $ivLen = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLen);
        $encrypted = substr($data, $ivLen);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', APP_KEY, 0, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    public static function all(): array
    {
        if (is_array(self::$cache)) {
            return self::$cache;
        }

        $redisKey = 'settings:all';
        $cached = Cache::get($redisKey);
        if (is_array($cached)) {
            $sensitiveKeys = array_filter(array_keys(self::defaults()), [self::class, 'isSensitive']);
            if (!empty($sensitiveKeys)) {
                $rows = Database::all('SELECT setting_key, setting_value FROM app_settings');
                foreach ($rows as $row) {
                    $k = (string) $row['setting_key'];
                    if (in_array($k, $sensitiveKeys, true)) {
                        $cached[$k] = self::decrypt((string) $row['setting_value']);
                    }
                }
            }
            self::$cache = $cached;
            return self::$cache;
        }

        $defaults = self::defaults();
        $rows = Database::all('SELECT setting_key, setting_value FROM app_settings');
        $data = $defaults;
        foreach ($rows as $row) {
            $k = (string) $row['setting_key'];
            $v = (string) $row['setting_value'];
            if (self::isSensitive($k)) {
                $v = self::decrypt($v);
            }
            $data[$k] = $v;
        }

        self::$cache = $data;

        $safeForRedis = $data;
        foreach (array_keys($safeForRedis) as $k) {
            if (self::isSensitive($k)) {
                unset($safeForRedis[$k]);
            }
        }
        Cache::set($redisKey, $safeForRedis, 300);

        return self::$cache;
    }

    public static function get(string $key): string
    {
        $all = self::all();
        return (string) ($all[$key] ?? '');
    }

    public static function set(string $key, string $value): void
    {
        if (self::isSensitive($key)) {
            $value = self::encrypt($value);
        }
        Database::exec(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [':key' => $key, ':value' => $value]
        );
        self::$cache = null;
        Cache::delete('settings:all');
    }

    public static function saveMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set((string) $k, (string) $v);
        }
    }
}
