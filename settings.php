<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$pdo = db();
$user = current_user();
$errors = [];
$pwErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_school') {
        require_admin();
        $fields = ['school_name', 'school_address', 'school_year', 'registrar_name', 'registrar_title', 'principal_name', 'principal_title', 'backup_note'];
        foreach ($fields as $f) {
            $value = trim((string) ($_POST[$f] ?? ''));
            if ($f === 'school_name' && $value === '') {
                $errors['school_name'] = 'The school name cannot be empty.';
                continue;
            }
            set_setting($f, $value);
        }
        if (!$errors) {
            log_activity('School settings updated', 'Institutional details updated by ' . $user['full_name']);
            flash('success', 'School details saved. Letterheads and page headers now use these values.');
            redirect('settings.php');
        }
    }

    if ($action === 'save_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (!password_verify($current, $user['password_hash'])) {
            $pwErrors['current_password'] = 'Your current password is incorrect.';
        }
        if (strlen($new) < 8) {
            $pwErrors['new_password'] = 'New password must be at least 8 characters.';
        }
        if ($new !== $confirm) {
            $pwErrors['confirm_password'] = 'Passwords do not match.';
        }
        if (!$pwErrors) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            log_activity('Password changed', $user['full_name'] . ' changed their password');
            flash('success', 'Your password was updated.');
            redirect('settings.php');
        }
    }
}

$settings = [
    'school_name' => setting('school_name'),
    'school_address' => setting('school_address'),
    'school_year' => setting('school_year'),
    'registrar_name' => setting('registrar_name'),
    'registrar_title' => setting('registrar_title'),
    'principal_name' => setting('principal_name'),
    'principal_title' => setting('principal_title'),
    'backup_note' => setting('backup_note'),
];
$dbSize = file_exists(DB_FILE) ? (int) filesize(DB_FILE) : 0;
$dbSizeText = $dbSize > 1048576 ? number_format($dbSize / 1048576, 1) . ' MB' : number_format(max(1, $dbSize) / 1024, 1) . ' KB';
$counts = [
    'students' => (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),
    'credentials' => (int) $pdo->query('SELECT COUNT(*) FROM credentials')->fetchColumn(),
    'requests' => (int) $pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn(),
    'followups' => (int) $pdo->query('SELECT COUNT(*) FROM followups')->fetchColumn(),
    'certifications' => (int) $pdo->query('SELECT COUNT(*) FROM certifications')->fetchColumn(),
];
$lastBackupAt = setting('last_backup_at', '');
$lastBackupFile = setting('last_backup_file', '');
$lastBackupText = $lastBackupAt !== ''
    ? fmt_datetime($lastBackupAt) . ($lastBackupFile !== '' ? ' · ' . $lastBackupFile : '')
    : 'No backup recorded yet';

