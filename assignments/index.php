<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header("Location: ../auth/login.php");
    exit;
}

$instructor_id = (int)$_SESSION['user_id'];
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';

// ============================================================
// Search and Filter Parameters
// ============================================================
$searchTerm = trim($_GET['search'] ?? '');
$filterCourse = $_GET['course'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$sortBy = $_GET['sort'] ?? 'due_date';
$sortOrder = $_GET['order'] ?? 'ASC';
$viewMode = $_GET['view'] ?? 'table';

// ============================================================
// Handle POST Actions
// ============================================================
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create Assignment
    if (isset($_POST['create_assignment'])) {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $due_date = $_POST['due_date'] ?? null;
        $points = (int)($_POST['points'] ?? 100);
        
        if (!empty($title) && $subject_id > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO assignments (subject_id, title, description, due_date, points, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$subject_id, $title, $description, $due_date, $points]);
                $success_msg = "Assignment created successfully!";
            } catch (Exception $e) {
                $error_msg = "Error creating assignment: " . $e->getMessage();
            }
        } else {
            $error_msg = "Please provide assignment title and course.";
        }
    }

    // Update Assignment Status
    if (isset($_POST['update_assignment_status'])) {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        if ($assignment_id > 0) {
            $stmt = $pdo->prepare("UPDATE assignments SET status = ? WHERE id = ?");
            $stmt->execute([$status, $assignment_id]);
            $success_msg = "Assignment status updated successfully.";
        }
    }
}

// ============================================================
// Get Instructor's Courses
// ============================================================
$myCourses = $pdo->prepare("SELECT id, title, class_code FROM subjects WHERE instructor_id = ? ORDER BY title");
$myCourses->execute([$instructor_id]);
$myCourses = $myCourses->fetchAll();

// ============================================================
// Statistics
// ============================================================
$stats = [];

