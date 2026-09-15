<?php
declare(strict_types=1);

namespace App\Services;

use Throwable;

final class AlertService
{
    private static ?bool $available = null;

    public static function deliveryQueueAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }
        try {
            $row = db_one(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'alert_delivery_queue'
                 LIMIT 1"
            );
            self::$available = $row !== null;
        } catch (Throwable) {
            self::$available = false;
        }
        return self::$available;
    }

    public static function inCooldown(?int $serverId, string $alertType, int $cooldownMinutes): bool
    {
        if ($cooldownMinutes <= 0) {
            return false;
        }

        // Calculate the cutoff in PHP. MariaDB does not consistently support
        // bound parameters in DATE_SUB(... INTERVAL ...), especially with native
        // prepared statements.
        $cutoff = date('Y-m-d H:i:s', time() - ($cooldownMinutes * 60));
        $sql = 'SELECT created_at, status, silenced_until FROM alert_logs
                WHERE alert_type = :alert_type
                AND created_at >= :cutoff';
        $params = [':alert_type' => $alertType, ':cutoff' => $cutoff];

        if ($serverId !== null) {
            $sql .= ' AND server_id = :server_id';
            $params[':server_id'] = $serverId;
        }

        $sql .= ' ORDER BY id DESC LIMIT 1';
        $lastAlert = db_one($sql, $params);

        if ($lastAlert === null) {
            return false;
        }

        if ((string) ($lastAlert['status'] ?? '') === 'resolved') {
            return false;
        }

        if ((string) ($lastAlert['status'] ?? '') === 'silenced'
            && !empty($lastAlert['silenced_until'])
            && strtotime((string) $lastAlert['silenced_until']) > time()) {
            return true;
        }

        $recoveryType = null;
        if ($alertType === 'server_down') {
            $recoveryType = 'server_recovery';
        } elseif (str_starts_with($alertType, 'service_down_')) {
            $recoveryType = 'service_recovery_' . substr($alertType, 13);
        } elseif (str_starts_with($alertType, 'ping_down_')) {
            $recoveryType = 'ping_recovery_' . substr($alertType, 10);
        }

        if ($recoveryType !== null) {
            $recSql = 'SELECT id FROM alert_logs
                       WHERE alert_type = :rec_type
                       AND created_at >= :last_alert';
            $recParams = [':rec_type' => $recoveryType, ':last_alert' => $lastAlert['created_at']];
            if ($serverId !== null) {
                $recSql .= ' AND server_id = :server_id';
                $recParams[':server_id'] = $serverId;
            }
            $recSql .= ' LIMIT 1';

            if (db_one($recSql, $recParams) !== null) {
                return false;
            }
        }

        return true;
    }

    public static function create(
        ?int $serverId,
        string $alertType,
        string $severity,
        string $title,
        string $message,
        array $context = []
    ): void {
        $settings = settings_get_all();
        $cooldownMinutes = max(0, (int) ($settings['alert_cooldown_minutes'] ?? '30'));

        if (self::inCooldown($serverId, $alertType, $cooldownMinutes)) {
            return;
        }

        // Outbox: alert row + queue rows commit atomically so a crash
        // between them can neither lose nor duplicate the notification.
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_exec(
                'INSERT INTO alert_logs
            (server_id, alert_type, severity, title, message, context_json, sent_email, sent_telegram, created_at)
            VALUES
            (:server_id, :alert_type, :severity, :title, :message, :context_json, :sent_email, :sent_telegram, NOW())',
                [
                    ':server_id' => $serverId,
                    ':alert_type' => $alertType,
                    ':severity' => $severity,
                    ':title' => $title,
                    ':message' => $message,
                    ':context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':sent_email' => 0,
                    ':sent_telegram' => 0,
                ]
            );
            $alertId = (int) db()->lastInsertId();

            if (self::deliveryQueueAvailable()) {
                $channels = [];
                if (($settings['channel_email_enabled'] ?? '0') === '1') {
                    $channels[] = 'email';
                }
                if (($settings['channel_telegram_enabled'] ?? '0') === '1') {
                    $channels[] = 'telegram';
                }
                foreach ($channels as $channel) {
                    db_exec(
                        'INSERT INTO alert_delivery_queue
                      (alert_id, channel, available_at, created_at)
                      VALUES (:alert_id, :channel, NOW(), NOW())
                      ON DUPLICATE KEY UPDATE alert_id = VALUES(alert_id)',
                        [':alert_id' => $alertId, ':channel' => $channel]
                    );
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('AlertService::create failed, rolled back: ' . $e->getMessage());
            return;
        }

        if (!self::deliveryQueueAvailable()) {
            // No synchronous send fallback: delivering here would tie alert
            // creation (often inside the push hot path) to SMTP/Telegram
            // network latency. The delivery worker owns all sending; apply
            // pending migrations so it can pick this alert up from the queue.
            error_log('AlertService::create alert_id=' . $alertId . ' has no delivery queue table; run migrations');
        }
        invalidate_alert_cache();
    }

    /**
     * Mark outstanding alerts as resolved when their monitored condition clears.
     * A recovery notification is kept as a separate event, while the original
     * incident is closed for a complete lifecycle in the alert log.
     */
    public static function resolveConditionAlerts(?int $serverId, array $alertTypes): int
    {
        $alertTypes = array_values(array_filter(array_map(
            static fn(mixed $type): string => trim((string) $type),
            $alertTypes
        ), static fn(string $type): bool => $type !== ''));
        if ($alertTypes === []) {
            return 0;
        }

        $typePlaceholders = [];
        $params = [];
        foreach ($alertTypes as $index => $type) {
            $placeholder = ':alert_type_' . $index;
            $typePlaceholders[] = $placeholder;
            $params[$placeholder] = $type;
        }

        $serverClause = 'server_id IS NULL';
        if ($serverId !== null) {
            $serverClause = 'server_id = :resolve_server_id';
            $params[':resolve_server_id'] = $serverId;
        }

        // NOTE: db_exec() returns bool (statement success), NOT the affected
        // row count. Use db_exec_count() here: callers rely on the return
        // value to decide whether an incident was actually closed (e.g. the
        // service snapshot check only emits a recovery when this is > 0).
        $changed = db_exec_count(
            'UPDATE alert_logs
             SET status = \'resolved\', resolved_at = NOW(), silenced_until = NULL
             WHERE ' . $serverClause . '
               AND alert_type IN (' . implode(', ', $typePlaceholders) . ')
               AND status IN (\'active\', \'acknowledged\', \'silenced\')',
            $params
        );

        if ($changed > 0) {
            invalidate_alert_cache();
        }

        return $changed;
    }

    public static function evaluateServerThresholdAlerts(array $server, array $metric): void
    {
        $serverId = (int) $server['id'];
        if (is_server_in_maintenance($serverId)) {
            return;
        }

        $settings = settings_get_all();
        $serverName = (string) $server['name'];

        self::evaluateThreshold(
            $serverId, $serverName, $settings,
            'mail_queue', (int) ($metric['mail_queue_total'] ?? 0),
            (int) ($settings['threshold_mail_queue'] ?? '50'),
            (int) ($settings['threshold_mail_queue_critical'] ?? '100'),
            ['mail_queue_total' => (int) ($metric['mail_queue_total'] ?? 0)]
        );

        self::evaluateThreshold(
            $serverId, $serverName, $settings,
            'cpu', (float) ($metric['cpu_load'] ?? 0.0),
            (float) ($settings['threshold_cpu_load'] ?? '2.00'),
            (float) ($settings['threshold_cpu_load_critical'] ?? '4.00'),
            ['cpu_load' => (float) ($metric['cpu_load'] ?? 0.0)]
        );

        $ramPct = calculateUsagePercent((int) ($metric['ram_used'] ?? 0), (int) ($metric['ram_total'] ?? 0));
        self::evaluateThreshold(
            $serverId, $serverName, $settings,
            'ram', $ramPct,
            max(0, (float) ($settings['threshold_ram_pct'] ?? '85')),
            max(0, (float) ($settings['threshold_ram_pct_critical'] ?? '95')),
            ['ram_pct' => $ramPct]
        );

        $diskPct = calculateUsagePercent((int) ($metric['hdd_used'] ?? 0), (int) ($metric['hdd_total'] ?? 0));
        self::evaluateThreshold(
            $serverId, $serverName, $settings,
            'disk', $diskPct,
            max(0, (float) ($settings['threshold_disk_pct'] ?? '90')),
            max(0, (float) ($settings['threshold_disk_pct_critical'] ?? '97')),
            ['disk_pct' => $diskPct]
        );
    }

    private static function evaluateThreshold(
        int $serverId,
        string $serverName,
        array $settings,
        string $resource,
        int|float $value,
        int|float $warnThreshold,
        int|float $criticalThreshold,
        array $context
    ): void {
        $criticalType = $resource . '_critical';
        $warningType = $resource . '_high';
        $label = match ($resource) {
            'mail_queue' => 'Mail Queue',
            'cpu' => 'CPU Load',
            'ram' => 'RAM Usage',
            'disk' => 'Disk Usage',
            default => ucfirst($resource),
        };
        $valueDisplay = $resource === 'mail_queue' ? (string) $value : ($resource === 'cpu' ? (string) $value : ((string) $value . '%'));

        if ($value >= $criticalThreshold) {
            self::resolveConditionAlerts($serverId, [$warningType]);
            self::create(
                $serverId,
                $criticalType,
                'danger',
                "[{$serverName}] Critical {$label}",
                "{$label} {$valueDisplay} exceeds critical threshold {$criticalThreshold}" . ($resource === 'cpu' ? '' : '%') . ".",
                array_merge($context, ['threshold' => $criticalThreshold])
            );
        } elseif ($value >= $warnThreshold) {
            self::resolveConditionAlerts($serverId, [$criticalType]);
            self::create(
                $serverId,
                $warningType,
                'warning',
                "[{$serverName}] High {$label}",
                "{$label} {$valueDisplay} exceeds threshold {$warnThreshold}" . ($resource === 'cpu' ? '' : '%') . ".",
                array_merge($context, ['threshold' => $warnThreshold])
            );
        } else {
            self::resolveConditionAlerts($serverId, [$criticalType, $warningType]);
        }
    }

    public static function evaluateServiceTransitionAlerts(array $server, array $transitions): void
    {
        $settings = settings_get_all();
        if (($settings['alert_service_status_enabled'] ?? '1') !== '1') {
            return;
        }

        $serverId = (int) ($server['id'] ?? 0);
        $serverName = (string) ($server['name'] ?? ('Server #' . $serverId));
        $flapSuppressMinutes = max(0, (int) ($settings['alert_service_flap_suppress_minutes'] ?? '5'));
        if ($serverId <= 0 || empty($transitions)) {
            return;
        }
        if (is_server_in_maintenance($serverId)) {
            return;
        }

        foreach ($transitions as $transition) {
            $group = (string) ($transition['service_group'] ?? '');
            $key = (string) ($transition['service_key'] ?? '');
            $unit = (string) ($transition['unit_name'] ?? '');
            $prev = (string) ($transition['prev_status'] ?? '');
            $next = (string) ($transition['new_status'] ?? '');
            $prevChangedAt = (string) ($transition['prev_changed_at'] ?? '');

            if ($key === '' || $group === '' || $unit === '') {
                continue;
            }
            if ($prev === 'unknown' || $next === 'unknown' || $prev === $next) {
                continue;
            }
            if ($flapSuppressMinutes > 0 && $prevChangedAt !== '') {
                $prevChangedTs = strtotime($prevChangedAt);
                if ($prevChangedTs !== false && (time() - $prevChangedTs) < ($flapSuppressMinutes * 60)) {
                    continue;
                }
            }

            $serviceLabel = strtoupper($key);
            if ($prev === 'up' && $next === 'down') {
                self::create(
                    $serverId,
                    'service_down_' . $key,
                    'warning',
                    "[{$serverName}] Service {$serviceLabel} Down",
                    "Service {$serviceLabel} ({$unit}) changed from up to down.",
                    [
                        'service_group' => $group,
                        'service_key' => $key,
                        'unit_name' => $unit,
                        'prev_status' => $prev,
                        'new_status' => $next,
                    ]
                );
                continue;
            }

            if ($prev === 'down' && $next === 'up') {
                self::resolveConditionAlerts($serverId, ['service_down_' . $key]);
                self::create(
                    $serverId,
                    'service_recovery_' . $key,
                    'success',
                    "[{$serverName}] Service {$serviceLabel} Recovery",
                    "Service {$serviceLabel} ({$unit}) recovered from down to up.",
                    [
                        'service_group' => $group,
                        'service_key' => $key,
                        'unit_name' => $unit,
                        'prev_status' => $prev,
                        'new_status' => $next,
                    ]
                );
            }
        }
    }

    public static function evaluateDownRecoveryAlerts(): void
    {
        $settings = settings_get_all();
        $downMinutes = max(1, (int) ($settings['alert_down_minutes'] ?? '5'));

        $servers = db_all(
            'SELECT s.id, s.name, s.active,
                    COALESCE(s.last_seen_at, m.recorded_at) AS last_seen
             FROM servers s' . latest_metric_join_sql('s', 'm') . '
             WHERE s.active = 1'
        );

        $serverIds = array_map(static fn(array $s): int => (int) $s['id'], $servers);
        $states = [];
        if (!empty($serverIds)) {
            $safePlaceholders = [];
            $params = [];
            foreach ($serverIds as $i => $sid) {
                $pk = ':sid_' . $i;
                $safePlaceholders[] = $pk;
                $params[$pk] = $sid;
            }
            $statesRaw = db_all(
                'SELECT server_id, is_down FROM server_states WHERE server_id IN (' . implode(',', $safePlaceholders) . ')',
                $params
            );
            foreach ($statesRaw as $st) {
                $states[(int) $st['server_id']] = (int) $st['is_down'];
            }
        }

        foreach ($servers as $server) {
            $serverId = (int) $server['id'];
            if (is_server_in_maintenance($serverId)) {
                db_exec(
                    'INSERT INTO server_states (server_id, is_down) VALUES (:id, 0)
                     ON DUPLICATE KEY UPDATE is_down = 0',
                    [':id' => $serverId]
                );
                // Maintenance suppresses alerting: resolve any pre-maintenance
                // server_down so it does not stay active forever.
                self::resolveConditionAlerts($serverId, ['server_down']);
                continue;
            }
            $isDown = isset($states[$serverId]) && $states[$serverId] === 1;

            $lastSeen = $server['last_seen'] ?? null;
            $statusNow = 'pending';
            if ($lastSeen !== null && trim((string) $lastSeen) !== '') {
                $seenTs = strtotime((string) $lastSeen);
                if ($seenTs !== false) {
                    $minutes = (time() - $seenTs) / 60;
                    $statusNow = $minutes > $downMinutes ? 'down' : 'online';
                }
            }
            $downNow = $statusNow === 'down';

            if ($downNow && !$isDown) {
                self::create(
                    $serverId,
                    'server_down',
                    'danger',
                    '[' . $server['name'] . '] Server Down',
                    'Server has not sent metrics for more than ' . $downMinutes . ' minutes.',
                    ['last_seen' => $lastSeen]
                );
            }

            if ($statusNow === 'online') {
                self::resolveConditionAlerts($serverId, ['server_down']);
                if ($isDown) {
                    self::create(
                        $serverId,
                        'server_recovery',
                        'success',
                        '[' . $server['name'] . '] Server Recovery',
                        'Server is back online and sending metrics.',
                        ['last_seen' => $lastSeen]
                    );
                }
            }

            db_exec(
                'INSERT INTO server_states (server_id, is_down) VALUES (:id, :is_down)
                 ON DUPLICATE KEY UPDATE is_down = VALUES(is_down)',
                [':id' => $serverId, ':is_down' => $downNow ? 1 : 0]
            );
        }
    }

    public static function evaluateCurrentServiceDownAlerts(): void
    {
        $settings = settings_get_all();
        if (($settings['alert_service_status_enabled'] ?? '1') !== '1') {
            return;
        }
        $statusOnlineMinutes = max(1, (int) ($settings['alert_down_minutes'] ?? '5'));

        $rows = db_all(
            'SELECT s.id AS server_id, s.name AS server_name,
                    COALESCE(s.last_seen_at, m.recorded_at) AS last_seen,
                    st.service_group, st.service_key, st.unit_name, st.last_status, st.updated_at
             FROM servers s' . latest_metric_join_sql('s', 'm') . '
             INNER JOIN server_service_states st ON st.server_id = s.id
             WHERE s.active = 1
             LIMIT 1000'
        );

        foreach ($rows as $row) {
            $serverOnline = serverStatusFromLastSeen($row['last_seen'] ?? null, true, $statusOnlineMinutes) === 'online';
            if (!$serverOnline) {
                continue;
            }

            $status = (string) ($row['last_status'] ?? 'unknown');
            $serverId = (int) ($row['server_id'] ?? 0);
            $serviceKey = (string) ($row['service_key'] ?? '');
            if ($status === 'up') {
                // resolveConditionAlerts() returns 1 only when it actually
                // closed an incident, so the recovery fires exactly once.
                $resolved = self::resolveConditionAlerts($serverId, ['service_down_' . $serviceKey]);
                if ($resolved > 0 && $serverId > 0 && $serviceKey !== '') {
                    $serviceLabel = strtoupper($serviceKey);
                    $serverName = (string) ($row['server_name'] ?? ('Server #' . $serverId));
                    self::create(
                        $serverId,
                        'service_recovery_' . $serviceKey,
                        'success',
                        "[{$serverName}] Service {$serviceLabel} Recovery",
                        "Service {$serviceLabel} (" . (string) ($row['unit_name'] ?? '') . ") recovered from down to up.",
                        [
                            'service_group' => (string) ($row['service_group'] ?? ''),
                            'service_key' => $serviceKey,
                            'unit_name' => (string) ($row['unit_name'] ?? ''),
                            'source' => 'snapshot-check',
                        ]
                    );
                }
            }
            if ($status !== 'down') {
                continue;
            }

            if ($serverId <= 0) {
                continue;
            }
            if (is_server_in_maintenance($serverId)) {
                continue;
            }

            $serverName = (string) ($row['server_name'] ?? ('Server #' . $serverId));
            $serviceKey = (string) ($row['service_key'] ?? '');
            $serviceGroup = (string) ($row['service_group'] ?? '');
            $unitName = (string) ($row['unit_name'] ?? '');
            if ($serviceKey === '' || $serviceGroup === '' || $unitName === '') {
                continue;
            }

            $serviceLabel = strtoupper($serviceKey);
            self::create(
                $serverId,
                'service_down_' . $serviceKey,
                'warning',
                "[{$serverName}] Service {$serviceLabel} Down",
                "Service {$serviceLabel} ({$unitName}) is currently down.",
                [
                    'service_group' => $serviceGroup,
                    'service_key' => $serviceKey,
                    'unit_name' => $unitName,
                    'current_status' => $status,
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'source' => 'snapshot-check',
                ]
            );
        }
    }

    public static function evaluateCurrentPingDownAlerts(): void
    {
        $settings = settings_get_all();
        if (($settings['alert_ping_enabled'] ?? '1') !== '1') {
            return;
        }

        $rows = db_all(
            'SELECT pm.id, pm.name, pm.target, pm.check_method,
                    ps.last_status, ps.last_error, ps.updated_at
             FROM ping_monitors pm
             INNER JOIN ping_monitor_states ps ON ps.monitor_id = pm.id
             WHERE pm.active = 1
             LIMIT 1000'
        );

        foreach ($rows as $row) {
            $lastStatus = (string) ($row['last_status'] ?? 'unknown');
            $monitorId = (int) ($row['id'] ?? 0);
            if ($lastStatus === 'up' && $monitorId > 0) {
                self::resolveConditionAlerts(null, ['ping_down_monitor_' . $monitorId]);
            }
            if ($lastStatus !== 'down') {
                continue;
            }

            if ($monitorId <= 0) {
                continue;
            }

            $name = (string) ($row['name'] ?? ('Ping Monitor #' . $monitorId));
            $target = (string) ($row['target'] ?? '-');
            $method = strtolower((string) ($row['check_method'] ?? 'icmp'));
            $error = trim((string) ($row['last_error'] ?? ''));

            $message = "Target {$target} ({$method}) is currently down.";
            if ($error !== '') {
                $message .= ' Error: ' . $error;
            }

            self::create(
                null,
                'ping_down_monitor_' . $monitorId,
                'danger',
                "[Ping] {$name} Down",
                $message,
                [
                    'monitor_id' => $monitorId,
                    'monitor_name' => $name,
                    'target' => $target,
                    'check_method' => $method,
                    'current_status' => 'down',
                    'error' => $error,
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'source' => 'snapshot-check',
                ]
            );
        }
    }

    public static function evaluatePingMonitorTransitionAlert(array $monitor, array $transition, array $probe): void
    {
        $settings = settings_get_all();
        if (($settings['alert_ping_enabled'] ?? '1') !== '1') {
            return;
        }

        $monitorId = (int) ($monitor['id'] ?? 0);
        if ($monitorId <= 0) {
            return;
        }

        $name = (string) ($monitor['name'] ?? ('Ping Monitor #' . $monitorId));
        $target = (string) ($monitor['target'] ?? '-');
        $method = strtolower((string) ($monitor['check_method'] ?? 'icmp'));
        $prev = (string) ($transition['previous_status'] ?? 'unknown');
        $next = (string) ($transition['current_status'] ?? 'unknown');
        if ($prev === $next) {
            return;
        }

        $latency = $probe['latency_ms'] ?? null;
        $error = trim((string) ($probe['error'] ?? ''));
        $context = [
            'monitor_id' => $monitorId,
            'monitor_name' => $name,
            'target' => $target,
            'check_method' => $method,
            'previous_status' => $prev,
            'current_status' => $next,
            'latency_ms' => $latency,
            'error' => $error,
        ];

        if ($prev === 'down' && $next === 'up') {
            self::resolveConditionAlerts(null, ['ping_down_monitor_' . $monitorId]);
            $latencyText = is_numeric($latency) ? ('Latency: ' . number_format((float) $latency, 2) . ' ms.') : '';
            self::create(
                null,
                'ping_recovery_monitor_' . $monitorId,
                'success',
                "[Ping] {$name} Recovery",
                "Target {$target} ({$method}) is reachable again. {$latencyText}",
                $context
            );
            return;
        }

        if ($next === 'down') {
            $message = "Target {$target} ({$method}) is unreachable.";
            if ($error !== '') {
                $message .= ' Error: ' . $error;
            }
            self::create(
                null,
                'ping_down_monitor_' . $monitorId,
                'danger',
                "[Ping] {$name} Down",
                $message,
                $context
            );
        }
    }

    public static function evaluateIpRepTransitionAlert(array $target, array $transition): void
    {
        $settings = settings_get_all();
        if (($settings['ip_rep_alert_enabled'] ?? '1') !== '1') {
            return;
        }

        $targetId = (int) ($target['id'] ?? 0);
        if ($targetId <= 0) {
            return;
        }

        $ipAddress = (string) ($target['ip_address'] ?? '');
        $label = trim((string) ($target['label'] ?? ''));
        $displayName = $label !== '' ? "{$label} ({$ipAddress})" : $ipAddress;
        $prevStatus = (string) ($transition['prev_status'] ?? 'unknown');
        $newStatus = (string) ($transition['new_status'] ?? 'unknown');
        $listedOn = $transition['listed_on'] ?? [];

        if ($prevStatus === $newStatus) {
            return;
        }

        $serverId = isset($target['server_id']) && (int) $target['server_id'] > 0 ? (int) $target['server_id'] : null;

        $context = [
            'ip_rep_target_id' => $targetId,
            'ip_address'       => $ipAddress,
            'label'            => $label,
            'prev_status'      => $prevStatus,
            'new_status'       => $newStatus,
            'listed_on'        => $listedOn,
            'listed_count'     => (int) ($transition['listed_count'] ?? 0),
        ];

        if ($newStatus === 'listed') {
            // Linked-server maintenance suppresses new listing alerts, but a
            // later clean transition still resolves (see below).
            if ($serverId !== null && is_server_in_maintenance($serverId)) {
                return;
            }
            $blacklists = !empty($listedOn) ? ' Blacklists: ' . implode(', ', $listedOn) . '.' : '';
            self::create(
                $serverId,
                'ip_rep_listed_' . $targetId,
                'danger',
                "[IP Rep] {$displayName} Blacklisted",
                "IP {$ipAddress} is now listed on " . count($listedOn) . " blacklist(s).{$blacklists}",
                $context
            );
            return;
        }

        if ($prevStatus === 'listed' && $newStatus === 'clean') {
            self::resolveConditionAlerts($serverId, ['ip_rep_listed_' . $targetId]);
            self::create(
                $serverId,
                'ip_rep_clean_' . $targetId,
                'success',
                "[IP Rep] {$displayName} Cleared",
                "IP {$ipAddress} has been removed from all blacklists and is now clean.",
                $context
            );
        }
    }
}
