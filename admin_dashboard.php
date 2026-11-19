<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Administrator';
$role = $_SESSION['user_role'];

$academicYears = $pdo->query('SELECT id, name, start_date, end_date, is_current, status FROM academic_years ORDER BY start_date DESC')->fetchAll();
$selectedAcademicYearId = (int)($_GET['academic_year_id'] ?? 0);
if ($selectedAcademicYearId <= 0) {
    $currentYearStmt = $pdo->query("SELECT id FROM academic_years WHERE status = 'active' AND start_date <= CURDATE() AND end_date >= CURDATE() ORDER BY start_date DESC, id DESC LIMIT 1");
    $selectedAcademicYearId = (int)($currentYearStmt->fetchColumn() ?: 0);
}
$selectedAcademicYear = null;
$academicYearSummary = null;
$academicYearCourses = [];
$academicYearSections = [];
$academicYearStudents = [];
$academicYearInstructors = [];
if ($selectedAcademicYearId > 0) {
    $selectedYearStmt = $pdo->prepare('SELECT id, name, start_date, end_date FROM academic_years WHERE id = ?');
    $selectedYearStmt->execute([$selectedAcademicYearId]);
    $selectedAcademicYear = $selectedYearStmt->fetch() ?: null;
    if (!$selectedAcademicYear) $selectedAcademicYearId = 0;
}
if ($selectedAcademicYear) {
    $yearSummaryStmt = $pdo->prepare(" 
        SELECT
            (SELECT COUNT(DISTINCT sub.id) FROM subjects sub LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE sem.academic_year_id = ay.id OR sec.academic_year_id = ay.id OR sub.academic_year_id = ay.id) AS course_count,
            (SELECT COUNT(DISTINCT sec.id) FROM sections sec WHERE sec.academic_year_id = ay.id) AS section_count,
            (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e JOIN subjects sub ON sub.id = e.subject_id LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE sem.academic_year_id = ay.id OR sec.academic_year_id = ay.id OR sub.academic_year_id = ay.id) AS student_count,
            (SELECT COUNT(DISTINCT sub.instructor_id) FROM subjects sub LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE (sem.academic_year_id = ay.id OR sec.academic_year_id = ay.id OR sub.academic_year_id = ay.id) AND sub.instructor_id IS NOT NULL) AS instructor_count,
            (SELECT COUNT(DISTINCT a.id) FROM assignments a JOIN subjects sub ON sub.id = a.subject_id LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE sem.academic_year_id = ay.id OR sec.academic_year_id = ay.id OR sub.academic_year_id = ay.id) AS assignment_count,
            (SELECT COUNT(DISTINCT att.id) FROM attendance att JOIN subjects sub ON sub.id = att.subject_id LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE sem.academic_year_id = ay.id OR sec.academic_year_id = ay.id OR sub.academic_year_id = ay.id) AS attendance_count,
            (SELECT COUNT(DISTINCT g.grade_id) FROM student_grades g JOIN subjects sub ON sub.id = g.subject_id LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE sem.academic_year_id = ay.id OR sec.academic_year_id = ay.id OR sub.academic_year_id = ay.id) AS grade_count,
            (SELECT COUNT(DISTINCT e.id) FROM enrollments e JOIN subjects sub ON sub.id = e.subject_id LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE sem.academic_year_id = ay.id OR sec.academic_year_id = ay.id OR sub.academic_year_id = ay.id) AS enrollment_count,
            (SELECT AVG(CASE WHEN subm.grade REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST(subm.grade AS DECIMAL(8,2)) END) FROM submissions subm JOIN assignments a ON a.id = subm.assignment_id JOIN subjects sub ON sub.id = a.subject_id LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE sem.academic_year_id = ay.id OR sec.academic_year_id = ay.id OR sub.academic_year_id = ay.id) AS average_assignment_grade,
            (SELECT AVG(g.final_average) FROM student_grades g JOIN subjects sub ON sub.id = g.subject_id LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE (sem.academic_year_id = ay.id OR sec.academic_year_id = ay.id OR sub.academic_year_id = ay.id) AND g.final_average IS NOT NULL) AS average_course_grade
        FROM academic_years ay WHERE ay.id = ?
    ");
    $yearSummaryStmt->execute([$selectedAcademicYearId]);
    $academicYearSummary = $yearSummaryStmt->fetch();

    $yearCoursesStmt = $pdo->prepare(" 
        SELECT DISTINCT sub.id, sub.title, sub.class_code, COALESCE(sub.semester, sem.semester_name) AS semester_name, instructor.name AS instructor_name
        FROM subjects sub
        LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id
        LEFT JOIN sections sec ON sec.id = sub.section_id
        LEFT JOIN users instructor ON instructor.id = sub.instructor_id
        WHERE COALESCE(sub.academic_year_id, sem.academic_year_id, sec.academic_year_id) = ?
        ORDER BY sub.title
    ");
    $yearCoursesStmt->execute([$selectedAcademicYearId]);
    $academicYearCourses = $yearCoursesStmt->fetchAll();
    $yearSectionsStmt = $pdo->prepare("SELECT sec.id, sec.name, p.name AS program_name, sem.semester_name FROM sections sec JOIN programs p ON p.id = sec.program_id LEFT JOIN semesters sem ON sem.semester_id = sec.semester_id WHERE sec.academic_year_id = ? ORDER BY p.name, sec.name");
    $yearSectionsStmt->execute([$selectedAcademicYearId]);
    $academicYearSections = $yearSectionsStmt->fetchAll();

    $yearStudentsStmt = $pdo->prepare(" 
        SELECT DISTINCT u.id, u.name, COALESCE(sp.student_id_number, u.student_id_number, CONCAT('STU-', LPAD(u.id, 4, '0'))) AS student_id_number
        FROM enrollments e
        JOIN users u ON u.id = e.student_id
        JOIN subjects sub ON sub.id = e.subject_id
        LEFT JOIN student_profiles sp ON sp.student_id = u.id
        LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id
        LEFT JOIN sections sec ON sec.id = sub.section_id
        WHERE COALESCE(sub.academic_year_id, sem.academic_year_id, sec.academic_year_id) = ?
        ORDER BY u.name
        LIMIT 20
    ");
    $yearStudentsStmt->execute([$selectedAcademicYearId]);
    $academicYearStudents = $yearStudentsStmt->fetchAll();
    $yearInstructorsStmt = $pdo->prepare(" 
        SELECT DISTINCT u.id, u.name
        FROM subjects sub
        JOIN users u ON u.id = sub.instructor_id
        LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id
        LEFT JOIN sections sec ON sec.id = sub.section_id
        WHERE COALESCE(sub.academic_year_id, sem.academic_year_id, sec.academic_year_id) = ?
        ORDER BY u.name
    ");
    $yearInstructorsStmt->execute([$selectedAcademicYearId]);
    $academicYearInstructors = $yearInstructorsStmt->fetchAll();
}

// ============================================================
// Dashboard Statistics
// ============================================================

$stats = [];
$stats['total_students'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$stats['total_instructors'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'instructor'")->fetchColumn();
$stats['total_deans'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'dean'")->fetchColumn();
$stats['total_courses'] = $pdo->query("SELECT COUNT(*) FROM subjects WHERE status = 'active'")->fetchColumn();
$stats['active_enrollments'] = $pdo->query("SELECT COUNT(*) FROM enrollments")->fetchColumn();
$stats['total_programs'] = $pdo->query("SELECT COUNT(*) FROM programs WHERE status = 'active'")->fetchColumn();
$stats['total_departments'] = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();

// Course completion stats
$stats['completed_courses'] = $pdo->query("SELECT COUNT(*) FROM subjects WHERE status = 'completed'")->fetchColumn();
$stats['draft_courses'] = $pdo->query("SELECT COUNT(*) FROM subjects WHERE status = 'draft'")->fetchColumn();

// Recent activity
$stats['new_students_today'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND DATE(created_at) = CURDATE()")->fetchColumn();
$stats['new_instructors_today'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'instructor' AND DATE(created_at) = CURDATE()")->fetchColumn();
if ($selectedAcademicYear) {
    $recentYearEnrollments = $pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN subjects sub ON sub.id = e.subject_id LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE DATE(e.enrolled_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND COALESCE(sub.academic_year_id, sem.academic_year_id, sec.academic_year_id) = ?");
    $recentYearEnrollments->execute([$selectedAcademicYearId]);
    $stats['new_enrollments_week'] = $recentYearEnrollments->fetchColumn();
} else {
    $stats['new_enrollments_week'] = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE DATE(enrolled_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
}

// ============================================================
// Enrollment Trends (Last 6 months)
// ============================================================
if ($selectedAcademicYear) {
    $stmt = $pdo->prepare(" 
        SELECT DATE_FORMAT(e.enrolled_at, '%Y-%m') AS month, COUNT(*) AS count
        FROM enrollments e
        JOIN subjects sub ON sub.id = e.subject_id
        LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id
        LEFT JOIN sections sec ON sec.id = sub.section_id
        WHERE e.enrolled_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
          AND COALESCE(sub.academic_year_id, sem.academic_year_id, sec.academic_year_id) = ?
        GROUP BY DATE_FORMAT(e.enrolled_at, '%Y-%m') ORDER BY month ASC
    ");
    $stmt->execute([$selectedAcademicYearId]);
} else {
    $stmt = $pdo->query("SELECT DATE_FORMAT(enrolled_at, '%Y-%m') AS month, COUNT(*) AS count FROM enrollments WHERE enrolled_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(enrolled_at, '%Y-%m') ORDER BY month ASC");
}
$enrollmentTrends = $stmt->fetchAll();

// ============================================================
// Course Completion Rates
// ============================================================
if ($selectedAcademicYear) {
    $stmt = $pdo->prepare("SELECT sub.status, COUNT(DISTINCT sub.id) AS count FROM subjects sub LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE COALESCE(sub.academic_year_id, sem.academic_year_id, sec.academic_year_id) = ? GROUP BY sub.status");
    $stmt->execute([$selectedAcademicYearId]);
} else {
    $stmt = $pdo->query("SELECT s.status, COUNT(*) AS count FROM subjects s GROUP BY s.status");
}
$courseCompletionRates = $stmt->fetchAll();

// ============================================================
// Student Performance Statistics (Average grades)
// ============================================================
if ($selectedAcademicYear) {
    $stmt = $pdo->prepare(" 
        SELECT COUNT(CASE WHEN subm.grade IS NOT NULL THEN 1 END) AS graded_submissions,
               COUNT(*) AS total_submissions,
               ROUND((COUNT(CASE WHEN subm.grade IS NOT NULL THEN 1 END) / NULLIF(COUNT(*), 0)) * 100, 1) AS grading_rate
        FROM submissions subm
        JOIN assignments a ON a.id = subm.assignment_id
        JOIN subjects sub ON sub.id = a.subject_id
        LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id
        LEFT JOIN sections sec ON sec.id = sub.section_id
        WHERE COALESCE(sub.academic_year_id, sem.academic_year_id, sec.academic_year_id) = ?
    ");
    $stmt->execute([$selectedAcademicYearId]);
} else {
    $stmt = $pdo->query("SELECT COUNT(CASE WHEN grade IS NOT NULL THEN 1 END) AS graded_submissions, COUNT(*) AS total_submissions, ROUND((COUNT(CASE WHEN grade IS NOT NULL THEN 1 END) / NULLIF(COUNT(*), 0)) * 100, 1) AS grading_rate FROM submissions");
}
$performanceStats = $stmt->fetch();

// ============================================================
// System Activity (Last 30 days)
// ============================================================
$stmt = $pdo->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m-%d') as date,
        COUNT(*) as count
    FROM users 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d')
    ORDER BY date ASC
");
$systemActivity = $stmt->fetchAll();

// ============================================================
// Recent Announcements
// ============================================================
$stmt = $pdo->query("
    SELECT 
        n.id, n.message, n.is_read, n.created_at,
        u.name as created_by_name
    FROM notifications n
    LEFT JOIN users u ON u.id = n.user_id
    ORDER BY n.created_at DESC
    LIMIT 5
");
$recentAnnouncements = $stmt->fetchAll();

// ============================================================
// Recent Registrations
// ============================================================
$stmt = $pdo->query("
    SELECT id, name, email, role, created_at 
    FROM users 
    WHERE role IN ('student', 'instructor') 
    ORDER BY created_at DESC 
    LIMIT 10
");
$recentRegistrations = $stmt->fetchAll();

// ============================================================
// Top Performing Courses
// ============================================================
if ($selectedAcademicYear) {
    $stmt = $pdo->prepare(" 
        SELECT sub.id, sub.title, sub.class_code, u.name AS instructor_name, COUNT(DISTINCT e.id) AS enrollment_count
        FROM subjects sub
        LEFT JOIN users u ON u.id = sub.instructor_id
        LEFT JOIN enrollments e ON e.subject_id = sub.id
        LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id
        LEFT JOIN sections sec ON sec.id = sub.section_id
        WHERE sub.status = 'active' AND COALESCE(sub.academic_year_id, sem.academic_year_id, sec.academic_year_id) = ?
        GROUP BY sub.id, sub.title, sub.class_code, u.name ORDER BY enrollment_count DESC LIMIT 5
    ");
    $stmt->execute([$selectedAcademicYearId]);
} else {
    $stmt = $pdo->query("SELECT s.id, s.title, s.class_code, u.name AS instructor_name, COUNT(e.id) AS enrollment_count FROM subjects s LEFT JOIN users u ON u.id = s.instructor_id LEFT JOIN enrollments e ON e.subject_id = s.id WHERE s.status = 'active' GROUP BY s.id, s.title, s.class_code, u.name ORDER BY enrollment_count DESC LIMIT 5");
}
$topCourses = $stmt->fetchAll();

// ============================================================
// Pending Tasks
// ============================================================
if ($selectedAcademicYear) {
    $pendingGradesStmt = $pdo->prepare("SELECT COUNT(*) FROM submissions subm JOIN assignments a ON a.id = subm.assignment_id JOIN subjects sub ON sub.id = a.subject_id LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id WHERE subm.grade IS NULL AND COALESCE(sub.academic_year_id, sem.academic_year_id, sec.academic_year_id) = ?");
    $pendingGradesStmt->execute([$selectedAcademicYearId]);
    $pending_grades = $pendingGradesStmt->fetchColumn();
} else {
    $pending_grades = $pdo->query("SELECT COUNT(*) FROM submissions WHERE grade IS NULL")->fetchColumn();
}
$pending_enrollments = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE enrolled_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$unread_notifications = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();

// ============================================================
// Department Distribution
// ============================================================
$stmt = $pdo->query("
    SELECT 
        d.name as department_name,
        COUNT(DISTINCT sp.student_id) as student_count,
        COUNT(DISTINCT p.id) as program_count
    FROM departments d
    LEFT JOIN programs p ON p.department_id = d.id
    LEFT JOIN student_profiles sp ON sp.department_id = d.id
    GROUP BY d.id, d.name
    ORDER BY student_count DESC
    LIMIT 5
");
$departmentStats = $stmt->fetchAll();

// Prepare chart data
$enrollmentMonths = array_column($enrollmentTrends, 'month');
$enrollmentCounts = array_column($enrollmentTrends, 'count');

$completionLabels = array_column($courseCompletionRates, 'status');
$completionCounts = array_column($courseCompletionRates, 'count');

$activityDates = array_column($systemActivity, 'date');
$activityCounts = array_column($systemActivity, 'count');

$pageTitle = 'Admin Dashboard - LearnAlytics';
$extraCss = ['assets/css/academic-year-management.css'];
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4 admin-dashboard">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold dashboard-title">
                <i class="fas fa-tachometer-alt me-2 dashboard-icon"></i> Admin Dashboard
            </h1>
            <p class="dashboard-subtitle mb-0">Welcome back, <?php echo htmlspecialchars($user_name); ?>! Here's your system overview.</p>
        </div>
        <div class="d-flex gap-2 page-actions">
            <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
        </div>
    </div>

    <section class="academic-year-overview mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
            <div>
                <h2 class="h5 mb-1" style="color:#1e3a8a;">Academic Year View</h2>
                <p class="text-muted small mb-0">Switch the academic records shown for a specific year.</p>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <form method="get" class="d-flex gap-2 align-items-center">
                    <label class="visually-hidden" for="dashboardAcademicYear">Academic year</label>
                    <select class="form-select form-select-sm" id="dashboardAcademicYear" name="academic_year_id" onchange="this.form.submit()">
                        <option value="0">Select academic year</option>
                        <?php foreach ($academicYears as $academicYear): ?>
                            <option value="<?php echo (int)$academicYear['id']; ?>" <?php echo $selectedAcademicYearId === (int)$academicYear['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($academicYear['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a href="academic_year_management.php" class="btn btn-sm btn-outline-primary">Manage Years</a>
            </div>
        </div>
        <?php if ($selectedAcademicYear && $academicYearSummary): ?>
            <h3 class="h6 mb-3"><?php echo htmlspecialchars($selectedAcademicYear['name']); ?> <span class="text-muted fw-normal">· <?php echo htmlspecialchars($selectedAcademicYear['start_date'] . ' to ' . $selectedAcademicYear['end_date']); ?></span></h3>
            <div class="academic-year-stat-grid mb-3">
                <?php foreach (['student_count' => 'Students', 'instructor_count' => 'Instructors', 'course_count' => 'Courses', 'section_count' => 'Sections', 'enrollment_count' => 'Enrollments', 'assignment_count' => 'Assignments', 'attendance_count' => 'Attendance', 'grade_count' => 'Grade Records', 'average_assignment_grade' => 'Average Assignment Score', 'average_course_grade' => 'Average Course Grade'] as $key => $label): ?>
                    <?php $isAverage = in_array($key, ['average_assignment_grade', 'average_course_grade'], true); ?>
                    <div class="academic-year-stat"><strong><?php echo $isAverage ? ($academicYearSummary[$key] !== null ? number_format((float)$academicYearSummary[$key], 1) : '—') : (int)$academicYearSummary[$key]; ?></strong><span><?php echo $label; ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="table-responsive border rounded">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Courses</th><th>Semester</th><th>Instructor</th></tr></thead>
                            <tbody>
                                <?php foreach ($academicYearCourses as $course): ?>
                                    <tr><td><?php echo htmlspecialchars($course['title']); ?><small class="d-block text-muted"><?php echo htmlspecialchars($course['class_code'] ?? ''); ?></small></td><td><?php echo htmlspecialchars($course['semester_name'] ?? 'Unassigned'); ?></td><td><?php echo htmlspecialchars($course['instructor_name'] ?? 'Unassigned'); ?></td></tr>
                                <?php endforeach; ?>
                                <?php if (!$academicYearCourses): ?><tr><td colspan="3" class="text-muted text-center py-3">No courses are associated with this year yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="table-responsive border rounded">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Sections</th><th>Program</th><th>Semester</th></tr></thead>
                            <tbody>
                                <?php foreach ($academicYearSections as $section): ?>
                                    <tr><td><?php echo htmlspecialchars($section['name']); ?></td><td><?php echo htmlspecialchars($section['program_name']); ?></td><td><?php echo htmlspecialchars($section['semester_name'] ?? 'Unassigned'); ?></td></tr>
                                <?php endforeach; ?>
                                <?php if (!$academicYearSections): ?><tr><td colspan="3" class="text-muted text-center py-3">No sections are associated with this year yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-lg-7">
                    <div class="table-responsive border rounded">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Enrolled Students</th><th>Student ID</th></tr></thead>
                            <tbody>
                                <?php foreach ($academicYearStudents as $student): ?>
                                    <tr><td><?php echo htmlspecialchars($student['name']); ?></td><td><?php echo htmlspecialchars($student['student_id_number']); ?></td></tr>
                                <?php endforeach; ?>
                                <?php if (!$academicYearStudents): ?><tr><td colspan="2" class="text-muted text-center py-3">No enrolled students are associated with this year.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="table-responsive border rounded">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Assigned Instructors</th></tr></thead>
                            <tbody>
                                <?php foreach ($academicYearInstructors as $instructor): ?><tr><td><?php echo htmlspecialchars($instructor['name']); ?></td></tr><?php endforeach; ?>
                                <?php if (!$academicYearInstructors): ?><tr><td class="text-muted text-center py-3">No instructors are associated with this year.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="text-muted small">No academic year is selected. Create or select one to view its academic records.</div>
        <?php endif; ?>
    </section>

    <!-- Search and Filter Section -->
    <div class="search-section">
        <h5 class="fw-bold mb-3" style="color: var(--primary-blue);">
            <i class="fas fa-search me-2"></i>Search & Filter System
        </h5>
        <form action="admin/search.php" method="GET" class="row g-3">
            <div class="col-lg-8">
                <div class="input-group search-input-group">
                    <span class="input-group-text search-input-prefix">
                        <i class="fas fa-search text-muted"></i>
                    </span>
                    <input type="text" name="q" class="form-control search-input border-start-0" placeholder="Search by Name, ID, Email, Program, Department, Course, or Status...">
                </div>
            </div>
            <div class="col-lg-2">
                <select name="type" class="form-select search-input">
                    <option value="all">All Types</option>
                    <option value="student">Students</option>
                    <option value="instructor">Instructors</option>
                    <option value="course">Courses</option>
                    <option value="enrollment">Enrollments</option>
                </select>
            </div>
            <div class="col-lg-2">
                <button type="submit" class="btn search-btn w-100">
                    <i class="fas fa-search me-1"></i> Search
                </button>
            </div>
        </form>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="chart-container">
                <div class="chart-header">
                    <h5 class="chart-title">
                        <i class="fas fa-chart-line me-2"></i> Enrollment Trends (6 Months)
                    </h5>
                    <select class="form-select form-select-sm chart-filter-select">
                        <option>Last 6 Months</option>
                        <option>Last Year</option>
                    </select>
                </div>
                <canvas id="enrollmentChart" height="150"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-container">
                <div class="chart-header">
                    <h5 class="chart-title">
                        <i class="fas fa-chart-pie me-2"></i> Course Completion Rates
                    </h5>
                </div>
                <canvas id="completionChart" height="150"></canvas>
                <div class="mt-3">
                    <?php foreach ($courseCompletionRates as $rate): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted"><?php echo ucfirst($rate['status']); ?></span>
                            <span class="fw-bold text-primary-blue"><?php echo $rate['count']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- System Activity Chart -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="chart-container">
                <div class="chart-header">
                    <h5 class="chart-title">
                        <i class="fas fa-chart-bar me-2"></i>System Activity (Last 30 Days)
                    </h5>
                </div>
                <canvas id="activityChart" height="100"></canvas>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <h5 class="fw-bold mb-3 text-primary-blue">
                <i class="fas fa-bolt me-2"></i>Quick Actions
            </h5>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a href="admin/users.php?type=student" class="quick-action-card">
                <div class="quick-action-icon icon-bg-blue">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="quick-action-label">View Students</div>
                <div class="quick-action-desc"><?php echo number_format($stats['total_students']); ?> registered</div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a href="admin/users.php?type=instructor" class="quick-action-card">
                <div class="quick-action-icon icon-bg-yellow">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="quick-action-label">View Instructors</div>
                <div class="quick-action-desc"><?php echo number_format($stats['total_instructors']); ?> active</div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a href="admin/enrollments.php" class="quick-action-card">
                <div class="quick-action-icon icon-bg-green">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div class="quick-action-label">Manage Enrollments</div>
                <div class="quick-action-desc"><?php echo number_format($stats['active_enrollments']); ?> enrollments</div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a href="admin/reports.php" class="quick-action-card">
                <div class="quick-action-icon icon-bg-purple">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <div class="quick-action-label">View Reports</div>
                <div class="quick-action-desc">Analytics & insights</div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a href="admin/announcements.php" class="quick-action-card">
                <div class="quick-action-icon icon-bg-pink">
                    <i class="fas fa-bullhorn"></i>
                </div>
                <div class="quick-action-label">Announcements</div>
                <div class="quick-action-desc">Post updates</div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a href="admin/settings.php" class="quick-action-card">
                <div class="quick-action-icon" style="background: #f1f5f9; color: #475569;">
                    <i class="fas fa-cog"></i>
                </div>
                <div class="quick-action-label">System Settings</div>
                <div class="quick-action-desc">Configuration</div>
            </a>
        </div>
    </div>

    <!-- Main Content Tabs -->
    <div class="chart-container mb-4">
        <div class="tab-nav">
            <button class="tab-btn active" onclick="switchTab('recent', this)">Recent Registrations</button>
            <button class="tab-btn" onclick="switchTab('courses', this)">Top Courses</button>
            <button class="tab-btn" onclick="switchTab('announcements', this)">Announcements</button>
            <button class="tab-btn" onclick="switchTab('departments', this)">Departments</button>
        </div>

        <!-- Recent Registrations Tab -->
        <div id="tab-recent" class="tab-content active">
            <div class="table-responsive">
                <table class="data-table w-100">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Registered</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentRegistrations as $user): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle me-2" style="width: 36px; height: 36px; background: <?php echo $user['role'] === 'student' ? '#dbeafe' : '#fef3c7'; ?>; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.8rem; color: <?php echo $user['role'] === 'student' ? '#2563eb' : '#d97706'; ?>;">
                                            <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                                        </div>
                                        <?php echo htmlspecialchars($user['name']); ?>
                                    </div>
                                </td>
                                <td class="text-muted"><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $user['role'] === 'student' ? 'status-active' : 'status-pending'; ?>">
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                </td>
                                <td class="text-muted"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td><span class="status-badge status-active">Active</span></td>
                                <td>
                                    <a href="admin/user_performance.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top Courses Tab -->
        <div id="tab-courses" class="tab-content">
            <div class="table-responsive">
                <table class="data-table w-100">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Code</th>
                            <th>Instructor</th>
                            <th>Enrollments</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topCourses as $course): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($course['title']); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars($course['class_code'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($course['instructor_name'] ?? 'N/A'); ?></td>
                                <td><span class="badge bg-primary"><?php echo $course['enrollment_count']; ?></span></td>
                                <td><span class="status-badge status-active">Active</span></td>
                                <td>
                                    <a href="subjects/view.php?id=<?php echo $course['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Announcements Tab -->
        <div id="tab-announcements" class="tab-content">
            <?php foreach ($recentAnnouncements as $announcement): ?>
                <div class="announcement-item <?php echo !$announcement['is_read'] ? 'unread' : ''; ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <p class="mb-1"><?php echo htmlspecialchars($announcement['message']); ?></p>
                            <small class="text-muted">
                                <?php echo date('M d, Y H:i', strtotime($announcement['created_at'])); ?>
                                <?php if ($announcement['created_by_name']): ?>
                                    · by <?php echo htmlspecialchars($announcement['created_by_name']); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <?php if (!$announcement['is_read']): ?>
                            <span class="badge bg-warning">New</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($recentAnnouncements)): ?>
                <p class="text-muted text-center py-4">No announcements yet.</p>
            <?php endif; ?>
        </div>

        <!-- Departments Tab -->
        <div id="tab-departments" class="tab-content">
            <div class="table-responsive">
                <table class="data-table w-100">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Students</th>
                            <th>Programs</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departmentStats as $dept): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($dept['department_name']); ?></td>
                                <td><span class="badge bg-info"><?php echo $dept['student_count']; ?></span></td>
                                <td><span class="badge bg-secondary"><?php echo $dept['program_count']; ?></span></td>
                                <td>
                                    <a href="programs/department_view.php?id=<?php echo $dept['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // Tab Switching
    function switchTab(tabName, button) {
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.getElementById('tab-' + tabName).classList.add('active');
        if (button) button.classList.add('active');
    }

    // Filter by Status
    function filterByStatus(status) {
        window.location.href = 'admin/users.php?status=' + status;
    }

    // Enrollment Trend Chart
    const enrollmentCtx = document.getElementById('enrollmentChart').getContext('2d');
    new Chart(enrollmentCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($enrollmentMonths); ?>,
            datasets: [{
                label: 'Enrollments',
                data: <?php echo json_encode($enrollmentCounts); ?>,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#2563eb',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 6,
                pointHoverRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false } }
            }
        }
    });

    // Completion Chart
    const completionCtx = document.getElementById('completionChart').getContext('2d');
    new Chart(completionCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($completionLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($completionCounts); ?>,
                backgroundColor: ['#10b981', '#6b7280', '#3b82f6', '#9ca3af'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '60%',
            plugins: { legend: { display: false } }
        }
    });

    // Activity Chart
    const activityCtx = document.getElementById('activityChart').getContext('2d');
    new Chart(activityCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($activityDates); ?>,
            datasets: [{
                label: 'New Users',
                data: <?php echo json_encode($activityCounts); ?>,
                backgroundColor: 'rgba(37, 99, 235, 0.8)',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false } }
            }
        }
    });
</script>

<?php include 'includes/footer.php'; ?>