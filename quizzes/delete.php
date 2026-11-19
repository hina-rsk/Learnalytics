<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Check if user has permission to delete (instructor or admin)
if ($role !== 'instructor' && $role !== 'admin') {
    header("Location: index.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$quiz_id = (int)$_GET['id'];

try {
    // Start transaction
    $pdo->beginTransaction();

    // Delete quiz results
    $stmt = $pdo->prepare("DELETE FROM quiz_results WHERE quiz_id = ?");
    $stmt->execute([$quiz_id]);

    // Delete questions
    $stmt = $pdo->prepare("DELETE FROM questions WHERE quiz_id = ?");
    $stmt->execute([$quiz_id]);

    // Delete quiz
    $stmt = $pdo->prepare("DELETE FROM quizzes WHERE id = ?");
    $stmt->execute([$quiz_id]);

    // Commit transaction
    $pdo->commit();

    // Redirect with success message
    header("Location: index.php?deleted=1");
    exit;

} catch (Exception $e) {
    // Rollback transaction on error
    $pdo->rollBack();

    // Redirect with error message
    header("Location: index.php?error=1");
    exit;
}
?>