<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Services\Validation\PingValidator;
use App\Support\View;

final class PingAddController
{
    public function index(Request $request): Response
    {
        require_role('admin');

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
            ], 'ping/add');

            db_exec(
                'INSERT INTO ping_monitors (
                    name, target, target_type, check_method, check_interval_seconds, timeout_seconds, failure_threshold, active, created_at, updated_at
                ) VALUES (
                    :name, :target, :target_type, :check_method, :check_interval_seconds, :timeout_seconds, :failure_threshold, :active, NOW(), NOW()
                )',
                [
                    ':name' => $name,
                    ':target' => $target,
                    ':target_type' => $targetType,
                    ':check_method' => $checkMethod,
                    ':check_interval_seconds' => $intervalSeconds,
                    ':timeout_seconds' => $timeoutSeconds,
                    ':failure_threshold' => $failureThreshold,
                    ':active' => $active,
                ]
            );

            $id = (int) db()->lastInsertId();
            invalidate_ping_cache();
            audit_log('ping_monitor_add', 'Created ping monitor', 'ping_monitor', $id, [
                'name' => $name,
                'target' => $target,
                'target_type' => $targetType,
                'check_method' => $checkMethod,
                'check_interval_seconds' => $intervalSeconds,
            ]);
            flash_set('success', 'Ping monitor created successfully.');
            redirect('ping/' . $id);
        }

        $data = [
            'title' => APP_NAME . ' - Add Ping Monitor',
            'activeNav' => 'ping',
        ];

        return Response::html(View::render('admin/ping_add', $data, 'admin'));
    }
}
