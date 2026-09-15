<main id="main-content" class="container py-4 admin-page admin-shell settings-page">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Settings</h1>
            <p class="page-subtitle">Configure alerts, channels, retention, security, and user access in one workspace.</p>
        </div>
    </section>
    <section class="card card-neon settings-navigation-card" data-ui-section>
        <div class="card-body p-3 p-lg-4">
            <?php $sectionIcons = [
                'general' => 'ti-settings',
                'notifications' => 'ti-bell',
                'security' => 'ti-shield-lock',
                'users' => 'ti-users',
                'ip_reputation' => 'ti-shield-check',
                'ops' => 'ti-tool',
            ]; ?>
            <nav class="settings-tabs nav nav-pills" aria-label="Settings Sections">
                <?php foreach ($sections as $key => $label): ?>
                    <a
                        class="settings-tab nav-link <?= $activeSection === $key ? 'active' : '' ?>"
                        href="<?= e(app_url('settings?section=' . $key)) ?>"
                        <?= $activeSection === $key ? 'aria-current="page"' : '' ?>
                    ><i class="ti <?= e($sectionIcons[$key] ?? 'ti-adjustments') ?> me-1" aria-hidden="true"></i><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
            <p class="settings-section-intro mb-0">
                <?php if ($activeSection === 'general'): ?>
                    Branding, alert policy, thresholds, and cache behavior.
                <?php elseif ($activeSection === 'notifications'): ?>
                    Email and Telegram channel configuration plus test actions.
                <?php elseif ($activeSection === 'security'): ?>
                    Session timeout and retention controls.
                <?php elseif ($activeSection === 'users'): ?>
                    Manage admin and viewer access accounts.
                <?php elseif ($activeSection === 'ip_reputation'): ?>
                    IP Reputation global configuration and third-party API keys.
                <?php else: ?>
                    Operational references for worker and runtime checks.
                <?php endif; ?>
            </p>
        </div>
    </section>

    <?php if ($activeSection === 'general'): ?>
    <form method="post" class="card card-neon settings-form-card" data-ui-section>
        <?= csrf_input() ?>
        <input type="hidden" name="section" value="general">
        <div class="card-header bg-surface-2 border-soft settings-section-head">
            <h2 class="h6 mb-0">General Settings</h2>
            <span class="text-secondary small">Branding, alert policy, resource thresholds, and cache behavior.</span>
        </div>
        <div class="card-body">
            <div class="settings-group">
                <div class="settings-group-head">
                    <span class="settings-group-icon"><i class="ti ti-photo" aria-hidden="true"></i></span>
                    <div>
                        <h2 class="settings-group-title">Branding</h2>
                        <p class="settings-group-desc">Logo and favicon shown in the sidebar and public pages.</p>
                    </div>
                </div>
                <div class="settings-group-body">
                    <div class="row g-4">
                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="setting-branding-logo">Brand Logo URL</label>
                            <input class="form-control" name="branding_logo_url" id="setting-branding-logo" placeholder="/assets/img/logo.svg or https://..." value="<?= e($settings['branding_logo_url'] ?? '') ?>">
                            <div class="field-help">Used for the logo in the sidebar and public pages.</div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="setting-branding-favicon">Favicon URL</label>
                            <input class="form-control" name="branding_favicon_url" id="setting-branding-favicon" placeholder="/assets/img/favicon.ico or https://..." value="<?= e($settings['branding_favicon_url'] ?? '') ?>">
                            <div class="field-help">Supports .ico, .png, .svg, or absolute URLs.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-group">
                <div class="settings-group-head">
                    <span class="settings-group-icon"><i class="ti ti-bell-ringing" aria-hidden="true"></i></span>
                    <div>
                        <h2 class="settings-group-title">Alert Policy</h2>
                        <p class="settings-group-desc">When servers and services are considered down, plus sound behavior.</p>
                    </div>
                </div>
                <div class="settings-group-body">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label" for="setting-alert-down-duration">Down Duration (minutes)</label>
                            <input class="form-control" type="number" min="1" name="alert_down_minutes" id="setting-alert-down-duration" value="<?= e($settings['alert_down_minutes']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-alert-cooldown">Alert Cooldown (minutes)</label>
                            <input class="form-control" type="number" min="0" name="alert_cooldown_minutes" id="setting-alert-cooldown" value="<?= e($settings['alert_cooldown_minutes']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label d-block" for="alert_service_status_enabled">Service Status Alerts</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="alert_service_status_enabled" id="alert_service_status_enabled" <?= ($settings['alert_service_status_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="alert_service_status_enabled">Enable Service UP/DOWN Alerts</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label d-block" for="alert_ping_enabled">Ping Alerts</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="alert_ping_enabled" id="alert_ping_enabled" <?= ($settings['alert_ping_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="alert_ping_enabled">Enable Ping UP/DOWN Alerts</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label d-block" for="alert_sound_enabled">Browser Sound</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="alert_sound_enabled" id="alert_sound_enabled" <?= ($settings['alert_sound_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="alert_sound_enabled">Enable alert sound</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-alert-sound-volume">Sound Volume (0–10)</label>
                            <input class="form-range" type="range" min="0" max="10" step="1" name="alert_sound_volume" id="setting-alert-sound-volume" value="<?= e($settings['alert_sound_volume'] ?? '8') ?>">
                            <div class="field-help">Applies to new browser alert popups.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-alert-flap-suppress">Service Flap Suppress (minutes)</label>
                            <input class="form-control" type="number" min="0" name="alert_service_flap_suppress_minutes" id="setting-alert-flap-suppress" value="<?= e($settings['alert_service_flap_suppress_minutes']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label d-block" for="public_alerts_redact_message">Public Alert Message</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="public_alerts_redact_message" id="public_alerts_redact_message" <?= ($settings['public_alerts_redact_message'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="public_alerts_redact_message">Redact public alert message</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-group">
                <div class="settings-group-head">
                    <span class="settings-group-icon"><i class="ti ti-gauge" aria-hidden="true"></i></span>
                    <div>
                        <h2 class="settings-group-title">Resource Thresholds</h2>
                        <p class="settings-group-desc">Warning and critical limits for CPU, memory, disk, and mail queue.</p>
                    </div>
                </div>
                <div class="settings-group-body">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label" for="setting-threshold-cpu">CPU Load</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="threshold_cpu_load" id="setting-threshold-cpu" value="<?= e($settings['threshold_cpu_load']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-threshold-cpu-critical">CPU Load Critical</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="threshold_cpu_load_critical" id="setting-threshold-cpu-critical" value="<?= e($settings['threshold_cpu_load_critical']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-threshold-ram">RAM (%)</label>
                            <input class="form-control" type="number" step="0.1" min="0" max="100" name="threshold_ram_pct" id="setting-threshold-ram" value="<?= e($settings['threshold_ram_pct']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-threshold-ram-critical">RAM Critical (%)</label>
                            <input class="form-control" type="number" step="0.1" min="0" max="100" name="threshold_ram_pct_critical" id="setting-threshold-ram-critical" value="<?= e($settings['threshold_ram_pct_critical']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-threshold-disk">Disk (%)</label>
                            <input class="form-control" type="number" step="0.1" min="0" max="100" name="threshold_disk_pct" id="setting-threshold-disk" value="<?= e($settings['threshold_disk_pct']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-threshold-disk-critical">Disk Critical (%)</label>
                            <input class="form-control" type="number" step="0.1" min="0" max="100" name="threshold_disk_pct_critical" id="setting-threshold-disk-critical" value="<?= e($settings['threshold_disk_pct_critical']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-threshold-mail">Mail Queue</label>
                            <input class="form-control" type="number" min="0" name="threshold_mail_queue" id="setting-threshold-mail" value="<?= e($settings['threshold_mail_queue']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-threshold-mail-critical">Mail Queue Critical</label>
                            <input class="form-control" type="number" min="0" name="threshold_mail_queue_critical" id="setting-threshold-mail-critical" value="<?= e($settings['threshold_mail_queue_critical']) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-group">
                <div class="settings-group-head">
                    <span class="settings-group-icon"><i class="ti ti-database" aria-hidden="true"></i></span>
                    <div>
                        <h2 class="settings-group-title">Cache TTL</h2>
                        <p class="settings-group-desc">How long status and history responses are cached, in seconds.</p>
                    </div>
                </div>
                <div class="settings-group-body">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label" for="setting-cache-status-list">Status List</label>
                            <input class="form-control" type="number" min="1" name="cache_ttl_status_list" id="setting-cache-status-list" value="<?= e($settings['cache_ttl_status_list']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-cache-status-single">Status Single</label>
                            <input class="form-control" type="number" min="1" name="cache_ttl_status_single" id="setting-cache-status-single" value="<?= e($settings['cache_ttl_status_single']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-cache-history-24h">History 24h</label>
                            <input class="form-control" type="number" min="1" name="cache_ttl_history_24h" id="setting-cache-history-24h" value="<?= e($settings['cache_ttl_history_24h']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-cache-history-7d">History 7d</label>
                            <input class="form-control" type="number" min="1" name="cache_ttl_history_7d" id="setting-cache-history-7d" value="<?= e($settings['cache_ttl_history_7d']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-cache-history-30d">History 30d</label>
                            <input class="form-control" type="number" min="1" name="cache_ttl_history_30d" id="setting-cache-history-30d" value="<?= e($settings['cache_ttl_history_30d']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-cache-alert-logs">Alert Logs</label>
                            <input class="form-control" type="number" min="1" name="cache_ttl_alert_logs" id="setting-cache-alert-logs" value="<?= e($settings['cache_ttl_alert_logs']) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-action-footer">
                <button class="btn btn-info" type="submit" name="action" value="save" data-submit-loading data-loading-text="Saving...">Save Settings</button>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($activeSection === 'notifications'): ?>
    <form method="post" class="card card-neon settings-form-card" data-ui-section>
        <?= csrf_input() ?>
        <input type="hidden" name="section" value="notifications">
        <div class="card-header bg-surface-2 border-soft settings-section-head">
            <h2 class="h6 mb-0">Notification Channels</h2>
            <span class="text-secondary small">Configure email and Telegram delivery, then verify with a test.</span>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-12 col-xl-7">
                    <div class="border-soft rounded-3 p-3 p-lg-4 h-100">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
                            <div class="d-flex align-items-center gap-2">
                                <span class="settings-group-icon"><i class="ti ti-mail" aria-hidden="true"></i></span>
                                <h2 class="h6 mb-0">Email</h2>
                            </div>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="channel_email_enabled" id="channel_email_enabled" <?= $settings['channel_email_enabled'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="channel_email_enabled">Enable Email</label>
                            </div>
                        </div>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label" for="setting-notify-email">Notification Recipient Email</label>
                                <input class="form-control" name="smtp_to_email" id="setting-notify-email" value="<?= e($settings['smtp_to_email']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="setting-smtp-host">SMTP Host</label>
                                <input class="form-control" name="smtp_host" id="setting-smtp-host" value="<?= e($settings['smtp_host']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="setting-smtp-port">SMTP Port</label>
                                <input class="form-control" type="number" name="smtp_port" id="setting-smtp-port" value="<?= e($settings['smtp_port']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="setting-smtp-secure">SMTP Secure</label>
                                <select class="form-select" name="smtp_secure" id="setting-smtp-secure">
                                    <option value="none" <?= $settings['smtp_secure'] === 'none' ? 'selected' : '' ?>>None</option>
                                    <option value="tls" <?= $settings['smtp_secure'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                                    <option value="ssl" <?= $settings['smtp_secure'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="setting-smtp-username">SMTP Username</label>
                                <input class="form-control" name="smtp_username" id="setting-smtp-username" value="<?= e($settings['smtp_username']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="setting-smtp-password">SMTP Password</label>
                                <input class="form-control" type="password" name="smtp_password" id="setting-smtp-password" placeholder="Leave blank to keep current password" autocomplete="off">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="setting-smtp-from-email">Sender Email</label>
                                <input class="form-control" name="smtp_from_email" id="setting-smtp-from-email" value="<?= e($settings['smtp_from_email']) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="setting-smtp-from-name">Sender Name</label>
                                <input class="form-control" name="smtp_from_name" id="setting-smtp-from-name" value="<?= e($settings['smtp_from_name']) ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-5">
                    <div class="border-soft rounded-3 p-3 p-lg-4 h-100">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
                            <div class="d-flex align-items-center gap-2">
                                <span class="settings-group-icon"><i class="ti ti-send" aria-hidden="true"></i></span>
                                <h2 class="h6 mb-0">Telegram</h2>
                            </div>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="channel_telegram_enabled" id="channel_telegram_enabled" <?= $settings['channel_telegram_enabled'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="channel_telegram_enabled">Enable Telegram</label>
                            </div>
                        </div>
                        <div class="row g-4">
                            <div class="col-12">
                                <label class="form-label" for="setting-telegram-token">Telegram Bot Token</label>
                                <input class="form-control" name="telegram_bot_token" id="setting-telegram-token" placeholder="Leave blank to keep current token" autocomplete="off">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="setting-telegram-chat-id">Telegram Chat ID</label>
                                <input class="form-control" name="telegram_chat_id" id="setting-telegram-chat-id" value="<?= e($settings['telegram_chat_id']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="setting-telegram-thread-id">Telegram Thread ID (optional)</label>
                                <input class="form-control" name="telegram_thread_id" id="setting-telegram-thread-id" value="<?= e($settings['telegram_thread_id']) ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="settings-action-footer">
                <button class="btn btn-info" type="submit" name="action" value="save" data-submit-loading data-loading-text="Saving...">Save Settings</button>
                <button class="btn btn-outline-success" type="submit" name="action" value="test_email" data-submit-loading data-loading-text="Sending test...">Test Email</button>
                <button class="btn btn-outline-primary" type="submit" name="action" value="test_telegram" data-submit-loading data-loading-text="Sending test...">Test Telegram</button>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($activeSection === 'security'): ?>
    <form method="post" class="card card-neon settings-form-card" data-ui-section>
        <?= csrf_input() ?>
        <input type="hidden" name="section" value="security">
        <div class="card-header bg-surface-2 border-soft settings-section-head">
            <h2 class="h6 mb-0">Security</h2>
            <span class="text-secondary small">Session timeouts and data retention controls.</span>
        </div>
        <div class="card-body">
            <div class="settings-group">
                <div class="settings-group-head">
                    <span class="settings-group-icon"><i class="ti ti-lock" aria-hidden="true"></i></span>
                    <div>
                        <h2 class="settings-group-title">Session Security</h2>
                        <p class="settings-group-desc">Idle timeout applies after inactivity. Absolute timeout applies from login.</p>
                    </div>
                </div>
                <div class="settings-group-body">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label" for="setting-session-idle">Idle Timeout (minutes)</label>
                            <input class="form-control" type="number" min="5" name="session_idle_timeout_minutes" id="setting-session-idle" value="<?= e($settings['session_idle_timeout_minutes']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-session-absolute">Absolute Timeout (minutes)</label>
                            <input class="form-control" type="number" min="15" name="session_absolute_timeout_minutes" id="setting-session-absolute" value="<?= e($settings['session_absolute_timeout_minutes']) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-group">
                <div class="settings-group-head">
                    <span class="settings-group-icon"><i class="ti ti-trash" aria-hidden="true"></i></span>
                    <div>
                        <h2 class="settings-group-title">Data Retention</h2>
                        <p class="settings-group-desc">How long historical data is kept before cleanup. Core retention cleans metrics, alerts, logins, and audit; disk retention cleans disk health metrics and disk history; backup retention prunes nightly database dumps.</p>
                    </div>
                </div>
                <div class="settings-group-body">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label" for="setting-retention-core">Core Retention (days)</label>
                            <select class="form-select" name="retention_days" id="setting-retention-core">
                                <?php foreach ([2, 7, 15, 30, 60, 90] as $d): ?>
                                    <option value="<?= $d ?>" <?= (int) $settings['retention_days'] === $d ? 'selected' : '' ?>><?= $d ?> days</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-retention-disk">Disk Retention (days)</label>
                            <select class="form-select" name="disk_retention_days" id="setting-retention-disk">
                                <?php foreach ([7, 15, 30, 60, 90, 180, 365] as $d): ?>
                                    <option value="<?= $d ?>" <?= (int) ($settings['disk_retention_days'] ?? '90') === $d ? 'selected' : '' ?>><?= $d ?> days</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="setting-retention-backup">Backup Retention (days)</label>
                            <select class="form-select" name="backup_retention_days" id="setting-retention-backup">
                                <?php foreach ([7, 15, 30, 60, 90] as $d): ?>
                                    <option value="<?= $d ?>" <?= (int) ($settings['backup_retention_days'] ?? '30') === $d ? 'selected' : '' ?>><?= $d ?> days</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="settings-action-footer">
                        <button class="btn btn-info" type="submit" name="action" value="save" data-submit-loading data-loading-text="Saving...">Save Settings</button>
                        <button class="btn btn-outline-warning" type="submit" name="action" value="run_retention" data-submit-loading data-loading-text="Running...">Run Core Retention</button>
                        <button class="btn btn-outline-warning" type="submit" name="action" value="run_disk_retention" data-submit-loading data-loading-text="Running...">Run Disk Retention</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($activeSection === 'ip_reputation'): ?>
    <form method="post" class="card card-neon settings-form-card" data-ui-section>
        <?= csrf_input() ?>
        <input type="hidden" name="section" value="ip_reputation">
        <div class="card-header bg-surface-2 border-soft settings-section-head">
            <h2 class="h6 mb-0">IP Reputation</h2>
            <span class="text-secondary small">Global configuration and third-party API keys.</span>
        </div>
        <div class="card-body">
            <div class="settings-group">
                <div class="settings-group-head">
                    <span class="settings-group-icon"><i class="ti ti-shield-check" aria-hidden="true"></i></span>
                    <div>
                        <h2 class="settings-group-title">Global Settings</h2>
                        <p class="settings-group-desc">Defaults applied when new IP reputation targets are added.</p>
                    </div>
                </div>
                <div class="settings-group-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="setting-iprep-interval">Check Interval (hours)</label>
                            <input class="form-control" type="number" min="1" max="168" name="ip_rep_check_interval_hours" id="setting-iprep-interval" value="<?= e($settings['ip_rep_check_interval_hours']) ?>">
                            <div class="form-text">Default interval used when adding new IP targets.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label d-block" for="ip_rep_alert_enabled">Alerts</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="ip_rep_alert_enabled" id="ip_rep_alert_enabled" <?= ($settings['ip_rep_alert_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="ip_rep_alert_enabled">Enable alerts for IP blacklisting and clearing</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-group">
                <div class="settings-group-head">
                    <span class="settings-group-icon"><i class="ti ti-key" aria-hidden="true"></i></span>
                    <div>
                        <h2 class="settings-group-title">External API Keys</h2>
                        <p class="settings-group-desc">Optional provider keys. Blank keeps the current value; use Clear to remove.</p>
                    </div>
                </div>
                <div class="settings-group-body">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label" for="setting-abuseipdb-key">AbuseIPDB API Key</label>
                            <div class="input-group">
                                <input class="form-control" type="password" name="ip_rep_abuseipdb_key" id="setting-abuseipdb-key" placeholder="Leave blank to keep existing key" autocomplete="off">
                                <?php if (setting_get('ip_rep_abuseipdb_key') !== ''): ?>
                                    <div class="input-group-text"><div class="form-check mb-0"><input class="form-check-input mt-0" type="checkbox" name="clear_ip_rep_abuseipdb_key" value="1" title="Clear key"> Clear</div></div>
                                <?php endif; ?>
                            </div>
                            <div class="form-text">Register at abuseipdb.com. Provides IP trust scores. Free tier has 1000 requests per day.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="setting-virustotal-key">VirusTotal API Key</label>
                            <div class="input-group">
                                <input class="form-control" type="password" name="ip_rep_virustotal_key" id="setting-virustotal-key" placeholder="Leave blank to keep existing key" autocomplete="off">
                                <?php if (setting_get('ip_rep_virustotal_key') !== ''): ?>
                                    <div class="input-group-text"><div class="form-check mb-0"><input class="form-check-input mt-0" type="checkbox" name="clear_ip_rep_virustotal_key" value="1" title="Clear key"> Clear</div></div>
                                <?php endif; ?>
                            </div>
                            <div class="form-text">Register at virustotal.com. Scans against dozens of security vendors. Free tier allows 500 requests per day.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="setting-ipinfo-key">ipinfo.io Access Token</label>
                            <div class="input-group">
                                <input class="form-control" type="password" name="ip_rep_ipinfo_key" id="setting-ipinfo-key" placeholder="Leave blank to keep existing key" autocomplete="off">
                                <?php if (setting_get('ip_rep_ipinfo_key') !== ''): ?>
                                    <div class="input-group-text"><div class="form-check mb-0"><input class="form-check-input mt-0" type="checkbox" name="clear_ip_rep_ipinfo_key" value="1" title="Clear key"> Clear</div></div>
                                <?php endif; ?>
                            </div>
                            <div class="form-text">Register at ipinfo.io. Provides privacy detection (VPN/Tor/Proxy/Hosting). Free tier offers 50k requests per month. Standard DNSBLs are queried automatically without keys.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-action-footer">
                <button class="btn btn-info" type="submit" name="action" value="save" data-submit-loading data-loading-text="Saving...">Save Settings</button>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($activeSection === 'users'): ?>
    <section class="card card-neon settings-data-card" data-ui-section>
        <div class="card-header bg-surface-2 border-soft settings-section-head">
            <span>User Management</span>
            <span class="text-secondary small">Manage admin/viewer accounts</span>
        </div>
        <div class="card-body">
            <form method="post" class="border-soft rounded-3 p-3 p-lg-4 mb-4">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="user_create">
                <input type="hidden" name="section" value="users">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="settings-group-icon"><i class="ti ti-user-plus" aria-hidden="true"></i></span>
                    <div>
                        <h2 class="settings-group-title mb-0">Create New User</h2>
                        <p class="settings-group-desc mb-0">Use the <code>viewer</code> role for read-only access, <code>admin</code> for full management.</p>
                    </div>
                </div>
                <div class="row g-4 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label" for="setting-user-username">Username</label>
                        <input class="form-control" name="username" id="setting-user-username" required maxlength="50" pattern="[a-zA-Z0-9_.-]{3,50}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="setting-user-password">Password</label>
                        <input class="form-control" type="password" name="password" id="setting-user-password" required minlength="8">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="setting-user-role">Role</label>
                        <select class="form-select" name="role" id="setting-user-role">
                            <option value="viewer">viewer</option>
                            <option value="admin">admin</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-info w-100" type="submit" data-submit-loading data-loading-text="Creating...">Add User</button>
                    </div>
                </div>
            </form>

            <div class="table-responsive table-shell" data-ui-table>
                <table class="table monitors-table settings-user-table mb-0">
                    <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Last Login</th>
                        <th>Created At</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="5" class="table-empty">No users found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($users as $userRow): ?>
                        <?php $isSelf = (int) ($userRow['id'] ?? 0) === (int) ($currentUser['id'] ?? 0); ?>
                        <?php $isLastAdmin = (string) ($userRow['role'] ?? '') === 'admin' && $adminCount <= 1; ?>
                        <tr>
                            <td><?= e((string) $userRow['username']) ?><?= $isSelf ? ' <span class="text-secondary small">(you)</span>' : '' ?></td>
                            <td><span class="badge text-bg-secondary text-uppercase"><?= e((string) $userRow['role']) ?></span></td>
                            <td><?= e((string) ($userRow['last_login'] ?? '-')) ?></td>
                            <td><?= e((string) ($userRow['created_at'] ?? '-')) ?></td>
                            <td class="text-end">
                                <div class="row-actions">
                                    <form method="post" class="d-inline-flex gap-1 align-items-center">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="user_update">
                                        <input type="hidden" name="section" value="users">
                                        <input type="hidden" name="user_id" value="<?= e((string) $userRow['id']) ?>">
                                        <select class="form-select form-select-sm" name="role" aria-label="Role for <?= e((string) $userRow['username']) ?>">
                                            <option value="viewer" <?= (string) $userRow['role'] === 'viewer' ? 'selected' : '' ?>>viewer</option>
                                            <option value="admin" <?= (string) $userRow['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                                        </select>
                                        <input class="form-control form-control-sm" type="password" name="new_password" placeholder="password" autocomplete="new-password" aria-label="New password for <?= e((string) $userRow['username']) ?>">
                                        <button class="btn btn-sm btn-outline-info" type="submit" data-submit-loading data-loading-text="Saving...">Save</button>
                                    </form>
                                    <form method="post" class="d-inline-flex">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="user_delete">
                                        <input type="hidden" name="section" value="users">
                                        <input type="hidden" name="user_id" value="<?= e((string) $userRow['id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Delete user <?= e((string) $userRow['username']) ?>?" data-submit-loading data-loading-text="Deleting..." <?= ($isSelf || $isLastAdmin) ? 'disabled' : '' ?>>Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($activeSection === 'ops'): ?>
    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <section class="card card-neon settings-data-card h-100" data-ui-section>
                <div class="card-header bg-surface-2 border-soft"><h2 class="h6 mb-0">Worker Health</h2></div>
                <div class="card-body">
                    <div class="table-responsive table-shell" data-ui-table>
                        <table class="table monitors-table mb-0">
                            <thead>
                            <tr>
                                <th>Worker</th>
                                <th>Health</th>
                                <th>Last Success</th>
                                <th>Last Error</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($workerStatuses as $workerName => $workerStatus): ?>
                                <?php
                                $health = (string) ($workerStatus['health'] ?? 'unknown');
                                $badgeClass = match ($health) {
                                    'ok' => 'badge-severity badge-severity-success',
                                    'error' => 'badge-severity badge-severity-danger',
                                    'stale' => 'badge-severity badge-severity-warning',
                                    default => 'badge-severity badge-severity-info',
                                };
                                ?>
                                <tr>
                                    <td><code><?= e($workerName) ?></code></td>
                                    <td><span class="badge <?= e($badgeClass) ?> text-uppercase"><?= e($health) ?></span></td>
                                    <td><?= e((string) ($workerStatus['last_success_at'] ?? 'never')) ?></td>
                                    <td><?= e((string) ($workerStatus['last_error'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-5">
            <section class="card card-neon h-100" data-ui-section>
                <div class="card-header bg-surface-2 border-soft"><h2 class="h6 mb-0">Operations Runbook</h2></div>
                <div class="card-body">
                    <h2 class="h6 mb-2">Recommended Cron</h2>
                    <p class="text-muted small">The export worker only processes jobs created via the Large Export Queue menu; it does not create exports automatically.</p>
                    <pre class="code-block rounded p-3 mb-0"><code><?= e(implode(PHP_EOL, $recommendedCron)) ?></code></pre>
                </div>
            </section>
        </div>
        <div class="col-12">
            <section class="card card-neon" data-ui-section>
                <div class="card-header bg-surface-2 border-soft"><h2 class="h6 mb-0">Security Posture</h2></div>
                <div class="card-body">
                    <?php
                    $instPresent = $installerPresent ?? installer_still_present();
                    $instLocked = $installerLocked ?? installer_locked();
                    $localPerms = $localConfigPerms ?? local_config_perms();
                    ?>
                    <ul class="list-unstyled mb-0 small">
                        <li class="mb-2">
                            <?php if (!$instPresent): ?>
                                <i class="ti ti-shield-check text-success me-1" aria-hidden="true"></i>Installer removed (<code>public/install.php</code> not present).
                            <?php elseif ($instLocked): ?>
                                <i class="ti ti-shield-check text-success me-1" aria-hidden="true"></i>Installer disabled via <code>config/.installer-locked</code>.
                            <?php else: ?>
                                <i class="ti ti-shield-lock text-danger me-1" aria-hidden="true"></i><strong>Installer still accessible</strong> — remove <code>public/install.php</code> or create <code>config/.installer-locked</code>.
                            <?php endif; ?>
                        </li>
                        <li class="mb-0">
                            <?php if (!($localPerms['exists'] ?? false)): ?>
                                <i class="ti ti-alert-triangle text-warning me-1" aria-hidden="true"></i><code>config/local.php</code> missing (using env/defaults).
                            <?php elseif (!empty($localPerms['world_readable'])): ?>
                                <i class="ti ti-alert-triangle text-warning me-1" aria-hidden="true"></i><code>config/local.php</code> is world-readable (<code><?= e((string) ($localPerms['octal'] ?? '?')) ?></code>). Tighten to <code>0640</code> if web and CLI share a group.
                            <?php else: ?>
                                <i class="ti ti-shield-check text-success me-1" aria-hidden="true"></i><code>config/local.php</code> permissions <code><?= e((string) ($localPerms['octal'] ?? '?')) ?></code> (not world-readable).
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            </section>
        </div>
        <div class="col-12">
            <section class="card card-neon" data-ui-section>
                <div class="card-header bg-surface-2 border-soft"><h2 class="h6 mb-0">Queue &amp; SLO 99.9%</h2></div>
                <div class="card-body">
                    <?php $qd = $queueDepth ?? ['alert_delivery_queue' => 0, 'alert_delivery_dead' => 0, 'export_jobs_queued' => 0, 'export_jobs_running' => 0]; ?>
                    <div class="queue-badge mb-3" data-queue-depth role="status" title="Queue depths from health checks">
                        Queue: delivery <?= e((string) ($qd['alert_delivery_queue'] ?? 0)) ?> | dead <?= e((string) ($qd['alert_delivery_dead'] ?? 0)) ?> | export <?= e((string) ($qd['export_jobs_queued'] ?? 0)) ?> | running <?= e((string) ($qd['export_jobs_running'] ?? 0)) ?>
                    </div>
                    <?php
                    $slo7v = $slo7 ?? []; $slo30v = $slo30 ?? []; $lagv = $ingestLag ?? []; $partsv = $parts ?? [];
                    $sloNum = static function ($v, int $dec = 1): string { return is_numeric($v) ? number_format((float) $v, $dec) : 'n/a'; };
                    $slo7Pct = $sloNum($slo7v['availability_pct'] ?? null); $slo30Pct = $sloNum($slo30v['availability_pct'] ?? null);
                    $sloDown = isset($slo30v['downtime_minutes']) && is_numeric($slo30v['downtime_minutes']) ? formatUptimeCompact((int) $slo30v['downtime_minutes'] * 60) : 'n/a';
                    $sloBudgetMin = isset($slo30v['error_budget_minutes']) && is_numeric($slo30v['error_budget_minutes']) ? formatUptimeCompact((int) $slo30v['error_budget_minutes'] * 60) : 'n/a';
                    $sloBudgetRem = $sloNum($slo30v['budget_remaining_pct'] ?? null, 0);
                    $sloBurn = $sloNum($slo30v['burn_rate'] ?? null, 1);
                    $sloBuckets = e((string) ($slo30v['online_buckets'] ?? 'n/a')) . ' / ' . e((string) ($slo30v['total_buckets'] ?? 'n/a'));
                    $sloP95 = isset($lagv['p95_ms']) && is_numeric($lagv['p95_ms']) ? e(number_format((float) $lagv['p95_ms']) . 'ms') : 'n/a';
                    $sloPart = isset($partsv['lag_days']) && is_numeric($partsv['lag_days']) ? e((string) $partsv['lag_days'] . 'd') : 'n/a';
                    $sloTrunc = !empty($slo30v['window_truncated']) && isset($slo30v['effective_days']) && is_numeric($slo30v['effective_days'])
                        ? ' <small>(calculated ' . e((string) $slo30v['effective_days']) . ' of 30 days)</small>' : '';
                    $sloMeasured = isset($slo30v['servers_measured']) && is_numeric($slo30v['servers_measured'])
                        ? ' <small>(' . e((string) $slo30v['servers_measured']) . ' server(s))</small>' : '';
                    ?>
                    <div class="slo-grid px-0" data-slo-card>
                        <span class="slo-item" title="Average availability across active servers (one healthy server does not mask servers that are down). Target 99.9%">Availability <strong>7d: <?= e($slo7Pct) ?>%</strong> · <strong>30d: <?= e($slo30Pct) ?>%</strong> <small>(target 99.9%)</small><?= $sloTrunc ?><?= $sloMeasured ?></span>
                        <span class="slo-item" title="Total data-less buckets × 5 minutes (<?= $sloBuckets ?> online / expected)">Downtime <strong><?= e($sloDown) ?></strong> <small>(<?= $sloBuckets ?> bucket)</small></span>
                        <span class="slo-item" title="Remaining 30-day downtime allowance (total budget <?= e($sloBudgetMin) ?>). Negative = budget exceeded">Budget rem <strong><?= e($sloBudgetRem) ?>%</strong></span>
                        <span class="slo-item" title="Budget burn rate: 1.0× = exactly exhausted within 30 days">Burn <strong><?= e($sloBurn) ?>×</strong></span>
                        <span class="slo-item" title="95th percentile of agent-to-server delay (requires a signed agent; n/a = no data yet)">Ingest p95 <strong><?= $sloP95 ?></strong></span>
                        <span class="slo-item" title="Newest metrics partition vs today">Partition lag <strong><?= $sloPart ?></strong></span>
                    </div>
                    <p class="text-muted small mb-0 mt-2">Per-worker details are available at <code>/api/health</code> (<code>checks.queue_depth</code>, <code>checks.slo</code>, <code>checks.ingest_lag</code>, <code>checks.partitions</code>).</p>
                </div>
            </section>
        </div>
        <div class="col-12">
            <section class="card card-neon" data-ui-section>
                <div class="card-header bg-surface-2 border-soft"><h2 class="h6 mb-0">Metrics Table Storage</h2></div>
                <div class="card-body">
                    <?php if (!empty($metricsStorage['error'])): ?>
                        <p class="text-muted small mb-0">Unable to read table statistics: <?= e($metricsStorage['error']) ?></p>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <div class="stat-box">
                                    <div class="stat-label text-muted small">Table Size (data + index)</div>
                                    <div class="stat-value font-mono"><?= e(formatBytes($metricsStorage['table_size'])) ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-box">
                                    <div class="stat-label text-muted small">Data / Index</div>
                                    <div class="stat-value font-mono"><?= e(formatBytes($metricsStorage['data_size'])) ?> / <?= e(formatBytes($metricsStorage['index_size'])) ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-box">
                                    <div class="stat-label text-muted small">Daily Partition Count</div>
                                    <div class="stat-value font-mono"><?= e((string) $metricsStorage['partition_count']) ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-box">
                                    <div class="stat-label text-muted small">Oldest / Newest Partition</div>
                                    <div class="stat-value font-mono">
                                        <?= $metricsStorage['oldest_partition'] !== null ? e($metricsStorage['oldest_partition']) : '-' ?>
                                        / <?= $metricsStorage['newest_partition'] !== null ? e($metricsStorage['newest_partition']) : '-' ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="text-muted small mb-0 mt-3">
                            <?php if ($metricsStorage['is_partitioned']): ?>
                                The <code>metrics</code> table is partitioned. Raw data older than <code>metrics_raw_hours</code> (24 hours) is removed automatically by the <code>cleanup.php</code> cron (<code>0 3 * * *</code>), and daily partitions are kept current by <code>partition-maintain.php</code> (<code>30 0 * * *</code>).
                            <?php else: ?>
                                The <code>metrics</code> table is not partitioned yet — run <code>php migrate.php</code>, then make sure <code>partition-maintain.php</code> is active.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
    <?php endif; ?>
</main>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
