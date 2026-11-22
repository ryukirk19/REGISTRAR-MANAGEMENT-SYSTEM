<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_manager();

$pdo = db();
$errors = [];
$openModal = isset($_GET['new']);
$storageCabinets = storage_cabinets();
$storageShelves = storage_shelves();
$storageFolders = storage_folders();
$old = [
    'lrn' => '', 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'sex' => 'F',
    'birth_date' => '', 'grade_level' => 'Grade 7', 'section' => '', 'strand' => '',
    'school_year' => setting('school_year', '2026-2027'), 'status' => 'Enrolled',
    'previous_school' => '', 'guardian_name' => '', 'guardian_contact' => '', 'address' => '',
    'cabinet' => '', 'shelf' => '', 'folder_no' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'create') {
        foreach ($old as $k => $v) {
            $old[$k] = trim((string) ($_POST[$k] ?? ''));
        }
        $openModal = true;

        if (!preg_match('/^\d{12}$/', $old['lrn'])) {
            $errors['lrn'] = 'LRN must be exactly 12 digits.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE lrn = ?');
            $stmt->execute([$old['lrn']]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors['lrn'] = 'This LRN is already in the index.';
            }
        }
        if ($old['first_name'] === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($old['last_name'] === '') {
            $errors['last_name'] = 'Last name is required.';
        }
        if (!in_array($old['grade_level'], grade_levels(), true)) {
            $errors['grade_level'] = 'Select a valid grade level.';
        }
        if ($old['birth_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['birth_date'])) {
            $errors['birth_date'] = 'Use a valid birth date.';
        }

        $syForLimits = $old['school_year'] !== '' ? $old['school_year'] : setting('school_year', '');
        if (!isset($errors['grade_level']) && $syForLimits !== '' && enrollment_grade_count($syForLimits, $old['grade_level']) >= GRADE_ENROLLMENT_LIMIT) {
            $errors['grade_level'] = $old['grade_level'] . ' already has ' . number_format(GRADE_ENROLLMENT_LIMIT) . ' learners for ' . $syForLimits . '.';
        }
        if (!isset($errors['strand']) && in_array($old['grade_level'], ['Grade 11', 'Grade 12'], true) && $old['strand'] !== ''
            && $syForLimits !== '' && enrollment_strand_count($syForLimits, $old['strand']) >= STRAND_ENROLLMENT_LIMIT) {
            $errors['strand'] = $old['strand'] . ' already has ' . number_format(STRAND_ENROLLMENT_LIMIT) . ' learners for ' . $syForLimits . '.';
        }
        $storageError = storage_validate($old);
        if ($storageError !== null) {
            $errors['folder_no'] = $storageError;
        }

        if (!$errors) {
            $nextId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM students')->fetchColumn();
            $recordCode = sprintf('REC-%s-%04d', setting('school_year', '2026') === '' ? date('Y') : substr(setting('school_year', '2026'), 0, 4), $nextId);

            $stmt = $pdo->prepare(
                'INSERT INTO students (lrn, first_name, middle_name, last_name, sex, birth_date, grade_level, section, strand, school_year, status, guardian_name, guardian_contact, address, previous_school, record_code, cabinet, shelf, folder_no, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $old['lrn'], $old['first_name'], $old['middle_name'], $old['last_name'], $old['sex'],
                $old['birth_date'] ?: null, $old['grade_level'], $old['section'], $old['strand'],
                $old['school_year'], $old['status'], $old['guardian_name'], $old['guardian_contact'],
                $old['address'], $old['previous_school'], $recordCode, $old['cabinet'], $old['shelf'],
                $old['folder_no'], current_user()['id'],
            ]);
            $studentId = (int) $pdo->lastInsertId();
            $credStmt = $pdo->prepare("INSERT INTO credentials (student_id, doc_type, status, remarks) VALUES (?, ?, 'Missing', '')");
            foreach (array_keys(credential_docs()) as $doc) {
                $credStmt->execute([$studentId, $doc]);
            }
            log_activity('Student record created', 'Added ' . $recordCode . ' for ' . $old['first_name'] . ' ' . $old['last_name']);
            flash('success', 'Record ' . $recordCode . ' was added to the index with a blank checklist ready for verification.');
            redirect('student.php?id=' . $studentId);
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$grade = (string) ($_GET['grade'] ?? '');
$completeness = (string) ($_GET['completeness'] ?? '');
$statusFilter = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = "(t.first_name || ' ' || COALESCE(t.middle_name,'') || ' ' || t.last_name LIKE :q OR t.lrn LIKE :q OR t.record_code LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if (in_array($grade, grade_levels(), true)) {
    $where[] = 't.grade_level = :grade';
    $params[':grade'] = $grade;
}
if ($completeness === 'incomplete') {
    $where[] = 't.cred_done < t.cred_total';
} elseif ($completeness === 'complete') {
    $where[] = 't.cred_done >= t.cred_total';
}
if (in_array($statusFilter, student_statuses(), true)) {
    $where[] = 't.status = :status';
    $params[':status'] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$baseSql = "FROM (SELECT s.*,
        (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status = 'Complete') AS cred_done,
        (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status <> 'N/A') AS cred_total
     FROM students s) t WHERE $whereSql";

$countStmt = $pdo->prepare("SELECT COUNT(*) $baseSql");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);

