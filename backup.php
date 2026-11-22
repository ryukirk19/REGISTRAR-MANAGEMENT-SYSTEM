<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_admin();

$pdo = db();
$pdo->query('PRAGMA wal_checkpoint(TRUNCATE)');

if (!is_file(DB_FILE)) {
    http_response_code(404);
    exit('No database file to back up.');
}

$filename = 'regis-backup-' . date('Ymd-His') . '.sqlite';
set_setting('last_backup_at', date('Y-m-d H:i:s'));
set_setting('last_backup_file', $filename);
log_activity('Database backup downloaded', 'Backup copy of the REGIS+ database downloaded by ' . current_user()['full_name']);

header('Content-Type: application/vnd.sqlite3');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize(DB_FILE));
header('Cache-Control: private, no-store');
readfile(DB_FILE);
