<?php
session_start();
require_once 'config/database.php';
require_once 'includes/enrollment_requests.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header("Location: auth/login.php");
    exit;
}

$instructor_id = (int)$_SESSION['user_id'];
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';
ensureEnrollmentRequestsTable($pdo);

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_subject'])) {
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    if ($subject_id > 0) {
        try {
            $verify = $pdo->prepare('SELECT id FROM subjects WHERE id = ? AND instructor_id = ?');
            $verify->execute([$subject_id, $instructor_id]);
            if ($verify->fetch()) {
                $pdo->prepare('DELETE FROM enrollment_requests WHERE subject_id = ?')->execute([$subject_id]);
                $pdo->prepare('DELETE FROM lesson_attachments WHERE lesson_id IN (SELECT id FROM lessons WHERE subject_id = ?)')->execute([$subject_id]);
                $pdo->prepare('DELETE FROM lessons WHERE subject_id = ?')->execute([$subject_id]);
                $pdo->prepare('DELETE FROM submissions WHERE assignment_id IN (SELECT id FROM assignments WHERE subject_id = ?)')->execute([$subject_id]);
                $pdo->prepare('DELETE FROM assignments WHERE subject_id = ?')->execute([$subject_id]);
                $pdo->prepare('DELETE FROM quiz_results WHERE quiz_id IN (SELECT id FROM quizzes WHERE subject_id = ?)')->execute([$subject_id]);
                $pdo->prepare('DELETE FROM quizzes WHERE subject_id = ?')->execute([$subject_id]);
                $pdo->prepare('DELETE FROM class_schedules WHERE subject_id = ?')->execute([$subject_id]);
                $pdo->prepare('DELETE FROM enrollments WHERE subject_id = ?')->execute([$subject_id]);
                $pdo->prepare('DELETE FROM subjects WHERE id = ?')->execute([$subject_id]);
                $success_msg = 'Class and its associated content were deleted successfully.';
            } else {
                $error_msg = 'Class not found or you are not authorized to delete it.';
            }
        } catch (Exception $e) {
            $error_msg = 'Error deleting class: ' . $e->getMessage();
        }
    } else {
        $error_msg = 'Invalid class selected for deletion.';
    }
}

