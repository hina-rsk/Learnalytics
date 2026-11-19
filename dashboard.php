<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

$role = $_SESSION['user_role'];
if ($role === 'admin') {
    header("Location: admin_dashboard.php");
    exit;
} elseif ($role === 'instructor') {
    header("Location: instructor_dashboard.php");
    exit;
} elseif ($role === 'student') {
    header("Location: student_dashboard.php");
    exit;
}

// Fallback: redirect to login if role is invalid
header("Location: auth/login.php");
exit;

