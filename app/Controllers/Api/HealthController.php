<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Services\Settings\CronWorkerService;
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

        $alertWorker = worker_health_status('alert_check', CronWorkerService::TTL['alert_check']);
        $pingWorker = worker_health_status('ping_check', CronWorkerService::TTL['ping_check']);
        $retentionWorker = worker_health_status('retention_cleanup', CronWorkerService::TTL['retention_cleanup']);
        $rollupWorker = worker_health_status('rollup_metrics', CronWorkerService::TTL['rollup_metrics']);
        $diskRollupWorker = worker_health_status('disk_history_rollup', CronWorkerService::TTL['disk_history_rollup']);
        $diskCleanupWorker = worker_health_status('disk_retention_cleanup', CronWorkerService::TTL['disk_retention_cleanup']);
        $ipRepWorker = worker_health_status('ip_reputation_check', CronWorkerService::TTL['ip_reputation_check']);
        $alertDeliveryWorker = worker_health_status('alert_delivery', CronWorkerService::TTL['alert_delivery']);
        $exportWorker = worker_health_status('export_worker', CronWorkerService::TTL['export_worker']);
        $partitionWorker = worker_health_status('partition_maintain', CronWorkerService::TTL['partition_maintain']);
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

        $queue = (new \App\Services\Reliability\QueueDepthService())->collect();
        $checks['queue_depth'] = $queue;

        return Response::json([
            'status' => $status,
            'service' => 'servmon',
            'time' => date('Y-m-d H:i:s'),
            'checks' => $checks,
        ], $status === 'ok' ? 200 : 503);
    }
}
