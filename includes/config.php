<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

const APP_NAME = 'REGIS+';
const APP_TAGLINE = 'Registrar Records Management System';
const APP_VERSION = '1.0.0';

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
    ]);
    session_name('REGISPLUS');
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
