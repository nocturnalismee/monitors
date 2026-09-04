<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;

final class PushController
{
    private const PUSH_MAX_BODY_BYTES = 1048576;

    public function index(Request $request): Response
    {
        if (strtoupper($request->method) !== 'POST') {
            header('Allow: POST');
            json_response(['error' => 'Method not allowed'], 405);
        }

        $contentType = strtolower(trim((string) ($request->header('Content-Type') ?? '')));
        if (!str_starts_with($contentType, 'application/json')) {
            json_response(['error' => 'Content-Type must be application/json'], 415);
        }

        $contentLength = $request->header('Content-Length') !== null
            ? max(0, (int) $request->header('Content-Length'))
            : 0;
        if ($contentLength > self::PUSH_MAX_BODY_BYTES) {
            json_response(['error' => 'Payload too large'], 413);
        }

        $token = (string) ($request->header('X-Server-Token') ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            json_response(['error' => 'Invalid token'], 403);
        }

        try {
            $server = null;
            $tokenHash = hash('sha256', $token);
            $hasTokenHash = db_column_exists('servers', 'token_hash');
            $tokenLookup = $hasTokenHash
                ? 'token_hash = :token_hash OR token = :token'
                : 'token = :token';
            $tokenParams = [':token' => $token];
            if ($hasTokenHash) {
                $tokenParams[':token_hash'] = $tokenHash;
            }
            if (db_column_exists('servers', 'push_allowed_ips')) {
                $server = db_one(
                    'SELECT id, active, push_allowed_ips FROM servers WHERE (' . $tokenLookup . ') LIMIT 1',
                    $tokenParams
                );
            } else {
                $server = db_one(
                    'SELECT id, active, NULL AS push_allowed_ips FROM servers WHERE (' . $tokenLookup . ') LIMIT 1',
                    $tokenParams
                );
            }
        } catch (Throwable $e) {
            error_log('push.php server lookup failed error=' . $e->getMessage());
            json_response(['error' => 'Database unavailable'], 503);
        }
        if ($server === null || (int) $server['active'] !== 1) {
            json_response(['error' => 'Invalid token'], 403);
        }
        $clientIp = get_client_ip();
        $allowlist = trim((string) ($server['push_allowed_ips'] ?? ''));
        if ($allowlist !== '' && !self::ipInAllowlist($clientIp, $allowlist)) {
            json_response(['error' => 'Source IP not allowed'], 403);
        }

        // Rate limit per server token + client IP to prevent token leaks from flooding ingest.
        // Configurable via push_api_rate_per_minute (default 600) to accommodate 10s+ deadband agents.
        $pushRatePerMinute = self::safeSettingInt('push_api_rate_per_minute', 600);
        if (!api_rate_check('push_api', (int) $server['id'] . ':' . $clientIp, $pushRatePerMinute)) {
            api_rate_limit_exceeded();
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw)) {
            json_response(['error' => 'Unable to read request body'], 400);
        }
        if (trim($raw) === '') {
            json_response(['error' => 'Empty request body'], 400);
        }
        if (strlen($raw) > self::PUSH_MAX_BODY_BYTES) {
            json_response(['error' => 'Payload too large'], 413);
        }
        $signedTimestamp = trim((string) ($request->header('X-Server-Timestamp') ?? ''));
        $signedSignature = strtolower(trim((string) ($request->header('X-Server-Signature') ?? '')));
        $signatureRequired = self::safeSettingGet('agent_push_signature_required', '1') === '1';

