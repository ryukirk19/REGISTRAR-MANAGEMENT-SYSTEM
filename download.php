<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';

require_login();
$studentId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM students WHERE id = ?');
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student || !$student['digital_path']) {
    http_response_code(404);
    exit('No digital scan is attached to this record.');
}

$file = SCAN_DIR . '/' . basename($student['digital_path']);
if (!is_file($file)) {
    http_response_code(404);
    exit('The scan file is missing from storage.');
}

log_activity('Digital scan opened', 'Viewed scan for ' . $student['record_code']);

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
$allowed = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mime, $allowed, true)) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $student['record_code'] . '.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($file);
