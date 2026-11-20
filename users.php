<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

$pdo = db();
$roles = ['admin' => 'System Administrator', 'registrar' => 'Registrar Officer', 'staff' => 'Registrar Staff'];
$errors = [];
$editErrors = [];
$openCreate = isset($_GET['new']);
$editId = (int) ($_GET['edit'] ?? 0);
$old = ['username' => '', 'full_name' => '', 'role' => 'staff', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        foreach ($old as $k => $v) {
            $old[$k] = trim((string) ($_POST[$k] ?? ''));
        }
        $password = (string) ($_POST['password'] ?? '');
        $openCreate = true;

        if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $old['username'])) {
            $errors['username'] = 'Use 3–32 letters, numbers, dots, dashes, or underscores.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE lower(username) = lower(?)');
            $stmt->execute([$old['username']]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors['username'] = 'That username is already taken.';
            }
        }
        if ($old['full_name'] === '') {
            $errors['full_name'] = 'Full name is required.';
        }
        if (!isset($roles[$old['role']])) {
            $errors['role'] = 'Choose a valid role.';
        }
        if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, email) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$old['username'], password_hash($password, PASSWORD_DEFAULT), $old['full_name'], $old['role'], $old['email']]);
            log_activity('User account created', $old['username'] . ' · ' . $roles[$old['role']]);
            flash('success', 'Account for ' . $old['full_name'] . ' created.');
            redirect('users.php');
        }
    }

    if ($action === 'update') {
        $userId = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target) {
            flash('error', 'User not found.');
            redirect('users.php');
        }
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = (string) ($_POST['role'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $newPassword = (string) ($_POST['password'] ?? '');

        if ($fullName === '') {
            $editErrors['full_name'] = 'Full name is required.';
        }
        if (!isset($roles[$role])) {
            $editErrors['role'] = 'Choose a valid role.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $editErrors['email'] = 'Enter a valid email address.';
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
            $editErrors['password'] = 'Password must be at least 8 characters.';
        }
        if ($userId === (int) current_user()['id'] && (!$isActive || $role !== 'admin')) {
            $editErrors['role'] = 'You cannot deactivate or demote your own administrator account.';
        }
        if (!$editErrors && $target['role'] === 'admin' && $role !== 'admin') {
            $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
            if ($admins <= 1) {
                $editErrors['role'] = 'At least one active administrator must remain.';
            }
        }

        if (!$editErrors) {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, is_active = ? WHERE id = ?');
            $stmt->execute([$fullName, $email, $role, $isActive, $userId]);
            if ($newPassword !== '') {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            }
            $pdo->commit();
            log_activity('User account updated', $target['username'] . ($newPassword !== '' ? ' (password reset)' : '') . ' · ' . $roles[$role] . ($isActive ? '' : ' · deactivated'));
            flash('success', 'Account updated.');
            redirect('users.php');
        }
        $editId = $userId;
    }
}

$users = $pdo->query(
    'SELECT u.*, (SELECT COUNT(*) FROM requests r WHERE r.assigned_to = u.id) AS open_requests
     FROM users u ORDER BY u.role, u.full_name'
)->fetchAll();
$editUser = null;
foreach ($users as $u) {
    if ((int) $u['id'] === $editId) {
        $editUser = $u;
    }
}

