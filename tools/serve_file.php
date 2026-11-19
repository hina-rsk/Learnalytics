<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo "Unauthorized access.";
    exit;
}

$file_param = $_GET['file'] ?? $_GET['path'] ?? '';

if (empty($file_param)) {
    http_response_code(400);
    echo "No file specified.";
    exit;
}

// Clean relative path and decode URL
$file_param = ltrim(str_replace(['\\', '../', '..'], ['/', '', ''], rawurldecode($file_param)), '/');

// Base directory (root of project)
$base_dir = realpath(__DIR__ . '/../');
$full_path = realpath($base_dir . '/' . $file_param);

// Ensure path is within the project base directory
if (!$full_path || strpos($full_path, $base_dir) !== 0 || !is_file($full_path)) {
    http_response_code(404);
    echo "File not found or permission denied.";
    exit;
}

$filename = basename($full_path);
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

$mime_types = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
    'txt'  => 'text/plain; charset=utf-8',
    'csv'  => 'text/csv; charset=utf-8',
    'html' => 'text/html; charset=utf-8',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip'  => 'application/zip',
];

$mime_type = $mime_types[$ext] ?? 'application/octet-stream';

// Disable caching for security
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: ' . $mime_type);

// Force inline viewing instead of downloading
$download = isset($_GET['download']) && $_GET['download'] === '1';
$disposition = $download ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . filesize($full_path));

readfile($full_path);
exit;
