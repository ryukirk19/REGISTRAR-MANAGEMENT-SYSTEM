<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_login();

$pdo = db();
$type = (string) ($_GET['type'] ?? 'students');
$stamp = date('Ymd');

if ($type === 'students') {
    $rows = $pdo->query(
        "SELECT s.*,
            (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status = 'Complete') AS cred_done,
            (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status <> 'N/A') AS cred_total
         FROM students s ORDER BY s.last_name, s.first_name"
    )->fetchAll();

    $out = [];
    foreach ($rows as $s) {
        $comp = completeness($s);
        $out[] = [
            $s['record_code'], $s['lrn'], full_name($s), $s['sex'], $s['birth_date'], $s['grade_level'], $s['section'],
            $s['strand'], $s['school_year'], $s['status'], $s['cabinet'], $s['shelf'], $s['folder_no'],
            $comp['done'], $comp['total'], $comp['incomplete'] ? 'Incomplete' : 'Complete', $s['previous_school'],
        ];
    }
    log_activity('Report exported', 'Student record index exported to CSV');
    export_csv('regis-students-' . $stamp . '.csv', [
        'Record code', 'LRN', 'Student name', 'Sex', 'Birth date', 'Grade level', 'Section', 'Strand',
        'School year', 'Status', 'Cabinet', 'Shelf', 'Folder', 'Credentials complete', 'Credential slots',
        'Completeness', 'Previous school',
    ], $out);
}

if ($type === 'requests') {
    $from = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-90 days')));
    $to = (string) ($_GET['to'] ?? today());
    $grade = (string) ($_GET['grade'] ?? '');
    $params = [':from' => $from, ':to' => $to];
    $gradeSql = '';
    if (in_array($grade, grade_levels(), true)) {
        $gradeSql = ' AND s.grade_level = :grade';
        $params[':grade'] = $grade;
    }
    $stmt = $pdo->prepare(
        "SELECT r.*, s.first_name, s.middle_name, s.last_name, s.lrn, s.record_code, s.grade_level, u.full_name AS assigned_name
         FROM requests r JOIN students s ON s.id = r.student_id
         LEFT JOIN users u ON u.id = r.assigned_to
         WHERE r.date_requested BETWEEN :from AND :to $gradeSql
         ORDER BY r.date_requested"
    );
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            $r['request_no'], $r['record_code'], $r['lrn'], $r['first_name'] . ' ' . $r['last_name'], $r['grade_level'],
            $r['document_type'], $r['purpose'], $r['requester_name'], $r['relationship'], $r['requester_contact'],
            $r['assigned_name'] ?? 'Unassigned', $r['status'], $r['date_requested'], $r['date_needed'],
            $r['date_released'], number_format((float) $r['fee'], 2), $r['remarks'],
        ];
    }
    log_activity('Report exported', 'Document requests exported to CSV');
    export_csv('regis-requests-' . $stamp . '.csv', [
        'Request no', 'Record code', 'LRN', 'Student', 'Grade', 'Document', 'Purpose', 'Requester',
        'Relationship', 'Contact', 'Assigned to', 'Status', 'Date requested', 'Needed by', 'Date released',
        'Fee (PHP)', 'Remarks',
    ], $out);
}

if ($type === 'followups') {
    $stmt = $pdo->query(
        "SELECT f.*, s.first_name, s.middle_name, s.last_name, s.record_code, u.full_name AS staff_name
         FROM followups f JOIN students s ON s.id = f.student_id LEFT JOIN users u ON u.id = f.created_by
         ORDER BY f.followup_date DESC"
    );
    $out = [];
    foreach ($stmt->fetchAll() as $f) {
        $out[] = [
            $f['record_code'], $f['first_name'] . ' ' . $f['last_name'], $f['concern'], $f['school_contacted'],
            $f['contact_person'], $f['method'], $f['followup_date'], $f['next_action'], $f['next_followup_date'],
            $f['status'], $f['staff_name'] ?? '',
        ];
    }
    log_activity('Report exported', 'Follow-up log exported to CSV');
    export_csv('regis-followups-' . $stamp . '.csv', [
        'Record code', 'Student', 'Concern', 'School / office contacted', 'Contact person', 'Method',
        'Follow-up date', 'Next action', 'Next follow-up date', 'Status', 'Logged by',
    ], $out);
}

if ($type === 'certifications') {
    $stmt = $pdo->query(
        "SELECT c.*, s.first_name, s.middle_name, s.last_name, s.record_code, u.full_name AS issued_by_name
         FROM certifications c JOIN students s ON s.id = c.student_id LEFT JOIN users u ON u.id = c.issued_by
         ORDER BY c.date_issued DESC"
    );
    $out = [];
    foreach ($stmt->fetchAll() as $c) {
        $out[] = [
            $c['cert_no'], $c['record_code'], $c['first_name'] . ' ' . $c['last_name'], $c['cert_type'],
            $c['issued_to'], $c['purpose'], $c['date_issued'], $c['issued_by_name'] ?? '', $c['remarks'],
        ];
    }
    log_activity('Report exported', 'Certifications register exported to CSV');
    export_csv('regis-certifications-' . $stamp . '.csv', [
        'Certificate no', 'Record code', 'Student', 'Type', 'Issued to', 'Purpose', 'Date issued', 'Issued by', 'Remarks',
    ], $out);
}

http_response_code(400);
exit('Unknown export type.');
