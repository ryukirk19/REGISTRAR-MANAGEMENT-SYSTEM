<?php
declare(strict_types=1);

function auth_device_label(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $browser = 'Unknown browser';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Brave' => 'Brave', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $label) {
        if (stripos($ua, $needle) !== false) {
            $browser = $label;
            break;
        }
    }
    $os = 'Unknown device';
    foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Mac OS X' => 'macOS', 'Linux' => 'Linux'] as $needle => $label) {
        if (stripos($ua, $needle) !== false) {
            $os = $label;
            break;
        }
    }
    return $browser . ' on ' . $os;
}

function auth_context(): string
{
    return auth_device_label() . ' · ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown IP');
}

function session_release_key(int $userId): string
{
    return 'session_release_' . $userId;
}

function session_release_mark(): void
{
    $token = (string) ($_SESSION['session_token'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($token === '' || $userId === 0) {
        return;
    }
    $payload = json_encode(['token' => $token, 'at' => time()]);
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([session_release_key($userId), $payload]);
}

function session_release_pending(int $userId): ?array
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([session_release_key($userId)]);
    $value = $stmt->fetchColumn();
    if (!is_string($value) || $value === '') {
        return null;
    }
    $pending = json_decode($value, true);
    return is_array($pending) && isset($pending['token'], $pending['at']) ? $pending : null;
}

function session_release_clear(int $userId): void
{
    db()->prepare('DELETE FROM settings WHERE key = ?')->execute([session_release_key($userId)]);
}

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
        unset($_SESSION['user_id'], $_SESSION['session_token']);
        return null;
    }

    $token = (string) ($_SESSION['session_token'] ?? '');
    if ($token === '' || !hash_equals((string) $row['session_token'], $token)) {
        unset($_SESSION['user_id'], $_SESSION['session_token']);
        if (!empty($row['session_token'])) {
            flash('error', 'This account was signed in on another device, so this session was closed.');
        }
        return null;
    }

    $seen = strtotime((string) $row['session_seen_at']);
    if (!$seen || $seen < time() - SESSION_IDLE_MINUTES * 60) {
        db()->prepare('UPDATE users SET session_token = NULL, session_seen_at = NULL WHERE id = ? AND session_token = ?')
            ->execute([$row['id'], $token]);
        unset($_SESSION['user_id'], $_SESSION['session_token']);
        flash('error', 'Your session expired after ' . SESSION_IDLE_MINUTES . ' minutes of inactivity. Sign in again.');
        return null;
    }

    $pending = session_release_pending((int) $row['id']);
    if ($pending && hash_equals((string) $pending['token'], $token)) {
        session_release_clear((int) $row['id']);
        if ((int) $pending['at'] + SESSION_RELEASE_GRACE <= time()) {
            db()->prepare('UPDATE users SET session_token = NULL, session_seen_at = NULL WHERE id = ? AND session_token = ?')
                ->execute([$row['id'], $token]);
            log_activity('Session released', $row['full_name'] . ' closed the site — session released');
            unset($_SESSION['user_id'], $_SESSION['session_token']);
            flash('error', 'The site was closed, so this session was released. Sign in again.');
            return null;
        }
    }

    if ($seen < time() - 60) {
        db()->prepare('UPDATE users SET session_seen_at = ? WHERE id = ? AND session_token = ?')
            ->execute([date('Y-m-d H:i:s'), $row['id'], $token]);
    }

    $user = $row;
    return $user;
}

function record_login_failure(array $user): void
{
    $count = (int) $user['failed_logins'] + 1;
    if ($count >= LOGIN_MAX_ATTEMPTS) {
        $until = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
        db()->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')
            ->execute([$count, $until, $user['id']]);
        log_activity('Sign-in locked', $user['full_name'] . ' account locked after ' . $count . ' failed attempts · ' . auth_context());
        return;
    }
    db()->prepare('UPDATE users SET failed_logins = ? WHERE id = ?')->execute([$count, $user['id']]);
}

function attempt_login(string $username, string $password): string
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && !empty($user['locked_until'])) {
        $lockedUntil = strtotime((string) $user['locked_until']);
        if ($lockedUntil && $lockedUntil > time()) {
            $remaining = max(1, (int) ceil(($lockedUntil - time()) / 60));
            return 'Too many failed sign-in attempts. This account is locked for about ' . $remaining . ' more minute' . ($remaining === 1 ? '' : 's') . '.';
        }
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        if ($user) {
            record_login_failure($user);
        }
        return 'Incorrect username or password.';
    }
    if ((int) $user['is_active'] !== 1) {
        return 'This account has been deactivated. Contact the administrator.';
    }

    $pending = session_release_pending((int) $user['id']);
    if ($pending) {
        $heldToken = (string) ($user['session_token'] ?? '');
        if ($heldToken !== '' && hash_equals((string) $pending['token'], $heldToken)) {
            db()->prepare('UPDATE users SET session_token = NULL, session_seen_at = NULL WHERE id = ? AND session_token = ?')
                ->execute([$user['id'], $heldToken]);
            db()->prepare('INSERT INTO activity (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$user['id'], 'Session released', $user['full_name'] . ' closed the site — session released']);
        }
        session_release_clear((int) $user['id']);
    }

    $now = date('Y-m-d H:i:s');
    $cutoff = date('Y-m-d H:i:s', time() - SESSION_IDLE_MINUTES * 60);
    $token = bin2hex(random_bytes(24));
    $claim = db()->prepare(
        'UPDATE users
         SET session_token = ?, session_seen_at = ?, failed_logins = 0, locked_until = NULL, last_login = ?
         WHERE id = ? AND (session_token IS NULL OR session_token = \'\' OR session_seen_at IS NULL OR session_seen_at < ?)'
    );
    $claim->execute([$token, $now, $now, $user['id'], $cutoff]);
    if ($claim->rowCount() !== 1) {
        log_activity('Sign-in blocked', $user['full_name'] . ' attempted a second sign-in while the account was active · ' . auth_context());
        return 'This account is already signed in on another device. Sign out there first, or try again after ' . SESSION_IDLE_MINUTES . ' minutes.';
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['session_token'] = $token;
    $_SESSION['csrf'] = bin2hex(random_bytes(24));

    db()->prepare('INSERT INTO activity (user_id, action, details) VALUES (?, ?, ?)')
        ->execute([$user['id'], 'User signed in', $user['full_name'] . ' signed in · ' . auth_context()]);

    return '';
}

function logout_user(): void
{
    $user = current_user();
    if ($user) {
        log_activity('User signed out', $user['full_name'] . ' signed out');
        session_release_clear((int) $user['id']);
        db()->prepare('UPDATE users SET session_token = NULL, session_seen_at = NULL WHERE id = ? AND session_token = ?')
            ->execute([$user['id'], (string) ($_SESSION['session_token'] ?? '')]);
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
    if (can_manage()) {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        flash('error', 'Student records are restricted to the System Administrator and the Registrar Officer.');
        redirect('dashboard.php');
    }
    http_response_code(403);
    exit('Registrar-level access is required for this action.');
}

function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'System Administrator',
        'registrar' => 'Registrar Officer',
        default => 'Registrar Staff',
    };
}
