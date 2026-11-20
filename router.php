<?php
// Router for PHP's built-in server. Blocks direct access to internal folders.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$blocked = ['/database/', '/storage/', '/includes/', '/runtime/'];
foreach ($blocked as $prefix) {
    if (str_starts_with($path, $prefix)) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}
if (str_contains($path, '..')) {
    http_response_code(400);
    echo 'Bad request';
    return true;
}

$file = __DIR__ . urldecode($path);
if ($path !== '/' && is_file($file)) {
    return false;
}
if ($path === '/' || $path === '') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
require __DIR__ . '/404.php';
return true;
