<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$instructor_id = (int)$_SESSION['user_id'];
$subject_id    = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

if ($subject_id <= 0) {
    echo json_encode(['error' => 'Invalid subject ID.']);
    exit;
}

// Verify ownership
$chk = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND instructor_id = ?");
$chk->execute([$subject_id, $instructor_id]);
if (!$chk->fetch()) {
    echo json_encode(['error' => 'Subject not found or access denied.']);
    exit;
}

// Fetch enrolled students with student ID number
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.email,
           COALESCE(sp.student_id_number, CONCAT('STU-', LPAD(u.id,4,'0'))) AS student_id_number
    FROM enrollments e
    INNER JOIN users u ON u.id = e.student_id
    LEFT JOIN student_profiles sp ON sp.student_id = u.id
    WHERE e.subject_id = ?
    ORDER BY u.name ASC
");
$stmt->execute([$subject_id]);
$students = $stmt->fetchAll();

echo json_encode(['students' => $students]);
exit;
