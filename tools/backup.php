<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This backup script runs from the command line.\n");
}

require_once dirname(__DIR__) . '/includes/config.php';

$keep = 8;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--keep=(\d+)$/', (string) $arg, $m)) {
        $keep = max(2, min(60, (int) $m[1]));
    }
}

$backupsDir = dirname(DB_FILE) . '/backups';
if (!is_dir($backupsDir)) {
    @mkdir($backupsDir, 0775, true);
}
$logFile = $backupsDir . '/backup.log';
$log = static function (string $line) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
};
$fail = static function (string $message) use ($log): never {
    $log('FAILED: ' . $message);
    fwrite(STDERR, 'Backup failed: ' . $message . "\n");
    exit(1);
};

if (!is_file(DB_FILE)) {
    $fail('database file not found at ' . DB_FILE);
}
if (!is_dir($backupsDir) || !is_writable($backupsDir)) {
    $fail('backup folder is not writable: ' . $backupsDir);
}

$lock = @fopen($backupsDir . '/.backup.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $fail('another backup is already running');
}

try {
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)');

    $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
    if ($integrity !== 'ok') {
        $fail('integrity check reported: ' . $integrity);
    }

    $now = microtime(true);
    $stamp = date('Ymd-His', (int) $now) . '-' . sprintf('%03d', (int) (($now - floor($now)) * 1000));
    $filename = 'regis-' . $stamp . '.sqlite';
    $target = $backupsDir . '/' . $filename;
    if (!copy(DB_FILE, $target)) {
        $fail('could not copy the database to ' . $target);
    }

    $copy = new PDO('sqlite:' . $target, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);
    $copyIntegrity = (string) $copy->query('PRAGMA integrity_check')->fetchColumn();
    $copy = null;
    if ($copyIntegrity !== 'ok') {
        @unlink($target);
        $fail('the copied file did not pass its integrity check');
    }

    $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute(['last_backup_at', date('Y-m-d H:i:s')]);
    $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute(['last_backup_file', $filename]);
    $pdo->prepare('INSERT INTO activity (user_id, action, details) VALUES (NULL, ?, ?)')
        ->execute(['Scheduled backup completed', $filename . ' written by the command-line backup']);

    $files = glob($backupsDir . '/regis-*.sqlite') ?: [];
    rsort($files);
    $removed = 0;
    foreach (array_slice($files, $keep) as $old) {
        if (is_file($old) && @unlink($old)) {
            $removed++;
        }
    }

    foreach (glob($backupsDir . '/regis-*.sqlite-*') ?: [] as $sidecar) {
        @unlink($sidecar);
    }

    $size = number_format(filesize($target) / 1024, 1);
    $message = $filename . ' (' . $size . ' KB), keeping ' . $keep;
    if ($removed > 0) {
        $message .= ', removed ' . $removed . ' older';
    }
    $log('OK: ' . $message);
    echo 'Backup written: ' . $target . "\n";
    echo 'Size: ' . $size . " KB\n";
    if ($removed > 0) {
        echo 'Removed ' . $removed . " older backup(s).\n";
    }
    exit(0);
} catch (Throwable $ex) {
    $fail($ex->getMessage());
}
