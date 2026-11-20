<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
http_response_code(404);
open_page('Page not found · ' . APP_NAME, 'auth', ['assets/css/app.css']);
?>
<main class="auth-panel" style="min-height:100vh">
    <div class="auth-card" style="text-align:center">
        <div class="eyebrow">Error 404</div>
        <h1>Page not found</h1>
        <p class="lede">The page you are looking for is not part of the REGIS+ workspace.</p>
        <a class="btn btn-primary" href="/dashboard.php"><?= icon('dashboard') ?> Return to dashboard</a>
        <div><a class="auth-back" href="/index.php"><?= icon('arrow-right') ?> Back to the REGIS+ overview</a></div>
    </div>
</main>
<?php
close_page();
