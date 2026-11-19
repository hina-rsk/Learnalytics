<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/database.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $action = $_POST['action'] ?? '';

    // ============================================================
    // Check Student ID Availability
    // ============================================================

    if ($action === 'check_student_id') {
        $student_id = trim($_POST['student_id'] ?? '');

        if (empty($student_id)) {
            echo json_encode(['available' => false, 'message' => 'Student ID cannot be empty']);
            exit;
        }

        // Check both the login record and its student profile.
        $stmt = $pdo->prepare("SELECT id FROM student_profiles WHERE student_id_number = ? UNION SELECT id FROM users WHERE student_id_number = ? LIMIT 1");
        $stmt->execute([$student_id, $student_id]);

        if ($stmt->fetch()) {
            echo json_encode([
                'available' => false,
                'message' => 'Student ID already exists.'
            ]);
        } else {
            echo json_encode([
                'available' => true,
                'message' => 'Student ID is available'
            ]);
        }
        exit;
    }

    // ============================================================
    // Check Email Availability
    // ============================================================

    if ($action === 'check_email') {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['available' => false, 'message' => 'Invalid email address']);
            exit;
        }

        // Check if email already exists in users table
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            echo json_encode([
                'available' => false,
                'message' => 'This email address is already registered'
            ]);
        } else {
            echo json_encode([
                'available' => true,
                'message' => 'Email is available'
            ]);
        }
        exit;
    }

    // Invalid action
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
