<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$program_id = $_GET['program_id'] ?? null;

if (!$program_id) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT s.id, s.name, ay.name as year_name 
    FROM sections s 
    JOIN academic_years ay ON s.academic_year_id = ay.id 
    WHERE s.program_id = ?
    ORDER BY s.name
");
$stmt->execute([$program_id]);
$sections = $stmt->fetchAll();

echo json_encode($sections);