<?php
require_once __DIR__ . '/../config/database.php';

echo "=== SUBJECTS ===\n";
$subjects = $pdo->query("SELECT id, title, instructor_id FROM subjects")->fetchAll(PDO::FETCH_ASSOC);
print_r($subjects);

echo "=== ASSIGNMENTS ===\n";
$assignments = $pdo->query("SELECT id, subject_id, title, due_date, points FROM assignments")->fetchAll(PDO::FETCH_ASSOC);
print_r($assignments);

echo "=== QUIZZES ===\n";
$quizzes = $pdo->query("SELECT id, subject_id, title FROM quizzes")->fetchAll(PDO::FETCH_ASSOC);
print_r($quizzes);

echo "=== LESSONS ===\n";
$lessons = $pdo->query("SELECT id, subject_id, title FROM lessons")->fetchAll(PDO::FETCH_ASSOC);
print_r($lessons);

echo "=== ATTENDANCE ===\n";
$attendance = $pdo->query("SELECT id, subject_id, attendance_date, status FROM attendance LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($attendance);
