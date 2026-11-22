<?php
declare(strict_types=1);

function icon(string $name, string $class = ''): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.6"/><rect x="14" y="3" width="7" height="5" rx="1.6"/><rect x="14" y="12" width="7" height="9" rx="1.6"/><rect x="3" y="16" width="7" height="5" rx="1.6"/>',
        'records' => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4H10l2 2.5h5.5A2.5 2.5 0 0 1 20 9v8.5a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5z"/><path d="M8 12h8M8 15.5h5"/>',
        'requests' => '<path d="M4 7.5 12 12l8-4.5"/><rect x="3" y="5" width="18" height="14" rx="2.4"/><path d="M8 3v4M16 3v4"/>',
        'followup' => '<path d="M5 4h3l1.8 4.5-2.2 1.6a12 12 0 0 0 6.3 6.3l1.6-2.2L20 16v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 3 6.2 2 2 0 0 1 5 4z"/>',
        'cert' => '<circle cx="12" cy="9" r="5"/><path d="M9 13.5 8 21l4-2.3L16 21l-1-7.5"/><path d="m10.4 9 1.2 1.2 2.2-2.4"/>',
        'transfer' => '<path d="M4 8h13l-3-3M20 16H7l3 3"/><path d="M17 8v0M7 16v0"/>',
        'reports' => '<path d="M4 20V9M10 20V4M16 20v-7M22 20H2"/>',
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19.5c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><path d="M16 5.2a3 3 0 0 1 0 5.6M17.5 14.7c2 .5 3.4 2.2 3.9 4.8"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.36a1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.36a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.64 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.64 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.64a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.36 9c.26.6.85 1 1.55 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51 1z"/>',
        'audit' => '<path d="M12 8v4.5l3 1.8"/><circle cx="12" cy="12" r="9"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'logout' => '<path d="M15 4h3.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15"/><path d="M10 8l-4 4 4 4M6 12h10"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'download' => '<path d="M12 4v10m0 0 4-4m-4 4-4-4"/><path d="M4 17v2.5A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5V17"/>',
        'print' => '<path d="M7 8V3h10v5"/><rect x="4" y="8" width="16" height="8" rx="2"/><path d="M7 13h10v8H7z"/>',
        'edit' => '<path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/><path d="m14.5 6.5 3 3"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'upload' => '<path d="M12 20V10m0 0-4 4m4-4 4 4"/><path d="M4 7V5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5V7"/>',
        'check' => '<path d="m4.5 12.5 5 5L20 6.5"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'alert' => '<path d="M12 3 2.5 20h19z"/><path d="M12 9v5M12 17.2v.3"/>',
        'arrow-right' => '<path d="M4 12h15m0 0-6-6m6 6-6 6"/>',
        'lock' => '<rect x="4.5" y="10" width="15" height="10" rx="2.2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'school' => '<path d="M3 21h18M5 21V9l7-4 7 4v12"/><path d="M10 21v-5h4v5M9 11h.01M15 11h.01M9 14h.01M15 14h.01"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="16" rx="2.4"/><path d="M8 3v4M16 3v4M3.5 10.5h17"/>',
        'pin' => '<path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/>',
        'shield' => '<path d="M12 3 5 6v5.5c0 4.6 3 8 7 9.5 4-1.5 7-4.9 7-9.5V6z"/><path d="m9.3 11.8 2 2 3.6-3.8"/>',
        'spark' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.8 2.8M15.2 15.2 18 18M18 6l-2.8 2.8M8.8 15.2 6 18"/>',
        'file' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
        'cabinet' => '<rect x="3" y="4" width="18" height="4" rx="1.2"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><path d="M10 13h4"/>',
    ];
    $d = $paths[$name] ?? $paths['spark'];
    return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

function logo_mark(string $class = ''): string
{
    return '<img class="logo-mark ' . e($class) . '" src="/assets/img/vnhs-logo.png" alt="REGIS+ logo" width="52" height="52">';
}

function open_page(string $title, string $bodyClass = '', array $css = [], string $bodyAttr = ''): void
{
    $css = $css ?: ['assets/css/app.css'];
    echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<title>' . e($title) . ' · ' . APP_NAME . "</title>\n";
    echo '<meta name="description" content="' . APP_NAME . ' — ' . APP_TAGLINE . '">' . "\n";
    if (!empty($_SESSION['session_token'])) {
        echo '<meta name="regis-csrf" content="' . e(csrf_token()) . '">' . "\n";
    }
    echo '<link rel="icon" href="/assets/img/favicon.png" type="image/png">' . "\n";
    foreach ($css as $file) {
        echo '<link rel="stylesheet" href="' . e(url($file)) . '">' . "\n";
    }
    echo "</head>\n<body class=\"" . e($bodyClass) . '"' . ($bodyAttr ? ' ' . $bodyAttr : '') . ">\n";
}