// ============================================================
// Search and Filter Parameters
// ============================================================
$searchTerm = trim($_GET['search'] ?? '');
$filterDepartment = $_GET['department'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterSemester = $_GET['semester'] ?? '';
$filterYear = $_GET['year'] ?? '';
$viewMode = $_GET['view'] ?? 'grid'; // grid or list

// ============================================================
// Statistics
// ============================================================
$stats = [];

$stats['total_classes'] = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE instructor_id = ?");
$stats['total_classes']->execute([$instructor_id]);
$stats['total_classes'] = $stats['total_classes']->fetchColumn();

$stats['total_students'] = $pdo->prepare("
    SELECT COUNT(DISTINCT e.student_id) 
    FROM enrollments e 
    JOIN subjects s ON e.subject_id = s.id 
    WHERE s.instructor_id = ?
");
$stats['total_students']->execute([$instructor_id]);
$stats['total_students'] = $stats['total_students']->fetchColumn();

$stats['active_classes'] = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE instructor_id = ? AND status = 'active'");
$stats['active_classes']->execute([$instructor_id]);
$stats['active_classes'] = $stats['active_classes']->fetchColumn();

// ============================================================
// Get Departments for Filter
// ============================================================
$departments = $pdo->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name")->fetchAll();

// ============================================================
// Get Academic Years for Filter
// ============================================================
$academicYears = $pdo->query("SELECT id, name, start_date, end_date FROM academic_years ORDER BY start_date DESC")->fetchAll();
$semesterOptions = $pdo->query("SELECT sem.semester_id, sem.semester_name, ay.name AS academic_year_name FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id ORDER BY ay.start_date DESC, sem.start_date DESC")->fetchAll();

// ============================================================
// Build Query for Classes
// ============================================================
$whereClauses = ["s.instructor_id = ?"];
$params = [$instructor_id];

if (!empty($searchTerm)) {
    $whereClauses[] = "(s.title LIKE ? OR s.class_code LIKE ? OR s.description LIKE ? OR d.name LIKE ?)";
    $wildcard = "%{$searchTerm}%";
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
}

if (!empty($filterDepartment)) {
    $whereClauses[] = "s.department_id = ?";
    $params[] = $filterDepartment;
}

if (!empty($filterStatus)) {
    $whereClauses[] = "s.status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterSemester)) {
    $whereClauses[] = 's.semester_id = ?';
    $params[] = (int)$filterSemester;
}

if (!empty($filterYear)) {
    $whereClauses[] = "COALESCE(s.academic_year_id, sem_year.id, ay.id) = ?";
    $params[] = $filterYear;
}

$whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Main query
$classesQuery = "
    SELECT 
        s.id, s.title, s.class_code, s.description, s.status, s.created_at,
        d.name as department_name,
        p.name as program_name,
        sec.name as section_name,
        COALESCE(s.semester, sem.semester_name) AS semester_name,
        COALESCE(class_year.name, sem_year.name, ay.name) as semester_year_name,
        u.name as instructor_name,
        COUNT(DISTINCT e.student_id) as enrolled_students,
        (SELECT COUNT(*) FROM enrollment_requests er WHERE er.subject_id = s.id AND er.status = 'pending') as pending_enrollment_requests,
        (SELECT COUNT(*) FROM assignments WHERE subject_id = s.id AND due_date >= NOW()) as active_assignments,
        (SELECT COUNT(*) FROM submissions sub 
         JOIN assignments a ON sub.assignment_id = a.id 
         WHERE a.subject_id = s.id AND sub.grade IS NULL) as pending_submissions,
        (SELECT cs.meeting_link FROM class_schedules cs WHERE cs.subject_id = s.id ORDER BY cs.id DESC LIMIT 1) as meeting_link
    FROM subjects s
    LEFT JOIN departments d ON d.id = s.department_id
    LEFT JOIN programs p ON p.id = s.program_id
    LEFT JOIN sections sec ON sec.id = s.section_id
    LEFT JOIN academic_years ay ON ay.id = sec.academic_year_id
    LEFT JOIN semesters sem ON sem.semester_id = s.semester_id
    LEFT JOIN academic_years sem_year ON sem_year.id = sem.academic_year_id
    LEFT JOIN academic_years class_year ON class_year.id = s.academic_year_id
    LEFT JOIN users u ON u.id = s.instructor_id
    LEFT JOIN enrollments e ON e.subject_id = s.id
    {$whereSQL}
    GROUP BY s.id, s.title, s.class_code, s.description, s.status, s.created_at,
             d.name, p.name, sec.name, sem.semester_name, sem_year.name, class_year.name, ay.name, u.name
    ORDER BY s.created_at DESC
";

$stmt = $pdo->prepare($classesQuery);
$stmt->execute($params);
$classes = $stmt->fetchAll();

// ============================================================
// Upcoming Sessions (for calendar view)
// ============================================================
$upcomingSessions = $pdo->prepare("
    SELECT s.title, s.class_code, a.title as assignment_title, a.due_date
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE s.instructor_id = ? AND a.due_date >= CURDATE()
    ORDER BY a.due_date ASC
    LIMIT 10
");
$upcomingSessions->execute([$instructor_id]);
$upcomingSessions = $upcomingSessions->fetchAll();

// ============================================================
// Upcoming Assignment Count
// ============================================================
$stats['upcoming_classes'] = $pdo->prepare(
    "SELECT COUNT(DISTINCT a.id) FROM assignments a 
     JOIN subjects s ON a.subject_id = s.id 
     WHERE s.instructor_id = ? AND a.due_date >= CURDATE()"
);
$stats['upcoming_classes']->execute([$instructor_id]);
$stats['upcoming_classes'] = $stats['upcoming_classes']->fetchColumn();

// ============================================================
// Recent Attendance Data
// ============================================================
$recentAttendance = $pdo->prepare("
    SELECT 
        s.title, s.class_code,
        COUNT(CASE WHEN att.status = 'present' THEN 1 END) as present,
        COUNT(CASE WHEN att.status = 'absent' THEN 1 END) as absent,
        COUNT(CASE WHEN att.status = 'late' THEN 1 END) as late
    FROM subjects s
    LEFT JOIN attendance att ON att.subject_id = s.id
    WHERE s.instructor_id = ? AND att.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY s.id, s.title, s.class_code
");
$recentAttendance->execute([$instructor_id]);
$attendanceData = $recentAttendance->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    :root {
        --primary-blue: #1e3a8a;
        --secondary-blue: #2563eb;
        --light-blue: #3b82f6;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
    }

    body { background: #f1f5f9; }

    /* Welcome Header */
    .page-header {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
        border-radius: 16px;
        padding: 1.5rem 2rem;
        color: white;
        margin-bottom: 1.5rem;
    }

    /* Stat Cards */
    .stat-card {
        border-radius: 14px;
        border: none;
        overflow: hidden;
        transition: all 0.3s ease;
        height: 100%;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.12) !important;
    }

    .stat-card .card-body { padding: 1.25rem; }

    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--primary-blue);
    }

    .stat-label {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
    }

    /* Search & Filter */
    .filter-section {
        background: white;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        margin-bottom: 1.5rem;
    }

    .search-input {
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem 1rem;
    }

    .search-input:focus {
        border-color: var(--secondary-blue);
        box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        outline: none;
    }

    /* Class Cards */
    .class-card {
        background: white;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        height: 100%;
    }

    .class-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.12);
    }

    .class-card-header {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
        padding: 1rem 1.25rem;
        color: white;
    }

    .class-code {
        font-size: 0.75rem;
        opacity: 0.9;
        background: rgba(255,255,255,0.2);
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
    }

    .class-card-body { padding: 1.25rem; }

    .class-title {
        font-weight: 600;
        font-size: 1rem;
        color: var(--primary-blue);
        margin-bottom: 0.5rem;
    }

    .class-info {
        font-size: 0.8rem;
        color: #64748b;
        margin-bottom: 0.25rem;
    }

    .class-info i {
        width: 16px;
        color: var(--secondary-blue);
    }

    .class-stats {
        display: flex;
        gap: 1rem;
        padding: 0.75rem 1.25rem;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
    }

    .class-stat-item {
        text-align: center;
        flex: 1;
    }

    .class-stat-value {
        font-weight: 700;
        font-size: 1.1rem;
        color: var(--primary-blue);
    }

    .class-stat-label {
        font-size: 0.65rem;
        text-transform: uppercase;
        color: #64748b;
    }

    .class-card-actions {
        padding: 0.75rem 1.25rem;
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.6rem;
        border-radius: 6px;
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-active { background: #dcfce7; color: #166534; }
    .status-draft { background: #f1f5f9; color: #475569; }
    .status-completed { background: #dbeafe; color: #1e40af; }
    .status-archived { background: #e2e8f0; color: #475569; }

    /* Quick Action Buttons */
    .action-btn-sm {
        padding: 0.4rem 0.7rem;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 500;
        transition: all 0.2s ease;
        border: none;
        cursor: pointer;
        text-decoration: none;
        min-width: 120px;
    }

    .action-btn-sm:hover {
        transform: scale(1.05);
    }

    .page-header {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
        border-radius: 16px;
        padding: 1.5rem 2rem;
        color: white;
        margin-bottom: 1.5rem;
    }

    .page-header .header-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .filter-section {
        background: white;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        margin-bottom: 1.5rem;
    }

    .filter-section .view-toggle {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
    }

    .class-card {
        background: white;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        height: 100%;
        min-height: 320px;
        display: flex;
        flex-direction: column;
    }

    .class-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.12);
    }

    .class-card-body { padding: 1.25rem; flex: 1 1 auto; }

    .class-stats {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        padding: 0.75rem 1.25rem;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
    }

    .class-stat-item {
        text-align: center;
        flex: 1 1 30%;
        min-width: 120px;
    }

    .class-card-actions {
        padding: 0.75rem 1.25rem;
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .data-table-container {
        overflow-x: auto;
    }

    @media (max-width: 992px) {
        .page-header { padding: 1.25rem 1rem; }
        .filter-section { padding: 1.25rem 1rem; }
    }

    @media (max-width: 768px) {
        .page-header { padding: 1rem; }
        .page-header .header-actions { justify-content: flex-start; width: 100%; }
        .page-header .header-actions a,
        .page-header .header-actions button { width: calc(50% - 0.5rem); }
        .class-card { min-height: auto; }
        .class-stats { flex-direction: column; align-items: stretch; }
        .class-stat-item { min-width: 100%; }
        .class-card-actions { justify-content: center; }
    }

    .btn-view { background: #dbeafe; color: #2563eb; }
    .btn-students { background: #dcfce7; color: #059669; }
    .btn-assignments { background: #fef3c7; color: #d97706; }
    .btn-materials { background: #e0e7ff; color: #4f46e5; }
    .btn-announce { background: #fce7f3; color: #db2777; }

    /* Calendar/Schedule View */
    .schedule-card {
        background: white;
        border-radius: 14px;
        padding: 1.25rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }

    .schedule-item {
        display: flex;
        align-items: center;
        padding: 0.75rem;
        border-left: 4px solid var(--secondary-blue);
        background: #f8fafc;
        border-radius: 0 8px 8px 0;
        margin-bottom: 0.75rem;
    }

    .schedule-date {
        min-width: 60px;
        text-align: center;
        padding: 0.5rem;
        background: var(--secondary-blue);
        color: white;
        border-radius: 8px;
        margin-right: 1rem;
    }

    .schedule-date .day { font-size: 1.25rem; font-weight: 700; }
    .schedule-date .month { font-size: 0.7rem; text-transform: uppercase; }

    /* View Toggle */
    .view-toggle {
        display: flex;
        border-radius: 10px;
        overflow: hidden;
        border: 2px solid #e2e8f0;
    }

    .view-toggle-btn {
        padding: 0.5rem 1rem;
        border: none;
        background: white;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .view-toggle-btn.active {
        background: var(--secondary-blue);
        color: white;
    }

    @media (max-width: 768px) {
        .stat-value { font-size: 1.2rem; }
        .stat-icon { width: 40px; height: 40px; font-size: 1rem; }
    }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="background: #f1f5f9; min-height: 100vh;">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
            <div>
                <h1 class="h3 fw-bold mb-1">
                    <i class="fas fa-chalkboard-teacher me-2"></i>My Classes
                </h1>
                <p class="mb-0" style="opacity: 0.9;">Manage and monitor all your assigned courses</p>
            </div>
            <div class="header-actions">
                
                <a href="my_classes_create.php" class="btn btn-light" style="background: #fff; border: 1px solid rgba(0,0,0,0.06);">
                    <i class="fas fa-plus me-1"></i> Create Class
                </a>
                <button class="btn btn-outline-light" onclick="location.reload()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
            </div>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success mx-3 mx-md-0" role="alert">
            <?php echo htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger mx-3 mx-md-0" role="alert">
            <?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>
    <!-- Search & Filter Section -->
    <div class="filter-section">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0" style="color: var(--primary-blue);">
                <i class="fas fa-search me-2"></i>Search & Filter Classes
            </h5>
            <div class="view-toggle">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'grid'])); ?>" 
                   class="view-toggle-btn <?php echo $viewMode === 'grid' ? 'active' : ''; ?>">
                    <i class="fas fa-th"></i>
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'list'])); ?>" 
                   class="view-toggle-btn <?php echo $viewMode === 'list' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i>
                </a>
            </div>
        </div>
        <form method="GET" class="row g-3">
            <div class="col-lg-4 col-md-6">
                <label class="form-label small text-muted">Search</label>
                <input type="text" name="search" class="form-control search-input" 
                       placeholder="Course code, title, department..." 
                       value="<?php echo htmlspecialchars($searchTerm); ?>">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Department</label>
                <select name="department" class="form-select search-input">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>" <?php echo $filterDepartment == $dept['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Status</label>
                <select name="status" class="form-select search-input">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="archived" <?php echo $filterStatus === 'archived' ? 'selected' : ''; ?>>Archived</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Academic Year</label>
                <select name="year" class="form-select search-input">
                    <option value="">All Years</option>
                    <?php foreach ($academicYears as $year): ?>
                        <option value="<?php echo $year['id']; ?>" <?php echo $filterYear == $year['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($year['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Semester</label>
                <select name="semester" class="form-select search-input">
                    <option value="">All Semesters</option>
                    <?php foreach ($semesterOptions as $semesterOption): ?>
                        <option value="<?php echo (int)$semesterOption['semester_id']; ?>" <?php echo (string)$filterSemester === (string)$semesterOption['semester_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($semesterOption['semester_name'] . ' · ' . $semesterOption['academic_year_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4 d-flex align-items-end">
                <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--light-blue) 100%); color: white; font-weight: 600; width: 100%;">
                    <i class="fas fa-search me-1"></i> Search
                </button>
            </div>
            <div class="col-12">
                <a href="my_classes.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times me-1"></i> Clear Filters
                </a>
                <span class="text-muted ms-2">Showing <?php echo count($classes); ?> class(es)</span>
            </div>
        </form>
    </div>

    <!-- Main Content -->
    <div class="row g-4">
        <!-- Classes Grid/List -->
        <div class="col-lg-8">
            <?php if ($viewMode === 'grid'): ?>
                <div class="row g-4">
                    <?php foreach ($classes as $class): ?>
                        <div class="col-md-6">
                            <div class="class-card">
                                <div class="class-card-header d-flex justify-content-between align-items-center">
                                    <span class="class-code"><?php echo htmlspecialchars($class['class_code'] ?? 'N/A'); ?></span>
                                    <span class="status-badge status-<?php echo $class['status']; ?>">
                                        <?php echo ucfirst($class['status']); ?>
                                    </span>
                                </div>
                                <div class="class-card-body">
                                    <div class="class-title"><?php echo htmlspecialchars($class['title']); ?></div>
                                    <?php if ($class['department_name']): ?>
                                        <div class="class-info">
                                            <i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($class['department_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($class['program_name']): ?>
                                        <div class="class-info">
                                            <i class="fas fa-graduation-cap me-1"></i> <?php echo htmlspecialchars($class['program_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($class['section_name']): ?>
                                        <div class="class-info">
                                            <i class="fas fa-users me-1"></i> Section: <?php echo htmlspecialchars($class['section_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($class['semester_name'] || $class['semester_year_name']): ?>
                                        <div class="class-info">
                                            <i class="fas fa-calendar-days me-1"></i> <?php echo htmlspecialchars(($class['semester_name'] ?: 'No semester') . ($class['semester_year_name'] ? ' · ' . $class['semester_year_name'] : '')); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="class-stats">
                                    <div class="class-stat-item">
                                        <div class="class-stat-value"><?php echo $class['enrolled_students']; ?></div>
                                        <div class="class-stat-label">Students</div>
                                    </div>
                                    <div class="class-stat-item">
                                        <div class="class-stat-value"><?php echo $class['active_assignments']; ?></div>
                                        <div class="class-stat-label">Active Assign.</div>
                                    </div>
                                    <div class="class-stat-item">
                                        <div class="class-stat-value"><?php echo $class['pending_submissions']; ?></div>
                                        <div class="class-stat-label">Pending</div>
                                    </div>
                                </div>
                                <div class="class-card-actions">
                                    <a href="subjects/view.php?id=<?php echo $class['id']; ?>" class="action-btn-sm btn-view">
                                        <i class="fas fa-eye me-1"></i> View
                                    </a>
                                    <a href="instructor/analytics.php?subject_id=<?php echo (int)$class['id']; ?>" class="action-btn-sm btn-primary">
                                        <i class="fas fa-chart-line me-1"></i> Monitor
                                    </a>
                                    <?php if (!empty($class['meeting_link'])): ?>
                                        <a href="virtual_class.php?id=<?php echo $class['id']; ?>" class="action-btn-sm btn-primary">
                                            <i class="fas fa-video me-1"></i> Live Class
                                        </a>
                                    <?php endif; ?>
                                    <a href="my_class_details.php?id=<?php echo $class['id']; ?>" class="action-btn-sm btn-students">
                                        <i class="fas fa-users me-1"></i> Students
                                        <?php if ((int)$class['pending_enrollment_requests'] > 0): ?>
                                            <span class="badge bg-warning text-dark ms-1"><?php echo (int)$class['pending_enrollment_requests']; ?> pending</span>
                                        <?php endif; ?>
                                    </a>
                                    <form method="POST" onsubmit="return confirm('Delete this class and all its related content?');" style="display:inline;">
                                        <input type="hidden" name="subject_id" value="<?php echo $class['id']; ?>">
                                        <button type="submit" name="delete_subject" class="action-btn-sm" style="background: #ef4444; color: white;">
                                            <i class="fas fa-trash-alt me-1"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($classes)): ?>
                        <div class="col-12">
                            <div class="text-center py-5">
                                <i class="fas fa-chalkboard" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                                <p class="text-muted">No classes found matching your criteria.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- List View -->
                <div class="data-table-container">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead style="background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%); color: white;">
                                <tr>
                                    <th>Course</th>
                                    <th>Department</th>
                                    <th>Section</th>
                                    <th>Semester</th>
                                    <th>Students</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($class['title']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($class['class_code'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($class['department_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($class['section_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars(($class['semester_name'] ?: 'No semester') . ($class['semester_year_name'] ? ' · ' . $class['semester_year_name'] : '')); ?></td>
                                        <td><span class="badge bg-primary"><?php echo $class['enrolled_students']; ?></span></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $class['status']; ?>">
                                                <?php echo ucfirst($class['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="subjects/view.php?id=<?php echo $class['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="instructor/analytics.php?subject_id=<?php echo (int)$class['id']; ?>" class="btn btn-sm btn-outline-success ms-1" title="Monitor class performance">
                                                <i class="fas fa-chart-line"></i> Monitor
                                            </a>
                                            <?php if ((int)$class['pending_enrollment_requests'] > 0): ?>
                                                <a href="my_class_details.php?id=<?php echo $class['id']; ?>" class="btn btn-sm btn-warning ms-1" title="Review enrollment requests">
                                                    <i class="fas fa-user-clock"></i> <?php echo (int)$class['pending_enrollment_requests']; ?>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($class['meeting_link'])): ?>
                                                <a href="virtual_class.php?id=<?php echo $class['id']; ?>" class="btn btn-sm btn-outline-success ms-1">
                                                    <i class="fas fa-video"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="assignments/index.php?subject_id=<?php echo $class['id']; ?>" class="btn btn-sm btn-outline-warning ms-1">
                                                <i class="fas fa-tasks"></i>
                                            </a>
                                            <form method="POST" onsubmit="return confirm('Delete this class and all its related content?');" style="display:inline; margin:0;">
                                                <input type="hidden" name="subject_id" value="<?php echo $class['id']; ?>">
                                                <button type="submit" name="delete_subject" class="btn btn-sm btn-outline-danger ms-1">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar: Schedule & Attendance -->
        <div class="col-lg-4">
            <!-- Upcoming Schedule -->
            <div class="schedule-card mb-4">
                <h5 class="fw-bold mb-3" style="color: var(--primary-blue);">
                    <i class="fas fa-calendar-alt me-2"></i>Upcoming Schedule
                </h5>
                <?php if (!empty($upcomingSessions)): ?>
                    <?php foreach ($upcomingSessions as $session): ?>
                        <div class="schedule-item">
                            <div class="schedule-date">
                                <div class="day"><?php echo date('d', strtotime($session['due_date'])); ?></div>
                                <div class="month"><?php echo date('M', strtotime($session['due_date'])); ?></div>
                            </div>
                            <div>
                                <div class="fw-semibold" style="font-size: 0.85rem;"><?php echo htmlspecialchars($session['assignment_title']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($session['title']); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No upcoming sessions scheduled.</p>
                <?php endif; ?>
            </div>

            <!-- Recent Attendance -->
            <div class="schedule-card">
                <h5 class="fw-bold mb-3" style="color: var(--primary-blue);">
                    <i class="fas fa-chart-pie me-2"></i>Attendance (Last 7 Days)
                </h5>
                <?php if (!empty($attendanceData)): ?>
                    <canvas id="attendanceChart" height="200"></canvas>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No attendance data available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    <?php if (!empty($attendanceData)): ?>
    // Attendance Chart
    const attCtx = document.getElementById('attendanceChart').getContext('2d');
    new Chart(attCtx, {
        type: 'doughnut',
        data: {
            labels: ['Present', 'Absent', 'Late'],
            datasets: [{
                data: [
                    <?php echo array_sum(array_column($attendanceData, 'present')); ?>,
                    <?php echo array_sum(array_column($attendanceData, 'absent')); ?>,
                    <?php echo array_sum(array_column($attendanceData, 'late')); ?>
                ],
                backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 8 } }
            }
        }
    });
    <?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>