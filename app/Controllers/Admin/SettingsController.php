<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class SettingsController
{
    public function index(Request $request): Response
    {
        require_role('admin');

        if ($request->isPost()) {
            $section = self::inferSectionFromPost($request->body);
            if (!csrf_validate($request->input('_csrf_token'))) {
                flash_set('danger', 'Invalid CSRF token.');
                redirect(self::settingsUrl($section));
            }

            $action = (string) ($request->input('retention_action') ?? $request->input('action') ?? 'save');
            if ($request->input('run_core_retention') !== null) {
                $action = 'run_retention';
            } elseif ($request->input('run_disk_retention') !== null) {
                $action = 'run_disk_retention';
            }

            match ($action) {
                'save' => self::handleSave($request, $section),
                'test_email' => self::handleTestEmail($request),
                'test_telegram' => self::handleTestTelegram($request),
                'run_retention' => self::handleRunRetention($request),
                'run_disk_retention' => self::handleRunDiskRetention($request),
                'user_create' => self::handleUserCreate($request),
                'user_update' => self::handleUserUpdate($request),
                'user_delete' => self::handleUserDelete($request),
                default => null,
            };
        }

        $sections = self::settingsSectionMap();
        $activeSection = self::normalizeSettingsSection($request->query('section', 'general'));
        $settings = settings_get_all();
        $users = db_all('SELECT id, username, role, last_login, created_at FROM users ORDER BY created_at ASC');
        $currentUser = current_user();
        $adminCount = 0;
        foreach ($users as $u) {
            if ((string) ($u['role'] ?? '') === 'admin') {
                $adminCount++;
            }
        }

        // @see DashboardController for dedup note — cron/worker logic unified here
        $cronSvc = new \App\Services\Settings\CronWorkerService();
        $recommendedCron = $cronSvc->recommendedCron(SERVMON_BASE_DIR);
        $workerStatuses = $cronSvc->workerStatuses();
        $metricsStorage = (new \App\Services\Settings\StorageStatsService())->collect();

        $activeSectionLabel = $sections[$activeSection] ?? ucfirst($activeSection);
        $sectionActionHints = [
            'general' => 'Save once after updating policy, threshold, and cache values.',
            'notifications' => 'Save config first, then run test delivery.',
            'security' => 'Save timeout/retention changes before running cleanup.',
            'users' => 'User add/edit/delete is applied immediately after submit.',
            'ip_reputation' => 'Configure global defaults and API keys for IP reputation providers.',
            'ops' => 'Monitor worker health and keep cron jobs active.',
        ];
        $activeSectionHint = $sectionActionHints[$activeSection] ?? 'Manage this section using the controls below.';

        $data = [
            'sections' => $sections,
            'activeSection' => $activeSection,
            'settings' => $settings,
            'users' => $users,
            'currentUser' => $currentUser,
            'adminCount' => $adminCount,
            'recommendedCron' => $recommendedCron,
            'workerStatuses' => $workerStatuses,
            'metricsStorage' => $metricsStorage,
            'activeSectionLabel' => $activeSectionLabel,
            'activeSectionHint' => $activeSectionHint,
            'title' => APP_NAME . ' - Settings',
            'activeNav' => 'settings',
        ];

        return Response::html(View::render('admin/settings', $data, 'admin'));
    }

    private static function handleSave(Request $request, string $section): never
    {
        $values = match ($section) {
            'general' => [
                'branding_logo_url' => trim((string) ($request->input('branding_logo_url') ?? setting_get('branding_logo_url'))),
                'branding_favicon_url' => trim((string) ($request->input('branding_favicon_url') ?? setting_get('branding_favicon_url'))),
                'alert_down_minutes' => (string) max(1, (int) ($request->input('alert_down_minutes') ?? setting_get('alert_down_minutes'))),
                'alert_cooldown_minutes' => (string) max(0, (int) ($request->input('alert_cooldown_minutes') ?? setting_get('alert_cooldown_minutes'))),
                'alert_service_status_enabled' => $request->input('alert_service_status_enabled') !== null ? '1' : '0',
                'alert_ping_enabled' => $request->input('alert_ping_enabled') !== null ? '1' : '0',
                'alert_sound_enabled' => $request->input('alert_sound_enabled') !== null ? '1' : '0',
                'alert_sound_volume' => (string) max(0, min(10, (int) ($request->input('alert_sound_volume') ?? setting_get('alert_sound_volume')))),
                'threshold_mail_queue' => (string) max(0, (int) ($request->input('threshold_mail_queue') ?? setting_get('threshold_mail_queue'))),
                'threshold_mail_queue_critical' => (string) max(0, (int) ($request->input('threshold_mail_queue_critical') ?? setting_get('threshold_mail_queue_critical'))),
                'threshold_cpu_load' => (string) max(0, (float) ($request->input('threshold_cpu_load') ?? setting_get('threshold_cpu_load'))),
                'threshold_cpu_load_critical' => (string) max(0, (float) ($request->input('threshold_cpu_load_critical') ?? setting_get('threshold_cpu_load_critical'))),
                'threshold_ram_pct' => (string) max(0, min(100, (float) ($request->input('threshold_ram_pct') ?? setting_get('threshold_ram_pct')))),
                'threshold_ram_pct_critical' => (string) max(0, min(100, (float) ($request->input('threshold_ram_pct_critical') ?? setting_get('threshold_ram_pct_critical')))),
                'threshold_disk_pct' => (string) max(0, min(100, (float) ($request->input('threshold_disk_pct') ?? setting_get('threshold_disk_pct')))),
                'threshold_disk_pct_critical' => (string) max(0, min(100, (float) ($request->input('threshold_disk_pct_critical') ?? setting_get('threshold_disk_pct_critical')))),
                'alert_service_flap_suppress_minutes' => (string) max(0, (int) ($request->input('alert_service_flap_suppress_minutes') ?? setting_get('alert_service_flap_suppress_minutes'))),
                'public_alerts_redact_message' => $request->input('public_alerts_redact_message') !== null ? '1' : '0',
                'cache_ttl_status_list' => (string) max(1, (int) ($request->input('cache_ttl_status_list') ?? setting_get('cache_ttl_status_list'))),
                'cache_ttl_status_single' => (string) max(1, (int) ($request->input('cache_ttl_status_single') ?? setting_get('cache_ttl_status_single'))),
                'cache_ttl_history_24h' => (string) max(1, (int) ($request->input('cache_ttl_history_24h') ?? setting_get('cache_ttl_history_24h'))),
                'cache_ttl_history_7d' => (string) max(1, (int) ($request->input('cache_ttl_history_7d') ?? setting_get('cache_ttl_history_7d'))),
                'cache_ttl_history_30d' => (string) max(1, (int) ($request->input('cache_ttl_history_30d') ?? setting_get('cache_ttl_history_30d'))),
                'cache_ttl_alert_logs' => (string) max(1, (int) ($request->input('cache_ttl_alert_logs') ?? setting_get('cache_ttl_alert_logs'))),
            ],
            'notifications' => self::notificationValues($request),
            'security' => [
                'session_idle_timeout_minutes' => (string) max(5, (int) ($request->input('session_idle_timeout_minutes') ?? setting_get('session_idle_timeout_minutes'))),
                'session_absolute_timeout_minutes' => (string) max(15, (int) ($request->input('session_absolute_timeout_minutes') ?? setting_get('session_absolute_timeout_minutes'))),
                'retention_days' => (string) max(1, (int) ($request->input('retention_days') ?? setting_get('retention_days'))),
                'disk_retention_days' => (string) max(1, (int) ($request->input('disk_retention_days') ?? setting_get('disk_retention_days'))),
            ],
            'ip_reputation' => self::ipReputationValues($request),
            default => [],
        };

        if (!empty($values)) {
            settings_save_many($values);
            invalidate_status_cache();
            audit_log('settings_save', 'Updated application settings', 'settings', null, [
                'section' => $section,
                'updated_keys' => array_keys($values),
            ]);
            flash_set('success', 'Settings saved successfully.');
        } else {
            flash_set('warning', 'No settings updated for this section.');
        }
        redirect(self::settingsUrl($section));
    }

    private static function notificationValues(Request $request): array
    {
        $values = [
            'channel_email_enabled' => $request->input('channel_email_enabled') !== null ? '1' : '0',
            'channel_telegram_enabled' => $request->input('channel_telegram_enabled') !== null ? '1' : '0',
            'smtp_host' => trim((string) ($request->input('smtp_host') ?? setting_get('smtp_host'))),
            'smtp_port' => (string) max(1, (int) ($request->input('smtp_port') ?? setting_get('smtp_port'))),
            'smtp_username' => trim((string) ($request->input('smtp_username') ?? setting_get('smtp_username'))),
            'smtp_secure' => in_array((string) ($request->input('smtp_secure') ?? setting_get('smtp_secure')), ['none', 'tls', 'ssl'], true) ? (string) ($request->input('smtp_secure') ?? setting_get('smtp_secure')) : 'tls',
            'smtp_from_email' => trim((string) ($request->input('smtp_from_email') ?? setting_get('smtp_from_email'))),
            'smtp_from_name' => trim((string) ($request->input('smtp_from_name') ?? setting_get('smtp_from_name'))),
            'smtp_to_email' => trim((string) ($request->input('smtp_to_email') ?? setting_get('smtp_to_email'))),
            'telegram_bot_token' => trim((string) ($request->input('telegram_bot_token') ?? setting_get('telegram_bot_token'))),
            'telegram_chat_id' => trim((string) ($request->input('telegram_chat_id') ?? setting_get('telegram_chat_id'))),
            'telegram_thread_id' => trim((string) ($request->input('telegram_thread_id') ?? setting_get('telegram_thread_id'))),
        ];
        $newSmtpPassword = trim((string) ($request->input('smtp_password') ?? ''));
        if ($newSmtpPassword !== '') {
            $values['smtp_password'] = $newSmtpPassword;
        }
        return $values;
    }

    private static function ipReputationValues(Request $request): array
    {
        $values = [
            'ip_rep_check_interval_hours' => (string) max(1, (int) ($request->input('ip_rep_check_interval_hours') ?? setting_get('ip_rep_check_interval_hours'))),
            'ip_rep_alert_enabled' => $request->input('ip_rep_alert_enabled') !== null ? '1' : '0',
        ];
        $abuseIpdDb = trim((string) ($request->input('ip_rep_abuseipdb_key') ?? ''));
        if ($abuseIpdDb !== '' || $request->input('clear_ip_rep_abuseipdb_key') !== null) {
            $values['ip_rep_abuseipdb_key'] = $request->input('clear_ip_rep_abuseipdb_key') !== null ? '' : $abuseIpdDb;
        }
        $vtKey = trim((string) ($request->input('ip_rep_virustotal_key') ?? ''));
        if ($vtKey !== '' || $request->input('clear_ip_rep_virustotal_key') !== null) {
            $values['ip_rep_virustotal_key'] = $request->input('clear_ip_rep_virustotal_key') !== null ? '' : $vtKey;
        }
        $ipinfoKey = trim((string) ($request->input('ip_rep_ipinfo_key') ?? ''));
        if ($ipinfoKey !== '' || $request->input('clear_ip_rep_ipinfo_key') !== null) {
            $values['ip_rep_ipinfo_key'] = $request->input('clear_ip_rep_ipinfo_key') !== null ? '' : $ipinfoKey;
        }
        return $values;
    }

    private static function handleTestEmail(Request $request): never
    {
        $settings = settings_get_all();
        $ok = notify_email('servmon Test Notification', 'This is a test email notification from servmon.', $settings);
        audit_log('settings_test_email', 'Ran test email notification', 'settings');
        flash_set($ok ? 'success' : 'warning', $ok ? 'Test email sent.' : 'Test email failed. Check configuration or server mail() support.');
        redirect(self::settingsUrl('notifications'));
    }

    private static function handleTestTelegram(Request $request): never
    {
        $settings = settings_get_all();
        $settings['channel_telegram_enabled'] = '1';
        $settings['telegram_bot_token'] = trim((string) ($request->input('telegram_bot_token') ?? $settings['telegram_bot_token'] ?? ''));
        $settings['telegram_chat_id'] = trim((string) ($request->input('telegram_chat_id') ?? $settings['telegram_chat_id'] ?? ''));
        $settings['telegram_thread_id'] = trim((string) ($request->input('telegram_thread_id') ?? $settings['telegram_thread_id'] ?? ''));
        $ok = notify_telegram("<b>servmon Test Notification</b>\nThis is a Telegram test notification.", $settings);
        audit_log('settings_test_telegram', 'Ran test telegram notification', 'settings');
        flash_set($ok ? 'success' : 'warning', $ok ? 'Test Telegram message sent.' : 'Test Telegram failed. Check bot token and chat ID. Thread ID is optional.');
        redirect(self::settingsUrl('notifications'));
    }

    private static function handleRunRetention(Request $request): never
    {
        $days = max(1, (int) ($request->input('retention_days') ?? setting_get('retention_days')));
        $result = run_core_retention_cleanup($days);
        audit_log('settings_run_retention', 'Executed retention cleanup from settings', 'settings', null, ['days' => $days, 'result' => $result]);
        $deletedTotal = (int) ($result['service_metrics_deleted'] ?? 0)
            + (int) ($result['metrics_deleted'] ?? 0)
            + (int) ($result['metrics_history_deleted'] ?? 0)
            + (int) ($result['ping_checks_deleted'] ?? 0)
            + (int) ($result['alerts_deleted'] ?? 0)
            + (int) ($result['attempts_deleted'] ?? 0)
            + (int) ($result['audits_deleted'] ?? 0);
        $retentionSummary = 'Core retention completed (cutoff: ' . ($result['cutoff_at'] ?? '-') . '). Service Metrics: ' . ($result['service_metrics_deleted'] ?? 0)
            . ', Metrics: ' . $result['metrics_deleted']
            . ', Metrics History: ' . ($result['metrics_history_deleted'] ?? 0)
            . ', Ping Checks: ' . ($result['ping_checks_deleted'] ?? 0)
            . ', Alerts: ' . $result['alerts_deleted']
            . ', Login Attempts: ' . $result['attempts_deleted']
            . ', Audit Logs: ' . ($result['audits_deleted'] ?? 0);
        if ($deletedTotal === 0) {
            $retentionSummary .= ' No records older than the selected retention period were found.';
        }
        flash_set('success', $retentionSummary);
        redirect(self::settingsUrl('security'));
    }

    private static function handleRunDiskRetention(Request $request): never
    {
        $days = max(1, (int) ($request->input('disk_retention_days') ?? setting_get('disk_retention_days')));
        $result = run_disk_retention_cleanup($days);
        audit_log('settings_run_disk_retention', 'Executed disk retention cleanup from settings', 'settings', null, ['days' => $days, 'result' => $result]);
        flash_set(
            'success',
            'Disk retention completed (cutoff: ' . ($result['cutoff_at'] ?? '-') . '). Disk Metrics: '
            . ($result['disk_metrics_deleted'] ?? 0)
            . ', Disk History: ' . ($result['disk_history_deleted'] ?? 0)
        );
        redirect(self::settingsUrl('security'));
    }

    private static function handleUserCreate(Request $request): never
    {
        $username = trim((string) ($request->input('username') ?? ''));
        $password = (string) ($request->input('password') ?? '');
        $role = self::normalizeUserRole((string) ($request->input('role') ?? 'viewer'));

        if ($username === '' || preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username) !== 1) {
            flash_set('danger', 'Invalid username format. Use 3-50 chars: letters, numbers, dot, underscore, hyphen.');
            redirect(self::settingsUrl('users'));
        }
        if (strlen($password) < 8) {
            flash_set('danger', 'User password must be at least 8 characters.');
            redirect(self::settingsUrl('users'));
        }
        if (db_one('SELECT id FROM users WHERE username = :username LIMIT 1', [':username' => $username]) !== null) {
            flash_set('danger', 'Username already exists.');
            redirect(self::settingsUrl('users'));
        }

        db_exec(
            'INSERT INTO users (username, password_hash, role, created_at) VALUES (:username, :password_hash, :role, NOW())',
            [
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                ':role' => $role,
            ]
        );
        $newUserId = (int) db()->lastInsertId();
        audit_log('user_create', 'Created user account', 'user', $newUserId, ['username' => $username, 'role' => $role]);
        flash_set('success', 'User created successfully.');
        redirect(self::settingsUrl('users'));
    }

    private static function handleUserUpdate(Request $request): never
    {
        $userId = (int) ($request->input('user_id') ?? 0);
        $role = self::normalizeUserRole((string) ($request->input('role') ?? 'viewer'));
        $newPassword = (string) ($request->input('new_password') ?? '');
        $target = db_one('SELECT id, username, role FROM users WHERE id = :id LIMIT 1', [':id' => $userId]);
        $current = current_user();
        $currentUserId = (int) ($current['id'] ?? 0);

        if ($target === null || $userId <= 0) {
            flash_set('danger', 'User not found.');
            redirect(self::settingsUrl('users'));
        }
        if ($userId === $currentUserId && $role !== 'admin') {
            flash_set('danger', 'You cannot downgrade your own role.');
            redirect(self::settingsUrl('users'));
        }
        if ((string) $target['role'] === 'admin' && $role !== 'admin' && self::adminUserCount() <= 1) {
            flash_set('danger', 'Cannot remove role from the last admin user.');
            redirect(self::settingsUrl('users'));
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
            flash_set('danger', 'New password must be at least 8 characters.');
            redirect(self::settingsUrl('users'));
        }

        db_exec('UPDATE users SET role = :role WHERE id = :id', [':role' => $role, ':id' => $userId]);
        if ($newPassword !== '') {
            db_exec(
                'UPDATE users SET password_hash = :password_hash WHERE id = :id',
                [':password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $userId]
            );
        }

        audit_log(
            'user_update',
            'Updated user account',
            'user',
            $userId,
            ['username' => $target['username'], 'role' => $role, 'password_changed' => $newPassword !== '']
        );
        flash_set('success', 'User updated successfully.');
        redirect(self::settingsUrl('users'));
    }

    private static function handleUserDelete(Request $request): never
    {
        $userId = (int) ($request->input('user_id') ?? 0);
        $target = db_one('SELECT id, username, role FROM users WHERE id = :id LIMIT 1', [':id' => $userId]);
        $current = current_user();
        $currentUserId = (int) ($current['id'] ?? 0);

        if ($target === null || $userId <= 0) {
            flash_set('danger', 'User not found.');
            redirect(self::settingsUrl('users'));
        }
        if ($userId === $currentUserId) {
            flash_set('danger', 'You cannot delete your own account.');
            redirect(self::settingsUrl('users'));
        }
        if ((string) $target['role'] === 'admin' && self::adminUserCount() <= 1) {
            flash_set('danger', 'Cannot delete the last admin user.');
            redirect(self::settingsUrl('users'));
        }

        db_exec('DELETE FROM users WHERE id = :id', [':id' => $userId]);
        audit_log('user_delete', 'Deleted user account', 'user', $userId, ['username' => $target['username'], 'role' => $target['role']]);
        flash_set('success', 'User deleted successfully.');
        redirect(self::settingsUrl('users'));
    }

    private static function normalizeUserRole(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        return in_array($role, ['admin', 'viewer'], true) ? $role : 'viewer';
    }

    private static function adminUserCount(): int
    {
        $row = db_one('SELECT COUNT(*) AS total FROM users WHERE role = "admin"');
        return (int) ($row['total'] ?? 0);
    }

    private static function settingsSectionMap(): array
    {
        return [
            'general' => 'General',
            'notifications' => 'Notifications',
            'security' => 'Security',
            'users' => 'Users',
            'ip_reputation' => 'IP Reputation',
            'ops' => 'Ops',
        ];
    }

    private static function normalizeSettingsSection(?string $section): string
    {
        $section = strtolower(trim((string) $section));
        return array_key_exists($section, self::settingsSectionMap()) ? $section : 'general';
    }

    private static function inferSectionFromPost(array $post): string
    {
        if (isset($post['section'])) {
            return self::normalizeSettingsSection((string) $post['section']);
        }

        $action = (string) ($post['retention_action'] ?? $post['action'] ?? '');
        if (isset($post['run_core_retention'])) {
            $action = 'run_retention';
        } elseif (isset($post['run_disk_retention'])) {
            $action = 'run_disk_retention';
        }
        if (in_array($action, ['test_email', 'test_telegram'], true)) {
            return 'notifications';
        }
        if ($action === 'run_retention') {
            return 'security';
        }
        if (str_starts_with($action, 'user_')) {
            return 'users';
        }
        if (isset($post['smtp_host']) || isset($post['telegram_bot_token']) || isset($post['channel_email_enabled']) || isset($post['channel_telegram_enabled'])) {
            return 'notifications';
        }
        if (isset($post['session_idle_timeout_minutes']) || isset($post['session_absolute_timeout_minutes']) || isset($post['retention_days']) || isset($post['disk_retention_days'])) {
            return 'security';
        }
        if (isset($post['ip_rep_check_interval_hours']) || isset($post['ip_rep_alert_enabled']) || isset($post['ip_rep_abuseipdb_key'])) {
            return 'ip_reputation';
        }

        return 'general';
    }

    private static function settingsUrl(string $section): string
    {
        return 'settings?section=' . rawurlencode(self::normalizeSettingsSection($section));
    }
}
