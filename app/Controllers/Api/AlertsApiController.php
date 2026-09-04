<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use PDO;
use Throwable;

final class AlertsApiController
{
    public function index(Request $request): Response
    {
        if (!in_array($request->method, ['GET', 'POST'], true)) {
            return Response::json(['error' => 'Method not allowed'], 405)->withHeader('Allow', 'GET, POST');
        }

        require_login();

        if ($request->method === 'POST') {
            if (!csrf_validate((string) ($request->input('_csrf_token') ?? ''))) {
                return Response::json(['error' => 'Invalid CSRF token'], 419);
            }

            $action = (string) ($request->input('action') ?? '');
            $user = current_user();
            $userId = (int) ($user['id'] ?? 0);

            try {
                if ($action === 'acknowledge_all') {
                    db_exec(
                        "UPDATE alert_logs
                         SET status = 'acknowledged', acknowledged_by = :user_id, acknowledged_at = NOW()
                         WHERE status = 'active'",
                        [':user_id' => $userId]
                    );
                    audit_log('alert_acknowledge_all', 'Marked all active alerts as seen', 'alert', null);
                    return Response::json(['status' => 'ok']);
                }

                if ($action === 'acknowledge') {
                    $alertId = max(0, (int) ($request->input('alert_id') ?? 0));
                    if ($alertId <= 0) {
                        return Response::json(['error' => 'Invalid alert ID'], 422);
                    }
                    db_exec(
                        "UPDATE alert_logs
                         SET status = 'acknowledged', acknowledged_by = :user_id, acknowledged_at = NOW()
                         WHERE id = :id AND status = 'active'",
                        [':user_id' => $userId, ':id' => $alertId]
                    );
                    audit_log('alert_acknowledge', 'Marked alert as seen', 'alert', $alertId);
                    return Response::json(['status' => 'ok']);
                }

                return Response::json(['error' => 'Unsupported action'], 422);
            } catch (Throwable $e) {
                error_log('alerts.php action failed: ' . $e->getMessage());
                return Response::json(['error' => 'Action failed'], 500);
            }
        }

        $sinceId = max(0, (int) ($request->query('since_id') ?? 0));
        $limit = max(1, min(100, (int) ($request->query('limit') ?? 20)));

        try {
            if ($request->query('recent') !== null) {
                $rows = db_all(
                    'SELECT id, server_id, alert_type, severity, title, message, status, created_at
                     FROM alert_logs
                     WHERE status = \'active\'
                     ORDER BY id DESC
                     LIMIT ' . $limit
                );
                $countRow = db_one("SELECT COUNT(*) AS total FROM alert_logs WHERE status = 'active'");
                return Response::json([
                    'alerts' => $rows,
                    'unread_count' => (int) ($countRow['total'] ?? 0),
                ]);
            }

            $sql = 'SELECT id, server_id, alert_type, severity, title, message, status, sent_email, sent_telegram, created_at
                    FROM alert_logs
                    WHERE id > :since_id
                    ORDER BY id ASC
                    LIMIT ' . $limit;

            $stmt = db()->prepare($sql);
            $stmt->bindValue(':since_id', $sinceId, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            return Response::json($rows);
        } catch (Throwable $e) {
            error_log('alerts.php query failed: ' . $e->getMessage());
            return Response::json(['error' => 'Query failed'], 500);
        }
    }
}
