<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Only admins can access analytics
if ($role !== 'admin') {
    header("Location: index.php");
    exit;
}

// Get overall quiz statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT q.id) as total_quizzes,
        COUNT(DISTINCT q.instructor_id) as total_instructors,
        COUNT(DISTINCT qr.student_id) as total_students_attempted,
        COUNT(DISTINCT qr.id) as total_attempts
    FROM quizzes q
    LEFT JOIN quiz_results qr ON q.id = qr.quiz_id
");
$overall_stats = $stmt->fetch();

// Get quiz status breakdown
$stmt = $pdo->query("
    SELECT status, COUNT(*) as count
    FROM quizzes
    GROUP BY status
");
$status_breakdown = $stmt->fetchAll();

// Get top performing quizzes
$stmt = $pdo->query("
    SELECT q.id, q.title, s.title as subject_title, u.name as instructor_name,
           COUNT(qr.id) as attempts,
           AVG(CASE WHEN qr.grading_status = 'graded' THEN
               CASE WHEN qr.max_score > 0 THEN qr.score * 100 / qr.max_score ELSE qr.score END END) as avg_score,
           MAX(CASE WHEN qr.grading_status = 'graded' THEN
               CASE WHEN qr.max_score > 0 THEN qr.score * 100 / qr.max_score ELSE qr.score END END) as highest_score,
           MIN(CASE WHEN qr.grading_status = 'graded' THEN
               CASE WHEN qr.max_score > 0 THEN qr.score * 100 / qr.max_score ELSE qr.score END END) as lowest_score
    FROM quizzes q
    JOIN subjects s ON q.subject_id = s.id
    JOIN users u ON q.instructor_id = u.id
    LEFT JOIN quiz_results qr ON q.id = qr.quiz_id
    GROUP BY q.id, q.title, s.title, u.name
    HAVING attempts > 0
    ORDER BY attempts DESC, avg_score DESC
    LIMIT 10
");
$top_quizzes = $stmt->fetchAll();

// Get instructor performance
$stmt = $pdo->query("
    SELECT u.id, u.name, u.email,
           COUNT(DISTINCT q.id) as quizzes_created,
           COUNT(DISTINCT qr.id) as total_attempts,
           AVG(CASE WHEN qr.grading_status = 'graded' THEN
               CASE WHEN qr.max_score > 0 THEN qr.score * 100 / qr.max_score ELSE qr.score END END) as avg_score
    FROM users u
    LEFT JOIN quizzes q ON u.id = q.instructor_id
    LEFT JOIN quiz_results qr ON q.id = qr.quiz_id
    WHERE u.role = 'instructor'
    GROUP BY u.id, u.name, u.email
    HAVING quizzes_created > 0
    ORDER BY quizzes_created DESC, total_attempts DESC
");
$instructor_stats = $stmt->fetchAll();

// Get recent quiz activity
$stmt = $pdo->query("
    SELECT q.title, s.title as subject_title, u.name as instructor_name,
           COUNT(qr.id) as recent_attempts,
           MAX(qr.taken_at) as last_attempt
    FROM quizzes q
    JOIN subjects s ON q.subject_id = s.id
    JOIN users u ON q.instructor_id = u.id
    LEFT JOIN quiz_results qr ON q.id = qr.quiz_id AND qr.taken_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY q.id, q.title, s.title, u.name
    ORDER BY last_attempt DESC NULLS LAST
    LIMIT 10
");
$recent_activity = $stmt->fetchAll();

// Get low performing quizzes (for intervention)
$stmt = $pdo->query("
    SELECT q.id, q.title, s.title as subject_title, u.name as instructor_name,
           COUNT(qr.id) as attempts,
           ROUND(AVG(CASE WHEN qr.grading_status = 'graded' THEN
               CASE WHEN qr.max_score > 0 THEN qr.score * 100 / qr.max_score ELSE qr.score END END), 2) as avg_score
    FROM quizzes q
    JOIN subjects s ON q.subject_id = s.id
    JOIN users u ON q.instructor_id = u.id
    LEFT JOIN quiz_results qr ON q.id = qr.quiz_id
    GROUP BY q.id, q.title, s.title, u.name
    HAVING attempts >= 3 AND avg_score < 50
    ORDER BY avg_score ASC
");
$low_performing = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">
            <i class="fas fa-chart-line me-2"></i>Quiz Analytics & Reports
        </h1>
        <div class="btn-group">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Quizzes
            </a>
            <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
            </button>
        </div>
    </div>

    <!-- Overall Statistics -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card shadow border-0 h-100" style="border-left: 4px solid #2563eb;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Quizzes</h6>
                            <h3 class="mb-0" style="color: #1e3a8a;"><?php echo $overall_stats['total_quizzes']; ?></h3>
                        </div>
                        <div class="text-primary">
                            <i class="fas fa-file-alt fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card shadow border-0 h-100" style="border-left: 4px solid #10b981;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Active Instructors</h6>
                            <h3 class="mb-0" style="color: #1e3a8a;"><?php echo $overall_stats['total_instructors']; ?></h3>
                        </div>
                        <div class="text-success">
                            <i class="fas fa-chalkboard-teacher fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card shadow border-0 h-100" style="border-left: 4px solid #f59e0b;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Students Attempted</h6>
                            <h3 class="mb-0" style="color: #1e3a8a;"><?php echo $overall_stats['total_students_attempted']; ?></h3>
                        </div>
                        <div class="text-warning">
                            <i class="fas fa-user-graduate fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card shadow border-0 h-100" style="border-left: 4px solid #ef4444;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Attempts</h6>
                            <h3 class="mb-0" style="color: #1e3a8a;"><?php echo $overall_stats['total_attempts']; ?></h3>
                        </div>
                        <div class="text-danger">
                            <i class="fas fa-mouse-pointer fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Quiz Status Breakdown -->
        <div class="col-md-4 mb-4">
            <div class="card shadow border-0 h-100">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Quiz Status</h5>
                </div>
                <div class="card-body">
                    <?php
                    $status_colors = [
                        'draft' => 'secondary',
                        'published' => 'success',
                        'closed' => 'danger',
                        'archived' => 'info'
                    ];
                    ?>
                    <div class="list-group">
                        <?php foreach ($status_breakdown as $status): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span class="badge bg-<?php echo $status_colors[$status['status']] ?? 'secondary'; ?> me-2">
                                    <?php echo ucfirst($status['status']); ?>
                                </span>
                                <span class="fw-bold"><?php echo $status['count']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="col-md-8 mb-4">
            <div class="card shadow border-0 h-100">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0"><i class="fas fa-clock me-2"></i>Recent Quiz Activity (Last 7 Days)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th style="color: #1e3a8a;">Quiz</th>
                                    <th style="color: #1e3a8a;">Subject</th>
                                    <th style="color: #1e3a8a;">Instructor</th>
                                    <th style="color: #1e3a8a;">Recent Attempts</th>
                                    <th style="color: #1e3a8a;">Last Attempt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_activity as $activity): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($activity['title']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($activity['subject_title']); ?></td>
                                        <td><?php echo htmlspecialchars($activity['instructor_name']); ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo $activity['recent_attempts']; ?></span>
                                        </td>
                                        <td>
                                            <?php if ($activity['last_attempt']): ?>
                                                <?php echo date('M d, H:i', strtotime($activity['last_attempt'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">No activity</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Top Performing Quizzes -->
        <div class="col-md-6 mb-4">
            <div class="card shadow border-0 h-100">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0"><i class="fas fa-trophy me-2"></i>Top Performing Quizzes</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th style="color: #1e3a8a;">Quiz</th>
                                    <th style="color: #1e3a8a;">Attempts</th>
                                    <th style="color: #1e3a8a;">Avg Score</th>
                                    <th style="color: #1e3a8a;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_quizzes as $quiz): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($quiz['title']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($quiz['subject_title']); ?></small>
                                        </td>
                                        <td><?php echo $quiz['attempts']; ?></td>
                                        <td>
                                            <?php
                                            $avg = round($quiz['avg_score'], 1);
                                            $color = $avg >= 70 ? 'success' : ($avg >= 50 ? 'warning' : 'danger');
                                            ?>
                                            <span class="badge bg-<?php echo $color; ?>"><?php echo $avg; ?>%</span>
                                        </td>
                                        <td>
                                            <a href="results.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-chart-bar"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Performing Quizzes (Needs Attention) -->
        <div class="col-md-6 mb-4">
            <div class="card shadow border-0 h-100">
                <div class="card-header card-header-custom" style="background-color: #fef3c7;">
                    <h5 class="card-title mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Quizzes Needing Attention</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($low_performing)): ?>
                        <p class="text-muted mb-0"><i class="fas fa-check-circle me-2 text-success"></i>All quizzes are performing well!</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th style="color: #1e3a8a;">Quiz</th>
                                        <th style="color: #1e3a8a;">Attempts</th>
                                        <th style="color: #1e3a8a;">Avg Score</th>
                                        <th style="color: #1e3a8a;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($low_performing as $quiz): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($quiz['title']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($quiz['subject_title']); ?></small>
                                            </td>
                                            <td><?php echo $quiz['attempts']; ?></td>
                                            <td>
                                                <span class="badge bg-danger"><?php echo $quiz['avg_score']; ?>%</span>
                                            </td>
                                            <td>
                                                <a href="results.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-warning">
                                                    <i class="fas fa-search"></i> Review
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Instructor Performance -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow border-0">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0"><i class="fas fa-chalkboard-teacher me-2"></i>Instructor Quiz Activity</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th style="color: #1e3a8a;">Instructor</th>
                                    <th style="color: #1e3a8a;">Email</th>
                                    <th style="color: #1e3a8a;">Quizzes Created</th>
                                    <th style="color: #1e3a8a;">Total Attempts</th>
                                    <th style="color: #1e3a8a;">Avg Score</th>
                                    <th style="color: #1e3a8a;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($instructor_stats as $instructor): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($instructor['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($instructor['email']); ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo $instructor['quizzes_created']; ?></span>
                                        </td>
                                        <td><?php echo $instructor['total_attempts']; ?></td>
                                        <td>
                                            <?php if ($instructor['avg_score']): ?>
                                                <?php
                                                $avg = round($instructor['avg_score'], 1);
                                                $color = $avg >= 70 ? 'success' : ($avg >= 50 ? 'warning' : 'danger');
                                                ?>
                                                <span class="badge bg-<?php echo $color; ?>"><?php echo $avg; ?>%</span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="../admin/user_performance.php?id=<?php echo $instructor['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-user-circle"></i> Profile
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>