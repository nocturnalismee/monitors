<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function redirect(string $path): never
{
    $url = ltrim($path, '/');
    if (preg_match('/^https?:\/\//i', $path) === 1) {
        $appDomain = parse_url(APP_URL, PHP_URL_HOST);
        $targetDomain = parse_url($path, PHP_URL_HOST);
        if ($appDomain !== null && $targetDomain !== null && strcasecmp($appDomain, $targetDomain) !== 0) {
            $url = '';
        } else {
            header('Location: ' . $path);
            exit;
        }
    }

    if (APP_URL !== '') {
        header('Location: ' . APP_URL . '/' . $url);
    } else {
        header('Location: /' . $url);
    }
    exit;
}

function app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    if (APP_URL !== '') {
        return APP_URL . ($path === '' ? '' : '/' . $path);
    }
    return '/' . $path;
}

function route(string $name, array $params = []): string
{
    return \App\Support\Url::route($name, $params);
}

function asset_url(string $path): string
{
    $relative = ltrim($path, '/');
    $fullPath = SERVMON_BASE_DIR . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $version = is_file($fullPath) ? (string) filemtime($fullPath) : (string) time();
    return app_url($relative) . '?v=' . rawurlencode($version);
}

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get_all(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($items) ? $items : [];
}

function old(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? (string) $_POST[$key] : $default;
}

function calculateUsagePercent(int $used, int $total): float
{
    if ($total <= 0) {
        return 0.0;
    }
    $value = ($used / $total) * 100;
    return round(max(0.0, min(100.0, $value)), 1);
}

function formatBytes(?int $bytes, int $decimals = 2): string
{
    if ($bytes === null || $bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $index = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return number_format($value, $index === 0 ? 0 : $decimals) . ' ' . $units[$index];
}

function formatUptime(?int $seconds): string
{
    if ($seconds === null) {
        return 'N/A';
    }
    if ($seconds < 60) {
        return '< 1 min';
    }

    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $parts = [];

    if ($days > 0) {
        $parts[] = $days . ' days';
    }
    if ($hours > 0) {
        $parts[] = $hours . ' hours';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' min';
    }

    return implode(', ', $parts);
}

function formatUptimeCompact(?int $seconds): string
{
    if ($seconds === null) {
        return 'N/A';
    }
    $seconds = max(0, $seconds);
    if ($seconds <= 0) {
        return '0D';
    }
    $days = intdiv($seconds, 86400);
    if ($days > 0) {
        return $days . 'D';
    }
    $hours = intdiv($seconds, 3600);
    if ($hours > 0) {
        return $hours . 'h';
    }
    $minutes = max(1, intdiv($seconds, 60));
    return $minutes . 'm';
}

function formatNetworkBps(?int $bps): string
{
    if ($bps === null || $bps <= 0) {
        return '0 bps';
    }

    $units = ['bps', 'Kb', 'Mb', 'Gb', 'Tb'];
    $value = (float) $bps;
    $idx = 0;
    while ($value >= 1000 && $idx < count($units) - 1) {
        $value /= 1000;
        $idx++;
    }

    return number_format($value, $idx === 0 ? 0 : 2) . ' ' . $units[$idx];
}

function serverStatusFromLastSeen(?string $lastSeen, bool $isActive = true, ?int $onlineThresholdMinutes = null): string
{
    if (!$isActive) {
        return 'pending';
    }
    if ($onlineThresholdMinutes !== null) {
        $thresholdMinutes = max(1, $onlineThresholdMinutes);
    } else {
        // Single source of truth: alert_down_minutes setting; the
        // STATUS_ONLINE_MINUTES constant is only a fallback (e.g. DB down).
        $thresholdMinutes = STATUS_ONLINE_MINUTES;
        try {
            $fromSettings = (int) setting_get('alert_down_minutes');
            if ($fromSettings >= 1) {
                $thresholdMinutes = $fromSettings;
            }
        } catch (Throwable) {
            // fall through to constant
        }
        $thresholdMinutes = max(1, $thresholdMinutes);
    }

    if ($lastSeen === null || trim($lastSeen) === '') {
        return 'pending';
    }

    try {
        $seenAt = new DateTimeImmutable($lastSeen);
        $now = new DateTimeImmutable('now');
        $minutes = ($now->getTimestamp() - $seenAt->getTimestamp()) / 60;
        if ($minutes <= $thresholdMinutes) {
            return 'online';
        }
        return 'down';
    } catch (Throwable $e) {
        return 'pending';
    }
}

function get_client_ip(): string
{
    $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $remoteValid = filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false ? $remoteAddr : '0.0.0.0';

    $trustedCsv = (string)env('TRUSTED_PROXIES', '127.0.0.1,::1');
    $trusted = \App\Services\Security\ProxyTrustService::parseTrustedProxies($trustedCsv);
    if (!\App\Services\Security\ProxyTrustService::isTrustedProxy($_SERVER['REMOTE_ADDR'] ?? '', $trusted)) {
        return $remoteValid;
    }

    $candidates = [];
    $cfConnectingIp = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if ($cfConnectingIp !== '') {
        $candidates[] = $cfConnectingIp;
    }

    $xRealIp = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
    if ($xRealIp !== '') {
        $candidates[] = $xRealIp;
    }

    $xForwardedFor = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xForwardedFor !== '') {
        $parts = explode(',', $xForwardedFor);
        $candidates[] = trim($parts[0]);
    }

    foreach ($candidates as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $candidate;
        }
    }

    return $remoteValid;
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csp_nonce_attr(): string
{
    $nonce = defined('SERVMON_CSP_NONCE') ? (string) SERVMON_CSP_NONCE : '';
    if ($nonce === '') {
        return '';
    }
    return ' nonce="' . $nonce . '"';
}
