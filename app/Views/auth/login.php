<main id="main-content" class="auth-shell auth-login-page login-split-root">
    <div class="login-split">
        <section class="login-split-form" aria-labelledby="login-title">
            <header class="login-split-topbar">
                <a class="login-split-brand" href="<?= e(app_url('/')) ?>" aria-label="<?= e(APP_NAME) ?> home">
                    <span class="login-split-brand-mark" aria-hidden="true">
                        <?php if ($brandingLogoUrl !== ''): ?>
                            <img src="<?= e($brandingLogoUrl) ?>" alt="">
                        <?php else: ?>
                            <i class="ti ti-activity-heartbeat"></i>
                        <?php endif; ?>
                    </span>
                    <span class="login-split-brand-name"><?= e(APP_NAME) ?></span>
                </a>
                <button type="button" class="auth-theme-toggle login-split-theme-toggle" data-theme-toggle aria-label="Toggle color theme" title="Toggle theme">
                    <i class="ti ti-sun" data-theme-toggle-icon aria-hidden="true"></i>
                </button>
            </header>

            <div class="login-split-form-inner">
                <p class="auth-kicker login-split-kicker">Welcome back</p>
                <h1 id="login-title" class="login-split-title">Sign in to your workspace</h1>
                <p class="login-split-subtitle">Pick up where you left off &mdash; servers, uptime, and alerts waiting.</p>

                <?php require __DIR__ . '/../layouts/flash.php'; ?>

                <form method="post" novalidate class="auth-form login-split-form-fields" action="<?= e(app_url('login')) ?>">
                    <?= csrf_input() ?>
                    <div class="login-split-field">
                        <label class="form-label" for="login-username">Username</label>
                        <div class="login-split-input-wrap">
                            <input id="login-username" class="form-control login-split-input" type="text" name="username" required value="<?= e(old('username')) ?>" placeholder="Enter your username" autocomplete="username" autofocus>
                            <span class="login-split-input-trailing" aria-hidden="true"><i class="ti ti-user"></i></span>
                        </div>
                    </div>
                    <div class="login-split-field">
                        <label class="form-label" for="login-password">Password</label>
                        <div class="login-split-input-wrap">
                            <input id="login-password" class="form-control login-split-input has-toggle" type="password" name="password" required placeholder="Enter your password" autocomplete="current-password">
                            <button class="login-split-eye" type="button" data-password-toggle="login-password" aria-label="Show password" title="Show password"><i class="ti ti-eye" aria-hidden="true"></i></button>
                        </div>
                    </div>
                    <?php if ($turnstileEnabled && turnstile_site_key() !== ''): ?>
                        <div class="cf-turnstile login-split-turnstile" data-sitekey="<?= e(turnstile_site_key()) ?>"></div>
                    <?php endif; ?>
                    <button class="btn w-100 fw-semibold auth-submit-btn login-split-submit" type="submit" data-submit-loading data-loading-text="Signing in...">
                        <i class="ti ti-login-2 me-2" aria-hidden="true"></i>Sign in
                    </button>
                </form>

                <p class="auth-note login-split-note"><i class="ti ti-lock me-1" aria-hidden="true"></i>Protected by rate limiting</p>
            </div>
        </section>

        <aside class="login-split-showcase" aria-label="About <?= e(APP_NAME) ?>">
            <div class="login-split-showcase-inner">
                <div class="login-split-hero-mark" aria-hidden="true">
                    <?php if ($brandingLogoUrl !== ''): ?>
                        <img src="<?= e($brandingLogoUrl) ?>" alt="">
                    <?php else: ?>
                        <i class="ti ti-activity-heartbeat"></i>
                    <?php endif; ?>
                </div>

                <p class="login-split-badge"><span class="login-split-badge-dot" aria-hidden="true"></span><?= e(strtoupper(APP_NAME)) ?> &middot; SERVER MONITORING FOR TEAMS</p>

                <h2 class="login-split-headline">Server monitoring for modern teams.</h2>
                <p class="login-split-desc">Connect agents, track uptime and disk health, and get alerted &mdash; over a clean dashboard built for operators.</p>

                <ul class="login-split-points">
                    <li><i class="ti ti-activity" aria-hidden="true"></i><span>Live status, ping &amp; resource metrics</span></li>
                    <li><i class="ti ti-bell-ringing" aria-hidden="true"></i><span>Instant alerts via Telegram &amp; webhook</span></li>
                    <li><i class="ti ti-shield-check" aria-hidden="true"></i><span>Hardened admin access with rate limiting</span></li>
                </ul>
            </div>
        </aside>
    </div>
</main>
<?php if ($turnstileEnabled && turnstile_site_key() !== ''): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