        if ($signedTimestamp !== '' || $signedSignature !== '' || $signatureRequired) {
            if ($signedTimestamp === '' || preg_match('/^\d{10}$/', $signedTimestamp) !== 1) {
                json_response(['error' => 'Invalid or missing X-Server-Timestamp'], 400);
            }
            if ($signedSignature === '' || preg_match('/^[a-f0-9]{64}$/', $signedSignature) !== 1) {
                json_response(['error' => 'Invalid or missing X-Server-Signature'], 400);
            }

            $requestTs = (int) $signedTimestamp;
            if (abs(time() - $requestTs) > 60) {
                json_response(['error' => 'Signature timestamp expired'], 403);
            }

            $expectedSig = hash_hmac('sha256', $signedTimestamp . '.' . (string) $raw, $token);
            if (!hash_equals($expectedSig, $signedSignature)) {
                json_response(['error' => 'Invalid request signature'], 403);
            }
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            json_response(['error' => 'Invalid JSON payload'], 400);
        }

        $serverId = isset($data['server_id']) ? (int) $data['server_id'] : (int) $server['id'];
        if ($serverId !== (int) $server['id']) {
            json_response(['error' => 'server_id does not match token'], 400);
        }

        $mail = $data['mail'] ?? [];
        $mailMta = 'none';
        $queueTotal = 0;

        if (is_array($mail)) {
            $mailMta = (string) ($mail['mta'] ?? $mail['mail_mta'] ?? 'none');
            $queueTotal = (int) ($mail['queue_total'] ?? $mail['mail_queue_total'] ?? $mail['queue'] ?? 0);
        } else {
            // Backward compatibility for agents that still send top-level mail keys.
            $mailMta = (string) ($data['mail_mta'] ?? 'none');
            $queueTotal = (int) ($data['mail_queue_total'] ?? $data['queue_total'] ?? $data['mail_queue'] ?? 0);
        }

        $mailMta = strtolower(trim($mailMta));
        $network = $data['network'] ?? [];
        $networkIn = is_array($network) ? (int) ($network['in_bps'] ?? 0) : 0;
        $networkOut = is_array($network) ? (int) ($network['out_bps'] ?? 0) : 0;
        $panelProfile = strtolower(trim((string) ($data['panel_profile'] ?? 'generic')));
        $storeAllServiceSamples = self::safeSettingGet('service_metrics_store_all', '0') === '1';

        $allowedPanelProfiles = ['cpanel', 'cpanel_mail', 'cpanel_email', 'plesk', 'directadmin', 'cyberpanel', 'aapanel', 'generic'];
        if (!in_array($panelProfile, $allowedPanelProfiles, true)) {
            $panelProfile = 'generic';
        }
        if ($panelProfile === 'cpanel_email') {
            $panelProfile = 'cpanel_mail';
        }

        $allowedMta = ['postfix', 'exim', 'qmail', 'none'];
        if (!in_array($mailMta, $allowedMta, true)) {
            $mailMta = 'none';
        }

        $allowedServiceGroups = ['webserver', 'mail_mta', 'mail_access', 'mail_service', 'ssh', 'ftp', 'database', 'firewall'];
        $allowedServiceKeys = [
            'apache', 'nginx', 'litespeed',
            'postfix', 'exim', 'qmail', 'sendmail',
            'dovecot', 'courier', 'pop3', 'imap', 'mailman',
            'sshd', 'pureftpd', 'xinetd', 'mariadb',
            'csf', 'imunify360', 'imunifyav', 'fail2ban', 'clamd', 'spamd',
        ];
        $allowedServiceStatus = ['up', 'down', 'unknown'];
        $allowedServiceSources = ['systemctl', 'service', 'pgrep'];
        $panelServiceKeys = [
            'cpanel' => ['apache', 'nginx', 'litespeed', 'csf', 'imunify360', 'imunifyav', 'mariadb', 'pureftpd', 'dovecot', 'exim', 'sshd', 'postfix'],
            'cpanel_mail' => ['exim', 'dovecot', 'mailman', 'csf', 'clamd', 'spamd', 'sshd'],
            'plesk' => ['apache', 'nginx', 'litespeed', 'mariadb', 'postfix', 'dovecot', 'xinetd', 'sshd', 'imunify360', 'imunifyav', 'fail2ban'],
            'directadmin' => ['apache', 'nginx', 'litespeed', 'mariadb', 'exim', 'postfix', 'dovecot', 'pureftpd', 'sshd', 'csf', 'imunify360', 'imunifyav', 'fail2ban'],
            'cyberpanel' => ['litespeed', 'mariadb', 'postfix', 'dovecot', 'pureftpd', 'sshd', 'imunify360', 'imunifyav', 'fail2ban'],
            'aapanel' => ['apache', 'nginx', 'mariadb', 'pureftpd', 'sshd', 'fail2ban'],
            'generic' => ['apache', 'nginx', 'mariadb', 'postfix', 'exim', 'sshd'],
        ];
        $servicesByKey = [];
        $rejectedServiceCounters = [];
        $rejectService = static function (string $reason) use (&$rejectedServiceCounters): void {
            if (!isset($rejectedServiceCounters[$reason])) {
                $rejectedServiceCounters[$reason] = 0;
            }
            $rejectedServiceCounters[$reason]++;
        };

