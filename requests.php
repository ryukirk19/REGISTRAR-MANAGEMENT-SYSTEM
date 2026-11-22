<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$pdo = db();
$errors = [];
$openModal = isset($_GET['new']);
$returnTo = '/requests.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $candidate = (string) ($_POST['return'] ?? '');
    if (preg_match('~^/requests\.php(\?[^#]*)?$~', $candidate)) {
        $query = [];
        parse_str((string) parse_url($candidate, PHP_URL_QUERY), $query);
        unset($query['focus'], $query['new']);
        $returnTo = '/requests.php' . ($query ? '?' . http_build_query($query) : '');
    }
}
$staff = $pdo->query("SELECT id, first_name, middle_name, last_name, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();
$studentList = student_options();
$preselectStudent = (int) ($_GET['student'] ?? 0);
$old = [
    'student_id' => $preselectStudent ?: '',
    'document_type' => 'SF10 (Form 137)',
    'purpose' => '',
    'requester_name' => '',
    'relationship' => 'Student',
    'requester_contact' => '',
    'assigned_to' => current_user()['id'],
    'date_needed' => '',
    'fee' => '0',
    'remarks' => '',
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
            $errors['student_id'] = 'Choose a student from the index.';
        }
        if (!in_array($old['document_type'], request_docs(), true)) {
            $errors['document_type'] = 'Choose a valid document type.';
        }
        if ($old['requester_name'] === '') {
            $errors['requester_name'] = 'Name of the requester is required.';
        }
        if ($old['fee'] === '' || !is_numeric($old['fee']) || (float) $old['fee'] < 0) {
            $errors['fee'] = 'Enter a valid fee (0 if free).';
        }
        if ($old['date_needed'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['date_needed'])) {
            $errors['date_needed'] = 'Use a valid date.';
        }

        if (!$errors) {
            $requestNo = next_code('requests', 'request_no', 'REQ-' . date('Y'));
            $stmt = $pdo->prepare(
                'INSERT INTO requests (request_no, student_id, document_type, purpose, requester_name, relationship, requester_contact, assigned_to, status, date_requested, date_needed, fee, remarks, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'Pending\', ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $requestNo, (int) $old['student_id'], $old['document_type'], $old['purpose'],
                $old['requester_name'], $old['relationship'], $old['requester_contact'],
                $old['assigned_to'] !== '' ? (int) $old['assigned_to'] : null, today(),
                $old['date_needed'] ?: null, (float) $old['fee'], $old['remarks'], current_user()['id'],
            ]);
            log_activity('Request logged', $requestNo . ' · ' . $old['document_type'] . ' for ' . $student['record_code']);
            flash('success', $requestNo . ' was logged and assigned for processing.');
            redirect($returnTo);
        }
    }

    if ($action === 'advance') {
        $reqId = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM requests WHERE id = ?');
        $stmt->execute([$reqId]);
        $req = $stmt->fetch();
        $next = $req ? request_next_status($req['status']) : null;
        if ($req && $next) {
            $released = $next === 'Released' ? today() : $req['date_released'];
            $stmt = $pdo->prepare("UPDATE requests SET status = ?, date_released = ?, updated_at = datetime('now','localtime') WHERE id = ?");
            $stmt->execute([$next, $released, $reqId]);
            log_activity('Request updated', $req['request_no'] . ' marked ' . $next);
            flash('success', $req['request_no'] . ' is now "' . $next . '".');
        }
        redirect($returnTo);
    }

    if ($action === 'update') {
        $reqId = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM requests WHERE id = ?');
        $stmt->execute([$reqId]);
        $req = $stmt->fetch();
        if (!$req) {
            flash('error', 'Request not found.');
            redirect('requests.php');
        }
        $status = (string) ($_POST['status'] ?? $req['status']);
        $assigned = (int) ($_POST['assigned_to'] ?? 0) ?: null;
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        $released = (string) ($_POST['date_released'] ?? '');

        if (!in_array($status, request_statuses(), true)) {
            flash('error', 'Unknown request status.');
            redirect($returnTo);
        }
        if ($status === 'Released' && $released === '') {
            $released = $req['date_released'] ?: today();
        }
        if ($status !== 'Released') {
            $released = null;
        }
        $stmt = $pdo->prepare("UPDATE requests SET status = ?, assigned_to = ?, remarks = ?, date_released = ?, updated_at = datetime('now','localtime') WHERE id = ?");
        $stmt->execute([$status, $assigned, $remarks, $released ?: null, $reqId]);
        log_activity('Request updated', $req['request_no'] . ' set to ' . $status);
        flash('success', $req['request_no'] . ' updated.');
        redirect($returnTo);
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$assignedFilter = (int) ($_GET['assigned'] ?? 0);
$studentFilter = (int) ($_GET['student'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

$counts = array_fill_keys(request_statuses(), 0);
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM requests GROUP BY status') as $row) {
    $counts[$row['status']] = (int) $row['n'];
}
$totalAll = array_sum($counts);

$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = "(r.request_no LIKE :q OR r.document_type LIKE :q OR r.requester_name LIKE :q OR s.first_name || ' ' || s.last_name LIKE :q OR s.lrn LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if (in_array($status, request_statuses(), true)) {
    $where[] = 'r.status = :status';
    $params[':status'] = $status;
}
if ($assignedFilter > 0) {
    $where[] = 'r.assigned_to = :assigned';
    $params[':assigned'] = $assignedFilter;
}
if ($studentFilter > 0) {
    $where[] = 'r.student_id = :student';
    $params[':student'] = $studentFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM requests r JOIN students s ON s.id = r.student_id WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);

$stmt = $pdo->prepare(
    "SELECT r.*, s.first_name, s.middle_name, s.last_name, s.grade_level, s.section, s.record_code, s.lrn,
            u.full_name AS assigned_name, u.first_name AS assigned_first_name, u.middle_name AS assigned_middle_name, u.last_name AS assigned_last_name
     FROM requests r
     JOIN students s ON s.id = r.student_id
     LEFT JOIN users u ON u.id = r.assigned_to
     WHERE $whereSql
     ORDER BY r.date_requested DESC, r.id DESC
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$focusId = (int) ($_GET['focus'] ?? 0);
$focusRow = null;
foreach ($rows as $r) {
    if ((int) $r['id'] === $focusId) {
        $focusRow = $r;
    }
}
if ($focusId && !$focusRow) {
    $stmt = $pdo->prepare(
        "SELECT r.*, s.first_name, s.middle_name, s.last_name, s.grade_level, s.section, s.record_code, s.lrn, u.full_name AS assigned_name, u.first_name AS assigned_first_name, u.middle_name AS assigned_middle_name, u.last_name AS assigned_last_name
         FROM requests r JOIN students s ON s.id = r.student_id LEFT JOIN users u ON u.id = r.assigned_to WHERE r.id = ?"
    );
    $stmt->execute([$focusId]);
    $focusRow = $stmt->fetch() ?: null;
}

$studentFilterName = '';
if ($studentFilter) {
    $stmt = $pdo->prepare('SELECT first_name, middle_name, last_name FROM students WHERE id = ?');
    $stmt->execute([$studentFilter]);
    $row = $stmt->fetch();
    $studentFilterName = $row ? student_name($row) : '';
}

$qs = $_GET;
unset($qs['page'], $qs['new'], $qs['focus']);
$pageUrl = fn (int $p) => '/requests.php?' . http_build_query(array_merge($qs, ['page' => $p]));
$chipUrl = function (string $st) use ($qs) {
    $params = $qs;
    unset($params['status']);
    if ($st !== '') {
        $params['status'] = $st;
    }
    return '/requests.php' . ($params ? '?' . http_build_query($params) : '');
};

app_header('Document requests', 'requests.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Document request tracker</div>
        <h1>Document requests</h1>
        <p class="page-sub">Every request is logged from receipt to release, with an assigned staff member and a status trail.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/reports.php"><?= icon('reports') ?> Reports</a>
        <button class="btn btn-primary" type="button" data-dialog-open="#newRequest"><?= icon('plus') ?> Log request</button>
    </div>
</div>

<div style="display:flex; gap:7px; flex-wrap:wrap; margin-bottom:14px">
    <a class="chip <?= $status === '' ? 'is-active' : '' ?>" href="<?= e($chipUrl('')) ?>">All requests <span class="count"><?= $totalAll ?></span></a>
    <?php foreach (request_statuses() as $st): ?>
        <a class="chip <?= $status === $st ? 'is-active' : '' ?>" href="<?= e($chipUrl($st)) ?>"><?= e($st) ?> <span class="count"><?= $counts[$st] ?></span></a>
    <?php endforeach; ?>
</div>

<form class="card" method="get" action="/requests.php" style="margin-bottom:16px">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="toolbar">
        <label class="field-search">
            <?= icon('search') ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Request no, student, or document">
        </label>
        <select class="select-inline" name="assigned">
            <option value="0">Any staff member</option>
            <?php foreach ($staff as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= $assignedFilter === (int) $u['id'] ? 'selected' : '' ?>><?= e(account_name($u)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('search') ?> Search</button>
        <?php if ($studentFilterName): ?>
            <a class="pill pill-brass" href="/requests.php<?= $status ? '?status=' . urlencode($status) : '' ?>">Student: <?= e($studentFilterName) ?> ✕</a>
            <input type="hidden" name="student" value="<?= $studentFilter ?>">
        <?php endif; ?>
        <span class="spacer"></span>
        <span class="pill pill-muted"><span class="pill-dot"></span><?= $totalRows ?> results</span>
    </div>
</form>

<section class="card">
    <?php if ($rows): ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Request</th><th>Student</th><th>Document</th><th>Assigned</th><th>Needed</th><th>Status</th><th class="right">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $next = request_next_status($r['status']);
                ?>
                    <tr id="req-<?= (int) $r['id'] ?>">
                        <td>
                            <span class="code-tag"><?= e($r['request_no']) ?></span>
                            <span class="sub"><?= e(fmt_date($r['date_requested'], 'M j, Y')) ?></span>
                        </td>
                        <td>
                            <a class="strong" href="/student.php?id=<?= (int) $r['student_id'] ?>"><?= e(student_name($r)) ?></a>
                            <span class="sub"><?= e($r['grade_level']) ?> · <?= e($r['record_code']) ?></span>
                        </td>
                        <td>
                            <span style="font-weight:500"><?= e($r['document_type']) ?></span>
                            <span class="sub"><?= e($r['purpose'] ?: '—') ?></span>
                        </td>
                        <td><?= e($r['assigned_name'] ? account_name(['first_name' => $r['assigned_first_name'], 'middle_name' => $r['assigned_middle_name'], 'last_name' => $r['assigned_last_name'], 'full_name' => $r['assigned_name']]) : 'Unassigned') ?></td>
                        <td class="num"><?= $r['date_needed'] ? e(fmt_date($r['date_needed'], 'M j')) : '—' ?><?php if ($r['status'] !== 'Released' && $r['status'] !== 'Cancelled' && $r['date_needed'] && $r['date_needed'] < today()): ?><span class="sub" style="color:var(--danger)">Overdue</span><?php endif; ?></td>
                        <td><?= status_pill($r['status']) ?></td>
                        <td class="actions">
                            <?php if ($next): ?>
                                <form method="post" action="/requests.php" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '/requests.php') ?>">
                                    <input type="hidden" name="action" value="advance">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-sm btn-accent" type="submit" title="Move to <?= e($next) ?>"><?= e($next) ?></button>
                                </form>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-ghost" type="button" data-dialog-open="#req-<?= (int) $r['id'] ?>-modal">Manage</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="pager">
                <span class="pager-info">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page > 1): ?><a href="<?= e($pageUrl($page - 1)) ?>">‹ Prev</a><?php endif; ?>
                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                    <?php if ($p === $page): ?><span class="pg is-current"><?= $p ?></span><?php else: ?><a href="<?= e($pageUrl($p)) ?>"><?= $p ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?><a href="<?= e($pageUrl($page + 1)) ?>">Next ›</a><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty">
            <span class="empty-mark"><?= icon('requests') ?></span>
            <strong>No requests match these filters</strong>
            <p>Log a new request, or clear the filters to see the full tracker.</p>
        </div>
    <?php endif; ?>
</section>

<?php foreach ($rows as $r): ?>
<dialog class="modal modal-sm" id="req-<?= (int) $r['id'] ?>-modal" data-autopen="<?= ($focusId === (int) $r['id']) ? '1' : '0' ?>">
    <form method="post" action="/requests.php?focus=<?= (int) $r['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '/requests.php') ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <div class="modal-head">
            <div>
                <h2><?= e($r['request_no']) ?></h2>
                <p><?= e($r['document_type']) ?> · <?= e(student_name($r)) ?></p>
            </div>
            <button class="icon-btn modal-close" type="button" data-dialog-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <dl class="detail-grid" style="grid-template-columns:repeat(2,1fr); gap:12px 16px; margin-bottom:18px">
                <div class="detail"><dt>Requested by</dt><dd><?= e($r['requester_name']) ?><br><span class="muted" style="font-size:11.5px"><?= e($r['relationship'] ?: 'Requester') ?> · <?= e($r['requester_contact'] ?: 'no contact') ?></span></dd></div>
                <div class="detail"><dt>Purpose</dt><dd><?= e($r['purpose'] ?: '—') ?></dd></div>
                <div class="detail"><dt>Date requested</dt><dd><?= e(fmt_date($r['date_requested'])) ?></dd></div>
                <div class="detail"><dt>Fee</dt><dd><?= e(fmt_money((float) $r['fee'])) ?></dd></div>
                <div class="detail"><dt>Student</dt><dd><a href="/student.php?id=<?= (int) $r['student_id'] ?>"><?= e($r['record_code']) ?></a></dd></div>
                <div class="detail"><dt>Released</dt><dd><?= $r['date_released'] ? e(fmt_date($r['date_released'])) : 'Not yet' ?></dd></div>
            </dl>
            <div class="form-grid">
                <div class="field">
                    <label for="st-<?= (int) $r['id'] ?>">Status</label>
                    <select id="st-<?= (int) $r['id'] ?>" name="status">
                        <?php foreach (request_statuses() as $st): ?>
                            <option value="<?= e($st) ?>" <?= $r['status'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="as-<?= (int) $r['id'] ?>">Assigned to</label>
                    <select id="as-<?= (int) $r['id'] ?>" name="assigned_to">
                        <option value="0">Unassigned</option>
                        <?php foreach ($staff as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= (int) $r['assigned_to'] === (int) $u['id'] ? 'selected' : '' ?>><?= e(account_name($u)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="dr-<?= (int) $r['id'] ?>">Date released</label>
                    <input type="date" id="dr-<?= (int) $r['id'] ?>" name="date_released" value="<?= e($r['date_released'] ?? '') ?>">
                    <span class="hint">Filled automatically when marked Released.</span>
                </div>
                <div class="field">
                    <label>Requested on</label>
                    <input type="text" value="<?= e(fmt_date($r['date_requested'], 'M j, Y')) ?>" disabled>
                </div>
                <div class="field span-2">
                    <label for="rm-<?= (int) $r['id'] ?>">Remarks</label>
                    <textarea id="rm-<?= (int) $r['id'] ?>" name="remarks" placeholder="Processing notes, release instructions..."><?= e($r['remarks'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" type="button" data-dialog-close>Close</button>
            <button class="btn btn-primary" type="submit"><?= icon('check') ?> Save changes</button>
        </div>
    </form>
</dialog>
<?php endforeach; ?>

<?php if ($focusRow && !in_array($focusRow['id'], array_column($rows, 'id'), true)): ?>
<dialog class="modal modal-sm" id="focusRequest" data-autopen="1">
    <div class="modal-head">
        <div><h2><?= e($focusRow['request_no']) ?></h2><p><?= e($focusRow['document_type']) ?> · <?= e(student_name($focusRow)) ?></p></div>
        <button class="icon-btn modal-close" type="button" data-dialog-close aria-label="Close"><?= icon('x') ?></button>
    </div>
    <div class="modal-body">
        <p class="section-sub" style="margin-bottom:14px">Status: <?= status_pill($focusRow['status']) ?></p>
        <dl class="detail-grid" style="grid-template-columns:repeat(2,1fr); gap:12px 16px">
            <div class="detail"><dt>Requested</dt><dd><?= e(fmt_date($focusRow['date_requested'])) ?></dd></div>
            <div class="detail"><dt>Released</dt><dd><?= $focusRow['date_released'] ? e(fmt_date($focusRow['date_released'])) : 'Not yet' ?></dd></div>
            <div class="detail"><dt>Requester</dt><dd><?= e($focusRow['requester_name']) ?></dd></div>
            <div class="detail"><dt>Fee</dt><dd><?= e(fmt_money((float) $focusRow['fee'])) ?></dd></div>
            <div class="detail"><dt>Assigned</dt><dd><?= e($focusRow['assigned_name'] ? account_name(['first_name' => $focusRow['assigned_first_name'], 'middle_name' => $focusRow['assigned_middle_name'], 'last_name' => $focusRow['assigned_last_name'], 'full_name' => $focusRow['assigned_name']]) : 'Unassigned') ?></dd></div>
            <div class="detail"><dt>Student</dt><dd><a href="/student.php?id=<?= (int) $focusRow['student_id'] ?>"><?= e($focusRow['record_code']) ?></a></dd></div>
        </dl>
        <?php if ($focusRow['remarks']): ?><p class="section-sub" style="margin-top:14px"><?= e($focusRow['remarks']) ?></p><?php endif; ?>
    </div>
</dialog>
<?php endif; ?>

<dialog class="modal" id="newRequest" data-autopen="<?= $openModal ? '1' : '0' ?>">
    <form method="post" action="/requests.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI'] ?? '/requests.php') ?>">
        <input type="hidden" name="action" value="create">
        <div class="modal-head">
            <div>
                <h2>Log a document request</h2>
                <p>Record the request at the counter. It enters the tracker as Pending.</p>
            </div>
            <button class="icon-btn modal-close" type="button" data-dialog-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <?php if ($errors): ?>
                <div class="alert alert-error"><?= icon('alert') ?><span>Review the highlighted fields.</span></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field span-2 <?= isset($errors['student_id']) ? 'has-error' : '' ?>">
                    <label for="student_id">Student record</label>
                    <select id="student_id" name="student_id" required>
                        <option value="">Select a student…</option>
                        <?php foreach ($studentList as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= (string) $old['student_id'] === (string) $s['id'] ? 'selected' : '' ?>>
                                <?= e(student_name($s) . ' — ' . $s['grade_level'] . ' · ' . $s['record_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['student_id'])): ?><span class="error"><?= e($errors['student_id']) ?></span><?php endif; ?>
                </div>
                <div class="field <?= isset($errors['document_type']) ? 'has-error' : '' ?>">
                    <label for="document_type">Document requested</label>
                    <select id="document_type" name="document_type">
                        <?php foreach (request_docs() as $doc): ?>
                            <option value="<?= e($doc) ?>" <?= $old['document_type'] === $doc ? 'selected' : '' ?>><?= e($doc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="purpose">Purpose</label>
                    <input type="text" id="purpose" name="purpose" value="<?= e($old['purpose']) ?>" placeholder="e.g. Visa application">
                </div>
                <div class="field <?= isset($errors['requester_name']) ? 'has-error' : '' ?>">
                    <label for="requester_name">Requested by</label>
                    <input type="text" id="requester_name" name="requester_name" value="<?= e($old['requester_name']) ?>" placeholder="Full name">
                    <?php if (isset($errors['requester_name'])): ?><span class="error"><?= e($errors['requester_name']) ?></span><?php endif; ?>
                </div>
                <div class="field">
                    <label for="relationship">Relationship</label>
                    <select id="relationship" name="relationship">
                        <?php foreach (['Student', 'Parent', 'Guardian', 'Authorized representative', 'Receiving school'] as $rel): ?>
                            <option value="<?= e($rel) ?>" <?= $old['relationship'] === $rel ? 'selected' : '' ?>><?= e($rel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="requester_contact">Contact number</label>
                    <input type="text" id="requester_contact" name="requester_contact" value="<?= e($old['requester_contact']) ?>" placeholder="e.g. 0917 123 4567">
                </div>
                <div class="field">
                    <label for="assigned_to">Assign to</label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">Unassigned</option>
                        <?php foreach ($staff as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= (string) $old['assigned_to'] === (string) $u['id'] ? 'selected' : '' ?>><?= e(account_name($u)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="date_needed">Needed by</label>
                    <input type="date" id="date_needed" name="date_needed" value="<?= e($old['date_needed']) ?>">
                </div>
                <div class="field <?= isset($errors['fee']) ? 'has-error' : '' ?>">
                    <label for="fee">Fee (PHP)</label>
                    <input type="number" id="fee" name="fee" step="0.01" min="0" value="<?= e($old['fee']) ?>">
                    <?php if (isset($errors['fee'])): ?><span class="error"><?= e($errors['fee']) ?></span><?php endif; ?>
                </div>
                <div class="field span-2">
                    <label for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" placeholder="e.g. For principal signature, parent will pick up"><?= e($old['remarks']) ?></textarea>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" type="button" data-dialog-close>Cancel</button>
            <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Log request</button>
        </div>
    </form>
</dialog>
<?php
app_footer();
