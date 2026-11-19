<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['instructor', 'student'], true)) {
    http_response_code(401);
    exit('Authentication required.');
}

$submissionId = (int)($_GET['submission_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'];
$stmt = $pdo->prepare(" 
    SELECT sub.file_path, sub.student_id, s.instructor_id
    FROM submissions sub
    INNER JOIN assignments a ON a.id = sub.assignment_id
    INNER JOIN subjects s ON s.id = a.subject_id
    WHERE sub.id = ?
");
$stmt->execute([$submissionId]);
$submission = $stmt->fetch();

$authorized = $submission && (($role === 'instructor' && (int)$submission['instructor_id'] === $userId)
    || ($role === 'student' && (int)$submission['student_id'] === $userId));
if (!$authorized || empty($submission['file_path'])) {
    http_response_code(404);
    exit('Submission file not found.');
}

$relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $submission['file_path']);
$uploadRoot = realpath(__DIR__ . '/../uploads/submissions');
$filePath = realpath(__DIR__ . '/../' . $relativePath);
if (!$uploadRoot || !$filePath || !is_file($filePath) || strpos($filePath, $uploadRoot . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit('Submission file not found.');
}

$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$contentTypes = [
    'pdf' => 'application/pdf',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp'
];
if (!isset($contentTypes[$extension])) {
    http_response_code(415);
    exit('This file type cannot be previewed.');
}

$fileName = basename($filePath);
header('Content-Type: ' . $contentTypes[$extension]);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . $fileName . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($filePath);