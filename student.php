<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_manager();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$student = $id ? fetch_student($id) : null;
if (!$student) {
    flash('error', 'That student record could not be found.');
    redirect('students.php');
}

$storageCabinets = storage_cabinets();
$storageShelves = storage_shelves();
$storageFolders = storage_folders();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $actor = current_user();

    if (in_array($action, ['update', 'credential', 'upload', 'remove_scan', 'delete'], true) && !can_manage()) {
        http_response_code(403);
        exit('Registrar-level access is required for this action.');
    }

    if ($action === 'credential') {
        $doc = (string) ($_POST['doc_type'] ?? '');
        $status = (string) ($_POST['status'] ?? '');
        if (array_key_exists($doc, credential_docs()) && in_array($status, credential_statuses(), true)) {
            $received = null;
            if ($status === 'Complete') {
                $existing = $pdo->prepare('SELECT date_received FROM credentials WHERE student_id = ? AND doc_type = ?');
                $existing->execute([$id, $doc]);
                $received = $existing->fetchColumn() ?: today();
            }
            $stmt = $pdo->prepare(
                "INSERT INTO credentials (student_id, doc_type, status, date_received) VALUES (?, ?, ?, ?)
                 ON CONFLICT(student_id, doc_type) DO UPDATE SET status = excluded.status, date_received = excluded.date_received, updated_at = datetime('now','localtime')"
            );
            $stmt->execute([$id, $doc, $status, $received]);
            log_activity('Checklist updated', $doc . ' marked ' . $status . ' for ' . $student['record_code']);
            flash('success', $doc . ' marked as ' . strtolower($status) . '.');
            redirect('student.php?id=' . $id);
        }
        flash('error', 'Unknown document or status.');
        redirect('student.php?id=' . $id);
    }

    if ($action === 'update') {
        $fields = [
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'middle_name' => trim((string) ($_POST['middle_name'] ?? '')),
            'last_name' => trim((string) ($_POST['last_name'] ?? '')),
            'sex' => (string) ($_POST['sex'] ?? 'F'),
            'birth_date' => (string) ($_POST['birth_date'] ?? ''),
            'grade_level' => (string) ($_POST['grade_level'] ?? ''),
            'section' => trim((string) ($_POST['section'] ?? '')),
            'strand' => (string) ($_POST['strand'] ?? ''),
            'school_year' => trim((string) ($_POST['school_year'] ?? '')),
            'status' => (string) ($_POST['status'] ?? ''),
            'previous_school' => trim((string) ($_POST['previous_school'] ?? '')),
            'guardian_name' => trim((string) ($_POST['guardian_name'] ?? '')),
            'guardian_contact' => trim((string) ($_POST['guardian_contact'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'cabinet' => trim((string) ($_POST['cabinet'] ?? '')),
            'shelf' => trim((string) ($_POST['shelf'] ?? '')),
            'folder_no' => trim((string) ($_POST['folder_no'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];
        if ($fields['first_name'] === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($fields['last_name'] === '') {
            $errors['last_name'] = 'Last name is required.';
        }
        if (!in_array($fields['grade_level'], grade_levels(), true)) {
            $errors['grade_level'] = 'Select a valid grade level.';
        }
        if (!in_array($fields['status'], student_statuses(), true)) {
            $errors['status'] = 'Select a valid status.';
        }
        $storageError = storage_validate($fields, $id);
        if ($storageError !== null) {
            $errors['folder_no'] = $storageError;
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                "UPDATE students SET first_name=?, middle_name=?, last_name=?, sex=?, birth_date=?, grade_level=?, section=?, strand=?, school_year=?, status=?, previous_school=?, guardian_name=?, guardian_contact=?, address=?, cabinet=?, shelf=?, folder_no=?, notes=?, updated_at=datetime('now','localtime') WHERE id=?"
            );
            $stmt->execute([
                $fields['first_name'], $fields['middle_name'], $fields['last_name'], $fields['sex'],
                $fields['birth_date'] ?: null, $fields['grade_level'], $fields['section'], $fields['strand'],
                $fields['school_year'], $fields['status'], $fields['previous_school'], $fields['guardian_name'],
                $fields['guardian_contact'], $fields['address'], $fields['cabinet'], $fields['shelf'],
                $fields['folder_no'], $fields['notes'], $id,
            ]);
            log_activity('Student record updated', $student['record_code'] . ' details edited');
            flash('success', 'Record details saved.');
            redirect('student.php?id=' . $id);
        }
    }

    if ($action === 'upload') {
        try {
            $name = upload_scan($_FILES['scan'] ?? [], $id);
            if ($name === null) {
                flash('error', 'Choose a PDF or image file to upload.');
                redirect('student.php?id=' . $id);
            }
            if ($student['digital_path'] && is_file(SCAN_DIR . '/' . $student['digital_path'])) {
                @unlink(SCAN_DIR . '/' . $student['digital_path']);
            }
            $stmt = $pdo->prepare('UPDATE students SET digital_path = ?, updated_at = datetime(\'now\',\'localtime\') WHERE id = ?');
            $stmt->execute([$name, $id]);
            log_activity('Digital scan attached', 'Scan uploaded for ' . $student['record_code']);
            flash('success', 'Digital scan attached to this record.');
        } catch (RuntimeException $ex) {
            flash('error', $ex->getMessage());
        }
        redirect('student.php?id=' . $id);
    }

    if ($action === 'remove_scan') {
        if ($student['digital_path'] && is_file(SCAN_DIR . '/' . $student['digital_path'])) {
            @unlink(SCAN_DIR . '/' . $student['digital_path']);
        }
        $pdo->prepare('UPDATE students SET digital_path = NULL WHERE id = ?')->execute([$id]);
        log_activity('Digital scan removed', 'Scan removed from ' . $student['record_code']);
        flash('success', 'Digital scan removed.');
        redirect('student.php?id=' . $id);
    }

    if ($action === 'delete') {
        $recordCode = $student['record_code'];
        $studentName = full_name($student);
        if ($student['digital_path'] && is_file(SCAN_DIR . '/' . $student['digital_path'])) {
            @unlink(SCAN_DIR . '/' . $student['digital_path']);
        }
        $pdo->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);
        log_activity('Student record deleted', $recordCode . ' removed (' . $studentName . ')');
        flash('success', 'Record ' . $recordCode . ' was permanently deleted.');
        redirect('students.php');
    }
}

$student = fetch_student($id);
$comp = completeness($student);
$creds = $pdo->prepare('SELECT * FROM credentials WHERE student_id = ?');
$creds->execute([$id]);
$credMap = [];
foreach ($creds->fetchAll() as $c) {
    $credMap[$c['doc_type']] = $c;
}

$requests = $pdo->prepare(
    "SELECT r.*, u.full_name AS assigned_name, u.first_name AS assigned_first_name, u.middle_name AS assigned_middle_name, u.last_name AS assigned_last_name FROM requests r
     LEFT JOIN users u ON u.id = r.assigned_to
     WHERE r.student_id = ? ORDER BY r.date_requested DESC, r.id DESC LIMIT 6"
);
$requests->execute([$id]);
$requests = $requests->fetchAll();

$followups = $pdo->prepare('SELECT * FROM followups WHERE student_id = ? ORDER BY followup_date DESC, id DESC LIMIT 5');
$followups->execute([$id]);
$followups = $followups->fetchAll();

$certs = $pdo->prepare('SELECT * FROM certifications WHERE student_id = ? ORDER BY date_issued DESC LIMIT 4');
$certs->execute([$id]);
$certs = $certs->fetchAll();

$transfers = $pdo->prepare('SELECT * FROM transfers WHERE student_id = ? ORDER BY effective_date DESC LIMIT 3');
$transfers->execute([$id]);
$transfers = $transfers->fetchAll();

$timeline = [];
$timeline[] = ['date' => substr($student['created_at'], 0, 10), 'title' => 'Record indexed', 'body' => $student['record_code'] . ' created in the register'];
foreach ($requests as $r) {
    $timeline[] = ['date' => $r['date_requested'], 'title' => $r['document_type'] . ' requested', 'body' => $r['request_no'] . ' · ' . $r['status']];
}
foreach ($followups as $f) {
    $timeline[] = ['date' => $f['followup_date'], 'title' => 'Follow-up: ' . $f['method'], 'body' => $f['concern']];
}
foreach ($certs as $c) {
    $timeline[] = ['date' => $c['date_issued'], 'title' => $c['cert_type'] . ' issued', 'body' => $c['cert_no']];
}
foreach ($transfers as $t) {
    $timeline[] = ['date' => $t['effective_date'] ?? '', 'title' => $t['direction'] . ' transfer', 'body' => $t['counterpart_school']];
}
usort($timeline, fn ($a, $b) => strcmp($b['date'], $a['date']));
$timeline = array_slice($timeline, 0, 8);

$form = $errors ? array_merge($student, $_POST) : $student;

app_header(student_name($student), 'students.php');
?>
<div class="page-head" style="margin-bottom:18px">
    <div>
        <div class="eyebrow">Record <?= e($student['record_code']) ?></div>
        <h1><?= e(student_name($student)) ?></h1>
        <p class="page-sub">Complete record file, credential checklist, and activity for this student.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/students.php"><?= icon('records') ?> Back to index</a>
        <a class="btn btn-ghost" href="/certificate_enrollment.php?id=<?= $id ?>" target="_blank" rel="noopener"><?= icon('cert') ?> Enrollment certificate</a>
        <?php if (can_manage()): ?>
            <button class="btn btn-primary" type="button" data-dialog-open="#editStudent"><?= icon('edit') ?> Edit record</button>
            <form method="post" action="/student.php?id=<?= $id ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button class="btn btn-danger" type="submit" data-confirm="Delete this student record permanently? Their credential checklist, requests, certificates, and transfers will also be removed."><?= icon('x') ?> Delete record</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<section class="profile-hero" style="margin-bottom:18px">
    <div class="profile-avatar"><?= e(initials(full_name($student))) ?></div>
    <div class="profile-id">
        <h1><?= e(student_name($student)) ?></h1>
        <div class="meta">
            <?= status_pill($student['status']) ?>
            <span class="code-tag"><?= e($student['record_code']) ?></span>
            <span>LRN <?= e($student['lrn']) ?></span>
            <span>·</span>
            <span><?= e($student['grade_level']) ?><?= $student['section'] ? ' – ' . e($student['section']) : '' ?><?= $student['strand'] ? ' · ' . e($student['strand']) : '' ?></span>
            <span>·</span>
            <span>SY <?= e($student['school_year']) ?></span>
        </div>
    </div>
    <div class="profile-actions">
        <div style="text-align:right">
            <div style="font-family:var(--serif); font-size:28px; line-height:1"><?= $comp['pct'] ?>%</div>
            <div style="font-size:11px; letter-spacing:.1em; text-transform:uppercase; color:var(--muted)">Checklist complete</div>
        </div>
    </div>
</section>

<div class="grid split-2">
    <div style="display:flex; flex-direction:column; gap:18px; min-width:0">
        <section class="card">
            <div class="card-head bordered">
                <div>
                    <h2 class="section-title">Student details</h2>
                    <p class="section-sub">Permanent record information</p>
                </div>
            </div>
            <div class="card-body" style="padding-top:18px">
                <dl class="detail-grid" style="margin:0">
                    <div class="detail"><dt>Sex</dt><dd><?= $student['sex'] === 'M' ? 'Male' : 'Female' ?></dd></div>
                    <div class="detail"><dt>Birth date</dt><dd><?= e(fmt_date($student['birth_date'])) ?> <span class="muted">(<?= e(age_from($student['birth_date'])) ?> yrs)</span></dd></div>
                    <div class="detail"><dt>Previous school</dt><dd><?= e($student['previous_school'] ?: '—') ?></dd></div>
                    <div class="detail"><dt>Parent / guardian</dt><dd><?= e($student['guardian_name'] ?: '—') ?></dd></div>
                    <div class="detail"><dt>Contact</dt><dd><?= e($student['guardian_contact'] ?: '—') ?></dd></div>
                    <div class="detail"><dt>Address</dt><dd><?= e($student['address'] ?: '—') ?></dd></div>
                </dl>
                <?php if ($student['notes']): ?>
                    <div class="field-set" style="margin-top:18px; grid-column:auto">
                        <?= icon('file') ?><span><?= e($student['notes']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head bordered">
                <div>
                    <h2 class="section-title">Credential checklist</h2>
                    <p class="section-sub"><?= $comp['done'] ?> of <?= $comp['total'] ?> credentials verified — <?= $comp['incomplete'] ?> still open</p>
                </div>
                <?php if ($comp['incomplete'] > 0): ?>
                    <span class="pill pill-warn"><span class="pill-dot"></span>Incomplete file</span>
                <?php else: ?>
                    <span class="pill pill-ok"><span class="pill-dot"></span>File complete</span>
                <?php endif; ?>
            </div>
            <div class="checklist">
                <?php foreach (credential_docs() as $doc => $desc):
                    $c = $credMap[$doc] ?? ['status' => 'Missing', 'date_received' => null, 'remarks' => ''];
                ?>
                    <div class="check-row">
                        <div class="check-doc">
                            <strong><?= e($doc) ?></strong>
                            <span><?= e($c['remarks'] ?: $desc) ?></span>
                        </div>
                        <div class="check-date"><?= $c['date_received'] ? 'Rec. ' . e(fmt_date($c['date_received'], 'M j')) : 'No date' ?></div>
                        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap">
                            <?= status_pill($c['status']) ?>
                            <?php if (can_manage()): ?>
                                <form method="post" action="/student.php?id=<?= $id ?>" class="seg-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="credential">
                                    <input type="hidden" name="doc_type" value="<?= e($doc) ?>">
                                    <div class="seg">
                                        <?php foreach (credential_statuses() as $st): ?>
                                            <button type="submit" name="status" value="<?= e($st) ?>"
                                                class="<?= $c['status'] === $st ? 'is-on ' . ($st === 'Missing' ? 'is-danger' : ($st === 'Pending' ? 'is-warn' : ($st === 'N/A' ? 'is-muted' : ''))) : '' ?>"><?= e($st) ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-head bordered">
                <div>
                    <h2 class="section-title">Document requests</h2>
                    <p class="section-sub">Requests filed for this student</p>
                </div>
                <a class="btn btn-sm btn-ghost" href="/requests.php?student=<?= $id ?>&new=1"><?= icon('plus') ?> New request</a>
            </div>
            <?php if ($requests): ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>Request</th><th>Document</th><th>Assigned</th><th>Status</th><th class="right">Requested</th></tr></thead>
                        <tbody>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td><span class="code-tag"><?= e($r['request_no']) ?></span></td>
                                <td><?= e($r['document_type']) ?></td>
                                <td><?= e($r['assigned_name'] ? account_name(['first_name' => $r['assigned_first_name'], 'middle_name' => $r['assigned_middle_name'], 'last_name' => $r['assigned_last_name'], 'full_name' => $r['assigned_name']]) : 'Unassigned') ?></td>
                                <td><?= status_pill($r['status']) ?></td>
                                <td class="right num"><?= e(fmt_date($r['date_requested'], 'M j, Y')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty" style="padding:30px"><p>No requests have been filed for this record yet.</p></div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head bordered">
                <div>
                    <h2 class="section-title">Follow-up log</h2>
                    <p class="section-sub">Communication with previous schools</p>
                </div>
                <a class="btn btn-sm btn-ghost" href="/followups.php?student=<?= $id ?>&new=1"><?= icon('plus') ?> Log follow-up</a>
            </div>
            <?php if ($followups): ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead><tr><th>Date</th><th>Concern</th><th>Contacted</th><th>Next action</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($followups as $f): ?>
                            <tr>
                                <td class="num"><?= e(fmt_date($f['followup_date'], 'M j, Y')) ?></td>
                                <td><span class="strong" style="font-weight:500"><?= e($f['concern']) ?></span><span class="sub"><?= e($f['method']) ?></span></td>
                                <td><?= e($f['school_contacted'] ?: '—') ?><span class="sub"><?= e($f['contact_person'] ?: '') ?></span></td>
                                <td><?= e($f['next_action'] ?: '—') ?><span class="sub"><?= $f['next_followup_date'] ? 'Due ' . e(fmt_date($f['next_followup_date'], 'M j')) : '' ?></span></td>
                                <td><?= status_pill($f['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty" style="padding:30px"><p>No follow-ups logged for this student.</p></div>
            <?php endif; ?>
        </section>
    </div>

    <div style="display:flex; flex-direction:column; gap:18px; min-width:0">
        <section class="card card-pad">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:13px">
                <h2 class="section-title">Record locator</h2>
                <button class="btn btn-sm btn-ghost" type="button" data-copy="<?= e($student['record_code']) ?>" data-copy-done="Copied"><?= icon('copy') ?> Code</button>
            </div>
            <dl class="locator">
                <div><dt>Cabinet</dt><dd><?= e($student['cabinet'] ?: '—') ?></dd></div>
                <div><dt>Shelf</dt><dd><?= e($student['shelf'] ?: '—') ?></dd></div>
                <div><dt>Folder</dt><dd><?= e($student['folder_no'] ?: '—') ?></dd></div>
            </dl>
            <p class="section-sub" style="margin-top:12px"><?= $student['cabinet'] ? 'Physical folder is filed in the registrar vault.' : 'This record has not been assigned a physical location.' ?></p>
        </section>

        <section class="card card-pad">
            <h2 class="section-title" style="margin-bottom:10px">Completeness</h2>
            <div class="meter" style="margin-bottom:8px">
                <div class="meter-track" style="height:8px"><div class="meter-fill <?= $comp['pct'] >= 100 ? '' : ($comp['pct'] >= 70 ? 'warn' : 'danger') ?>" style="width: <?= $comp['pct'] ?>%"></div></div>
                <span class="meter-label"><?= $comp['pct'] ?>%</span>
            </div>
            <div class="legend" style="gap:7px">
                <?php
                $statusCount = ['Complete' => 0, 'Pending' => 0, 'Missing' => 0, 'N/A' => 0];
                foreach ($credMap as $c) {
                    $statusCount[$c['status']] = ($statusCount[$c['status']] ?? 0) + 1;
                }
                $tones = ['Complete' => 'pill-ok', 'Pending' => 'pill-warn', 'Missing' => 'pill-danger', 'N/A' => 'pill-muted'];
                foreach ($statusCount as $st => $n):
                    if ($n === 0) continue;
                ?>
                    <div class="legend-row"><span class="pill <?= $tones[$st] ?>" style="font-size:11px"><span class="pill-dot"></span><?= e($st) ?></span><span class="val"><?= $n ?></span></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card card-pad">
            <h2 class="section-title" style="margin-bottom:10px">Digital copy</h2>
            <?php if ($student['digital_path']): ?>
                <div class="doc-file" style="margin-bottom:12px">
                    <?= icon('file') ?>
                    <div style="flex:1; min-width:0">
                        <strong style="display:block; font-size:12.8px; overflow:hidden; text-overflow:ellipsis">Attached scan</strong>
                        <span class="muted" style="font-size:11.5px"><?= e(strtoupper(pathinfo($student['digital_path'], PATHINFO_EXTENSION))) ?> file</span>
                    </div>
                    <a class="btn btn-sm btn-ghost" href="/download.php?id=<?= $id ?>"><?= icon('eye') ?> View</a>
                </div>
            <?php endif; ?>
            <?php if (can_manage()): ?>
                <form method="post" action="/student.php?id=<?= $id ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload">
                    <div class="field">
                        <label for="scan"><?= $student['digital_path'] ? 'Replace scan' : 'Attach a scan' ?></label>
                        <input type="file" id="scan" name="scan" accept=".pdf,.jpg,.jpeg,.png">
                        <span class="hint">PDF, JPG, or PNG · up to 8 MB</span>
                    </div>
                    <div style="display:flex; gap:8px; margin-top:12px">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('upload') ?> Upload</button>
                        <?php if ($student['digital_path']): ?>
                            <button class="btn btn-danger btn-sm" type="submit" name="action" value="remove_scan" data-confirm="Remove the attached scan?">Remove</button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="card card-pad">
            <h2 class="section-title" style="margin-bottom:12px">Certifications</h2>
            <?php if ($certs): ?>
                <?php foreach ($certs as $c): ?>
                    <div class="mini-row" style="padding:9px 0">
                        <div class="left">
                            <strong><?= e($c['cert_type']) ?></strong>
                            <span><?= e($c['cert_no']) ?> · <?= e(fmt_date($c['date_issued'], 'M j, Y')) ?></span>
                        </div>
                        <a class="btn btn-sm btn-ghost" href="/certificate.php?id=<?= (int) $c['id'] ?>" target="_blank"><?= icon('print') ?></a>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="section-sub">No certifications issued yet.</p>
            <?php endif; ?>
            <?php if ($transfers): ?>
                <div class="divider" style="border-top:1px solid var(--line); margin:12px 0"></div>
                <h2 class="section-title" style="font-size:15px; margin-bottom:8px">Transfers</h2>
                <?php foreach ($transfers as $t): ?>
                    <div class="mini-row" style="padding:8px 0">
                        <div class="left">
                            <strong><?= e($t['direction']) ?> → <?= e($t['counterpart_school']) ?></strong>
                            <span><?= e(fmt_date($t['effective_date'])) ?> · <?= e($t['status']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="card card-pad">
            <h2 class="section-title" style="margin-bottom:14px">Record timeline</h2>
            <ul class="timeline">
                <?php foreach ($timeline as $ev): ?>
                    <li>
                        <span class="dot<?= str_contains($ev['title'], 'Follow-up') ? ' warn' : '' ?>"></span>
                        <strong><?= e($ev['title']) ?></strong>
                        <span><?= e(fmt_date($ev['date'])) ?></span>
                        <p><?= e($ev['body']) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</div>

<?php if (can_manage()): ?>
<dialog class="modal" id="editStudent" data-autopen="<?= $errors ? '1' : '0' ?>">
    <form method="post" action="/student.php?id=<?= $id ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <div class="modal-head">
            <div>
                <h2>Edit record details</h2>
                <p><?= e($student['record_code']) ?> · LRN <?= e($student['lrn']) ?></p>
            </div>
            <button class="icon-btn modal-close" type="button" data-dialog-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <?php if ($errors): ?>
                <div class="alert alert-error"><?= icon('alert') ?><span>Please review the highlighted fields.</span></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field <?= isset($errors['first_name']) ? 'has-error' : '' ?>">
                    <label for="e_first">First name</label>
                    <input type="text" id="e_first" name="first_name" value="<?= e((string) ($form['first_name'] ?? '')) ?>" required>
                    <?php if (isset($errors['first_name'])): ?><span class="error"><?= e($errors['first_name']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="e_middle">Middle name (optional)</label>
                    <input type="text" id="e_middle" name="middle_name" value="<?= e((string) ($form['middle_name'] ?? '')) ?>" placeholder="Leave blank if none">
                </div>
                <div class="field <?= isset($errors['last_name']) ? 'has-error' : '' ?>">
                    <label for="e_last">Last name</label>
                    <input type="text" id="e_last" name="last_name" value="<?= e((string) ($form['last_name'] ?? '')) ?>" required>
                    <?php if (isset($errors['last_name'])): ?><span class="error"><?= e($errors['last_name']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="e_sex">Sex</label>
                    <select id="e_sex" name="sex">
                        <option value="F" <?= ($form['sex'] ?? '') === 'F' ? 'selected' : '' ?>>Female</option>
                        <option value="M" <?= ($form['sex'] ?? '') === 'M' ? 'selected' : '' ?>>Male</option>
                    </select>
                </div>
                <div class="field">
                    <label for="e_bday">Birth date</label>
                    <input type="date" id="e_bday" name="birth_date" value="<?= e((string) ($form['birth_date'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label for="e_grade">Grade level</label>
                    <select id="e_grade" name="grade_level">
                        <?php foreach (grade_levels() as $g): ?>
                            <option value="<?= e($g) ?>" <?= ($form['grade_level'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="e_section">Section</label>
                    <input type="text" id="e_section" name="section" value="<?= e((string) ($form['section'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label for="e_strand">Track / strand</label>
                    <select id="e_strand" name="strand">
                        <?php foreach (strands() as $st): ?>
                            <option value="<?= e($st) ?>" <?= ($form['strand'] ?? '') === $st ? 'selected' : '' ?>><?= $st === '' ? 'Junior high (no strand)' : e($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="e_sy">School year</label>
                    <input type="text" id="e_sy" name="school_year" value="<?= e((string) ($form['school_year'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label for="e_status">Record status</label>
                    <select id="e_status" name="status">
                        <?php foreach (student_statuses() as $st): ?>
                            <option value="<?= e($st) ?>" <?= ($form['status'] ?? '') === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field span-2">
                    <label for="e_prev">Previous school</label>
                    <input type="text" id="e_prev" name="previous_school" value="<?= e((string) ($form['previous_school'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label for="e_guardian">Parent / guardian</label>
                    <input type="text" id="e_guardian" name="guardian_name" value="<?= e((string) ($form['guardian_name'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label for="e_contact">Contact number</label>
                    <input type="text" id="e_contact" name="guardian_contact" value="<?= e((string) ($form['guardian_contact'] ?? '')) ?>">
                </div>
                <div class="field span-2">
                    <label for="e_addr">Address</label>
                    <input type="text" id="e_addr" name="address" value="<?= e((string) ($form['address'] ?? '')) ?>">
                </div>
                <div class="field-set">
                    <?= icon('cabinet') ?><span>Filing follows the vault: the cabinet matches the student's grade level, the shelf is the section, and the folder belongs to one student. <a href="/cabinets.php" target="_blank" rel="noopener">Manage cabinets and shelves</a>.</span>
                </div>
                <div class="field">
                    <label for="e_cab">Cabinet (grade level)</label>
                    <select id="e_cab" name="cabinet" data-storage-cabinet>
                        <option value="">— Not filed —</option>
                        <?php foreach ($storageCabinets as $cab): ?>
                            <option value="<?= e($cab['code']) ?>"
                                data-grade="<?= e((string) ($cab['grade_level'] ?? '')) ?>"
                                <?= ($form['cabinet'] ?? '') === $cab['code'] ? 'selected' : '' ?>>
                                <?= e($cab['code'] . (trim((string) ($cab['grade_level'] ?? '')) !== '' ? ' · ' . $cab['grade_level'] : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="e_shelf">Shelf (section)</label>
                    <select id="e_shelf" name="shelf" data-storage-shelf>
                        <option value="">— Select cabinet —</option>
                        <?php foreach ($storageShelves as $shelf): ?>
                            <option value="<?= e($shelf['code']) ?>"
                                data-cabinet="<?= e($shelf['cabinet_code']) ?>"
                                data-section="<?= e((string) ($shelf['section'] ?? '')) ?>"
                                data-grade="<?= e((string) ($shelf['grade_level'] ?? '')) ?>"
                                data-strand="<?= e((string) ($shelf['strand'] ?? '')) ?>"
                                <?= ($form['cabinet'] ?? '') === $shelf['cabinet_code'] && ($form['shelf'] ?? '') === $shelf['code'] ? 'selected' : '' ?>>
                                <?= e($shelf['code'] . ($shelf['section'] ? ' — ' . $shelf['section'] : ' — unreserved')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="e_folder">Folder (student)</label>
                    <select id="e_folder" name="folder_no" data-storage-folder>
                        <option value="">— Select shelf —</option>
                        <?php foreach ($storageFolders as $folder):
                            $occupant = trim((string) ($folder['occupant'] ?? ''));
                            $isCurrent = ($form['cabinet'] ?? '') === $folder['cabinet_code'] && ($form['shelf'] ?? '') === $folder['shelf_code'] && ($form['folder_no'] ?? '') === $folder['number'];
                            $isTaken = $occupant !== '' && !$isCurrent;
                        ?>
                            <option value="<?= e($folder['number']) ?>"
                                data-cabinet="<?= e($folder['cabinet_code']) ?>"
                                data-shelf="<?= e($folder['shelf_code']) ?>"
                                data-taken="<?= $isTaken ? '1' : '0' ?>"
                                <?= $isCurrent ? 'selected' : '' ?>
                                <?= $isTaken ? 'disabled' : '' ?>>
                                Folder <?= e($folder['number']) ?> · <?= $occupant !== '' ? e($occupant) : 'Empty' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['folder_no'])): ?><span class="error"><?= e($errors['folder_no']) ?></span><?php endif; ?>
                </div>
                <div class="field span-2">
                    <label for="e_notes">Notes</label>
                    <textarea id="e_notes" name="notes" placeholder="Remarks for registrar staff only"><?= e((string) ($form['notes'] ?? '')) ?></textarea>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" type="button" data-dialog-close>Cancel</button>
            <button class="btn btn-primary" type="submit"><?= icon('check') ?> Save changes</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php
app_footer();
