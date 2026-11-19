<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/RiskAssessmentService.php';

echo "=== TESTING RISK ASSESSMENT FOR JUAN DELA CRUZ ===\n";
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'juan.delacruz@learnalytics.com'");
$stmt->execute();
$juanId = (int)$stmt->fetchColumn();

$risk = RiskAssessmentService::calculateStudentRisk($pdo, $juanId, 1, false);

echo "Name: " . $risk['name'] . "\n";
echo "Student ID: " . $risk['student_id_number'] . "\n";
echo "Risk Level: " . $risk['risk_level'] . " (" . $risk['risk_badge_label'] . ")\n";
echo "Risk Score: " . $risk['risk_score'] . "/100\n";
echo "Overall Grade: " . $risk['overall_grade'] . "%\n";
echo "Previous Avg: " . $risk['previous_average'] . "%\n";
echo "Change: " . $risk['trend_change'] . "% (" . $risk['trend_direction'] . ")\n";
echo "Quiz Average: " . $risk['quiz_average'] . "%\n";
echo "Attendance: " . $risk['attendance_rate'] . "% (P: {$risk['attendance_present']}, A: {$risk['attendance_absent']}, L: {$risk['attendance_late']})\n";
echo "\nFactor Breakdown:\n";
foreach ($risk['factor_breakdown'] as $fb) {
    echo "  - {$fb['factor']}: {$fb['score']} [{$fb['status']}]\n";
}
echo "\nWhy at risk:\n";
foreach ($risk['reasons'] as $r) {
    echo "  * $r\n";
}
echo "\nRecommended Interventions:\n";
foreach ($risk['recommended_interventions'] as $i) {
    echo "  * $i\n";
}
echo "\nCourses:\n";
foreach ($risk['courses'] as $c) {
    echo "  - {$c['course_title']} ({$c['instructor_name']}): Grade={$c['grade']}%, Status={$c['status']}\n";
}
echo "\nInterventions Recorded: " . count($risk['interventions']) . "\n";
echo "Risk History Snapshots: " . count($risk['risk_history']) . "\n";

echo "\n=== DEPARTMENT 1 SUMMARY ===\n";
$summary = RiskAssessmentService::getDepartmentSummary($pdo, 1);
echo "Total Students: {$summary['total_students']}\n";
echo "At-Risk: {$summary['at_risk_count']}\n";
echo "Moderate: {$summary['moderate_count']}\n";
echo "Low Risk: {$summary['low_risk_count']}\n";
echo "Avg Grade: {$summary['avg_grade']}%\n";
echo "Avg Quiz: {$summary['avg_quiz_score']}%\n";
echo "Avg Attendance: {$summary['avg_attendance']}%\n";
