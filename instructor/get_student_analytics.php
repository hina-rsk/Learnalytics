<?php
// Suppress PHP notices/warnings so they don't corrupt JSON output
ini_set('display_errors', 0);
error_reporting(0);

session_start();
header('Content-Type: application/json');
require_once '../config/database.php';
require_once __DIR__ . '/analytics_functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$instructor_id = (int)$_SESSION['user_id'];
$student_id    = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$threshold     = isset($_GET['threshold']) && is_numeric($_GET['threshold']) ? (float)$_GET['threshold'] : 75.0;

if (!$student_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid student ID']);
    exit;
}

// 1. Get student info
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.email,
           COALESCE(sp.student_id_number, CONCAT('STU-', LPAD(u.id, 4, '0'))) as student_id_number
    FROM users u
    LEFT JOIN student_profiles sp ON sp.student_id = u.id
    WHERE u.id = ? AND u.role = 'student'
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo json_encode(['error' => 'Student not found']);
    exit;
}

// 2. Fetch all normalized assessments for teacher in teacher's subjects
$allAssessments = getTeacherNormalizedAssessments($pdo, $instructor_id);

// Filter to this student
$studentAssessments = [];
foreach ($allAssessments as $item) {
    if ((int)$item['student_id'] === $student_id) {
        $studentAssessments[] = $item;
    }
}

if (empty($studentAssessments)) {
    echo json_encode([
        'student_name'      => $student['name'],
        'student_email'     => $student['email'],
        'student_id_number' => $student['student_id_number'],
        'avg_quiz'          => 'N/A',
        'raw_avg'           => null,
        'quiz_count'        => 0,
        'status_label'      => 'No Record',
        'status_class'      => 'text-secondary',
        'subject_breakdown' => [],
        'quizzes'           => [],
        'recommendations'   => ['Student has no recorded exam or quiz submissions in your subjects.']
    ]);
    exit;
}

// Calculate overall student MPS
$studentPcts = array_column($studentAssessments, 'percentage');
$overallAvg  = round(array_sum($studentPcts) / count($studentPcts), 1);

// Subject breakdown
$subjectMap = [];
foreach ($studentAssessments as $item) {
    $subTitle = $item['subject_title'];
    if (!isset($subjectMap[$subTitle])) {
        $subjectMap[$subTitle] = [];
    }
    $subjectMap[$subTitle][] = (float)$item['percentage'];
}

$subjectBreakdown = [];
$atRiskSubjects = [];
$goodSubjects = [];

foreach ($subjectMap as $title => $scores) {
    $subAvg = round(array_sum($scores) / count($scores), 1);
    $statusInfo = getPerformanceStatusInfo($subAvg, $threshold);
    $subjectBreakdown[] = [
        'subject_title' => $title,
        'mps'           => $subAvg,
        'exams_count'   => count($scores),
        'status'        => $statusInfo['label'],
        'badge'         => $statusInfo['badge']
    ];

    if ($subAvg < $threshold) {
        $atRiskSubjects[] = "{$title} ({$subAvg}%)";
    } else {
        $goodSubjects[] = "{$title} ({$subAvg}%)";
    }
}

// Overall status info
$statusInfo = getPerformanceStatusInfo($overallAvg, $threshold);

// Map level to Bootstrap text class
$levelToClass = [
    'excellent'    => 'text-success',
    'good'         => 'text-primary',
    'satisfactory' => 'text-info',
    'at_risk'      => 'text-warning',
    'high_risk'    => 'text-danger',
    'no_data'      => 'text-secondary',
];
$statusTextClass = $levelToClass[$statusInfo['level']] ?? 'text-secondary';

// Recommendations
$recommendations = [];
if (!empty($atRiskSubjects)) {
    $recommendations[] = "Student is struggling in: " . implode(', ', $atRiskSubjects) . ". Recommend scheduling a review or remedial session on low-scoring topics.";
}
if (!empty($goodSubjects)) {
    $recommendations[] = "Student is maintaining good performance in: " . implode(', ', $goodSubjects) . ".";
}
if (empty($recommendations)) {
    $recommendations[] = "Encourage consistent study habits and regular participation in class activities.";
}

// Format assessments for display
$formatted = array_map(function($r) {
    return [
        'quiz_title'    => $r['title'],
        'subject_title' => $r['subject_title'],
        'score'         => (float)$r['percentage'],
        'raw_score'     => (float)$r['score'],
        'total_items'   => (int)$r['total_points'],
        'source_type'   => $r['type'],
        'taken_at'      => date('M d, Y H:i', strtotime($r['date']))
    ];
}, $studentAssessments);

echo json_encode([
    'student_name'      => $student['name'],
    'student_email'     => $student['email'],
    'student_id_number' => $student['student_id_number'],
    'avg_quiz'          => $overallAvg,
    'raw_avg'           => $overallAvg,
    'quiz_count'        => count($studentAssessments),
    'status_label'      => $statusInfo['label'],
    'status_class'      => $statusTextClass,
    'subject_breakdown' => $subjectBreakdown,
    'quizzes'           => $formatted,
    'recommendations'   => $recommendations
]);
exit;
