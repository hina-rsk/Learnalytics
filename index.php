<?php
session_start();

if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'];
    if ($role === 'admin') {
        header("Location: admin_dashboard.php");
    } elseif ($role === 'instructor') {
        header("Location: instructor_dashboard.php");
    } else {
        header("Location: student_dashboard.php");
    }
} else {
    header("Location: auth/login.php");
}
exit;
?>
