<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class PingAddController
{
    public function index(Request $request): Response
    {
        require_role('admin');

        if ($request->isPost()) {
            // CSRF single-guard: enforced by csrf middleware.
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
                redirect('ping/add');
            }
            if (!ping_validate_target($target, $targetType, $checkMethod)) {
                flash_set('danger', 'Invalid ping target for selected type.');
                redirect('ping/add');
            }

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
