<?php
require 'config/database.php';
// Simulate instructor 47 session and test the endpoint for student 53 (Bea Dizon)
session_start();
$_SESSION['user_id']   = 47;
$_SESSION['user_role'] = 'instructor';
$_GET['student_id']    = 53;
$_GET['threshold']     = 75;

ob_start();
include 'instructor/get_student_analytics.php';
$output = ob_get_clean();

echo "Raw output:\n$output\n\n";
$decoded = json_decode($output, true);
if ($decoded === null) {
    echo "INVALID JSON: " . json_last_error_msg() . "\n";
} else {
    echo "VALID JSON!\n";
    echo "student_name:  " . $decoded['student_name'] . "\n";
    echo "avg_quiz:      " . $decoded['avg_quiz'] . "\n";
    echo "status_label:  " . $decoded['status_label'] . "\n";
    echo "status_class:  " . $decoded['status_class'] . "\n";
    echo "quiz_count:    " . $decoded['quiz_count'] . "\n";
}
