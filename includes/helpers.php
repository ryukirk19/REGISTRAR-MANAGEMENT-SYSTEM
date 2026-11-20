<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Session expired. Please go back, refresh the page, and try again.');
    }
}

function today(): string
{
    return date('Y-m-d');
}

function fmt_date(?string $date, string $format = 'M j, Y'): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '—';
}

function fmt_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y · g:i A', $ts) : '—';
}

function fmt_money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

function full_name(array $student): string
{
    $middle = trim((string) ($student['middle_name'] ?? ''));
    return trim($student['first_name'] . ' ' . $middle . ' ' . $student['last_name']);
}

function age_from(?string $birthDate): string
{
    if (!$birthDate) {
        return '—';
    }
    $born = new DateTime($birthDate);
    return (string) $born->diff(new DateTime())->y;
}

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT key, value FROM settings')->fetchAll() as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
}

function credential_docs(): array
{
    return [
        'SF10 (Form 137)' => 'Permanent record forwarded by the previous school',
        'SF9 (Report Card)' => 'Most recent report card on file',
        'Elementary Record' => 'Form 137 from elementary school',
        'PSA Birth Certificate' => 'Philippine Statistics Authority copy',
        'Good Moral Certificate' => 'Guidance office clearance',
        'Transfer Credentials' => 'Honorable dismissal for outgoing transfers',
    ];
}

function credential_statuses(): array
{
    return ['Complete', 'Pending', 'Missing', 'N/A'];
}

function request_docs(): array
{
    return [
        'SF10 (Form 137)',
        'SF9 (Form 138)',
        'Certificate of Enrollment',
        'Certificate of Good Moral',
        'Certificate of Completion',
        'Certificate of Transfer',
        'Authentication / Verification',
    ];
}

function request_statuses(): array
{
    return ['Pending', 'Processing', 'Ready for Release', 'Released', 'Cancelled'];
}

function request_next_status(string $status): ?string
{
    return match ($status) {
        'Pending' => 'Processing',
        'Processing' => 'Ready for Release',
        'Ready for Release' => 'Released',
        default => null,
    };
}

function cert_types(): array
{
    return [
        'Certificate of Enrollment',
        'Certificate of Good Moral',
        'Certificate of Completion',
        'Certificate of Transfer',
        'Certificate of Recognition',
    ];
}

function followup_statuses(): array
{
    return ['Open', 'In Progress', 'Resolved'];
}

function transfer_statuses(): array
{
    return ['Documents Pending', 'In Process', 'Completed'];
}

function student_statuses(): array
{
    return ['Enrolled', 'Transferred Out', 'Transferred In', 'Graduated', 'Archived'];
}

function grade_levels(): array
{
    return ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
}

function strands(): array
{
    return ['', 'STEM', 'ABM', 'HUMSS', 'GAS', 'TVL-ICT', 'TVL-HE'];
}

function status_tone(string $status): string
{
    return match ($status) {
        'Complete', 'Released', 'Resolved', 'Completed', 'Enrolled' => 'ok',
        'Pending', 'Open', 'Documents Pending' => 'warn',
        'Processing', 'In Progress', 'Ready for Release', 'In Process', 'Transferred In', 'Incoming', 'Outgoing' => 'info',
        'Missing', 'Cancelled' => 'danger',
        'Graduated', 'Transferred Out', 'Archived', 'N/A' => 'muted',
        default => 'muted',
    };
}

function status_pill(string $status): string
{
    return '<span class="pill pill-' . status_tone($status) . '"><span class="pill-dot"></span>' . e($status) . '</span>';
}

function completeness(array $row): array
{
    $total = (int) ($row['cred_total'] ?? 0);
    $done = (int) ($row['cred_done'] ?? 0);
    if ($total <= 0) {
        return ['done' => 0, 'total' => 0, 'pct' => 0, 'incomplete' => 0];
    }
    $pct = (int) round($done / $total * 100);
    return ['done' => $done, 'total' => $total, 'pct' => $pct, 'incomplete' => $total - $done];
}

function location_line(array $student): string
{
    $parts = array_filter([
        $student['cabinet'] ? 'Cabinet ' . $student['cabinet'] : null,
        $student['shelf'] ?? null,
        $student['folder_no'] ?? null,
    ]);
    return $parts ? implode(' · ', $parts) : 'Not yet filed';
}

function log_activity(string $action, string $details = ''): void
{
    $user = current_user();
    $stmt = db()->prepare('INSERT INTO activity (user_id, action, details) VALUES (?, ?, ?)');
    $stmt->execute([$user['id'] ?? null, $action, $details]);
}

function next_code(string $table, string $column, string $prefix): string
{
    $stmt = db()->query("SELECT $column FROM $table ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    $n = $last ? (int) substr((string) $last, -4) + 1 : 1;
    return sprintf('%s-%04d', $prefix, $n);
}

function student_options(): array
{
    return db()->query('SELECT id, lrn, first_name, middle_name, last_name, grade_level, section, record_code FROM students ORDER BY last_name, first_name')->fetchAll();
}

function fetch_student(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*,
            (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status = \'Complete\') AS cred_done,
            (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status <> \'N/A\') AS cred_total
         FROM students s WHERE s.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upload_scan(array $file, int $studentId): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The upload did not finish. Please try again.');
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('The scan must be 8 MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only PDF, JPG, or PNG scans are accepted.');
    }

    if (!is_dir(SCAN_DIR)) {
        mkdir(SCAN_DIR, 0775, true);
    }
    $name = sprintf('s%d-%s.%s', $studentId, bin2hex(random_bytes(8)), $allowed[$mime]);
    if (!move_uploaded_file($file['tmp_name'], SCAN_DIR . '/' . $name)) {
        throw new RuntimeException('The scan could not be saved. Check folder permissions.');
    }
    return $name;
}

function grade_order(string $grade): int
{
    return (int) preg_replace('/\D/', '', $grade);
}

function export_csv(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, $row, ',', '"', '');
    }
    fclose($out);
    exit;
}
