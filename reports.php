<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_manager();

$pdo = db();
$activeSy = setting('school_year', '');
$from = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-90 days')));
$to = (string) ($_GET['to'] ?? today());
$grade = (string) ($_GET['grade'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime('-90 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = today();
}
$gradeSql = in_array($grade, grade_levels(), true) ? ' AND s.grade_level = :grade' : '';
$rangeParams = [':from' => $from, ':to' => $to];
if ($gradeSql) {
    $rangeParams[':grade'] = $grade;
}

$studentSql =
    "SELECT s.*,
        (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status = 'Complete') AS cred_done,
        (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status <> 'N/A') AS cred_total
     FROM students s";
$studentParams = [];
if ($activeSy !== '') {
    $studentSql .= ' WHERE s.school_year = :sy';
    $studentParams[':sy'] = $activeSy;
}
$studentSql .= ' ORDER BY s.last_name COLLATE NOCASE, s.first_name COLLATE NOCASE';
$studentStmt = $pdo->prepare($studentSql);
$studentStmt->execute($studentParams);
$students = $studentStmt->fetchAll();

$byGrade = [];
foreach (grade_levels() as $g) {
    $byGrade[$g] = ['done' => 0, 'total' => 0, 'students' => 0, 'incomplete' => 0];
}
$missingDocs = [];
foreach ($students as $s) {
    $comp = completeness($s);
    $g = $s['grade_level'];
    if (!isset($byGrade[$g])) {
        continue;
    }
    $byGrade[$g]['done'] += $comp['done'];
    $byGrade[$g]['total'] += $comp['total'];
    $byGrade[$g]['students']++;
    if ($comp['incomplete'] > 0) {
        $byGrade[$g]['incomplete']++;
        $missingDocs[] = $s;
    }
}
usort($missingDocs, fn ($a, $b) => ((int) $b['cred_total'] - (int) $b['cred_done']) <=> ((int) $a['cred_total'] - (int) $a['cred_done']));

$strandOrder = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL'];
$byStrand = array_fill_keys($strandOrder, 0);
$otherStrands = [];
foreach ($students as $s) {
    $strandName = trim((string) $s['strand']);
    if ($strandName === '') {
        continue;
    }
    if (isset($byStrand[$strandName])) {
        $byStrand[$strandName]++;
    } else {
        $otherStrands[$strandName] = ($otherStrands[$strandName] ?? 0) + 1;
    }
}
$shsTotal = array_sum($byStrand) + array_sum($otherStrands);

$rosterSql = "SELECT s.grade_level, COALESCE(s.strand, '') AS strand, s.section, COUNT(*) AS n
    FROM students s
    WHERE s.section IS NOT NULL AND s.section <> ''";
$rosterParams = [];
if ($activeSy !== '') {
    $rosterSql .= ' AND s.school_year = :sy';
    $rosterParams[':sy'] = $activeSy;
}
$rosterSql .= " GROUP BY s.grade_level, COALESCE(s.strand, ''), s.section
    ORDER BY CAST(REPLACE(s.grade_level, 'Grade ', '') AS INTEGER) ASC,
        CASE WHEN s.strand IS NULL OR s.strand = '' THEN 0
            WHEN s.strand = 'STEM' THEN 1
            WHEN s.strand = 'ABM' THEN 2
            WHEN s.strand = 'HUMSS' THEN 3
            WHEN s.strand = 'GAS' THEN 4
            WHEN s.strand = 'TVL' THEN 5
            ELSE 6 END ASC,
        COALESCE(s.strand, '') COLLATE NOCASE ASC,
        s.section COLLATE NOCASE ASC";
$rosterStmt = $pdo->prepare($rosterSql);
$rosterStmt->execute($rosterParams);
$rosters = $rosterStmt->fetchAll();

$reqStmt = $pdo->prepare(
    "SELECT r.*, s.first_name, s.last_name, s.record_code, s.grade_level
     FROM requests r JOIN students s ON s.id = r.student_id
     WHERE r.date_requested BETWEEN :from AND :to $gradeSql
     ORDER BY r.date_requested"
);
$reqStmt->execute($rangeParams);
$reqRows = $reqStmt->fetchAll();

$reqByStatus = array_fill_keys(request_statuses(), 0);
$reqByType = [];
$reqByMonth = [];
foreach ($reqRows as $r) {
    $reqByStatus[$r['status']] = ($reqByStatus[$r['status']] ?? 0) + 1;
    $reqByType[$r['document_type']] = ($reqByType[$r['document_type']] ?? 0) + 1;
    $ym = substr($r['date_requested'], 0, 7);
    $reqByMonth[$ym] = ($reqByMonth[$ym] ?? 0) + 1;
}
ksort($reqByMonth);
$maxMonth = max(1, $reqByMonth ? max($reqByMonth) : 1);

$releasedInRange = count(array_filter($reqRows, fn ($r) => $r['status'] === 'Released'));
$openFollowups = (int) $pdo->query("SELECT COUNT(*) FROM followups WHERE status <> 'Resolved'")->fetchColumn();
$overdueFollowups = (int) $pdo->query("SELECT COUNT(*) FROM followups WHERE status <> 'Resolved' AND next_followup_date IS NOT NULL AND next_followup_date < date('now','localtime')")->fetchColumn();
$certsInRange = (int) $pdo->query("SELECT COUNT(*) FROM certifications WHERE date_issued BETWEEN '" . $from . "' AND '" . $to . "'")->fetchColumn();

$totalDone = array_sum(array_column($byGrade, 'done'));
$totalSlots = array_sum(array_column($byGrade, 'total'));
$overallPct = $totalSlots ? (int) round($totalDone / $totalSlots * 100) : 0;

$exportQuery = http_build_query(['from' => $from, 'to' => $to, 'grade' => $grade]);

app_header('Reports', 'reports.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Reports, monitoring &amp; data privacy</div>
        <h1>Registrar reports</h1>
        <p class="page-sub">Enrollment, completeness, and request volume for <strong><?= e($activeSy ?: 'all school years') ?></strong> — <?= count($students) ?> enrolled learners.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/reports_export.php?type=students"><?= icon('download') ?> Roster CSV</a>
        <a class="btn btn-ghost" href="/reports_export.php?type=requests&<?= e($exportQuery) ?>"><?= icon('download') ?> Requests CSV</a>
        <a class="btn btn-primary" href="/reports_export.php?type=followups"><?= icon('download') ?> Follow-ups CSV</a>
    </div>
</div>

<form class="card" method="get" action="/reports.php" style="margin-bottom:18px">
    <div class="toolbar">
        <div class="field" style="flex-direction:row; align-items:center; gap:8px">
            <label for="from" style="font-size:12.2px">From</label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>" style="height:36px; border:1px solid var(--line-2); border-radius:9px; padding:0 10px; font:inherit; font-size:13px">
        </div>
        <div class="field" style="flex-direction:row; align-items:center; gap:8px">
            <label for="to" style="font-size:12.2px">To</label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>" style="height:36px; border:1px solid var(--line-2); border-radius:9px; padding:0 10px; font:inherit; font-size:13px">
        </div>
        <select class="select-inline" name="grade">
            <option value="">All grade levels</option>
            <?php foreach (grade_levels() as $g): ?>
                <option value="<?= e($g) ?>" <?= $grade === $g ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('reports') ?> Apply range</button>
        <span class="spacer"></span>
        <span class="pill pill-muted"><span class="pill-dot"></span>Requests <?= e(fmt_date($from, 'M j')) ?> – <?= e(fmt_date($to, 'M j, Y')) ?></span>
    </div>
</form>

<div class="grid kpi-grid" style="margin-bottom:18px">
    <div class="kpi">
        <div class="kpi-top"><span class="kpi-label">Checklist completeness</span><span class="kpi-icon"><?= icon('check') ?></span></div>
        <div class="kpi-value"><?= $overallPct ?><small>%</small></div>
        <div class="kpi-foot"><?= $totalDone ?> of <?= $totalSlots ?> credential slots complete</div>
    </div>
    <div class="kpi tone-info">
        <div class="kpi-top"><span class="kpi-label">Requests in range</span><span class="kpi-icon"><?= icon('requests') ?></span></div>
        <div class="kpi-value"><?= count($reqRows) ?></div>
        <div class="kpi-foot"><?= $releasedInRange ?> released · <?= $reqByStatus['Pending'] ?> still pending</div>
    </div>
    <div class="kpi tone-accent">
        <div class="kpi-top"><span class="kpi-label">Certificates issued</span><span class="kpi-icon"><?= icon('cert') ?></span></div>
        <div class="kpi-value"><?= $certsInRange ?></div>
        <div class="kpi-foot">Within the selected date range</div>
    </div>
    <div class="kpi tone-warn">
        <div class="kpi-top"><span class="kpi-label">Follow-ups open</span><span class="kpi-icon"><?= icon('followup') ?></span></div>
        <div class="kpi-value"><?= $openFollowups ?></div>
        <div class="kpi-foot"><span class="tag-down"><?= $overdueFollowups ?></span> past the next-action date</div>
    </div>
</div>

<div class="grid split-2" style="margin-bottom:18px">
    <section class="card">
        <div class="card-head bordered">
            <div>
                <h2 class="section-title">Enrollment by grade level</h2>
                <p class="section-sub"><?= e($activeSy ?: 'All years') ?> · <?= count($students) ?> learners · limit <?= number_format(GRADE_ENROLLMENT_LIMIT) ?> per grade</p>
            </div>
            <?php if ($activeSy !== ''): ?><span class="pill pill-ok"><span class="pill-dot"></span>Active SY</span><?php endif; ?>
        </div>
        <div style="padding:18px 22px 8px">
            <?php foreach ($byGrade as $g => $d): $pct = min(100, (int) round($d['students'] / GRADE_ENROLLMENT_LIMIT * 100)); ?>
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:11px">
                    <span style="width:64px; font-size:12.4px; font-weight:600"><?= e($g) ?></span>
                    <div class="meter-track" style="flex:1"><div class="meter-fill <?= $pct >= 95 ? 'danger' : ($pct >= 80 ? 'warn' : '') ?>" style="width:<?= $pct ?>%"></div></div>
                    <span style="width:74px; text-align:right; font-size:12.2px; font-weight:600"><?= $d['students'] ?><span style="color:var(--muted); font-weight:500"> / <?= number_format(GRADE_ENROLLMENT_LIMIT) ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-head bordered">
            <div>
                <h2 class="section-title">Senior high by strand</h2>
                <p class="section-sub">Grade 11–12 learners · limit <?= number_format(STRAND_ENROLLMENT_LIMIT) ?> per strand</p>
            </div>
            <span class="pill pill-muted"><span class="pill-dot"></span><?= $shsTotal ?> SHS</span>
        </div>
        <div style="padding:18px 22px 8px">
            <?php if (!$shsTotal): ?>
                <div class="empty" style="padding:26px"><p>No senior high enrollments for this school year.</p></div>
            <?php else: ?>
                <?php foreach ($byStrand as $st => $n): $pct = min(100, (int) round($n / STRAND_ENROLLMENT_LIMIT * 100)); ?>
                    <div style="display:flex; align-items:center; gap:12px; margin-bottom:11px">
                        <span style="width:84px; font-size:12.4px; font-weight:600"><?= e($st) ?></span>
                        <div class="meter-track" style="flex:1"><div class="meter-fill <?= $pct >= 95 ? 'danger' : ($pct >= 80 ? 'warn' : '') ?>" style="width:<?= $pct ?>%"></div></div>
                        <span style="width:74px; text-align:right; font-size:12.2px; font-weight:600"><?= $n ?><span style="color:var(--muted); font-weight:500"> / <?= number_format(STRAND_ENROLLMENT_LIMIT) ?></span></span>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($otherStrands as $st => $n): $pct = min(100, (int) round($n / STRAND_ENROLLMENT_LIMIT * 100)); ?>
                    <div style="display:flex; align-items:center; gap:12px; margin-bottom:11px">
                        <span style="width:84px; font-size:12.4px; font-weight:600" title="<?= e($st) ?>"><?= e($st) ?></span>
                        <div class="meter-track" style="flex:1"><div class="meter-fill warn" style="width:<?= $pct ?>%"></div></div>
                        <span style="width:74px; text-align:right; font-size:12.2px; font-weight:600"><?= $n ?><span style="color:var(--muted); font-weight:500"> / <?= number_format(STRAND_ENROLLMENT_LIMIT) ?></span></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <p class="section-sub" style="padding:0 22px 16px">Grades 7–10 are junior high and do not carry a strand.</p>
    </section>
</div>

<div class="grid split-2" style="margin-bottom:18px">
    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="section-title">Request volume</h2>
                <p class="section-sub">Requests logged per month in the selected range</p>
            </div>
        </div>
        <div style="padding: 6px 22px 20px">
            <?php if ($reqByMonth): ?>
                <div class="bars">
                    <?php foreach ($reqByMonth as $ym => $n): ?>
                        <div class="bar-col" title="<?= e($ym) ?>: <?= $n ?> requests">
                            <span class="bar-val"><?= $n ?></span>
                            <div class="bar" style="height: <?= max(6, (int) round($n / $maxMonth * 118)) ?>px"></div>
                            <span class="bar-label"><?= e(date('M', strtotime($ym . '-01'))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty" style="padding:40px"><p>No requests were logged in this range.</p></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="section-title">Requests by document</h2>
                <p class="section-sub">Most requested documents in the range</p>
            </div>
        </div>
        <div style="padding: 2px 22px 20px">
            <?php if ($reqByType): arsort($reqByType); $maxType = max($reqByType); foreach ($reqByType as $t => $n): ?>
                <div style="margin-bottom:12px">
                    <div style="display:flex; justify-content:space-between; font-size:12.6px; margin-bottom:5px; color:var(--muted)">
                        <span><?= e($t) ?></span><span style="font-weight:600; color:var(--text)"><?= $n ?></span>
                    </div>
                    <div class="meter-track"><div class="meter-fill" style="width: <?= (int) round($n / $maxType * 100) ?>%"></div></div>
                </div>
            <?php endforeach; else: ?>
                <div class="empty" style="padding:40px"><p>No request data yet.</p></div>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="card" id="rosters" style="margin-bottom:18px">
    <div class="card-head bordered">
        <div>
            <h2 class="section-title">Class rosters</h2>
            <p class="section-sub">One printable roster per section — <?= count($rosters) ?> sections for <?= e($activeSy ?: 'all school years') ?></p>
        </div>
        <span class="pill pill-muted"><span class="pill-dot"></span><?= count($students) ?> learners</span>
    </div>
    <?php if ($rosters): ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr><th style="width:60px">#</th><th>Grade</th><th>Strand</th><th>Section</th><th>Students</th><th class="right">Roster</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rosters as $i => $r):
                    $isShs = in_array($r['grade_level'], ['Grade 11', 'Grade 12'], true);
                    $rosterLink = '/print_roster.php?' . http_build_query([
                        'grade' => $r['grade_level'],
                        'section' => $r['section'],
                        'strand' => $isShs ? (string) $r['strand'] : '',
                    ]);
                ?>
                    <tr>
                        <td class="num"><?= $i + 1 ?></td>
                        <td class="strong"><?= e($r['grade_level']) ?></td>
                        <td><?= $r['strand'] !== '' ? '<span class="pill pill-muted">' . e($r['strand']) . '</span>' : '<span class="sub">—</span>' ?></td>
                        <td><?= e($r['section']) ?></td>
                        <td class="num"><?= (int) $r['n'] ?></td>
                        <td class="actions">
                            <a class="btn btn-sm btn-ghost" href="<?= e($rosterLink) ?>" target="_blank" rel="noopener"><?= icon('print') ?> Print roster</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty" style="padding:40px">
            <p>No sections have been recorded for this school year.</p>
        </div>
    <?php endif; ?>
</section>

<div class="grid split-2">
    <section class="card">
        <div class="card-head bordered">
            <div>
                <h2 class="section-title">Completeness by grade</h2>
                <p class="section-sub">Credential checklist coverage</p>
            </div>
            <a class="btn btn-sm btn-ghost" href="/reports_export.php?type=students"><?= icon('download') ?> Export</a>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Grade</th><th>Students</th><th>Complete</th><th>Incomplete</th><th style="width:34%">Coverage</th></tr></thead>
                <tbody>
                <?php foreach ($byGrade as $g => $d):
                    $pct = $d['total'] ? (int) round($d['done'] / $d['total'] * 100) : 0;
                ?>
                    <tr>
                        <td class="strong"><?= e($g) ?></td>
                        <td class="num"><?= $d['students'] ?></td>
                        <td class="num"><?= $d['students'] - $d['incomplete'] ?></td>
                        <td class="num"><?= $d['incomplete'] ?></td>
                        <td>
                            <div class="meter">
                                <div class="meter-track"><div class="meter-fill <?= $pct >= 90 ? '' : ($pct >= 70 ? 'warn' : 'danger') ?>" style="width: <?= $pct ?>%"></div></div>
                                <span class="meter-label"><?= $pct ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head bordered">
            <div>
                <h2 class="section-title">Records requiring follow-up</h2>
                <p class="section-sub">Incomplete files, ordered by missing credentials</p>
            </div>
            <span class="pill pill-warn"><span class="pill-dot"></span><?= count($missingDocs) ?> records</span>
        </div>
        <?php if ($missingDocs): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Student</th><th>Grade</th><th>Missing</th><th class="right"></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($missingDocs, 0, 8) as $s):
                        $comp = completeness($s);
                    ?>
                        <tr>
                            <td>
                                <span class="strong"><?= e(student_name($s)) ?></span>
                                <span class="sub mono"><?= e($s['record_code']) ?></span>
                            </td>
                            <td><?= e($s['grade_level']) ?></td>
                            <td><span class="pill pill-danger"><span class="pill-dot"></span><?= $comp['incomplete'] ?> missing</span></td>
                            <td class="actions"><a class="btn btn-sm btn-ghost" href="/student.php?id=<?= (int) $s['id'] ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty" style="padding:40px">
                <span class="empty-mark"><?= icon('check') ?></span>
                <strong>All files are complete</strong>
                <p>No credential gaps are currently recorded.</p>
            </div>
        <?php endif; ?>
    </section>
</div>

<p class="section-sub" style="margin-top:18px; display:flex; gap:8px; align-items:center">
    <?= icon('shield') ?> Exports contain confidential student data. Share only through official channels and log any disclosure with the registrar.
</p>
<?php
app_footer();
