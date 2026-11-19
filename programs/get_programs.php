<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;

if ($department_id <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, name 
        FROM programs 
        WHERE department_id = ? AND status = 'active' 
        ORDER BY name
    ");
    $stmt->execute([$department_id]);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($programs);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed']);
}
