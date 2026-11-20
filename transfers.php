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
    'direction' => 'Outgoing',
    'counterpart_school' => '',
    'effective_date' => today(),
    'reason' => '',
    'status' => 'Documents Pending',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_manager();
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
        if (!in_array($old['direction'], ['Incoming', 'Outgoing'], true)) {
            $errors['direction'] = 'Choose a transfer direction.';
        }
        if ($old['counterpart_school'] === '') {
            $errors['counterpart_school'] = 'Name the school on the other end of the transfer.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['effective_date'])) {
            $errors['effective_date'] = 'Enter a valid effective date.';
        }
        if (!in_array($old['status'], transfer_statuses(), true)) {
            $errors['status'] = 'Choose a valid status.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO transfers (student_id, direction, counterpart_school, effective_date, reason, status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $old['student_id'], $old['direction'], $old['counterpart_school'], $old['effective_date'],
                $old['reason'], $old['status'], $old['notes'], current_user()['id'],
            ]);
            if ($old['direction'] === 'Outgoing') {
                $pdo->prepare("UPDATE students SET status = 'Transferred Out', updated_at = datetime('now','localtime') WHERE id = ?")
                    ->execute([(int) $old['student_id']]);
            }
            log_activity('Transfer recorded', $old['direction'] . ' transfer for ' . $student['record_code'] . ' · ' . $old['counterpart_school']);
            flash('success', $old['direction'] . ' transfer recorded for ' . $student['first_name'] . ' ' . $student['last_name'] . '.');
            redirect('transfers.php');
        }
    }

    if ($action === 'set_status') {
        $trId = (int) ($_POST['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        $stmt = $pdo->prepare('SELECT t.*, s.record_code FROM transfers t JOIN students s ON s.id = t.student_id WHERE t.id = ?');
        $stmt->execute([$trId]);
        $tr = $stmt->fetch();
        if ($tr && in_array($status, transfer_statuses(), true)) {
            $pdo->prepare('UPDATE transfers SET status = ? WHERE id = ?')->execute([$status, $trId]);
            if ($status === 'Completed' && $tr['direction'] === 'Outgoing') {
                $pdo->prepare("UPDATE students SET status = 'Transferred Out', updated_at = datetime('now','localtime') WHERE id = ? AND status = 'Enrolled'")
                    ->execute([(int) $tr['student_id']]);
            }
            log_activity('Transfer updated', $tr['record_code'] . ' transfer marked ' . $status);
            flash('success', 'Transfer status updated to ' . $status . '.');
        }
        redirect('transfers.php');
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$direction = (string) ($_GET['direction'] ?? '');
$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = "(t.counterpart_school LIKE :q OR s.first_name || ' ' || s.last_name LIKE :q OR s.record_code LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if (in_array($direction, ['Incoming', 'Outgoing'], true)) {
    $where[] = 't.direction = :direction';
    $params[':direction'] = $direction;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT t.*, s.first_name, s.middle_name, s.last_name, s.grade_level, s.record_code, u.full_name AS staff_name
     FROM transfers t JOIN students s ON s.id = t.student_id LEFT JOIN users u ON u.id = t.created_by
     WHERE $whereSql ORDER BY t.effective_date DESC, t.id DESC LIMIT 60"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$incoming = (int) $pdo->query("SELECT COUNT(*) FROM transfers WHERE direction = 'Incoming'")->fetchColumn();
$outgoing = (int) $pdo->query("SELECT COUNT(*) FROM transfers WHERE direction = 'Outgoing'")->fetchColumn();
$pending = (int) $pdo->query("SELECT COUNT(*) FROM transfers WHERE status <> 'Completed'")->fetchColumn();

app_header('Transfers', 'transfers.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Student transfer support</div>
        <h1>Incoming &amp; outgoing transfers</h1>
        <p class="page-sub">Track honorable dismissals, receiving schools, and the credentials that still need to follow.</p>
    </div>
    <?php if (can_manage()): ?>
        <div class="page-actions">
            <button class="btn btn-primary" type="button" data-dialog-open="#newTransfer"><?= icon('plus') ?> Record transfer</button>
        </div>
    <?php endif; ?>
</div>

<div class="grid split-3" style="margin-bottom:18px">
    <div class="kpi">
        <div class="kpi-top"><span class="kpi-label">Incoming</span><span class="kpi-icon"><?= icon('transfer') ?></span></div>
        <div class="kpi-value"><?= $incoming ?></div>
        <div class="kpi-foot">Students who joined from another school</div>
    </div>
    <div class="kpi">
        <div class="kpi-top"><span class="kpi-label">Outgoing</span><span class="kpi-icon"><?= icon('arrow-right') ?></span></div>
        <div class="kpi-value"><?= $outgoing ?></div>
        <div class="kpi-foot">Students endorsed to a receiving school</div>
    </div>
    <div class="kpi tone-warn">
        <div class="kpi-top"><span class="kpi-label">Awaiting completion</span><span class="kpi-icon"><?= icon('clock') ?></span></div>
        <div class="kpi-value"><?= $pending ?></div>
        <div class="kpi-foot">Transfers with credentials still in transit</div>
    </div>
</div>

<div style="display:flex; gap:7px; flex-wrap:wrap; margin-bottom:14px">
    <a class="chip <?= $direction === '' ? 'is-active' : '' ?>" href="/transfers.php">All transfers</a>
    <a class="chip <?= $direction === 'Incoming' ? 'is-active' : '' ?>" href="/transfers.php?direction=Incoming">Incoming</a>
    <a class="chip <?= $direction === 'Outgoing' ? 'is-active' : '' ?>" href="/transfers.php?direction=Outgoing">Outgoing</a>
</div>

<form class="card" method="get" action="/transfers.php" style="margin-bottom:16px">
    <div class="toolbar">
        <label class="field-search">
            <?= icon('search') ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="School or student">
        </label>
        <?php if ($direction): ?><input type="hidden" name="direction" value="<?= e($direction) ?>"><?php endif; ?>
        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('search') ?> Search</button>
        <?php if ($q || $direction): ?><a class="btn-link" href="/transfers.php">Reset</a><?php endif; ?>
        <span class="spacer"></span>
        <span class="pill pill-muted"><span class="pill-dot"></span><?= count($rows) ?> transfers</span>
    </div>
</form>

<section class="card">
    <?php if ($rows): ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Student</th><th>Direction</th><th>Counterpart school</th><th>Reason</th><th>Effective</th><th>Status</th><th class="right">Action</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $t): ?>
                    <tr>
                        <td>
                            <a class="strong" href="/student.php?id=<?= (int) $t['student_id'] ?>"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></a>
                            <span class="sub"><?= e($t['record_code']) ?> · <?= e($t['grade_level']) ?></span>
                        </td>
                        <td><span class="pill <?= $t['direction'] === 'Outgoing' ? 'pill-brass' : 'pill-info' ?>"><span class="pill-dot"></span><?= e($t['direction']) ?></span></td>
                        <td><?= e($t['counterpart_school']) ?><span class="sub"><?= e($t['notes'] ?: '') ?></span></td>
                        <td><?= e($t['reason'] ?: '—') ?></td>
                        <td class="num"><?= e(fmt_date($t['effective_date'])) ?></td>
                        <td><?= status_pill($t['status']) ?></td>
                        <td class="actions">
                            <?php if (can_manage() && $t['status'] !== 'Completed'): ?>
                                <form method="post" action="/transfers.php" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $t['status'] === 'Documents Pending' ? 'In Process' : 'Completed' ?>">
                                    <button class="btn btn-sm <?= $t['status'] === 'Documents Pending' ? 'btn-ghost' : 'btn-accent' ?>" type="submit">
                                        <?= $t['status'] === 'Documents Pending' ? 'Start' : 'Complete' ?>
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
            <span class="empty-mark"><?= icon('transfer') ?></span>
            <strong>No transfers recorded</strong>
            <p>Record an incoming or outgoing transfer to begin tracking the credentials still in transit.</p>
        </div>
    <?php endif; ?>
</section>

<?php if (can_manage()): ?>
<dialog class="modal" id="newTransfer" data-autopen="<?= $openModal ? '1' : '0' ?>">
    <form method="post" action="/transfers.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-head">
            <div>
                <h2>Record a transfer</h2>
                <p>Outgoing transfers mark the student as transferred out of the school.</p>
            </div>
            <button class="icon-btn modal-close" type="button" data-dialog-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <?php if ($errors): ?>
                <div class="alert alert-error"><?= icon('alert') ?><span>Review the highlighted fields.</span></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field span-2 <?= isset($errors['student_id']) ? 'has-error' : '' ?>">
                    <label for="t_student">Student record</label>
                    <select id="t_student" name="student_id" required>
                        <option value="">Select a student…</option>
                        <?php foreach (student_options() as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= (string) $old['student_id'] === (string) $s['id'] ? 'selected' : '' ?>>
                                <?= e($s['last_name'] . ', ' . $s['first_name'] . ' — ' . $s['grade_level'] . ' · ' . $s['record_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['student_id'])): ?><span class="error"><?= e($errors['student_id']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="direction">Direction</label>
                    <select id="direction" name="direction">
                        <option value="Outgoing" <?= $old['direction'] === 'Outgoing' ? 'selected' : '' ?>>Outgoing — leaving the school</option>
                        <option value="Incoming" <?= $old['direction'] === 'Incoming' ? 'selected' : '' ?>>Incoming — joining the school</option>
                    </select>
                </div>
                <div class="field <?= isset($errors['counterpart_school']) ? 'has-error' : '' ?>">
                    <label for="counterpart_school">Counterpart school</label>
                    <input type="text" id="counterpart_school" name="counterpart_school" value="<?= e($old['counterpart_school']) ?>" placeholder="Receiving or previous school">
                    <?php if (isset($errors['counterpart_school'])): ?><span class="error"><?= e($errors['counterpart_school']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="effective_date">Effective date</label>
                    <input type="date" id="effective_date" name="effective_date" value="<?= e($old['effective_date']) ?>">
                </div>
                <div class="field">
                    <label for="t_status">Status</label>
                    <select id="t_status" name="status">
                        <?php foreach (transfer_statuses() as $st): ?>
                            <option value="<?= e($st) ?>" <?= $old['status'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field span-2">
                    <label for="reason">Reason</label>
                    <input type="text" id="reason" name="reason" value="<?= e($old['reason']) ?>" placeholder="e.g. Family relocated to another city">
                </div>
                <div class="field span-2">
                    <label for="t_notes">Notes</label>
                    <textarea id="t_notes" name="notes" placeholder="Credentials still to follow, endorsements, acknowledgments"><?= e($old['notes']) ?></textarea>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" type="button" data-dialog-close>Cancel</button>
            <button class="btn btn-primary" type="submit"><?= icon('check') ?> Save transfer</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php
app_footer();
