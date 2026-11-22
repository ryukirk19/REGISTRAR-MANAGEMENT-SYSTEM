<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_manager();

$pdo = db();
$grade = trim((string) ($_GET['grade'] ?? ''));
$section = trim((string) ($_GET['section'] ?? ''));
$strand = trim((string) ($_GET['strand'] ?? ''));
$activeSy = setting('school_year', '');

if (!in_array($grade, grade_levels(), true) || $section === '' || strlen($section) > 60 || strlen($strand) > 40) {
    http_response_code(422);
    open_page('Class roster unavailable', 'roster-page', ['assets/css/app.css']);
    echo '<div class="print-bar no-print"><a class="btn-link" href="/reports.php#rosters">‹ Back to reports</a></div>';
    echo '<div class="card" style="max-width:820px; margin:0 auto; padding:34px">'
        . '<h1 class="section-title">Roster unavailable</h1>'
        . '<p class="section-sub">Choose a grade level and section from the Reports page to print a class roster.</p>'
        . '</div>';
    close_page();
    exit;
}

$adviserKey = 'adviser::' . $grade . '::' . $section;
$self = '/print_roster.php?' . http_build_query(['grade' => $grade, 'section' => $section, 'strand' => $strand]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $adviserName = trim((string) ($_POST['adviser'] ?? ''));
    if (mb_strlen($adviserName) > 120) {
        $adviserName = mb_substr($adviserName, 0, 120);
    }
    set_setting($adviserKey, $adviserName);
    log_activity('Class adviser updated', $grade . ' · ' . $section . ' — ' . ($adviserName !== '' ? $adviserName : 'cleared'));
    redirect($self . '&saved=1');
}

$sql = 'SELECT * FROM students WHERE grade_level = :grade AND section = :section';
$params = [':grade' => $grade, ':section' => $section];
if ($strand !== '') {
    $sql .= ' AND COALESCE(strand, \'\') = :strand';
    $params[':strand'] = $strand;
}
if ($activeSy !== '') {
    $sql .= ' AND school_year = :sy';
    $params[':sy'] = $activeSy;
}
$sql .= ' ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE, middle_name COLLATE NOCASE';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rosterStudents = $stmt->fetchAll();

$adviser = setting($adviserKey, '');
$school = setting('school_name');
$address = setting('school_address');

open_page('Class roster · ' . $grade . ' · ' . $section, 'roster-page', ['assets/css/app.css'], isset($_GET['print']) ? 'data-autoprint="1"' : '');
?>
<div class="print-bar no-print">
    <div>
        <a class="btn-link" href="/reports.php#rosters">‹ Back to reports</a>
        <span style="margin-left:14px; font-size:12px; color:var(--muted)">Print on A4 · portrait</span>
    </div>
    <div style="display:flex; gap:8px">
        <button class="btn btn-primary" type="button" data-print><?= icon('print') ?> Print roster</button>
    </div>
</div>

<form class="roster-adviser no-print" method="post" action="<?= e($self) ?>">
    <?= csrf_field() ?>
    <label for="adviser">Class adviser</label>
    <input id="adviser" name="adviser" value="<?= e($adviser) ?>" maxlength="120" placeholder="Adviser's full name">
    <button class="btn btn-sm btn-primary" type="submit">Save adviser</button>
    <?php if (isset($_GET['saved'])): ?><span class="pill pill-ok"><span class="pill-dot"></span>Saved</span><?php endif; ?>
</form>

<div class="roster-sheet">
    <header class="roster-head">
        <div class="roster-seal"><?= logo_mark() ?></div>
        <div>
            <p class="roster-republic">Republic of the Philippines · Department of Education</p>
            <h1><?= e(strtoupper($school)) ?></h1>
            <p><?= e($address) ?></p>
            <p class="roster-office">Office of the Registrar</p>
        </div>
    </header>

    <h2 class="roster-title">Class Roster</h2>
    <p class="roster-sub">School Year <?= e($activeSy ?: '—') ?></p>

    <dl class="roster-meta">
        <div><dt>Grade level</dt><dd><?= e($grade) ?></dd></div>
        <?php if ($strand !== ''): ?><div><dt>Track / strand</dt><dd><?= e($strand) ?></dd></div><?php endif; ?>
        <div><dt>Section</dt><dd><?= e($section) ?></dd></div>
        <div><dt>Class adviser</dt><dd><?= e($adviser !== '' ? $adviser : '—') ?></dd></div>
        <div><dt>Total learners</dt><dd><?= count($rosterStudents) ?></dd></div>
    </dl>

    <?php if ($rosterStudents): ?>
        <table class="roster-table">
            <thead>
            <tr>
                <th class="num">#</th>
                <th>LRN</th>
                <th>Learner's name</th>
                <th>Sex</th>
                <th>Birth date</th>
                <th class="num">Age</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rosterStudents as $i => $s): ?>
                <tr>
                    <td class="num"><?= $i + 1 ?></td>
                    <td class="mono"><?= e($s['lrn']) ?></td>
                    <td class="name"><?= e(full_name($s)) ?></td>
                    <td><?= e($s['sex']) ?></td>
                    <td><?= e(fmt_date($s['birth_date'])) ?></td>
                    <td class="num"><?= e(age_from($s['birth_date'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty" style="padding:34px">
            <p>No learners are enrolled in this section for <?= e($activeSy ?: 'any school year') ?>.</p>
        </div>
    <?php endif; ?>

    <div class="roster-signs">
        <div class="roster-sign">
            <div class="line"></div>
            <strong><?= e($adviser !== '' ? $adviser : 'Class Adviser') ?></strong>
            <span>Class Adviser</span>
        </div>
        <div class="roster-sign">
            <div class="line"></div>
            <strong><?= e(setting('registrar_name')) ?></strong>
            <span><?= e(setting('registrar_title')) ?></span>
        </div>
    </div>

    <p class="roster-foot">
        <?= count($rosterStudents) ?> learner<?= count($rosterStudents) === 1 ? '' : 's' ?> ·
        Generated <?= e(date('F j, Y g:i A')) ?> via REGIS+ · Verify against the registrar's record index.
    </p>
</div>
<?php
close_page();
