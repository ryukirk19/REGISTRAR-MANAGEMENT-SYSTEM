<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$pdo = db();
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

$students = $pdo->query(
    "SELECT s.*,
        (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status = 'Complete') AS cred_done,
        (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status <> 'N/A') AS cred_total
     FROM students s ORDER BY s.last_name, s.first_name"
)->fetchAll();

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
        <p class="page-sub">Completeness, request volume, and follow-up exposure for school monitoring.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/reports_export.php?type=students"><?= icon('download') ?> Students CSV</a>
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
                                <span class="strong"><?= e(full_name($s)) ?></span>
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
