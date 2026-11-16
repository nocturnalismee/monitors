<?php
declare(strict_types=1);

use App\Services\MaintenanceService;
use App\Support\Audit;
use App\Support\Logger;

function monitors_log_info(string $message, string $context = 'app', array $extra = []): void
{
    Logger::info($message, $context, $extra);
}

function monitors_log_error(string $message, string $context = 'app', array $extra = []): void
{
    Logger::error($message, $context, $extra);
}

function audit_log(
    string $actionType,
    string $actionDetail,
    ?string $targetType = null,
    ?int $targetId = null,
    array $context = []
): void {
    Audit::log($actionType, $actionDetail, $targetType, $targetId, $context);
}

function is_server_in_maintenance(int $serverId): bool
{
    return MaintenanceService::isServerInMaintenance($serverId);
}

function maintenance_display_text(array $server): string
{
    return MaintenanceService::displayText($server);
}

/**
 * Whether the browser should open a long-lived Server-Sent Events stream.
 *
 * SSE holds one PHP worker per viewer. That is fine behind php-fpm/nginx,
 * but PHP's built-in development server (`php -S`) is single-threaded, so an
 * open stream starves every other request on the page (icon font, chart
 * engine, JSON polling). The dashboard and alert toast panel already fall
 * back to plain polling, so disabling the stream there is lossless for the
 * operator and fixes "assets never finish loading" while developing.
 */
function realtime_stream_enabled(): bool
{
    if (PHP_SAPI === 'cli-server') {
        return false;
    }
    return setting_get('realtime_stream_enabled') !== '0';
}
