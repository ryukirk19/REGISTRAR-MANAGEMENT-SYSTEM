<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_manager();

const IMPORT_ROW_LIMIT = 500;
const IMPORT_MAX_BYTES = 1048576;

function import_columns(): array
{
    return [
        'lrn' => 'LRN',
        'last_name' => 'Last name',
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'sex' => 'Sex',
        'birth_date' => 'Birth date',
        'grade_level' => 'Grade level',
        'section' => 'Section',
        'strand' => 'Strand',
        'school_year' => 'School year',
        'status' => 'Status',
        'previous_school' => 'Previous school',
        'guardian_name' => 'Guardian name',
        'guardian_contact' => 'Guardian contact',
        'address' => 'Address',
        'cabinet' => 'Cabinet',
        'shelf' => 'Shelf',
        'folder_no' => 'Folder',
    ];
}

function import_key(string $header): string
{
    $header = str_replace("\xEF\xBB\xBF", '', trim($header));
    return (string) preg_replace('/[^a-z0-9]/', '', strtolower($header));
}

function import_header_map(): array
{
    $aliases = [
        'lrn' => ['lrn', 'lrnno', 'learnerreferencenumber', 'learnerreference'],
        'last_name' => ['lastname', 'surname', 'familyname'],
        'first_name' => ['firstname', 'givenname'],
        'middle_name' => ['middlename'],
        'sex' => ['sex', 'gender'],
        'birth_date' => ['birthdate', 'dateofbirth', 'dob'],
        'grade_level' => ['gradelevel', 'grade', 'yearlevel'],
        'section' => ['section'],
        'strand' => ['strand', 'track', 'trackstrand'],
        'school_year' => ['schoolyear', 'sy'],
        'status' => ['status', 'recordstatus'],
        'previous_school' => ['previousschool', 'schoollastattended'],
        'guardian_name' => ['guardianname', 'parentguardian', 'guardian'],
        'guardian_contact' => ['guardiancontact', 'contactnumber', 'contact'],
        'address' => ['address', 'homeaddress'],
        'cabinet' => ['cabinet'],
        'shelf' => ['shelf'],
        'folder_no' => ['folder', 'folderno', 'foldernumber'],
    ];
    $map = [];
    foreach ($aliases as $canonical => $list) {
        foreach ($list as $alias) {
            $map[$alias] = $canonical;
        }
    }
    return $map;
}

function import_fail(string $message): never
{
    unset($_SESSION['import_preview']);
    flash('error', $message);
    redirect('import.php');
}

function import_read_csv(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        import_fail('Choose a CSV file to upload.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        import_fail('The upload did not finish. Please try again.');
    }
    if ((int) $file['size'] > IMPORT_MAX_BYTES) {
        import_fail('The CSV file must be 1 MB or smaller.');
    }
    $name = (string) ($file['name'] ?? '');
    if (!preg_match('/\.(csv|txt)$/i', $name)) {
        import_fail('Upload a .csv file. You can download the template from this page.');
    }

    $content = file_get_contents($file['tmp_name']);
    if ($content === false || trim($content) === '') {
        import_fail('That file is empty.');
    }
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    $firstLine = strtok($content, "\r\n");
    $counts = [
        ',' => substr_count((string) $firstLine, ','),
        ';' => substr_count((string) $firstLine, ';'),
        "\t" => substr_count((string) $firstLine, "\t"),
    ];
    arsort($counts);
    $delimiter = (string) array_key_first($counts);
    if ($counts[$delimiter] === 0) {
        $delimiter = ',';
    }

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $headerRow = null;
    while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        if (array_filter($record, static fn ($v) => trim((string) $v) !== '')) {
            $headerRow = $record;
            break;
        }
    }
    if ($headerRow === null) {
        fclose($handle);
        import_fail('The file has no header row.');
    }

    $alias = import_header_map();
    $columnIndex = [];
    foreach ($headerRow as $index => $label) {
        $canonical = $alias[import_key((string) $label)] ?? null;
        if ($canonical !== null && !isset($columnIndex[$canonical])) {
            $columnIndex[$canonical] = $index;
        }
    }

    $missing = [];
    foreach (['lrn', 'last_name', 'first_name', 'grade_level'] as $required) {
        if (!isset($columnIndex[$required])) {
            $missing[] = import_columns()[$required];
        }
    }
    if ($missing) {
        fclose($handle);
        import_fail('The header is missing: ' . implode(', ', $missing) . '. Use the template to get the correct column names.');
    }

    $rows = [];
    $line = 1;
    while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        $line++;
        $values = [];
        $blank = true;
        foreach (import_columns() as $canonical => $label) {
            $raw = isset($columnIndex[$canonical]) ? (string) ($record[$columnIndex[$canonical]] ?? '') : '';
            $values[$canonical] = trim($raw);
            if ($values[$canonical] !== '') {
                $blank = false;
            }
        }
        if ($blank) {
            continue;
        }
        $extra = array_slice($record, count($headerRow));
        $hasExtra = (bool) array_filter($extra, static fn ($v) => trim((string) $v) !== '');
        $rows[] = ['line' => $line, 'values' => $values, 'extra' => $hasExtra, 'errors' => []];
        if (count($rows) > IMPORT_ROW_LIMIT) {
            fclose($handle);
            import_fail('That file has more than ' . IMPORT_ROW_LIMIT . ' rows. Split it into smaller files.');
        }
    }
    fclose($handle);

    if (!$rows) {
        import_fail('The file has a header but no student rows.');
    }
    return $rows;
}

