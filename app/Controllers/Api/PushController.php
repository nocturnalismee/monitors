<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use Throwable;

final class PushController
{
    private const PUSH_MAX_BODY_BYTES = 1048576;

    public function index(Request $request): Response
    {
        // Shared ingest auth: token lookup, IP allowlist, per-server rate
        // limit (configurable via push_api_rate_per_minute, default 600),
        // body caps, and HMAC-SHA256 request signing.
        $auth = \App\Services\PushAuthService::authenticate($request, [
            'max_body_bytes' => self::PUSH_MAX_BODY_BYTES,
            'rate_key' => 'push_api',
            'rate_max' => self::safeSettingInt('push_api_rate_per_minute', 600),
            'signature_required' => self::safeSettingGet('agent_push_signature_required', '1') === '1',
        ]);
        $serverId = $auth['server_id'];
        $agentTs = $auth['agent_ts'];
        $data = $auth['data'];

        $ingestLagMs = \App\Services\Slo\IngestLagService::computeLagMs(time(), $agentTs);

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
            'ingest_lag_ms' => $ingestLagMs,
        ];

        $metricColumns = ['server_id', 'uptime', 'ram_total', 'ram_used', 'hdd_total', 'hdd_used', 'cpu_load'];
        foreach (['network_in_bps', 'network_out_bps', 'mail_mta', 'mail_queue_total', 'panel_profile', 'ingest_lag_ms'] as $col) {
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
                // Best-effort pointer: a contended servers row must not
                // discard the already-persisted metric sample above.
                error_log('push.php latest metric update failed server_id=' . $serverId . ' error=' . $e->getMessage());
            }
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

        // Service samples + state upserts run AFTER the metric commit in a
        // short dedicated transaction: holding the metric transaction across
        // per-service writes inflated lock time on every push.
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
                    if ($prevState === null || $prevState['last_status'] !== $service['status']) {
                        $changedServices[] = $service;
                    }
                }
                if ($storeAllServiceSamples) {
                    $changedServices = $services;
                }

                self::persistServiceStates($serverId, $metricId, $changedServices);
            } catch (Throwable $e) {
                // Best-effort: the metric above is already committed, so a
                // service-write failure must not fail the push (the agent
                // retry would duplicate the metric sample).
                error_log('push.php service write failed server_id=' . $serverId . ' error=' . $e->getMessage());
            }
        } elseif ($metricId > 0 && !empty($services)) {
            error_log('push.php service tables missing, skip service write server_id=' . $serverId);
        }

        if ($metricId > 0) {
            self::publishLive($serverId, $metricId, $metricValues);
        }

        // Threshold, recovery, and service-transition evaluation is deferred
        // to the alert-check worker (1/min): running it here kept every push
        // waiting on maintenance checks, state writes, and SMTP-adjacent
        // alert creation. See AlertService::evaluateDownRecoveryAlerts(),
        // evaluateServerThresholdAlerts(), evaluateCurrentServiceDownAlerts().
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

    /**
     * Batch-persist service samples + state upserts in one short transaction:
     * two round-trips regardless of service count.
     */
    private static function persistServiceStates(int $serverId, int $metricId, array $services): void
    {
        $services = array_values($services);
        if ($services === []) {
            return;
        }
        $metricRows = [];
        $metricParams = [];
        $stateRows = [];
        $stateParams = [];
        foreach ($services as $i => $service) {
            $metricRows[] = '(:metric_id_' . $i . ', :server_id_' . $i . ', :service_group_' . $i
                . ', :service_key_' . $i . ', :unit_name_' . $i . ', :status_' . $i . ', :source_' . $i . ', NOW())';
            $metricParams[':metric_id_' . $i] = $metricId;
            $metricParams[':server_id_' . $i] = $serverId;
            $metricParams[':service_group_' . $i] = $service['service_group'];
            $metricParams[':service_key_' . $i] = $service['service_key'];
            $metricParams[':unit_name_' . $i] = $service['unit_name'];
            $metricParams[':status_' . $i] = $service['status'];
            $metricParams[':source_' . $i] = $service['source'];

            $stateRows[] = '(:st_server_id_' . $i . ', :st_service_group_' . $i . ', :st_service_key_' . $i
                . ', :st_unit_name_' . $i . ', :st_last_status_' . $i . ', NOW(), NOW())';
            $stateParams[':st_server_id_' . $i] = $serverId;
            $stateParams[':st_service_group_' . $i] = $service['service_group'];
            $stateParams[':st_service_key_' . $i] = $service['service_key'];
            $stateParams[':st_unit_name_' . $i] = $service['unit_name'];
            $stateParams[':st_last_status_' . $i] = $service['status'];
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_exec(
                'INSERT INTO service_metrics (
                    metric_id, server_id, service_group, service_key, unit_name, status, source, recorded_at
                 ) VALUES ' . implode(', ', $metricRows),
                $metricParams
            );
            db_exec(
                'INSERT INTO server_service_states (
                    server_id, service_group, service_key, unit_name, last_status, last_change_at, updated_at
                 ) VALUES ' . implode(', ', $stateRows) . '
                 ON DUPLICATE KEY UPDATE
                    unit_name = VALUES(unit_name),
                    last_status = VALUES(last_status),
                    last_change_at = IF(last_status <> VALUES(last_status), NOW(), last_change_at),
                    updated_at = NOW()',
                $stateParams
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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

}
