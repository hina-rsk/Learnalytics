<?php
// Image Proxy - serves image files through PHP to bypass InfinityFree 403 block
$file = $_GET['f'] ?? '';

// Sanitize: prevent directory traversal
$file = str_replace(['..', "\0"], '', $file);

$mimes = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'svg'  => 'image/svg+xml',
    'webp' => 'image/webp',
    'ico'  => 'image/x-icon',
];

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

if (!isset($mimes[$ext])) {
    http_response_code(403);
    exit('Forbidden');
}

$path = __DIR__ . '/assets/css/' . $file;

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: ' . $mimes[$ext]);
header('Cache-Control: public, max-age=86400');
readfile($path);