function import_normalize_grade(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{1,2}$/', $value)) {
        $value = 'Grade ' . (int) $value;
    }
    if (preg_match('/^grade\s*(\d{1,2})$/i', $value, $m)) {
        return 'Grade ' . (int) $m[1];
    }
    return '';
}

function import_normalize_strand(string $value): string
{
    $value = strtoupper(trim($value));
    if (in_array($value, ['', 'N/A', 'NA', 'NONE', 'JUNIOR HIGH'], true)) {
        return '';
    }
    return $value === 'TVL-ICT' ? 'TVL' : $value;
}

function import_normalize_status(string $value): string
{
    foreach (student_statuses() as $status) {
        if (strcasecmp($value, $status) === 0) {
            return $status;
        }
    }
    return $value === '' ? 'Enrolled' : $value;
}

function import_normalize_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    foreach (['Y-m-d', 'm/d/Y', 'n/j/Y'] as $format) {
        $date = DateTime::createFromFormat('!' . $format, $value);
        if ($date && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }
    return $value;
}

function import_normalize_school_year(string $value): string
{
    $value = str_replace(['–', '—'], '-', trim($value));
    if (preg_match('/^(\d{4})\s*-\s*(\d{4})$/', $value, $m)) {
        return $m[1] . '-' . $m[2];
    }
    return $value;
}

function import_storage_context(): array
{
    $cabinets = [];
    foreach (storage_cabinets() as $cabinet) {
        $cabinets[strtoupper($cabinet['code'])] = $cabinet['code'];
    }
    $shelves = [];
    foreach (storage_shelves() as $shelf) {
        $shelves[strtoupper($shelf['cabinet_code']) . '|' . strtoupper($shelf['code'])] = $shelf['code'];
    }
    $folders = [];
    foreach (storage_folders() as $folder) {
        $key = strtoupper($folder['cabinet_code']) . '|' . strtoupper($folder['shelf_code']) . '|' . $folder['number'];
        $folders[$key] = $folder;
    }
    return ['cabinets' => $cabinets, 'shelves' => $shelves, 'folders' => $folders];
}

function import_normalize_row(array $values, array $context, string $defaultSy): array
{
    $values['lrn'] = (string) preg_replace('/[\s-]/', '', $values['lrn']);
    $values['sex'] = strtoupper(substr($values['sex'], 0, 1));
    if (!in_array($values['sex'], ['F', 'M'], true)) {
        $values['sex'] = $values['sex'] === '' ? 'F' : $values['sex'];
    }
    $values['birth_date'] = import_normalize_date($values['birth_date']);
    $values['grade_level'] = import_normalize_grade($values['grade_level']);
    $values['strand'] = import_normalize_strand($values['strand']);
    $values['school_year'] = import_normalize_school_year($values['school_year']) ?: $defaultSy;
    $values['status'] = import_normalize_status($values['status']);
    $values['section'] = (string) preg_replace('/\s+/', ' ', $values['section']);

    $cabinetKey = strtoupper($values['cabinet']);
    if ($cabinetKey !== '' && isset($context['cabinets'][$cabinetKey])) {
        $values['cabinet'] = $context['cabinets'][$cabinetKey];
    }
    if ($values['cabinet'] !== '' && $values['shelf'] !== '') {
        $shelfKey = strtoupper($values['cabinet']) . '|' . strtoupper($values['shelf']);
        if (isset($context['shelves'][$shelfKey])) {
            $values['shelf'] = $context['shelves'][$shelfKey];
        }
    }
    $values['folder_no'] = (string) preg_replace('/^folder\s*/i', '', $values['folder_no']);
    if (ctype_digit($values['folder_no']) && strlen($values['folder_no']) < 3) {
        $values['folder_no'] = str_pad($values['folder_no'], 3, '0', STR_PAD_LEFT);
    }
    return $values;
}

