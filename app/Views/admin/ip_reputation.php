<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <h1 class="page-title">IP Reputation</h1>
            <p class="page-subtitle">Monitor IP reputation across DNSBL, AbuseIPDB, VirusTotal, and ipinfo.</p>
        </div>
        <?php if ($canManage): ?>
            <div class="toolbar-actions">
                <a href="<?= e(app_url('ip-reputation/add')) ?>" class="btn btn-info"><i class="ti ti-plus me-1"></i>Add IP</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="row g-3 summary-grid" data-ui-section>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-total p-3">
                <div class="summary-card-head"><span class="summary-card-label">Total</span><i class="ti ti-shield-lock summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) $summary['total']) ?></div>
                <div class="summary-card-subtitle">Monitored IPs</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-online p-3">
                <div class="summary-card-head"><span class="summary-card-label">Clean</span><i class="ti ti-shield-check summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) $summary['clean']) ?></div>
                <div class="summary-card-subtitle">No blacklist listings</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-down p-3">
                <div class="summary-card-head"><span class="summary-card-label">Listed</span><i class="ti ti-alert-octagon summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) $summary['listed']) ?></div>
                <div class="summary-card-subtitle">Blacklisted IPs</div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card card-neon summary-card summary-card-pending p-3">
                <div class="summary-card-head"><span class="summary-card-label">Unknown/Paused</span><i class="ti ti-history summary-card-icon"></i></div>
                <div class="summary-card-value"><?= e((string) ($summary['unknown'] + $summary['paused'])) ?></div>
                <div class="summary-card-subtitle">Pending <?= e((string) $summary['unknown']) ?> | Paused <?= e((string) $summary['paused']) ?></div>
            </div>
        </div>
    </section>

    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-5 col-lg-4">
                    <label class="form-label">Search</label>
                    <input class="form-control" name="q" placeholder="IP address or label" value="<?= e($q) ?>">
                </div>
                <div class="col-md-4 col-lg-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <?php foreach (['all', 'clean', 'listed', 'unknown', 'paused'] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $statusFilter === $opt ? 'selected' : '' ?>><?= e(strtoupper($opt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2 d-flex align-items-end">
                    <button class="btn btn-info w-100" type="submit">Filter</button>
                </div>
            </form>
        </div>

        <div class="table-responsive table-shell" data-ui-table>
            <table class="table servmon-table mb-0">
                <thead>
                <tr>
                    <th>IP Address</th>
                    <th>Label</th>
                    <th>Server</th>
                    <th>Status</th>
                    <th>Blacklists</th>
                    <th>Providers</th>
                    <th>Last Check</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="table-empty">
                        <div class="table-empty-inner">
                            <span>No IP reputation targets found.</span>
                            <?php if ($canManage): ?>
                                <a href="<?= e(app_url('ip-reputation/add')) ?>" class="btn btn-sm btn-info mt-2"><i class="ti ti-plus me-1" aria-hidden="true"></i>Add your first target</a>
                            <?php endif; ?>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $status      = (string) ($row['display_status'] ?? 'unknown');
                    $listedOnArr = $row['listed_on_arr'] ?? [];
                    $providers   = $row['provider_results_arr'] ?? [];
                    $listedCount = (int) ($row['listed_count'] ?? 0);
                    $totalChecked = (int) ($row['total_checked'] ?? 0);
                    $abuseScore   = isset($providers['abuseipdb']['score']) ? (int) $providers['abuseipdb']['score'] : null;
                    $vtMalicious  = isset($providers['virustotal']['malicious']) ? (int) $providers['virustotal']['malicious'] : null;
                    ?>
                    <tr>
                        <td>
                            <a href="<?= e(app_url('ip-reputation/' . (int) $row['id'])) ?>" class="fw-semibold text-decoration-none">
                                <code><?= e((string) $row['ip_address']) ?></code>
                            </a>
                        </td>
                        <td><?= e((string) ($row['label'] ?? '-')) ?></td>
                        <td>
                            <?php if (!empty($row['server_name'])): ?>
                                <span><i class="ti ti-server me-1"></i><?= e((string) $row['server_name']) ?></span>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= e(ip_rep_status_badge_class($status)) ?> text-uppercase"><?= e($status) ?></span></td>
                        <td>
                            <?php if ($status === 'clean' || $status === 'unknown' || $status === 'paused'): ?>
                                <span class="font-mono text-secondary"><?= e("{$listedCount}/{$totalChecked}") ?></span>
                            <?php else: ?>
                                <span class="font-mono text-danger fw-semibold"><?= e("{$listedCount}/{$totalChecked}") ?></span>
                                <?php if (!empty($listedOnArr)): ?>
                                    <div class="ip-rep-listed-chips mt-1">
                                        <?php foreach (array_slice($listedOnArr, 0, 3) as $bl): ?>
                                            <span class="badge bg-danger bg-opacity-25 text-danger me-1 mb-1"><?= e((string) $bl) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($listedOnArr) > 3): ?>
                                            <span class="badge bg-secondary bg-opacity-25 text-secondary mb-1">+<?= e((string) (count($listedOnArr) - 3)) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <?php if (isset($providers['abuseipdb'])): ?>
                                    <span class="badge <?= $abuseScore > 25 ? 'bg-danger' : 'bg-success' ?> bg-opacity-25 <?= $abuseScore > 25 ? 'text-danger' : 'text-success' ?>" title="AbuseIPDB score: <?= e((string) $abuseScore) ?>">
                                        AIPDB <?= e((string) $abuseScore) ?>%
                                    </span>
                                <?php endif; ?>
                                <?php if (isset($providers['virustotal'])): ?>
                                    <span class="badge <?= $vtMalicious > 0 ? 'bg-danger' : 'bg-success' ?> bg-opacity-25 <?= $vtMalicious > 0 ? 'text-danger' : 'text-success' ?>" title="VirusTotal malicious: <?= e((string) $vtMalicious) ?>">
                                        VT <?= e((string) $vtMalicious) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (isset($providers['ipinfo'])): ?>
                                    <?php $privacy = $providers['ipinfo']['privacy'] ?? []; $flags = []; if ($privacy['vpn'] ?? false) $flags[] = 'VPN'; if ($privacy['proxy'] ?? false) $flags[] = 'Proxy'; if ($privacy['tor'] ?? false) $flags[] = 'Tor'; if ($privacy['hosting'] ?? false) $flags[] = 'Host'; ?>
                                    <?php if (!empty($flags)): ?>
                                        <span class="badge bg-warning bg-opacity-25 text-warning" title="ipinfo flags"><?= e(implode(' ', $flags)) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-success bg-opacity-25 text-success" title="ipinfo: clean">ipinfo ✓</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><small class="text-secondary"><?= e((string) ($row['last_checked_at'] ?? '-')) ?></small></td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end flex-wrap">
                                <a class="btn btn-sm btn-soft" href="<?= e(app_url('ip-reputation/' . (int) $row['id'])) ?>">Details</a>
                                <?php if ($canManage): ?>
                                    <button class="btn btn-sm btn-outline-primary" type="button" data-ip-rep-check-now="<?= e((string) $row['id']) ?>" data-ip="<?= e((string) $row['ip_address']) ?>">Check</button>
                                    <a class="btn btn-sm btn-outline-info" href="<?= e(app_url('ip-reputation/' . (int) $row['id'] . '/edit')) ?>">Edit</a>
                                    <form method="post" class="m-0 d-inline">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="target_id" value="<?= e((string) $row['id']) ?>">
                                        <button class="btn btn-sm btn-outline-warning" type="submit"><?= (int) ($row['active'] ?? 0) === 1 ? 'Pause' : 'Enable' ?></button>
                                    </form>
                                    <form method="post" class="m-0 d-inline">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="target_id" value="<?= e((string) $row['id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Delete this IP reputation target and all history?">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
window.SERVMON_IP_REP_API = <?= json_encode(app_url('api/ip-reputation'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.SERVMON_CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= e(asset_url('assets/js/forms.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/ip-reputation.js')) ?>"></script>