        if (isset($data['services']) && is_array($data['services'])) {
            foreach ($data['services'] as $item) {
                if (!is_array($item)) {
                    $rejectService('invalid_item_type');
                    continue;
                }

                $serviceGroup = strtolower(trim((string) ($item['group'] ?? '')));
                $serviceKey = strtolower(trim((string) ($item['service_key'] ?? '')));
                $unitName = trim((string) ($item['unit_name'] ?? ''));
                $serviceStatus = strtolower(trim((string) ($item['status'] ?? 'unknown')));
                $serviceSource = strtolower(trim((string) ($item['source'] ?? 'pgrep')));

                if ($serviceGroup === '' || $serviceKey === '' || $unitName === '') {
                    $rejectService('missing_required_field');
                    continue;
                }
                if (!in_array($serviceGroup, $allowedServiceGroups, true)) {
                    $rejectService('invalid_group');
                    continue;
                }
                if (!in_array($serviceKey, $allowedServiceKeys, true)) {
                    $rejectService('invalid_key');
                    continue;
                }
                if (!in_array($serviceStatus, $allowedServiceStatus, true)) {
                    $rejectService('invalid_status');
                    continue;
                }
                if (!in_array($serviceSource, $allowedServiceSources, true)) {
                    $rejectService('invalid_source');
                    continue;
                }
                if (!in_array($serviceKey, $panelServiceKeys[$panelProfile] ?? [], true)) {
                    $rejectService('disallowed_for_panel_profile');
                    continue;
                }

                $servicesByKey[$serviceGroup . '|' . $serviceKey] = [
                    'service_group' => $serviceGroup,
                    'service_key' => $serviceKey,
                    'unit_name' => substr($unitName, 0, 64),
                    'status' => $serviceStatus,
                    'source' => $serviceSource,
                ];
            }
        }
        $services = array_values($servicesByKey);
        if (!empty($rejectedServiceCounters)) {
            error_log('push.php service rejected server_id=' . $serverId . ' panel=' . $panelProfile . ' reasons=' . json_encode($rejectedServiceCounters));
        }

        $metricValues = [
            'server_id' => $serverId,
            'uptime' => max(0, (int) ($data['uptime'] ?? 0)),
            'ram_total' => max(0, (int) ($data['ram_total'] ?? 0)),
            'ram_used' => max(0, (int) ($data['ram_used'] ?? 0)),
            'hdd_total' => max(0, (int) ($data['hdd_total'] ?? 0)),
            'hdd_used' => max(0, (int) ($data['hdd_used'] ?? 0)),
            'cpu_load' => (float) ($data['cpu_load'] ?? 0.0),
            'network_in_bps' => max(0, $networkIn),
            'network_out_bps' => max(0, $networkOut),
            'mail_mta' => $mailMta,
            'mail_queue_total' => max(0, $queueTotal),
            'panel_profile' => $panelProfile,
        ];

