<main id="main-content" class="auth-shell auth-login-page">
    <button type="button" class="auth-theme-toggle" data-theme-toggle aria-label="Toggle color theme" title="Toggle theme">
        <i class="ti ti-sun" data-theme-toggle-icon aria-hidden="true"></i>
    </button>
    <div class="auth-layout">
        <section class="auth-panel card card-neon" aria-labelledby="login-title">
            <div class="auth-panel-body">
                <div class="auth-brand-badge" aria-hidden="true">
                    <?php if ($brandingLogoUrl !== ''): ?>
                        <img class="login-brand-logo" src="<?= e($brandingLogoUrl) ?>" alt="">
                    <?php else: ?>
                        <i class="ti ti-activity-heartbeat"></i>
                    <?php endif; ?>
                </div>
                <div class="auth-panel-heading">
                    <p class="auth-kicker">ADMIN ACCESS</p>
                    <h2 id="login-title" class="auth-title">Welcome back</h2>
                    <p class="auth-subtitle">Sign in to continue to your monitoring workspace.</p>
                </div>

                <?php require __DIR__ . '/../layouts/flash.php'; ?>

                <form method="post" novalidate class="auth-form" action="<?= e(app_url('login')) ?>">
                    <?= csrf_input() ?>
                    <div class="mb-3">
                        <label class="form-label" for="login-username">Username</label>
                        <div class="input-group">
                            <span class="input-group-text auth-input-icon"><i class="ti ti-user" aria-hidden="true"></i></span>
                            <input id="login-username" class="form-control auth-input" type="text" name="username" required value="<?= e(old('username')) ?>" placeholder="Enter your username" autocomplete="username">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="login-password">Password</label>
                        <div class="input-group">
                            <span class="input-group-text auth-input-icon"><i class="ti ti-lock" aria-hidden="true"></i></span>
                            <input id="login-password" class="form-control auth-input" type="password" name="password" required placeholder="Enter your password" autocomplete="current-password">
                            <button class="btn auth-password-toggle" type="button" data-password-toggle="login-password" aria-label="Show password" title="Show password"><i class="ti ti-eye" aria-hidden="true"></i></button>
                        </div>
                    </div>
                    <?php if ($turnstileEnabled && turnstile_site_key() !== ''): ?>
                        <div class="cf-turnstile mb-3" data-sitekey="<?= e(turnstile_site_key()) ?>"></div>
                    <?php endif; ?>
                    <button class="btn w-100 fw-semibold auth-submit-btn" type="submit" data-submit-loading data-loading-text="Signing in...">
                        <i class="ti ti-login-2 me-2" aria-hidden="true"></i>Sign in
                    </button>
                </form>

                <p class="auth-note"><i class="ti ti-lock me-1" aria-hidden="true"></i>Protected by rate limiting</p>
            </div>
        </section>
    </div>
</main>
<script<?= csp_nonce_attr() ?>>
document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
        var input = document.getElementById(button.getAttribute('data-password-toggle'));
        if (!input) return;
        var isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        button.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
        var icon = button.querySelector('i');
        if (icon) icon.className = isPassword ? 'ti ti-eye-off' : 'ti ti-eye';
    });
});
</script>
<?php if ($turnstileEnabled && turnstile_site_key() !== ''): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
