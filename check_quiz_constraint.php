<?php
// Diagnostic script to check the foreign key constraint issue
require_once 'config/database.php';

echo "<h2>Quiz Foreign Key Constraint Diagnostic</h2>";

// 1. Check if the foreign key constraint exists
echo "<h3>1. Foreign Key Constraints on quizzes table:</h3>";
$stmt = $pdo->query("
    SELECT 
        kcu.CONSTRAINT_NAME,
        kcu.COLUMN_NAME,
        kcu.REFERENCED_TABLE_NAME,
        kcu.REFERENCED_COLUMN_NAME,
        rc.DELETE_RULE
    FROM information_schema.KEY_COLUMN_USAGE kcu
    JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
        ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
        AND kcu.TABLE_NAME = rc.TABLE_NAME
    WHERE kcu.TABLE_SCHEMA = DATABASE()
    AND kcu.TABLE_NAME = 'quizzes'
    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
");
$constraints = $stmt->fetchAll();
if (empty($constraints)) {
    echo "<p>No foreign key constraints found on quizzes table.</p>";
} else {
    echo "<ul>";
    foreach ($constraints as $c) {
        echo "<li>Constraint: {$c['CONSTRAINT_NAME']} - {$c['COLUMN_NAME']} -> {$c['REFERENCED_TABLE_NAME']}.{$c['REFERENCED_COLUMN_NAME']} ({$c['DELETE_RULE']})</li>";
    }
    echo "</ul>";
}

// 2. Check columns in quizzes table
echo "<h3>2. Columns in quizzes table:</h3>";
$stmt = $pdo->query("SHOW COLUMNS FROM quizzes");
$columns = $stmt->fetchAll();
echo "<ul>";
foreach ($columns as $col) {
    echo "<li>{$col['Field']} - {$col['Type']} (Nullable: {$col['Null']}, Key: {$col['Key']})</li>";
}
echo "</ul>";

// 3. Check for orphaned subjects (instructor_id not in users table)
echo "<h3>3. Orphaned Subjects (instructor_id not in users table):</h3>";
$stmt = $pdo->query("
    SELECT s.id, s.title, s.instructor_id, u.id as user_exists
    FROM subjects s
    LEFT JOIN users u ON s.instructor_id = u.id
    WHERE u.id IS NULL
");
$orphanedSubjects = $stmt->fetchAll();
if (empty($orphanedSubjects)) {
    echo "<p>No orphaned subjects found. All subjects have valid instructor_id.</p>";
} else {
    echo "<p style='color: red;'>Found " . count($orphanedSubjects) . " orphaned subjects!</p>";
    echo "<ul>";
    foreach ($orphanedSubjects as $s) {
        echo "<li>Subject ID: {$s['id']}, Title: {$s['title']}, Invalid instructor_id: {$s['instructor_id']}</li>";
    }
    echo "</ul>";
}

// 4. Check for orphaned quizzes (instructor_id not in users table)
echo "<h3>4. Orphaned Quizzes (instructor_id not in users table):</h3>";
// First check if instructor_id column exists
$hasInstructorId = false;
foreach ($columns as $col) {
    if ($col['Field'] === 'instructor_id') {
        $hasInstructorId = true;
        break;
    }
}

if ($hasInstructorId) {
    $stmt = $pdo->query("
        SELECT q.id, q.title, q.instructor_id, q.subject_id, u.id as user_exists
        FROM quizzes q
        LEFT JOIN users u ON q.instructor_id = u.id
        WHERE u.id IS NULL
    ");
    $orphanedQuizzes = $stmt->fetchAll();
    if (empty($orphanedQuizzes)) {
        echo "<p>No orphaned quizzes found. All quizzes have valid instructor_id.</p>";
    } else {
        echo "<p style='color: red;'>Found " . count($orphanedQuizzes) . " orphaned quizzes!</p>";
        echo "<ul>";
        foreach ($orphanedQuizzes as $q) {
            echo "<li>Quiz ID: {$q['id']}, Title: {$q['title']}, Invalid instructor_id: {$q['instructor_id']}, Subject ID: {$q['subject_id']}</li>";
        }
        echo "</ul>";
    }
} else {
    echo "<p>instructor_id column does not exist in quizzes table.</p>";
}

// 5. Show current session user info
session_start();
echo "<h3>5. Current Session User:</h3>";
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if ($user) {
        echo "<ul>";
        echo "<li>User ID: {$user['id']}</li>";
        echo "<li>Name: {$user['name']}</li>";
        echo "<li>Email: {$user['email']}</li>";
        echo "<li>Role: {$user['role']}</li>";
        echo "<li>Status: {$user['status']}</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: red;'>User ID {$_SESSION['user_id']} not found in users table!</p>";
    }
} else {
    echo "<p>No user session found.</p>";
}

// 6. Show subjects for current user
if (isset($_SESSION['user_id'])) {
    echo "<h3>6. Subjects for Current User:</h3>";
    $stmt = $pdo->prepare("SELECT id, title, instructor_id FROM subjects WHERE instructor_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $subjects = $stmt->fetchAll();
    if (empty($subjects)) {
        echo "<p>No subjects found for this instructor.</p>";
    } else {
        echo "<ul>";
        foreach ($subjects as $s) {
            echo "<li>Subject ID: {$s['id']}, Title: {$s['title']}</li>";
        }
        echo "</ul>";
    }
}

// 7. Check if the constraint name matches
echo "<h3>7. All constraints referencing users table:</h3>";
$stmt = $pdo->query("
    SELECT 
        TABLE_NAME,
        CONSTRAINT_NAME,
        COLUMN_NAME,
        REFERENCED_TABLE_NAME,
        REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
    AND REFERENCED_TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'fk_quizzes_instructor'
");
$fkQuizzes = $stmt->fetchAll();
if (!empty($fkQuizzes)) {
    echo "<p>Found constraint 'fk_quizzes_instructor':</p>";
    echo "<ul>";
    foreach ($fkQuizzes as $fk) {
        echo "<li>Table: {$fk['TABLE_NAME']}, Column: {$fk['COLUMN_NAME']} -> users.{$fk['REFERENCED_COLUMN_NAME']}</li>";
    }
    echo "</ul>";
} else {
    echo "<p>Constraint 'fk_quizzes_instructor' not found.</p>";
}
?>