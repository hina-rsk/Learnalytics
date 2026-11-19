<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header("Location: auth/login.php");
    exit;
}

$instructor_id = (int)$_SESSION['user_id'];
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';

// Get instructor details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$instructor_id]);
$instructor = $stmt->fetch();

// ============================================================
// Dashboard Statistics
// ============================================================
$stats = [];

// Total courses taught
$stats['total_courses'] = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE instructor_id = ?");
$stats['total_courses']->execute([$instructor_id]);
$stats['total_courses'] = $stats['total_courses']->fetchColumn();

// Total students across all courses
$stats['total_students'] = $pdo->prepare("
    SELECT COUNT(DISTINCT e.student_id) 
    FROM enrollments e 
    JOIN subjects s ON e.subject_id = s.id 
    WHERE s.instructor_id = ?
");
$stats['total_students']->execute([$instructor_id]);
$stats['total_students'] = $stats['total_students']->fetchColumn();

// Active assignments
$stats['active_assignments'] = $pdo->prepare("
    SELECT COUNT(*) FROM assignments a 
    JOIN subjects s ON a.subject_id = s.id 
    WHERE s.instructor_id = ? AND a.due_date >= NOW()
");
$stats['active_assignments']->execute([$instructor_id]);
$stats['active_assignments'] = $stats['active_assignments']->fetchColumn();

// Pending submissions
$stats['pending_submissions'] = $pdo->prepare("
    SELECT COUNT(*) FROM submissions sub 
    JOIN assignments a ON sub.assignment_id = a.id 
    JOIN subjects s ON a.subject_id = s.id 
    WHERE s.instructor_id = ? AND sub.grade IS NULL
");
$stats['pending_submissions']->execute([$instructor_id]);
$stats['pending_submissions'] = $stats['pending_submissions']->fetchColumn();

// Upcoming classes today
$stats['upcoming_classes'] = $pdo->prepare("
    SELECT COUNT(*) FROM subjects s 
    WHERE s.instructor_id = ? AND s.status = 'active'
");
$stats['upcoming_classes']->execute([$instructor_id]);
$stats['upcoming_classes'] = $stats['upcoming_classes']->fetchColumn();

// ============================================================
// Recent Courses
// ============================================================
$stmt = $pdo->prepare("
    SELECT s.*, COUNT(DISTINCT e.student_id) as student_count,
           (SELECT COUNT(*) FROM assignments WHERE subject_id = s.id AND due_date >= NOW()) as active_assignments
    FROM subjects s
    LEFT JOIN enrollments e ON e.subject_id = s.id
    WHERE s.instructor_id = ?
    GROUP BY s.id
    ORDER BY s.created_at DESC
    LIMIT 5
");
$stmt->execute([$instructor_id]);
$recentCourses = $stmt->fetchAll();

// ============================================================
// Pending Submissions
// ============================================================
$stmt = $pdo->prepare("
    SELECT sub.id, sub.content, sub.submitted_at, sub.grade,
           u.name as student_name, u.email as student_email,
           a.id as assignment_id, a.title as assignment_title, a.due_date,
           s.title as subject_title
    FROM submissions sub
    JOIN assignments a ON sub.assignment_id = a.id
    JOIN subjects s ON a.subject_id = s.id
    JOIN users u ON u.id = sub.student_id
    WHERE s.instructor_id = ? AND sub.grade IS NULL
    ORDER BY sub.submitted_at DESC
    LIMIT 5
");
$stmt->execute([$instructor_id]);
$pendingSubmissions = $stmt->fetchAll();

// ============================================================
// Upcoming Deadlines
// ============================================================
$stmt = $pdo->prepare("
    SELECT a.id, a.title, a.due_date, s.title as subject_title,
           COUNT(sub.id) as submission_count
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    LEFT JOIN submissions sub ON sub.assignment_id = a.id
    WHERE s.instructor_id = ? AND a.due_date >= NOW()
    GROUP BY a.id
    ORDER BY a.due_date ASC
    LIMIT 5
");
$stmt->execute([$instructor_id]);
$upcomingDeadlines = $stmt->fetchAll();

// ============================================================
// Recent Announcements
// ============================================================
$stmt = $pdo->prepare("
    SELECT n.id, n.message, n.is_read, n.created_at
    FROM notifications n
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 5
");
$stmt->execute([$instructor_id]);
$recentAnnouncements = $stmt->fetchAll();

// ============================================================
// Instructor Announcement Feature
// ============================================================
$announcement_success = '';
$announcement_error = '';

$stmt = $pdo->prepare("SELECT id, title, class_code FROM subjects WHERE instructor_id = ? ORDER BY title ASC");
$stmt->execute([$instructor_id]);
$instructorSubjects = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_instructor_announcement'])) {
    $announcement_message = trim($_POST['announcement_message'] ?? '');
    $announcement_scope = $_POST['announcement_scope'] ?? 'all';
    $announcement_subject_id = isset($_POST['announcement_subject_id']) ? (int)$_POST['announcement_subject_id'] : 0;

    if ($announcement_message === '') {
        $announcement_error = 'Announcement message cannot be empty.';
    } else {
        $recipientQuery = '';
        $params = [];

        if ($announcement_scope === 'subject' && $announcement_subject_id > 0) {
            $subjectStmt = $pdo->prepare("SELECT title FROM subjects WHERE id = ? AND instructor_id = ?");
            $subjectStmt->execute([$announcement_subject_id, $instructor_id]);
            $subject = $subjectStmt->fetch();

            if (!$subject) {
                $announcement_error = 'Please select a valid class.';
            } else {
                $recipientQuery = "SELECT DISTINCT e.student_id FROM enrollments e WHERE e.subject_id = ?";
                $params = [$announcement_subject_id];
                $announcement_subject_label = $subject['title'];
            }
        } else {
            $recipientQuery = "
                SELECT DISTINCT e.student_id
                FROM enrollments e
                INNER JOIN subjects s ON s.id = e.subject_id
                WHERE s.instructor_id = ?
            ";
            $params = [$instructor_id];
            $announcement_subject_label = 'all your classes';
        }

        if (empty($announcement_error)) {
            $recipientStmt = $pdo->prepare($recipientQuery);
            $recipientStmt->execute($params);
            $recipients = $recipientStmt->fetchAll();

            if (empty($recipients)) {
                $announcement_error = 'No students are enrolled in the selected class yet.';
            } else {
                $insertStmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
                $prefix = $announcement_scope === 'subject' ? "[Class Announcement] {$announcement_subject_label}: " : "[Class Announcement] All classes: ";

                foreach ($recipients as $recipient) {
                    $insertStmt->execute([
                        (int)$recipient['student_id'],
                        $prefix . $announcement_message
                    ]);
                }

                $announcement_success = 'Announcement sent to ' . count($recipients) . ' student(s) in ' . $announcement_subject_label . '.';
            }
        }
    }
}

// ============================================================
// Student Messages
// ============================================================
// Feature removed. Keep placeholder to avoid undefined variable issues.
$studentMessages = [];

// ============================================================
// Class Performance (for chart)
// ============================================================
$stmt = $pdo->prepare("
    SELECT s.title, 
           COUNT(DISTINCT e.student_id) as student_count,
           COALESCE(AVG(CASE WHEN sub.grade IS NOT NULL THEN 
               CASE 
                   WHEN sub.grade REGEXP '^[0-9]+(\.[0-9]+)?$' THEN CAST(sub.grade AS DECIMAL(10,2))
                   WHEN sub.grade = 'A' THEN 95 WHEN sub.grade = 'A-' THEN 91 WHEN sub.grade = 'B+' THEN 88 WHEN sub.grade = 'B' THEN 85 
                   WHEN sub.grade = 'B-' THEN 82 WHEN sub.grade = 'C+' THEN 79 WHEN sub.grade = 'C' THEN 76 WHEN sub.grade = 'C-' THEN 73 
                   WHEN sub.grade = 'D+' THEN 70 WHEN sub.grade = 'D' THEN 67 WHEN sub.grade = 'D-' THEN 64 WHEN sub.grade = 'F' THEN 50 
                   ELSE 70 END END), 0) as avg_grade
    FROM subjects s
    LEFT JOIN enrollments e ON e.subject_id = s.id
    LEFT JOIN assignments a ON a.subject_id = s.id
    LEFT JOIN submissions sub ON sub.assignment_id = a.id
    WHERE s.instructor_id = ?
    GROUP BY s.id, s.title
    ORDER BY avg_grade DESC
    LIMIT 6
");
$stmt->execute([$instructor_id]);
$classPerformance = $stmt->fetchAll();

// ============================================================
// Attendance Summary
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_count,
        COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late_count
    FROM attendance a
    JOIN subjects s ON a.subject_id = s.id
    WHERE s.instructor_id = ? AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stmt->execute([$instructor_id]);
$attendanceSummary = $stmt->fetch();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div>
    <!-- Welcome Section -->
    <div class="welcome-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center">
                <div class="welcome-avatar">
                    <?php echo strtoupper(substr($instructor_name, 0, 2)); ?>
                </div>
                <div>
                    <h1 class="welcome-title">Welcome back, <?php echo htmlspecialchars($instructor_name); ?>! 👋</h1>
                    <p class="welcome-subtitle mb-0">
                        <i class="fas fa-building me-1"></i> Department of <?php echo htmlspecialchars($instructor['bio'] ?? 'General'); ?>
                        &bull;
                        <i class="fas fa-book me-1"></i> <?php echo $stats['total_courses']; ?> Courses
                    </p>
                </div>
            </div>
            <div class="d-none d-md-block text-end">
                <div class="h4 fw-bold mb-0" style="color:#fff;"><?php echo date('l'); ?></div>
                <div style="color:rgba(255,255,255,0.85); font-size:0.9rem;"><?php echo date('F d, Y'); ?></div>
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
            <a href="lessons/index.php" class="quick-action-btn">
                <div class="quick-action-icon icon-bg-blue">
                    <i class="fas fa-video"></i>
                </div>
                <div class="quick-action-label">Upload Materials</div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a href="assignments/create.php" class="quick-action-btn">
                <div class="quick-action-icon icon-bg-green">
                    <i class="fas fa-plus-circle"></i>
                </div>
                <div class="quick-action-label">Create Assignment</div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a href="quizzes/create.php" class="quick-action-btn">
                <div class="quick-action-icon icon-bg-yellow">
                    <i class="fas fa-question-circle"></i>
                </div>
                <div class="quick-action-label">Create Quiz</div>
            </a>
        </div>
       
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a href="attendance/index.php" class="quick-action-btn">
                <div class="quick-action-icon icon-bg-pink">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="quick-action-label">Take Attendance</div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a href="instructor/analytics.php" class="quick-action-btn">
                <div class="quick-action-icon" style="background: #e0e7ff; color: #3730a3;">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="quick-action-label">View Analytics</div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a href="assignments/review.php" class="quick-action-btn">
                <div class="quick-action-icon" style="background: #dbeafe; color: #1d4ed8;">
                    <i class="fas fa-file-circle-check"></i>
                </div>
                <div class="quick-action-label">Review Assignments</div>
            </a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-bullhorn me-2"></i>Send Announcement
                </div>
                <?php if (!empty($announcement_success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-left: 4px solid #22c55e; border-radius: 10px;">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($announcement_success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (!empty($announcement_error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-left: 4px solid #ef4444; border-radius: 10px;">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($announcement_error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Send to</label>
                        <select name="announcement_scope" id="announcement_scope" class="form-select" onchange="toggleInstructorAnnouncementSubject()">
                            <option value="all">All my enrolled students</option>
                            <option value="subject">Specific class</option>
                        </select>
                    </div>

                    <div class="mb-3" id="instructor_announcement_subject_group">
                        <label class="form-label small fw-semibold">Select class</label>
                        <select name="announcement_subject_id" class="form-select">
                            <option value="">Choose a class</option>
                            <?php foreach ($instructorSubjects as $subject): ?>
                                <option value="<?php echo (int)$subject['id']; ?>"><?php echo htmlspecialchars($subject['title']); ?><?php echo !empty($subject['class_code']) ? ' (' . htmlspecialchars($subject['class_code']) . ')' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Announcement message</label>
                        <textarea name="announcement_message" class="form-control" rows="4" placeholder="Share updates, reminders, or important notices with your students..." required></textarea>
                    </div>

                    <button type="submit" name="create_instructor_announcement" class="btn btn-primary w-100">
                        <i class="fas fa-paper-plane me-1"></i> Send to Students
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-book me-2"></i>My Classes
                </div>
                <?php foreach ($recentCourses as $course): ?>
                    <div class="course-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="course-code"><?php echo htmlspecialchars($course['class_code'] ?? 'N/A'); ?></span>
                                    <span class="status-badge status-<?php echo $course['status']; ?>"><?php echo ucfirst($course['status']); ?></span>
                                </div>
                                <div class="course-title"><?php echo htmlspecialchars($course['title']); ?></div>
                                <div class="d-flex gap-3 mt-2 muted-strong" style="font-size: 0.8rem;">
                                    <span><i class="fas fa-users me-1"></i> <?php echo $course['student_count']; ?> students</span>
                                    <span><i class="fas fa-tasks me-1"></i> <?php echo $course['active_assignments']; ?> active assignments</span>
                                </div>
                            </div>
                            <a href="subjects/view.php?id=<?php echo $course['id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row g-4 mb-4">
        <!-- My Courses -->
        <div class="col-lg-6">
            <div class="chart-container">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="chart-title mb-0">
                        <i class="fas fa-book me-2"></i>My Courses
                    </h5>
                    <a href="my_classes.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <?php foreach ($recentCourses as $course): ?>
                    <div class="course-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="course-code"><?php echo htmlspecialchars($course['class_code'] ?? 'N/A'); ?></span>
                                    <span class="status-badge status-<?php echo $course['status']; ?>">
                                        <?php echo ucfirst($course['status']); ?>
                                    </span>
                                </div>
                                <div class="course-title"><?php echo htmlspecialchars($course['title']); ?></div>
                                <div class="d-flex gap-3 mt-2 muted-strong" style="font-size: 0.8rem;">
                                    <span><i class="fas fa-users me-1"></i> <?php echo $course['student_count']; ?> students</span>
                                    <span><i class="fas fa-tasks me-1"></i> <?php echo $course['active_assignments']; ?> active assignments</span>
                                </div>
                            </div>
                            <a href="subjects/view.php?id=<?php echo $course['id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Right Column -->
        <div class="col-lg-6">
            <!-- Pending Submissions -->
            <div class="chart-container mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="chart-title mb-0">
                        <i class="fas fa-clock me-2"></i>Pending Submissions
                    </h5>
                    <a href="assignments/review.php" class="btn btn-sm btn-outline-primary">Review All</a>
                </div>
                <?php if (!empty($pendingSubmissions)): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Assignment</th>
                                    <th>Submitted</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingSubmissions as $sub): ?>
                                    <tr>
                                        <td>
                                            <div><?php echo htmlspecialchars($sub['student_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($sub['student_email']); ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($sub['assignment_title']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($sub['subject_title']); ?></small>
                                        </td>
                                        <td><?php echo date('M d, H:i', strtotime($sub['submitted_at'])); ?></td>
                                        <td>
                                            <a href="assignments/review.php?submission_id=<?php echo (int)$sub['id']; ?>" class="btn btn-sm btn-outline-primary">Review</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No pending submissions!</p>
                <?php endif; ?>
            </div>

            <!-- Upcoming Deadlines -->
            <div class="chart-container">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="chart-title mb-0">
                        <i class="fas fa-calendar-alt me-2"></i>Upcoming Deadlines
                    </h5>
                </div>
                <?php foreach ($upcomingDeadlines as $deadline): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3 p-2 page-bg-light" style="border-radius: 8px;">
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($deadline['title']); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($deadline['subject_title']); ?></small>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-secondary-blue">
                                <?php echo date('M d, Y', strtotime($deadline['due_date'])); ?>
                            </div>
                            <small class="text-muted"><?php echo $deadline['submission_count']; ?> submissions</small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Bottom Row: Performance Chart & Notifications -->
    <div class="row g-4 mb-4">
        <!-- Class Performance Chart -->
        <div class="col-lg-8">
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-chart-bar me-2"></i>Class Performance Overview
                </div>
                <canvas id="performanceChart" height="120"></canvas>
            </div>
        </div>

        <!-- Notifications & Messages -->
        <div class="col-lg-4">
            <!-- Announcements -->
            <div class="chart-container mb-4">
                <div class="chart-title">
                    <i class="fas fa-bullhorn me-2"></i>Recent Announcements
                </div>
                <?php foreach ($recentAnnouncements as $announcement): ?>
                    <div class="announcement-item <?php echo !$announcement['is_read'] ? 'unread' : ''; ?>">
                        <p class="mb-1" style="font-size: 0.875rem;"><?php echo htmlspecialchars($announcement['message']); ?></p>
                        <small class="text-muted"><?php echo date('M d, H:i', strtotime($announcement['created_at'])); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    function toggleInstructorAnnouncementSubject() {
        const scope = document.getElementById('announcement_scope');
        const subjectGroup = document.getElementById('instructor_announcement_subject_group');
        if (!scope || !subjectGroup) return;
        subjectGroup.style.display = scope.value === 'subject' ? 'block' : 'none';
    }

    // Class Performance Chart
    const perfCtx = document.getElementById('performanceChart').getContext('2d');
    new Chart(perfCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($classPerformance, 'title')); ?>,
            datasets: [{
                label: 'Average Grade',
                data: <?php echo json_encode(array_column($classPerformance, 'avg_grade')); ?>,
                backgroundColor: [
                    '#2563eb', '#7c3aed', '#0891b2', '#059669', '#f59e0b', '#ef4444'
                ],
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

    toggleInstructorAnnouncementSubject();
</script>

<?php include 'includes/footer.php'; ?>