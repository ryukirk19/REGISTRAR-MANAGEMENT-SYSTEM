<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$user = current_user();
$pdo = db();

$studentTotal = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
$reqOpen = (int) $pdo->query("SELECT COUNT(*) FROM requests WHERE status IN ('Pending','Processing','Ready for Release')")->fetchColumn();
$followDue = (int) $pdo->query("SELECT COUNT(*) FROM followups WHERE status <> 'Resolved' AND next_followup_date IS NOT NULL AND next_followup_date <= date('now','localtime','+3 day')")->fetchColumn();

$studentStats = $pdo->query(
    "SELECT s.id, s.grade_level, s.first_name, s.middle_name, s.last_name, s.lrn, s.record_code,
            (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status = 'Complete') AS cred_done,
            (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status <> 'N/A') AS cred_total
     FROM students s"
)->fetchAll();

$incomplete = array_values(array_filter($studentStats, fn ($s) => (int) $s['cred_total'] > (int) $s['cred_done']));
$complete = $studentTotal - count($incomplete);
$completePct = $studentTotal ? (int) round($complete / $studentTotal * 100) : 0;

$statusCounts = array_fill_keys(request_statuses(), 0);
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM requests GROUP BY status') as $row) {
    $statusCounts[$row['status']] = (int) $row['n'];
}
$totalRequests = array_sum($statusCounts);

$monthly = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i month"));
    $monthly[$key] = 0;
}
foreach ($pdo->query("SELECT substr(date_requested, 1, 7) AS ym, COUNT(*) AS n FROM requests GROUP BY ym") as $row) {
    if (isset($monthly[$row['ym']])) {
        $monthly[$row['ym']] = (int) $row['n'];
    }
}
$maxMonth = max(1, max($monthly));

$byGrade = [];
foreach (grade_levels() as $g) {
    $byGrade[$g] = ['done' => 0, 'total' => 0];
}
foreach ($studentStats as $s) {
    $g = $s['grade_level'];
    if (isset($byGrade[$g])) {
        $byGrade[$g]['done'] += (int) $s['cred_done'];
        $byGrade[$g]['total'] += (int) $s['cred_total'];
    }
}

$recent = $pdo->query(
    "SELECT r.*, s.first_name, s.middle_name, s.last_name, s.grade_level, s.section, u.full_name AS assigned_name, u.role AS assigned_role
     FROM requests r
     JOIN students s ON s.id = r.student_id
     LEFT JOIN users u ON u.id = r.assigned_to
     ORDER BY r.date_requested DESC, r.id DESC LIMIT 6"
)->fetchAll();

$attention = $incomplete;
usort($attention, fn ($a, $b) => ((int) $b['cred_total'] - (int) $b['cred_done']) <=> ((int) $a['cred_total'] - (int) $a['cred_done']));
$attention = array_slice($attention, 0, 4);

$due = $pdo->query(
    "SELECT f.*, s.first_name, s.middle_name, s.last_name, s.record_code
     FROM followups f JOIN students s ON s.id = f.student_id
     WHERE f.status <> 'Resolved' AND f.next_followup_date IS NOT NULL
     ORDER BY f.next_followup_date ASC LIMIT 4"
)->fetchAll();

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', $user['full_name'])[0];

$donutColors = [
    'Pending' => '#b98f3e',
    'Processing' => '#2b5f8c',
    'Ready for Release' => '#0f7d68',
    'Released' => '#14532d',
    'Cancelled' => '#9aa6aa',
];
$circumference = 2 * M_PI * 60;
$backupAge = is_admin() ? last_backup_age_days() : null;
$backupStale = is_admin() && ($backupAge === null || $backupAge > 7);