app_header('User accounts', 'users.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Administration · privacy controls</div>
        <h1>User accounts</h1>
        <p class="page-sub">Only authorized registrar personnel may hold an account. Roles limit what each person can change.</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" type="button" data-dialog-open="#newUser"><?= icon('plus') ?> New account</button>
    </div>
</div>

<div class="grid split-3" style="margin-bottom:18px">
    <?php
    $counts = ['admin' => 0, 'registrar' => 0, 'staff' => 0];
    foreach ($users as $u) {
        $counts[$u['role']] = ($counts[$u['role']] ?? 0) + 1;
    }
    foreach ($roles as $key => $label): ?>
        <div class="kpi">
            <div class="kpi-top"><span class="kpi-label"><?= e($label) ?></span><span class="kpi-icon"><?= icon($key === 'admin' ? 'shield' : ($key === 'registrar' ? 'users' : 'records')) ?></span></div>
            <div class="kpi-value"><?= $counts[$key] ?? 0 ?></div>
            <div class="kpi-foot"><?= $key === 'admin' ? 'Full system control' : ($key === 'registrar' ? 'Can manage records and issue certifications' : 'Can log requests and follow-ups') ?></div>
        </div>
    <?php endforeach; ?>
</div>

<section class="card">
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Person</th><th>Username</th><th>Role</th><th>Assigned requests</th><th>Last sign-in</th><th>Status</th><th class="right"></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div class="name-cell">
                            <span class="avatar-sm accent"><?= e(initials($u['full_name'])) ?></span>
                            <span>
                                <span class="strong"><?= e($u['full_name']) ?></span>
                                <span class="sub"><?= e($u['email'] ?: 'No email on file') ?></span>
                            </span>
                        </div>
                    </td>
                    <td class="mono"><?= e($u['username']) ?></td>
                    <td><span class="pill <?= $u['role'] === 'admin' ? 'pill-brass' : ($u['role'] === 'registrar' ? 'pill-info' : 'pill-muted') ?>"><span class="pill-dot"></span><?= e($roles[$u['role']] ?? $u['role']) ?></span></td>
                    <td class="num"><?= (int) $u['open_requests'] ?></td>
                    <td><?= $u['last_login'] ? e(fmt_datetime($u['last_login'])) : 'Never' ?></td>
                    <td><?= $u['is_active'] ? '<span class="pill pill-ok"><span class="pill-dot"></span>Active</span>' : '<span class="pill pill-danger"><span class="pill-dot"></span>Deactivated</span>' ?></td>
                    <td class="actions">
                        <button class="btn btn-sm btn-ghost" type="button" data-dialog-open="#editUser-<?= (int) $u['id'] ?>"><?= icon('edit') ?> Edit</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<p class="section-sub" style="margin-top:16px; display:flex; gap:8px; align-items:center">
    <?= icon('shield') ?> Accounts are personal. Never share credentials — the audit trail records every sign-in and change.
</p>

<?php foreach ($users as $u):
    $isSelf = (int) $u['id'] === (int) current_user()['id'];
    $vals = ($editId === (int) $u['id'] && $editErrors) ? array_merge($u, $_POST) : $u;
?>
<dialog class="modal modal-sm" id="editUser-<?= (int) $u['id'] ?>" data-autopen="<?= ($editId === (int) $u['id']) ? '1' : '0' ?>">
    <form method="post" action="/users.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        <div class="modal-head">
            <div>
                <h2><?= e($u['full_name']) ?></h2>
                <p class="mono"><?= e($u['username']) ?><?= $isSelf ? ' · your account' : '' ?></p>
            </div>
            <button class="icon-btn modal-close" type="button" data-dialog-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <?php if ($editId === (int) $u['id'] && $editErrors): ?>
                <div class="alert alert-error"><?= icon('alert') ?><span><?= e(reset($editErrors)) ?></span></div>
            <?php endif; ?>
            <div class="form-grid" style="grid-template-columns: minmax(0,1fr)">
                <div class="field">
                    <label for="fn-<?= (int) $u['id'] ?>">Full name</label>
                    <input type="text" id="fn-<?= (int) $u['id'] ?>" name="full_name" value="<?= e((string) $vals['full_name']) ?>">
                </div>
                <div class="field">
                    <label for="em-<?= (int) $u['id'] ?>">Email</label>
                    <input type="email" id="em-<?= (int) $u['id'] ?>" name="email" value="<?= e((string) $vals['email']) ?>">
                </div>
                <div class="field">
                    <label for="ro-<?= (int) $u['id'] ?>">Role</label>
                    <select id="ro-<?= (int) $u['id'] ?>" name="role">
                        <?php foreach ($roles as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= ($vals['role'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="pw-<?= (int) $u['id'] ?>">New password</label>
                    <input type="password" id="pw-<?= (int) $u['id'] ?>" name="password" placeholder="Leave blank to keep current" autocomplete="new-password">
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $vals['is_active'] === 1 ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
                    Account is active
                </label>
                <?php if ($isSelf): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" type="button" data-dialog-close>Cancel</button>
            <button class="btn btn-primary" type="submit"><?= icon('check') ?> Save account</button>
        </div>
    </form>
</dialog>
<?php endforeach; ?>

<dialog class="modal" id="newUser" data-autopen="<?= $openCreate ? '1' : '0' ?>">
    <form method="post" action="/users.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-head">
            <div>
                <h2>Create a user account</h2>
                <p>Accounts are for authorized registrar personnel only.</p>
            </div>
            <button class="icon-btn modal-close" type="button" data-dialog-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <?php if ($errors): ?>
                <div class="alert alert-error"><?= icon('alert') ?><span>Review the highlighted fields.</span></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field <?= isset($errors['full_name']) ? 'has-error' : '' ?>">
                    <label for="u_name">Full name</label>
                    <input type="text" id="u_name" name="full_name" value="<?= e($old['full_name']) ?>">
                    <?php if (isset($errors['full_name'])): ?><span class="error"><?= e($errors['full_name']) ?></span><?php endif; ?>
                </div>
                <div class="field <?= isset($errors['username']) ? 'has-error' : '' ?>">
                    <label for="u_user">Username</label>
                    <input type="text" id="u_user" name="username" value="<?= e($old['username']) ?>" autocomplete="off">
                    <?php if (isset($errors['username'])): ?><span class="error"><?= e($errors['username']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="u_role">Role</label>
                    <select id="u_role" name="role">
                        <?php foreach ($roles as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $old['role'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field <?= isset($errors['email']) ? 'has-error' : '' ?>">
                    <label for="u_email">Email</label>
                    <input type="email" id="u_email" name="email" value="<?= e($old['email']) ?>">
                    <?php if (isset($errors['email'])): ?><span class="error"><?= e($errors['email']) ?></span><?php endif; ?>
                </div>
                <div class="field span-2 <?= isset($errors['password']) ? 'has-error' : '' ?>">
                    <label for="u_pass">Temporary password</label>
                    <input type="password" id="u_pass" name="password" autocomplete="new-password" placeholder="At least 8 characters">
                    <?php if (isset($errors['password'])): ?><span class="error"><?= e($errors['password']) ?></span><?php endif; ?>
                    <span class="hint">The staff member should change this after first sign-in.</span>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" type="button" data-dialog-close>Cancel</button>
            <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Create account</button>
        </div>
    </form>
</dialog>
<?php
app_footer();
