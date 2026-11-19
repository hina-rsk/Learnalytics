<?php
require_once __DIR__ . '/../config/database.php';
$subject_id = $argv[1] ?? 43;
echo "Debugging subject_id=$subject_id\n";
$types = ['lessons' => 'created_at', 'assignments' => 'due_date', 'quizzes' => 'created_at'];
foreach ($types as $table => $order) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM $table WHERE subject_id = ?");
    $stmt->execute([$subject_id]);
    $cnt = $stmt->fetchColumn();
    echo ucfirst($table) . ": $cnt\n";
    $stmt = $pdo->prepare("SELECT id, title FROM $table WHERE subject_id = ? ORDER BY $order DESC LIMIT 5");
    $stmt->execute([$subject_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        echo " - [{$r['id']}] {$r['title']}\n";
    }
}

// Also check subject exists and instructor
$stmt = $pdo->prepare("SELECT id, title, instructor_id FROM subjects WHERE id = ?");
$stmt->execute([$subject_id]);
$s = $stmt->fetch();
echo "Subject: " . ($s ? "{$s['id']} - {$s['title']} (instructor_id={$s['instructor_id']})" : 'NOT FOUND') . "\n";

// End

?>