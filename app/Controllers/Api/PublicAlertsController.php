<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use Throwable;

final class PublicAlertsController
{
    public function index(Request $request): Response
    {
        if ($request->method !== 'GET') {
            return Response::json(['error' => 'Method not allowed'], 405)->withHeader('Allow', 'GET');
        }

        $ip = $request->ip();
        if (!api_rate_check('public_alerts_api', $ip, 60)) {
            api_rate_limit_exceeded();
        }

        $sinceId = max(0, (int) ($request->query('since_id') ?? 0));
        $limit = max(1, min(100, (int) ($request->query('limit') ?? 20)));

        try {
            $sql = 'SELECT a.id, a.server_id, a.alert_type, a.severity, a.title, a.message, a.status, a.created_at
                    FROM alert_logs a
                    LEFT JOIN servers s ON s.id = a.server_id
                    WHERE a.id > :since_id
                      AND (a.server_id IS NULL OR s.active = 1)
                    ORDER BY a.id ASC
                    LIMIT ' . $limit;

            $rows = db_all($sql, [':since_id' => $sinceId]);
        } catch (Throwable $e) {
            error_log('public_alerts.php query failed: ' . $e->getMessage());
            return Response::json(['error' => 'Query failed'], 500);
        }

        $redactMessage = setting_get('public_alerts_redact_message') === '1';
        if ($redactMessage) {
            $rows = array_map(static function (array $row): array {
                $row['message'] = 'Alert detail is hidden in public mode.';
                return $row;
            }, $rows);
        }

        return Response::json($rows);
    }
}
