<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

$pdo = db();
$q = trim((string) ($_GET['q'] ?? ''));
$userId = (int) ($_GET['user'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;

$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = '(a.action LIKE :q OR a.details LIKE :q OR u.full_name LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($userId > 0) {
    $where[] = 'a.user_id = :user';
    $params[':user'] = $userId;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity a LEFT JOIN users u ON u.id = a.user_id WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);

$stmt = $pdo->prepare(
    "SELECT a.*, u.full_name AS user_name, u.role AS user_role,
            u.first_name AS user_first_name, u.middle_name AS user_middle_name, u.last_name AS user_last_name
     FROM activity a LEFT JOIN users u ON u.id = a.user_id
     WHERE $whereSql ORDER BY a.created_at DESC, a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$todayCount = (int) $pdo->query("SELECT COUNT(*) FROM activity WHERE date(created_at) = date('now','localtime')")->fetchColumn();
$signIns = (int) $pdo->query("SELECT COUNT(*) FROM activity WHERE action = 'User signed in'")->fetchColumn();
$users = $pdo->query('SELECT id, first_name, middle_name, last_name, full_name FROM users ORDER BY full_name')->fetchAll();

$nameMap = [];
foreach ($users as $u) {
    $nameMap[$u['full_name']] = account_name($u);
}
foreach ($pdo->query('SELECT first_name, middle_name, last_name FROM students')->fetchAll() as $s) {
    $formatted = student_name($s);
    $nameMap[full_name($s)] = $formatted;
    $short = trim($s['first_name'] . ' ' . $s['last_name']);
    $nameMap[$short] = $nameMap[$short] ?? $formatted;
}

$qs = $_GET;
unset($qs['page']);
$pageUrl = fn (int $p) => '/audit.php?' . http_build_query(array_merge($qs, ['page' => $p]));

app_header('Audit trail', 'audit.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Administration · accountability</div>
        <h1>Audit trail</h1>
        <p class="page-sub">Every sign-in, record edit, release, and export is recorded here for accountability.</p>
    </div>
</div>

<div class="grid split-3" style="margin-bottom:18px">
    <div class="kpi">
        <div class="kpi-top"><span class="kpi-label">Total events</span><span class="kpi-icon"><?= icon('audit') ?></span></div>
        <div class="kpi-value"><?= $total ?></div>
        <div class="kpi-foot">Recorded since the prototype was installed</div>
    </div>
    <div class="kpi tone-accent">
        <div class="kpi-top"><span class="kpi-label">Today</span><span class="kpi-icon"><?= icon('calendar') ?></span></div>
        <div class="kpi-value"><?= $todayCount ?></div>
        <div class="kpi-foot">Events logged <?= e(date('M j, Y')) ?></div>
    </div>
    <div class="kpi tone-info">
        <div class="kpi-top"><span class="kpi-label">Sign-ins</span><span class="kpi-icon"><?= icon('lock') ?></span></div>
        <div class="kpi-value"><?= $signIns ?></div>
        <div class="kpi-foot">Authorized sessions recorded</div>
    </div>
</div>

<form class="card" method="get" action="/audit.php" style="margin-bottom:16px">
    <div class="toolbar">
        <label class="field-search">
            <?= icon('search') ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Action, detail, or person">
        </label>
        <select class="select-inline" name="user">
            <option value="0">All users</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= $userId === (int) $u['id'] ? 'selected' : '' ?>><?= e(account_name($u)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('search') ?> Filter</button>
        <?php if ($q || $userId): ?><a class="btn-link" href="/audit.php">Reset</a><?php endif; ?>
        <span class="spacer"></span>
        <span class="pill pill-muted"><span class="pill-dot"></span><?= $total ?> events</span>
    </div>
</form>

<section class="card">
    <?php if ($rows): ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th style="width:180px">When</th><th>Person</th><th>Action</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $a):
                    $personName = $a['user_name'] ? account_name(['first_name' => $a['user_first_name'], 'middle_name' => $a['user_middle_name'], 'last_name' => $a['user_last_name'], 'full_name' => $a['user_name']]) : 'System';
                ?>
                    <tr>
                        <td class="num" style="white-space:nowrap"><?= e(fmt_datetime($a['created_at'])) ?></td>
                        <td>
                            <span class="strong" style="font-weight:500"><?= e($personName) ?></span>
                            <span class="sub"><?= e($a['user_role'] ? role_label($a['user_role']) : '') ?></span>
                        </td>
                        <td><span class="pill pill-muted"><span class="pill-dot"></span><?= e($a['action']) ?></span></td>
                        <td><?= e($a['details'] ? strtr((string) $a['details'], $nameMap) : '—') ?></td>
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
            <span class="empty-mark"><?= icon('audit') ?></span>
            <strong>No events match this filter</strong>
            <p>Try a different person or keyword.</p>
        </div>
    <?php endif; ?>
</section>
<?php
app_footer();
