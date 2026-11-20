<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$pdo = db();
$errors = [];
$openModal = isset($_GET['new']);
$old = [
    'student_id' => (int) ($_GET['student'] ?? 0) ?: '',
    'cert_type' => 'Certificate of Enrollment',
    'issued_to' => '',
    'purpose' => '',
    'date_issued' => today(),
    'remarks' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_manager();
    if (($_POST['action'] ?? '') === 'issue') {
        foreach ($old as $k => $v) {
            $old[$k] = trim((string) ($_POST[$k] ?? ''));
        }
        $openModal = true;

        $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
        $stmt->execute([(int) $old['student_id']]);
        $student = $stmt->fetch();
        if (!$student) {
            $errors['student_id'] = 'Choose a student record.';
        }
        if (!in_array($old['cert_type'], cert_types(), true)) {
            $errors['cert_type'] = 'Choose a valid certification.';
        }
        if ($old['issued_to'] === '') {
            $errors['issued_to'] = 'Enter the name of the person receiving the certificate.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['date_issued'])) {
            $errors['date_issued'] = 'Enter a valid issuance date.';
        }

        if (!$errors) {
            $certNo = next_code('certifications', 'cert_no', 'CERT-' . date('Y'));
            $stmt = $pdo->prepare(
                'INSERT INTO certifications (cert_no, student_id, cert_type, issued_to, purpose, issued_by, date_issued, remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $certNo, (int) $old['student_id'], $old['cert_type'], $old['issued_to'],
                $old['purpose'], current_user()['id'], $old['date_issued'], $old['remarks'],
            ]);
            log_activity('Certificate issued', $certNo . ' · ' . $old['cert_type'] . ' for ' . $student['record_code']);
            flash('success', $certNo . ' issued. It is ready to print.');
            redirect('certifications.php?focus=' . (int) $pdo->lastInsertId());
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$typeFilter = (string) ($_GET['type'] ?? '');
$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = "(c.cert_no LIKE :q OR c.issued_to LIKE :q OR s.first_name || ' ' || s.last_name LIKE :q OR s.record_code LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if (in_array($typeFilter, cert_types(), true)) {
    $where[] = 'c.cert_type = :type';
    $params[':type'] = $typeFilter;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT c.*, s.first_name, s.middle_name, s.last_name, s.grade_level, s.section, s.record_code, u.full_name AS issued_by_name
     FROM certifications c
     JOIN students s ON s.id = c.student_id
     LEFT JOIN users u ON u.id = c.issued_by
     WHERE $whereSql
     ORDER BY c.date_issued DESC, c.id DESC LIMIT 60"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$issuedThisMonth = (int) $pdo->query("SELECT COUNT(*) FROM certifications WHERE substr(date_issued, 1, 7) = '" . date('Y-m') . "'")->fetchColumn();
$typeCounts = [];
foreach ($pdo->query('SELECT cert_type, COUNT(*) AS n FROM certifications GROUP BY cert_type') as $row) {
    $typeCounts[$row['cert_type']] = (int) $row['n'];
}
$focusId = (int) ($_GET['focus'] ?? 0);

app_header('Certifications', 'certifications.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Certification generation &amp; records</div>
        <h1>Issued certifications</h1>
        <p class="page-sub">Every certificate is numbered, attributed to a student record, and printable on school letterhead.</p>
    </div>
    <?php if (can_manage()): ?>
        <div class="page-actions">
            <button class="btn btn-primary" type="button" data-dialog-open="#newCert"><?= icon('cert') ?> Issue certificate</button>
        </div>
    <?php endif; ?>
</div>

<div class="grid split-2" style="margin-bottom:18px">
    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="section-title">Certificates register</h2>
                <p class="section-sub"><?= count($rows) ?> shown · <?= $issuedThisMonth ?> issued this month</p>
            </div>
            <form method="get" action="/certifications.php" style="display:flex; gap:8px; flex-wrap:wrap">
                <label class="field-search" style="min-width:210px">
                    <?= icon('search') ?>
                    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Certificate no or student">
                </label>
                <select class="select-inline" name="type" data-autosubmit>
                    <option value="">All types</option>
                    <?php foreach (cert_types() as $t): ?>
                        <option value="<?= e($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php if ($rows): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Certificate</th><th>Student</th><th>Type</th><th>Issued to</th><th class="right">Date</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $c): ?>
                        <tr style="<?= $focusId === (int) $c['id'] ? 'background:#fdfcf7' : '' ?>">
                            <td><span class="code-tag"><?= e($c['cert_no']) ?></span></td>
                            <td>
                                <a class="strong" href="/student.php?id=<?= (int) $c['student_id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></a>
                                <span class="sub"><?= e($c['record_code']) ?> · <?= e($c['grade_level']) ?></span>
                            </td>
                            <td><?= e($c['cert_type']) ?></td>
                            <td><?= e($c['issued_to']) ?></td>
                            <td class="right num"><?= e(fmt_date($c['date_issued'], 'M j, Y')) ?></td>
                            <td class="actions">
                                <a class="btn btn-sm btn-ghost" href="/certificate.php?id=<?= (int) $c['id'] ?>" target="_blank"><?= icon('print') ?> Print</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty">
                <span class="empty-mark"><?= icon('cert') ?></span>
                <strong>No certificates issued yet</strong>
                <p>Issue a certificate to generate its number and printable letterhead copy.</p>
            </div>
        <?php endif; ?>
    </section>

    <div style="display:flex; flex-direction:column; gap:18px">
        <section class="card card-pad">
            <h2 class="section-title" style="margin-bottom:12px">By certificate type</h2>
            <?php if ($typeCounts): ?>
                <?php $maxType = max($typeCounts); foreach ($typeCounts as $t => $n): ?>
                    <div style="margin-bottom:12px">
                        <div style="display:flex; justify-content:space-between; font-size:12.6px; margin-bottom:5px; color:var(--muted)">
                            <span><?= e($t) ?></span><span style="font-weight:600; color:var(--text)"><?= $n ?></span>
                        </div>
                        <div class="meter-track"><div class="meter-fill" style="width: <?= (int) round($n / $maxType * 100) ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="section-sub">No data to summarize yet.</p>
            <?php endif; ?>
        </section>

        <section class="card card-pad">
            <h2 class="section-title" style="margin-bottom:8px">How issuance works</h2>
            <ul class="timeline" style="margin-top:10px">
                <li><span class="dot"></span><strong>Verify the record</strong><span>Confirm the checklist and request status before issuing.</span></li>
                <li><span class="dot"></span><strong>Generate the number</strong><span>REGIS+ assigns the next CERT-<?= date('Y') ?>-#### serial.</span></li>
                <li><span class="dot"></span><strong>Print on letterhead</strong><span>Signatures remain with the registrar and principal.</span></li>
            </ul>
        </section>
    </div>
</div>

<?php if (can_manage()): ?>
<dialog class="modal" id="newCert" data-autopen="<?= $openModal ? '1' : '0' ?>">
    <form method="post" action="/certifications.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="issue">
        <div class="modal-head">
            <div>
                <h2>Issue a certification</h2>
                <p>The certificate number is generated automatically.</p>
            </div>
            <button class="icon-btn modal-close" type="button" data-dialog-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <?php if ($errors): ?>
                <div class="alert alert-error"><?= icon('alert') ?><span>Review the highlighted fields.</span></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field span-2 <?= isset($errors['student_id']) ? 'has-error' : '' ?>">
                    <label for="c_student">Student record</label>
                    <select id="c_student" name="student_id" required>
                        <option value="">Select a student…</option>
                        <?php foreach (student_options() as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= (string) $old['student_id'] === (string) $s['id'] ? 'selected' : '' ?>>
                                <?= e($s['last_name'] . ', ' . $s['first_name'] . ' — ' . $s['grade_level'] . ' · ' . $s['record_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['student_id'])): ?><span class="error"><?= e($errors['student_id']) ?></span><?php endif; ?>
                </div>
                <div class="field <?= isset($errors['cert_type']) ? 'has-error' : '' ?>">
                    <label for="cert_type">Certificate type</label>
                    <select id="cert_type" name="cert_type">
                        <?php foreach (cert_types() as $t): ?>
                            <option value="<?= e($t) ?>" <?= $old['cert_type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field <?= isset($errors['issued_to']) ? 'has-error' : '' ?>">
                    <label for="issued_to">Issued to</label>
                    <input type="text" id="issued_to" name="issued_to" value="<?= e($old['issued_to']) ?>" placeholder="Student or parent name">
                    <?php if (isset($errors['issued_to'])): ?><span class="error"><?= e($errors['issued_to']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="c_purpose">Purpose</label>
                    <input type="text" id="c_purpose" name="purpose" value="<?= e($old['purpose']) ?>" placeholder="e.g. Visa application">
                </div>
                <div class="field <?= isset($errors['date_issued']) ? 'has-error' : '' ?>">
                    <label for="date_issued">Date issued</label>
                    <input type="date" id="date_issued" name="date_issued" value="<?= e($old['date_issued']) ?>">
                </div>
                <div class="field span-2">
                    <label for="c_remarks">Remarks</label>
                    <textarea id="c_remarks" name="remarks" placeholder="Internal note, e.g. signed copy filed"><?= e($old['remarks']) ?></textarea>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" type="button" data-dialog-close>Cancel</button>
            <button class="btn btn-primary" type="submit"><?= icon('cert') ?> Issue certificate</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php
app_footer();
