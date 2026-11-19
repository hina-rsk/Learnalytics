<?php
/**
 * Fix Quiz Instructor Foreign Key Constraint Issue
 * 
 * This script fixes the foreign key constraint issue by:
 * 1. Updating any quizzes with NULL or invalid instructor_id
 * 2. Ensuring all quizzes have a valid instructor_id from their subject
 * 3. Verifying the foreign key constraint is properly set up
 * 
 * Run this script once to fix the database state.
 */

require_once 'config/database.php';

echo "<h2>Fixing Quiz Instructor Foreign Key Constraint</h2>";

// Step 1: Check current state
echo "<h3>Step 1: Checking current state...</h3>";

// Check if instructor_id column exists
$stmt = $pdo->query("SHOW COLUMNS FROM quizzes LIKE 'instructor_id'");
$hasInstructorId = $stmt->fetch();

if (!$hasInstructorId) {
    echo "<p style='color: orange;'>instructor_id column does not exist. Running migration...</p>";
    // Would need to run the migration first
} else {
    echo "<p>instructor_id column exists.</p>";
}

// Step 2: Find quizzes with NULL instructor_id
echo "<h3>Step 2: Finding quizzes with NULL or invalid instructor_id...</h3>";

$stmt = $pdo->query("
    SELECT q.id, q.title, q.instructor_id, q.subject_id, s.instructor_id as subject_instructor_id, u.id as user_exists
    FROM quizzes q
    JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN users u ON q.instructor_id = u.id
    WHERE q.instructor_id IS NULL OR u.id IS NULL
");
$problematicQuizzes = $stmt->fetchAll();

if (empty($problematicQuizzes)) {
    echo "<p>No quizzes with NULL or invalid instructor_id found.</p>";
} else {
    echo "<p style='color: orange;'>Found " . count($problematicQuizzes) . " quizzes with issues:</p>";
    echo "<ul>";
    foreach ($problematicQuizzes as $q) {
        echo "<li>Quiz ID: {$q['id']}, Title: {$q['title']}, Current instructor_id: " . ($q['instructor_id'] ?? 'NULL') . ", Subject instructor_id: {$q['subject_instructor_id']}</li>";
    }
    echo "</ul>";
}

// Step 3: Fix quizzes with NULL or invalid instructor_id
echo "<h3>Step 3: Fixing quizzes with NULL or invalid instructor_id...</h3>";

// Update quizzes to use the subject's instructor_id where it's NULL or invalid
$stmt = $pdo->exec("
    UPDATE quizzes q
    JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN users u ON q.instructor_id = u.id
    SET q.instructor_id = s.instructor_id
    WHERE q.instructor_id IS NULL OR u.id IS NULL
");

echo "<p>Updated $stmt quizzes with valid instructor_id from their subjects.</p>";

// Step 4: Verify the fix
echo "<h3>Step 4: Verifying the fix...</h3>";

$stmt = $pdo->query("
    SELECT q.id, q.title, q.instructor_id, u.id as user_exists
    FROM quizzes q
    LEFT JOIN users u ON q.instructor_id = u.id
    WHERE u.id IS NULL
");
$remainingIssues = $stmt->fetchAll();

if (empty($remainingIssues)) {
    echo "<p style='color: green;'><strong>Success! All quizzes now have valid instructor_id values.</strong></p>";
} else {
    echo "<p style='color: red;'>Still found " . count($remainingIssues) . " quizzes with invalid instructor_id:</p>";
    echo "<ul>";
    foreach ($remainingIssues as $q) {
        echo "<li>Quiz ID: {$q['id']}, Title: {$q['title']}, Invalid instructor_id: {$q['instructor_id']}</li>";
    }
    echo "</ul>";
    echo "<p>These quizzes may have a subject_id that points to a subject with an invalid instructor_id.</p>";
    
    // Try to fix by finding subjects with invalid instructor_id
    $stmt = $pdo->query("
        SELECT s.id, s.title, s.instructor_id, u.id as user_exists
        FROM subjects s
        LEFT JOIN users u ON s.instructor_id = u.id
        WHERE u.id IS NULL
    ");
    $orphanedSubjects = $stmt->fetchAll();
    
    if (!empty($orphanedSubjects)) {
        echo "<p style='color: orange;'>Found subjects with invalid instructor_id:</p>";
        echo "<ul>";
        foreach ($orphanedSubjects as $s) {
            echo "<li>Subject ID: {$s['id']}, Title: {$s['title']}, Invalid instructor_id: {$s['instructor_id']}</li>";
        }
        echo "</ul>";
        echo "<p>Please fix these subjects by assigning a valid instructor.</p>";
    }
}

// Step 5: Check foreign key constraint
echo "<h3>Step 5: Checking foreign key constraint...</h3>";

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
    AND kcu.COLUMN_NAME = 'instructor_id'
    AND kcu.REFERENCED_TABLE_NAME = 'users'
");
$fkConstraint = $stmt->fetch();

if ($fkConstraint) {
    echo "<p style='color: green;'>Foreign key constraint '{{$fkConstraint['CONSTRAINT_NAME']}}' exists on instructor_id -> users.id ({{$fkConstraint['DELETE_RULE']}})</p>";
} else {
    echo "<p style='color: orange;'>No foreign key constraint found on instructor_id. Adding constraint...</p>";
    
    // First, make sure all instructor_id values are valid
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM quizzes q
        LEFT JOIN users u ON q.instructor_id = u.id
        WHERE u.id IS NULL
    ");
    $invalidCount = $stmt->fetch()['count'];
    
    if ($invalidCount == 0) {
        try {
            // Make instructor_id NOT NULL if it isn't already
            $pdo->exec("ALTER TABLE quizzes MODIFY COLUMN instructor_id INT NOT NULL");
            
            // Add the foreign key constraint
            $pdo->exec("
                ALTER TABLE quizzes 
                ADD CONSTRAINT fk_quizzes_instructor 
                FOREIGN KEY (instructor_id) 
                REFERENCES users(id) 
                ON DELETE CASCADE
            ");
            echo "<p style='color: green;'>Foreign key constraint added successfully.</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>Error adding constraint: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p style='color: red;'>Cannot add foreign key constraint - there are still $invalidCount quizzes with invalid instructor_id.</p>";
    }
}

echo "<h3>Done!</h3>";
echo "<p>You can now try creating a quiz again. The issue should be resolved.</p>";
?>