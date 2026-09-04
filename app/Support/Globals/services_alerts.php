<?php
declare(strict_types=1);

use App\Services\AlertService;

function alert_delivery_queue_available(): bool
{
    return AlertService::deliveryQueueAvailable();
}

function alert_in_cooldown(?int $serverId, string $alertType, int $cooldownMinutes): bool
{
    return AlertService::inCooldown($serverId, $alertType, $cooldownMinutes);
}

function create_alert(
    ?int $serverId,
    string $alertType,
    string $severity,
    string $title,
    string $message,
    array $context = []
): void {
    AlertService::create($serverId, $alertType, $severity, $title, $message, $context);
}

function resolve_condition_alerts(?int $serverId, array $alertTypes): int
{
    return AlertService::resolveConditionAlerts($serverId, $alertTypes);
}

function evaluate_server_threshold_alerts(array $server, array $metric): void
{
    AlertService::evaluateServerThresholdAlerts($server, $metric);
}

function evaluate_service_transition_alerts(array $server, array $transitions): void
{
    AlertService::evaluateServiceTransitionAlerts($server, $transitions);
}

function evaluate_down_recovery_alerts(): void
{
    AlertService::evaluateDownRecoveryAlerts();
}

function evaluate_current_service_down_alerts(): void
{
    AlertService::evaluateCurrentServiceDownAlerts();
}

function evaluate_current_ping_down_alerts(): void
{
    AlertService::evaluateCurrentPingDownAlerts();
}

function evaluate_ping_monitor_transition_alert(array $monitor, array $transition, array $probe): void
{
    AlertService::evaluatePingMonitorTransitionAlert($monitor, $transition, $probe);
}

function evaluate_ip_rep_transition_alert(array $target, array $transition): void
{
    AlertService::evaluateIpRepTransitionAlert($target, $transition);
}