function import_validate_rows(array $rows, string $defaultSy): array
{
    $context = import_storage_context();
    $existingLrn = array_fill_keys(db()->query('SELECT lrn FROM students')->fetchAll(PDO::FETCH_COLUMN), true);

    $gradeCounts = [];
    foreach (db()->query('SELECT school_year, grade_level, COUNT(*) AS n FROM students GROUP BY school_year, grade_level')->fetchAll() as $row) {
        $gradeCounts[$row['school_year'] . '|' . $row['grade_level']] = (int) $row['n'];
    }
    $strandCounts = [];
    foreach (db()->query("SELECT school_year, strand, COUNT(*) AS n FROM students WHERE TRIM(COALESCE(strand, '')) <> '' GROUP BY school_year, strand")->fetchAll() as $row) {
        $strandCounts[$row['school_year'] . '|' . $row['strand']] = (int) $row['n'];
    }
    $folderUsage = [];
    $seenLrn = [];

    foreach ($rows as &$row) {
        $values = import_normalize_row($row['values'], $context, $defaultSy);
        $row['values'] = $values;
        $errors = [];
        if ($row['extra']) {
            $errors[] = 'This row has more values than the header has columns.';
        }

        if (!preg_match('/^\d{12}$/', $values['lrn'])) {
            $errors[] = 'LRN must be exactly 12 digits.';
        } else {
            if (isset($seenLrn[$values['lrn']])) {
                $errors[] = 'LRN ' . $values['lrn'] . ' appears twice in this file (also row ' . $seenLrn[$values['lrn']] . ').';
            } elseif (isset($existingLrn[$values['lrn']])) {
                $errors[] = 'LRN ' . $values['lrn'] . ' is already in the index.';
            }
            $seenLrn[$values['lrn']] = $row['line'];
        }

        if ($values['first_name'] === '') {
            $errors[] = 'First name is required.';
        }
        if ($values['last_name'] === '') {
            $errors[] = 'Last name is required.';
        }
        if (!in_array($values['grade_level'], grade_levels(), true)) {
            $errors[] = 'Grade level must be Grade 7 to Grade 12.';
        }
        if (!in_array($values['sex'], ['F', 'M'], true)) {
            $errors[] = 'Sex must be F or M.';
        }
        if ($values['birth_date'] !== '') {
            $birth = DateTime::createFromFormat('!Y-m-d', $values['birth_date']);
            if (!$birth || $birth->format('Y-m-d') !== $values['birth_date']) {
                $errors[] = 'Birth date must be a real date in YYYY-MM-DD form.';
            }
        }
        if (!in_array($values['strand'], strands(), true)) {
            $errors[] = 'Strand must be STEM, ABM, HUMSS, GAS, or TVL.';
        }
        if (!preg_match('/^\d{4}-\d{4}$/', $values['school_year'])) {
            $errors[] = 'School year must look like 2026-2027.';
        }
        if (!in_array($values['status'], student_statuses(), true)) {
            $errors[] = 'Status must be Enrolled, Transferred Out, Transferred In, Graduated, or Archived.';
        }

        $trio = [$values['cabinet'], $values['shelf'], $values['folder_no']];
        $filled = array_filter($trio, static fn (string $v): bool => $v !== '');
        $folderKey = null;
        if ($filled && count($filled) < 3) {
            $errors[] = 'Choose a cabinet, shelf, and folder together, or leave all three blank.';
        } elseif (count($filled) === 3) {
            $folderKey = strtoupper($values['cabinet']) . '|' . strtoupper($values['shelf']) . '|' . $values['folder_no'];
            if (!isset($context['folders'][$folderKey])) {
                $errors[] = 'That cabinet, shelf, or folder is not in the storage registry.';
                $folderKey = null;
            } else {
                $folder = $context['folders'][$folderKey];
                $used = (int) $folder['record_count'] + ($folderUsage[$folderKey] ?? 0);
                if ($used >= (int) $folder['capacity']) {
                    $errors[] = 'Folder ' . $folder['number'] . ' is already assigned to a student. Choose an empty folder.';
                }
                $shelfSection = trim((string) ($folder['shelf_section'] ?? ''));
                if ($shelfSection === '') {
                    $errors[] = 'Shelf ' . $folder['shelf_code'] . ' is not reserved for a section yet.';
                } elseif ($values['section'] !== $shelfSection) {
                    $errors[] = 'Shelf ' . $folder['shelf_code'] . ' is reserved for ' . $shelfSection . '.';
                }
                $cabinetGrade = trim((string) ($folder['cabinet_grade'] ?? ''));
                if ($cabinetGrade === '') {
                    $errors[] = 'Cabinet ' . $folder['cabinet_code'] . ' has no grade level set.';
                } elseif ($values['grade_level'] !== $cabinetGrade) {
                    $errors[] = 'Cabinet ' . $folder['cabinet_code'] . ' is reserved for ' . $cabinetGrade . '.';
                }
            }
        }

        $gradeKey = $values['school_year'] . '|' . $values['grade_level'];
        if (in_array($values['grade_level'], grade_levels(), true) && preg_match('/^\d{4}-\d{4}$/', $values['school_year'])) {
            if (($gradeCounts[$gradeKey] ?? 0) >= GRADE_ENROLLMENT_LIMIT) {
                $errors[] = $values['grade_level'] . ' already has ' . number_format(GRADE_ENROLLMENT_LIMIT) . ' learners for ' . $values['school_year'] . '.';
            }
        }
        $strandKey = $values['school_year'] . '|' . $values['strand'];
        if (in_array($values['grade_level'], ['Grade 11', 'Grade 12'], true) && $values['strand'] !== ''
            && in_array($values['strand'], strands(), true) && preg_match('/^\d{4}-\d{4}$/', $values['school_year'])) {
            if (($strandCounts[$strandKey] ?? 0) >= STRAND_ENROLLMENT_LIMIT) {
                $errors[] = $values['strand'] . ' already has ' . number_format(STRAND_ENROLLMENT_LIMIT) . ' learners for ' . $values['school_year'] . '.';
            }
        }

        if (!$errors) {
            $gradeCounts[$gradeKey] = ($gradeCounts[$gradeKey] ?? 0) + 1;
            if (in_array($values['grade_level'], ['Grade 11', 'Grade 12'], true) && $values['strand'] !== '') {
                $strandCounts[$strandKey] = ($strandCounts[$strandKey] ?? 0) + 1;
            }
            if ($folderKey !== null) {
                $folderUsage[$folderKey] = ($folderUsage[$folderKey] ?? 0) + 1;
            }
        }
        $row['errors'] = $errors;
    }
    unset($row);

    return $rows;
}

