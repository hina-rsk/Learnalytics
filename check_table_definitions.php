<?php
require_once 'config/database.php';

echo "<h2>Table Definitions</h2>";

echo "<h3>Users Table</h3>";
$stmt = $pdo->query("SHOW CREATE TABLE users");
$row = $stmt->fetch();
echo "<pre>" . htmlspecialchars($row['Create Table']) . "</pre>";

echo "<h3>Quizzes Table</h3>";
$stmt = $pdo->query("SHOW CREATE TABLE quizzes");
$row = $stmt->fetch();
echo "<pre>" . htmlspecialchars($row['Create Table']) . "</pre>";

echo "<h3>Check User ID 11</h3>";
$stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = 11");
$stmt->execute();
$user = $stmt->fetch();
if ($user) {
    echo "<p>User exists: ID={$user['id']}, Name={$user['name']}, Email={$user['email']}, Role={$user['role']}</p>";
} else {
    echo "<p style='color: red;'>User ID 11 NOT FOUND!</p>";
}

echo "<h3>Check Foreign Key Constraints on quizzes</h3>";
$stmt = $pdo->query("
    SELECT 
        kcu.CONSTRAINT_NAME,
        kcu.COLUMN_NAME,
        kcu.REFERENCED_TABLE_NAME,
        kcu.REFERENCED_COLUMN_NAME,
        rc.DELETE_RULE,
        rc.UPDATE_RULE
    FROM information_schema.KEY_COLUMN_USAGE kcu
    JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
        ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
        AND kcu.TABLE_NAME = rc.TABLE_NAME
    WHERE kcu.TABLE_SCHEMA = DATABASE()
    AND kcu.TABLE_NAME = 'quizzes'
    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
");
$fks = $stmt->fetchAll();
echo "<ul>";
foreach ($fks as $fk) {
    echo "<li>Constraint: {$fk['CONSTRAINT_NAME']} - {$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']} (DELETE: {$fk['DELETE_RULE']}, UPDATE: {$fk['UPDATE_RULE']})</li>";
}
echo "</ul>";

echo "<h3>Column Types Comparison</h3>";
echo "<h4>users.id column:</h4>";
$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'id'");
$col = $stmt->fetch();
echo "<p>Type: {$col['Type']}, Null: {$col['Null']}, Key: {$col['Key']}, Extra: {$col['Extra']}</p>";

echo "<h4>quizzes.instructor_id column:</h4>";
$stmt = $pdo->query("SHOW COLUMNS FROM quizzes LIKE 'instructor_id'");
$col = $stmt->fetch();
echo "<p>Type: {$col['Type']}, Null: {$col['Null']}, Key: {$col['Key']}, Extra: {$col['Extra']}</p>";

// Try a direct insert to see the exact error
echo "<h3>Test Insert</h3>";
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO quizzes (subject_id, instructor_id, title, status) VALUES (42, 11, 'Test Quiz', 'draft')");
    $stmt->execute();
    $quizId = $pdo->lastInsertId();
    echo "<p style='color: green;'>Test insert succeeded! Quiz ID: $quizId</p>";
    // Delete the test record
    $pdo->prepare("DELETE FROM quizzes WHERE id = ?")->execute([$quizId]);
    echo "<p>Cleaned up test record.</p>";
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<p style='color: red;'>Test insert failed: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>