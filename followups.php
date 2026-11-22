<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$pdo = db();
$errors = [];
$openModal = isset($_GET['new']);
$preselectStudent = (int) ($_GET['student'] ?? 0);
$methods = ['Email', 'Call', 'SMS', 'Letter', 'Portal', 'In person'];
$old = [
    'student_id' => $preselectStudent ?: '',
    'concern' => '',
    'school_contacted' => '',
    'contact_person' => '',
    'method' => 'Email',
    'followup_date' => today(),
    'next_action' => '',
    'next_followup_date' => date('Y-m-d', strtotime('+7 day')),
    'status' => 'Open',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
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
        if ($old['concern'] === '') {
            $errors['concern'] = 'Describe the concern being followed up.';
        }
        if (!in_array($old['method'], $methods, true)) {
            $errors['method'] = 'Choose a valid contact method.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['followup_date'])) {
            $errors['followup_date'] = 'Enter the date of this follow-up.';
        }
        if ($old['next_followup_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['next_followup_date'])) {
            $errors['next_followup_date'] = 'Use a valid date.';
        }
        if (!in_array($old['status'], followup_statuses(), true)) {
            $errors['status'] = 'Choose a valid status.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO followups (student_id, concern, school_contacted, contact_person, method, followup_date, next_action, next_followup_date, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $old['student_id'], $old['concern'], $old['school_contacted'], $old['contact_person'],
                $old['method'], $old['followup_date'], $old['next_action'], $old['next_followup_date'] ?: null,
                $old['status'], current_user()['id'],
            ]);
            log_activity('Follow-up logged', $old['method'] . ' follow-up for ' . $student['record_code'] . ': ' . $old['concern']);
            flash('success', 'Follow-up logged for ' . student_name($student) . '.');
            redirect('followups.php');
        }
    }

    if ($action === 'set_status') {
        $fuId = (int) ($_POST['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        $stmt = $pdo->prepare('SELECT f.*, s.record_code FROM followups f JOIN students s ON s.id = f.student_id WHERE f.id = ?');
        $stmt->execute([$fuId]);
        $fu = $stmt->fetch();
        if ($fu && in_array($status, followup_statuses(), true)) {
            $stmt = $pdo->prepare('UPDATE followups SET status = ? WHERE id = ?');
            $stmt->execute([$status, $fuId]);
            log_activity('Follow-up updated', $fu['record_code'] . ' follow-up marked ' . $status);
            flash('success', 'Follow-up marked as ' . strtolower($status) . '.');
        }
        redirect('followups.php');
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$studentFilter = (int) ($_GET['student'] ?? 0);
$onlyOverdue = isset($_GET['overdue']);

$counts = array_fill_keys(followup_statuses(), 0);
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM followups GROUP BY status') as $row) {
    $counts[$row['status']] = (int) $row['n'];
}
$totalAll = array_sum($counts);

$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = "(f.concern LIKE :q OR f.school_contacted LIKE :q OR f.contact_person LIKE :q OR s.first_name || ' ' || s.last_name LIKE :q OR s.record_code LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if (in_array($status, followup_statuses(), true)) {
    $where[] = 'f.status = :status';
    $params[':status'] = $status;
}
if ($studentFilter > 0) {
    $where[] = 'f.student_id = :student';
    $params[':student'] = $studentFilter;
}
if ($onlyOverdue) {
    $where[] = "f.status <> 'Resolved' AND f.next_followup_date IS NOT NULL AND f.next_followup_date < date('now','localtime')";
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT f.*, s.first_name, s.middle_name, s.last_name, s.grade_level, s.record_code, s.previous_school, u.full_name AS staff_name
     FROM followups f
     JOIN students s ON s.id = f.student_id
     LEFT JOIN users u ON u.id = f.created_by
     WHERE $whereSql
     ORDER BY (f.status = 'Resolved') ASC, COALESCE(f.next_followup_date, '9999-12-31') ASC, f.followup_date DESC
     LIMIT 60"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$studentFilterName = '';
if ($studentFilter) {
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM students WHERE id = ?');
    $stmt->execute([$studentFilter]);
    $studentFilterName = (string) $stmt->fetchColumn() ?: '';
}

app_header('Follow-up log', 'followups.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Follow-up log</div>
        <h1>Follow-ups with previous schools</h1>
        <p class="page-sub">Track every contact, concern, and next action for credentials that have not yet been forwarded.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost<?= $onlyOverdue ? ' btn-primary' : '' ?>" href="/followups.php<?= $onlyOverdue ? '' : '?overdue=1' ?>"><?= icon('alert') ?> <?= $onlyOverdue ? 'Showing overdue' : 'Overdue only' ?></a>
        <button class="btn btn-primary" type="button" data-dialog-open="#newFollowup"><?= icon('plus') ?> Log follow-up</button>
    </div>
</div>

<div style="display:flex; gap:7px; flex-wrap:wrap; margin-bottom:14px">
    <a class="chip <?= $status === '' && !$onlyOverdue ? 'is-active' : '' ?>" href="/followups.php">All <span class="count"><?= $totalAll ?></span></a>
    <?php foreach (followup_statuses() as $st): ?>
        <a class="chip <?= $status === $st ? 'is-active' : '' ?>" href="/followups.php?status=<?= urlencode($st) ?>"><?= e($st) ?> <span class="count"><?= $counts[$st] ?></span></a>
    <?php endforeach; ?>
    <?php if ($studentFilterName): ?>
        <span class="chip is-active">Student: <?= e($studentFilterName) ?></span>
    <?php endif; ?>
</div>

<form class="card" method="get" action="/followups.php" style="margin-bottom:16px">
    <div class="toolbar">
        <label class="field-search">
            <?= icon('search') ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Concern, school, or student">
        </label>
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <?php if ($studentFilter): ?><input type="hidden" name="student" value="<?= $studentFilter ?>"><?php endif; ?>
        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('search') ?> Search</button>
        <?php if ($q !== '' || $status !== '' || $studentFilter || $onlyOverdue): ?><a class="btn-link" href="/followups.php">Reset</a><?php endif; ?>
        <span class="spacer"></span>
        <span class="pill pill-muted"><span class="pill-dot"></span><?= count($rows) ?> entries</span>
    </div>
</form>

<section class="card">
    <?php if ($rows): ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Student</th><th>Concern</th><th>Contacted</th><th>Next action</th><th>Status</th><th class="right">Log</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $f):
                    $overdue = $f['status'] !== 'Resolved' && $f['next_followup_date'] && $f['next_followup_date'] < today();
                ?>
                    <tr>
                        <td>
                            <a class="strong" href="/student.php?id=<?= (int) $f['student_id'] ?>"><?= e(student_name($f)) ?></a>
                            <span class="sub"><?= e($f['record_code']) ?> · <?= e($f['grade_level']) ?></span>
                        </td>
                        <td>
                            <span class="strong" style="font-weight:500"><?= e($f['concern']) ?></span>
                            <span class="sub"><?= e($f['method']) ?> · logged <?= e(fmt_date($f['followup_date'], 'M j, Y')) ?></span>
                        </td>
                        <td>
                            <?= e($f['school_contacted'] ?: '—') ?>
                            <span class="sub"><?= e($f['contact_person'] ?: 'No contact person') ?></span>
                        </td>
                        <td>
                            <?= e($f['next_action'] ?: '—') ?>
                            <span class="sub" style="<?= $overdue ? 'color:var(--danger); font-weight:600' : '' ?>">
                                <?= $f['next_followup_date'] ? ($overdue ? 'Overdue ' : 'Due ') . e(fmt_date($f['next_followup_date'], 'M j, Y')) : 'No next date' ?>
                            </span>
                        </td>
                        <td><?= status_pill($f['status']) ?></td>
                        <td class="actions">
                            <?php if ($f['status'] !== 'Resolved'): ?>
                                <form method="post" action="/followups.php" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $f['status'] === 'Open' ? 'In Progress' : 'Resolved' ?>">
                                    <button class="btn btn-sm <?= $f['status'] === 'Open' ? 'btn-ghost' : 'btn-accent' ?>" type="submit">
                                        <?= $f['status'] === 'Open' ? 'Start' : 'Resolve' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="muted" style="font-size:11.5px; color:var(--faint)">Closed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty">
            <span class="empty-mark"><?= icon('followup') ?></span>
            <strong>No follow-ups in this view</strong>
            <p>Log a follow-up after contacting a previous school about pending credentials.</p>
        </div>
    <?php endif; ?>
</section>

<dialog class="modal" id="newFollowup" data-autopen="<?= $openModal ? '1' : '0' ?>">
    <form method="post" action="/followups.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-head">
            <div>
                <h2>Log a follow-up</h2>
                <p>Record the contact, the concern, and the next action on file.</p>
            </div>
            <button class="icon-btn modal-close" type="button" data-dialog-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <?php if ($errors): ?>
                <div class="alert alert-error"><?= icon('alert') ?><span>Review the highlighted fields.</span></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field span-2 <?= isset($errors['student_id']) ? 'has-error' : '' ?>">
                    <label for="fu_student">Student record</label>
                    <select id="fu_student" name="student_id" required>
                        <option value="">Select a student…</option>
                        <?php foreach (student_options() as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= (string) $old['student_id'] === (string) $s['id'] ? 'selected' : '' ?>>
                                <?= e(student_name($s) . ' — ' . $s['grade_level'] . ' · ' . $s['record_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['student_id'])): ?><span class="error"><?= e($errors['student_id']) ?></span><?php endif; ?>
                </div>
                <div class="field span-2 <?= isset($errors['concern']) ? 'has-error' : '' ?>">
                    <label for="concern">Concern</label>
                    <input type="text" id="concern" name="concern" value="<?= e($old['concern']) ?>" placeholder="e.g. SF10 not yet forwarded by previous school">
                    <?php if (isset($errors['concern'])): ?><span class="error"><?= e($errors['concern']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="school_contacted">School / office contacted</label>
                    <input type="text" id="school_contacted" name="school_contacted" value="<?= e($old['school_contacted']) ?>" placeholder="e.g. Bagontaas National High School">
                </div>
                <div class="field">
                    <label for="contact_person">Contact person</label>
                    <input type="text" id="contact_person" name="contact_person" value="<?= e($old['contact_person']) ?>" placeholder="e.g. Ms. Salas">
                </div>
                <div class="field">
                    <label for="method">Method</label>
                    <select id="method" name="method">
                        <?php foreach ($methods as $m): ?>
                            <option value="<?= e($m) ?>" <?= $old['method'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field <?= isset($errors['followup_date']) ? 'has-error' : '' ?>">
                    <label for="followup_date">Date of follow-up</label>
                    <input type="date" id="followup_date" name="followup_date" value="<?= e($old['followup_date']) ?>">
                    <?php if (isset($errors['followup_date'])): ?><span class="error"><?= e($errors['followup_date']) ?></span><?php endif; ?>
                </div>
                <div class="field span-2">
                    <label for="next_action">Next action</label>
                    <input type="text" id="next_action" name="next_action" value="<?= e($old['next_action']) ?>" placeholder="e.g. Send second request letter">
                </div>
                <div class="field">
                    <label for="next_followup_date">Next follow-up date</label>
                    <input type="date" id="next_followup_date" name="next_followup_date" value="<?= e($old['next_followup_date']) ?>">
                </div>
                <div class="field">
                    <label for="fu_status">Status</label>
                    <select id="fu_status" name="status">
                        <?php foreach (followup_statuses() as $st): ?>
                            <option value="<?= e($st) ?>" <?= $old['status'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" type="button" data-dialog-close>Cancel</button>
            <button class="btn btn-primary" type="submit"><?= icon('check') ?> Save follow-up</button>
        </div>
    </form>
</dialog>
<?php
app_footer();
