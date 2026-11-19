<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS student_archived_subjects (
        student_id INT NOT NULL,
        subject_id INT NOT NULL,
        archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (student_id, subject_id),
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if (empty($_SESSION['student_subject_archive_csrf'])) {
    $_SESSION['student_subject_archive_csrf'] = bin2hex(random_bytes(32));
}

$archiveMessage = $_SESSION['student_subject_archive_message'] ?? '';
unset($_SESSION['student_subject_archive_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_class_archive'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $subjectId = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT);
    $archiveAction = $_POST['archive_action'] ?? '';

    if (!is_string($csrfToken) || !hash_equals($_SESSION['student_subject_archive_csrf'], $csrfToken)) {
        $_SESSION['student_subject_archive_message'] = 'Your session has expired. Refresh the page and try again.';
    } elseif (!$subjectId || !in_array($archiveAction, ['archive', 'restore'], true)) {
        $_SESSION['student_subject_archive_message'] = 'Invalid class archive request.';
    } else {
        $enrollmentStmt = $pdo->prepare('SELECT id FROM enrollments WHERE student_id = ? AND subject_id = ?');
        $enrollmentStmt->execute([$student_id, $subjectId]);

        if (!$enrollmentStmt->fetchColumn()) {
            $_SESSION['student_subject_archive_message'] = 'You are not enrolled in that class.';
        } elseif ($archiveAction === 'archive') {
            $archiveStmt = $pdo->prepare('INSERT IGNORE INTO student_archived_subjects (student_id, subject_id) VALUES (?, ?)');
            $archiveStmt->execute([$student_id, $subjectId]);
            $_SESSION['student_subject_archive_message'] = 'Class archived from your list.';
        } else {
            $restoreStmt = $pdo->prepare('DELETE FROM student_archived_subjects WHERE student_id = ? AND subject_id = ?');
            $restoreStmt->execute([$student_id, $subjectId]);
            $_SESSION['student_subject_archive_message'] = 'Class restored to your list.';
        }
    }

    $redirectView = $_POST['class_view'] ?? 'active';
    if (!in_array($redirectView, ['active', 'archived', 'all'], true)) {
        $redirectView = 'active';
    }
    header('Location: subjects.php?class_view=' . urlencode($redirectView));
    exit;
}

// Get Parameters
$searchTerm = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? '';
$viewMode = $_GET['view'] ?? 'grid';
$classView = $_GET['class_view'] ?? 'active';
if (!in_array($classView, ['active', 'archived', 'all'], true)) {
    $classView = 'active';
}

// ============================================================
// Get Enrolled Subjects
// ============================================================
$subjectsQuery = "
    SELECT 
        s.id, s.title, s.class_code, s.description, s.status,
        (archived.subject_id IS NOT NULL) AS is_archived,
        u.name as instructor_name, u.email as instructor_email,
        p.name as program_name,
        COUNT(DISTINCT a.id) as total_assignments,
        COUNT(DISTINCT CASE WHEN sub.grade IS NOT NULL THEN a.id END) as graded_assignments,
        COUNT(DISTINCT q.id) as total_quizzes,
        COUNT(DISTINCT CASE WHEN qr.score IS NOT NULL THEN q.id END) as graded_quizzes,
        COUNT(DISTINCT l.id) as total_lessons,
        COALESCE(AVG(
            CASE 
                WHEN sub.grade REGEXP '^[0-9]+(\.[0-9]+)?$' THEN CAST(sub.grade AS DECIMAL(10,2))
                WHEN sub.grade = 'A' THEN 95 WHEN sub.grade = 'A-' THEN 91 WHEN sub.grade = 'B+' THEN 88 WHEN sub.grade = 'B' THEN 85 
                WHEN sub.grade = 'B-' THEN 82 WHEN sub.grade = 'C+' THEN 79 WHEN sub.grade = 'C' THEN 76 WHEN sub.grade = 'C-' THEN 73 
                WHEN sub.grade = 'D+' THEN 70 WHEN sub.grade = 'D' THEN 67 WHEN sub.grade = 'D-' THEN 64 WHEN sub.grade = 'F' THEN 50 
                ELSE NULL 
            END
        ), 0) as current_grade,
        (SELECT COUNT(*) FROM attendance WHERE subject_id = s.id AND student_id = ? AND status = 'present') as present_days,
        (SELECT COUNT(*) FROM attendance WHERE subject_id = s.id AND student_id = ?) as total_attendance_days
    FROM enrollments e
    JOIN subjects s ON s.id = e.subject_id
    LEFT JOIN student_archived_subjects archived
        ON archived.student_id = e.student_id AND archived.subject_id = e.subject_id
    LEFT JOIN users u ON u.id = s.instructor_id
    LEFT JOIN programs p ON p.id = s.program_id
    LEFT JOIN assignments a ON a.subject_id = s.id
    LEFT JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = ?
    LEFT JOIN quizzes q ON q.subject_id = s.id
    LEFT JOIN quiz_results qr ON qr.quiz_id = q.id AND qr.student_id = ?
    LEFT JOIN lessons l ON l.subject_id = s.id
    WHERE e.student_id = ?
";

$params = [$student_id, $student_id, $student_id, $student_id, $student_id];

if ($classView === 'active') {
    $subjectsQuery .= ' AND archived.subject_id IS NULL';
} elseif ($classView === 'archived') {
    $subjectsQuery .= ' AND archived.subject_id IS NOT NULL';
}

if (!empty($searchTerm)) {
    $subjectsQuery .= " AND (s.title LIKE ? OR s.class_code LIKE ? OR u.name LIKE ?)";
    $wildcard = "%{$searchTerm}%";
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
}

if (!empty($filterStatus)) {
    $subjectsQuery .= " AND s.status = ?";
    $params[] = $filterStatus;
}

$subjectsQuery .= " GROUP BY s.id, s.title, s.class_code, s.description, s.status, archived.subject_id, u.name, u.email, p.name ORDER BY s.title";

$stmt = $pdo->prepare($subjectsQuery);
$stmt->execute($params);
$subjects = $stmt->fetchAll();

// ============================================================
// Statistics
// ============================================================
$stats = [];

// Total subjects
$stats['total_subjects'] = count($subjects);

// Average grade across all subjects
$avgGradeQuery = $pdo->prepare("
    SELECT AVG(score) as avg_grade FROM (
        SELECT 
            CASE 
                WHEN sub.grade REGEXP '^[0-9]+(\.[0-9]+)?$' THEN CAST(sub.grade AS DECIMAL(10,2))
                WHEN sub.grade = 'A' THEN 95 WHEN sub.grade = 'A-' THEN 91 WHEN sub.grade = 'B+' THEN 88 WHEN sub.grade = 'B' THEN 85 
                WHEN sub.grade = 'B-' THEN 82 WHEN sub.grade = 'C+' THEN 79 WHEN sub.grade = 'C' THEN 76 WHEN sub.grade = 'C-' THEN 73 
                WHEN sub.grade = 'D+' THEN 70 WHEN sub.grade = 'D' THEN 67 WHEN sub.grade = 'D-' THEN 64 WHEN sub.grade = 'F' THEN 50 
                ELSE NULL 
            END as score
        FROM submissions sub
        JOIN assignments a ON sub.assignment_id = a.id
        JOIN subjects s ON s.id = a.subject_id
        JOIN enrollments e ON e.subject_id = s.id
        WHERE e.student_id = ? AND sub.student_id = ? AND sub.grade IS NOT NULL
        
        UNION ALL
        
        SELECT qr.score as score
        FROM quiz_results qr
        JOIN quizzes q ON qr.quiz_id = q.id
        JOIN subjects s ON s.id = q.subject_id
        JOIN enrollments e ON e.subject_id = s.id
        WHERE e.student_id = ? AND qr.student_id = ? AND qr.score IS NOT NULL
    ) as combined
");
$avgGradeQuery->execute([$student_id, $student_id, $student_id, $student_id]);
$avgGradeData = $avgGradeQuery->fetch();
$stats['avg_grade'] = $avgGradeData['avg_grade'] ?? 0;

// Overall attendance rate
$attQuery = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
        COUNT(*) as total,
        ROUND((COUNT(CASE WHEN status = 'present' THEN 1 END) / COUNT(*)) * 100, 1) as rate
    FROM attendance WHERE student_id = ?
");
$attQuery->execute([$student_id]);
$attData = $attQuery->fetch();
$stats['attendance_rate'] = $attData ? ($attData['rate'] ?? 0) : 0;

// Completed activities
$completedQuery = $pdo->prepare("
    SELECT COUNT(*) FROM submissions sub
    JOIN assignments a ON sub.assignment_id = a.id
    JOIN subjects s ON s.id = a.subject_id
    JOIN enrollments e ON e.subject_id = s.id
    WHERE e.student_id = ? AND sub.id IS NOT NULL
");
$completedQuery->execute([$student_id]);
$stats['completed_activities'] = $completedQuery->fetchColumn();

// Pending activities
$pendingQuery = $pdo->prepare("
    SELECT COUNT(*) FROM assignments a
    JOIN subjects s ON s.id = a.subject_id
    JOIN enrollments e ON e.subject_id = s.id
    LEFT JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = ?
    WHERE e.student_id = ? AND sub.id IS NULL AND a.due_date >= NOW()
");
$pendingQuery->execute([$student_id, $student_id]);
$stats['pending_activities'] = $pendingQuery->fetchColumn();

// ============================================================
// Upcoming Deadlines
// ============================================================
$deadlinesQuery = $pdo->prepare("
    SELECT 
        a.id, a.title, a.due_date, a.points,
        s.title as subject_title, s.class_code,
        'assignment' as type
    FROM assignments a
    JOIN subjects s ON s.id = a.subject_id
    JOIN enrollments e ON e.subject_id = s.id
    LEFT JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = ?
    WHERE e.student_id = ? AND sub.id IS NULL AND a.due_date >= CURDATE()
    UNION ALL
    SELECT 
        q.id, q.title, q.created_at as due_date, 0 as points,
        s.title as subject_title, s.class_code,
        'quiz' as type
    FROM quizzes q
    JOIN subjects s ON s.id = q.subject_id
    JOIN enrollments e ON e.subject_id = s.id
    LEFT JOIN quiz_results qr ON qr.quiz_id = q.id AND qr.student_id = ?
    WHERE e.student_id = ? AND qr.id IS NULL
    ORDER BY due_date ASC
    LIMIT 5
");
$deadlinesQuery->execute([$student_id, $student_id, $student_id, $student_id]);
$upcomingDeadlines = $deadlinesQuery->fetchAll();

// ============================================================
// Recent Announcements (from lessons)
// ============================================================
$announcementsQuery = $pdo->prepare("
    SELECT 
        l.title, l.content, l.created_at,
        s.title as subject_title, s.class_code
    FROM lessons l
    JOIN subjects s ON s.id = l.subject_id
    JOIN enrollments e ON e.subject_id = s.id
    WHERE e.student_id = ? AND l.content LIKE '%announcement%'
    ORDER BY l.created_at DESC
    LIMIT 5
");
$announcementsQuery->execute([$student_id]);
$announcements = $announcementsQuery->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>



<div>
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h1 class="h3 fw-bold mb-1">
                    <i class="fas fa-book me-2"></i>My Classes
                </h1>
                <p class="mb-0">Monitor your academic progress across all enrolled courses</p>
            </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="join_class.php" class="btn btn-light fw-semibold">
                        <i class="fas fa-user-plus me-1"></i> Join Class
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($_GET['joined'])): ?>
        <div class="alert alert-success mt-3">Successfully joined class: <?php echo htmlspecialchars($_GET['title'] ?? ''); ?></div>
    <?php endif; ?>

    <?php if ($archiveMessage !== ''): ?>
        <div class="alert alert-info mt-3" role="status"><?php echo htmlspecialchars($archiveMessage); ?></div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="filter-section">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0" style="color: var(--primary-blue);">
                <i class="fas fa-filter me-2"></i>Search & Filter
            </h5>
            <div class="d-flex align-items-center gap-3">
                <div class="view-toggle">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'grid'])); ?>" 
                       class="view-toggle-btn <?php echo $viewMode === 'grid' ? 'active' : ''; ?>">
                        <i class="fas fa-th"></i> Grid
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'table'])); ?>" 
                       class="view-toggle-btn <?php echo $viewMode === 'table' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i> Table
                    </a>
                </div>
            </div>
        </div>
        <form method="GET" class="row g-3">
            <div class="col-lg-4 col-md-6">
                <label class="form-label small text-muted">Search</label>
                <input type="text" name="search" class="form-control search-input" 
                       placeholder="Class code, name, or instructor..." 
                       value="<?php echo htmlspecialchars($searchTerm); ?>">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Status</label>
                <select name="status" class="form-select search-input">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="archived" <?php echo $filterStatus === 'archived' ? 'selected' : ''; ?>>Archived</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">My Classes</label>
                <select name="class_view" class="form-select search-input">
                    <option value="active" <?php echo $classView === 'active' ? 'selected' : ''; ?>>Current</option>
                    <option value="archived" <?php echo $classView === 'archived' ? 'selected' : ''; ?>>Archived</option>
                    <option value="all" <?php echo $classView === 'all' ? 'selected' : ''; ?>>All</option>
                </select>
            </div>
            <div class="col-lg-1 col-md-4 d-flex align-items-end">
                <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--light-blue) 100%); color: white; font-weight: 600; width: 100%;">
                    <i class="fas fa-search"></i>
                </button>
            </div>
            <div class="col-12">
                <a href="subjects.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times me-1"></i> Clear Filters
                </a>
                <span class="text-muted ms-2">Showing <?php echo count($subjects); ?> <?php echo count($subjects) === 1 ? 'class' : 'classes'; ?></span>
            </div>
        </form>
    </div>

    <!-- Main Content -->
    <div class="row g-4">
        <!-- Subjects List -->
        <div class="col-lg-8">
            <?php if ($viewMode === 'grid'): ?>
                <div class="subjects-grid">
                    <?php foreach ($subjects as $subject): ?>
                        <div class="subject-card">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <span class="subject-code"><?php echo htmlspecialchars($subject['class_code']); ?></span>
                                    <h5 class="fw-semibold mt-2 mb-1"><?php echo htmlspecialchars($subject['title']); ?></h5>
                                    <small class="text-muted">
                                        <i class="fas fa-user-tie me-1"></i> <?php echo htmlspecialchars($subject['instructor_name'] ?? 'TBA'); ?>
                                    </small>
                                </div>
                                <div class="grade-badge <?php 
                                    $grade = $subject['current_grade'];
                                    echo $grade >= 90 ? 'grade-excellent' : ($grade >= 80 ? 'grade-good' : ($grade >= 70 ? 'grade-fair' : 'grade-poor'));
                                ?>">
                                    <?php echo $grade > 0 ? number_format($grade, 0) : '-'; ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1" style="font-size: 0.75rem;">
                                    <span class="text-muted">Progress</span>
                                    <span class="text-muted">
                                        <?php 
                                        $totalActivities = $subject['total_assignments'] + $subject['total_quizzes'];
                                        $gradedActivities = $subject['graded_assignments'] + $subject['graded_quizzes'];
                                        echo $gradedActivities . '/' . $totalActivities; 
                                        ?>
                                    </span>
                                </div>
                                <div class="progress-custom">
                                    <div class="progress-fill" style="width: <?php echo $totalActivities > 0 ? ($gradedActivities / $totalActivities) * 100 : 0; ?>%; background: var(--secondary-blue);"></div>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <small class="text-muted d-block">Attendance</small>
                                    <strong class="<?php 
                                        $attRate = $subject['total_attendance_days'] > 0 ? round(($subject['present_days'] / $subject['total_attendance_days']) * 100, 1) : 100;
                                        echo $attRate >= 90 ? 'text-success' : ($attRate >= 75 ? 'text-warning' : 'text-danger');
                                    ?>"><?php echo $attRate; ?>%</strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Lessons</small>
                                    <strong><?php echo $subject['total_lessons']; ?></strong>
                                </div>
                            </div>

                            <span class="status-badge status-<?php echo $subject['status']; ?> mb-3 d-inline-block">
                                <?php echo ucfirst($subject['status']); ?>
                            </span>

                            <div class="d-grid gap-2">
                                <a href="subject_details.php?id=<?php echo $subject['id']; ?>" class="action-btn btn-primary-sm w-100 justify-content-center">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['student_subject_archive_csrf']); ?>">
                                    <input type="hidden" name="subject_id" value="<?php echo (int)$subject['id']; ?>">
                                    <input type="hidden" name="archive_action" value="<?php echo $subject['is_archived'] ? 'restore' : 'archive'; ?>">
                                    <input type="hidden" name="class_view" value="<?php echo htmlspecialchars($classView); ?>">
                                    <button type="submit" name="toggle_class_archive" value="1" class="btn btn-outline-secondary w-100">
                                        <i class="fas <?php echo $subject['is_archived'] ? 'fa-box-open' : 'fa-archive'; ?> me-1"></i>
                                        <?php echo $subject['is_archived'] ? 'Restore Class' : 'Archive Class'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="subjects-table">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Class</th>
                                    <th>Instructor</th>
                                    <th>Grade</th>
                                    <th>Attendance</th>
                                    <th>Progress</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subjects as $subject): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($subject['title']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($subject['class_code']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($subject['instructor_name'] ?? 'TBA'); ?></td>
                                        <td>
                                            <span class="badge <?php 
                                                $grade = $subject['current_grade'];
                                                echo $grade >= 90 ? 'bg-success' : ($grade >= 80 ? 'bg-primary' : ($grade >= 70 ? 'bg-warning' : 'bg-danger'));
                                            ?>"><?php echo $grade > 0 ? number_format($grade, 0) : '-'; ?></span>
                                        </td>
                                        <td>
                                            <?php 
                                            $attRate = $subject['total_attendance_days'] > 0 ? round(($subject['present_days'] / $subject['total_attendance_days']) * 100, 1) : 100;
                                            ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress-custom" style="width: 60px;">
                                                    <div class="progress-fill" style="width: <?php echo $attRate; ?>%; background: <?php echo $attRate >= 90 ? 'var(--success)' : ($attRate >= 75 ? 'var(--warning)' : 'var(--danger)'); ?>;"></div>
                                                </div>
                                                <span class="<?php echo $attRate >= 90 ? 'text-success' : ($attRate >= 75 ? 'text-warning' : 'text-danger'); ?>"><?php echo $attRate; ?>%</span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            $totalActivities = $subject['total_assignments'] + $subject['total_quizzes'];
                                            $gradedActivities = $subject['graded_assignments'] + $subject['graded_quizzes'];
                                            ?>
                                            <div class="progress-custom" style="width: 80px;">
                                                <div class="progress-fill" style="width: <?php echo $totalActivities > 0 ? ($gradedActivities / $totalActivities) * 100 : 0; ?>%; background: var(--secondary-blue);"></div>
                                            </div>
                                            <small class="text-muted"><?php echo $gradedActivities; ?>/<?php echo $totalActivities; ?></small>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $subject['status']; ?>">
                                                <?php echo ucfirst($subject['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="subject_details.php?id=<?php echo $subject['id']; ?>" class="action-btn btn-primary-sm">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['student_subject_archive_csrf']); ?>">
                                                <input type="hidden" name="subject_id" value="<?php echo (int)$subject['id']; ?>">
                                                <input type="hidden" name="archive_action" value="<?php echo $subject['is_archived'] ? 'restore' : 'archive'; ?>">
                                                <input type="hidden" name="class_view" value="<?php echo htmlspecialchars($classView); ?>">
                                                <button type="submit" name="toggle_class_archive" value="1" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas <?php echo $subject['is_archived'] ? 'fa-box-open' : 'fa-archive'; ?> me-1"></i>
                                                    <?php echo $subject['is_archived'] ? 'Restore' : 'Archive'; ?>
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

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Upcoming Deadlines -->
            <div class="chart-container mb-4">
                <h5 class="chart-title">
                    <i class="fas fa-clock me-2"></i>Upcoming Deadlines
                </h5>
                <?php if (!empty($upcomingDeadlines)): ?>
                    <?php foreach ($upcomingDeadlines as $deadline): ?>
                        <div class="sidebar-item <?php echo strtotime($deadline['due_date']) < strtotime('+3 days') ? 'urgent' : ''; ?>">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="fw-semibold" style="font-size: 0.85rem;">
                                        <?php if ($deadline['type'] === 'quiz'): ?>
                                            <i class="fas fa-question-circle text-warning me-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-tasks text-primary me-1"></i>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($deadline['title']); ?>
                                    </div>
                                    <small class="text-muted"><?php echo htmlspecialchars($deadline['subject_title']); ?></small>
                                </div>
                                <div class="text-end">
                                    <div class="<?php echo strtotime($deadline['due_date']) < strtotime('+3 days') ? 'text-danger fw-bold' : 'text-muted'; ?>" style="font-size: 0.75rem;">
                                        <?php echo date('M d', strtotime($deadline['due_date'])); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No upcoming deadlines!</p>
                <?php endif; ?>
            </div>

            <!-- Announcements -->
            <div class="chart-container">
                <h5 class="chart-title">
                    <i class="fas fa-bullhorn me-2"></i>Recent Announcements
                </h5>
                <?php if (!empty($announcements)): ?>
                    <?php foreach ($announcements as $announcement): ?>
                        <div class="sidebar-item">
                            <div class="fw-semibold" style="font-size: 0.85rem;"><?php echo htmlspecialchars($announcement['title']); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($announcement['subject_title']); ?></small>
                            <div style="font-size: 0.7rem; color: #64748b; margin-top: 0.25rem;">
                                <?php echo date('M d, Y', strtotime($announcement['created_at'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No recent announcements.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>