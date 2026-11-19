<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/student_account.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$captchaAnswer = strtoupper(trim($_POST['captcha_answer'] ?? ''));
$captcha = $_SESSION['student_registration_captcha'] ?? null;
unset($_SESSION['student_registration_captcha']);

if (
    !is_array($captcha)
    || !isset($captcha['answer'], $captcha['created_at'])
    || time() - (int)$captcha['created_at'] > 600
    || !hash_equals((string)$captcha['answer'], $captchaAnswer)
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The CAPTCHA is incorrect or expired. Please try again.']);
    exit;
}

try {
    // Get form data
    $student_id = trim($_POST['student_id'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $middle_initial = trim($_POST['middle_initial'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $contact_number = trim($_POST['contact_number'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $program = trim($_POST['program'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $year_level = trim($_POST['year_level'] ?? '');

    validateStudentAccountInput($pdo, $_POST);

    // ============================================================
    // Profile Picture Upload
    // ============================================================

    $profile_picture_path = null;

    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_picture'];

        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception('Invalid image file type. Allowed types: JPEG, PNG, GIF, WebP');
        }

        // Validate file size (5MB)
        $max_size = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $max_size) {
            throw new Exception('File size must be less than 5MB.');
        }

        // Create upload directory if it doesn't exist
        $upload_dir = '../../uploads/student_profiles';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Generate unique filename
        $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
        $file_path = $upload_dir . '/' . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            throw new Exception('Failed to upload profile picture. Please try again.');
        }

        $profile_picture_path = 'uploads/student_profiles/' . $filename;
    }

    // ============================================================
    // Create User Account
    // ============================================================

    $account = createStudentAccount($pdo, $_POST, 'active', $profile_picture_path);
    echo json_encode([
        'success' => true,
        'message' => 'Registration successful! Your account has been created.',
        'student_id' => $account['student_id'],
        'full_name' => $account['full_name'],
        'email' => $account['email'],
        'program' => $account['program'],
        'year_level' => $account['year_level']
    ]);

} catch (Exception $e) {
    if (!empty($profile_picture_path) && file_exists('../../' . $profile_picture_path)) {
        unlink('../../' . $profile_picture_path);
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
