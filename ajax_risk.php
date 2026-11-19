<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/RiskAssessmentService.php';

// Auth Guard: Dean or Admin only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['dean', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$currentUserRole = $_SESSION['user_role'];

// Get user's department
$userDeptId = null;
if ($currentUserRole === 'dean') {
    $stmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
    $stmt->execute([$currentUserId]);
    $userDeptId = $stmt->fetchColumn();
    $userDeptId = $userDeptId ? (int)$userDeptId : null;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_student_profile':
            $studentId = (int)($_GET['student_id'] ?? 0);
            if ($studentId <= 0) {
                throw new Exception('Invalid student ID.');
            }

            // Calculate student risk data
            $riskData = RiskAssessmentService::calculateStudentRisk($pdo, $studentId, $userDeptId, true);
            if (!$riskData['found']) {
                throw new Exception('Student profile not found.');
            }

            // Timeframe filtered trend points for multi-metric chart
            $assessmentEvents = $riskData['assessment_events'] ?? [];
            $attendanceRecords = $riskData['attendance_records'] ?? [];

            // Build time series for 7d, 30d, semester, year
            $timeframes = ['7d', '30d', 'semester', 'year'];
            $trendSeries = [];

            foreach ($timeframes as $tf) {
                $days = match($tf) {
                    '7d' => 7,
                    '30d' => 30,
                    'semester' => 120,
                    'year' => 365,
                };
                $cutoff = date('Y-m-d H:i:s', strtotime("-$days days"));

                // Filter events
                $tfEvents = array_filter($assessmentEvents, fn($e) => $e['date'] >= $cutoff);
                $tfAtt = array_filter($attendanceRecords, fn($a) => $a['attendance_date'] >= substr($cutoff, 0, 10));

                $trendSeries[$tf] = [
                    'assessments' => array_values($tfEvents),
                    'attendance'  => array_values($tfAtt),
                ];
            }

            $riskData['timeframe_trends'] = $trendSeries;

            echo json_encode([
                'success' => true,
                'data'    => $riskData,
            ]);
            break;

        case 'save_thresholds':
            $postData = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $deptId = $userDeptId;

            $success = RiskAssessmentService::saveThresholds($pdo, $deptId, $postData, $currentUserId);
            $updatedThresholds = RiskAssessmentService::getThresholds($pdo, $deptId);

            echo json_encode([
                'success'    => $success,
                'thresholds' => $updatedThresholds,
                'message'    => 'Risk thresholds successfully updated and recalculated.',
            ]);
            break;

        case 'record_intervention':
            $postData = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $studentId = (int)($postData['student_id'] ?? 0);
            if ($studentId <= 0) {
                throw new Exception('Invalid student ID for intervention.');
            }

            $postData['assigned_by'] = $currentUserId;
            $interventionId = RiskAssessmentService::recordIntervention($pdo, $postData);

            // Re-fetch updated interventions list
            $interventions = RiskAssessmentService::getStudentInterventions($pdo, $studentId);

            echo json_encode([
                'success'         => true,
                'intervention_id' => $interventionId,
                'interventions'   => $interventions,
                'message'         => 'Intervention successfully recorded.',
            ]);
            break;

        case 'update_intervention':
            $postData = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $interventionId = (int)($postData['intervention_id'] ?? 0);
            $status         = trim($postData['status'] ?? 'recommended');
            $notes          = isset($postData['notes']) ? trim($postData['notes']) : null;
            $gradeCurrent   = isset($postData['grade_current']) && is_numeric($postData['grade_current']) ? (float)$postData['grade_current'] : null;

            if ($interventionId <= 0) {
                throw new Exception('Invalid intervention ID.');
            }

            $success = RiskAssessmentService::updateIntervention($pdo, $interventionId, $status, $notes, $gradeCurrent);
            echo json_encode([
                'success' => $success,
                'message' => 'Intervention status successfully updated.',
            ]);
            break;

        case 'get_course_analysis':
            $courseId = (int)($_GET['course_id'] ?? 0);
            if ($courseId <= 0) {
                throw new Exception('Invalid course ID.');
            }

            $analysis = RiskAssessmentService::getCourseRiskAnalysis($pdo, $courseId, $userDeptId);
            echo json_encode([
                'success' => true,
                'data'    => $analysis,
            ]);
            break;

        case 'get_department_trends':
            $timeframe = $_GET['timeframe'] ?? 'monthly';
            // Provide aggregated department trend data
            $deptId = $userDeptId ?? (int)($_GET['department_id'] ?? 1);

            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(assessed_at, '%Y-%m-%d') as assess_date,
                       ROUND(AVG(grade_avg), 1) as avg_grade,
                       ROUND(AVG(quiz_avg), 1) as avg_quiz,
                       ROUND(AVG(attendance_rate), 1) as avg_att,
                       SUM(risk_level = 'at_risk') as at_risk_count
                FROM risk_assessments
                WHERE (department_id = ? OR department_id IS NULL)
                GROUP BY assess_date
                ORDER BY assess_date ASC
            ");
            $stmt->execute([$deptId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data'    => $history,
            ]);
            break;

        default:
            throw new Exception("Unknown action: $action");
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