function close_page(array $js = ['assets/js/app.js']): void
{
    foreach ($js as $file) {
        echo '<script src="' . e(url($file)) . '" defer></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

function app_header(string $title, string $active, string $screenTitle = ''): void
{
    require_login();
    $user = current_user();

    $openRequests = (int) db()->query("SELECT COUNT(*) FROM requests WHERE status IN ('Pending','Processing','Ready for Release')")->fetchColumn();
    $overdue = (int) db()->query("SELECT COUNT(*) FROM followups WHERE status <> 'Resolved' AND next_followup_date IS NOT NULL AND next_followup_date < date('now','localtime')")->fetchColumn();

    $workspace = [['dashboard.php', 'dashboard', 'Dashboard', 0]];
    if (can_manage()) {
        $workspace[] = ['students.php', 'records', 'Student Records', 0];
        $workspace[] = ['cabinets.php', 'cabinet', 'Storage & Cabinets', 0];
    }
    $workspace[] = ['requests.php', 'requests', 'Document Requests', $openRequests];

    $nav = [
        'Workspace' => $workspace,
        'Tracking' => [
            ['followups.php', 'followup', 'Follow-Up Log', $overdue],
            ['certifications.php', 'cert', 'Certifications', 0],
            ['transfers.php', 'transfer', 'Transfers', 0],
        ],
        'Administration' => [],
    ];
    if (can_manage()) {
        $nav['Administration'][] = ['reports.php', 'reports', 'Reports', 0];
    }
    if (is_admin()) {
        $nav['Administration'][] = ['users.php', 'users', 'User Accounts', 0];
        $nav['Administration'][] = ['audit.php', 'audit', 'Audit Trail', 0];
    }
    $nav['Administration'][] = ['settings.php', 'settings', 'Settings', 0];

    open_page($title . ' · ' . APP_NAME, 'app');
    ?>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="/dashboard.php">
            <span class="brand-mark"><?= logo_mark() ?></span>
            <span class="brand-text">
                <span class="brand-name">REGIS<span class="brand-plus">+</span></span>
                <span class="brand-sub">Registrar Records System</span>
            </span>
        </a>
        <div class="sidebar-school">
            <span class="school-dot"></span>
            <span><?= e(setting('school_name', 'Registrar Office')) ?></span>
        </div>
        <nav class="side-nav">
            <?php foreach ($nav as $section => $items): ?>
                <div class="nav-section"><?= e($section) ?></div>
                <?php foreach ($items as [$href, $ic, $label, $count]): ?>
                    <a class="nav-link<?= $active === $href ? ' is-active' : '' ?>" href="/<?= e($href) ?>">
                        <?= icon($ic) ?>
                        <span><?= e($label) ?></span>
                        <?php if ($count > 0): ?><span class="nav-count"><?= $count ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-note">
            <?= icon('shield') ?>
            <div>
                <strong>Data privacy in effect</strong>
                <p>Student records are confidential. Access is limited to authorized personnel and logged.</p>
            </div>
        </div>
        <div class="sidebar-foot">
            <span>SY <?= e(setting('school_year', '2026-2027')) ?></span>
            <span class="dot-sep"></span>
            <span>Prototype <?= e(APP_VERSION) ?></span>
        </div>
    </aside>
    <div class="app-body">
        <header class="topbar">
            <button class="icon-btn topbar-menu" type="button" data-sidebar-toggle aria-label="Toggle navigation"><?= icon('menu') ?></button>
            <?php if (can_manage()): ?>
            <form class="topbar-search" action="/students.php" method="get" role="search">
                <?= icon('search') ?>
                <input type="search" name="q" placeholder="Search student name, LRN, or record code" value="">
                <kbd>Enter</kbd>
            </form>
            <?php endif; ?>
            <div class="topbar-right">
                <span class="topbar-date"><?= icon('calendar') ?><?= e(date('D, M j')) ?></span>
                <details class="user-menu">
                    <summary>
                        <span class="avatar"><?= e(initials($user['full_name'])) ?></span>
                        <span class="user-meta">
                            <strong><?= e(account_name($user)) ?></strong>
                            <small><?= e(role_label($user['role'])) ?></small>
                        </span>
                        <?= icon('chevron', 'chev') ?>
                    </summary>
                    <div class="user-dropdown">
                        <div class="user-dropdown-head">
                            <strong><?= e(account_name($user)) ?></strong>
                            <small><?= e($user['email'] ?? '') ?></small>
                        </div>
                        <a href="/settings.php"><?= icon('settings') ?>Account settings</a>
                        <?php if (is_admin()): ?><a href="/users.php"><?= icon('users') ?>Manage users</a><?php endif; ?>
                        <form action="/logout.php" method="post">
                            <?= csrf_field() ?>
                            <button type="submit"><?= icon('logout') ?>Sign out</button>
                        </form>
                    </div>
                </details>
            </div>
        </header>
        <main class="content">
    <?php
}

function page_head(string $heading, string $sub = '', string $actions = '', string $eyebrow = ''): void
{
    ?>
    <div class="page-head">
        <div>
            <?php if ($eyebrow): ?><div class="eyebrow"><?= e($eyebrow) ?></div><?php endif; ?>
            <h1><?= e($heading) ?></h1>
            <?php if ($sub): ?><p class="page-sub"><?= e($sub) ?></p><?php endif; ?>
        </div>
        <?php if ($actions): ?><div class="page-actions"><?= $actions ?></div><?php endif; ?>
    </div>
    <?php
}

function app_footer(): void
{
    ?>
        </main>
        <footer class="app-foot">
            <span><?= e(APP_NAME) ?> · <?= e(setting('school_name')) ?></span>
            <span><?= e(setting('backup_note')) ?></span>
        </footer>
    </div>
</div>
<?php
    $flashes = take_flashes();
    echo '<div class="toasts" id="toasts">';
    foreach ($flashes as $f) {
        echo '<div class="toast toast-' . e($f['type']) . '">' . icon($f['type'] === 'error' ? 'alert' : 'check') . '<span>' . e($f['message']) . '</span><button type="button" aria-label="Dismiss">' . icon('x') . '</button></div>';
    }
    echo '</div>';
    close_page();
}

function site_header(string $active = ''): void
{
    open_page(APP_NAME . ' — ' . APP_TAGLINE, 'site', ['assets/css/site.css', 'assets/css/app.css']);
    $items = [
        ['index.php#problem', 'The problem'],
        ['index.php#modules', 'Modules'],
        ['index.php#workflow', 'How it works'],
        ['index.php#impact', 'Impact'],
    ];
    ?>
<header class="site-nav">
    <div class="site-nav-inner">
        <a class="site-brand" href="/index.php">
            <span class="site-brand-mark"><?= logo_mark() ?></span>
            <span>
                <span class="site-brand-name">REGIS<span>+</span></span>
                <span class="site-brand-sub">Registrar Records System</span>
            </span>
        </a>
        <nav class="site-links">
            <?php foreach ($items as [$href, $label]): ?>
                <a href="/<?= e($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="site-nav-actions">
            <?php if (current_user()): ?>
                <a class="btn btn-light" href="/dashboard.php">Open dashboard <?= icon('arrow-right') ?></a>
            <?php else: ?>
                <a class="site-signin" href="/login.php">Sign in</a>
                <a class="btn btn-brass" href="/login.php">Open the system <?= icon('arrow-right') ?></a>
            <?php endif; ?>
        </div>
    </div>
</header>
    <?php
}

function site_footer(): void
{
    ?>
<footer class="site-footer">
    <div class="site-footer-inner">
        <div class="site-footer-brand">
            <?= logo_mark() ?>
            <div>
                <strong>REGIS<span>+</span></strong>
                <p>A proposed digital records tracking and retrieval solution for the Registrar Office. Grade 12 – Versatility, 2026.</p>
            </div>
        </div>
        <div class="site-footer-col">
            <h4>System</h4>
            <a href="/login.php">Sign in</a>
            <a href="/index.php#modules">Modules</a>
            <a href="/index.php#impact">Impact</a>
        </div>
        <div class="site-footer-col">
            <h4>Prototype</h4>
            <p>Built with HTML, CSS, JavaScript, and PHP on a relational database.</p>
        </div>
    </div>
    <div class="site-footer-base">
        <span>© <?= date('Y') ?> REGIS+ · Registrar Records Management System</span>
        <span>Registrar's Office · Valencia National High School</span>
    </div>
</footer>
    <?php
    close_page(['assets/js/site.js']);
}
