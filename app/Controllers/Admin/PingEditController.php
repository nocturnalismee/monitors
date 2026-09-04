<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class PingEditController
{
    public function index(Request $request): Response
    {
        require_role('admin');

        $id = (int) ($request->params['id'] ?? 0);
        $monitor = db_one('SELECT * FROM ping_monitors WHERE id = :id LIMIT 1', [':id' => $id]);
        if ($monitor === null) {
            flash_set('danger', 'Ping monitor not found.');
            redirect('ping');
        }

        if ($request->isPost()) {
            if (!csrf_validate($request->input('_csrf_token'))) {
                flash_set('danger', 'Invalid CSRF token.');
                redirect('ping/' . $id . '/edit');
            }

            $name = trim((string) ($request->input('name') ?? ''));
            $target = ping_normalize_target((string) ($request->input('target') ?? ''));
            $targetType = ping_normalize_target_type((string) ($request->input('target_type') ?? 'domain'));
            $checkMethod = ping_normalize_check_method((string) ($request->input('check_method') ?? 'icmp'));
            if ($checkMethod === 'http') {
                $targetType = 'url';
            } elseif ($targetType === 'url') {
                $targetType = 'domain';
            }
            $intervalSeconds = max(30, min(3600, (int) ($request->input('check_interval_seconds') ?? 60)));
            $timeoutSeconds = max(1, min(10, (int) ($request->input('timeout_seconds') ?? 2)));
            $failureThreshold = max(1, min(10, (int) ($request->input('failure_threshold') ?? 2)));
            $active = $request->input('active') !== null ? 1 : 0;

            if ($name === '') {
                flash_set('danger', 'Monitor name is required.');
                redirect('ping/' . $id . '/edit');
            }
            if (!ping_validate_target($target, $targetType, $checkMethod)) {
                flash_set('danger', 'Invalid ping target for selected type.');
                redirect('ping/' . $id . '/edit');
            }

            db_exec(
                'UPDATE ping_monitors
                 SET name = :name,
                     target = :target,
                     target_type = :target_type,
                     check_method = :check_method,
                     check_interval_seconds = :check_interval_seconds,
                     timeout_seconds = :timeout_seconds,
                     failure_threshold = :failure_threshold,
                     active = :active,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    ':name' => $name,
                    ':target' => $target,
                    ':target_type' => $targetType,
                    ':check_method' => $checkMethod,
                    ':check_interval_seconds' => $intervalSeconds,
                    ':timeout_seconds' => $timeoutSeconds,
                    ':failure_threshold' => $failureThreshold,
                    ':active' => $active,
                    ':id' => $id,
                ]
            );

            invalidate_ping_cache($id);
            audit_log('ping_monitor_update', 'Updated ping monitor', 'ping_monitor', $id, [
                'name' => $name,
                'target' => $target,
                'target_type' => $targetType,
                'check_method' => $checkMethod,
                'check_interval_seconds' => $intervalSeconds,
            ]);
            flash_set('success', 'Ping monitor updated successfully.');
            redirect('ping/' . $id);
        }

        $data = [
            'id' => $id,
            'monitor' => $monitor,
            'title' => APP_NAME . ' - Edit Ping Monitor',
            'activeNav' => 'ping',
        ];

        return Response::html(View::render('admin/ping_edit', $data, 'admin'));
    }
}
