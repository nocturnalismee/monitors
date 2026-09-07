<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;

final class EventsController
{
    public function index(Request $request): Response
    {
        require_login();
        $this->streamAlerts($request);
    }

    private function streamAlerts(Request $request): never
    {
        session_write_close();

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');

        $lastId = max(0, (int) ($request->query('since_id') ?? 0));
        // See LiveStreamController: honor native reconnect cursor.
        $lastId = max($lastId, max(0, (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0)));
        set_time_limit(65);
        ignore_user_abort(true);

        // Snap to MAX only on a genuinely fresh connect, never on reconnect.
        if ($lastId === 0) {
            $latest = db_one('SELECT COALESCE(MAX(id), 0) AS latest_id FROM alert_logs');
            $lastId = (int) ($latest['latest_id'] ?? 0);
        }

        for ($cycle = 0; $cycle < 12; $cycle++) {
            $rows = db_all(
                'SELECT id, server_id, alert_type, severity, title, message, status, created_at
                 FROM alert_logs WHERE id > :last_id ORDER BY id ASC LIMIT 20',
                [':last_id' => $lastId]
            );

            foreach ($rows as $row) {
                $lastId = max($lastId, (int) $row['id']);
                echo 'id: ' . (int) $row['id'] . "\n";
                echo "event: alert\n";
                echo 'data: ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            }
            if (!$rows) {
                echo ": heartbeat\n\n";
            }
            @ob_flush();
            flush();
            if (connection_aborted()) {
                break;
            }
            sleep(5);
        }

        exit;
    }
}
