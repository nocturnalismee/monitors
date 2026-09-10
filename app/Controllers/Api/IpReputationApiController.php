<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use Throwable;

final class IpReputationApiController
{
    public function index(Request $request): Response
    {
        if (!is_logged_in()) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $action = strtolower(trim((string) ($request->query('action', 'list') ?? 'list')));

        if ($action === 'check_now') {
            if (!$request->isPost()) {
                return Response::json(['error' => 'Method not allowed'], 405)->withHeader('Allow', 'GET, POST');
            }
            if (!csrf_validate($request->input('_csrf_token'))) {
                return Response::json(['error' => 'Invalid CSRF token'], 419);
            }
            if (!api_rate_check('ip_rep_check_now', get_client_ip(), 6)) {
                return Response::json(['error' => 'Rate limit exceeded. Try again later.'], 429)->withHeader('Retry-After', '60');
            }
        } elseif ($request->method !== 'GET') {
            return Response::json(['error' => 'Method not allowed'], 405)->withHeader('Allow', 'GET, POST');
        }

        try {
            switch ($action) {
                case 'list':
                    $cacheKey = 'ip_rep:list';
                    $cached = cache_get($cacheKey);
                    if (is_array($cached)) {
                        return Response::json($cached);
                    }

                    $rows = db_all(
                        'SELECT t.id, t.ip_address, t.label, t.server_id, t.active, t.check_interval_hours,
                                s.overall_status, s.listed_count, s.total_checked, s.listed_on, s.provider_results,
                                s.last_checked_at, s.last_change_at,
                                sv.name AS server_name
                         FROM ip_reputation_targets t
                         LEFT JOIN ip_reputation_states s ON s.target_id = t.id
                         LEFT JOIN servers sv ON sv.id = t.server_id
                         ORDER BY t.created_at DESC, t.id DESC'
                    );

                    $summary = ['total' => 0, 'clean' => 0, 'listed' => 0, 'unknown' => 0, 'paused' => 0];
                    foreach ($rows as &$row) {
                        $status = ip_rep_display_status(
                            (string) ($row['overall_status'] ?? 'unknown'),
                            (int) ($row['active'] ?? 0)
                        );
                        $row['display_status'] = $status;
                        $row['listed_on'] = json_decode((string) ($row['listed_on'] ?? '[]'), true) ?: [];
                        $row['provider_results'] = json_decode((string) ($row['provider_results'] ?? '{}'), true) ?: [];
                        $summary['total']++;
                        if (isset($summary[$status])) {
                            $summary[$status]++;
                        }
                    }
                    unset($row);

                    $payload = ['rows' => $rows, 'summary' => $summary];
                    cache_set($cacheKey, $payload, cache_ttl('cache_ttl_ip_rep_list', 30));
                    return Response::json($payload);

                case 'detail':
                    $targetId = (int) ($request->query('id', 0) ?? 0);
                    if ($targetId <= 0) {
                        return Response::json(['error' => 'Invalid target ID'], 400);
                    }

                    $cacheKey = 'ip_rep:detail:' . $targetId;
                    $cached = cache_get($cacheKey);
                    if (is_array($cached)) {
                        return Response::json($cached);
                    }

                    $target = db_one(
                        'SELECT t.id, t.ip_address, t.label, t.server_id, t.active, t.check_interval_hours,
                                s.overall_status, s.listed_count, s.total_checked, s.listed_on, s.provider_results,
                                s.last_checked_at, s.last_change_at,
                                sv.name AS server_name
                         FROM ip_reputation_targets t
                         LEFT JOIN ip_reputation_states s ON s.target_id = t.id
                         LEFT JOIN servers sv ON sv.id = t.server_id
                         WHERE t.id = :id',
                        [':id' => $targetId]
                    );

                    if ($target === null) {
                        return Response::json(['error' => 'Target not found'], 404);
                    }

                    $target['listed_on'] = json_decode((string) ($target['listed_on'] ?? '[]'), true) ?: [];
                    $target['provider_results'] = json_decode((string) ($target['provider_results'] ?? '{}'), true) ?: [];

                    $checks = db_all(
                        'SELECT id, overall_status, listed_count, total_checked, listed_on, provider_results, check_duration_ms, checked_at
                         FROM ip_reputation_checks
                         WHERE target_id = :tid
                         ORDER BY checked_at DESC
                         LIMIT 50',
                        [':tid' => $targetId]
                    );

                    foreach ($checks as &$check) {
                        $check['listed_on'] = json_decode((string) ($check['listed_on'] ?? '[]'), true) ?: [];
                        $check['provider_results'] = json_decode((string) ($check['provider_results'] ?? '{}'), true) ?: [];
                    }
                    unset($check);

                    $payload = ['target' => $target, 'checks' => $checks];
                    cache_set($cacheKey, $payload, cache_ttl('cache_ttl_ip_rep_detail', 15));
                    return Response::json($payload);

                case 'check_now':
                    require_role('admin');

                    session_write_close();
                    set_time_limit(120);

                    $targetId = (int) ($request->query('id', 0) ?? 0);
                    if ($targetId <= 0) {
                        return Response::json(['error' => 'Invalid target ID'], 400);
                    }

                    $target = db_one(
                        'SELECT id, ip_address, label, server_id FROM ip_reputation_targets WHERE id = :id',
                        [':id' => $targetId]
                    );

                    if ($target === null) {
                        return Response::json(['error' => 'Target not found'], 404);
                    }

                    $prev = db_one('SELECT provider_results FROM ip_reputation_states WHERE target_id = :tid', [':tid' => $targetId]);
                    $prevProviders = json_decode((string) ($prev['provider_results'] ?? '{}'), true);
                    $prevProviders = is_array($prevProviders) ? $prevProviders : [];
                    $result = ip_rep_check_single((string) $target['ip_address'], $prevProviders);
                    $transition = ip_rep_record_check($targetId, $result);
                    invalidate_ip_rep_cache($targetId);

                    return Response::json([
                        'success'    => true,
                        'result'     => $result,
                        'transition' => $transition,
                    ]);

                default:
                    return Response::json(['error' => 'Unknown action: ' . $action], 400);
            }
        } catch (Throwable $e) {
            error_log('ip-reputation.php error: ' . $e->getMessage());
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
