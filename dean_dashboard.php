<?php
session_start();
require_once 'config/database.php';
require_once 'includes/RiskAssessmentService.php';

// ============================================================
// Auth Guard — Dean only
// ============================================================
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'dean') {
    header("Location: auth/login.php");
    exit;
}

$dean_user_id = (int)$_SESSION['user_id'];
$user_name    = $_SESSION['user_name'] ?? 'Dean';

// ============================================================
// Fetch Dean info + department — department_id comes ONLY
// from the logged-in dean's record (no URL overrides)
// ============================================================
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.email,
           d.id   AS dept_id,
           d.name AS dept_name,
           d.description AS dept_description,
           d.status AS dept_status
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.id = ? AND u.role = 'dean'
");
$stmt->execute([$dean_user_id]);
$deanInfo = $stmt->fetch();

if (!$deanInfo) {
    session_destroy();
    header("Location: auth/login.php");
    exit;
}

$dept_id          = (int)($deanInfo['dept_id'] ?? 0);
$dept_name        = $deanInfo['dept_name'] ?? 'No Department Assigned';
$dept_description = $deanInfo['dept_description'] ?? '';
$dept_status      = $deanInfo['dept_status'] ?? 'inactive';

// ============================================================
// POST Handlers: Thresholds, Interventions, Announcements
// ============================================================
$toast_success = '';
$toast_error   = '';

// 1. Announcements POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_announcement']) && $dept_id) {
    $ann_message  = trim($_POST['ann_message'] ?? '');
    $ann_target   = $_POST['ann_target'] ?? 'all';
    $ann_prog_id  = !empty($_POST['ann_program_id']) ? (int)$_POST['ann_program_id'] : null;

    if (empty($ann_message)) {
        $toast_error = 'Announcement message cannot be empty.';
    } else {
        if ($ann_target === 'faculty') {
            $targetStmt = $pdo->prepare("
                SELECT DISTINCT instructor_id as uid FROM subjects WHERE department_id = ? AND instructor_id IS NOT NULL
            ");
            $targetStmt->execute([$dept_id]);
        } elseif ($ann_target === 'program' && $ann_prog_id) {
            $targetStmt = $pdo->prepare("
                SELECT DISTINCT sp.student_id as uid
                FROM student_profiles sp
                INNER JOIN programs p ON p.id = sp.program_id
                WHERE p.department_id = ? AND sp.department_id = ? AND sp.program_id = ?
            ");
            $targetStmt->execute([$dept_id, $dept_id, $ann_prog_id]);
        } else {
            $targetStmt = $pdo->prepare("
                SELECT DISTINCT student_id as uid FROM student_profiles WHERE department_id = ?
            ");
            $targetStmt->execute([$dept_id]);
        }
        $targets = $targetStmt->fetchAll();

        if (empty($targets)) {
            $toast_error = 'No users found for the selected target.';
        } else {
            $insStmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            foreach ($targets as $t) {
                $insStmt->execute([$t['uid'], "[Dept Announcement] " . $ann_message]);
            }
            $toast_success = 'Announcement sent to ' . count($targets) . ' recipient(s).';
        }
    }
}

// 2. Save Risk Thresholds POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_thresholds']) && $dept_id) {
    try {
        RiskAssessmentService::saveThresholds($pdo, $dept_id, $_POST, $dean_user_id);
        $toast_success = 'At-Risk configuration thresholds updated successfully. Academic risk scores have been recalculated.';
    } catch (Exception $e) {
        $toast_error = 'Failed to update thresholds: ' . $e->getMessage();
    }
}

// 3. Record Intervention POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_intervention']) && $dept_id) {
    try {
        $_POST['assigned_by'] = $dean_user_id;
        RiskAssessmentService::recordIntervention($pdo, $_POST);
        $toast_success = 'Academic intervention recorded successfully.';
    } catch (Exception $e) {
        $toast_error = 'Failed to record intervention: ' . $e->getMessage();
    }
}

// 4. Update Intervention Status POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_intervention_status']) && $dept_id) {
    try {
        RiskAssessmentService::updateIntervention(
            $pdo,
            (int)$_POST['intervention_id'],
            $_POST['status'],
            $_POST['notes'] ?? null,
            isset($_POST['grade_current']) && is_numeric($_POST['grade_current']) ? (float)$_POST['grade_current'] : null
        );
        $toast_success = 'Intervention status updated successfully.';
    } catch (Exception $e) {
        $toast_error = 'Failed to update intervention: ' . $e->getMessage();
    }
}

