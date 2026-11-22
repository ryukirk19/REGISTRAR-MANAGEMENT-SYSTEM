<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_manager();

$id = (int) ($_GET['id'] ?? 0);
$student = $id ? fetch_student($id) : null;

if (!$student) {
    http_response_code(404);
    exit('Student record not found.');
}

$name = full_name($student);
$school = setting('school_name');
$address = setting('school_address');
$sy = $student['school_year'] !== '' ? $student['school_year'] : setting('school_year', '');
$gradeLine = $student['grade_level'];
if ($student['section'] !== '') {
    $gradeLine .= ' – ' . $student['section'];
}
if ($student['strand'] !== '') {
    $gradeLine .= ' (' . $student['strand'] . ' strand)';
}
$dateText = date('jS \d\a\y \o\f F Y');

open_page('Certificate of Enrollment · ' . $name, 'certificate-page', ['assets/css/app.css'], isset($_GET['print']) ? 'data-autoprint="1"' : '');
?>
<div class="print-bar no-print">
    <div>
        <a class="btn-link" href="/student.php?id=<?= (int) $student['id'] ?>">‹ Back to record</a>
        <span style="margin-left:14px; font-size:12px; color:var(--muted)">Print on A4 · margins default</span>
    </div>
    <div style="display:flex; gap:8px">
        <a class="btn btn-ghost" href="/certifications.php"><?= icon('cert') ?> Certifications</a>
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

        <div class="cert-eyebrow">Official Certification</div>
        <h2 class="cert-title">Certificate of Enrollment</h2>
        <p class="cert-sub">School Year <?= e($sy !== '' ? $sy : '—') ?> · Record <?= e($student['record_code']) ?></p>

        <div class="cert-body">
            <p>
                This is to certify that <strong><?= e($name) ?></strong>, bearing Learner Reference Number
                <strong><?= e($student['lrn']) ?></strong>, is officially enrolled in
                <strong><?= e($gradeLine) ?></strong> at <?= e($school) ?> for School Year
                <strong><?= e($sy !== '' ? $sy : '—') ?></strong>.
            </p>
            <p>
                This certification is issued upon the request of the learner or their parent / guardian for whatever
                legal purpose it may serve.
            </p>
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
            Record <?= e($student['record_code']) ?> · LRN <?= e($student['lrn']) ?> · Prepared by <?= e(current_user()['full_name'] ?: 'Registrar Office') ?>
            <br>System-generated copy via REGIS+ — verify authenticity against the registrar's record index.
        </p>
    </div>
</div>
<?php
close_page();
