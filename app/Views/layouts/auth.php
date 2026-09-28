<?php
$successMessage = flash('success');
$errorMessage = flash('error');
$logoExists = is_file(public_path('assets/images/rankers-mind-logo.png'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="#101936">
    <title><?= e($title ?? 'Sign in') ?> · Rankers Mind CRM</title>
    <link rel="icon" href="<?= asset('images/favicon.svg') ?>?v=1.0.0" type="image/svg+xml">
    <link rel="manifest" href="<?= url('site.webmanifest') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>?v=5.3.8" rel="stylesheet">
    <link href="<?= asset('css/app.css') ?>?v=1.2.1" rel="stylesheet">
    <link href="<?= asset('css/enterprise.css') ?>?v=1.0.0" rel="stylesheet">
</head>
<body class="auth-body">
<div class="splash" id="splash">
    <div class="splash-brand">
        <?php if ($logoExists): ?>
            <span class="rm-logo splash-logo" role="img" aria-label="Rankers Mind"></span>
        <?php else: ?>
            <span class="splash-mark">RM</span><strong>Rankers Mind</strong>
        <?php endif; ?>
    </div>
    <div class="splash-line"><span></span></div>
    <small>Preparing your workspace</small>
</div>

<div class="auth-shell">
    <section class="auth-brand-panel">
        <div class="auth-brand">
            <?php if ($logoExists): ?>
                <span class="rm-logo auth-logo" role="img" aria-label="Rankers Mind"></span>
            <?php else: ?>
                <span class="brand-mark">RM</span>
                <span><strong>Rankers Mind</strong><small>CRM Workspace</small></span>
            <?php endif; ?>
        </div>
        <div class="auth-message">
            <span class="eyebrow">One connected workspace</span>
            <h1>Bring sales, delivery and people operations into focus.</h1>
            <p>Securely manage every customer journey—from the first follow-up to project delivery, billing and payroll.</p>
            <div class="auth-features">
                <span><i class="fa-solid fa-circle-check"></i> Live operational dashboards</span>
                <span><i class="fa-solid fa-circle-check"></i> Role-based access controls</span>
                <span><i class="fa-solid fa-circle-check"></i> Attendance and payroll automation</span>
            </div>
        </div>
        <div class="auth-panel-foot">© <?= date('Y') ?> Rankers Mind. Internal business system.</div>
    </section>
    <main class="auth-content">
        <div class="auth-content-inner">
            <?php if ($successMessage): ?>
                <div class="inline-alert success"><i class="fa-solid fa-circle-check"></i><?= e($successMessage) ?></div>
            <?php endif; ?>
            <?php if ($errorMessage): ?>
                <div class="inline-alert error"><i class="fa-solid fa-circle-exclamation"></i><?= e($errorMessage) ?></div>
            <?php endif; ?>
            <?= $content ?>
        </div>
    </main>
</div>

<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>?v=5.3.8"></script>
<script>
if (sessionStorage.getItem('rmSplashSeen')) {
    document.getElementById('splash')?.remove();
} else {
    sessionStorage.setItem('rmSplashSeen', '1');
    setTimeout(() => document.getElementById('splash')?.classList.add('hide'), 1700);
    setTimeout(() => document.getElementById('splash')?.remove(), 2100);
}
document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.passwordToggle);
    input.type = input.type === 'password' ? 'text' : 'password';
    button.querySelector('i').classList.toggle('fa-eye-slash');
}));
</script>
</body>
</html>