app_header('Settings', 'settings.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Configuration &amp; access</div>
        <h1>Settings</h1>
        <p class="page-sub">Institutional details, your account, and the state of the prototype database.</p>
    </div>
</div>

<div class="grid split-2">
    <div style="display:flex; flex-direction:column; gap:18px; min-width:0">
        <section class="card">
            <div class="card-head bordered">
                <div>
                    <h2 class="section-title">School identity</h2>
                    <p class="section-sub">Used on certificates, headers, and reports</p>
                </div>
                <?php if (!is_admin()): ?><span class="pill pill-muted"><span class="pill-dot"></span>Admin only</span><?php endif; ?>
            </div>
            <form method="post" action="/settings.php" class="card-body" style="padding-top:18px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_school">
                <div class="form-grid">
                    <div class="field span-2 <?= isset($errors['school_name']) ? 'has-error' : '' ?>">
                        <label for="school_name">School name</label>
                        <input type="text" id="school_name" name="school_name" value="<?= e($settings['school_name']) ?>" <?= is_admin() ? '' : 'readonly' ?>>
                        <?php if (isset($errors['school_name'])): ?><span class="error"><?= e($errors['school_name']) ?></span><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="school_address">Address</label>
                        <input type="text" id="school_address" name="school_address" value="<?= e($settings['school_address']) ?>" <?= is_admin() ? '' : 'readonly' ?>>
                    </div>
                    <div class="field">
                        <label for="school_year">School year</label>
                        <input type="text" id="school_year" name="school_year" value="<?= e($settings['school_year']) ?>" <?= is_admin() ? '' : 'readonly' ?>>
                    </div>
                    <div class="field">
                        <label for="registrar_name">Registrar (signatory)</label>
                        <input type="text" id="registrar_name" name="registrar_name" value="<?= e($settings['registrar_name']) ?>" <?= is_admin() ? '' : 'readonly' ?>>
                    </div>
                    <div class="field">
                        <label for="registrar_title">Registrar title</label>
                        <input type="text" id="registrar_title" name="registrar_title" value="<?= e($settings['registrar_title']) ?>" <?= is_admin() ? '' : 'readonly' ?>>
                    </div>
                    <div class="field">
                        <label for="principal_name">Principal (signatory)</label>
                        <input type="text" id="principal_name" name="principal_name" value="<?= e($settings['principal_name']) ?>" <?= is_admin() ? '' : 'readonly' ?>>
                    </div>
                    <div class="field">
                        <label for="principal_title">Principal title</label>
                        <input type="text" id="principal_title" name="principal_title" value="<?= e($settings['principal_title']) ?>" <?= is_admin() ? '' : 'readonly' ?>>
                    </div>
                    <div class="field span-2">
                        <label for="backup_note">Backup schedule note</label>
                        <input type="text" id="backup_note" name="backup_note" value="<?= e($settings['backup_note']) ?>" <?= is_admin() ? '' : 'readonly' ?>>
                    </div>
                </div>
                <?php if (is_admin()): ?>
                    <div class="form-actions" style="margin-top:16px">
                        <button class="btn btn-primary" type="submit"><?= icon('check') ?> Save school details</button>
                    </div>
                <?php endif; ?>
            </form>
        </section>

        <section class="card">
            <div class="card-head bordered">
                <div>
                    <h2 class="section-title">Change password</h2>
                    <p class="section-sub">Signed in as <?= e($user['username']) ?> · <?= e(role_label($user['role'])) ?></p>
                </div>
            </div>
            <form method="post" action="/settings.php" class="card-body" style="padding-top:18px" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_password">
                <div class="form-grid">
                    <div class="field span-2 <?= isset($pwErrors['current_password']) ? 'has-error' : '' ?>">
                        <label for="current_password">Current password</label>
                        <input type="password" id="current_password" name="current_password" autocomplete="current-password">
                        <?php if (isset($pwErrors['current_password'])): ?><span class="error"><?= e($pwErrors['current_password']) ?></span><?php endif; ?>
                    </div>
                    <div class="field <?= isset($pwErrors['new_password']) ? 'has-error' : '' ?>">
                        <label for="new_password">New password</label>
                        <input type="password" id="new_password" name="new_password" autocomplete="new-password">
                        <?php if (isset($pwErrors['new_password'])): ?><span class="error"><?= e($pwErrors['new_password']) ?></span><?php endif; ?>
                    </div>
                    <div class="field <?= isset($pwErrors['confirm_password']) ? 'has-error' : '' ?>">
                        <label for="confirm_password">Confirm new password</label>
                        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">
                        <?php if (isset($pwErrors['confirm_password'])): ?><span class="error"><?= e($pwErrors['confirm_password']) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="form-actions" style="margin-top:16px">
                    <button class="btn btn-primary" type="submit"><?= icon('lock') ?> Update password</button>
                </div>
            </form>
        </section>
    </div>

    <div style="display:flex; flex-direction:column; gap:18px; min-width:0">
        <section class="card card-pad">
            <h2 class="section-title" style="margin-bottom:14px">My account</h2>
            <dl class="detail-grid" style="grid-template-columns:minmax(0,1fr)">
                <div class="detail"><dt>Full name</dt><dd><?= e(account_name($user)) ?></dd></div>
                <div class="detail"><dt>Username</dt><dd class="mono"><?= e($user['username']) ?></dd></div>
                <div class="detail"><dt>Role</dt><dd><?= e(role_label($user['role'])) ?></dd></div>
                <div class="detail"><dt>Email</dt><dd><?= e($user['email'] ?: 'Not set') ?></dd></div>
                <div class="detail"><dt>Last sign-in</dt><dd><?= e(fmt_datetime($user['last_login'])) ?></dd></div>
            </dl>
        </section>

        <section class="card card-pad">
            <h2 class="section-title" style="margin-bottom:14px">Prototype status</h2>
            <div class="mini-row" style="padding:8px 0; border-top:0"><div class="left"><strong>Application</strong><span>REGIS+ v<?= e(APP_VERSION) ?></span></div></div>
            <div class="mini-row" style="padding:8px 0"><div class="left"><strong>Server</strong><span>PHP <?= e(PHP_VERSION) ?> · SQLite <?= e($pdo->query('SELECT sqlite_version()')->fetchColumn()) ?></span></div></div>
            <div class="mini-row" style="padding:8px 0"><div class="left"><strong>Database</strong><span><?= e($dbSizeText) ?> · <?= $counts['students'] ?> students · <?= $counts['credentials'] ?> checklist entries</span></div></div>
            <div class="mini-row" style="padding:8px 0"><div class="left"><strong>Last backup</strong><span><?= e($lastBackupText) ?></span></div></div>
            <div class="mini-row" style="padding:8px 0"><div class="left"><strong>Activity</strong><span><?= $counts['requests'] ?> requests · <?= $counts['followups'] ?> follow-ups · <?= $counts['certifications'] ?> certificates</span></div></div>
            <?php if (is_admin()): ?>
                <div style="margin-top:14px; display:flex; gap:8px; flex-wrap:wrap">
                    <a class="btn btn-ghost btn-sm" href="/audit.php"><?= icon('audit') ?> Audit trail</a>
                    <a class="btn btn-ghost btn-sm" href="/backup.php" data-confirm="Download a copy of the database? Store it only on an authorized device."><?= icon('download') ?> Download backup</a>
                </div>
                <p class="sub" style="margin:10px 0 0">Scheduled runs use <span class="mono">tools\backup.cmd</span> and keep the last eight copies under <span class="mono">database\backups</span>.</p>
            <?php endif; ?>
        </section>

        <section class="card card-pad">
            <h2 class="section-title" style="margin-bottom:8px">Data privacy reminder</h2>
            <p class="section-sub" style="line-height:1.65; margin-bottom:12px">
                This database contains confidential student records. Keep the workstation locked, use only your own account,
                and store any backup or export in a secure location approved by the school.
            </p>
            <div class="field-set" style="grid-column:auto">
                <?= icon('shield') ?><span><?= e($settings['backup_note'] ?: 'Set a backup schedule for this workstation.') ?></span>
            </div>
        </section>
    </div>
</div>
<?php
app_footer();