$stmt = $pdo->prepare("SELECT t.* $baseSql ORDER BY t.last_name COLLATE NOCASE, t.first_name COLLATE NOCASE LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();

$qs = $_GET;
unset($qs['page'], $qs['new']);
$pageUrl = fn (int $p) => '/students.php?' . http_build_query(array_merge($qs, ['page' => $p]));

$filtersOn = $q !== '' || $grade !== '' || $completeness !== '' || $statusFilter !== '';

app_header('Student records', 'students.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Student record index &amp; locator</div>
        <h1>Student records</h1>
        <p class="page-sub">Search <?= $totalRows ?> indexed records by name, LRN, or record code, and trace the exact physical location of each file.</p>
    </div>
    <?php if (can_manage()): ?>
        <div class="page-actions">
            <a class="btn btn-ghost" href="/import.php"><?= icon('upload') ?> Import CSV</a>
            <button class="btn btn-primary" type="button" data-dialog-open="#newStudent"><?= icon('plus') ?> New record</button>
        </div>
    <?php endif; ?>
</div>

<form class="card" method="get" action="/students.php" style="margin-bottom:18px">
    <div class="toolbar">
        <label class="field-search">
            <?= icon('search') ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, LRN, or record code">
        </label>
        <select class="select-inline" name="grade">
            <option value="">All grade levels</option>
            <?php foreach (grade_levels() as $g): ?>
                <option value="<?= e($g) ?>" <?= $grade === $g ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select-inline" name="completeness">
            <option value="">Any completeness</option>
            <option value="complete" <?= $completeness === 'complete' ? 'selected' : '' ?>>Complete files</option>
            <option value="incomplete" <?= $completeness === 'incomplete' ? 'selected' : '' ?>>Incomplete files</option>
        </select>
        <select class="select-inline" name="status">
            <option value="">Any status</option>
            <?php foreach (student_statuses() as $st): ?>
                <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e($st) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('search') ?> Apply</button>
        <?php if ($filtersOn): ?>
            <a class="btn-link" href="/students.php">Reset</a>
        <?php endif; ?>
        <span class="spacer"></span>
        <span class="pill pill-muted"><span class="pill-dot"></span><?= $totalRows ?> records</span>
    </div>
</form>

<section class="card">
    <?php if ($rows): ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Level</th>
                        <th>Record location</th>
                        <th>Checklist</th>
                        <th>Status</th>
                        <th class="right">Open</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $s):
                    $comp = completeness($s);
                    $tone = $comp['pct'] >= 100 ? '' : ($comp['pct'] >= 70 ? 'warn' : 'danger');
                ?>
                    <tr>
                        <td>
                            <div class="name-cell">
                                <span class="avatar-sm<?= $comp['pct'] >= 100 ? ' accent' : '' ?>"><?= e(initials($s['first_name'] . ' ' . $s['last_name'])) ?></span>
                                <span>
                                    <a class="strong" href="/student.php?id=<?= (int) $s['id'] ?>"><?= e(student_name($s)) ?></a>
                                    <span class="sub mono"><?= e($s['record_code']) ?> · LRN <?= e($s['lrn']) ?></span>
                                </span>
                            </div>
                        </td>
                        <td>
                            <span class="strong" style="font-weight:500"><?= e($s['grade_level']) ?></span>
                            <span class="sub"><?= e($s['section'] ?: 'No section') ?><?= $s['strand'] ? ' · ' . e($s['strand']) : '' ?></span>
                        </td>
                        <td>
                            <span style="font-size:12.6px"><?= e(location_line($s)) ?></span>
                            <span class="sub"><?= $s['digital_path'] ? 'Digital scan attached' : 'No digital scan' ?></span>
                        </td>
                        <td>
                            <div class="meter">
                                <div class="meter-track"><div class="meter-fill <?= $tone ?>" style="width: <?= $comp['pct'] ?>%"></div></div>
                                <span class="meter-label"><?= $comp['pct'] ?>%</span>
                            </div>
                            <span class="sub"><?= $comp['done'] ?>/<?= $comp['total'] ?> credentials on file</span>
                        </td>
                        <td><?= status_pill($s['status']) ?></td>
                        <td class="actions">
                            <a class="btn btn-sm btn-ghost" href="/student.php?id=<?= (int) $s['id'] ?>">Open <?= icon('arrow-right') ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pager">
            <span class="pager-info">Showing <?= (($page - 1) * $perPage) + 1 ?>–<?= min($page * $perPage, $totalRows) ?> of <?= $totalRows ?></span>
            <?php if ($page > 1): ?><a href="<?= e($pageUrl($page - 1)) ?>">‹ Prev</a><?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <?php if ($p === $page): ?><span class="pg is-current"><?= $p ?></span><?php else: ?><a href="<?= e($pageUrl($p)) ?>"><?= $p ?></a><?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?><a href="<?= e($pageUrl($page + 1)) ?>">Next ›</a><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="empty">
            <span class="empty-mark"><?= icon('records') ?></span>
            <strong>No records match these filters</strong>
            <p>Try a different name, LRN, or record code — or reset the filters to browse the full index.</p>
        </div>
    <?php endif; ?>
</section>

<?php if (can_manage()): ?>
<dialog class="modal" id="newStudent" data-autopen="<?= $openModal ? '1' : '0' ?>">
    <form method="post" action="/students.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-head">
            <div>
                <h2>Add a student record</h2>
                <p>A record code is issued automatically. The checklist starts blank for verification.</p>
            </div>
            <button class="icon-btn modal-close" type="button" data-dialog-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <?php if ($errors): ?>
                <div class="alert alert-error"><?= icon('alert') ?><span>Some fields need attention. Review the highlighted inputs below.</span></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field <?= isset($errors['lrn']) ? 'has-error' : '' ?>">
                    <label for="lrn">Learner reference number (LRN)</label>
                    <input type="text" id="lrn" name="lrn" inputmode="numeric" maxlength="12" placeholder="12-digit LRN" value="<?= e($old['lrn']) ?>" required>
                    <?php if (isset($errors['lrn'])): ?><span class="error"><?= e($errors['lrn']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="sex">Sex</label>
                    <select id="sex" name="sex">
                        <option value="F" <?= $old['sex'] === 'F' ? 'selected' : '' ?>>Female</option>
                        <option value="M" <?= $old['sex'] === 'M' ? 'selected' : '' ?>>Male</option>
                    </select>
                </div>
                <div class="field <?= isset($errors['first_name']) ? 'has-error' : '' ?>">
                    <label for="first_name">First name</label>
                    <input type="text" id="first_name" name="first_name" value="<?= e($old['first_name']) ?>" required>
                    <?php if (isset($errors['first_name'])): ?><span class="error"><?= e($errors['first_name']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="middle_name">Middle name (optional)</label>
                    <input type="text" id="middle_name" name="middle_name" value="<?= e($old['middle_name']) ?>" placeholder="Leave blank if none">
                </div>
                <div class="field <?= isset($errors['last_name']) ? 'has-error' : '' ?>">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" value="<?= e($old['last_name']) ?>" required>
                    <?php if (isset($errors['last_name'])): ?><span class="error"><?= e($errors['last_name']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="birth_date">Birth date</label>
                    <input type="date" id="birth_date" name="birth_date" value="<?= e($old['birth_date']) ?>">
                </div>
                <div class="field">
                    <label for="grade_level">Grade level</label>
                    <select id="grade_level" name="grade_level">
                        <?php foreach (grade_levels() as $g): ?>
                            <option value="<?= e($g) ?>" <?= $old['grade_level'] === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['grade_level'])): ?><span class="error"><?= e($errors['grade_level']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="section">Section</label>
                    <input type="text" id="section" name="section" value="<?= e($old['section']) ?>" placeholder="e.g. Sampaguita">
                </div>
                <div class="field">
                    <label for="strand">Track / strand</label>
                    <select id="strand" name="strand">
                        <?php foreach (strands() as $st): ?>
                            <option value="<?= e($st) ?>" <?= $old['strand'] === $st ? 'selected' : '' ?>><?= $st === '' ? 'Junior high (no strand)' : e($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['strand'])): ?><span class="error"><?= e($errors['strand']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="status">Record status</label>
                    <select id="status" name="status">
                        <?php foreach (student_statuses() as $st): ?>
                            <option value="<?= e($st) ?>" <?= $old['status'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field span-2">
                    <label for="previous_school">Previous school</label>
                    <input type="text" id="previous_school" name="previous_school" value="<?= e($old['previous_school']) ?>" placeholder="School that should forward the SF10">
                </div>
                <div class="field">
                    <label for="guardian_name">Parent / guardian</label>
                    <input type="text" id="guardian_name" name="guardian_name" value="<?= e($old['guardian_name']) ?>">
                </div>
                <div class="field">
                    <label for="guardian_contact">Contact number</label>
                    <input type="text" id="guardian_contact" name="guardian_contact" value="<?= e($old['guardian_contact']) ?>" placeholder="e.g. 0917 123 4567">
                </div>
                <div class="field span-2">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" value="<?= e($old['address']) ?>" placeholder="Barangay, City, Province">
                </div>
                <div class="field-set">
                    <?= icon('cabinet') ?>
                    <span>Pick from the storage registry. The cabinet matches the grade level, the shelf is the section, and the folder belongs to one student. <a href="/cabinets.php" target="_blank" rel="noopener">Add cabinet, shelf, or folder</a>.</span>
                </div>
                <div class="field">
                    <label for="cabinet">Cabinet (grade level)</label>
                    <select id="cabinet" name="cabinet" data-storage-cabinet>
                        <option value="">— Not filed —</option>
                        <?php foreach ($storageCabinets as $cab): ?>
                            <option value="<?= e($cab['code']) ?>"
                                data-grade="<?= e((string) ($cab['grade_level'] ?? '')) ?>"
                                <?= $old['cabinet'] === $cab['code'] ? 'selected' : '' ?>>
                                <?= e($cab['code'] . (trim((string) ($cab['grade_level'] ?? '')) !== '' ? ' · ' . $cab['grade_level'] : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="shelf">Shelf (section)</label>
                    <select id="shelf" name="shelf" data-storage-shelf>
                        <option value="">— Select cabinet —</option>
                        <?php foreach ($storageShelves as $shelf): ?>
                            <option value="<?= e($shelf['code']) ?>"
                                data-cabinet="<?= e($shelf['cabinet_code']) ?>"
                                data-section="<?= e((string) ($shelf['section'] ?? '')) ?>"
                                data-grade="<?= e((string) ($shelf['grade_level'] ?? '')) ?>"
                                data-strand="<?= e((string) ($shelf['strand'] ?? '')) ?>"
                                <?= $old['cabinet'] === $shelf['cabinet_code'] && $old['shelf'] === $shelf['code'] ? 'selected' : '' ?>>
                                <?= e($shelf['code'] . ($shelf['section'] ? ' — ' . $shelf['section'] : ' — unreserved')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="folder_no">Folder (student)</label>
                    <select id="folder_no" name="folder_no" data-storage-folder>
                        <option value="">— Select shelf —</option>
                        <?php foreach ($storageFolders as $folder):
                            $occupant = trim((string) ($folder['occupant'] ?? ''));
                            $isCurrent = $old['cabinet'] === $folder['cabinet_code'] && $old['shelf'] === $folder['shelf_code'] && $old['folder_no'] === $folder['number'];
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
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" type="button" data-dialog-close>Cancel</button>
            <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Add to index</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php
app_footer();
