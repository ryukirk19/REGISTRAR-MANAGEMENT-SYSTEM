<?php
declare(strict_types=1);

function current_user(): ?array
{
    static $user = null;
    static $loaded = false;
    if ($loaded) {
        return $user;
    }
    $loaded = true;

    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['user_id']);
        return null;
    }
    $user = $row;
    return $user;
}

function attempt_login(string $username, string $password): string
{
    $fails = $_SESSION['login_fails'] ?? ['count' => 0, 'first' => 0];
    if ($fails['count'] >= 5 && (time() - $fails['first']) < 300) {
        return 'Too many failed attempts. Waiting a few minutes is required before trying again.';
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $fails = $fails['count'] === 0
            ? ['count' => 1, 'first' => time()]
            : ['count' => $fails['count'] + 1, 'first' => $fails['first']];
        $_SESSION['login_fails'] = $fails;
        return 'Incorrect username or password.';
    }
    if ((int) $user['is_active'] !== 1) {
        return 'This account has been deactivated. Contact the administrator.';
    }

    unset($_SESSION['login_fails']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(24));

    $stmt = db()->prepare("UPDATE users SET last_login = datetime('now','localtime') WHERE id = ?");
    $stmt->execute([$user['id']]);
    log_activity('User signed in', $user['full_name'] . ' signed in');

    return '';
}

function logout_user(): void
{
    if (current_user()) {
        log_activity('User signed out', current_user()['full_name'] . ' signed out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login.php');
    }
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function can_manage(): bool
{
    return in_array(current_user()['role'] ?? '', ['admin', 'registrar'], true);
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Only the system administrator can open this page.');
    }
}

function require_manager(): void
{
    require_login();
    if (!can_manage()) {
        http_response_code(403);
        exit('Registrar-level access is required for this action.');
    }
}

function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'System Administrator',
        'registrar' => 'Registrar Officer',
        default => 'Registrar Staff',
    };
}