function import_stats(array $rows): array
{
    $valid = 0;
    foreach ($rows as $row) {
        if (!$row['errors']) {
            $valid++;
        }
    }
    return ['total' => count($rows), 'valid' => $valid, 'invalid' => count($rows) - $valid];
}

$pdo = db();
$preview = null;
$action = (string) ($_POST['action'] ?? '');

if (isset($_GET['template'])) {
    export_csv('regis-student-import-template.csv', array_values(import_columns()), []);
}
if (isset($_GET['cancel'])) {
    unset($_SESSION['import_preview']);
    redirect('import.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $defaultSy = setting('school_year', '2026-2027');

    if ($action === 'preview') {
        $rows = import_validate_rows(import_read_csv($_FILES['file'] ?? []), $defaultSy);
        $token = bin2hex(random_bytes(16));
        $_SESSION['import_preview'] = [
            'token' => $token,
            'file' => (string) ($_FILES['file']['name'] ?? 'import.csv'),
            'rows' => $rows,
            'at' => time(),
        ];
        $preview = $_SESSION['import_preview'];
    } elseif ($action === 'import') {
        $saved = $_SESSION['import_preview'] ?? null;
        if (!$saved || !hash_equals((string) $saved['token'], (string) ($_POST['token'] ?? ''))) {
            import_fail('That preview expired. Upload the file again.');
        }
        if (time() - (int) $saved['at'] > 1800) {
            import_fail('That preview is older than 30 minutes. Upload the file again.');
        }

        $rows = import_validate_rows($saved['rows'], $defaultSy);
        $stats = import_stats($rows);
        if ($stats['invalid'] > 0) {
            $saved['rows'] = $rows;
            $_SESSION['import_preview'] = $saved;
            $preview = $saved;
            flash('error', 'Nothing was imported. The index changed or the file no longer passes validation.');
        } else {
            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare(
                    'INSERT INTO students (lrn, first_name, middle_name, last_name, sex, birth_date, grade_level, section, strand, school_year, status, guardian_name, guardian_contact, address, previous_school, cabinet, shelf, folder_no, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $setCode = $pdo->prepare('UPDATE students SET record_code = ? WHERE id = ?');
                $insertCred = $pdo->prepare("INSERT INTO credentials (student_id, doc_type, status, remarks) VALUES (?, ?, 'Missing', '')");
                $codeYear = setting('school_year', '2026') === '' ? date('Y') : substr(setting('school_year', '2026'), 0, 4);
                $codes = [];

                foreach ($rows as $row) {
                    $v = $row['values'];
                    $insert->execute([
                        $v['lrn'], $v['first_name'], $v['middle_name'], $v['last_name'], $v['sex'],
                        $v['birth_date'] ?: null, $v['grade_level'], $v['section'], $v['strand'],
                        $v['school_year'], $v['status'], $v['guardian_name'], $v['guardian_contact'],
                        $v['address'], $v['previous_school'], $v['cabinet'], $v['shelf'],
                        $v['folder_no'], current_user()['id'],
                    ]);
                    $studentId = (int) $pdo->lastInsertId();
                    $code = sprintf('REC-%s-%04d', $codeYear, $studentId);
                    $setCode->execute([$code, $studentId]);
                    foreach (array_keys(credential_docs()) as $doc) {
                        $insertCred->execute([$studentId, $doc]);
                    }
                    $codes[] = $code;
                }
                log_activity(
                    'Student records imported',
                    count($rows) . ' records imported from ' . $saved['file'] . ' (' . $codes[0] . ' to ' . end($codes) . ')'
                );
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $saved['rows'] = $rows;
                $_SESSION['import_preview'] = $saved;
                $preview = $saved;
                flash('error', 'The import was rolled back. Nothing was saved. ' . $ex->getMessage());
            }

            if ($preview === null) {
                unset($_SESSION['import_preview']);
                flash('success', count($rows) . ' records were imported. Each one starts with a blank credential checklist.');
                redirect('students.php');
            }
        }
    }
}

if ($preview === null && isset($_SESSION['import_preview']) && $action === '') {
    unset($_SESSION['import_preview']);
}

$stats = $preview ? import_stats($preview['rows']) : ['total' => 0, 'valid' => 0, 'invalid' => 0];
$columns = import_columns();

app_header('Import student records', 'students.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Student record index &amp; locator</div>
        <h1>Import student records</h1>
        <p class="page-sub">Add records from a CSV list. Every row is checked before anything is saved, and each new record starts with a blank credential checklist.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/students.php"><?= icon('arrow-right') ?> Back to records</a>
    </div>
</div>

<?php if (!$preview): ?>
<section class="card card-pad" style="max-width:820px">
    <form method="post" action="/import.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preview">
        <div class="field" style="margin-bottom:16px">
            <label for="file">CSV file</label>
            <input type="file" id="file" name="file" accept=".csv,text/csv" required>
            <span class="sub">Up to <?= IMPORT_ROW_LIMIT ?> rows or 1 MB. The first row must contain the column names.</span>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('upload') ?> Check file</button>
            <a class="btn btn-ghost" href="/import.php?template=1"><?= icon('download') ?> Download template</a>
        </div>
    </form>
</section>

<section class="card card-pad" style="max-width:820px;margin-top:18px">
    <h2 style="margin-top:0">What the checker enforces</h2>
    <ul style="margin:14px 0 0;padding-left:20px;line-height:1.75">
        <li>LRN must be 12 digits and must not already exist in the index.</li>
        <li>Grade level, strand, status, and school year must match the values the system accepts.</li>
        <li>Enrollment limits stay at <?= number_format(GRADE_ENROLLMENT_LIMIT) ?> per grade and <?= number_format(STRAND_ENROLLMENT_LIMIT) ?> per strand.</li>
        <li>Cabinet, shelf, and folder must exist in the storage registry. The cabinet must match the student's grade level, the shelf must be reserved for the student's section, and each folder takes one student.</li>
        <li>If one row fails, the whole file is held back so you can fix it and upload again.</li>
    </ul>
    <p class="sub" style="margin:12px 0 0">Columns are read in any order. The template shows the expected names: <?= e(implode(', ', array_values($columns))) ?>.</p>
</section>

<?php else: ?>
<section class="card card-pad" style="margin-bottom:18px">
    <div class="toolbar" style="margin:0">
        <span class="strong"><?= e($preview['file']) ?></span>
        <span class="pill pill-muted"><span class="pill-dot"></span><?= $stats['total'] ?> rows</span>
        <?php if ($stats['valid'] > 0): ?>
            <span class="pill pill-ok"><span class="pill-dot"></span><?= $stats['valid'] ?> ready</span>
        <?php endif; ?>
        <?php if ($stats['invalid'] > 0): ?>
            <span class="pill pill-danger"><span class="pill-dot"></span><?= $stats['invalid'] ?> need fixes</span>
        <?php endif; ?>
        <span class="spacer"></span>
        <a class="btn btn-ghost btn-sm" href="/import.php?cancel=1">Discard</a>
    </div>
</section>

<?php if ($stats['invalid'] > 0): ?>
    <div class="alert alert-error"><?= icon('alert') ?><span>Nothing has been saved. Fix the rows marked below, save the file, and upload it again. The preview is only a check.</span></div>
<?php endif; ?>

<section class="card">
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Row</th>
                    <th>Student</th>
                    <th>LRN</th>
                    <th>Level</th>
                    <th>School year</th>
                    <th>File location</th>
                    <th>Issues</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($preview['rows'] as $row): $v = $row['values']; ?>
                <tr>
                    <td class="mono"><?= (int) $row['line'] ?></td>
                    <td>
                        <span class="strong"><?= e(trim($v['first_name'] . ' ' . $v['middle_name'] . ' ' . $v['last_name'])) ?></span>
                        <span class="sub"><?= $v['guardian_name'] !== '' ? 'Guardian: ' . e($v['guardian_name']) : 'No guardian on file' ?></span>
                    </td>
                    <td class="mono"><?= e($v['lrn']) ?></td>
                    <td>
                        <span class="strong" style="font-weight:500"><?= e($v['grade_level'] ?: '—') ?></span>
                        <span class="sub"><?= e($v['section'] !== '' ? $v['section'] : 'No section') ?><?= $v['strand'] !== '' ? ' · ' . e($v['strand']) : '' ?></span>
                    </td>
                    <td class="mono"><?= e($v['school_year']) ?></td>
                    <td>
                        <?php if ($v['cabinet'] !== '' || $v['shelf'] !== '' || $v['folder_no'] !== ''): ?>
                            <span class="mono"><?= e($v['cabinet'] . ' · ' . $v['shelf'] . ' · ' . $v['folder_no']) ?></span>
                        <?php else: ?>
                            <span class="sub">Not filed</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['errors']): ?>
                            <span class="sub" style="color:var(--danger)"><?= implode('<br>', array_map('e', $row['errors'])) ?></span>
                        <?php else: ?>
                            <span class="pill pill-ok"><span class="pill-dot"></span>Ready</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($stats['invalid'] === 0): ?>
<form method="post" action="/import.php" style="margin-top:18px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <input type="hidden" name="token" value="<?= e($preview['token']) ?>">
    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= icon('check') ?> Import <?= $stats['valid'] ?> records</button>
        <a class="btn btn-ghost" href="/import.php?cancel=1">Discard</a>
    </div>
</form>
<?php endif; ?>

<?php endif; ?>
<?php
app_footer();
