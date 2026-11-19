<?php
// CSS Proxy - serves CSS files through PHP to bypass InfinityFree 403 block
$file = $_GET['f'] ?? '';

// Sanitize: only allow css files in assets/css
$file = str_replace(['..', "\0"], '', $file);
$allowed_extensions = ['css'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

if (!in_array($ext, $allowed_extensions)) {
    http_response_code(403);
    exit('Forbidden');
}

$path = __DIR__ . '/assets/css/' . $file;

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=86400');
readfile($path);
