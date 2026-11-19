<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: auth/login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? 'Student';

// Get student profile
$stmt = $pdo->prepare("
    SELECT sp.*, u.name, u.email, u.profile_picture,
           p.name as program_name, d.name as department_name
    FROM student_profiles sp
    JOIN users u ON u.id = sp.student_id
    LEFT JOIN programs p ON p.id = sp.program_id
    LEFT JOIN departments d ON d.id = sp.department_id
    WHERE sp.student_id = ?
");
$stmt->execute([$student_id]);
$studentProfile = $stmt->fetch() ?? [];

// ============================================================
// Dashboard Statistics
// ============================================================
$stats = [];

// Total subjects enrolled
$stats['total_subjects'] = $pdo->prepare("
    SELECT COUNT(*) FROM enrollments WHERE student_id = ?
");
$stats['total_subjects']->execute([$student_id]);
$stats['total_subjects'] = $stats['total_subjects']->fetchColumn();

// Attendance rate
$stats['attendance'] = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
        COUNT(*) as total,
        ROUND((COUNT(CASE WHEN status = 'present' THEN 1 END) / COUNT(*)) * 100, 1) as rate
    FROM attendance WHERE student_id = ?
");
$stats['attendance']->execute([$student_id]);
$attData = $stats['attendance']->fetch();
$stats['attendance_rate'] = $attData ? ($attData['rate'] ?? 0) : 0;

// Completed assignments
$stats['completed_assignments'] = $pdo->prepare("
    SELECT COUNT(*) FROM submissions sub
    JOIN assignments a ON sub.assignment_id = a.id
    JOIN subjects s ON s.id = a.subject_id
    JOIN enrollments e ON e.subject_id = s.id
    WHERE e.student_id = ? AND sub.grade IS NOT NULL
");
$stats['completed_assignments']->execute([$student_id]);
$stats['completed_assignments'] = $stats['completed_assignments']->fetchColumn();

// Pending assignments
$stats['pending_assignments'] = $pdo->prepare("
    SELECT COUNT(*) FROM assignments a
    JOIN subjects s ON s.id = a.subject_id
    JOIN enrollments e ON e.subject_id = s.id
    LEFT JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = ?
    WHERE e.student_id = ? AND sub.id IS NULL AND a.due_date >= NOW()
");
$stats['pending_assignments']->execute([$student_id, $student_id]);
$stats['pending_assignments'] = $stats['pending_assignments']->fetchColumn();

// Average grade (GPA-like)
$stats['avg_grade'] = $pdo->prepare("
    SELECT AVG(score) as avg_score FROM (
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
        
        SELECT CASE WHEN qr.max_score > 0 THEN (qr.score / qr.max_score) * 100 ELSE qr.score END as score
        FROM quiz_results qr
        JOIN quizzes q ON qr.quiz_id = q.id
        JOIN subjects s ON s.id = q.subject_id
        JOIN enrollments e ON e.subject_id = s.id
        WHERE e.student_id = ? AND qr.student_id = ? AND qr.score IS NOT NULL
          AND qr.grading_status = 'graded'
    ) as combined
");
$stats['avg_grade']->execute([$student_id, $student_id, $student_id, $student_id]);
$avgData = $stats['avg_grade']->fetch();
$stats['avg_grade'] = $avgData['avg_score'] ?? 0;

// ============================================================
// Enrolled Subjects
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        s.id, s.title, s.class_code, s.status,
        u.name as instructor_name,
        COUNT(DISTINCT a.id) as total_assignments,
        COUNT(DISTINCT CASE WHEN sub.grade IS NOT NULL THEN a.id END) as graded_assignments,
        COALESCE(AVG(
            CASE 
                WHEN sub.grade REGEXP '^[0-9]+(\.[0-9]+)?$' THEN CAST(sub.grade AS DECIMAL(10,2))
                WHEN sub.grade = 'A' THEN 95 WHEN sub.grade = 'A-' THEN 91 WHEN sub.grade = 'B+' THEN 88 WHEN sub.grade = 'B' THEN 85 
                WHEN sub.grade = 'B-' THEN 82 WHEN sub.grade = 'C+' THEN 79 WHEN sub.grade = 'C' THEN 76 WHEN sub.grade = 'C-' THEN 73 
                WHEN sub.grade = 'D+' THEN 70 WHEN sub.grade = 'D' THEN 67 WHEN sub.grade = 'D-' THEN 64 WHEN sub.grade = 'F' THEN 50 
                ELSE NULL 
            END
        ), 0) as current_grade
    FROM enrollments e
    JOIN subjects s ON s.id = e.subject_id
    LEFT JOIN users u ON u.id = s.instructor_id
    LEFT JOIN assignments a ON a.subject_id = s.id
    LEFT JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = ?
    WHERE e.student_id = ?
    GROUP BY s.id, s.title, s.class_code, s.status, u.name
    ORDER BY s.title
");
$stmt->execute([$student_id, $student_id]);
$enrolledSubjects = $stmt->fetchAll();

// ============================================================
// Upcoming Assignments
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        a.id, a.title, a.due_date, a.points,
        s.title as subject_title, s.class_code,
        sub.grade, sub.feedback
    FROM assignments a
    JOIN subjects s ON s.id = a.subject_id
    JOIN enrollments e ON e.subject_id = s.id
    LEFT JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = ?
    WHERE e.student_id = ? AND sub.id IS NULL AND a.due_date >= CURDATE()
    ORDER BY a.due_date ASC
    LIMIT 5
");
$stmt->execute([$student_id, $student_id]);
$upcomingAssignments = $stmt->fetchAll();

// ============================================================
// Recent Grades
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        sub.grade, sub.feedback, sub.submitted_at,
        a.title as assignment_title,
        s.title as subject_title, s.class_code
    FROM submissions sub
    JOIN assignments a ON sub.assignment_id = a.id
    JOIN subjects s ON s.id = a.subject_id
    JOIN enrollments e ON e.subject_id = s.id
    WHERE e.student_id = ? AND sub.grade IS NOT NULL
    ORDER BY sub.submitted_at DESC
    LIMIT 5
");
$stmt->execute([$student_id]);
$recentGrades = $stmt->fetchAll();

// ============================================================
// Attendance by Subject
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        s.title, s.class_code,
        COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent,
        COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late,
        COUNT(*) as total,
        ROUND((COUNT(CASE WHEN a.status = 'present' THEN 1 END) / COUNT(*)) * 100, 1) as rate
    FROM attendance a
    JOIN subjects s ON s.id = a.subject_id
    WHERE a.student_id = ?
    GROUP BY s.id, s.title, s.class_code
    ORDER BY rate ASC
");
$stmt->execute([$student_id]);
$attendanceBySubject = $stmt->fetchAll();

// ============================================================
// Notifications
// ============================================================
$stmt = $pdo->prepare("
    SELECT id, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute([$student_id]);
$notifications = $stmt->fetchAll();

// ============================================================
// Grade Distribution (for chart)
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        s.title,
        COALESCE(AVG(
            CASE 
                WHEN sub.grade REGEXP '^[0-9]+(\.[0-9]+)?$' THEN CAST(sub.grade AS DECIMAL(10,2))
                WHEN sub.grade = 'A' THEN 95 WHEN sub.grade = 'A-' THEN 91 WHEN sub.grade = 'B+' THEN 88 WHEN sub.grade = 'B' THEN 85 
                WHEN sub.grade = 'B-' THEN 82 WHEN sub.grade = 'C+' THEN 79 WHEN sub.grade = 'C' THEN 76 WHEN sub.grade = 'C-' THEN 73 
                WHEN sub.grade = 'D+' THEN 70 WHEN sub.grade = 'D' THEN 67 WHEN sub.grade = 'D-' THEN 64 WHEN sub.grade = 'F' THEN 50 
                ELSE NULL 
            END
        ), 0) as avg_grade
    FROM subjects s
    JOIN enrollments e ON e.subject_id = s.id
    LEFT JOIN assignments a ON a.subject_id = s.id
    LEFT JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = ?
    WHERE e.student_id = ?
    GROUP BY s.id, s.title
    ORDER BY avg_grade DESC
");
$stmt->execute([$student_id, $student_id]);
$gradeDistribution = $stmt->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div>
    <!-- Welcome Section -->
    <div class="welcome-card">
        <div class="d-flex align-items-center">
            <div class="welcome-avatar">
                <?php echo strtoupper(substr($student_name, 0, 2)); ?>
            </div>
            <div class="flex-grow-1 min-width-0">
                <h1 class="welcome-title">Welcome back, <?php echo htmlspecialchars($student_name); ?>! 👋</h1>
                <p class="welcome-subtitle mb-0">
                    <i class="fas fa-graduation-cap me-1"></i>
                    <?php echo htmlspecialchars($studentProfile['program_name'] ?? 'General'); ?>
                    <?php if (!empty($studentProfile['year_level'])): ?>
                        &bull; Year <?php echo htmlspecialchars($studentProfile['year_level']); ?>
                    <?php endif; ?>
                    <?php if (!empty($studentProfile['student_id_number'])): ?>
                        &bull; ID: <?php echo htmlspecialchars($studentProfile['student_id_number']); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #dbeafe; color: #2563eb;">
                        <i class="fas fa-star"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo number_format($stats['avg_grade'], 1); ?>%</div>
                        <div class="stat-label">Average Grade</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #dbeafe; color: #2563eb;">
                        <i class="fas fa-book"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo $stats['total_subjects']; ?></div>
                        <div class="stat-label">Classes</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #dcfce7; color: #059669;">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo $stats['attendance_rate']; ?>%</div>
                        <div class="stat-label">Attendance</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #dcfce7; color: #059669;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo $stats['completed_assignments']; ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #fef3c7; color: #d97706;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo $stats['pending_assignments']; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #e0e7ff; color: #4f46e5;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <div class="stat-value" style="font-size:1.1rem;">
                            <?php
                            $performance = $stats['avg_grade'] >= 90 ? 'Excellent' : ($stats['avg_grade'] >= 80 ? 'Good' : ($stats['avg_grade'] >= 70 ? 'Fair' : 'Needs Work'));
                            echo $performance;
                            ?>
                        </div>
                        <div class="stat-label">Performance</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="row g-4 mb-4">
        <!-- My Subjects -->
        <div class="col-lg-6">
            <div class="chart-container">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="chart-title mb-0">
                            <i class="fas fa-book me-2"></i>My Classes
                        </h5>
                </div>
                <?php foreach ($enrolledSubjects as $subject): ?>
                    <div class="subject-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="subject-code"><?php echo htmlspecialchars($subject['class_code']); ?></span>
                                    <span class="status-badge status-<?php echo $subject['status']; ?>">
                                        <?php echo ucfirst($subject['status']); ?>
                                    </span>
                                </div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($subject['title']); ?></div>
                                <small class="text-muted">
                                    <i class="fas fa-user-tie me-1"></i> <?php echo htmlspecialchars($subject['instructor_name'] ?? 'TBA'); ?>
                                </small>
                                <div class="mt-2">
                                    <div class="d-flex justify-content-between mb-1" style="font-size: 0.75rem;">
                                        <span class="text-muted">Progress</span>
                                        <span class="text-muted"><?php echo $subject['graded_assignments']; ?>/<?php echo $subject['total_assignments']; ?> graded</span>
                                    </div>
                                    <div class="progress-custom">
                                        <div class="progress-fill" style="width: <?php echo $subject['total_assignments'] > 0 ? ($subject['graded_assignments'] / $subject['total_assignments']) * 100 : 0; ?>%; background: var(--secondary-blue);"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="grade-badge <?php 
                                $grade = $subject['current_grade'];
                                echo $grade >= 90 ? 'grade-excellent' : ($grade >= 80 ? 'grade-good' : ($grade >= 70 ? 'grade-fair' : 'grade-poor'));
                            ?>">
                                <?php echo $grade > 0 ? number_format($grade, 0) : '-'; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Right Column -->
        <div class="col-lg-6">
            <!-- Upcoming Assignments -->
            <div class="chart-container mb-4">
                <h5 class="chart-title">
                    <i class="fas fa-tasks me-2"></i>Upcoming Assignments
                </h5>
                <?php if (!empty($upcomingAssignments)): ?>
                    <?php foreach ($upcomingAssignments as $assignment): ?>
                        <div class="assignment-item <?php echo strtotime($assignment['due_date']) < strtotime('+3 days') ? 'urgent' : ''; ?> <?php echo $assignment['grade'] ? 'completed' : ''; ?>">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="fw-semibold" style="font-size: 0.9rem;"><?php echo htmlspecialchars($assignment['title']); ?></div>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($assignment['subject_title']); ?>
                                        <?php if ($assignment['points']): ?>
                                            • <?php echo $assignment['points']; ?> pts
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <div class="<?php echo strtotime($assignment['due_date']) < strtotime('+3 days') ? 'text-danger fw-bold' : 'text-muted'; ?>" style="font-size: 0.8rem;">
                                        <?php echo date('M d', strtotime($assignment['due_date'])); ?>
                                    </div>
                                    <?php if ($assignment['grade']): ?>
                                        <span class="status-badge status-present">Graded: <?php echo $assignment['grade']; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No upcoming assignments!</p>
                <?php endif; ?>
            </div>

            <!-- Recent Grades -->
            <div class="chart-container">
                <h5 class="chart-title">
                    <i class="fas fa-clipboard-check me-2"></i>Recent Grades
                </h5>
                <?php if (!empty($recentGrades)): ?>
                    <?php foreach ($recentGrades as $grade): ?>
                        <div class="assignment-item completed">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold" style="font-size: 0.9rem;"><?php echo htmlspecialchars($grade['assignment_title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($grade['subject_title']); ?></small>
                                </div>
                                <div class="text-end">
                                    <span class="status-badge status-present" style="font-size: 0.85rem;"><?php echo $grade['grade']; ?></span>
                                    <div style="font-size: 0.7rem; color: #64748b;"><?php echo date('M d', strtotime($grade['submitted_at'])); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No grades available yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bottom Row -->
    <div class="row g-4 mb-4">
        <!-- Grade Distribution Chart -->
        <div class="col-lg-8">
            <div class="chart-container">
                <h5 class="chart-title">
                    <i class="fas fa-chart-bar me-2"></i>Performance by Subject
                </h5>
                <?php if (!empty($gradeDistribution)): ?>
                    <canvas id="gradeChart" height="120"></canvas>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No grade data available yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Attendance & Notifications -->
        <div class="col-lg-4">
            <!-- Attendance by Subject -->
            <div class="chart-container mb-4">
                <h5 class="chart-title">
                    <i class="fas fa-calendar-alt me-2"></i>Attendance by Subject
                </h5>
                <?php if (!empty($attendanceBySubject)): ?>
                    <?php foreach ($attendanceBySubject as $att): ?>
                        <div class="attendance-item">
                            <div class="flex-grow-1">
                                <div class="fw-semibold" style="font-size: 0.85rem;"><?php echo htmlspecialchars($att['title']); ?></div>
                                <small class="text-muted" style="font-size: 0.7rem;">
                                    <?php echo $att['present']; ?> present, <?php echo $att['absent']; ?> absent, <?php echo $att['late']; ?> late
                                </small>
                            </div>
                            <div class="attendance-rate-bar">
                                <div class="progress-custom">
                                    <div class="progress-fill" style="width: <?php echo $att['rate']; ?>%; background: <?php echo $att['rate'] >= 90 ? 'var(--success)' : ($att['rate'] >= 75 ? 'var(--warning)' : 'var(--danger)'); ?>;"></div>
                                </div>
                                <small class="text-muted" style="font-size: 0.65rem;"><?php echo $att['rate']; ?>%</small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No attendance data available.</p>
                <?php endif; ?>
            </div>

            <!-- Notifications -->
            <div class="chart-container">
                <h5 class="chart-title">
                    <i class="fas fa-bell me-2"></i>Notifications
                </h5>
                <?php if (!empty($notifications)): ?>
                    <?php foreach ($notifications as $notif): ?>
                        <div class="notification-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>">
                            <div class="notification-icon" style="background: <?php echo !$notif['is_read'] ? '#dbeafe' : '#f1f5f9'; ?>; color: <?php echo !$notif['is_read'] ? '#2563eb' : '#64748b'; ?>;">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <div class="flex-grow-1">
                                <p class="mb-1" style="font-size: 0.85rem;"><?php echo htmlspecialchars($notif['message']); ?></p>
                                <small class="text-muted" style="font-size: 0.7rem;"><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No new notifications.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    <?php if (!empty($gradeDistribution)): ?>
    // Grade Distribution Chart
    const gradeCtx = document.getElementById('gradeChart').getContext('2d');
    new Chart(gradeCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($gradeDistribution, 'title')); ?>,
            datasets: [{
                label: 'Average Grade',
                data: <?php echo json_encode(array_column($gradeDistribution, 'avg_grade')); ?>,
                backgroundColor: <?php echo json_encode(array_map(function($g) {
                    return $g['avg_grade'] >= 90 ? '#10b981' : ($g['avg_grade'] >= 80 ? '#3b82f6' : ($g['avg_grade'] >= 70 ? '#f59e0b' : '#ef4444'));
                }, $gradeDistribution)); ?>,
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
</script>

<?php include 'includes/footer.php'; ?>