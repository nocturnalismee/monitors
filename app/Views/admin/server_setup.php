<main id="main-content" class="container py-4 admin-page admin-shell">
    <?php $setupToken = $displayToken !== '' ? $displayToken : 'Token available after rotation'; ?>
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">Agent Installation</h1>
            <p class="page-subtitle">Server: <?= e((string) $server['name']) ?>. Select the appropriate agent profile, run a manual test, then enable the service.</p>
        </div>
        <div class="toolbar-actions">
            <a class="btn btn-soft" href="<?= e(app_url('servers')) ?>">Back to List</a>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0">Quick Config Values</h2>
            <span class="badge text-bg-info">Copy & paste ready</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-lg-4">
                    <label class="form-label fw-semibold mb-1" for="setup-master-url">MASTER_URL</label>
                    <div class="input-group">
                        <input class="form-control font-mono" type="text" id="setup-master-url" readonly value="<?= e($pushEndpoint) ?>">
                        <button class="btn btn-soft" type="button" data-copy-text="<?= e($pushEndpoint) ?>">Copy</button>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <label class="form-label fw-semibold mb-1" for="setup-server-token">SERVER_TOKEN</label>
                    <div class="input-group">
                        <input class="form-control font-mono" type="text" id="setup-server-token" readonly value="<?= e($setupToken) ?>">
                        <?php if ($displayToken !== ''): ?><button class="btn btn-soft" type="button" data-copy-text="<?= e($displayToken) ?>">Copy</button><?php endif; ?>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <label class="form-label fw-semibold mb-1" for="setup-server-id">SERVER_ID</label>
                    <div class="input-group">
                        <input class="form-control font-mono" type="text" id="setup-server-id" readonly value="<?= e((string) ((int) $server['id'])) ?>">
                        <button class="btn btn-soft" type="button" data-copy-text="<?= e((string) ((int) $server['id'])) ?>">Copy</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0">Profile A: General Server (Recommended)</h2>
            <span class="badge text-bg-success">systemd service (real-time)</span>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-2">Run on the target server:</p>
<div class="term" data-term>
    <div class="term-bar">
        <span class="term-dots" aria-hidden="true"><i></i><i></i><i></i></span>
        <span class="term-title">root@server:~</span>
        <button class="btn btn-sm term-copy" type="button" data-term-copy title="Copy commands only (comment lines skipped)"><i class="ti ti-copy me-1" aria-hidden="true"></i>Copy</button>
    </div>
<pre class="term-body mb-0"><code># 1) Install Script Bash agent
wget <?= e($agentUrl) ?> -O /usr/local/bin/monitoring-agent.sh
chmod +x /usr/local/bin/monitoring-agent.sh

# 2) Configuration: everything lives in /etc/systemd/monitoring-agent.conf
wget <?= e($confExampleUrl) ?> -O /etc/systemd/monitoring-agent.conf
sed -i "s|^MASTER_URL=.*|MASTER_URL=<?= e($pushEndpoint) ?>|" /etc/systemd/monitoring-agent.conf
sed -i "s|^SERVER_TOKEN=.*|SERVER_TOKEN=<?= e($setupToken) ?>|" /etc/systemd/monitoring-agent.conf
sed -i "s|^SERVER_ID=.*|SERVER_ID=<?= e((string) $server['id']) ?>|" /etc/systemd/monitoring-agent.conf

# Manual test (one-shot; the daemon only runs every 10 seconds once the service is active)
/usr/local/bin/monitoring-agent.sh

# 3) mkdir /var/lib/monitoring-agent

# 4) Install systemd service (daemon, auto-restart, pushes every 10 seconds)
wget <?= e($systemdServiceUrl) ?> -O /etc/systemd/system/monitoring-agent.service

# Manual test (one-shot; the daemon only runs every 10 seconds once the service is active)
/usr/local/bin/monitoring-agent.sh

# 5) Run service systemd
systemctl daemon-reload
systemctl enable --now monitoring-agent.service
systemctl status monitoring-agent.service --no-pager
</code></pre>
</div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0">Profile B: cPanel Email Host</h2>
            <span class="badge text-bg-warning">systemd service (real-time)</span>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-2">Use this profile specifically for mail/cPanel email nodes:</p>
<div class="term" data-term>
    <div class="term-bar">
        <span class="term-dots" aria-hidden="true"><i></i><i></i><i></i></span>
        <span class="term-title">root@server:~</span>
        <button class="btn btn-sm term-copy" type="button" data-term-copy title="Copy commands only (comment lines skipped)"><i class="ti ti-copy me-1" aria-hidden="true"></i>Copy</button>
    </div>
<pre class="term-body mb-0"><code># 1) Special email agent script
wget <?= e($agentEmailUrl) ?> -O /usr/local/bin/monitoring-agent-cpanel-mail.sh
chmod +x /usr/local/bin/monitoring-agent-cpanel-mail.sh

# 2) Configuration: everything lives in /etc/monitoring-agent-cpanel-mail.conf
wget <?= e($confEmailExampleUrl) ?> -O /etc/monitoring-agent-cpanel-mail.conf
sed -i "s|^MASTER_URL=.*|MASTER_URL=<?= e($pushEndpoint) ?>|" /etc/monitoring-agent-cpanel-mail.conf
sed -i "s|^SERVER_TOKEN=.*|SERVER_TOKEN=<?= e($setupToken) ?>|" /etc/monitoring-agent-cpanel-mail.conf
sed -i "s|^SERVER_ID=.*|SERVER_ID=<?= e((string) $server['id']) ?>|" /etc/monitoring-agent-cpanel-mail.conf

# 3) mkdir /var/lib/monitoring-agent

# 4) Install systemd service (daemon, auto-restart, pushes every 10 seconds)
wget <?= e($systemdEmailServiceUrl) ?> -O /etc/systemd/system/monitoring-agent-cpanel-email.service
systemctl daemon-reload
systemctl enable --now monitoring-agent-cpanel-email.service
systemctl status monitoring-agent-cpanel-email.service --no-pager

# 5) Watch live logs (optional)
journalctl -u monitoring-agent-cpanel-email.service -f
</code></pre>
</div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0">Fallback: Legacy Cron</h2>
            <span class="badge text-bg-secondary">optional</span>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-2">Use this if the server does not support systemd service (daemon):</p>
<div class="term" data-term>
    <div class="term-bar">
        <span class="term-dots" aria-hidden="true"><i></i><i></i><i></i></span>
        <span class="term-title">root@server:~</span>
        <button class="btn btn-sm term-copy" type="button" data-term-copy title="Copy commands only (comment lines skipped)"><i class="ti ti-copy me-1" aria-hidden="true"></i>Copy</button>
    </div>
<pre class="term-body mb-0"><code>(crontab -l 2>/dev/null; echo "* * * * * /usr/local/bin/monitoring-agent.sh") | crontab -
</code></pre>
</div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-body d-flex flex-wrap gap-2 justify-content-end">
            <a class="btn btn-info" href="<?= e(app_url('servers/' . (int) $server['id'])) ?>">View Server Details</a>
            <a class="btn btn-outline-light" href="<?= e(app_url('servers')) ?>">Back to List</a>
        </div>
    </section>
</main>
