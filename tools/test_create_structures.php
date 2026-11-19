<?php
require_once __DIR__ . '/../config/database.php';

try {
    // Create a test department
    $deptName = 'Test Dept ' . time();
    $stmt = $pdo->prepare("INSERT INTO departments (name, description) VALUES (?, ?)");
    $stmt->execute([$deptName, 'Created by test script']);
    $deptId = $pdo->lastInsertId();

    // Create a test program
    $progName = 'Test Program ' . time();
    $stmt = $pdo->prepare("INSERT INTO programs (name, description, department_id) VALUES (?, ?, ?)");
    $stmt->execute([$progName, 'Created by test script', $deptId]);
    $progId = $pdo->lastInsertId();

    // Create academic year
    $yearName = 'AY ' . date('Y') . '/' . (date('Y')+1);
    $start = date('Y-09-01');
    $end = date('Y-06-30', strtotime('+1 year'));
    $stmt = $pdo->prepare("INSERT INTO academic_years (name, start_date, end_date, is_current) VALUES (?, ?, ?, ?)");
    $stmt->execute([$yearName, $start, $end, 0]);
    $yearId = $pdo->lastInsertId();

    // Create section
    $sectionName = 'Test Section ' . time();
    $stmt = $pdo->prepare("INSERT INTO sections (program_id, academic_year_id, name) VALUES (?, ?, ?)");
    $stmt->execute([$progId, $yearId, $sectionName]);
    $sectionId = $pdo->lastInsertId();

    echo "Created dept={$deptId}, program={$progId}, year={$yearId}, section={$sectionId}\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>
