<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;

final class PushDiskController
{
    private const DISK_ALLOWED_STATUS = ['ok', 'warning', 'critical', 'unknown'];
    private const DISK_ALLOWED_TYPES = ['ssd', 'hdd', 'nvme', 'unknown'];
    private const DISK_ALLOWED_SOURCES = ['hdsentinel', 'smartctl'];

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

        // Keep ingest fast-fail to avoid wasting DB resources on oversized payloads.
        $maxBodyBytes = self::diskSettingInt('disk_push_max_body_bytes', 1048576);
        $contentLength = $request->header('Content-Length') !== null
            ? max(0, (int) $request->header('Content-Length'))
            : 0;
        if ($contentLength > $maxBodyBytes) {
            json_response(['error' => 'Payload too large'], 413);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(15);
        }

        if (!self::dbTableExists('disk_health_states') || !self::dbTableExists('disk_health_metrics')) {
            json_response(['error' => 'Disk health tables are not ready'], 503);
        }

        $token = (string) ($request->header('X-Server-Token') ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            json_response(['error' => 'Invalid token'], 403);
        }

        try {
            $tokenHash = hash('sha256', $token);
            $server = db_one(
                'SELECT id, active FROM servers WHERE token_hash = :token_hash LIMIT 1',
                [':token_hash' => $tokenHash]
            );
        } catch (Throwable $e) {
            error_log('push-disk.php server lookup failed error=' . $e->getMessage());
            json_response(['error' => 'Database unavailable'], 503);
        }

        if ($server === null || (int) ($server['active'] ?? 0) !== 1) {
            json_response(['error' => 'Invalid token'], 403);
        }

