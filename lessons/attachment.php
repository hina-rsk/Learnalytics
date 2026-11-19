<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$attachment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($attachment_id <= 0) {
    http_response_code(400);
    echo 'Invalid attachment ID.';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM lesson_attachments WHERE id = ?');
$stmt->execute([$attachment_id]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$file_path = __DIR__ . '/../' . $attachment['file_path'];
if (!is_file($file_path) || !is_readable($file_path)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$extension = strtolower(pathinfo($attachment['file_name'], PATHINFO_EXTENSION));
$mime_types = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'mp4' => 'video/mp4',
    'txt' => 'text/plain',
    'csv' => 'text/csv',
    'zip' => 'application/zip',
];

$mime_type = $mime_types[$extension] ?? 'application/octet-stream';

header('Content-Type: ' . $mime_type);
header('Content-Disposition: inline; filename="' . basename($attachment['file_name']) . '"');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
exit;
