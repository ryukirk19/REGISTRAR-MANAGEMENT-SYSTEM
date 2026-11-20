<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT c.*, s.first_name, s.middle_name, s.last_name, s.lrn, s.grade_level, s.section, s.strand, s.school_year, s.record_code,
            u.full_name AS issued_by_name
     FROM certifications c
     JOIN students s ON s.id = c.student_id
     LEFT JOIN users u ON u.id = c.issued_by
     WHERE c.id = ?'
);
$stmt->execute([$id]);
$cert = $stmt->fetch();

if (!$cert) {
    http_response_code(404);
    exit('Certificate not found.');
}

$name = full_name($cert);
$gradeLabel = $cert['grade_level'] . ($cert['section'] ? ' – ' . $cert['section'] : '') . ($cert['strand'] ? ' (' . $cert['strand'] . ')' : '');
$school = setting('school_name');
$address = setting('school_address');
$sy = $cert['school_year'];
$purpose = $cert['purpose'] ? 'for ' . strtolower($cert['purpose']) : 'for whatever legal purpose it may serve';

$body = match ($cert['cert_type']) {
    'Certificate of Enrollment' =>
        'This is to certify that <strong>' . e($name) . '</strong>, bearing Learner Reference Number <strong>' . e($cert['lrn']) . '</strong>, is officially enrolled in <strong>' . e($gradeLabel) . '</strong> of ' . e($school) . ' for School Year <strong>' . e($sy) . '</strong>.',
    'Certificate of Good Moral' =>
        'This is to certify that <strong>' . e($name) . '</strong>, bearing Learner Reference Number <strong>' . e($cert['lrn']) . '</strong>, is a bona fide student of ' . e($school) . ' and has demonstrated good moral character and conduct befitting a responsible member of the school community.',
    'Certificate of Completion' =>
        'This is to certify that <strong>' . e($name) . '</strong>, bearing Learner Reference Number <strong>' . e($cert['lrn']) . '</strong>, has satisfactorily completed the requirements of <strong>' . e($cert['grade_level']) . '</strong> at ' . e($school) . ' for School Year <strong>' . e($sy) . '</strong>.',
    'Certificate of Transfer' =>
        'This is to certify that <strong>' . e($name) . '</strong>, bearing Learner Reference Number <strong>' . e($cert['lrn']) . '</strong>, has been given honorable dismissal from ' . e($school) . ' where the student was last enrolled in <strong>' . e($gradeLabel) . '</strong>. This student is academically eligible to transfer to another institution.',
    'Certificate of Recognition' =>
        'This is to certify that <strong>' . e($name) . '</strong>, bearing Learner Reference Number <strong>' . e($cert['lrn']) . '</strong>, is recognized by ' . e($school) . ' for outstanding academic performance and exemplary conduct during School Year <strong>' . e($sy) . '</strong>.',
    default =>
        'This is to certify that <strong>' . e($name) . '</strong>, bearing Learner Reference Number <strong>' . e($cert['lrn']) . '</strong>, is a bona fide student of ' . e($school) . '.',
};

$dateText = date('jS \d\a\y \o\f F Y', strtotime($cert['date_issued']));

open_page($cert['cert_no'] . ' · ' . APP_NAME, 'certificate-page', ['assets/css/app.css'], isset($_GET['print']) ? 'data-autoprint="1"' : '');
?>
<div class="print-bar no-print">
    <div>
        <a class="btn-link" href="/certifications.php">‹ Back to certifications</a>
        <span style="margin-left:14px; font-size:12px; color:var(--muted)">Print on A4 · margins default</span>
    </div>
    <div style="display:flex; gap:8px">
        <a class="btn btn-ghost" href="/student.php?id=<?= (int) $cert['student_id'] ?>"><?= icon('records') ?> Open record</a>
        <button class="btn btn-primary" type="button" data-print><?= icon('print') ?> Print certificate</button>
    </div>
</div>

<div class="certificate">
    <div class="cert-inner">
        <header class="cert-letterhead">
            <div class="cert-seal"><?= logo_mark() ?></div>
            <h1><?= e(strtoupper($school)) ?></h1>
            <p><?= e($address) ?> · Office of the Registrar</p>
        </header>

        <div class="cert-eyebrow"><?= e(strtoupper($cert['cert_type'])) ?></div>
        <h2 class="cert-title">To Whom It May Concern</h2>
        <p class="cert-sub">Certificate No. <?= e($cert['cert_no']) ?></p>

        <div class="cert-body">
            <p><?= $body ?></p>
            <p>This certification is issued upon the request of <strong><?= e($cert['issued_to']) ?></strong> <?= e($purpose) ?>.</p>
            <p>Issued this <?= e($dateText) ?> at <?= e($address) ?>, Philippines.</p>
        </div>

        <div class="cert-signs">
            <div class="cert-sign">
                <div class="line"></div>
                <strong><?= e(setting('registrar_name')) ?></strong>
                <span><?= e(setting('registrar_title')) ?></span>
            </div>
            <div class="cert-sign">
                <div class="line"></div>
                <strong><?= e(setting('principal_name')) ?></strong>
                <span><?= e(setting('principal_title')) ?></span>
            </div>
        </div>

        <p class="cert-footer">
            Record <?= e($cert['record_code']) ?> · LRN <?= e($cert['lrn']) ?> · Prepared by <?= e($cert['issued_by_name'] ?: 'Registrar Office') ?>
            <br>System-generated copy via REGIS+ — verify authenticity against the registrar's record index.
        </p>
    </div>
</div>
<?php
close_page();