        // Rate limit per server token + client IP.
        $clientIp = get_client_ip();
        if (!api_rate_check('push_disk_api', (int) $server['id'] . ':' . $clientIp, 120)) {
            api_rate_limit_exceeded();
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw)) {
            json_response(['error' => 'Unable to read request body'], 400);
        }
        if (trim($raw) === '') {
            json_response(['error' => 'Empty request body'], 400);
        }
        if (strlen($raw) > $maxBodyBytes) {
            json_response(['error' => 'Payload too large'], 413);
        }

        $signedTimestamp = trim((string) ($request->header('X-Server-Timestamp') ?? ''));
        $signedSignature = strtolower(trim((string) ($request->header('X-Server-Signature') ?? '')));
        $signatureRequired = self::diskSettingGet('agent_push_signature_required', '1') === '1';

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

        $payload = json_decode((string) $raw, true);
        if (!is_array($payload)) {
            json_response(['error' => 'Invalid JSON payload'], 400);
        }

        $serverId = isset($payload['server_id']) ? (int) $payload['server_id'] : (int) ($server['id'] ?? 0);
        if ($serverId !== (int) ($server['id'] ?? 0)) {
            json_response(['error' => 'server_id does not match token'], 400);
        }

        $items = $payload['disk_health'] ?? $payload['disks'] ?? null;
        if (!is_array($items)) {
            json_response(['error' => 'disk_health array is required'], 400);
        }
        $maxItems = self::diskSettingInt('disk_push_max_items', 64);
        if (count($items) > $maxItems) {
            json_response([
                'error' => 'Too many disk_health items',
                'max_items' => $maxItems,
            ], 422);
        }

        $accepted = 0;
        $rejected = 0;
        $nowExpr = 'NOW()';
        $pdo = db();

        try {
            $pdo->beginTransaction();

            foreach ($items as $item) {
                if (!is_array($item)) {
                    $rejected++;
                    continue;
                }

                $normalized = self::diskNormalizeItem($item);
                if ($normalized === null) {
                    $rejected++;
                    continue;
                }

                db_exec(
                    'INSERT INTO disk_health_metrics (
                        server_id, disk_key, device_name, model, serial,
                        health_status, health_score, temperature_c, power_on_time, total_written_bytes,
                        source_tool, raw_summary, recorded_at
                     ) VALUES (
                        :server_id, :disk_key, :device_name, :model, :serial,
                        :health_status, :health_score, :temperature_c, :power_on_time, :total_written_bytes,
                        :source_tool, :raw_summary, ' . $nowExpr . '
                     )',
                    [
                        ':server_id' => $serverId,
                        ':disk_key' => $normalized['disk_key'],
                        ':device_name' => $normalized['device_name'],
                        ':model' => $normalized['model'],
                        ':serial' => $normalized['serial'],
                        ':health_status' => $normalized['health_status'],
                        ':health_score' => $normalized['health_score'],
                        ':temperature_c' => $normalized['temperature_c'],
                        ':power_on_time' => $normalized['power_on_time'],
                        ':total_written_bytes' => $normalized['total_written_bytes'],
                        ':source_tool' => $normalized['source_tool'],
                        ':raw_summary' => $normalized['raw_summary'],
                    ]
                );

                db_exec(
                    'INSERT INTO disk_health_states (
                        server_id, disk_key, device_name, model, serial, firmware, disk_type, capacity_bytes,
                        health_status, health_score, temperature_c, power_on_time,
                        reallocated_sectors, pending_sectors, uncorrectable_sectors, wearout_pct, total_written_bytes,
                        source_tool, last_error, last_change_at, updated_at
                     ) VALUES (
                        :server_id, :disk_key, :device_name, :model, :serial, :firmware, :disk_type, :capacity_bytes,
                        :health_status, :health_score, :temperature_c, :power_on_time,
                        :reallocated_sectors, :pending_sectors, :uncorrectable_sectors, :wearout_pct, :total_written_bytes,
                        :source_tool, :last_error, ' . $nowExpr . ', ' . $nowExpr . '
                     )
                     ON DUPLICATE KEY UPDATE
                        device_name = VALUES(device_name),
                        model = VALUES(model),
                        serial = VALUES(serial),
                        firmware = VALUES(firmware),
                        disk_type = VALUES(disk_type),
                        capacity_bytes = VALUES(capacity_bytes),
                        health_score = VALUES(health_score),
                        temperature_c = VALUES(temperature_c),
                        power_on_time = VALUES(power_on_time),
                        reallocated_sectors = VALUES(reallocated_sectors),
                        pending_sectors = VALUES(pending_sectors),
                        uncorrectable_sectors = VALUES(uncorrectable_sectors),
                        wearout_pct = VALUES(wearout_pct),
                        total_written_bytes = VALUES(total_written_bytes),
                        source_tool = VALUES(source_tool),
                        last_error = VALUES(last_error),
                        last_change_at = IF(health_status <> VALUES(health_status), ' . $nowExpr . ', last_change_at),
                        health_status = VALUES(health_status),
                        updated_at = ' . $nowExpr,
                    [
                        ':server_id' => $serverId,
                        ':disk_key' => $normalized['disk_key'],
                        ':device_name' => $normalized['device_name'],
                        ':model' => $normalized['model'],
                        ':serial' => $normalized['serial'],
                        ':firmware' => $normalized['firmware'],
                        ':disk_type' => $normalized['disk_type'],
                        ':capacity_bytes' => $normalized['capacity_bytes'],
                        ':health_status' => $normalized['health_status'],
                        ':health_score' => $normalized['health_score'],
                        ':temperature_c' => $normalized['temperature_c'],
                        ':power_on_time' => $normalized['power_on_time'],
                        ':reallocated_sectors' => $normalized['reallocated_sectors'],
                        ':pending_sectors' => $normalized['pending_sectors'],
                        ':uncorrectable_sectors' => $normalized['uncorrectable_sectors'],
                        ':wearout_pct' => $normalized['wearout_pct'],
                        ':total_written_bytes' => $normalized['total_written_bytes'],
                        ':source_tool' => $normalized['source_tool'],
                        ':last_error' => $normalized['last_error'],
                    ]
                );

                $accepted++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('push-disk.php write failed server_id=' . $serverId . ' error=' . $e->getMessage());
            json_response(['error' => 'Failed to save disk health data'], 500);
        }

        cache_delete('status:disk_summary:all');
        cache_delete('status:disk_summary:active');
        invalidate_status_cache($serverId);

        if ($accepted === 0) {
            return Response::json([
                'error' => 'No valid disk_health items accepted',
                'server_id' => $serverId,
                'accepted' => 0,
                'rejected' => $rejected,
            ], 422);
        }

        return Response::json([
            'ok' => true,
            'server_id' => $serverId,
            'accepted' => $accepted,
            'rejected' => $rejected,
        ], 200);
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

    private static function diskSettingGet(string $key, string $fallback = '0'): string
    {
        try {
            return setting_get($key);
        } catch (Throwable) {
            return $fallback;
        }
    }

    private static function diskSettingInt(string $key, int $fallback): int
    {
        $raw = trim(self::diskSettingGet($key, (string) $fallback));
        if ($raw === '' || preg_match('/^-?\d+$/', $raw) !== 1) {
            return $fallback;
        }
        $value = (int) $raw;
        return $value > 0 ? $value : $fallback;
    }

    private static function diskNormalizeEnum(string $value, array $allowed, string $fallback): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }

    private static function diskNormalizeItem(array $item): ?array
    {
        $diskKey = trim((string) ($item['disk_key'] ?? ''));
        $deviceName = trim((string) ($item['device_name'] ?? ''));
        if ($diskKey === '') {
            $diskKey = $deviceName;
        }
        if ($diskKey === '') {
            return null;
        }

        $model = trim((string) ($item['model'] ?? ''));
        $serial = trim((string) ($item['serial'] ?? ''));
        $firmware = trim((string) ($item['firmware'] ?? ''));
        $lastError = trim((string) ($item['last_error'] ?? ''));
        $rawSummary = trim((string) ($item['raw_summary'] ?? ''));

        $diskType = self::diskNormalizeEnum((string) ($item['disk_type'] ?? 'unknown'), self::DISK_ALLOWED_TYPES, 'unknown');
        $healthStatus = self::diskNormalizeEnum((string) ($item['health_status'] ?? 'unknown'), self::DISK_ALLOWED_STATUS, 'unknown');
        $sourceTool = self::diskNormalizeEnum((string) ($item['source_tool'] ?? 'smartctl'), self::DISK_ALLOWED_SOURCES, 'smartctl');

        $powerOnTimeRaw = $item['power_on_time'] ?? $item['power_on_hours'] ?? null;

        return [
            'disk_key' => $diskKey,
            'device_name' => $deviceName !== '' ? $deviceName : null,
            'model' => $model !== '' ? $model : null,
            'serial' => $serial !== '' ? $serial : null,
            'firmware' => $firmware !== '' ? $firmware : null,
            'disk_type' => $diskType,
            'health_status' => $healthStatus,
            'source_tool' => $sourceTool,
            'last_error' => $lastError !== '' ? $lastError : null,
            'raw_summary' => $rawSummary !== '' ? $rawSummary : null,
            'capacity_bytes' => isset($item['capacity_bytes']) ? max(0, (int) $item['capacity_bytes']) : null,
            'health_score' => isset($item['health_score']) ? max(0.0, min(100.0, (float) $item['health_score'])) : null,
            'temperature_c' => isset($item['temperature_c']) ? (float) $item['temperature_c'] : null,
            // Canonical field is power_on_time (POT); keep payload fallback for legacy agents.
            'power_on_time' => $powerOnTimeRaw !== null ? max(0, (int) $powerOnTimeRaw) : null,
            'reallocated_sectors' => isset($item['reallocated_sectors']) ? max(0, (int) $item['reallocated_sectors']) : null,
            'pending_sectors' => isset($item['pending_sectors']) ? max(0, (int) $item['pending_sectors']) : null,
            'uncorrectable_sectors' => isset($item['uncorrectable_sectors']) ? max(0, (int) $item['uncorrectable_sectors']) : null,
            'wearout_pct' => isset($item['wearout_pct']) ? max(0.0, min(100.0, (float) $item['wearout_pct'])) : null,
            'total_written_bytes' => isset($item['total_written_bytes']) ? max(0, (int) $item['total_written_bytes']) : null,
        ];
    }
}
