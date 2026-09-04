<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;

final class LiveStreamController
{
    public function index(Request $request): Response
    {
        if (strtoupper($request->method) !== 'GET') {
            return Response::json(['error' => 'Method not allowed'], 405)->withHeader('Allow', 'GET');
        }

        $this->streamMetrics($request);
    }

    private function streamMetrics(Request $request): never
    {
        require_login();
        session_write_close();

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');

        $serverId = max(0, (int) ($request->query('server_id') ?? 0));
        $sinceId = max(0, (int) ($request->query('since_id') ?? 0));
        $limit = max(1, min(500, (int) ($request->query('limit') ?? 50)));

        set_time_limit(0);
        ignore_user_abort(true);

        if ($sinceId === 0) {
            $latest = db_one('SELECT COALESCE(MAX(id), 0) AS latest_id FROM metrics');
            $sinceId = (int) ($latest['latest_id'] ?? 0);
        }

        $redis = redis_client();
        $ringServerIds = [];
        if ($redis) {
            $ringRows = db_all('SELECT id FROM servers WHERE active = 1');
            foreach ($ringRows as $rr) {
                $ringServerIds[] = (int) $rr['id'];
            }
            if ($serverId > 0) {
                $ringServerIds = array_values(array_filter($ringServerIds, static fn (int $sid): bool => $sid === $serverId));
            }
        }

        $baseSql = 'SELECT m.id, m.server_id, m.recorded_at, m.cpu_load, m.ram_used, m.ram_total,
                           m.hdd_used, m.hdd_total, m.network_in_bps, m.network_out_bps,
                           m.mail_queue_total, m.uptime
                    FROM metrics m
                    INNER JOIN servers s ON s.id = m.server_id AND s.active = 1';
        $where = 'WHERE m.id > :since';
        if ($serverId > 0) {
            $where .= ' AND m.server_id = :sid';
        }
        $where .= " ORDER BY m.id ASC LIMIT {$limit}";

        $stmt = db()->prepare($baseSql . ' ' . $where);

        $startTime = time();
        $maxDuration = 45;

        while (true) {
            $emitted = false;

            if ($redis !== null && $ringServerIds !== []) {
                try {
                    foreach ($ringServerIds as $sid) {
                        $items = $redis->lRange(cache_key('ring:' . $sid), 0, -1);
                        if (!$items) {
                            continue;
                        }
                        foreach ($items as $json) {
                            $row = json_decode((string) $json, true);
                            if (!is_array($row) || !isset($row['id'])) {
                                continue;
                            }
                            $rid = (int) $row['id'];
                            if ($rid <= $sinceId) {
                                continue;
                            }
                            $sinceId = max($sinceId, $rid);
                            $emitted = true;
                            echo "event: metric\n";
                            echo 'data: ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                        }
                    }
                } catch (Throwable $e) {
                    $redis = null;
                }
            }

            if ($redis === null) {
                $stmt->bindValue(':since', $sinceId, \PDO::PARAM_INT);
                if ($serverId > 0) {
                    $stmt->bindValue(':sid', $serverId, \PDO::PARAM_INT);
                }
                $stmt->execute();
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $sinceId = max($sinceId, (int) $row['id']);
                    $emitted = true;
                    echo "event: metric\n";
                    echo 'data: ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                }
            }

            if (!$emitted) {
                echo ": heartbeat\n\n";
            }
            @ob_flush();
            flush();
            if (connection_aborted() || (time() - $startTime) >= $maxDuration) {
                break;
            }
            sleep(1);
        }

        exit;
    }
}
