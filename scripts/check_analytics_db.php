<?php
require 'config/database.php';
// Users
echo "=== USERS ===\n";
$rows = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('instructor','student') ORDER BY role, id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $u) {
    echo $u['role'] . " id=" . $u['id'] . " " . $u['name'] . "\n";
}

// Subjects
echo "\n=== SUBJECTS (instructor_id, title, status) ===\n";
$rows = $pdo->query("SELECT id, title, instructor_id, status FROM subjects ORDER BY instructor_id, id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $s) {
    echo "subject_id={$s['id']} instructor_id={$s['instructor_id']} status={$s['status']} title={$s['title']}\n";
}

// quiz_results count
echo "\n=== QUIZ RESULTS COUNT ===\n";
$count = $pdo->query("SELECT COUNT(*) FROM quiz_results")->fetchColumn();
echo "Total quiz_results rows: $count\n";

// exam_scores count
echo "\n=== EXAM SCORES COUNT ===\n";
$count = $pdo->query("SELECT COUNT(*) FROM exam_scores")->fetchColumn();
echo "Total exam_scores rows: $count\n";

// Check join: quiz_results -> quizzes -> subjects -> instructor
echo "\n=== QUIZ RESULTS joined to instructor ===\n";
$rows = $pdo->query("
    SELECT qr.id, qr.student_id, qr.quiz_id, qr.score, s.instructor_id, s.title as subject
    FROM quiz_results qr
    INNER JOIN quizzes q ON qr.quiz_id = q.id
    INNER JOIN subjects s ON q.subject_id = s.id
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "result_id={$r['id']} student={$r['student_id']} quiz={$r['quiz_id']} score={$r['score']} instructor={$r['instructor_id']} subject={$r['subject']}\n";
}

// Check exam_scores -> exam_records -> subjects -> instructor
echo "\n=== EXAM SCORES joined to instructor ===\n";
$rows = $pdo->query("
    SELECT es.id, es.student_id, es.exam_id, es.score, er.instructor_id, s.title as subject
    FROM exam_scores es
    INNER JOIN exam_records er ON es.exam_id = er.id
    INNER JOIN subjects s ON er.subject_id = s.id
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "score_id={$r['id']} student={$r['student_id']} exam={$r['exam_id']} score={$r['score']} instructor={$r['instructor_id']} subject={$r['subject']}\n";
}