        $metricColumns = ['server_id', 'uptime', 'ram_total', 'ram_used', 'hdd_total', 'hdd_used', 'cpu_load'];
        foreach (['network_in_bps', 'network_out_bps', 'mail_mta', 'mail_queue_total', 'panel_profile'] as $col) {
            if (db_column_exists('metrics', $col)) {
                $metricColumns[] = $col;
            }
        }

        $metricPlaceholders = [];
        $metricParams = [];
        foreach ($metricColumns as $col) {
            $ph = ':' . $col;
            $metricPlaceholders[] = $ph;
            $metricParams[$ph] = $metricValues[$col];
        }
        $ingestPdo = db();
        $ingestPdo->beginTransaction();
        try {
            db_exec(
                'INSERT INTO metrics (' . implode(', ', $metricColumns) . ', recorded_at) VALUES (' . implode(', ', $metricPlaceholders) . ', NOW())',
                $metricParams
            );
        } catch (Throwable $e) {
            if ($ingestPdo->inTransaction()) {
                $ingestPdo->rollBack();
            }
            error_log('push.php metrics insert failed server_id=' . $serverId . ' error=' . $e->getMessage());
            json_response(['error' => 'Failed to persist metrics'], 500);
        }
        $metricId = (int) db()->lastInsertId();

        // Update latest_metric_id pointer for optimized JOIN queries
        if ($metricId > 0 && db_column_exists('servers', 'latest_metric_id')) {
            try {
                $lastSeenSql = db_column_exists('servers', 'last_seen_at') ? ', last_seen_at = NOW()' : '';
                db_exec('UPDATE servers SET latest_metric_id = :mid' . $lastSeenSql . ' WHERE id = :sid', [
                    ':mid' => $metricId,
                    ':sid' => $serverId,
                ]);
            } catch (Throwable $e) {
                // The column existence check above handles older schemas. If the
                // update itself fails, keep metric and pointer state atomic.
                if ($ingestPdo->inTransaction()) {
                    $ingestPdo->rollBack();
                }
                error_log('push.php latest metric update failed server_id=' . $serverId . ' error=' . $e->getMessage());
                json_response(['error' => 'Failed to update latest metric'], 500);
            }
        }

