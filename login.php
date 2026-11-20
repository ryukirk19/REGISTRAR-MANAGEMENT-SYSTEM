<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $error = attempt_login($username, $password);
        if ($error === '') {
            redirect('dashboard.php');
        }
    }
}

open_page('Sign in · ' . APP_NAME, 'auth');
?>
<div class="auth-shell">
    <aside class="auth-visual">
        <a class="auth-brand" href="/index.php">
            <?= logo_mark() ?>
            <span>
                <span class="auth-brand-name">REGIS<span>+</span></span>
                <span class="auth-brand-sub">Registrar Records System</span>
            </span>
        </a>
        <div class="auth-quote">
            <h2>Permanent records deserve a <em>permanent home.</em></h2>
            <p>One index for student records, one checklist for credentials, one log for every request — so no document is ever searched for twice.</p>
        </div>
        <div class="auth-features">
            <span><?= icon('lock') ?> Authorized access</span>
            <span><?= icon('audit') ?> Audited actions</span>
            <span><?= icon('shield') ?> Data minimization</span>
        </div>
    </aside>

    <main class="auth-panel">
        <div class="auth-card">
            <div class="eyebrow"><?= e(setting('school_name', 'Registrar Office')) ?></div>
            <h1>Sign in to the registrar workspace</h1>
            <p class="lede">Use your assigned registrar account. All activity is logged.</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= icon('alert') ?><span><?= e($error) ?></span></div>
            <?php endif; ?>
            <?php foreach (take_flashes() as $f): ?>
                <div class="alert alert-<?= $f['type'] === 'error' ? 'error' : 'info' ?>"><?= icon('check') ?><span><?= e($f['message']) ?></span></div>
            <?php endforeach; ?>

            <form method="post" action="/login.php" novalidate>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" placeholder="e.g. etalha" required autofocus>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                </div>
                <button class="btn btn-primary" type="submit"><?= icon('logout') ?> Sign in</button>
            </form>

            <div class="demo-box">
                <strong>Demo accounts</strong>
                <table>
                    <tr><td>System administrator</td><td>mcamero / regis2026</td></tr>
                    <tr><td>Registrar officer</td><td>etalha / regis2026</td></tr>
                    <tr><td>Registrar staff</td><td>xcordero / regis2026</td></tr>
                </table>
            </div>

            <a class="auth-back" href="/index.php"><?= icon('arrow-right') ?> Back to the REGIS+ overview</a>
        </div>
    </main>
</div>
<?php
close_page();
