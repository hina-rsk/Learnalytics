<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$subject_id = (int)($_GET['id'] ?? 0);

// Verify student is enrolled in this subject
$enrollmentCheck = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND subject_id = ?");
$enrollmentCheck->execute([$student_id, $subject_id]);
if ($enrollmentCheck->fetchColumn() == 0) {
    header("Location: subjects.php");
    exit;
}

// ============================================================
// Get Subject Details
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        u.name as instructor_name, u.email as instructor_email,
        p.name as program_name, d.name as department_name
    FROM subjects s
    LEFT JOIN users u ON u.id = s.instructor_id
    LEFT JOIN programs p ON p.id = s.program_id
    LEFT JOIN departments d ON d.id = s.department_id
    WHERE s.id = ?
");
$stmt->execute([$subject_id]);
$subject = $stmt->fetch();

// Fetch latest schedule and virtual meeting link
$scheduleStmt = $pdo->prepare('SELECT * FROM class_schedules WHERE subject_id = ? ORDER BY id DESC LIMIT 1');
$scheduleStmt->execute([$subject_id]);
$schedule = $scheduleStmt->fetch();

// ============================================================
// Get Statistics for this Subject
// ============================================================
$stats = [];

// Current grade
$gradeQuery = $pdo->prepare("
    SELECT COALESCE(AVG(score), 0) as avg_grade FROM (
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
        WHERE a.subject_id = ? AND sub.student_id = ? AND sub.grade IS NOT NULL
        
        UNION ALL
        
        SELECT qr.score as score
        FROM quiz_results qr
        JOIN quizzes q ON qr.quiz_id = q.id
        WHERE q.subject_id = ? AND qr.student_id = ? AND qr.score IS NOT NULL
    ) as combined
");
$gradeQuery->execute([$subject_id, $student_id, $subject_id, $student_id]);
$gradeData = $gradeQuery->fetch();
$stats['current_grade'] = $gradeData['avg_grade'] ?? 0;

// Attendance rate
$attQuery = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
        COUNT(*) as total,
        ROUND((COUNT(CASE WHEN status = 'present' THEN 1 END) / COUNT(*)) * 100, 1) as rate
    FROM attendance WHERE subject_id = ? AND student_id = ?
");
$attQuery->execute([$subject_id, $student_id]);
$attData = $attQuery->fetch();
$stats['attendance_rate'] = $attData ? ($attData['rate'] ?? 0) : 0;
$stats['present_days'] = $attData['present'] ?? 0;
$stats['total_days'] = $attData['total'] ?? 0;

// Completed assignments
$completedQuery = $pdo->prepare("
    SELECT COUNT(*) FROM submissions sub
    JOIN assignments a ON sub.assignment_id = a.id
    WHERE a.subject_id = ? AND sub.student_id = ?
");
$completedQuery->execute([$subject_id, $student_id]);
$stats['completed_assignments'] = $completedQuery->fetchColumn();

// Pending assignments
$pendingQuery = $pdo->prepare("
    SELECT COUNT(*) FROM assignments a
    LEFT JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = ?
    WHERE a.subject_id = ? AND sub.id IS NULL AND a.due_date >= NOW()
");
$pendingQuery->execute([$student_id, $subject_id]);
$stats['pending_assignments'] = $pendingQuery->fetchColumn();

// Total lessons
$lessonsQuery = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE subject_id = ?");
$lessonsQuery->execute([$subject_id]);
$stats['total_lessons'] = $lessonsQuery->fetchColumn();

// ============================================================
// Get Assignments
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        a.*,
        sub.grade, sub.feedback, sub.submitted_at, sub.file_path
    FROM assignments a
    LEFT JOIN submissions sub ON sub.assignment_id = a.id AND sub.student_id = ?
    WHERE a.subject_id = ?
    ORDER BY a.due_date DESC
");
$stmt->execute([$student_id, $subject_id]);
$assignments = $stmt->fetchAll();

// ============================================================
// Get Quizzes (Only published quizzes assigned to this student)
// ============================================================
$stmt = $pdo->prepare("
    SELECT DISTINCT
        q.*,
        qr.score, qr.taken_at,
        (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) AS question_count
    FROM quizzes q
    LEFT JOIN quiz_results qr ON qr.quiz_id = q.id AND qr.student_id = ?
    LEFT JOIN quiz_assignments qa ON q.id = qa.quiz_id
    LEFT JOIN student_profiles sp ON sp.student_id = ?
    WHERE q.subject_id = ?
      AND (q.status = 'published' OR q.status IS NULL)
      AND (
          qa.id IS NULL
          OR qa.assignment_type = 'subject'
          OR (qa.assignment_type = 'section' AND qa.target_id = sp.section_id)
          OR (qa.assignment_type = 'program' AND qa.target_id = sp.program_id)
          OR (qa.assignment_type = 'year_level' AND qa.year_level = sp.year_level)
          OR (qa.assignment_type = 'student' AND qa.target_id = ?)
      )
    ORDER BY q.created_at DESC
");
$stmt->execute([$student_id, $student_id, $subject_id, $student_id]);
$quizzes = $stmt->fetchAll();

// ============================================================
// Get Lessons/Materials
// ============================================================
$stmt = $pdo->prepare("
    SELECT id, title, content, video_url, created_at
    FROM lessons
    WHERE subject_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$subject_id]);
$lessons = $stmt->fetchAll();

// ============================================================
// Get Attendance Records
// ============================================================
$stmt = $pdo->prepare("
    SELECT attendance_date, status
    FROM attendance
    WHERE subject_id = ? AND student_id = ?
    ORDER BY attendance_date DESC
    LIMIT 30
");
$stmt->execute([$subject_id, $student_id]);
$attendanceRecords = $stmt->fetchAll();

// ============================================================
// Get Upcoming Deadlines
// ============================================================
$stmt = $pdo->prepare("
    SELECT id, title, due_date, points, 'assignment' as type
    FROM assignments
    WHERE subject_id = ? AND due_date >= CURDATE()
    AND id NOT IN (SELECT assignment_id FROM submissions WHERE student_id = ?)
    UNION ALL
    SELECT DISTINCT q.id, q.title, COALESCE(q.due_date, q.created_at) as due_date, 0 as points, 'quiz' as type
    FROM quizzes q
    LEFT JOIN quiz_results qr ON qr.quiz_id = q.id AND qr.student_id = ?
    LEFT JOIN quiz_assignments qa ON q.id = qa.quiz_id
    LEFT JOIN student_profiles sp ON sp.student_id = ?
    WHERE q.subject_id = ? AND qr.id IS NULL
      AND (q.status = 'published' OR q.status IS NULL)
      AND (
          qa.id IS NULL
          OR qa.assignment_type = 'subject'
          OR (qa.assignment_type = 'section' AND qa.target_id = sp.section_id)
          OR (qa.assignment_type = 'program' AND qa.target_id = sp.program_id)
          OR (qa.assignment_type = 'year_level' AND qa.year_level = sp.year_level)
          OR (qa.assignment_type = 'student' AND qa.target_id = ?)
      )
    ORDER BY due_date ASC
    LIMIT 5
");
$stmt->execute([$subject_id, $student_id, $student_id, $student_id, $subject_id, $student_id]);
$upcomingDeadlines = $stmt->fetchAll();

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

    /* Content Cards */
    .content-card {
        background: white;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        margin-bottom: 1.5rem;
    }

    .content-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--primary-blue);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    /* Progress Bar */
    .progress-custom {
        height: 8px;
        border-radius: 4px;
        background: #e2e8f0;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        border-radius: 4px;
        transition: width 0.3s ease;
    }

    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .status-present { background: #dcfce7; color: #166534; }
    .status-absent { background: #fee2e2; color: #991b1b; }
    .status-late { background: #fef3c7; color: #78350f; }
    .status-excused { background: #e0e7ff; color: #4f46e5; }
    .status-submitted { background: #dbeafe; color: #1e40af; }
    .status-pending { background: #fef3c7; color: #78350f; }
    .status-graded { background: #dcfce7; color: #166534; }

    /* Item List */
    .item-list {
        max-height: 400px;
        overflow-y: auto;
    }

    .item-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.875rem;
        border-bottom: 1px solid #f1f5f9;
    }

    .item-row:last-child { border-bottom: none; }
    .item-row:hover { background: #f8fafc; }

    .item-info { flex: 1; }
    .item-title { font-weight: 600; font-size: 0.9rem; }
    .item-meta { font-size: 0.75rem; color: #64748b; margin-top: 0.25rem; }

    /* Grade Badge */
    .grade-badge {
        width: 45px;
        height: 45px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9rem;
    }

    .grade-excellent { background: #dcfce7; color: #166534; }
    .grade-good { background: #dbeafe; color: #1e40af; }
    .grade-fair { background: #fef3c7; color: #78350f; }
    .grade-poor { background: #fee2e2; color: #991b1b; }

    /* Tab Navigation */
    .tab-nav {
        display: flex;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 1rem;
    }

    .tab-btn {
        padding: 0.75rem 1.25rem;
        border: none;
        background: none;
        cursor: pointer;
        font-weight: 500;
        color: #64748b;
        border-bottom: 2px solid transparent;
        margin-bottom: -2px;
        transition: all 0.2s ease;
    }

    .tab-btn:hover { color: var(--secondary-blue); }
    .tab-btn.active {
        color: var(--secondary-blue);
        border-bottom-color: var(--secondary-blue);
    }

    .tab-content { display: none; }
    .tab-content.active { display: block; }

    /* Sidebar Items */
    .sidebar-item {
        padding: 0.875rem;
        border-left: 4px solid var(--secondary-blue);
        background: #f8fafc;
        border-radius: 0 8px 8px 0;
        margin-bottom: 0.75rem;
    }

    .sidebar-item.urgent {
        border-left-color: var(--danger);
        background: #fef2f2;
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
                <a href="subjects.php" class="btn btn-outline-light btn-sm me-2">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
                <span class="badge bg-white text-primary me-2"><?php echo htmlspecialchars($subject['class_code']); ?></span>
                <h1 class="h3 fw-bold mb-1 d-inline"><?php echo htmlspecialchars($subject['title']); ?></h1>
                <p class="mb-0 mt-2" style="opacity: 0.9;">
                    <i class="fas fa-user-tie me-1"></i> <?php echo htmlspecialchars($subject['instructor_name'] ?? 'TBA'); ?>
                    <?php if ($subject['program_name']): ?>
                        • <?php echo htmlspecialchars($subject['program_name']); ?>
                    <?php endif; ?>
                </p>
                <?php if (!empty($schedule['meeting_link'])): ?>
                    <div class="mt-3">
                        <a href="../virtual_class.php?id=<?php echo $subject_id; ?>" class="btn btn-success btn-sm">
                            <i class="fas fa-video me-1"></i> Join Live Class
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <span class="status-badge status-<?php echo $subject['status']; ?>" style="font-size: 0.8rem; padding: 0.5rem 1rem;">
                <?php echo ucfirst($subject['status']); ?>
            </span>
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
                        <div class="stat-value"><?php echo number_format($stats['current_grade'], 1); ?>%</div>
                        <div class="stat-label">Current Grade</div>
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
                        <i class="fas fa-book"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo $stats['total_lessons']; ?></div>
                        <div class="stat-label">Lessons</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #dcfce7; color: #059669;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <div class="stat-value">
                            <?php 
                            $perf = $stats['current_grade'] >= 90 ? 'Excellent' : ($stats['current_grade'] >= 80 ? 'Good' : ($stats['current_grade'] >= 70 ? 'Fair' : 'Needs Work'));
                            echo $perf;
                            ?>
                        </div>
                        <div class="stat-label">Performance</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="row g-4">
        <!-- Left Column - Tabs -->
        <div class="col-lg-8">
            <div class="content-card">
                <!-- Tab Navigation -->
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="showTab('assignments')">
                        <i class="fas fa-tasks me-1"></i> Assignments
                    </button>
                    <button class="tab-btn" onclick="showTab('quizzes')">
                        <i class="fas fa-question-circle me-1"></i> Quizzes
                    </button>
                    <button class="tab-btn" onclick="showTab('lessons')">
                        <i class="fas fa-graduation-cap me-1"></i> Lessons
                    </button>
                    <button class="tab-btn" onclick="showTab('attendance')">
                        <i class="fas fa-calendar-check me-1"></i> Attendance
                    </button>
                </div>

                <!-- Assignments Tab -->
                <div id="assignments" class="tab-content active">
                    <div class="item-list">
                        <?php if (!empty($assignments)): ?>
                            <?php foreach ($assignments as $assignment): ?>
                                <div class="item-row">
                                    <div class="item-info">
                                        <div class="item-title"><?php echo htmlspecialchars($assignment['title']); ?></div>
                                        <div class="item-meta">
                                            Due: <?php echo $assignment['due_date'] ? date('M d, Y', strtotime($assignment['due_date'])) : 'No due date'; ?>
                                            <?php if ($assignment['points']): ?> • <?php echo $assignment['points']; ?> pts <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="text-end">
                                            <?php if ($assignment['grade']): ?>
                                                <div class="grade-badge <?php 
                                                    $g = $assignment['grade'];
                                                    echo $g >= 90 ? 'grade-excellent' : ($g >= 80 ? 'grade-good' : ($g >= 70 ? 'grade-fair' : 'grade-poor'));
                                                ?>"><?php echo $assignment['grade']; ?></div>
                                                <small class="text-muted">Submitted</small>
                                            <?php elseif ($assignment['submitted_at']): ?>
                                                <span class="status-badge status-submitted">Submitted</span>
                                            <?php else: ?>
                                                <span class="status-badge status-pending">Pending</span>
                                            <?php endif; ?>
                                        </div>
                                        <a href="../assignments/view.php?id=<?php echo $assignment['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center py-4">No assignments yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quizzes Tab -->
                <div id="quizzes" class="tab-content">
                    <div class="item-list">
                        <?php if (!empty($quizzes)): ?>
                            <?php foreach ($quizzes as $quiz): ?>
                                <div class="item-row">
                                    <div class="item-info">
                                        <div class="item-title"><?php echo htmlspecialchars($quiz['title']); ?></div>
                                        <div class="item-meta">
                                            Created: <?php echo date('M d, Y', strtotime($quiz['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="text-end">
                                            <?php if ($quiz['score'] !== null): ?>
                                                <?php
                                                    $question_count = (int)$quiz['question_count'];
                                                    if (!empty($quiz['description']) && strpos($quiz['description'], 'Quiz Title:') === 0) {
                                                        preg_match_all('/^Q\d+\./m', $quiz['description'], $question_matches);
                                                        $question_count = count($question_matches[0]);
                                                    }
                                                ?>
                                                <div class="grade-badge grade-good">
                                                    <?php echo htmlspecialchars($quiz['score']); ?><?php echo $question_count > 0 ? '/' . $question_count : ''; ?>
                                                </div>
                                                <small class="text-muted">Taken</small>
                                            <?php else: ?>
                                                <span class="status-badge status-pending">Not Taken</span>
                                            <?php endif; ?>
                                        </div>
                                        <a href="../quizzes/view.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center py-4">No quizzes yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Lessons Tab -->
                <div id="lessons" class="tab-content">
                    <div class="item-list">
                        <?php if (!empty($lessons)): ?>
                            <?php foreach ($lessons as $lesson): ?>
                                <div class="item-row">
                                    <div class="item-info">
                                        <div class="item-title">
                                            <i class="fas fa-file-alt me-2 text-primary"></i>
                                            <?php echo htmlspecialchars($lesson['title']); ?>
                                        </div>
                                        <div class="item-meta">
                                            <?php echo date('M d, Y', strtotime($lesson['created_at'])); ?>
                                            <?php if ($lesson['video_url']): ?>
                                                <span class="ms-2"><i class="fas fa-video me-1"></i>Video</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <a href="../lessons/view.php?id=<?php echo $lesson['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center py-4">No lessons available yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Attendance Tab -->
                <div id="attendance" class="tab-content">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Overall Attendance</span>
                            <span class="fw-bold"><?php echo $stats['attendance_rate']; ?>%</span>
                        </div>
                        <div class="progress-custom">
                            <div class="progress-fill" style="width: <?php echo $stats['attendance_rate']; ?>%; background: <?php echo $stats['attendance_rate'] >= 90 ? 'var(--success)' : ($stats['attendance_rate'] >= 75 ? 'var(--warning)' : 'var(--danger)'); ?>;"></div>
                        </div>
                        <small class="text-muted"><?php echo $stats['present_days']; ?> present out of <?php echo $stats['total_days']; ?> days</small>
                    </div>
                    <div class="item-list">
                        <?php if (!empty($attendanceRecords)): ?>
                            <?php foreach ($attendanceRecords as $record): ?>
                                <div class="item-row">
                                    <div class="item-info">
                                        <div class="item-title"><?php echo date('M d, Y', strtotime($record['attendance_date'])); ?></div>
                                    </div>
                                    <span class="status-badge status-<?php echo $record['status']; ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center py-4">No attendance records yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column - Sidebar -->
        <div class="col-lg-4">
            <!-- Upcoming Deadlines -->
            <div class="content-card">
                <h5 class="content-title">
                    <i class="fas fa-clock me-2"></i>Upcoming Deadlines
                </h5>
                <?php if (!empty($upcomingDeadlines)): ?>
                    <?php foreach ($upcomingDeadlines as $deadline): ?>
                        <div class="sidebar-item <?php echo strtotime($deadline['due_date']) < strtotime('+3 days') ? 'urgent' : ''; ?>">
                            <div class="fw-semibold" style="font-size: 0.85rem;">
                                <?php if ($deadline['type'] === 'quiz'): ?>
                                    <i class="fas fa-question-circle text-warning me-1"></i>
                                <?php else: ?>
                                    <i class="fas fa-tasks text-primary me-1"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($deadline['title']); ?>
                            </div>
                            <small class="text-muted">Due: <?php echo date('M d, Y', strtotime($deadline['due_date'])); ?></small>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-3">No upcoming deadlines!</p>
                <?php endif; ?>
            </div>

            <!-- Instructor Info -->
            <div class="content-card">
                <h5 class="content-title">
                    <i class="fas fa-user-tie me-2"></i>Instructor
                </h5>
                <div class="d-flex align-items-center">
                    <div class="stat-icon me-3" style="background: #dbeafe; color: #2563eb;">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($subject['instructor_name'] ?? 'TBA'); ?></div>
                        <small class="text-muted"><?php echo htmlspecialchars($subject['instructor_email'] ?? ''); ?></small>
                    </div>
                </div>
            </div>

            <!-- Subject Description -->
            <?php if (!empty($subject['description'])): ?>
            <div class="content-card">
                <h5 class="content-title">
                    <i class="fas fa-info-circle me-2"></i>Description
                </h5>
                <p class="text-muted mb-0" style="font-size: 0.85rem;"><?php echo nl2br(htmlspecialchars($subject['description'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
function showTab(tabId) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    // Remove active from all buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    // Show selected tab
    document.getElementById(tabId).classList.add('active');
    // Add active to clicked button
    event.target.closest('.tab-btn').classList.add('active');
}
</script>

<?php include '../includes/footer.php'; ?>