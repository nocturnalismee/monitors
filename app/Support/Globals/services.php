<?php
declare(strict_types=1);

use App\Services\MaintenanceService;
use App\Support\Audit;
use App\Support\Logger;

function servmon_log_info(string $message, string $context = 'app', array $extra = []): void
{
    Logger::info($message, $context, $extra);
}

function servmon_log_error(string $message, string $context = 'app', array $extra = []): void
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
