<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h2>Auth Folder Diagnostic (PHP " . phpversion() . ")</h2>";

// Test session
echo "<h3>1. Session</h3>";
session_start();
echo "<p style='color:green'>Session OK</p>";

// Test database include from auth/ perspective (same as login.php)
echo "<h3>2. Database Include (from auth/ folder)</h3>";
try {
    require_once '../config/database.php';
    echo "<p style='color:green'>DB connected OK!</p>";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Tables: " . implode(', ', $tables) . "</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>File: " . htmlspecialchars($e->getFile()) . " Line: " . $e->getLine() . "</p>";
}

// Test hash_equals
echo "<h3>3. Functions</h3>";
foreach (['hash_equals','password_verify','random_int','session_regenerate_id'] as $fn) {
    $ok = function_exists($fn);
    echo "<p style='color:" . ($ok?'green':'red') . "'>$fn: " . ($ok?'OK':'MISSING') . "</p>";
}

echo "<h3>Done</h3>";
?>
