<main id="main-content" class="auth-shell auth-login-page monitors-auth-root">
    <!-- Architectural Wireframe Geometric Curves -->
    <div class="monitors-auth-arc monitors-auth-arc-top-right" aria-hidden="true"></div>
    <div class="monitors-auth-arc monitors-auth-arc-bottom-left" aria-hidden="true"></div>

    <!-- Vertical Side Margins (Rotated Editorial Text) -->
    <aside class="monitors-auth-edge monitors-auth-edge-left" aria-hidden="true">
        <span><?= e(strtoupper(APP_NAME)) ?> &middot; MONITORS YOUR SERVER</span>
    </aside>
    <aside class="monitors-auth-edge monitors-auth-edge-right" aria-hidden="true">
        <span>EST. 2026 &middot; NOCTURNALISMEE</span>
    </aside>

    <!-- Top Fixed Bar -->
    <header class="monitors-auth-topbar">
        <a class="monitors-auth-brand" href="<?= e(app_url('/')) ?>" aria-label="<?= e(APP_NAME) ?> home">
            <span class="monitors-auth-brand-symbol" aria-hidden="true">
                <?php if ($brandingLogoUrl !== ''): ?>
                    <img src="<?= e($brandingLogoUrl) ?>" alt="">
                <?php else: ?>
                    <i class="ti ti-activity-heartbeat"></i>
                <?php endif; ?>
            </span>
            <span class="monitors-auth-brand-name"><?= e(APP_NAME) ?></span>
        </a>

        <div class="monitors-auth-top-meta">
            <span class="monitors-auth-status-beacon" title="Cluster state is nominal">
                <span class="monitors-auth-beacon-dot"></span> ALL SYSTEMS NORMAL
            </span>
            <button type="button" class="monitors-auth-theme-toggle" data-theme-toggle aria-label="Toggle color theme" title="Toggle theme">
                <i class="ti ti-sun" data-theme-toggle-icon aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <!-- Centered Editorial Login Form with Side Showcase -->
    <div class="monitors-auth-container">
        <!-- Left Side Showcase: Large Framed Art -->
        <div class="monitors-auth-showcase" aria-hidden="true">
            <div class="monitors-auth-portrait-frame">
                <span class="monitors-auth-corner corner-tl"></span>
                <span class="monitors-auth-corner corner-tr"></span>
                <span class="monitors-auth-corner corner-bl"></span>
                <span class="monitors-auth-corner corner-br"></span>
                <img src="<?= e(asset_url('assets/img/nocturnalismee.png')) ?>" alt="Nocturnalismee" class="monitors-auth-portrait-img" loading="eager">
            </div>
        </div>

        <div class="monitors-auth-card">
            <div class="monitors-auth-kicker-line">
                <span class="monitors-auth-kicker-dash">&mdash;</span>
                <span class="monitors-auth-kicker-text">IDENTITY &middot; LOGIN</span>
            </div>

            <h1 id="login-title" class="monitors-auth-title">Sign in</h1>
            <?php require __DIR__ . '/../layouts/flash.php'; ?>

            <form method="post" novalidate class="monitors-auth-form" action="<?= e(app_url('login')) ?>">
                <?= csrf_input() ?>

                <div class="monitors-auth-field">
                    <label class="monitors-auth-label" for="login-username">USERNAME</label>
                    <div class="monitors-auth-input-wrap">
                        <input id="login-username" class="monitors-auth-input" type="text" name="username" required value="<?= e(old('username')) ?>" placeholder="username" autocomplete="username" autofocus>
                        <span class="monitors-auth-badge-indicator" aria-hidden="true">
                            <span class="monitors-auth-dot"></span><span class="monitors-auth-dot"></span><span class="monitors-auth-dot"></span>
                        </span>
                    </div>
                </div>

                <div class="monitors-auth-field">
                    <label class="monitors-auth-label" for="login-password">PASSWORD</label>
                    <div class="monitors-auth-input-wrap">
                        <input id="login-password" class="monitors-auth-input" type="password" name="password" required placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" autocomplete="current-password">
                        <div class="monitors-auth-password-trailing">
                            <span class="monitors-auth-badge-indicator" aria-hidden="true">
                                <span class="monitors-auth-dot"></span><span class="monitors-auth-dot"></span><span class="monitors-auth-dot"></span>
                            </span>
                            <button class="monitors-auth-eye-btn" type="button" data-password-toggle="login-password" aria-label="Show password" title="Show password">
                                <span class="monitors-auth-eye-text">VIEW</span>
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($turnstileEnabled && turnstile_site_key() !== ''): ?>
                    <div class="cf-turnstile monitors-auth-turnstile" data-sitekey="<?= e(turnstile_site_key()) ?>"></div>
                <?php endif; ?>

                <button class="monitors-auth-submit-btn" type="submit" data-submit-loading data-loading-text="Signing in...">
                    LOGIN
                </button>
            </form>

            <div class="monitors-auth-footer-meta">
                <svg class="monitors-auth-barcode" width="112" height="20" viewBox="0 0 120 24" fill="currentColor" aria-hidden="true">
                    <rect x="0" y="4" width="2" height="16"/>
                    <rect x="4" y="0" width="1" height="24"/>
                    <rect x="7" y="6" width="3" height="14"/>
                    <rect x="12" y="2" width="1" height="20"/>
                    <rect x="15" y="0" width="2" height="24"/>
                    <rect x="19" y="5" width="1" height="15"/>
                    <rect x="22" y="1" width="4" height="22"/>
                    <rect x="28" y="7" width="1" height="13"/>
                    <rect x="31" y="0" width="2" height="24"/>
                    <rect x="35" y="4" width="1" height="17"/>
                    <rect x="38" y="2" width="3" height="20"/>
                    <rect x="43" y="6" width="1" height="14"/>
                    <rect x="46" y="0" width="2" height="24"/>
                    <rect x="50" y="3" width="1" height="18"/>
                    <rect x="53" y="1" width="4" height="22"/>
                    <rect x="59" y="7" width="1" height="13"/>
                    <rect x="62" y="0" width="2" height="24"/>
                    <rect x="66" y="4" width="2" height="16"/>
                    <rect x="70" y="2" width="1" height="20"/>
                    <rect x="73" y="5" width="3" height="15"/>
                    <rect x="78" y="0" width="1" height="24"/>
                    <rect x="81" y="3" width="2" height="18"/>
                    <rect x="85" y="6" width="1" height="14"/>
                    <rect x="88" y="1" width="3" height="22"/>
                    <rect x="93" y="0" width="1" height="24"/>
                    <rect x="96" y="4" width="4" height="16"/>
                    <rect x="102" y="2" width="1" height="20"/>
                    <rect x="105" y="0" width="2" height="24"/>
                    <rect x="109" y="5" width="1" height="15"/>
                    <rect x="112" y="1" width="3" height="22"/>
                    <rect x="117" y="0" width="2" height="24"/>
                </svg>
                <div class="monitors-auth-security-text">
                    <span class="monitors-auth-sec-title">ENCRYPTED</span>
                    <span class="monitors-auth-sec-code">TLS 1.3 &middot; SOC 2 TYPE II</span>
                </div>
            </div>
        </div>
    </div>
</main>
<?php if ($turnstileEnabled && turnstile_site_key() !== ''): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
<script<?= csp_nonce_attr() ?>>
document.addEventListener('DOMContentLoaded', function() {
    var eyeBtn = document.querySelector('.monitors-auth-eye-btn');
    if (!eyeBtn) return;
    eyeBtn.addEventListener('click', function() {
        var inp = document.getElementById(eyeBtn.getAttribute('data-password-toggle'));
        var txt = eyeBtn.querySelector('.monitors-auth-eye-text');
        if (inp && txt) {
            setTimeout(function() {
                txt.textContent = inp.type === 'text' ? 'HIDE' : 'VIEW';
            }, 10);
        }
    });
});
</script>
