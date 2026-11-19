<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'instructor' && $_SESSION['user_role'] !== 'admin')) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['lesson_id'])) {
    header('Location: index.php');
    exit;
}

$lesson_id = $_POST['lesson_id'];
$stmt = $pdo->prepare("SELECT l.id, c.instructor_id FROM lessons l JOIN subjects c ON l.subject_id = c.id WHERE l.id = ?");
$stmt->execute([$lesson_id]);
$lesson = $stmt->fetch();

if (!$lesson) {
    header('Location: index.php');
    exit;
}

if ($role === 'instructor' && $lesson['instructor_id'] != $user_id) {
    die('Unauthorized access to delete this lesson.');
}

$stmt = $pdo->prepare("SELECT file_path FROM lesson_attachments WHERE lesson_id = ?");
$stmt->execute([$lesson_id]);
$attachments = $stmt->fetchAll();

foreach ($attachments as $attachment) {
    $path = __DIR__ . '/../' . $attachment['file_path'];
    if (file_exists($path)) {
        @unlink($path);
    }
}

$stmt = $pdo->prepare("DELETE FROM lessons WHERE id = ?");
$stmt->execute([$lesson_id]);

header('Location: index.php');
exit;
?>