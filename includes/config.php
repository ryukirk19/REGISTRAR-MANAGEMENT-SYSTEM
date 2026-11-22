<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

const APP_NAME = 'REGIS+';
const APP_TAGLINE = 'Registrar Records Management System';
const APP_VERSION = '1.2.3';
const SESSION_IDLE_MINUTES = 30;
const SESSION_RELEASE_GRACE = 10;
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 15;

define('BASE_PATH', dirname(__DIR__));
define('DB_FILE', BASE_PATH . '/database/regis.sqlite');
define('STORAGE_DIR', BASE_PATH . '/storage');
define('SCAN_DIR', STORAGE_DIR . '/scans');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
    ]);
    session_name('REGISPLUS');
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage.php';