        $serviceTransitions = [];
        if (
            $metricId > 0 &&
            !empty($services) &&
            self::dbTableExists('service_metrics') &&
            self::dbTableExists('server_service_states')
        ) {
            try {
                $prevRows = db_all(
                    'SELECT service_group, service_key, last_status, last_change_at
                     FROM server_service_states
                     WHERE server_id = :server_id',
                    [':server_id' => $serverId]
                );
                $prevStateMap = [];
                foreach ($prevRows as $prevRow) {
                    $prevKey = (string) ($prevRow['service_group'] ?? '') . '|' . (string) ($prevRow['service_key'] ?? '');
                    if ($prevKey === '|') {
                        continue;
                    }
                    $prevStateMap[$prevKey] = [
                        'last_status' => (string) ($prevRow['last_status'] ?? 'unknown'),
                        'last_change_at' => $prevRow['last_change_at'] ?? null,
                    ];
                }

                $changedServices = [];
                foreach ($services as $service) {
                    $serviceMapKey = $service['service_group'] . '|' . $service['service_key'];
                    $prevState = $prevStateMap[$serviceMapKey] ?? null;
                    if ($prevState === null) {
                        $changedServices[] = $service;
                        continue;
                    }

                    if ($prevState['last_status'] !== $service['status']) {
                        $changedServices[] = $service;
                        $serviceTransitions[] = [
                            'service_group' => $service['service_group'],
                            'service_key' => $service['service_key'],
                            'unit_name' => $service['unit_name'],
                            'prev_status' => $prevState['last_status'],
                            'new_status' => $service['status'],
                            'prev_changed_at' => $prevState['last_change_at'],
                        ];
                    }
                }
                if ($storeAllServiceSamples) {
                    $changedServices = $services;
                }

                foreach ($changedServices as $service) {
                    db_exec(
                        'INSERT INTO service_metrics (
                            metric_id, server_id, service_group, service_key, unit_name, status, source, recorded_at
                        ) VALUES (
                            :metric_id, :server_id, :service_group, :service_key, :unit_name, :status, :source, NOW()
                        )',
                        [
                            ':metric_id' => $metricId,
                            ':server_id' => $serverId,
                            ':service_group' => $service['service_group'],
                            ':service_key' => $service['service_key'],
                            ':unit_name' => $service['unit_name'],
                            ':status' => $service['status'],
                            ':source' => $service['source'],
                        ]
                    );

                    db_exec(
                        'INSERT INTO server_service_states (
                            server_id, service_group, service_key, unit_name, last_status, last_change_at, updated_at
                         ) VALUES (
                            :server_id, :service_group, :service_key, :unit_name, :last_status, NOW(), NOW()
                         )
                         ON DUPLICATE KEY UPDATE
                            unit_name = VALUES(unit_name),
                            last_status = VALUES(last_status),
                            last_change_at = IF(last_status <> VALUES(last_status), NOW(), last_change_at),
                            updated_at = NOW()',
                        [
                            ':server_id' => $serverId,
                            ':service_group' => $service['service_group'],
                            ':service_key' => $service['service_key'],
                            ':unit_name' => $service['unit_name'],
                            ':last_status' => $service['status'],
                        ]
                    );
                }
            } catch (Throwable $e) {
                if ($ingestPdo->inTransaction()) {
                    $ingestPdo->rollBack();
                }
                error_log('push.php service write failed server_id=' . $serverId . ' error=' . $e->getMessage());
                json_response(['error' => 'Failed to persist service metrics'], 500);
            }
        } elseif ($metricId > 0 && !empty($services)) {
            error_log('push.php service tables missing, skip service write server_id=' . $serverId);
        }

        try {
            $ingestPdo->commit();
        } catch (Throwable $e) {
            if ($ingestPdo->inTransaction()) {
                $ingestPdo->rollBack();
            }
            error_log('push.php ingest commit failed server_id=' . $serverId . ' error=' . $e->getMessage());
            json_response(['error' => 'Failed to commit metrics'], 500);
        }

        if ($metricId > 0) {
            self::publishLive($serverId, $metricId, $metricValues);
        }

        try {
            $serverInfo = db_one('SELECT id, name FROM servers WHERE id = :id LIMIT 1', [':id' => $serverId]);
            $maintenanceActive = false;
            try {
                $maintenanceActive = is_server_in_maintenance($serverId);
            } catch (Throwable $e) {
                error_log('push.php maintenance check failed server_id=' . $serverId . ' error=' . $e->getMessage());
            }

            $state = null;
            if (self::dbTableExists('server_states')) {
                $state = db_one('SELECT is_down FROM server_states WHERE server_id = :id LIMIT 1', [':id' => $serverId]);
            }

            if (!$maintenanceActive && $state !== null && (int) $state['is_down'] === 1) {
                create_alert(
                    $serverId,
                    'server_recovery',
                    'success',
                    '[' . ($serverInfo['name'] ?? ('Server #' . $serverId)) . '] Server Recovery',
                    'Server is back online and sending metrics.',
                    ['recorded_at' => date('Y-m-d H:i:s')]
                );
            }

            if (self::dbTableExists('server_states')) {
                db_exec(
                    'INSERT INTO server_states (server_id, is_down) VALUES (:id, 0)
                     ON DUPLICATE KEY UPDATE is_down = 0',
                    [':id' => $serverId]
                );
            }

            if ($serverInfo !== null && !$maintenanceActive) {
                $thresholdThrottle = (int) ($data['ts'] ?? time());
                if (($thresholdThrottle % 60) < 10) {
                    evaluate_server_threshold_alerts($serverInfo, [
                        'mail_queue_total' => $metricValues['mail_queue_total'],
                        'cpu_load' => $metricValues['cpu_load'],
                        'ram_used' => $metricValues['ram_used'],
                        'ram_total' => $metricValues['ram_total'],
                        'hdd_used' => $metricValues['hdd_used'],
                        'hdd_total' => $metricValues['hdd_total'],
                    ]);
                }
                evaluate_service_transition_alerts($serverInfo, $serviceTransitions);
            }
        } catch (Throwable $e) {
            error_log('push.php post-metric processing failed server_id=' . $serverId . ' error=' . $e->getMessage());
        }
        invalidate_status_cache($serverId);

        $recordedAt = db_one('SELECT DATE_FORMAT(NOW(), "%Y-%m-%d %H:%i:%s") AS ts');
        return Response::json(['status' => 'ok', 'recorded_at' => $recordedAt['ts'] ?? date('Y-m-d H:i:s')], 200);
    }

    private static function dbTableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        try {
            $row = db_one(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table
                 LIMIT 1",
                [':table' => $table]
            );
            $cache[$table] = ($row !== null);
        } catch (Throwable) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }

    private static function safeSettingGet(string $key, string $fallback = '0'): string
    {
        try {
            return setting_get($key);
        } catch (Throwable) {
            return $fallback;
        }
    }

    private static function safeSettingInt(string $key, int $fallback): int
    {
        $value = (int) self::safeSettingGet($key, (string) $fallback);
        return $value > 0 ? $value : $fallback;
    }

    private static function publishLive(int $serverId, int $metricId, array $metricValues): void
    {
        $redis = redis_client();
        if (!$redis) {
            return;
        }
        try {
            $payload = [
                'id' => $metricId,
                'server_id' => $serverId,
                'recorded_at' => date('Y-m-d H:i:s'),
                'cpu_load' => $metricValues['cpu_load'],
                'ram_used' => $metricValues['ram_used'],
                'ram_total' => $metricValues['ram_total'],
                'hdd_used' => $metricValues['hdd_used'],
                'hdd_total' => $metricValues['hdd_total'],
                'network_in_bps' => $metricValues['network_in_bps'],
                'network_out_bps' => $metricValues['network_out_bps'],
                'mail_queue_total' => $metricValues['mail_queue_total'],
                'uptime' => $metricValues['uptime'],
                'panel_profile' => $metricValues['panel_profile'],
            ];
            $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ringKey = cache_key('ring:' . $serverId);
            $redis->rPush($ringKey, $json);
            $redis->lTrim($ringKey, -200, -1);
            $redis->expire($ringKey, 3600);
            $redis->publish(cache_key('live:' . $serverId), $json);
        } catch (Throwable $e) {
            error_log('push.php publish_live failed server_id=' . $serverId . ' error=' . $e->getMessage());
        }
    }

    private static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (str_contains($cidr, '/') === false) {
            return false;
        }
        [$network, $prefix] = explode('/', $cidr, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $prefixInt = (int) $prefix;
        if ($prefixInt < 0 || $prefixInt > 32) {
            return false;
        }
        $ipLong = ip2long($ip);
        $netLong = ip2long($network);
        if ($ipLong === false || $netLong === false) {
            return false;
        }
        $mask = $prefixInt === 0 ? 0 : (-1 << (32 - $prefixInt));
        return (($ipLong & $mask) === ($netLong & $mask));
    }

    private static function ipInAllowlist(string $ip, string $allowlist): bool
    {
        $entries = preg_split('/[\s,]+/', trim($allowlist)) ?: [];
        foreach ($entries as $entry) {
            $candidate = trim($entry);
            if ($candidate === '') {
                continue;
            }
            if (str_contains($candidate, '/')) {
                if (self::ipMatchesCidr($ip, $candidate)) {
                    return true;
                }
                continue;
            }
            if (strcasecmp($ip, $candidate) === 0) {
                return true;
            }
        }
        return false;
    }
}