// Total assignments
$stats['total'] = $pdo->prepare("
    SELECT COUNT(*) FROM assignments a 
    JOIN subjects s ON a.subject_id = s.id 
    WHERE s.instructor_id = ?
");
$stats['total']->execute([$instructor_id]);
$stats['total'] = $stats['total']->fetchColumn();

// Total submissions
$stats['submissions'] = $pdo->prepare("
    SELECT COUNT(*) FROM submissions sub 
    JOIN assignments a ON sub.assignment_id = a.id 
    JOIN subjects s ON a.subject_id = s.id 
    WHERE s.instructor_id = ?
");
$stats['submissions']->execute([$instructor_id]);
$stats['submissions'] = $stats['submissions']->fetchColumn();

// Pending submissions
$stats['pending'] = $pdo->prepare("
    SELECT COUNT(*) FROM submissions sub 
    JOIN assignments a ON sub.assignment_id = a.id 
    JOIN subjects s ON a.subject_id = s.id 
    WHERE s.instructor_id = ? AND sub.grade IS NULL
");
$stats['pending']->execute([$instructor_id]);
$stats['pending'] = $stats['pending']->fetchColumn();

// Graded submissions
$stats['graded'] = $pdo->prepare("
    SELECT COUNT(*) FROM submissions sub 
    JOIN assignments a ON sub.assignment_id = a.id 
    JOIN subjects s ON a.subject_id = s.id 
    WHERE s.instructor_id = ? AND sub.grade IS NOT NULL
");
$stats['graded']->execute([$instructor_id]);
$stats['graded'] = $stats['graded']->fetchColumn();

// ============================================================
// Build Assignment Query
// ============================================================
$whereClauses = ["s.instructor_id = ?"];
$params = [$instructor_id];

if (!empty($searchTerm)) {
    $whereClauses[] = "(a.title LIKE ? OR s.title LIKE ?)";
    $wildcard = "%{$searchTerm}%";
    $params[] = $wildcard;
    $params[] = $wildcard;
}

if (!empty($filterCourse)) {
    $whereClauses[] = "a.subject_id = ?";
    $params[] = $filterCourse;
}

if (!empty($filterStatus)) {
    $whereClauses[] = "a.status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterDateFrom)) {
    $whereClauses[] = "DATE(a.due_date) >= ?";
    $params[] = $filterDateFrom;
}

if (!empty($filterDateTo)) {
    $whereClauses[] = "DATE(a.due_date) <= ?";
    $params[] = $filterDateTo;
}

$whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Valid sort columns
$validSortColumns = ['title', 'due_date', 'points'];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'due_date';
}
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

$assignmentsQuery = "
    SELECT 
        a.id, a.title, a.description, a.due_date, a.points, a.status, a.created_at,
        s.id as subject_id, s.title as subject_title, s.class_code,
        COUNT(DISTINCT sub.id) as total_submissions,
        COUNT(DISTINCT CASE WHEN sub.grade IS NOT NULL THEN sub.id END) as graded_count,
        COUNT(DISTINCT CASE WHEN sub.grade IS NULL AND sub.submitted_at IS NOT NULL THEN sub.id END) as pending_count,
        COUNT(DISTINCT CASE WHEN sub.id IS NULL AND e.student_id IS NOT NULL THEN e.student_id END) as not_submitted_count
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    LEFT JOIN submissions sub ON sub.assignment_id = a.id
    LEFT JOIN enrollments e ON e.subject_id = s.id
    {$whereSQL}
    GROUP BY a.id, a.title, a.description, a.due_date, a.points, a.status, a.created_at,
             s.id, s.title, s.class_code
    ORDER BY a.{$sortBy} {$sortOrder}
";

$stmt = $pdo->prepare($assignmentsQuery);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// ============================================================
// Recent Submissions (for notifications)
// ============================================================
$recentSubmissions = $pdo->prepare("
    SELECT sub.id, sub.submitted_at, sub.content,
           u.name as student_name,
           a.title as assignment_title,
           s.title as subject_title
    FROM submissions sub
    JOIN assignments a ON sub.assignment_id = a.id
    JOIN subjects s ON a.subject_id = s.id
    JOIN users u ON u.id = sub.student_id
    WHERE s.instructor_id = ? AND sub.grade IS NULL
    ORDER BY sub.submitted_at DESC
    LIMIT 5
");
$recentSubmissions->execute([$instructor_id]);
$recentSubmissions = $recentSubmissions->fetchAll();

// ============================================================
// Completion Rate Data (for chart)
// ============================================================
$completionData = $pdo->prepare("
    SELECT 
        a.title,
        COUNT(DISTINCT e.student_id) as total_students,
        COUNT(DISTINCT sub.id) as submissions,
        ROUND((COUNT(DISTINCT sub.id) / COUNT(DISTINCT e.student_id)) * 100, 1) as completion_rate
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    LEFT JOIN enrollments e ON e.subject_id = s.id
    LEFT JOIN submissions sub ON sub.assignment_id = a.id
    WHERE s.instructor_id = ? AND a.due_date >= CURDATE() - INTERVAL 30 DAY
    GROUP BY a.id, a.title
    ORDER BY a.due_date DESC
    LIMIT 8
");
$completionData->execute([$instructor_id]);
$completionData = $completionData->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
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

    /* Page Header */
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

    /* Filter Section */
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

    /* Table */
    .data-table-container {
        background: white;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }

    .data-table { margin-bottom: 0; }

    .data-table thead {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
        color: white;
    }

    .data-table th {
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 1rem;
        border: none;
        cursor: pointer;
    }

    .data-table td {
        padding: 0.875rem 1rem;
        vertical-align: middle;
        font-size: 0.875rem;
        border-bottom: 1px solid #f1f5f9;
    }

    .data-table tbody tr:hover { background: #f8fafc; }
    .data-table tbody tr:last-child td { border-bottom: none; }

    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-active { background: #dcfce7; color: #166534; }
    .status-draft { background: #f1f5f9; color: #475569; }
    .status-completed { background: #dbeafe; color: #1e40af; }
    .status-archived { background: #e2e8f0; color: #475569; }

    /* Submission Progress Bar */
    .progress-bar-custom {
        height: 8px;
        border-radius: 4px;
        background: #e2e8f0;
        overflow: hidden;
    }

    .progress-bar-fill {
        height: 100%;
        border-radius: 4px;
        transition: width 0.3s ease;
    }

    .progress-graded { background: #10b981; }
    .progress-pending { background: #f59e0b; }
    .progress-not-submitted { background: #ef4444; }

    /* Action Buttons */
    .action-btn-sm {
        padding: 0.4rem 0.7rem;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 500;
        transition: all 0.2s ease;
        border: none;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }

    .action-btn-sm:hover { transform: scale(1.05); }

    .btn-view { background: #dbeafe; color: #2563eb; }
    .btn-edit { background: #fef3c7; color: #d97706; }
    .btn-grade { background: #dcfce7; color: #059669; }
    .btn-archive { background: #f1f5f9; color: #64748b; }

    /* Modal */
    .modal-content {
        border-radius: 14px;
        border: none;
    }

    .modal-header {
        border-radius: 14px 14px 0 0;
    }

    /* Chart */
    .chart-container {
        background: white;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }

    .chart-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--primary-blue);
        margin-bottom: 1rem;
    }

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

    /* Submissions List */
    .submission-item {
        padding: 1rem;
        border-left: 4px solid var(--secondary-blue);
        background: #f8fafc;
        border-radius: 0 8px 8px 0;
        margin-bottom: 0.75rem;
    }

    @media (max-width: 768px) {
        .stat-value { font-size: 1.2rem; }
        .stat-icon { width: 40px; height: 40px; font-size: 1rem; }
    }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="background: #f1f5f9; min-height: 100vh;">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 fw-bold mb-1">
                    <i class="fas fa-tasks me-2"></i>Assignment Management
                </h1>
                <p class="mb-0" style="opacity: 0.9;">Create, manage, and grade assignments across your classes</p>
            </div>
            <div>
                <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#createAssignmentModal">
                    <i class="fas fa-plus me-1"></i> New Assignment
                </button>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #dbeafe; color: #2563eb;">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                        <div class="stat-label">Total Assignments</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #dcfce7; color: #059669;">
                        <i class="fas fa-file-upload"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo number_format($stats['submissions']); ?></div>
                        <div class="stat-label">Total Submissions</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #fef3c7; color: #d97706;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #dcfce7; color: #059669;">
                        <i class="fas fa-check-double"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo number_format($stats['graded']); ?></div>
                        <div class="stat-label">Graded</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & Filter Section -->
    <div class="filter-section">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0" style="color: var(--primary-blue);">
                <i class="fas fa-search me-2"></i>Search & Filter Assignments
            </h5>
            <div class="view-toggle">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'table'])); ?>" 
                   class="view-toggle-btn <?php echo $viewMode === 'table' ? 'active' : ''; ?>">
                    <i class="fas fa-table"></i>
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'cards'])); ?>" 
                   class="view-toggle-btn <?php echo $viewMode === 'cards' ? 'active' : ''; ?>">
                    <i class="fas fa-th"></i>
                </a>
            </div>
        </div>
        <form method="GET" class="row g-3">
            <div class="col-lg-3 col-md-6">
                <label class="form-label small text-muted">Search</label>
                <input type="text" name="search" class="form-control search-input" 
                       placeholder="Assignment title, course..." 
                       value="<?php echo htmlspecialchars($searchTerm); ?>">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Course</label>
                <select name="course" class="form-select search-input">
                    <option value="">All Courses</option>
                    <?php foreach ($myCourses as $course): ?>
                        <option value="<?php echo $course['id']; ?>" <?php echo $filterCourse == $course['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($course['class_code'] . ' - ' . $course['title']); ?>
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
                <label class="form-label small text-muted">Due From</label>
                <input type="date" name="date_from" class="form-control search-input" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Due To</label>
                <input type="date" name="date_to" class="form-control search-input" value="<?php echo htmlspecialchars($filterDateTo); ?>">
            </div>
            <div class="col-lg-1 col-md-4 d-flex align-items-end">
                <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--light-blue) 100%); color: white; font-weight: 600; width: 100%;">
                    <i class="fas fa-search"></i>
                </button>
            </div>
            <div class="col-12">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times me-1"></i> Clear Filters
                </a>
                <span class="text-muted ms-2">Showing <?php echo count($assignments); ?> assignment(s)</span>
            </div>
        </form>
    </div>

    <!-- Main Content -->
    <div class="row g-4">
        <!-- Assignments Table/Cards -->
        <div class="col-lg-8">
            <?php if ($viewMode === 'table'): ?>
                <div class="data-table-container">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th data-sort="title">Assignment <i class="fas fa-sort"></i></th>
                                    <th>Course</th>
                                    <th data-sort="due_date">Due Date <i class="fas fa-sort"></i></th>
                                    <th data-sort="points">Points <i class="fas fa-sort"></i></th>
                                    <th>Submissions</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($assignment['title']); ?></div>
                                            <?php if ($assignment['description']): ?>
                                                <small class="text-muted" style="font-size: 0.75rem;"><?php echo htmlspecialchars(substr($assignment['description'], 0, 50)); ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($assignment['subject_title']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($assignment['class_code']); ?></small>
                                        </td>
                                        <td>
                                            <div class="<?php echo strtotime($assignment['due_date']) < time() ? 'text-danger' : 'text-muted'; ?>">
                                                <?php echo $assignment['due_date'] ? date('M d, Y', strtotime($assignment['due_date'])) : 'No due date'; ?>
                                            </div>
                                        </td>
                                        <td><span class="badge bg-info"><?php echo $assignment['points']; ?> pts</span></td>
                                        <td style="min-width: 150px;">
                                            <div class="d-flex justify-content-between mb-1" style="font-size: 0.7rem;">
                                                <span class="text-success"><?php echo $assignment['graded_count']; ?> graded</span>
                                                <span class="text-warning"><?php echo $assignment['pending_count']; ?> pending</span>
                                            </div>
                                            <div class="progress-bar-custom d-flex">
                                                <?php 
                                                $total = max(1, $assignment['graded_count'] + $assignment['pending_count'] + $assignment['not_submitted_count']);
                                                $gradedPct = ($assignment['graded_count'] / $total) * 100;
                                                $pendingPct = ($assignment['pending_count'] / $total) * 100;
                                                $notSubmittedPct = ($assignment['not_submitted_count'] / $total) * 100;
                                                ?>
                                                <div class="progress-bar-fill progress-graded" style="width: <?php echo $gradedPct; ?>%"></div>
                                                <div class="progress-bar-fill progress-pending" style="width: <?php echo $pendingPct; ?>%"></div>
                                                <div class="progress-bar-fill progress-not-submitted" style="width: <?php echo $notSubmittedPct; ?>%"></div>
                                            </div>
                                            <small class="text-muted" style="font-size: 0.65rem;">
                                                Total: <?php echo $assignment['total_submissions']; ?> / <?php echo $assignment['graded_count'] + $assignment['pending_count'] + $assignment['not_submitted_count']; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $assignment['status']; ?>">
                                                <?php echo ucfirst($assignment['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <a href="view.php?id=<?php echo $assignment['id']; ?>" class="action-btn-sm btn-view" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="view.php?id=<?php echo $assignment['id']; ?>&tab=submissions" class="action-btn-sm btn-grade" title="Grade">
                                                    <i class="fas fa-pen"></i>
                                                </a>
                                                <button class="action-btn-sm btn-archive" title="Change Status" onclick="changeStatus(<?php echo $assignment['id']; ?>, '<?php echo $assignment['status']; ?>')">
                                                    <i class="fas fa-archive"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($assignments)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-5">
                                            <i class="fas fa-clipboard-list" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                                            <p>No assignments found.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <!-- Cards View -->
                <div class="row g-4">
                    <?php foreach ($assignments as $assignment): ?>
                        <div class="col-md-6">
                            <div class="card shadow-sm border-0" style="border-radius: 14px;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="card-title fw-semibold"><?php echo htmlspecialchars($assignment['title']); ?></h5>
                                            <small class="text-muted"><?php echo htmlspecialchars($assignment['subject_title']); ?> (<?php echo htmlspecialchars($assignment['class_code']); ?>)</small>
                                        </div>
                                        <span class="status-badge status-<?php echo $assignment['status']; ?>">
                                            <?php echo ucfirst($assignment['status']); ?>
                                        </span>
                                    </div>
                                    <div class="row g-2 mb-3">
                                        <div class="col-6">
                                            <small class="text-muted d-block">Due Date</small>
                                            <strong><?php echo $assignment['due_date'] ? date('M d, Y', strtotime($assignment['due_date'])) : 'N/A'; ?></strong>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block">Points</small>
                                            <strong><?php echo $assignment['points']; ?> pts</strong>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between mb-1">
                                            <small>Progress</small>
                                            <small class="text-muted"><?php echo $assignment['total_submissions']; ?> submissions</small>
                                        </div>
                                        <div class="progress-bar-custom d-flex">
                                            <?php 
                                            $total = max(1, $assignment['graded_count'] + $assignment['pending_count'] + $assignment['not_submitted_count']);
                                            $gradedPct = ($assignment['graded_count'] / $total) * 100;
                                            $pendingPct = ($assignment['pending_count'] / $total) * 100;
                                            ?>
                                            <div class="progress-bar-fill progress-graded" style="width: <?php echo $gradedPct; ?>%"></div>
                                            <div class="progress-bar-fill progress-pending" style="width: <?php echo $pendingPct; ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a href="view.php?id=<?php echo $assignment['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                        <a href="view.php?id=<?php echo $assignment['id']; ?>&tab=submissions" class="btn btn-sm btn-outline-success">Grade</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Recent Submissions -->
            <div class="chart-container mb-4">
                <h5 class="chart-title">
                    <i class="fas fa-bell me-2"></i>Recent Submissions
                </h5>
                <?php if (!empty($recentSubmissions)): ?>
                    <?php foreach ($recentSubmissions as $sub): ?>
                        <div class="submission-item">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="fw-semibold" style="font-size: 0.85rem;"><?php echo htmlspecialchars($sub['student_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($sub['assignment_title']); ?></small>
                                </div>
                                <small class="text-muted"><?php echo date('M d, H:i', strtotime($sub['submitted_at'])); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No recent submissions.</p>
                <?php endif; ?>
            </div>

            <!-- Completion Rate Chart -->
            <div class="chart-container">
                <h5 class="chart-title">
                    <i class="fas fa-chart-bar me-2"></i>Completion Rates
                </h5>
                <?php if (!empty($completionData)): ?>
                    <canvas id="completionChart" height="200"></canvas>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No data available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Create Assignment Modal -->
<div class="modal fade" id="createAssignmentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-plus-circle me-2"></i>Create New Assignment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Assignment Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required placeholder="Enter assignment title">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course <span class="text-danger">*</span></label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">Select Course</option>
                                <?php foreach ($myCourses as $course): ?>
                                    <option value="<?php echo $course['id']; ?>"><?php echo htmlspecialchars($course['class_code'] . ' - ' . $course['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description & Instructions</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Enter assignment description and instructions..."></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="datetime-local" name="due_date" class="form-control">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Total Points</label>
                            <input type="number" name="points" class="form-control" value="100" min="1">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Allowed File Formats</label>
                            <input type="text" name="file_formats" class="form-control" placeholder="e.g., pdf, docx, txt">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Attachment (Optional)</label>
                        <input type="file" name="attachment" class="form-control">
                        <small class="text-muted">Upload reference materials, templates, or instructions.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_assignment" class="btn" style="background: var(--secondary-blue); color: white;">Create Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Change Status Modal -->
<div class="modal fade" id="changeStatusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-archive me-2"></i>Change Assignment Status</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="assignment_id" id="status_assignment_id">
                    <input type="hidden" name="status" id="status_new_status">
                    <p id="status_message" class="mb-0"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_assignment_status" class="btn" style="background: var(--warning); color: white;">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    <?php if (!empty($completionData)): ?>
    // Completion Rate Chart
    const compCtx = document.getElementById('completionChart').getContext('2d');
    new Chart(compCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($completionData, 'title')); ?>,
            datasets: [{
                label: 'Completion Rate (%)',
                data: <?php echo json_encode(array_column($completionData, 'completion_rate')); ?>,
                backgroundColor: '#2563eb',
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, max: 100, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false } }
            }
        }
    });
    <?php endif; ?>

    // Change Status
    function changeStatus(id, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'archived' : 'active';
        document.getElementById('status_assignment_id').value = id;
        document.getElementById('status_new_status').value = newStatus;
        document.getElementById('status_message').textContent = 
            'Are you sure you want to ' + (newStatus === 'active' ? 'activate' : 'archive') + ' this assignment?';
        new bootstrap.Modal(document.getElementById('changeStatusModal')).show();
    }
</script>

<?php include '../includes/footer.php'; ?>