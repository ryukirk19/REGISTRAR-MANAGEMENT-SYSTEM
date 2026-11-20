<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    logout_user();
    flash('success', 'You have signed out securely.');
} else {
    logout_user();
}

redirect('login.php');
