<main id="main-content" class="container py-4 admin-page admin-shell">
    <section class="page-header" data-ui-toolbar>
        <div>
            <a href="<?= e(app_url('ip-reputation')) ?>" class="btn btn-sm btn-outline-secondary me-2"><i class="ti ti-arrow-left"></i></a>
            <h1 class="page-title d-inline-block mb-0 align-middle">
                <code><?= e((string) $target['ip_address']) ?></code>
                <?php if (!empty($target['label'])): ?>
                    <small class="text-secondary ms-2"><?= e((string) $target['label']) ?></small>
                <?php endif; ?>
            </h1>
            <div class="mt-1">
                <span class="badge <?= e(ip_rep_status_badge_class($displayStatus)) ?> text-uppercase me-2"><?= e($displayStatus) ?></span>
                <?php if (!empty($target['server_name'])): ?>
                    <span class="text-secondary"><i class="ti ti-server me-1"></i><?= e((string) $target['server_name']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="toolbar-actions">
            <?php if (has_role('admin')): ?>
                <button class="btn btn-outline-info btn-sm" type="button" id="btn-check-now" data-ip-rep-check-now="<?= e((string) $target['id']) ?>" data-ip="<?= e((string) $target['ip_address']) ?>">
                    <i class="ti ti-refresh me-1"></i>Check Now
                </button>
                <a href="<?= e(app_url('ip-reputation/' . (int) $target['id'] . '/edit')) ?>" class="btn btn-soft btn-sm">
                    <i class="ti ti-pencil me-1"></i>Edit
                </a>
            <?php endif; ?>
        </div>
    </section>

    <!-- Summary Info -->
    <section class="row g-3 mb-4" data-ui-section>
        <div class="col-12 col-md-3">
            <div class="card card-neon p-3 text-center">
                <div class="text-secondary small mb-1">DNSBL Listings</div>
                <div class="fs-3 fw-bold font-mono <?= (int) ($target['listed_count'] ?? 0) > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= e((string) ($target['listed_count'] ?? 0)) ?> / <?= e((string) ($target['total_checked'] ?? 0)) ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card card-neon p-3 text-center">
                <div class="text-secondary small mb-1">AbuseIPDB Score</div>
                <div class="fs-3 fw-bold font-mono <?= $abuseipdb !== null && ($abuseipdb['score'] ?? 0) > 25 ? 'text-danger' : 'text-success' ?>">
                    <?= $abuseipdb !== null ? e((string) ($abuseipdb['score'] ?? 0)) . '%' : '—' ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card card-neon p-3 text-center">
                <div class="text-secondary small mb-1">VirusTotal</div>
                <div class="fs-3 fw-bold font-mono <?= $virustotal !== null && ($virustotal['malicious'] ?? 0) > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= $virustotal !== null ? e((string) ($virustotal['malicious'] ?? 0)) . ' malicious' : '—' ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card card-neon p-3 text-center">
                <div class="text-secondary small mb-1">Last Check</div>
                <div class="fs-6 fw-semibold font-mono text-secondary">
                    <?= e((string) ($target['last_checked_at'] ?? 'Never')) ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Provider Details -->
    <section class="row g-3 mb-4">
        <!-- DNSBL Grid -->
        <div class="col-12 col-lg-6">
            <div class="card card-neon">
                <div class="card-header bg-surface-2 border-soft"><h2 class="h6 mb-0"><i class="ti ti-list-check me-2"></i>DNSBL Results</h2></div>
                <div class="card-body p-0">
                    <div class="ip-rep-blacklist-grid">
                        <?php foreach ($dnsbls as $bl): ?>
                            <?php
                            $isListed = in_array($bl, $dnsblResults['listed'] ?? [], true);
                            $chipClass = $isListed ? 'ip-rep-dnsbl-chip is-listed' : 'ip-rep-dnsbl-chip is-clean';
                            ?>
                            <div class="<?= e($chipClass) ?>">
                                <i class="ti <?= $isListed ? 'ti-alert-circle' : 'ti-circle-check' ?>"></i>
                                <span><?= e($bl) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- API Providers -->
        <div class="col-12 col-lg-6">
            <div class="card card-neon">
                <div class="card-header bg-surface-2 border-soft"><h2 class="h6 mb-0"><i class="ti ti-plug me-2"></i>API Providers</h2></div>
                <ul class="list-group list-group-flush">
                    <!-- AbuseIPDB -->
                    <li class="list-group-item bg-transparent">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong>AbuseIPDB</strong>
                            <?php if ($abuseipdb !== null): ?>
                                <span class="badge <?= ($abuseipdb['score'] ?? 0) > 25 ? 'bg-danger bg-opacity-25 text-danger' : 'bg-success bg-opacity-25 text-success' ?>"><?= e((string) ($abuseipdb['score'] ?? 0)) ?>% confidence</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Not configured</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($abuseipdb !== null): ?>
                            <small class="text-secondary">Reports: <?= e((string) ($abuseipdb['total_reports'] ?? 0)) ?> | ISP: <?= e((string) ($abuseipdb['isp'] ?? '-')) ?> | Country: <?= e((string) ($abuseipdb['country'] ?? '-')) ?></small>
                        <?php endif; ?>
                    </li>
                    <!-- VirusTotal -->
                    <li class="list-group-item bg-transparent">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong>VirusTotal</strong>
                            <?php if ($virustotal !== null): ?>
                                <span class="badge <?= ($virustotal['malicious'] ?? 0) > 0 ? 'bg-danger bg-opacity-25 text-danger' : 'bg-success bg-opacity-25 text-success' ?>"><?= e((string) ($virustotal['malicious'] ?? 0)) ?> malicious</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Not configured</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($virustotal !== null): ?>
                            <small class="text-secondary">Suspicious: <?= e((string) ($virustotal['suspicious'] ?? 0)) ?> | Harmless: <?= e((string) ($virustotal['harmless'] ?? 0)) ?> | Undetected: <?= e((string) ($virustotal['undetected'] ?? 0)) ?></small>
                        <?php endif; ?>
                    </li>
                    <!-- ipinfo -->
                    <li class="list-group-item bg-transparent">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong>ipinfo.io</strong>
                            <?php if ($ipinfo !== null): ?>
                                <?php
                                $privacy = $ipinfo['privacy'] ?? [];
                                $flags = [];
                                if ($privacy['vpn'] ?? false) $flags[] = 'VPN';
                                if ($privacy['proxy'] ?? false) $flags[] = 'Proxy';
                                if ($privacy['tor'] ?? false) $flags[] = 'Tor';
                                if ($privacy['hosting'] ?? false) $flags[] = 'Hosting';
                                ?>
                                <?php if (!empty($flags)): ?>
                                    <span class="badge bg-warning bg-opacity-25 text-warning"><?= e(implode(', ', $flags)) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-success bg-opacity-25 text-success">Clean</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Not configured</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($ipinfo !== null): ?>
                            <small class="text-secondary">Org: <?= e((string) ($ipinfo['org'] ?? '-')) ?> | <?= e((string) ($ipinfo['city'] ?? '')) ?>, <?= e((string) ($ipinfo['country'] ?? '-')) ?></small>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </section>

    <!-- Geolocation -->
    <section class="row g-3 mb-4" data-ui-section>
        <div class="col-12 col-lg-6">
            <div class="card card-neon">
                <div class="card-header bg-surface-2 border-soft">
                    <h2 class="h6 mb-0"><i class="ti ti-map-pin me-2"></i>Geolocation</h2>
                </div>
                <div class="card-body">
                    <?php if ($geo === null): ?>
                        <div class="text-secondary">Not available</div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-1">
                            <div><strong>Hostname:</strong> <?= e((string) ($geo['hostname'] ?? '-')) ?></div>
                            <div><strong>Location:</strong> <?= e(!empty($geoLocationParts) ? implode(', ', $geoLocationParts) : '-') ?></div>
                            <div><strong>Org:</strong> <?= e((string) ($geo['org'] ?? '-')) ?></div>
                            <div><strong>ASN:</strong> <?= e((string) ($geo['asn'] ?? '-')) ?></div>
                            <?php if (!empty($geoFlags)): ?>
                                <div class="mt-2 d-flex flex-wrap gap-1">
                                    <?php foreach ($geoFlags as $flag): ?>
                                        <span class="badge bg-warning bg-opacity-25 text-warning"><?= e($flag) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Timeline Chart -->
    <section class="card card-neon mb-4" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0"><i class="ti ti-chart-line me-2"></i>Listing History</h2>
        </div>
        <div class="card-body">
            <div id="ip-rep-history-chart" class="chart-container-md"></div>
        </div>
    </section>

    <!-- Check History Table -->
    <section class="card card-neon" data-ui-section>
        <div class="card-header bg-surface-2 border-soft">
            <h2 class="h6 mb-0"><i class="ti ti-history me-2"></i>Check History</h2>
        </div>
        <div class="table-responsive table-shell" data-ui-table>
            <table class="table monitors-table mb-0">
                <thead>
                <tr>
                    <th>Checked At</th>
                    <th>Status</th>
                    <th>Blacklists</th>
                    <th>Duration</th>
                    <th>Details</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($checks)): ?>
                    <tr><td colspan="5" class="table-empty">No check history yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($checks as $chk): ?>
                    <?php $chkStatus = (string) ($chk['overall_status'] ?? 'unknown'); ?>
                    <tr>
                        <td><small><?= e((string) ($chk['checked_at'] ?? '-')) ?></small></td>
                        <td><span class="badge <?= e(ip_rep_status_badge_class($chkStatus)) ?> text-uppercase"><?= e($chkStatus) ?></span></td>
                        <td>
                            <span class="font-mono"><?= e((string) ($chk['listed_count'] ?? 0)) ?>/<?= e((string) ($chk['total_checked'] ?? 0)) ?></span>
                            <?php if (!empty($chk['listed_on_arr'])): ?>
                                <small class="text-danger ms-1"><?= e(implode(', ', $chk['listed_on_arr'])) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><span class="font-mono"><?= e((string) ($chk['check_duration_ms'] ?? '-')) ?> ms</span></td>
                        <td>
                            <?php $pr = $chk['provider_results_arr'] ?? []; ?>
                            <?php if (isset($pr['abuseipdb'])): ?>
                                <span class="badge bg-secondary bg-opacity-25 text-secondary me-1">AIPDB:<?= e((string) ($pr['abuseipdb']['score'] ?? '?')) ?>%</span>
                            <?php endif; ?>
                            <?php if (isset($pr['virustotal'])): ?>
                                <span class="badge bg-secondary bg-opacity-25 text-secondary me-1">VT:<?= e((string) ($pr['virustotal']['malicious'] ?? '?')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script<?= csp_nonce_attr() ?>>
window.MONITORS_IP_REP_API = <?= json_encode(app_url('api/ip-reputation'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MONITORS_CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MONITORS_IP_REP_CHART_DATA = <?= json_encode(array_map(static fn(array $c) => [
    'time'    => $c['checked_at'],
    'listed'  => (int) ($c['listed_count'] ?? 0),
    'total'   => (int) ($c['total_checked'] ?? 0),
    'status'  => (string) ($c['overall_status'] ?? 'unknown'),
], array_reverse($checks)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= e(asset_url('assets/js/ip-reputation-detail.js')) ?>"></script>
