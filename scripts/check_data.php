<?php
require_once __DIR__ . '/../config/database.php';

echo "=== USERS BY ROLE ===\n";
$roles = $pdo->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role")->fetchAll();
print_r($roles);

echo "\n=== DEPARTMENTS ===\n";
$depts = $pdo->query("SELECT id, name FROM departments")->fetchAll();
print_r($depts);

echo "\n=== DEAN USERS ===\n";
$deans = $pdo->query("SELECT id, name, email, department_id FROM users WHERE role = 'dean'")->fetchAll();
print_r($deans);

echo "\n=== COUNTS ===\n";
foreach (['student_profiles', 'subjects', 'enrollments', 'assignments', 'submissions', 'quizzes', 'quiz_results', 'attendance', 'exam_records', 'exam_scores'] as $t) {
    $cnt = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "$t: $cnt\n";
}

echo "\n=== SAMPLE ATTENDANCE ===\n";
$att = $pdo->query("SELECT status, COUNT(*) as cnt FROM attendance GROUP BY status")->fetchAll();
print_r($att);

echo "\n=== SAMPLE QUIZ RESULTS ===\n";
$qr = $pdo->query("SELECT MIN(score) as min_s, MAX(score) as max_s, AVG(score) as avg_s, COUNT(*) as total FROM quiz_results")->fetch();
print_r($qr);