app_header('Dashboard', 'dashboard.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Registrar overview</div>
        <h1><?= e($greeting) ?>, <?= e($firstName) ?>.</h1>
        <p class="page-sub"><?= e(date('l, F j, Y')) ?> · <?= e(setting('school_name')) ?> · SY <?= e(setting('school_year')) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/followups.php?new=1"><?= icon('followup') ?> Log follow-up</a>
        <a class="btn btn-primary" href="/requests.php?new=1"><?= icon('plus') ?> New request</a>
    </div>
</div>

<?php if ($backupStale): ?>
<div class="alert alert-warn">
    <?= icon('alert') ?>
    <span>
        <?php if ($backupAge === null): ?>
            No database backup has been recorded yet.
        <?php else: ?>
            The last database backup was <?= $backupAge ?> day<?= $backupAge === 1 ? '' : 's' ?> ago.
        <?php endif; ?>
        Download one from <a href="/settings.php" style="color:inherit;text-decoration:underline">Settings</a> or run <span class="mono">tools\backup.cmd</span>.
    </span>
</div>
<?php endif; ?>

<div class="grid kpi-grid" style="margin-bottom:18px">
    <div class="kpi">
        <div class="kpi-top"><span class="kpi-label">Student records</span><span class="kpi-icon"><?= icon('records') ?></span></div>
        <div class="kpi-value"><?= $studentTotal ?></div>
        <div class="kpi-foot">Indexed across <?= count(grade_levels()) ?> grade levels</div>
    </div>
    <div class="kpi tone-accent">
        <div class="kpi-top"><span class="kpi-label">Files complete</span><span class="kpi-icon"><?= icon('check') ?></span></div>
        <div class="kpi-value"><?= $completePct ?><small>%</small></div>
        <div class="kpi-foot"><span class="tag-<?= count($incomplete) < 10 ? 'up' : 'down' ?>"><?= count($incomplete) ?></span> records still incomplete</div>
    </div>
    <div class="kpi tone-info">
        <div class="kpi-top"><span class="kpi-label">Open requests</span><span class="kpi-icon"><?= icon('requests') ?></span></div>
        <div class="kpi-value"><?= $reqOpen ?></div>
        <div class="kpi-foot">Pending, processing, or ready for release</div>
    </div>
    <div class="kpi tone-warn">
        <div class="kpi-top"><span class="kpi-label">Follow-ups due</span><span class="kpi-icon"><?= icon('followup') ?></span></div>
        <div class="kpi-value"><?= $followDue ?></div>
        <div class="kpi-foot">Due today or within three days</div>
    </div>
</div>

<div class="grid split-2" style="margin-bottom:18px">
    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="section-title">Request pipeline</h2>
                <p class="section-sub">Live status of all <?= $totalRequests ?> logged document requests</p>
            </div>
            <a class="btn btn-sm btn-ghost" href="/requests.php">Open tracker <?= icon('arrow-right') ?></a>
        </div>
        <div class="donut-wrap" style="padding: 6px 22px 20px">
            <div class="donut">
                <svg viewBox="0 0 160 160" role="img" aria-label="Request status distribution">
                    <circle cx="80" cy="80" r="60" stroke="#eeebe2"></circle>
                    <?php
                    $offset = 0.0;
                    foreach ($statusCounts as $status => $count):
                        if ($count === 0) continue;
                        $len = $count / max(1, $totalRequests) * $circumference;
                        $gap = $len > 10 ? 3 : 0;
                    ?>
                        <circle cx="80" cy="80" r="60"
                                stroke="<?= $donutColors[$status] ?? '#9aa6aa' ?>"
                                stroke-dasharray="<?= round($len - $gap, 2) ?> <?= round($circumference - $len + $gap, 2) ?>"
                                stroke-dashoffset="<?= round(-$offset, 2) ?>"></circle>
                    <?php $offset += $len; endforeach; ?>
                </svg>
                <div class="donut-center"><strong><?= $totalRequests ?></strong><span>Requests</span></div>
            </div>
            <div class="legend">
                <?php foreach ($statusCounts as $status => $count): ?>
                    <div class="legend-row">
                        <span class="swatch" style="background: <?= $donutColors[$status] ?>"></span>
                        <?= e($status) ?>
                        <span class="val"><?= $count ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="section-title">Checklist completeness</h2>
                <p class="section-sub">Credential verification by grade level</p>
            </div>
        </div>
        <div style="padding: 2px 22px 20px; display:flex; flex-direction:column; gap:13px">
            <?php foreach ($byGrade as $grade => $data):
                $pct = $data['total'] ? (int) round($data['done'] / $data['total'] * 100) : 0;
                $tone = $pct >= 90 ? '' : ($pct >= 75 ? 'warn' : 'danger');
            ?>
                <div>
                    <div style="display:flex; justify-content:space-between; font-size:12.6px; margin-bottom:5px; color:var(--muted)">
                        <span><?= e($grade) ?></span>
                        <span style="font-weight:600; color:var(--text)"><?= $pct ?>%</span>
                    </div>
                    <div class="meter-track" style="height:7px"><div class="meter-fill <?= $tone ?>" style="width: <?= $pct ?>%"></div></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="grid split-2">
    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="section-title">Recent document requests</h2>
                <p class="section-sub">Latest activity at the counter</p>
            </div>
            <a class="btn btn-sm btn-ghost" href="/requests.php">View all <?= icon('arrow-right') ?></a>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Request</th><th>Student</th><th>Document</th><th>Status</th><th>Requested</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><a class="code-tag" href="/requests.php?focus=<?= (int) $r['id'] ?>"><?= e($r['request_no']) ?></a></td>
                        <td>
                            <span class="strong"><?= e(student_name($r)) ?></span>
                            <span class="sub"><?= e($r['grade_level']) ?> · <?= e($r['section']) ?></span>
                        </td>
                        <td><?= e($r['document_type']) ?></td>
                        <td><?= status_pill($r['status']) ?></td>
                        <td class="num"><?= e(fmt_date($r['date_requested'], 'M j')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div style="display:flex; flex-direction:column; gap:18px">
        <?php if (can_manage()): ?>
        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="section-title">Needs attention</h2>
                    <p class="section-sub">Incomplete credential files</p>
                </div>
                <a class="btn btn-sm btn-ghost" href="/students.php?completeness=incomplete">View all</a>
            </div>
            <?php foreach ($attention as $s): ?>
                <div class="attention-item">
                    <span class="avatar-sm accent"><?= e(initials($s['first_name'] . ' ' . $s['last_name'])) ?></span>
                    <div class="meta">
                        <strong><?= e(student_name($s)) ?></strong>
                        <span><?= (int) $s['cred_total'] - (int) $s['cred_done'] ?> missing · <?= e($s['grade_level']) ?></span>
                    </div>
                    <a class="btn btn-sm btn-ghost" href="/student.php?id=<?= (int) $s['id'] ?>">Open</a>
                </div>
            <?php endforeach; ?>
            <?php if (!$attention): ?>
                <div class="empty" style="padding:26px"><p>Every record has a complete checklist.</p></div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="section-title">Follow-ups</h2>
                    <p class="section-sub">Next actions with previous schools</p>
                </div>
                <a class="btn btn-sm btn-ghost" href="/followups.php">Open log</a>
            </div>
            <?php foreach ($due as $f):
                $overdue = $f['next_followup_date'] < today();
            ?>
                <div class="mini-row">
                    <div class="left">
                        <strong><?= e(student_name($f)) ?></strong>
                        <span><?= e($f['concern']) ?></span>
                    </div>
                    <span class="pill <?= $overdue ? 'pill-danger' : 'pill-warn' ?>"><span class="pill-dot"></span><?= $overdue ? 'Overdue ' : '' ?><?= e(fmt_date($f['next_followup_date'], 'M j')) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (!$due): ?>
                <div class="empty" style="padding:26px"><p>No pending follow-ups. The log is clear.</p></div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php
app_footer();
