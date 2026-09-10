<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Services\Validation\PingValidator;
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
            // CSRF single-guard: enforced by csrf middleware.
            [
                'name' => $name,
                'target' => $target,
                'target_type' => $targetType,
                'check_method' => $checkMethod,
                'check_interval_seconds' => $intervalSeconds,
                'timeout_seconds' => $timeoutSeconds,
                'failure_threshold' => $failureThreshold,
                'active' => $active,
            ] = PingValidator::validateMonitor([
                'name' => $request->input('name'),
                'target' => $request->input('target'),
                'target_type' => $request->input('target_type'),
                'check_method' => $request->input('check_method'),
                'check_interval_seconds' => $request->input('check_interval_seconds'),
                'timeout_seconds' => $request->input('timeout_seconds'),
                'failure_threshold' => $request->input('failure_threshold'),
                'active' => $request->input('active'),
            ], 'ping/' . $id . '/edit');

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
