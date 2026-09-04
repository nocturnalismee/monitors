<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use Throwable;

final class HealthController
{
    public function index(Request $request): Response
    {
        if ($request->method !== 'GET') {
            return Response::json(['error' => 'Method not allowed'], 405)->withHeader('Allow', 'GET');
        }

        $ip = $request->ip();
        if (!api_rate_check('health_api', $ip, 30)) {
            api_rate_limit_exceeded();
        }

        $checks = [];
        $status = 'ok';

        try {
            $row = db_one('SELECT 1 AS ok');
            $checks['db'] = ['status' => (($row['ok'] ?? null) == 1 ? 'ok' : 'error')];
        } catch (Throwable $e) {
            $checks['db'] = ['status' => 'error'];
            error_log('health.php db check failed: ' . $e->getMessage());
        }
        if (($checks['db']['status'] ?? 'error') !== 'ok') {
            $status = 'degraded';
        }

        if (!REDIS_ENABLED) {
            $checks['redis'] = ['status' => 'disabled'];
        } else {
            $redis = redis_client();
            $checks['redis'] = $redis ? ['status' => 'ok'] : ['status' => 'error'];
            if (($checks['redis']['status'] ?? 'error') !== 'ok') {
                $status = 'degraded';
            }
        }

        $alertWorker = worker_health_status('alert_check', 180);
        $pingWorker = worker_health_status('ping_check', 300);
        $retentionWorker = worker_health_status('retention_cleanup', 129600);
        $rollupWorker = worker_health_status('rollup_metrics', 7200);
        $diskRollupWorker = worker_health_status('disk_history_rollup', 129600);
        $diskCleanupWorker = worker_health_status('disk_retention_cleanup', 129600);
        $ipRepWorker = worker_health_status('ip_reputation_check', 21600);
        $alertDeliveryWorker = worker_health_status('alert_delivery', 300);
        $exportWorker = worker_health_status('export_worker', 300);
        $partitionWorker = worker_health_status('partition_maintain', 129600);
        $checks['workers'] = [
            'alert_check' => $alertWorker,
            'ping_check' => $pingWorker,
            'retention_cleanup' => $retentionWorker,
            'rollup_metrics' => $rollupWorker,
            'disk_history_rollup' => $diskRollupWorker,
            'disk_retention_cleanup' => $diskCleanupWorker,
            'ip_reputation_check' => $ipRepWorker,
            'alert_delivery' => $alertDeliveryWorker,
            'export_worker' => $exportWorker,
            'partition_maintain' => $partitionWorker,
        ];

        if (($alertWorker['health'] ?? 'unknown') !== 'ok') {
            $status = 'degraded';
        }
        if (($pingWorker['health'] ?? 'unknown') !== 'ok') {
            $status = 'degraded';
        }
        if (($retentionWorker['health'] ?? 'unknown') === 'error') {
            $status = 'degraded';
        }
        if (($rollupWorker['health'] ?? 'unknown') === 'error') {
            $status = 'degraded';
        }
        if (($diskRollupWorker['health'] ?? 'unknown') === 'error') {
            $status = 'degraded';
        }
        if (($diskCleanupWorker['health'] ?? 'unknown') === 'error') {
            $status = 'degraded';
        }
        if (($ipRepWorker['health'] ?? 'unknown') === 'error') {
            $status = 'degraded';
        }
        if (($alertDeliveryWorker['health'] ?? 'unknown') === 'error') {
            $status = 'degraded';
        }

        return Response::json([
            'status' => $status,
            'service' => 'servmon',
            'time' => date('Y-m-d H:i:s'),
            'checks' => $checks,
        ], $status === 'ok' ? 200 : 503);
    }
}
