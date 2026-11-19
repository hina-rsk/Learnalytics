<?php
require_once 'config/database.php';

echo "<h2>Check for Triggers and Other Database Objects</h2>";

echo "<h3>Triggers on quizzes table</h3>";
$stmt = $pdo->query("SHOW TRIGGERS LIKE 'quizzes'");
$triggers = $stmt->fetchAll();
if (empty($triggers)) {
    echo "<p>No triggers found on quizzes table.</p>";
} else {
    echo "<ul>";
    foreach ($triggers as $t) {
        echo "<li>{$t['Trigger']} - {$t['Timing']} {$t['Event']} - {$t['Statement']}</li>";
    }
    echo "</ul>";
}

echo "<h3>All triggers in database</h3>";
$stmt = $pdo->query("SHOW TRIGGERS");
$allTriggers = $stmt->fetchAll();
if (empty($allTriggers)) {
    echo "<p>No triggers found in database.</p>";
} else {
    echo "<ul>";
    foreach ($allTriggers as $t) {
        echo "<li>{$t['Trigger']} - {$t['Table']} - {$t['Timing']} {$t['Event']}</li>";
    }
    echo "</ul>";
}

echo "<h3>Check subject_id 42</h3>";
$stmt = $pdo->prepare("SELECT id, title, instructor_id FROM subjects WHERE id = 42");
$stmt->execute();
$subject = $stmt->fetch();
if ($subject) {
    echo "<p>Subject exists: ID={$subject['id']}, Title={$subject['title']}, instructor_id={$subject['instructor_id']}</p>";
} else {
    echo "<p style='color: red;'>Subject ID 42 NOT FOUND!</p>";
}

echo "<h3>Test with full INSERT (same as create.php)</h3>";
try {
    $pdo->beginTransaction();
    
    $subject_id = 42;
    $instructor_id = 11;
    $title = "Test Quiz from Script";
    $description = "Test description";
    $due_date = null;
    $time_limit = null;
    $attempt_limit = 1;
    $show_results = 1;
    $shuffle_questions = 0;
    
    $stmt = $pdo->prepare(
        "INSERT INTO quizzes (subject_id, instructor_id, title, description, status, due_date, time_limit, attempt_limit, show_results, shuffle_questions) VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$subject_id, $instructor_id, $title, $description, $due_date, $time_limit, $attempt_limit, $show_results, $shuffle_questions]);
    
    $quizId = $pdo->lastInsertId();
    echo "<p style='color: green;'>Full INSERT succeeded! Quiz ID: $quizId</p>";
    
    // Clean up
    $pdo->prepare("DELETE FROM quizzes WHERE id = ?")->execute([$quizId]);
    echo "<p>Cleaned up test record.</p>";
    
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<p style='color: red;'>Full INSERT failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Error code: " . $e->getCode() . "</p>";
}

echo "<h3>Check for duplicate constraint names</h3>";
$stmt = $pdo->query("
    SELECT TABLE_NAME, CONSTRAINT_NAME, COUNT(*) as cnt
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
    GROUP BY TABLE_NAME, CONSTRAINT_NAME
    HAVING cnt > 1
");
$duplicates = $stmt->fetchAll();
if (empty($duplicates)) {
    echo "<p>No duplicate constraint names found.</p>";
} else {
    echo "<p style='color: orange;'>Found duplicate constraint names:</p>";
    echo "<ul>";
    foreach ($duplicates as $d) {
        echo "<li>Table: {$d['TABLE_NAME']}, Constraint: {$d['CONSTRAINT_NAME']} (count: {$d['cnt']})</li>";
    }
    echo "</ul>";
}
?>