<?php
/**
 * Debug script: Simulate get_student_analytics.php and capture any errors.
 * Access via: http://localhost/LearnAlytics/scripts/debug_analytics.php
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// Fake instructor session
$_SESSION['user_id']   = 1;
$_SESSION['user_role'] = 'instructor';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../instructor/analytics_functions.php';

$instructor_id = (int)$_SESSION['user_id'];
$threshold     = 75.0;

echo "<h2>Step 1: getTeacherNormalizedAssessments()</h2>";
try {
    $allAssessments = getTeacherNormalizedAssessments($pdo, $instructor_id);
    echo "<p>OK — returned " . count($allAssessments) . " records.</p>";

    // Show unique student IDs
    $studentIds = array_unique(array_column($allAssessments, 'student_id'));
    echo "<p>Unique students with assessments: " . implode(', ', $studentIds) . "</p>";

    if (!empty($allAssessments)) {
        echo "<p>Sample record:</p><pre>" . htmlspecialchars(print_r($allAssessments[0], true)) . "</pre>";
    }
} catch (Throwable $e) {
    echo "<p style='color:red'>ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<h2>Step 2: Fetch a real student</h2>";
try {
    $stmt = $pdo->prepare("SELECT u.id, u.name FROM users u WHERE u.role = 'student' LIMIT 5");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Students found: " . count($students) . "</p>";
    foreach ($students as $s) {
        echo "<p>ID={$s['id']} Name={$s['name']} — ";
        echo "<a href='http://localhost/LearnAlytics/instructor/get_student_analytics.php?student_id={$s['id']}&threshold=75' target='_blank'>Test Endpoint</a>";
        echo "</p>";
    }
} catch (Throwable $e) {
    echo "<p style='color:red'>ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>Step 3: Direct JSON output test (student_id from URL)</h2>";
$test_student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if ($test_student_id) {
    echo "<p>Testing student_id = $test_student_id</p>";
    ob_start();
    $_GET['threshold'] = 75;
    include __DIR__ . '/../instructor/get_student_analytics.php';
    $output = ob_get_clean();
    echo "<p>Raw output:</p><pre>" . htmlspecialchars($output) . "</pre>";
    $decoded = json_decode($output, true);
    if ($decoded === null) {
        echo "<p style='color:red'>INVALID JSON — json_last_error_msg: " . json_last_error_msg() . "</p>";
    } else {
        echo "<p style='color:green'>VALID JSON returned!</p>";
        echo "<pre>" . htmlspecialchars(print_r($decoded, true)) . "</pre>";
    }
}

echo "<h2>Step 4: Check required tables exist</h2>";
$tables = ['users', 'subjects', 'quizzes', 'quiz_results', 'exam_records', 'exam_scores', 'student_profiles', 'sections'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        $count = $stmt->fetchColumn();
        echo "<p>✅ $table — $count rows</p>";
    } catch (Throwable $e) {
        echo "<p style='color:red'>❌ $table — " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}
