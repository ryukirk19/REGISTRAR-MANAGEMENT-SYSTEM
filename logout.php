<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ((string) ($_POST['auto'] ?? '') === '1') {
        session_release_mark();
        http_response_code(204);
        exit;
    }
    if ((string) ($_POST['keep'] ?? '') === '1') {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId > 0) {
            session_release_clear($userId);
        }
        http_response_code(204);
        exit;
    }
    logout_user();
    flash('success', 'You have signed out securely.');
} else {
    logout_user();
}

redirect('login.php');