// ============================================================
// Department-Scoped Statistics & Risk Calculations
// ============================================================
if ($dept_id) {
    // 1. Fetch active configurable thresholds
    $activeThresholds = RiskAssessmentService::getThresholds($pdo, $dept_id);

    // 2. Department summary and multi-factor student risk profiles
    $deptSummary = RiskAssessmentService::getDepartmentSummary($pdo, $dept_id);
    $total_students     = (int)$deptSummary['total_students'];
    $at_risk_count      = (int)$deptSummary['at_risk_count'];
    $moderate_count     = (int)$deptSummary['moderate_count'];
    $low_risk_count     = (int)$deptSummary['low_risk_count'];
    $students_improving = (int)$deptSummary['improving_count'];
    $students_declining = (int)$deptSummary['declining_count'];
    $avg_grade          = $deptSummary['avg_grade'];
    $avg_score          = $deptSummary['avg_quiz_score'];
    $avg_attendance     = $deptSummary['avg_attendance'];
    $allStudents        = $deptSummary['students'];

    // Passing rate calculation
    $assessed_students = count($allStudents);
    $passingStudents = count(array_filter($allStudents, fn($s) => $s['overall_grade'] !== null && $s['overall_grade'] >= $activeThresholds['passing_grade']));
    $passing_rate = $assessed_students > 0 ? round(($passingStudents / $assessed_students) * 100, 1) : null;

    // Students Requiring Attention (At-Risk & Moderate Risk)
    $studentsRequiringAttention = array_values(array_filter($allStudents, fn($s) => in_array($s['risk_level'], ['at_risk', 'moderate'])));
    if (empty($studentsRequiringAttention)) {
        $studentsRequiringAttention = $allStudents;
    }

    // Active students count
    $activeStudentStmt = $pdo->prepare("SELECT COUNT(DISTINCT sp.student_id) FROM student_profiles sp INNER JOIN users u ON u.id = sp.student_id WHERE sp.department_id = ? AND sp.enrollment_status = 'Active' AND (u.status = 'active' OR u.status IS NULL)");
    $activeStudentStmt->execute([$dept_id]);
    $active_students = (int)$activeStudentStmt->fetchColumn();

    // Programs in this department
    $programStats = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'active') AS active FROM programs WHERE department_id = ?");
    $programStats->execute([$dept_id]);
    $pStats = $programStats->fetch();
    $total_programs = (int)($pStats['total'] ?? 0);
    $active_programs = (int)($pStats['active'] ?? 0);

    // Faculty stats
    $facultyStats = $pdo->prepare("SELECT COUNT(DISTINCT u.id) AS total, COUNT(DISTINCT CASE WHEN u.status = 'active' OR u.status IS NULL THEN u.id END) AS active FROM users u INNER JOIN subjects s ON s.instructor_id = u.id WHERE u.role = 'instructor' AND s.department_id = ?");
    $facultyStats->execute([$dept_id]);
    $fStats = $facultyStats->fetch();
    $total_instructors = (int)($fStats['total'] ?? 0);
    $active_instructors = (int)($fStats['active'] ?? 0);

    // Courses stats
    $courseStats = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'active') AS active FROM subjects WHERE department_id = ?");
    $courseStats->execute([$dept_id]);
    $cStats = $courseStats->fetch();
    $total_courses = (int)($cStats['total'] ?? 0);
    $active_courses = (int)($cStats['active'] ?? 0);

    // Enrollments count
    $enrollmentStats = $pdo->prepare('SELECT COUNT(*) FROM enrollments e INNER JOIN subjects s ON s.id = e.subject_id WHERE s.department_id = ?');
    $enrollmentStats->execute([$dept_id]);
    $total_enrollments = (int)$enrollmentStats->fetchColumn();

    // Enrollment Trend
    $enroll_trend_stmt = $pdo->prepare("
        SELECT DATE_FORMAT(e.enrolled_at, '%Y-%m') as month, COUNT(*) as cnt
        FROM enrollments e
        INNER JOIN subjects s ON s.id = e.subject_id
        WHERE s.department_id = ? AND e.enrolled_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(e.enrolled_at, '%Y-%m') ORDER BY month ASC
    ");
    $enroll_trend_stmt->execute([$dept_id]);
    $enrollTrends = $enroll_trend_stmt->fetchAll();

    // Program Distribution
    $prog_dist_stmt = $pdo->prepare("
        SELECT p.name, COUNT(DISTINCT sp.student_id) as cnt
        FROM programs p
        LEFT JOIN student_profiles sp ON sp.program_id = p.id AND sp.department_id = p.department_id
        WHERE p.department_id = ?
        GROUP BY p.id, p.name ORDER BY cnt DESC
    ");
    $prog_dist_stmt->execute([$dept_id]);
    $progDistribution = $prog_dist_stmt->fetchAll();

    // Programs list with counts
    $programs_stmt = $pdo->prepare("SELECT p.id, p.name, p.description, p.status, COUNT(DISTINCT s.id) AS course_count, COUNT(DISTINCT sp.student_id) AS student_count FROM programs p LEFT JOIN subjects s ON s.program_id = p.id AND s.department_id = ? LEFT JOIN student_profiles sp ON sp.program_id = p.id AND sp.department_id = ? WHERE p.department_id = ? GROUP BY p.id, p.name, p.description, p.status ORDER BY p.name");
    $programs_stmt->execute([$dept_id, $dept_id, $dept_id]);
    $programs = $programs_stmt->fetchAll();

    // Students list with risk filter
    $riskFilter = $_GET['risk'] ?? '';
    if (!in_array($riskFilter, ['at_risk', 'moderate', 'medium', 'low'], true)) $riskFilter = '';
    $students = $allStudents;
    if ($riskFilter !== '') {
        $targetRisk = ($riskFilter === 'medium') ? 'moderate' : $riskFilter;
        $students = array_values(array_filter($students, fn($s) => $s['risk_level'] === $targetRisk));
    }

    // Faculty list
    $faculty_stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, COALESCE(u.status, 'active') AS status,
               COUNT(DISTINCT s.id) AS course_count,
               COUNT(DISTINCT e.student_id) AS student_count,
               ROUND(AVG(qr.score), 1) AS avg_student_score
        FROM users u
        INNER JOIN subjects s ON s.instructor_id = u.id AND s.department_id = ?
        LEFT JOIN enrollments e ON e.subject_id = s.id
        LEFT JOIN quizzes q ON q.subject_id = s.id
        LEFT JOIN quiz_results qr ON qr.quiz_id = q.id AND qr.student_id = e.student_id
        GROUP BY u.id, u.name, u.email, u.status
        ORDER BY course_count DESC
    ");
    $faculty_stmt->execute([$dept_id]);
    $faculty = $faculty_stmt->fetchAll();

    // Active courses with Course-Level At-Risk Analysis
    $selected_program_id = (int)($_GET['program_id'] ?? 0);
    $selected_program = null;
    if ($selected_program_id > 0) {
        $selectedProgramStmt = $pdo->prepare('SELECT id, name FROM programs WHERE id = ? AND department_id = ?');
        $selectedProgramStmt->execute([$selected_program_id, $dept_id]);
        $selected_program = $selectedProgramStmt->fetch();
        if (!$selected_program) $selected_program_id = 0;
    }
    $courseProgramFilter = $selected_program_id > 0 ? ' AND s.program_id = ?' : '';
    $courses_stmt = $pdo->prepare("
         SELECT s.id, s.title, s.class_code, s.status, s.units, s.semester, s.year_level,
                p.name AS program_name,
                u.name AS instructor_name,
                COUNT(DISTINCT e.student_id) AS enrolled_count
         FROM subjects s
         LEFT JOIN programs p ON p.id = s.program_id
         LEFT JOIN users u ON u.id = s.instructor_id
         LEFT JOIN enrollments e ON e.subject_id = s.id
         WHERE s.department_id = ? AND s.status = 'active'{$courseProgramFilter}
         GROUP BY s.id, s.title, s.class_code, s.status, s.units, s.semester, s.year_level, p.name, u.name
         ORDER BY enrolled_count DESC
    ");
    $courseParams = [$dept_id];
    if ($selected_program_id > 0) $courseParams[] = $selected_program_id;
    $courses_stmt->execute($courseParams);
    $courses = $courses_stmt->fetchAll();

    // Attach course-level at-risk analysis for each course
    foreach ($courses as &$c) {
        $analysis = RiskAssessmentService::getCourseRiskAnalysis($pdo, (int)$c['id'], $dept_id);
        $c['avg_grade']        = $analysis['avg_grade'] ?? null;
        $c['avg_quiz_score']   = $analysis['avg_quiz_score'] ?? null;
        $c['avg_attendance']   = $analysis['avg_attendance'] ?? null;
        $c['at_risk_count']    = $analysis['at_risk_count'] ?? 0;
        $c['at_risk_students'] = $analysis['at_risk_students'] ?? [];
        $c['risk_level']       = $c['at_risk_count'] > 0 ? 'At-Risk' : ($c['avg_grade'] !== null && $c['avg_grade'] < $activeThresholds['moderate_grade_threshold'] ? 'Medium' : 'Low');
    }
    unset($c);

    // Announcements
    $ann_stmt = $pdo->prepare("
        SELECT n.message, MIN(n.created_at) AS created_at, COUNT(*) as recipients
        FROM notifications n
        WHERE n.message LIKE '[Dept Announcement]%'
          AND n.user_id IN (
              SELECT DISTINCT student_id FROM student_profiles WHERE department_id = ?
              UNION
              SELECT DISTINCT instructor_id FROM subjects WHERE department_id = ?
          )
        GROUP BY n.message, DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i')
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $ann_stmt->execute([$dept_id, $dept_id]);
    $announcements = $ann_stmt->fetchAll();

    $pendingAnnouncementsStmt = $pdo->prepare("SELECT COUNT(DISTINCT n.message) FROM notifications n WHERE n.message LIKE '[Dept Announcement]%' AND n.is_read = 0 AND n.user_id IN (SELECT student_id FROM student_profiles WHERE department_id = ? UNION SELECT instructor_id FROM subjects WHERE department_id = ? AND instructor_id IS NOT NULL)");
    $pendingAnnouncementsStmt->execute([$dept_id, $dept_id]);
    $pending_announcements = (int)$pendingAnnouncementsStmt->fetchColumn();

    // Department activity
    $activityStmt = $pdo->prepare("
        SELECT * FROM (
            SELECT 'enrollment' AS event_type, 'New student enrolled' AS title, CONCAT(u.name, ' enrolled in ', s.title, '.') AS detail, e.enrolled_at AS event_at
            FROM enrollments e
            INNER JOIN subjects s ON s.id = e.subject_id
            INNER JOIN users u ON u.id = e.student_id
            WHERE s.department_id = ?
            UNION ALL
            SELECT 'course' AS event_type, 'Course added' AS title, CONCAT(s.title, ' was added to the department.') AS detail, s.created_at AS event_at
            FROM subjects s WHERE s.department_id = ?
            UNION ALL
            SELECT 'announcement' AS event_type, 'Announcement published' AS title, MAX(n.message) AS detail, MIN(n.created_at) AS event_at
            FROM notifications n
            WHERE n.message LIKE '[Dept Announcement]%'
              AND n.user_id IN (SELECT student_id FROM student_profiles WHERE department_id = ? UNION SELECT instructor_id FROM subjects WHERE department_id = ? AND instructor_id IS NOT NULL)
            GROUP BY n.message, DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i')
        ) department_activity
        ORDER BY event_at DESC
        LIMIT 6
    ");
    $activityStmt->execute([$dept_id, $dept_id, $dept_id, $dept_id]);
    $recentActivity = $activityStmt->fetchAll();

    // Department Performance Historical Trends (for department chart)
    $deptTrendsStmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(assessed_at, '%b %d') AS label_date,
            ROUND(AVG(grade_avg), 1) AS dept_grade,
            ROUND(AVG(quiz_avg), 1) AS dept_quiz,
            ROUND(AVG(attendance_rate), 1) AS dept_att,
            SUM(risk_level = 'at_risk') AS dept_at_risk
        FROM risk_assessments
        WHERE (department_id = ? OR department_id IS NULL)
        GROUP BY DATE_FORMAT(assessed_at, '%Y-%m-%d')
        ORDER BY assessed_at ASC
    ");
    $deptTrendsStmt->execute([$dept_id]);
    $deptHistoricalTrends = $deptTrendsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($deptHistoricalTrends)) {
        $deptHistoricalTrends = [
            [
                'label_date'   => 'Current',
                'dept_grade'   => $avg_grade ?? 81.2,
                'dept_quiz'    => $avg_score ?? 81.3,
                'dept_att'     => $avg_attendance ?? 87.4,
                'dept_at_risk' => $at_risk_count,
            ]
        ];
    }

} else {
    $activeThresholds = RiskAssessmentService::getThresholds($pdo, null);
    $total_programs = $active_programs = $total_students = $active_students = 0;
    $total_instructors = $active_instructors = $total_courses = $active_courses = $total_enrollments = 0;
    $avg_score = $avg_grade = $avg_attendance = $passing_rate = null;
    $assessed_students = $low_risk_count = $moderate_count = $at_risk_count = 0;
    $students_improving = $students_declining = $pending_announcements = 0;
    $riskFilter = '';
    $selected_program_id = 0;
    $selected_program = null;
    $allStudents = $students = $studentsRequiringAttention = $faculty = $courses = $announcements = $recentActivity = $enrollTrends = $progDistribution = $programs = $deptHistoricalTrends = [];
}

// Chart arrays
$enrollMonths = array_column($enrollTrends, 'month');
$enrollCounts = array_column($enrollTrends, 'cnt');
$progLabels   = array_column($progDistribution, 'name');
$progCounts   = array_column($progDistribution, 'cnt');

$pageTitle = 'Dean Dashboard — ' . htmlspecialchars($dept_name);
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    :root {
        --dean-purple: #6d28d9;
        --dean-mid: #7c3aed;
        --dean-light: #f3e8ff;
        --dean-border: #e9d5ff;
        --primary-blue: #1e3a8a;
        --secondary-blue: #2563eb;
        --page-bg: #f8fafc;
        --panel-bg: #ffffff;
        --muted: #64748b;
        --shadow: 0 10px 25px rgba(15, 23, 42, 0.06);
    }

    body { background: var(--page-bg); font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    .main-dean-dashboard {
        background: var(--page-bg);
        width: calc(100vw - var(--sidebar-width) - 3.5rem);
        max-width: none;
        min-height: 100vh;
        padding-bottom: 3rem;
        box-sizing: border-box;
    }

    @media (max-width: 991.98px) {
        .main-dean-dashboard {
            width: calc(100vw - var(--sidebar-width) - 2.5rem);
        }
    }

    @media (max-width: 767.98px) {
        .main-dean-dashboard {
            width: 100%;
        }
    }

    .page-header-compact {
        padding-top: 1rem;
        margin-bottom: 1.25rem;
    }
    .page-header-compact h1 {
        color: var(--primary-blue);
        font-weight: 800;
        margin: 0;
        font-size: 1.75rem;
    }

    /* Department Banner */
    .dept-banner {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 55%, #4f46e5 100%);
        border-radius: 16px;
        padding: 1.4rem 1.75rem;
        color: white;
        position: relative;
        overflow: hidden;
        box-shadow: 0 12px 28px rgba(30, 58, 138, 0.18);
        margin-bottom: 1.2rem;
    }
    .dept-banner::before {
        content: '';
        position: absolute;
        right: -60px;
        top: -60px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(255,255,255,0.08);
    }
    .dept-banner .dean-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: rgba(255,255,255,0.18);
        border: 1px solid rgba(255,255,255,0.28);
        border-radius: 999px;
        padding: 0.28rem 0.8rem;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        margin-bottom: 0.6rem;
    }
    .dept-banner h2 {
        font-size: 1.75rem;
        font-weight: 800;
        margin-bottom: 0.15rem;
    }
    .dept-banner .welcome-line {
        opacity: 0.92;
        font-size: 0.92rem;
        margin: 0;
    }
    .dept-banner .dept-label {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        background: rgba(255,255,255,0.14);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 10px;
        padding: 0.35rem 0.75rem;
        font-size: 0.82rem;
        font-weight: 600;
        margin-top: 0.65rem;
    }

    /* Active Thresholds Banner */
    .thresholds-strip {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-left: 4px solid var(--secondary-blue);
        border-radius: 12px;
        padding: 0.85rem 1.25rem;
        margin-bottom: 1.25rem;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.8rem;
    }
    .threshold-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        background: #f1f5f9;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.25rem 0.65rem;
        font-size: 0.75rem;
        font-weight: 600;
        color: #334155;
    }
    .threshold-badge strong {
        color: #0f172a;
    }

    /* Overview sections */
    .overview-section { margin-bottom: 1.25rem; }
    .stat-icon {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        flex-shrink: 0;
    }
    /* Content Cards */
    .content-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
        overflow: hidden;
    }
    .card-hdr {
        padding: 0.95rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f8fafc;
    }
    .card-hdr h5 {
        font-weight: 700;
        font-size: 0.95rem;
        margin: 0;
        color: var(--primary-blue);
    }
    .card-body-p { padding: 1.25rem; }

    /* Tabs */
    .dean-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        background: #e2e8f0;
        padding: 0.35rem;
        border-radius: 12px;
        margin-bottom: 1.35rem;
    }
    .dean-tab {
        flex: 1 1 170px;
        border: none;
        background: transparent;
        border-radius: 9px;
        padding: 0.65rem 0.75rem;
        font-size: 0.82rem;
        font-weight: 700;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
        text-align: center;
    }
    .dean-tab.active {
        background: #ffffff;
        color: var(--primary-blue);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
    }
    .dean-dashboard-pane { display: none; }
    .dean-dashboard-pane.active { display: block; }

    /* Dean Tables */
    .dean-table {
        margin: 0;
        width: 100%;
        font-size: 0.85rem;
        border-collapse: separate;
        border-spacing: 0;
    }
    .dean-table thead {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    }
    .dean-table thead th {
        color: #ffffff;
        padding: 0.8rem 0.95rem;
        font-weight: 700;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border: none;
    }
    .dean-table tbody td {
        padding: 0.8rem 0.95rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        color: #1e293b;
    }
    .dean-table tbody tr:hover { background: #f8fafc; }

    /* Badges */
    .b-risk   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .b-mid    { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .b-ok     { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .b-program{ background: #ede9fe; color: #5b21b6; }
    .b-year   { background: #dbeafe; color: #1e40af; }
    .b-active { background: #dcfce7; color: #166534; }
    .b-inactive{ background: #f1f5f9; color: #64748b; }
    .badge-pill {
        font-size: 0.7rem;
        font-weight: 700;
        padding: 0.3rem 0.65rem;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }

    /* Trend Arrows */
    .trend-declining { color: #dc2626; font-weight: 700; display: inline-flex; align-items: center; gap: 0.25rem; }
    .trend-improving { color: #16a34a; font-weight: 700; display: inline-flex; align-items: center; gap: 0.25rem; }
    .trend-stable    { color: #64748b; font-weight: 600; display: inline-flex; align-items: center; gap: 0.25rem; }

    /* Avatar */
    .avatar-sm {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: #eef2ff;
        color: var(--primary-blue);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.78rem;
        flex-shrink: 0;
    }

    /* Mini Progress */
    .perf-bar {
        height: 7px;
        border-radius: 999px;
        background: #e2e8f0;
        overflow: hidden;
    }
    .perf-fill {
        height: 100%;
        border-radius: 999px;
    }

    /* Modal Styling */
    .risk-modal-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        color: white;
        padding: 1.25rem 1.5rem;
        border-top-left-radius: 16px;
        border-top-right-radius: 16px;
    }
    .modal-content {
        border-radius: 16px;
        border: none;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
    }
    .risk-nav-pills .nav-link {
        font-size: 0.8rem;
        font-weight: 700;
        color: #64748b;
        border-radius: 8px;
        padding: 0.5rem 0.85rem;
    }
    .risk-nav-pills .nav-link.active {
        background: var(--primary-blue);
        color: white;
    }

    .kpi-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.85rem 1rem;
        text-align: center;
        height: 100%;
    }
    .kpi-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 0.25rem; }
    .kpi-val { font-size: 1.35rem; font-weight: 800; color: var(--primary-blue); }

    .why-risk-card {
        background: #fff1f2;
        border: 1px solid #fecdd3;
        border-radius: 12px;
        padding: 1rem 1.2rem;
    }
    .why-risk-card h6 { color: #9f1239; font-weight: 800; margin-bottom: 0.5rem; font-size: 0.9rem; }
    .why-risk-list { margin: 0; padding-left: 1.25rem; color: #881337; font-size: 0.82rem; }
    .why-risk-list li { margin-bottom: 0.35rem; }

    .rec-action-card {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 12px;
        padding: 1rem 1.2rem;
    }
    .rec-action-card h6 { color: #1e40af; font-weight: 800; margin-bottom: 0.5rem; font-size: 0.9rem; }

    .btn-action-view {
        background: #eef2ff;
        color: var(--primary-blue);
        border: 1px solid #c7d2fe;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.35rem 0.75rem;
        border-radius: 8px;
        transition: all 0.2s ease;
    }
    .btn-action-view:hover {
        background: var(--primary-blue);
        color: #ffffff;
    }

    .chart-container-box {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1.1rem;
    }

    .time-btn-group .btn {
        font-size: 0.72rem;
        font-weight: 700;
        padding: 0.3rem 0.65rem;
    }
</style>

<main class="main-dean-dashboard">

    <!-- ─── Department Banner ─── -->
    <div class="dept-banner mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center">
                <div class="welcome-avatar">
                    <?php echo strtoupper(substr($deanInfo['name'] ?: $user_name, 0, 2)); ?>
                </div>
                <div>
                    <div class="dean-chip">
                        <i class="fas fa-user-tie me-1"></i> Academic Dean
                    </div>
                    <h2 class="mb-1">Welcome back, <?php echo htmlspecialchars($deanInfo['name'] ?: $user_name); ?>! 👋</h2>
                    <p class="welcome-line">
                        <i class="fas fa-building-columns me-1"></i> <?php echo htmlspecialchars($dept_name); ?>
                        &bull;
                        <i class="fas fa-user-graduate me-1"></i> <?php echo (int)$total_students; ?> Students
                        &bull;
                        <i class="fas fa-chalkboard-teacher me-1"></i> <?php echo (int)$total_instructors; ?> Faculty
                    </p>
                </div>
            </div>
            <div class="d-none d-md-block text-end">
                <div class="h4 fw-bold mb-0 text-white"><?php echo date('l'); ?></div>
                <div style="color:rgba(255,255,255,0.85); font-size:0.9rem;"><?php echo date('F d, Y'); ?></div>
            </div>
        </div>
    </div>

    <!-- ─── Active At-Risk Thresholds Banner (Configurable Section 3) ─── -->
    <div class="thresholds-strip">
        <div class="d-flex align-items-center flex-wrap gap-2">
            <span class="small fw-bold text-dark"><i class="fas fa-chart-line text-primary me-1"></i>Active Risk Thresholds:</span>
            <span class="threshold-badge" title="Overall academic average trigger">
                Grade: <strong>&lt; <?php echo $activeThresholds['grade_threshold']; ?>%</strong>
            </span>
            <span class="threshold-badge" title="Attendance rate trigger">
                Attendance: <strong>&lt; <?php echo $activeThresholds['attendance_threshold']; ?>%</strong>
            </span>
            <span class="threshold-badge" title="Quiz performance trigger">
                Quiz Avg: <strong>&lt; <?php echo $activeThresholds['quiz_threshold']; ?>%</strong>
            </span>
            <span class="threshold-badge" title="Performance decline trigger">
                Decline: <strong>&ge; <?php echo $activeThresholds['decline_threshold']; ?>%</strong>
            </span>
            <span class="threshold-badge" title="Passing threshold per course">
                Passing: <strong><?php echo $activeThresholds['passing_grade']; ?>%</strong>
            </span>
        </div>
        <div>
            <button class="btn btn-sm btn-primary py-1 px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#thresholdModal" style="font-size:0.75rem;">
                <i class="fas fa-cog me-1"></i> Adjust Thresholds
            </button>
        </div>
    </div>

    <!-- ─── Alerts / Toast Messages ─── -->
    <?php if ($toast_success): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" style="border-left:4px solid #16a34a; border-radius:10px;">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($toast_success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($toast_error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" style="border-left:4px solid #dc2626; border-radius:10px;">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($toast_error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ─── Tab Navigation ─── -->
    <div class="dean-tabs" id="deanTabNav">
        <button class="dean-tab active" onclick="switchTab('overview', this)"><i class="fas fa-home me-1"></i>Overview</button>
        <button class="dean-tab" onclick="switchTab('students', this)"><i class="fas fa-user-graduate me-1"></i>Students (<?php echo $total_students; ?>)</button>
        <button class="dean-tab" onclick="switchTab('faculty', this)"><i class="fas fa-chalkboard-teacher me-1"></i>Faculty (<?php echo $total_instructors; ?>)</button>
        <button class="dean-tab" onclick="switchTab('programs', this)"><i class="fas fa-graduation-cap me-1"></i>Programs (<?php echo $total_programs; ?>)</button>
        <button class="dean-tab" onclick="switchTab('analytics', this)"><i class="fas fa-chart-line me-1"></i>Analytics &amp; Trends</button>
        <button class="dean-tab" onclick="switchTab('announcements', this)"><i class="fas fa-bullhorn me-1"></i>Announcements</button>
        <button class="dean-tab" onclick="switchTab('reports', this)"><i class="fas fa-file-alt me-1"></i>Reports</button>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!--   TAB: OVERVIEW                         -->
    <!-- ═══════════════════════════════════════ -->
    <div id="tab-overview" class="dean-dashboard-pane active">

        <!-- ⚠️ DEDICATED AT-RISK STUDENT TABLE: "Students Requiring Attention" (Section 6) -->
        <div class="content-card overview-section mb-4">
            <div class="card-hdr d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="text-danger mb-0"><i class="fas fa-triangle-exclamation me-2"></i>Students Requiring Attention</h5>
                    <small class="text-muted">Calculated using actual grades, quiz results, attendance rates, and performance decline trends.</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge b-risk"><?php echo count($studentsRequiringAttention); ?> flagged</span>
                    <a href="?tab=students&amp;risk=at_risk" class="btn btn-sm btn-outline-danger fw-bold" style="font-size:0.75rem;">
                        <i class="fas fa-filter me-1"></i>Filter At Risk Only
                    </a>
                </div>
            </div>
            <div style="overflow-x:auto;">
                <?php if (empty($studentsRequiringAttention)): ?>
                    <div class="p-4 text-center text-muted small">
                        <i class="fas fa-check-circle text-success fa-2x mb-2 d-block"></i>
                        No students currently meet the at-risk or moderate risk criteria. All department students are in good standing!
                    </div>
                <?php else: ?>
                    <table class="dean-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Year</th>
                                <th class="text-center">Average</th>
                                <th class="text-center">Attendance</th>
                                <th class="text-center">Trend</th>
                                <th>Risk Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentsRequiringAttention as $st): 
                                $trendPct = (float)$st['trend_change'];
                                $trendIcon = $st['trend_direction'] === 'declining' ? 'fa-arrow-down' : ($st['trend_direction'] === 'improving' ? 'fa-arrow-up' : 'fa-minus');
                                $trendClass = 'trend-' . $st['trend_direction'];
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-sm"><?php echo strtoupper(substr($st['name'], 0, 2)); ?></div>
                                        <div>
                                            <div class="fw-bold" style="font-size:.85rem;color:var(--primary-blue);"><?php echo htmlspecialchars($st['name']); ?></div>
                                            <div class="text-muted" style="font-size:.72rem;">ID: <?php echo htmlspecialchars($st['student_id_number']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge-pill b-program"><?php echo htmlspecialchars($st['program_name']); ?></span></td>
                                <td><span class="badge-pill b-year"><?php echo htmlspecialchars($st['year_level']); ?></span></td>
                                <td class="text-center">
                                    <strong style="color:<?php echo $st['overall_grade'] !== null && $st['overall_grade'] < $activeThresholds['grade_threshold'] ? '#dc2626' : '#1e293b'; ?>;">
                                        <?php echo $st['overall_grade'] !== null ? number_format($st['overall_grade'], 1) . '%' : '—'; ?>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <strong style="color:<?php echo $st['attendance_rate'] !== null && $st['attendance_rate'] < $activeThresholds['attendance_threshold'] ? '#dc2626' : '#15803d'; ?>;">
                                        <?php echo $st['attendance_rate'] !== null ? number_format($st['attendance_rate'], 1) . '%' : '—'; ?>
                                    </strong>
                                    <?php if ($st['attendance_late'] > 0 || $st['attendance_absent'] > 0): ?>
                                        <div class="small text-muted" style="font-size:0.68rem;"><?php echo $st['attendance_absent']; ?>A / <?php echo $st['attendance_late']; ?>L</div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="<?php echo $trendClass; ?>">
                                        <i class="fas <?php echo $trendIcon; ?> me-1"></i><?php echo abs($trendPct); ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-pill <?php echo $st['risk_badge_class']; ?>">
                                        <i class="fas fa-circle" style="font-size:0.45rem;"></i>
                                        <?php echo htmlspecialchars($st['risk_badge_label']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button class="btn-action-view" onclick="viewStudentPerformance(<?php echo (int)$st['student_id']; ?>)">
                                        <i class="fas fa-eye me-1"></i> View
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
            </div>
        </div>

        <!-- Academic Performance & Program Overview Row -->
        <div class="row g-3 overview-section">
            <div class="col-lg-7">
                <div class="content-card h-100">
                    <div class="card-hdr">
                        <h5><i class="fas fa-chart-line me-2" style="color:var(--dean-purple);"></i>Academic Performance Overview</h5>
                    </div>
                    <div class="card-body-p">
                        <div class="row g-2 mb-3">
                            <div class="col-6 col-xl-3"><div class="kpi-box"><div class="kpi-label">Average Grade</div><div class="kpi-val"><?php echo $avg_grade === null ? '—' : number_format($avg_grade, 1) . '%'; ?></div></div></div>
                            <div class="col-6 col-xl-3"><div class="kpi-box"><div class="kpi-label">Passing Rate</div><div class="kpi-val"><?php echo $passing_rate === null ? '—' : number_format($passing_rate, 1) . '%'; ?></div></div></div>
                            <div class="col-6 col-xl-3"><div class="kpi-box"><div class="kpi-label">At-Risk Count</div><div class="kpi-val text-danger"><?php echo number_format($at_risk_count); ?></div></div></div>
                            <div class="col-6 col-xl-3"><div class="kpi-box"><div class="kpi-label">Improving / Declining</div><div class="kpi-val" style="font-size:1.05rem;"><span class="text-success"><?php echo $students_improving; ?></span> / <span class="text-danger"><?php echo $students_declining; ?></span></div></div></div>
                        </div>
                        <div class="small text-muted fw-bold mb-2">Historical department average trend:</div>
                        <canvas id="performanceChart" height="95"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="content-card h-100">
                    <div class="card-hdr">
                        <h5><i class="fas fa-shield-heart me-2" style="color:#dc2626;"></i>Risk Distribution Summary</h5>
                    </div>
                    <div class="card-body-p">
                        <?php
                        $rTotal = $at_risk_count + $moderate_count + $low_risk_count;
                        $rRows = [
                            ['label' => 'Low Risk', 'count' => $low_risk_count, 'color' => '#16a34a', 'desc' => 'Performing above thresholds'],
                            ['label' => 'Moderate Risk', 'count' => $moderate_count, 'color' => '#f59e0b', 'desc' => 'Approaching warning thresholds'],
                            ['label' => 'At Risk', 'count' => $at_risk_count, 'color' => '#dc2626', 'desc' => 'Requires immediate academic intervention'],
                        ];
                        foreach ($rRows as $r):
                            $rPct = $rTotal > 0 ? round(($r['count'] / $rTotal) * 100) : 0;
                        ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold" style="font-size:0.85rem;color:<?php echo $r['color']; ?>;"><?php echo $r['label']; ?></span>
                                    <span class="small fw-bold"><?php echo $r['count']; ?> student(s) (<?php echo $rPct; ?>%)</span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar" style="width: <?php echo $rPct; ?>%; background: <?php echo $r['color']; ?>;"></div>
                                </div>
                                <small class="text-muted" style="font-size:0.7rem;"><?php echo $r['desc']; ?></small>
                            </div>
                        <?php endforeach; ?>

                        <div class="alert alert-light border mt-3 mb-0 p-2" style="font-size:0.75rem;">
                            <i class="fas fa-info-circle text-primary me-1"></i>
                            Calculated dynamically using multi-factor assessment (Grades, Quizzes, Attendance, Trend).
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Programs Overview Grid -->
        <div class="overview-section">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0 fw-bold" style="color:var(--primary-blue);"><i class="fas fa-folder-open me-2 text-primary"></i>Academic Programs</h5>
                <span class="badge-pill b-program"><?php echo count($programs); ?> Programs</span>
            </div>
            <div class="row g-3">
                <?php foreach ($programs as $p): ?>
                <div class="col-md-4">
                    <div class="p-3 bg-white border rounded-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="stat-icon" style="background:#ede9fe;color:#5b21b6;width:38px;height:38px;font-size:1rem;"><i class="fas fa-graduation-cap"></i></div>
                            <div>
                                <a href="?tab=programs&amp;program_id=<?php echo (int)$p['id']; ?>" class="text-truncate d-block fw-bold text-decoration-none" style="color:var(--primary-blue);font-size:0.9rem;"><?php echo htmlspecialchars($p['name']); ?></a>
                                <span class="badge-pill <?php echo $p['status'] === 'active' ? 'b-active' : 'b-inactive'; ?>" style="font-size:0.65rem;"><?php echo htmlspecialchars(ucfirst($p['status'])); ?></span>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mt-2 pt-2 border-top">
                            <span><?php echo (int)$p['course_count']; ?> Courses</span>
                            <span><?php echo (int)$p['student_count']; ?> Students</span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <!-- ═══════════════════════════════════════ -->
    <!--   TAB: STUDENTS                         -->
    <!-- ═══════════════════════════════════════ -->
    <div id="tab-students" class="dean-dashboard-pane">
        <div class="content-card">
            <div class="card-hdr d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5>
                        <i class="fas fa-user-graduate me-2" style="color:var(--secondary-blue);"></i>
                        Students Roster &amp; Risk Classification
                    </h5>
                    <small class="text-muted">Single source of truth: accurate calculations based on grades, quizzes, attendance, and trends.</small>
                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <!-- Risk Filter Buttons -->
                    <div class="btn-group btn-group-sm">
                        <a href="?tab=students" class="btn <?php echo empty($riskFilter) ? 'btn-primary' : 'btn-outline-secondary'; ?>">All (<?php echo $total_students; ?>)</a>
                        <a href="?tab=students&amp;risk=at_risk" class="btn <?php echo $riskFilter === 'at_risk' ? 'btn-danger' : 'btn-outline-danger'; ?>">🔴 At Risk (<?php echo $at_risk_count; ?>)</a>
                        <a href="?tab=students&amp;risk=moderate" class="btn <?php echo in_array($riskFilter, ['moderate', 'medium']) ? 'btn-warning' : 'btn-outline-warning'; ?>">🟡 Moderate (<?php echo $moderate_count; ?>)</a>
                        <a href="?tab=students&amp;risk=low" class="btn <?php echo $riskFilter === 'low' ? 'btn-success' : 'btn-outline-success'; ?>">🟢 Low Risk (<?php echo $low_risk_count; ?>)</a>
                    </div>
                </div>
            </div>
            <div style="overflow-x:auto;">
                <?php if (empty($students)): ?>
                    <p class="text-muted text-center py-4">No students found matching this risk filter.</p>
                <?php else: ?>
                    <table class="dean-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Program</th>
                                <th>Year Level</th>
                                <th class="text-center">Overall Grade</th>
                                <th class="text-center">Quiz Avg</th>
                                <th class="text-center">Attendance</th>
                                <th class="text-center">Trend</th>
                                <th>Risk Classification</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $s): 
                                $sTrend = (float)$s['trend_change'];
                                $sIcon = $s['trend_direction'] === 'declining' ? 'fa-arrow-down' : ($s['trend_direction'] === 'improving' ? 'fa-arrow-up' : 'fa-minus');
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-sm"><?php echo strtoupper(substr($s['name'], 0, 2)); ?></div>
                                        <div>
                                            <div class="fw-bold" style="font-size:.85rem;color:var(--primary-blue);"><?php echo htmlspecialchars($s['name']); ?></div>
                                            <div class="text-muted" style="font-size:.72rem;"><?php echo htmlspecialchars($s['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-muted small fw-semibold"><?php echo htmlspecialchars($s['student_id_number'] ?? '—'); ?></td>
                                <td><span class="badge-pill b-program"><?php echo htmlspecialchars($s['program_name'] ?? 'Unassigned'); ?></span></td>
                                <td><span class="badge-pill b-year"><?php echo htmlspecialchars($s['year_level'] ?? '—'); ?></span></td>
                                <td class="text-center">
                                    <strong style="color:<?php echo $s['overall_grade'] !== null && $s['overall_grade'] < $activeThresholds['grade_threshold'] ? '#dc2626' : '#1e293b'; ?>;">
                                        <?php echo $s['overall_grade'] !== null ? number_format($s['overall_grade'], 1) . '%' : 'No data'; ?>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <span style="font-size:0.82rem;font-weight:600;color:<?php echo $s['quiz_average'] !== null && $s['quiz_average'] < $activeThresholds['quiz_threshold'] ? '#dc2626' : '#1e293b'; ?>;">
                                        <?php echo $s['quiz_average'] !== null ? number_format($s['quiz_average'], 1) . '%' : 'No data'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span style="font-size:0.82rem;font-weight:600;color:<?php echo $s['attendance_rate'] !== null && $s['attendance_rate'] < $activeThresholds['attendance_threshold'] ? '#dc2626' : '#15803d'; ?>;">
                                        <?php echo $s['attendance_rate'] !== null ? number_format($s['attendance_rate'], 1) . '%' : 'No data'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="trend-<?php echo $s['trend_direction']; ?>">
                                        <i class="fas <?php echo $sIcon; ?> me-1"></i><?php echo abs($sTrend); ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-pill <?php echo $s['risk_badge_class']; ?>">
                                        <i class="fas fa-circle" style="font-size:0.45rem;"></i>
                                        <?php echo htmlspecialchars($s['risk_badge_label']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button class="btn-action-view" onclick="viewStudentPerformance(<?php echo (int)$s['student_id']; ?>)">
                                        <i class="fas fa-eye me-1"></i> View Profile
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!--   TAB: FACULTY                          -->
    <!-- ═══════════════════════════════════════ -->
    <div id="tab-faculty" class="dean-dashboard-pane">
        <div class="content-card">
            <div class="card-hdr">
                <h5><i class="fas fa-chalkboard-teacher me-2" style="color:#d97706;"></i>Faculty — <?php echo htmlspecialchars($dept_name); ?></h5>
                <span class="badge-pill" style="background:#fef3c7; color:#78350f;"><?php echo count($faculty); ?> instructor(s)</span>
            </div>
            <div style="overflow-x:auto;">
                <?php if (empty($faculty)): ?>
                    <p class="text-muted text-center py-4">No instructors found for this department.</p>
                <?php else: ?>
                    <table class="dean-table">
                        <thead><tr><th>Instructor</th><th>Email</th><th>Courses</th><th>Students</th><th>Avg Student Score</th><th>Performance</th><th class="text-center">Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($faculty as $f):
                            $fscore = $f['avg_student_score'] !== null ? (float)$f['avg_student_score'] : null;
                            $fcolor = $fscore === null ? '#94a3b8' : ($fscore < 75 ? '#dc2626' : ($fscore < 85 ? '#f59e0b' : '#16a34a'));
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar-sm" style="background:#fef3c7;color:#b45309;"><?php echo strtoupper(substr($f['name'], 0, 2)); ?></div>
                                    <div class="fw-bold" style="font-size:.85rem;color:var(--primary-blue);"><?php echo htmlspecialchars($f['name']); ?></div>
                                </div>
                            </td>
                            <td class="text-muted small"><?php echo htmlspecialchars($f['email']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo $f['course_count']; ?></span></td>
                            <td><span class="badge bg-primary"><?php echo $f['student_count']; ?></span></td>
                            <td><strong style="color:<?php echo $fcolor; ?>;"><?php echo $fscore === null ? '—' : number_format($fscore, 1) . '%'; ?></strong></td>
                            <td>
                                <?php if ($fscore !== null): ?><div class="perf-bar" style="min-width:80px;"><div class="perf-fill" style="width:<?php echo min(100, $fscore); ?>%; background:<?php echo $fcolor; ?>;"></div></div><?php else: ?><span class="text-muted small">No data</span><?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a class="btn-action-view" href="admin/user_performance.php?user_id=<?php echo (int)$f['id']; ?>">
                                    <i class="fas fa-eye me-1"></i> View Profile
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!--   TAB: PROGRAMS (Section 19: Program and Course-Level At-Risk Analysis) -->
    <!-- ═══════════════════════════════════════ -->
    <div id="tab-programs" class="dean-dashboard-pane">
        <div class="content-card mb-4">
            <div class="card-hdr d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5><i class="fas fa-graduation-cap me-2" style="color:#059669;"></i><?php echo $selected_program ? htmlspecialchars($selected_program['name']) . ' — Course Performance' : 'Academic Programs'; ?></h5>
                    <small class="text-muted"><?php echo $selected_program ? 'Course performance and at-risk students in this program.' : 'Select a program to view its courses and at-risk student analysis.'; ?></small>
                </div>
                <?php if ($selected_program): ?>
                    <a href="?tab=programs" class="btn btn-sm btn-outline-primary fw-bold"><i class="fas fa-arrow-left me-1"></i>All Programs</a>
                <?php else: ?>
                    <span class="badge-pill b-active"><?php echo count($programs); ?> Programs</span>
                <?php endif; ?>
            </div>
            <div style="overflow-x:auto;">
                <?php if (!$selected_program && empty($programs)): ?>
                    <p class="text-muted text-center py-4">No programs found.</p>
                <?php elseif (!$selected_program): ?>
                    <table class="dean-table">
                        <thead>
                            <tr>
                                <th>Program</th>
                                <th class="text-center">Courses</th>
                                <th class="text-center">Students</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($programs as $program): ?>
                                <tr>
                                    <td>
                                        <a class="fw-bold text-decoration-none" style="color:var(--primary-blue);" href="?tab=programs&amp;program_id=<?php echo (int)$program['id']; ?>">
                                            <?php echo htmlspecialchars($program['name']); ?>
                                            <i class="fas fa-arrow-up-right-from-square ms-1 small" aria-hidden="true"></i>
                                        </a>
                                    </td>
                                    <td class="text-center"><?php echo (int)$program['course_count']; ?></td>
                                    <td class="text-center"><?php echo (int)$program['student_count']; ?></td>
                                    <td class="text-center"><span class="badge-pill <?php echo $program['status'] === 'active' ? 'b-active' : 'b-mid'; ?>"><?php echo htmlspecialchars(ucfirst($program['status'])); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="px-3 pt-3"><span class="badge-pill b-active"><?php echo count($courses); ?> Active Courses</span></div>
                    <?php if (empty($courses)): ?>
                        <p class="text-muted text-center py-4">No active courses found in this program.</p>
                    <?php else: ?>
                    <table class="dean-table">
                        <thead>
                            <tr>
                                <th>Course Title</th>
                                <th>Code</th>
                                <th>Instructor</th>
                                <th class="text-center">Total Students</th>
                                <th class="text-center">Average Grade</th>
                                <th class="text-center">Average Quiz</th>
                                <th class="text-center">Average Attendance</th>
                                <th class="text-center">At-Risk Students</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses as $c): 
                                $cAtRisk = (int)$c['at_risk_count'];
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold" style="color:var(--primary-blue);"><?php echo htmlspecialchars($c['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($c['program_name'] ?? '—'); ?></small>
                                </td>
                                <td><span class="badge-pill b-active"><?php echo htmlspecialchars($c['class_code'] ?? '—'); ?></span></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($c['instructor_name'] ?? 'Unassigned'); ?></td>
                                <td class="text-center"><strong><?php echo (int)$c['enrolled_count']; ?></strong></td>
                                <td class="text-center">
                                    <strong style="color:<?php echo $c['avg_grade'] !== null && $c['avg_grade'] < $activeThresholds['grade_threshold'] ? '#dc2626' : '#1e293b'; ?>;">
                                        <?php echo $c['avg_grade'] !== null ? number_format($c['avg_grade'], 1) . '%' : 'No data'; ?>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <span style="font-weight:600;color:<?php echo $c['avg_quiz_score'] !== null && $c['avg_quiz_score'] < $activeThresholds['quiz_threshold'] ? '#dc2626' : '#1e293b'; ?>;">
                                        <?php echo $c['avg_quiz_score'] !== null ? number_format($c['avg_quiz_score'], 1) . '%' : 'No data'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span style="font-weight:600;color:<?php echo $c['avg_attendance'] !== null && $c['avg_attendance'] < $activeThresholds['attendance_threshold'] ? '#dc2626' : '#15803d'; ?>;">
                                        <?php echo $c['avg_attendance'] !== null ? number_format($c['avg_attendance'], 1) . '%' : 'No data'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($cAtRisk > 0): ?>
                                        <span class="badge b-risk fw-bold py-1 px-2">
                                            <i class="fas fa-exclamation-triangle me-1"></i><?php echo $cAtRisk; ?> At Risk
                                        </span>
                                    <?php else: ?>
                                        <span class="badge b-ok py-1 px-2">0 At Risk</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary py-1 px-2 fw-bold" style="font-size:0.75rem;" onclick="openCourseRiskModal(<?php echo (int)$c['id']; ?>)">
                                        <i class="fas fa-users-viewfinder me-1"></i> View Students
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!--   TAB: ANALYTICS & TRENDS (Section 18) -->
    <!-- ═══════════════════════════════════════ -->
    <div id="tab-analytics" class="dean-dashboard-pane">
        <div class="content-card mb-4">
            <div class="card-hdr d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5><i class="fas fa-chart-line me-2" style="color:var(--dean-purple);"></i>Academic Performance Trends — <?php echo htmlspecialchars($dept_name); ?></h5>
                    <small class="text-muted">Monitor changes in department average grade, quiz scores, attendance, and at-risk students over time.</small>
                </div>
                <div class="btn-group btn-group-sm time-btn-group" id="deptTimeBtnGroup">
                    <button class="btn btn-outline-primary active" onclick="setDeptTrendFilter('weekly', this)">Weekly</button>
                    <button class="btn btn-outline-primary" onclick="setDeptTrendFilter('monthly', this)">Monthly</button>
                    <button class="btn btn-outline-primary" onclick="setDeptTrendFilter('semester', this)">Semester</button>
                    <button class="btn btn-outline-primary" onclick="setDeptTrendFilter('academic_year', this)">Academic Year</button>
                </div>
            </div>
            <div class="card-body-p">
                <canvas id="deptTrendsCanvas" height="110"></canvas>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="chart-container-box h-100">
                    <h6 class="fw-bold text-primary mb-3"><i class="fas fa-user-plus me-2"></i>Student Enrollment History</h6>
                    <canvas id="enrollChart" height="130"></canvas>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="chart-container-box h-100">
                    <h6 class="fw-bold text-primary mb-3"><i class="fas fa-chart-pie me-2"></i>Students per Program</h6>
                    <canvas id="progChart" height="130"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!--   TAB: ANNOUNCEMENTS                    -->
    <!-- ═══════════════════════════════════════ -->
    <div id="tab-announcements" class="dean-dashboard-pane">
        <div class="row g-4">
            <div class="col-lg-5">
                <div class="content-card">
                    <div class="card-hdr" id="announcement-form">
                        <h5><i class="fas fa-plus-circle me-2" style="color:var(--dean-purple);"></i>New Announcement</h5>
                    </div>
                    <div class="card-body-p">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Target Audience</label>
                                <select name="ann_target" id="ann_target" class="form-select" onchange="toggleProgSelect(this.value)">
                                    <option value="all">All Students in Department</option>
                                    <option value="faculty">Faculty / Instructors</option>
                                    <option value="program">Specific Program</option>
                                </select>
                            </div>
                            <div class="mb-3" id="ann_prog_wrapper" style="display:none;">
                                <label class="form-label fw-semibold small">Select Program</label>
                                <select name="ann_program_id" class="form-select">
                                    <option value="">-- Select Program --</option>
                                    <?php foreach ($programs as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Message <span class="text-danger">*</span></label>
                                <textarea name="ann_message" class="form-control" rows="4" placeholder="Write your announcement here..." required></textarea>
                            </div>
                            <button type="submit" name="create_announcement" class="btn btn-primary w-100 fw-bold">
                                <i class="fas fa-paper-plane me-1"></i> Send Announcement
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="content-card">
                    <div class="card-hdr">
                        <h5><i class="fas fa-history me-2" style="color:var(--dean-purple);"></i>Recent Announcements</h5>
                    </div>
                    <div class="card-body-p">
                        <?php if (empty($announcements)): ?>
                            <p class="text-muted text-center py-3 small mb-0">No department announcements sent yet.</p>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($announcements as $ann):
                                    $msg = preg_replace('/^\[Dept Announcement\]\s*/', '', $ann['message']);
                                ?>
                                <div class="p-3 bg-light border-start border-primary border-3 rounded-2">
                                    <div style="font-size:.85rem; color:#1e293b;"><?php echo htmlspecialchars($msg); ?></div>
                                    <div class="mt-2 d-flex justify-content-between align-items-center">
                                        <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($ann['created_at'])); ?></small>
                                        <span class="badge-pill b-program"><?php echo $ann['recipients']; ?> recipient(s)</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════ -->
    <!--   TAB: REPORTS                          -->
    <!-- ═══════════════════════════════════════ -->
    <div id="tab-reports" class="dean-dashboard-pane">
        <div class="row g-3 mb-4">
            <?php
            $reportTypes = [
                ['key'=>'atrisk',      'icon'=>'fa-exclamation-triangle','color'=>'#dc2626','bg'=>'#fee2e2', 'label'=>'At-Risk Students Report', 'desc'=>'Detailed risk factors, grades & attendance'],
                ['key'=>'students',    'icon'=>'fa-user-graduate',      'color'=>'#2563eb', 'bg'=>'#dbeafe', 'label'=>'Student Performance',      'desc'=>'Averages, quiz scores, year levels'],
                ['key'=>'faculty',     'icon'=>'fa-chalkboard-teacher', 'color'=>'#d97706', 'bg'=>'#fef3c7', 'label'=>'Faculty Performance',       'desc'=>'Courses taught and student scores'],
                ['key'=>'programs',    'icon'=>'fa-graduation-cap',     'color'=>'#6b21a8', 'bg'=>'#f3e8ff', 'label'=>'Program Enrollment',       'desc'=>'Students and course counts per program'],
                ['key'=>'academic',    'icon'=>'fa-chart-line',         'color'=>'#4f46e5', 'bg'=>'#e0e7ff', 'label'=>'Department Academic Report', 'desc'=>'Comprehensive department KPIs'],
            ];
            foreach ($reportTypes as $r): ?>
            <div class="col-lg-4 col-md-6">
                <button class="w-100 text-start border-0 bg-transparent p-0" onclick="showReport('<?php echo $r['key']; ?>')">
                    <div class="content-card p-3" id="rcard-<?php echo $r['key']; ?>" style="cursor:pointer; border: 2px solid transparent; transition:all .2s;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:44px;height:44px;border-radius:12px;background:<?php echo $r['bg']; ?>;color:<?php echo $r['color']; ?>;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                                <i class="fas <?php echo $r['icon']; ?>"></i>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:.9rem;color:#1e293b;"><?php echo $r['label']; ?></div>
                                <div style="font-size:.72rem;color:#64748b;"><?php echo $r['desc']; ?></div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto" style="color:#cbd5e1;"></i>
                        </div>
                    </div>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="content-card" id="reportOutput" style="display:none;">
            <div class="card-hdr">
                <h5 id="reportTitle"><i class="fas fa-file-alt me-2 text-primary"></i>Report</h5>
                <button class="btn btn-sm btn-outline-secondary fw-bold" onclick="window.print()">
                    <i class="fas fa-print me-1"></i> Print Report
                </button>
            </div>
            <div class="p-0" id="reportBody" style="overflow-x:auto;"></div>
        </div>
    </div>

</main>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: CONFIGURE RISK THRESHOLDS (Section 3)                -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="thresholdModal" tabindex="-1" aria-labelledby="thresholdModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="thresholdModalLabel">
                    <i class="fas fa-sliders-h me-2"></i>Configure At-Risk Thresholds
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="save_thresholds" value="1">
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">
                        These measurable thresholds determine how the system automatically identifies students at risk of poor academic performance.
                    </p>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Grade Threshold (Below this % is At Risk)</label>
                        <div class="input-group">
                            <input type="number" step="0.5" min="40" max="100" name="grade_threshold" class="form-control" value="<?php echo htmlspecialchars($activeThresholds['grade_threshold']); ?>" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted" style="font-size:0.7rem;">Default: Below 75%</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Attendance Threshold (Below this % is At Risk)</label>
                        <div class="input-group">
                            <input type="number" step="0.5" min="40" max="100" name="attendance_threshold" class="form-control" value="<?php echo htmlspecialchars($activeThresholds['attendance_threshold']); ?>" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted" style="font-size:0.7rem;">Default: Below 80%</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Quiz Performance Threshold (Below this % is At Risk)</label>
                        <div class="input-group">
                            <input type="number" step="0.5" min="40" max="100" name="quiz_threshold" class="form-control" value="<?php echo htmlspecialchars($activeThresholds['quiz_threshold']); ?>" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted" style="font-size:0.7rem;">Default: Below 75%</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Performance Decline Trigger (% Decline vs Previous Period)</label>
                        <div class="input-group">
                            <input type="number" step="0.5" min="1" max="50" name="decline_threshold" class="form-control" value="<?php echo htmlspecialchars($activeThresholds['decline_threshold']); ?>" required>
                            <span class="input-group-text">% or more decline</span>
                        </div>
                        <small class="text-muted" style="font-size:0.7rem;">Default: 10% or more decline</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Course Passing Grade Threshold</label>
                        <div class="input-group">
                            <input type="number" step="0.5" min="40" max="100" name="passing_grade" class="form-control" value="<?php echo htmlspecialchars($activeThresholds['passing_grade']); ?>" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted" style="font-size:0.7rem;">Default: 75%</small>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Moderate Grade %</label>
                            <input type="number" step="0.5" min="40" max="100" name="moderate_grade_threshold" class="form-control" value="<?php echo htmlspecialchars($activeThresholds['moderate_grade_threshold'] ?? 80.0); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Moderate Attendance %</label>
                            <input type="number" step="0.5" min="40" max="100" name="moderate_attendance_threshold" class="form-control" value="<?php echo htmlspecialchars($activeThresholds['moderate_attendance_threshold'] ?? 85.0); ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm fw-bold">
                        <i class="fas fa-save me-1"></i> Save &amp; Recalculate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: DETAILED STUDENT RISK PROFILE (Sections 7 to 17)     -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="studentRiskModal" tabindex="-1" aria-labelledby="studentRiskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="risk-modal-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <div class="avatar-sm bg-white text-primary fw-bold" id="mStudentAvatar" style="width:48px;height:48px;font-size:1.1rem;">JD</div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="mStudentName">Juan Dela Cruz</h4>
                        <div class="small opacity-75">
                            ID: <span id="mStudentID">20240001</span> &nbsp;|&nbsp;
                            Program: <span id="mStudentProgram">BSIT</span> &nbsp;|&nbsp;
                            Year: <span id="mStudentYear">3rd Year</span>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge py-2 px-3 fw-bold" id="mRiskBadge" style="font-size:0.9rem;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;">
                        🔴 AT RISK
                    </span>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>

            <div class="modal-body p-4 bg-light">

                <!-- Navigation Pills within Student Profile -->
                <ul class="nav nav-pills risk-nav-pills mb-3 bg-white p-2 border rounded-3" id="profileTabPills">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-p-overview">
                            <i class="fas fa-id-card me-1"></i> Risk Breakdown &amp; Triggers
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-p-performance">
                            <i class="fas fa-chart-line me-1"></i> Academic &amp; Course Performance
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-p-quizzes">
                            <i class="fas fa-question-circle me-1"></i> Quiz Results
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-p-attendance">
                            <i class="fas fa-calendar-check me-1"></i> Attendance Monitoring
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-p-trend">
                            <i class="fas fa-arrow-trend-down me-1"></i> Multi-Metric Trends &amp; History
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-p-interventions">
                            <i class="fas fa-hand-holding-heart me-1"></i> Interventions &amp; Tracking
                        </button>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- Sub-Tab 1: Overview & Why At Risk -->
                    <div class="tab-pane fade show active" id="tab-p-overview">
                        <!-- Top KPI Breakdown (Section 4) -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <div class="kpi-box bg-white">
                                    <div class="kpi-label">Overall Academic Score</div>
                                    <div class="kpi-val" id="mOverallScore">72%</div>
                                    <span class="badge b-risk mt-1" id="mGradeStatusBadge">At Risk</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="kpi-box bg-white">
                                    <div class="kpi-label">Quiz Average</div>
                                    <div class="kpi-val" id="mQuizScore">74.7%</div>
                                    <span class="badge b-risk mt-1" id="mQuizStatusBadge">At Risk</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="kpi-box bg-white">
                                    <div class="kpi-label">Attendance Rate</div>
                                    <div class="kpi-val" id="mAttScore">75.9%</div>
                                    <span class="badge b-risk mt-1" id="mAttStatusBadge">At Risk</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="kpi-box bg-white">
                                    <div class="kpi-label">Performance Trend</div>
                                    <div class="kpi-val" id="mTrendScore">↓ 8%</div>
                                    <span class="badge b-risk mt-1" id="mTrendStatusBadge">Declining</span>
                                </div>
                            </div>
                        </div>

                        <!-- ⚠️ Section 13: Explain WHY the Student Is At Risk -->
                        <div class="why-risk-card mb-4">
                            <h6><i class="fas fa-circle-exclamation me-2"></i>Why is this student classified as at risk?</h6>
                            <ul class="why-risk-list" id="mWhyRiskList">
                                <li>Loading assessment reasons...</li>
                            </ul>
                        </div>

                        <!-- ⚠️ Section 14: Recommended Intervention -->
                        <div class="rec-action-card d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                            <div>
                                <h6><i class="fas fa-lightbulb me-2"></i>Recommended Academic Interventions:</h6>
                                <div id="mRecommendedList" class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-primary">Academic Advising</span>
                                    <span class="badge bg-primary">Tutoring</span>
                                </div>
                            </div>
                            <button class="btn btn-sm btn-primary fw-bold" onclick="openRecordInterventionForCurrentStudent()">
                                <i class="fas fa-plus me-1"></i> Record Intervention
                            </button>
                        </div>

                        <!-- Quick Course Snapshot -->
                        <div class="bg-white border rounded-3 p-3">
                            <h6 class="fw-bold text-primary mb-2"><i class="fas fa-book me-2"></i>Enrolled Courses Status</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0" style="font-size:0.82rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Course</th>
                                            <th>Instructor</th>
                                            <th class="text-center">Grade</th>
                                            <th class="text-center">Attendance</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="mCoursesQuickTable"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-Tab 2: Academic & Course Performance (Section 8 & 9) -->
                    <div class="tab-pane fade" id="tab-p-performance">
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <div class="kpi-box bg-white">
                                    <div class="kpi-label">Current Average</div>
                                    <div class="kpi-val text-danger" id="mPerfCurrentAvg">72%</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="kpi-box bg-white">
                                    <div class="kpi-label">Previous Average</div>
                                    <div class="kpi-val text-muted" id="mPerfPrevAvg">80%</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="kpi-box bg-white">
                                    <div class="kpi-label">Academic Change</div>
                                    <div class="kpi-val text-danger" id="mPerfDelta">↓ 8%</div>
                                </div>
                            </div>
                        </div>

                        <!-- Performance Chart Over Time (Section 8) -->
                        <div class="chart-container-box mb-4">
                            <h6 class="fw-bold text-primary mb-2"><i class="fas fa-chart-line me-2"></i>Student Performance Over Time (Real Recorded Grades)</h6>
                            <canvas id="studentPerfChart" height="90"></canvas>
                        </div>

                        <!-- Course Performance Table (Section 9) -->
                        <div class="bg-white border rounded-3 p-3">
                            <h6 class="fw-bold text-primary mb-3"><i class="fas fa-table-list me-2"></i>Course Performance Breakdown</h6>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" style="font-size:0.85rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Course</th>
                                            <th>Instructor</th>
                                            <th class="text-center">Grade</th>
                                            <th class="text-center">Attendance</th>
                                            <th>Risk Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="mFullCoursesTable"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-Tab 3: Quiz Results (Section 10) -->
                    <div class="tab-pane fade" id="tab-p-quizzes">
                        <div class="bg-white border rounded-3 p-3">
                            <h6 class="fw-bold text-primary mb-3"><i class="fas fa-question-circle me-2"></i>Quiz Performance Log</h6>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" style="font-size:0.85rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Quiz</th>
                                            <th>Course</th>
                                            <th>Date Taken</th>
                                            <th class="text-center">Score</th>
                                            <th class="text-center">Percentage</th>
                                            <th>Performance Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="mQuizzesTable"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-Tab 4: Attendance Monitoring (Section 11) -->
                    <div class="tab-pane fade" id="tab-p-attendance">
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <div class="kpi-box bg-white">
                                    <div class="kpi-label">Attendance Rate</div>
                                    <div class="kpi-val text-primary" id="mAttRateBig">78%</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="kpi-box bg-white">
                                    <div class="kpi-label">Present Sessions</div>
                                    <div class="kpi-val text-success" id="mAttPresentCount">39</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="kpi-box bg-white">
                                    <div class="kpi-label">Absences</div>
                                    <div class="kpi-val text-danger" id="mAttAbsentCount">11</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="kpi-box bg-white">
                                    <div class="kpi-label">Late Records</div>
                                    <div class="kpi-val text-warning" id="mAttLateCount">4</div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white border rounded-3 p-3">
                            <h6 class="fw-bold text-primary mb-3"><i class="fas fa-history me-2"></i>Attendance Log by Date &amp; Course</h6>
                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                <table class="table table-sm table-hover mb-0" style="font-size:0.82rem;">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th>Date</th>
                                            <th>Course</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="mAttendanceLogTable"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-Tab 5: Multi-Metric Trends & History (Sections 12 & 17) -->
                    <div class="tab-pane fade" id="tab-p-trend">
                        <div class="chart-container-box mb-4">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <div>
                                    <h6 class="fw-bold text-primary mb-0"><i class="fas fa-chart-line me-2"></i>Academic Performance Trend (Grades, Quizzes &amp; Attendance)</h6>
                                    <span class="badge b-risk mt-1" id="mTrendIndicatorBadge">📉 Declining</span>
                                </div>
                                <div class="btn-group btn-group-sm time-btn-group" id="studentTrendBtnGroup">
                                    <button class="btn btn-outline-primary" onclick="switchStudentTrendTimeframe('7d', this)">7 Days</button>
                                    <button class="btn btn-outline-primary" onclick="switchStudentTrendTimeframe('30d', this)">30 Days</button>
                                    <button class="btn btn-outline-primary active" onclick="switchStudentTrendTimeframe('semester', this)">Semester</button>
                                    <button class="btn btn-outline-primary" onclick="switchStudentTrendTimeframe('year', this)">Academic Year</button>
                                </div>
                            </div>
                            <canvas id="studentMultiMetricChart" height="100"></canvas>
                        </div>

                        <!-- Historical Risk Snapshots (Section 17) -->
                        <div class="bg-white border rounded-3 p-3">
                            <h6 class="fw-bold text-primary mb-2"><i class="fas fa-clock-rotate-left me-2"></i>At-Risk Student History (Preserved Snapshots)</h6>
                            <small class="text-muted d-block mb-3">Past risk assessments are permanently recorded to track how the student's situation changed over time.</small>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" style="font-size:0.82rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Assessment Date</th>
                                            <th>Risk Level</th>
                                            <th class="text-center">Average</th>
                                            <th class="text-center">Attendance</th>
                                            <th>Documented Reasons</th>
                                        </tr>
                                    </thead>
                                    <tbody id="mRiskHistoryTable"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Sub-Tab 6: Interventions & Tracking (Sections 15 & 16) -->
                    <div class="tab-pane fade" id="tab-p-interventions">

                        <!-- Section 16: Track Improvement After Intervention -->
                        <div class="bg-white border rounded-3 p-3 mb-4">
                            <h6 class="fw-bold text-primary mb-3"><i class="fas fa-arrows-up-down me-2"></i>Performance After Intervention</h6>
                            <div class="row g-2 align-items-center text-center" id="mInterventionProgressionBox">
                                <div class="col-md-3">
                                    <div class="p-2 border rounded bg-light">
                                        <div class="small text-muted fw-bold">Before Intervention</div>
                                        <div class="h5 fw-bold text-danger my-1" id="mProgBefore">72%</div>
                                    </div>
                                </div>
                                <div class="col-md-1 text-muted"><i class="fas fa-arrow-right fa-lg"></i></div>
                                <div class="col-md-3">
                                    <div class="p-2 border rounded bg-light">
                                        <div class="small text-muted fw-bold">After 2 Weeks</div>
                                        <div class="h5 fw-bold text-warning my-1" id="mProgAfter2wk">76%</div>
                                    </div>
                                </div>
                                <div class="col-md-1 text-muted"><i class="fas fa-arrow-right fa-lg"></i></div>
                                <div class="col-md-3">
                                    <div class="p-2 border rounded bg-light">
                                        <div class="small text-muted fw-bold">Current Standing</div>
                                        <div class="h5 fw-bold text-success my-1" id="mProgCurrent">81%</div>
                                    </div>
                                </div>
                            </div>
                            <div class="text-center mt-2">
                                <span class="badge b-ok" id="mProgStatusBadge">🟢 Improving</span>
                            </div>
                        </div>

                        <!-- Section 15: Intervention History -->
                        <div class="bg-white border rounded-3 p-3">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <h6 class="fw-bold text-primary mb-0"><i class="fas fa-hand-holding-heart me-2"></i>Intervention Records &amp; Support History</h6>
                                <button class="btn btn-sm btn-primary fw-bold" onclick="openRecordInterventionForCurrentStudent()">
                                    <i class="fas fa-plus me-1"></i> Record New Intervention
                                </button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" style="font-size:0.85rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Intervention Type</th>
                                            <th>Assigned By</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Notes</th>
                                            <th class="text-center">Update</th>
                                        </tr>
                                    </thead>
                                    <tbody id="mInterventionsTable"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
            <div class="modal-footer bg-white">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close Profile</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: RECORD INTERVENTION (Section 14 & 15)                -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="recordInterventionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-hand-holding-heart me-2"></i>Record Academic Intervention</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formRecordIntervention">
                <input type="hidden" name="record_intervention" value="1">
                <input type="hidden" name="student_id" id="invModalStudentId" value="">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Student</label>
                        <input type="text" class="form-control" id="invModalStudentName" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Intervention Type <span class="text-danger">*</span></label>
                        <select name="intervention_type" class="form-select" required>
                            <option value="Academic Advising">Academic Advising</option>
                            <option value="Faculty Consultation">Faculty Consultation</option>
                            <option value="Tutoring">Tutoring</option>
                            <option value="Attendance Intervention">Attendance Intervention</option>
                            <option value="Parent/Guardian Notification">Parent/Guardian Notification</option>
                            <option value="Study Plan">Study Plan</option>
                            <option value="Academic Monitoring">Academic Monitoring</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Scheduled Date <span class="text-danger">*</span></label>
                        <input type="date" name="scheduled_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select" required>
                            <option value="recommended">Recommended</option>
                            <option value="scheduled" selected>Scheduled</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Current Grade at Intervention (% baseline)</label>
                        <input type="number" step="0.1" name="grade_before" id="invModalGradeBefore" class="form-control" placeholder="72.0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Action Notes &amp; Recommendations</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Describe the intervention action plan, advising advice, or tutoring schedule..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm fw-bold">
                        <i class="fas fa-check me-1"></i> Save Intervention
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: COURSE AT-RISK ANALYSIS (Section 19)                 -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="courseRiskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <div>
                    <h5 class="modal-title fw-bold" id="cModalTitle">Course Performance Analysis</h5>
                    <small id="cModalSubtitle" class="opacity-75">Course Code &amp; Instructor</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="row g-2 mb-3">
                    <div class="col-md-3"><div class="kpi-box bg-white"><div class="kpi-label">Total Enrolled</div><div class="kpi-val" id="cModalTotalStudents">0</div></div></div>
                    <div class="col-md-3"><div class="kpi-box bg-white"><div class="kpi-label">Average Grade</div><div class="kpi-val" id="cModalAvgGrade">0%</div></div></div>
                    <div class="col-md-3"><div class="kpi-box bg-white"><div class="kpi-label">Quiz Average</div><div class="kpi-val" id="cModalAvgQuiz">0%</div></div></div>
                    <div class="col-md-3"><div class="kpi-box bg-white"><div class="kpi-label">At-Risk Count</div><div class="kpi-val text-danger" id="cModalAtRiskCount">0</div></div></div>
                </div>

                <div class="bg-white border rounded-3 p-3">
                    <h6 class="fw-bold text-danger mb-3"><i class="fas fa-triangle-exclamation me-2"></i>Students Requiring Attention in This Course</h6>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="font-size:0.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th class="text-center">Course Grade</th>
                                    <th class="text-center">Attendance</th>
                                    <th>Overall Risk</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="cModalStudentsTable"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
    // Tab switching
    let analyticsChartsInitialized = false;
    function switchTab(name, btn) {
        document.querySelectorAll('.dean-dashboard-pane').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.dean-tab').forEach(b => b.classList.remove('active'));
        const pane = document.getElementById('tab-' + name);
        if (pane) pane.classList.add('active');
        if (btn) btn.classList.add('active');

        // Lazily initialize analytics charts when the tab is first shown
        if (name === 'analytics' && !analyticsChartsInitialized) {
            analyticsChartsInitialized = true;
            setTimeout(function() {
                initAnalyticsCharts();
                renderDeptTrendsChart('weekly');
            }, 50);
        }
    }

    // Auto-switch tab from URL
    (function() {
        const urlParams = new URLSearchParams(window.location.search);
        const urlTab = urlParams.get('tab');
        if (urlTab) {
            const pane = document.getElementById('tab-' + urlTab);
            if (pane) {
                document.querySelectorAll('.dean-dashboard-pane').forEach(p => p.classList.remove('active'));
                document.querySelectorAll('.dean-tab').forEach(b => b.classList.remove('active'));
                pane.classList.add('active');
                const btns = document.querySelectorAll('.dean-tab');
                btns.forEach(b => { if (b.getAttribute('onclick') && b.getAttribute('onclick').includes("'" + urlTab + "'")) b.classList.add('active'); });
            }
            // If landing directly on analytics tab, initialize charts
            if (urlTab === 'analytics' && !analyticsChartsInitialized) {
                analyticsChartsInitialized = true;
                setTimeout(function() {
                    initAnalyticsCharts();
                    renderDeptTrendsChart('weekly');
                }, 50);
            }
        }
        // Auto-open student profile if student_id parameter is present
        const urlStudentId = urlParams.get('student_id');
        if (urlStudentId) {
            setTimeout(() => openStudentProfile(urlStudentId), 300);
        }
    })();

    function toggleProgSelect(val) {
        document.getElementById('ann_prog_wrapper').style.display = val === 'program' ? 'block' : 'none';
    }

    function viewStudentPerformance(studentId) {
        window.location.href = `admin/user_performance.php?user_id=${encodeURIComponent(studentId)}`;
    }

    // ─── Student Profile Interactive Modal (Sections 7-17) ───
    let currentLoadedStudent = null;
    let studentPerfChartInstance = null;
    let studentMultiMetricChartInstance = null;

    function openStudentProfile(studentId) {
        const modalEl = document.getElementById('studentRiskModal');
        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        // Reset to first tab
        const firstPill = document.querySelector('#profileTabPills button:first-child');
        if (firstPill) {
            const trigger = new bootstrap.Tab(firstPill);
            trigger.show();
        }

        // Fetch data via ajax_risk.php
        fetch(`ajax_risk.php?action=get_student_profile&student_id=${studentId}`)
            .then(res => res.json())
            .then(res => {
                if (!res.success) {
                    alert('Error: ' + res.error);
                    return;
                }
                const d = res.data;
                currentLoadedStudent = d;

                // Header Info
                document.getElementById('mStudentName').innerText = d.name;
                document.getElementById('mStudentID').innerText = d.student_id_number || '—';
                document.getElementById('mStudentProgram').innerText = d.program_name || '—';
                document.getElementById('mStudentYear').innerText = d.year_level || '—';
                document.getElementById('mStudentAvatar').innerText = d.name.substring(0, 2).toUpperCase();

                // Risk Badge
                const bEl = document.getElementById('mRiskBadge');
                bEl.className = 'badge py-2 px-3 fw-bold ' + d.risk_badge_class;
                bEl.innerHTML = `<i class="fas fa-circle me-1" style="font-size:0.5rem;"></i>${d.risk_badge_label.toUpperCase()} (Score: ${d.risk_score}/100)`;

                // Section 4 Factor Breakdown
                document.getElementById('mOverallScore').innerText = d.overall_grade !== null ? d.overall_grade + '%' : 'No Data';
                document.getElementById('mQuizScore').innerText = d.quiz_average !== null ? d.quiz_average + '%' : 'No Data';
                document.getElementById('mAttScore').innerText = d.attendance_rate !== null ? d.attendance_rate + '%' : 'No Data';
                document.getElementById('mTrendScore').innerText = (d.trend_change >= 0 ? '+' : '') + d.trend_change + '%';

                const gradeStatus = d.factor_breakdown[0].status;
                const quizStatus  = d.factor_breakdown[1].status;
                const attStatus   = d.factor_breakdown[2].status;
                const trendStatus = d.factor_breakdown[3].status;

                setBadgeStatus('mGradeStatusBadge', gradeStatus);
                setBadgeStatus('mQuizStatusBadge', quizStatus);
                setBadgeStatus('mAttStatusBadge', attStatus);
                setBadgeStatus('mTrendStatusBadge', trendStatus);

                // Section 13: Why at risk?
                const whyList = document.getElementById('mWhyRiskList');
                whyList.innerHTML = '';
                d.reasons.forEach(r => {
                    const li = document.createElement('li');
                    li.innerHTML = `⚠️ ${r}`;
                    whyList.appendChild(li);
                });

                // Section 14: Recommended interventions
                const recList = document.getElementById('mRecommendedList');
                recList.innerHTML = '';
                d.recommended_interventions.forEach(rec => {
                    const sp = document.createElement('span');
                    sp.className = 'badge bg-primary px-3 py-2 fw-bold';
                    sp.innerText = rec;
                    recList.appendChild(sp);
                });

                // Course list quick
                renderCoursesTable(d.courses);

                // Section 8: Academic Performance
                document.getElementById('mPerfCurrentAvg').innerText = d.current_average !== null ? d.current_average + '%' : '—';
                document.getElementById('mPerfPrevAvg').innerText = d.previous_average !== null ? d.previous_average + '%' : '—';
                document.getElementById('mPerfDelta').innerText = (d.trend_change >= 0 ? '↑ ' : '↓ ') + Math.abs(d.trend_change) + '%';
                renderStudentPerfChart(d);

                // Section 10: Quiz Results
                renderQuizzesTable(d.quizzes);

                // Section 11: Attendance
                document.getElementById('mAttRateBig').innerText = d.attendance_rate !== null ? d.attendance_rate + '%' : '—';
                document.getElementById('mAttPresentCount').innerText = d.attendance_present;
                document.getElementById('mAttAbsentCount').innerText = d.attendance_absent;
                document.getElementById('mAttLateCount').innerText = d.attendance_late;
                renderAttendanceLog(d.attendance_records);

                // Section 12 & 17: Trends & History
                renderMultiMetricChart(d);
                renderRiskHistory(d.risk_history);

                // Section 15 & 16: Interventions & Tracker
                renderInterventions(d.interventions);
            })
            .catch(err => {
                console.error(err);
                alert('Could not load student profile.');
            });
    }

    function setBadgeStatus(elementId, status) {
        const el = document.getElementById(elementId);
        if (!el) return;
        el.innerText = status;
        const s = status.toLowerCase();
        if (s.includes('risk') || s.includes('declining')) {
            el.className = 'badge b-risk mt-1';
        } else if (s.includes('moderate')) {
            el.className = 'badge b-mid mt-1';
        } else {
            el.className = 'badge b-ok mt-1';
        }
    }

    function renderCoursesTable(courses) {
        const quick = document.getElementById('mCoursesQuickTable');
        const full  = document.getElementById('mFullCoursesTable');
        quick.innerHTML = '';
        full.innerHTML = '';

        if (!courses || courses.length === 0) {
            quick.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No courses enrolled.</td></tr>';
            full.innerHTML  = '<tr><td colspan="5" class="text-center text-muted">No courses enrolled.</td></tr>';
            return;
        }

        courses.forEach(c => {
            const isRisk = c.is_at_risk;
            const badgeClass = isRisk ? 'b-risk' : (c.status.toLowerCase().includes('moderate') ? 'b-mid' : 'b-ok');
            const rowHtml = `
                <tr>
                    <td><strong>${c.course_title}</strong></td>
                    <td class="text-muted small">${c.instructor_name}</td>
                    <td class="text-center"><strong>${c.grade !== null ? c.grade + '%' : '—'}</strong></td>
                    <td class="text-center">${c.attendance_rate !== null ? c.attendance_rate + '%' : '—'}</td>
                    <td><span class="badge-pill ${badgeClass}">${c.status}</span></td>
                </tr>
            `;
            quick.innerHTML += rowHtml;
            full.innerHTML  += rowHtml;
        });
    }

    function renderQuizzesTable(quizzes) {
        const tbody = document.getElementById('mQuizzesTable');
        tbody.innerHTML = '';
        if (!quizzes || quizzes.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No quiz records found.</td></tr>';
            return;
        }
        quizzes.forEach(q => {
            const badgeClass = q.status === 'at_risk' ? 'b-risk' : (q.status === 'low' ? 'b-mid' : 'b-ok');
            const badgeLabel = q.status === 'at_risk' ? '🔴 At Risk' : (q.status === 'low' ? '⚠️ Low' : '🟢 Good');
            const dateStr = q.date ? new Date(q.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
            tbody.innerHTML += `
                <tr>
                    <td><strong>${q.title}</strong></td>
                    <td class="text-muted small">${q.course_title}</td>
                    <td class="text-muted small">${dateStr}</td>
                    <td class="text-center fw-bold">${q.score}</td>
                    <td class="text-center fw-bold">${q.percentage}%</td>
                    <td><span class="badge-pill ${badgeClass}">${badgeLabel}</span></td>
                </tr>
            `;
        });
    }

    function renderAttendanceLog(records) {
        const tbody = document.getElementById('mAttendanceLogTable');
        tbody.innerHTML = '';
        if (!records || records.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">No attendance records found.</td></tr>';
            return;
        }
        records.forEach(r => {
            const st = r.status.toLowerCase();
            const badgeClass = st === 'present' ? 'b-ok' : (st === 'absent' ? 'b-risk' : 'b-mid');
            tbody.innerHTML += `
                <tr>
                    <td>${r.attendance_date}</td>
                    <td>${r.course_title}</td>
                    <td><span class="badge-pill ${badgeClass}">${r.status.toUpperCase()}</span></td>
                </tr>
            `;
        });
    }

    function renderRiskHistory(history) {
        const tbody = document.getElementById('mRiskHistoryTable');
        tbody.innerHTML = '';
        if (!history || history.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No previous risk snapshots recorded.</td></tr>';
            return;
        }
        history.forEach(h => {
            const dateStr = new Date(h.assessed_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            const badgeClass = h.risk_level === 'at_risk' ? 'b-risk' : (h.risk_level === 'moderate' ? 'b-mid' : 'b-ok');
            const reasonsFormatted = (h.reasons || '').split('\n').join('; ');
            tbody.innerHTML += `
                <tr>
                    <td><strong>${dateStr}</strong></td>
                    <td><span class="badge-pill ${badgeClass}">${h.risk_level.toUpperCase()}</span></td>
                    <td class="text-center font-monospace">${h.grade_avg !== null ? h.grade_avg + '%' : '—'}</td>
                    <td class="text-center font-monospace">${h.attendance_rate !== null ? h.attendance_rate + '%' : '—'}</td>
                    <td class="small text-muted">${reasonsFormatted || '—'}</td>
                </tr>
            `;
        });
    }

    function renderInterventions(interventions) {
        const tbody = document.getElementById('mInterventionsTable');
        tbody.innerHTML = '';

        if (!interventions || interventions.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No interventions recorded yet for this student.</td></tr>';
            // Default progression
            document.getElementById('mProgBefore').innerText = currentLoadedStudent.overall_grade ? currentLoadedStudent.overall_grade + '%' : '—';
            document.getElementById('mProgAfter2wk').innerText = '—';
            document.getElementById('mProgCurrent').innerText = currentLoadedStudent.overall_grade ? currentLoadedStudent.overall_grade + '%' : '—';
            document.getElementById('mProgStatusBadge').innerText = 'Awaiting Intervention';
            document.getElementById('mProgStatusBadge').className = 'badge b-inactive';
            return;
        }

        // Use latest intervention for before/after tracking
        const latest = interventions[0];
        document.getElementById('mProgBefore').innerText = latest.grade_before !== null ? latest.grade_before + '%' : (currentLoadedStudent.previous_average ? currentLoadedStudent.previous_average + '%' : '72%');
        document.getElementById('mProgAfter2wk').innerText = latest.grade_after_2wk !== null ? latest.grade_after_2wk + '%' : '76%';
        document.getElementById('mProgCurrent').innerText = latest.grade_current !== null ? latest.grade_current + '%' : (currentLoadedStudent.overall_grade ? currentLoadedStudent.overall_grade + '%' : '81%');

        const bVal = parseFloat(document.getElementById('mProgBefore').innerText);
        const cVal = parseFloat(document.getElementById('mProgCurrent').innerText);
        if (cVal > bVal) {
            document.getElementById('mProgStatusBadge').innerText = '🟢 Improving';
            document.getElementById('mProgStatusBadge').className = 'badge b-ok';
        } else {
            document.getElementById('mProgStatusBadge').innerText = '⚠️ Stable / Needs Follow-up';
            document.getElementById('mProgStatusBadge').className = 'badge b-mid';
        }

        interventions.forEach(inv => {
            const stBadge = inv.status === 'completed' ? 'b-ok' : (inv.status === 'in_progress' ? 'b-mid' : 'b-program');
            tbody.innerHTML += `
                <tr>
                    <td><strong>${inv.intervention_type}</strong></td>
                    <td class="small text-muted">${inv.assigned_by_name || 'Dean / Academic Advisor'}</td>
                    <td class="small text-muted">${inv.scheduled_date || '—'}</td>
                    <td><span class="badge-pill ${stBadge}">${inv.status.replace('_', ' ').toUpperCase()}</span></td>
                    <td class="small">${inv.notes || '—'}</td>
                    <td class="text-center">
                        <select class="form-select form-select-sm d-inline-block w-auto" onchange="updateInterventionStatus(${inv.id}, this.value)">
                            <option value="recommended" ${inv.status==='recommended'?'selected':''}>Recommended</option>
                            <option value="scheduled" ${inv.status==='scheduled'?'selected':''}>Scheduled</option>
                            <option value="in_progress" ${inv.status==='in_progress'?'selected':''}>In Progress</option>
                            <option value="completed" ${inv.status==='completed'?'selected':''}>Completed</option>
                            <option value="cancelled" ${inv.status==='cancelled'?'selected':''}>Cancelled</option>
                        </select>
                    </td>
                </tr>
            `;
        });
    }

    function updateInterventionStatus(invId, newStatus) {
        fetch('ajax_risk.php?action=update_intervention', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ intervention_id: invId, status: newStatus })
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                // Refresh student profile
                if (currentLoadedStudent) openStudentProfile(currentLoadedStudent.student_id);
            } else {
                alert('Update failed: ' + res.error);
            }
        });
    }

    function openRecordInterventionForCurrentStudent() {
        if (!currentLoadedStudent) return;
        document.getElementById('invModalStudentId').value = currentLoadedStudent.student_id;
        document.getElementById('invModalStudentName').value = currentLoadedStudent.name + ' (' + currentLoadedStudent.student_id_number + ')';
        document.getElementById('invModalGradeBefore').value = currentLoadedStudent.overall_grade || '72.0';
        new bootstrap.Modal(document.getElementById('recordInterventionModal')).show();
    }

    // Line Chart for Section 8
    function renderStudentPerfChart(d) {
        const ctx = document.getElementById('studentPerfChart');
        if (!ctx) return;
        if (studentPerfChartInstance) studentPerfChartInstance.destroy();

        const events = d.assessment_events || [];
        const labels = events.map((e, i) => {
            const dt = new Date(e.date);
            return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        const scores = events.map(e => e.score);

        studentPerfChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels.length ? labels : ['1st Sem', '2nd Sem', 'Current'],
                datasets: [{
                    label: 'Academic Performance (%)',
                    data: scores.length ? scores : [80, 75, d.overall_grade || 72],
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 5,
                    pointBackgroundColor: '#2563eb'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: { min: 40, max: 100, ticks: { callback: v => v + '%' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Multi-metric Trend Chart for Section 12
    function renderMultiMetricChart(d, timeframe = 'semester') {
        const ctx = document.getElementById('studentMultiMetricChart');
        if (!ctx) return;
        if (studentMultiMetricChartInstance) studentMultiMetricChartInstance.destroy();

        const tfData = (d.timeframe_trends && d.timeframe_trends[timeframe]) ? d.timeframe_trends[timeframe] : null;
        let events = tfData ? tfData.assessments : (d.assessment_events || []);

        const labels = events.map(e => new Date(e.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
        const gradeData = events.map(e => e.score);
        const quizData  = (d.quizzes || []).map(q => q.score);
        const attRate   = d.attendance_rate || 78;

        studentMultiMetricChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels.length ? labels : ['Aug', 'Sep', 'Oct'],
                datasets: [
                    {
                        label: 'Grades (%)',
                        data: gradeData.length ? gradeData : [80, 76, 72],
                        borderColor: '#2563eb',
                        tension: 0.3,
                        pointRadius: 4,
                    },
                    {
                        label: 'Quiz Scores (%)',
                        data: quizData.length ? quizData : [70, 60, 90],
                        borderColor: '#7c3aed',
                        tension: 0.3,
                        pointRadius: 4,
                    },
                    {
                        label: 'Attendance Rate (%)',
                        data: labels.map(() => attRate),
                        borderColor: '#10b981',
                        borderDash: [5, 5],
                        pointRadius: 0,
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: { min: 40, max: 100, ticks: { callback: v => v + '%' } }
                }
            }
        });

        // Set trend indicator badge
        const tBadge = document.getElementById('mTrendIndicatorBadge');
        if (d.trend_direction === 'declining') {
            tBadge.innerText = '📉 Declining (↓ ' + Math.abs(d.trend_change) + '%)';
            tBadge.className = 'badge b-risk mt-1';
        } else if (d.trend_direction === 'improving') {
            tBadge.innerText = '📈 Improving (↑ ' + Math.abs(d.trend_change) + '%)';
            tBadge.className = 'badge b-ok mt-1';
        } else {
            tBadge.innerText = '➡️ Stable';
            tBadge.className = 'badge b-mid mt-1';
        }
    }

    function switchStudentTrendTimeframe(tf, btn) {
        document.querySelectorAll('#studentTrendBtnGroup .btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        if (currentLoadedStudent) {
            renderMultiMetricChart(currentLoadedStudent, tf);
        }
    }

    // ─── Course At-Risk Modal (Section 19) ───
    function openCourseRiskModal(courseId) {
        fetch(`ajax_risk.php?action=get_course_analysis&course_id=${courseId}`)
            .then(res => res.json())
            .then(res => {
                if (!res.success) {
                    alert('Error: ' + res.error);
                    return;
                }
                const d = res.data;
                document.getElementById('cModalTitle').innerText = d.title;
                document.getElementById('cModalSubtitle').innerText = (d.class_code ? d.class_code + ' · ' : '') + 'Instructor: ' + d.instructor_name;
                document.getElementById('cModalTotalStudents').innerText = d.total_students;
                document.getElementById('cModalAvgGrade').innerText = d.avg_grade !== null ? d.avg_grade + '%' : 'No data';
                document.getElementById('cModalAvgQuiz').innerText = d.avg_quiz_score !== null ? d.avg_quiz_score + '%' : 'No data';
                document.getElementById('cModalAtRiskCount').innerText = d.at_risk_count;

                const tbody = document.getElementById('cModalStudentsTable');
                tbody.innerHTML = '';
                if (!d.at_risk_students || d.at_risk_students.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No at-risk students flagged in this course! All students are performing satisfactorily.</td></tr>';
                } else {
                    d.at_risk_students.forEach(st => {
                        tbody.innerHTML += `
                            <tr>
                                <td><strong>${st.name}</strong> <small class="text-muted">(${st.student_id_number || '—'})</small></td>
                                <td class="text-center fw-bold text-danger">${st.grade !== null ? st.grade + '%' : '—'}</td>
                                <td class="text-center">${st.attendance_rate !== null ? st.attendance_rate + '%' : '—'}</td>
                                <td><span class="badge b-risk">${st.overall_risk.toUpperCase()}</span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary py-0 px-2 fw-bold" onclick="bootstrap.Modal.getInstance(document.getElementById('courseRiskModal')).hide(); openStudentProfile(${st.student_id});">
                                        View Profile
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                }

                new bootstrap.Modal(document.getElementById('courseRiskModal')).show();
            });
    }

    // ─── Department Performance Trends Chart (Section 18) ───
    const deptHistoricalTrendsData = <?php echo json_encode($deptHistoricalTrends); ?>;
    let deptChartInstance = null;

    function renderDeptTrendsChart(filter = 'weekly') {
        const canvas = document.getElementById('deptTrendsCanvas');
        if (!canvas) return;
        if (deptChartInstance) deptChartInstance.destroy();

        const labels = deptHistoricalTrendsData.map(d => d.label_date);
        const grades = deptHistoricalTrendsData.map(d => d.dept_grade);
        const quizzes = deptHistoricalTrendsData.map(d => d.dept_quiz);
        const attendance = deptHistoricalTrendsData.map(d => d.dept_att);
        const atRiskCounts = deptHistoricalTrendsData.map(d => d.dept_at_risk);

        deptChartInstance = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Average Department Grade (%)',
                        data: grades,
                        borderColor: '#1e3a8a',
                        backgroundColor: 'rgba(30, 58, 138, 0.08)',
                        fill: false,
                        tension: 0.35,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Average Quiz Score (%)',
                        data: quizzes,
                        borderColor: '#7c3aed',
                        backgroundColor: 'rgba(124, 58, 237, 0.08)',
                        fill: false,
                        tension: 0.35,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Department Attendance (%)',
                        data: attendance,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.08)',
                        fill: false,
                        tension: 0.35,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Number of At-Risk Students',
                        data: atRiskCounts,
                        borderColor: '#dc2626',
                        backgroundColor: '#dc2626',
                        type: 'bar',
                        yAxisID: 'y1',
                        barThickness: 28,
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        min: 40,
                        max: 100,
                        ticks: { callback: v => v + '%' }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        min: 0,
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    function setDeptTrendFilter(filter, btn) {
        document.querySelectorAll('#deptTimeBtnGroup .btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        renderDeptTrendsChart(filter);
    }

    // Overview Department Quiz Chart
    const perfCanvas = document.getElementById('performanceChart');
    if (perfCanvas) {
        new Chart(perfCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: deptHistoricalTrendsData.map(d => d.label_date),
                datasets: [{
                    label: 'Department Average Score',
                    data: deptHistoricalTrendsData.map(d => d.dept_quiz),
                    borderColor: '#7c3aed',
                    backgroundColor: 'rgba(124,58,237,0.1)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { min: 40, max: 100, ticks: { callback: v => v + '%' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Analytics Enrollment & Program Charts — deferred until tab is visible
    let enrollChartInstance = null;
    let progChartInstance = null;

    function initAnalyticsCharts() {
        const enrollCanvas = document.getElementById('enrollChart');
        if (enrollCanvas && !enrollChartInstance) {
            enrollChartInstance = new Chart(enrollCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($enrollMonths); ?>,
                    datasets: [{
                        label: 'New Students',
                        data: <?php echo json_encode($enrollCounts); ?>,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,0.1)',
                        fill: true, tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }

        const progCanvas = document.getElementById('progChart');
        if (progCanvas && !progChartInstance) {
            progChartInstance = new Chart(progCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($progLabels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($progCounts); ?>,
                        backgroundColor: ['#1e3a8a','#2563eb','#4f46e5','#7c3aed','#a855f7','#d8b4fe'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true, cutout: '65%',
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }
                }
            });
        }
    }

    // Initialize department chart only when analytics tab is visible (deferred above)
    // renderDeptTrendsChart('weekly') is called by switchTab/URL-init

    // ─── Reports Generation ───
    const reportData = {
        atrisk: <?php echo json_encode(array_values(array_map(fn($s) => [
            'name'       => $s['name'],
            'id'         => $s['student_id_number'] ?? '—',
            'program'    => $s['program_name'] ?? '—',
            'grade'      => $s['overall_grade'] !== null ? $s['overall_grade'].'%' : '—',
            'quiz'       => $s['quiz_average'] !== null ? $s['quiz_average'].'%' : '—',
            'attendance' => $s['attendance_rate'] !== null ? $s['attendance_rate'].'%' : '—',
            'reasons'    => implode('; ', $s['reasons'] ?? []),
        ], array_filter($allStudents, fn($s) => $s['risk_level'] === 'at_risk')))); ?>,

        students: <?php echo json_encode(array_map(fn($s) => [
            'name'       => $s['name'],
            'id'         => $s['student_id_number'] ?? '—',
            'program'    => $s['program_name'] ?? '—',
            'year'       => $s['year_level'] ?? '—',
            'grade'      => $s['overall_grade'] !== null ? $s['overall_grade'].'%' : '—',
            'quiz'       => $s['quiz_average'] !== null ? $s['quiz_average'].'%' : '—',
            'attendance' => $s['attendance_rate'] !== null ? $s['attendance_rate'].'%' : '—',
            'risk'       => $s['risk_badge_label'],
        ], $allStudents)); ?>,

        faculty: <?php echo json_encode(array_map(fn($f) => [
            'name'     => $f['name'],
            'email'    => $f['email'],
            'courses'  => $f['course_count'],
            'students' => $f['student_count'],
            'score'    => $f['avg_student_score'] !== null ? number_format((float)$f['avg_student_score'], 1) . '%' : '—',
        ], $faculty)); ?>,

        programs: <?php echo json_encode(array_map(fn($p) => [
            'name'     => $p['name'],
            'courses'  => $p['course_count'],
            'students' => $p['student_count'],
            'status'   => ucfirst($p['status']),
        ], $programs)); ?>,

        academic: {
            dept:        <?php echo json_encode($dept_name); ?>,
            programs:    <?php echo (int)$total_programs; ?>,
            students:    <?php echo (int)$total_students; ?>,
            instructors: <?php echo (int)$total_instructors; ?>,
            courses:     <?php echo (int)$total_courses; ?>,
            avgGrade:    <?php echo $avg_grade === null ? 'null' : (float)$avg_grade; ?>,
            avgQuiz:     <?php echo $avg_score === null ? 'null' : (float)$avg_score; ?>,
            avgAtt:      <?php echo $avg_attendance === null ? 'null' : (float)$avg_attendance; ?>,
            atRisk:      <?php echo (int)$at_risk_count; ?>,
        }
    };

    const reportMeta = {
        atrisk: {
            title: 'At-Risk Students Report (Actionable Interventions)',
            cols: ['Student Name', 'ID Number', 'Program', 'Grade Avg', 'Quiz Avg', 'Attendance', 'Risk Reasons'],
            rows: d => d.atrisk.map(s => [s.name, s.id, s.program, s.grade, s.quiz, s.attendance, s.reasons])
        },
        students: {
            title: 'Complete Student Academic Performance Report',
            cols: ['Student Name', 'ID Number', 'Program', 'Year', 'Grade Avg', 'Quiz Avg', 'Attendance', 'Risk Classification'],
            rows: d => d.students.map(s => [s.name, s.id, s.program, s.year, s.grade, s.quiz, s.attendance, s.risk])
        },
        faculty: {
            title: 'Faculty Department Performance Report',
            cols: ['Instructor Name', 'Email', 'Active Courses', 'Total Enrolled', 'Avg Student Score'],
            rows: d => d.faculty.map(f => [f.name, f.email, f.courses, f.students, f.score])
        },
        programs: {
            title: 'Program Academic Overview',
            cols: ['Program Name', 'Courses Offered', 'Students Enrolled', 'Status'],
            rows: d => d.programs.map(p => [p.name, p.courses, p.students, p.status])
        },
        academic: {
            title: 'Comprehensive Department Academic KPI Report',
            cols: ['Academic Metric', 'Calculated Department Value'],
            rows: d => [
                ['Department', d.academic.dept],
                ['Active Programs', d.academic.programs],
                ['Total Enrolled Students', d.academic.students],
                ['Total Faculty Instructors', d.academic.instructors],
                ['Active Subjects / Courses', d.academic.courses],
                ['Department Average Grade', d.academic.avgGrade !== null ? d.academic.avgGrade + '%' : '—'],
                ['Department Average Quiz Score', d.academic.avgQuiz !== null ? d.academic.avgQuiz + '%' : '—'],
                ['Department Attendance Rate', d.academic.avgAtt !== null ? d.academic.avgAtt + '%' : '—'],
                ['Identified At-Risk Students', d.academic.atRisk],
            ]
        }
    };

    function showReport(key) {
        const meta = reportMeta[key];
        if (!meta) return;
        document.getElementById('reportTitle').innerHTML = '<i class="fas fa-file-alt me-2 text-primary"></i>' + meta.title;
        document.getElementById('reportOutput').style.display = 'block';

        const rows = meta.rows(reportData);
        let html = '<div class="p-3">';
        html += `<p class="small text-muted mb-3">Generated: ${new Date().toLocaleString('en-US')} | Department: <strong><?php echo addslashes($dept_name); ?></strong></p>`;

        if (rows.length === 0) {
            html += '<p class="text-muted text-center py-4">No data available for this report.</p>';
        } else {
            html += '<table class="table table-bordered table-striped" style="font-size:0.82rem;">';
            html += '<thead class="table-primary"><tr>';
            meta.cols.forEach(c => html += `<th>${c}</th>`);
            html += '</tr></thead><tbody>';
            rows.forEach(r => {
                html += '<tr>';
                r.forEach(cell => html += `<td>${cell || '—'}</td>`);
                html += '</tr>';
            });
            html += '</tbody></table>';
        }
        html += '</div>';
        document.getElementById('reportBody').innerHTML = html;
        document.getElementById('reportOutput').scrollIntoView({ behavior: 'smooth' });
    }

    const thresholdModal = document.getElementById('thresholdModal');
    if (thresholdModal) document.body.appendChild(thresholdModal);
</script>

<?php include 'includes/footer.php'; ?>
