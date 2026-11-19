<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Only instructors and admins can view results
if ($role !== 'instructor' && $role !== 'admin') {
    header("Location: index.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$quiz_id = (int)$_GET['id'];

// Get quiz details with access control
if ($role === 'instructor') {
    $stmt = $pdo->prepare("
        SELECT q.*, s.title as subject_title, s.instructor_id,
               (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count,
               (SELECT COALESCE(SUM(points), 0) FROM questions WHERE quiz_id = q.id) as total_points
        FROM quizzes q
        JOIN subjects s ON q.subject_id = s.id
        WHERE q.id = ? AND q.instructor_id = ?
    ");
    $stmt->execute([$quiz_id, $user_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT q.*, s.title as subject_title, s.instructor_id, u.name as instructor_name,
               (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count,
               (SELECT COALESCE(SUM(points), 0) FROM questions WHERE quiz_id = q.id) as total_points
        FROM quizzes q
        JOIN subjects s ON q.subject_id = s.id
        LEFT JOIN users u ON q.instructor_id = u.id
        WHERE q.id = ?
    ");
    $stmt->execute([$quiz_id]);
}
$quiz = $stmt->fetch();

if (!$quiz) {
    $_SESSION['error'] = "Quiz not found or access denied.";
    header("Location: index.php");
    exit;
}

// Get all results for this quiz
$stmt = $pdo->prepare("
    SELECT qr.*, u.name as student_name, u.email as student_email, 
           sp.student_id_number, sp.year_level,
           (SELECT MAX(score) FROM quiz_results WHERE quiz_id = qr.quiz_id) as highest_score
    FROM quiz_results qr
    JOIN users u ON qr.student_id = u.id
    LEFT JOIN student_profiles sp ON u.id = sp.student_id
    WHERE qr.quiz_id = ?
    ORDER BY qr.score DESC, qr.taken_at DESC
");
$stmt->execute([$quiz_id]);
$results = $stmt->fetchAll();

// Calculate statistics
$total_attempts = count($results);
$gradedResults = array_values(array_filter($results, static fn($result) => ($result['grading_status'] ?? 'graded') === 'graded'));
$graded_attempts = count($gradedResults);
$total_points = (float)($quiz['total_points'] ?? 0);
$total_points = $total_points > 0 ? $total_points : (float)$quiz['question_count'];
$highest_score = 0;
$lowest_score = $total_points;
$average_score = 0;
$average_percentage = 0;

if ($graded_attempts > 0) {
    $scores = array_column($gradedResults, 'score');
    $highest_score = max($scores);
    $lowest_score = min($scores);
    $average_score = array_sum($scores) / $graded_attempts;
    $average_percentage = array_sum(array_map(static function ($result) use ($total_points) {
        $maxScore = (float)($result['max_score'] ?? 0);
        $maxScore = $maxScore > 0 ? $maxScore : $total_points;
        return $maxScore > 0 ? ((float)$result['score'] / $maxScore) * 100 : 0;
    }, $gradedResults)) / $graded_attempts;
}

// Get grade distribution (numeric ranges)
$grade_distribution = [
    '90-100' => 0,
    '80-89' => 0,
    '70-79' => 0,
    '60-69' => 0,
    '0-59' => 0
];

foreach ($gradedResults as $result) {
    $maxScore = (float)($result['max_score'] ?? 0);
    $maxScore = $maxScore > 0 ? $maxScore : $total_points;
    $percentage = $maxScore > 0 ? ($result['score'] / $maxScore) * 100 : 0;
    if ($percentage >= 90) $grade_distribution['90-100']++;
    elseif ($percentage >= 80) $grade_distribution['80-89']++;
    elseif ($percentage >= 70) $grade_distribution['70-79']++;
    elseif ($percentage >= 60) $grade_distribution['60-69']++;
    else $grade_distribution['0-59']++;
}

// Handle exporting results
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="quiz_results_' . $quiz_id . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'Student Name', 'Email', 'Year Level', 'Score', 'Percentage', 'Grading Status', 'Highest Score', 'Taken At']);
    
    foreach ($results as $result) {
        $maxScore = (float)($result['max_score'] ?? 0);
        $maxScore = $maxScore > 0 ? $maxScore : $total_points;
        $isPending = ($result['grading_status'] ?? 'graded') === 'pending';
        $percentage = !$isPending && $maxScore > 0 ? round(($result['score'] / $maxScore) * 100, 1) : null;
        fputcsv($output, [
            $result['student_id_number'] ?? 'N/A',
            $result['student_name'],
            $result['student_email'],
            $result['year_level'] ?? 'N/A',
            $result['score'] . '/' . $maxScore,
            $percentage === null ? 'Pending review' : $percentage . '%',
            $isPending ? 'Pending' : 'Graded',
            $result['highest_score'],
            date('Y-m-d H:i:s', strtotime($result['taken_at']))
        ]);
    }
    
    fclose($output);
    exit;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php" style="color: #2563eb;">Quizzes</a></li>
                    <li class="breadcrumb-item active" style="color: #1e3a8a;">Results</li>
                </ol>
            </nav>
            <h1 class="h2" style="color: #1e3a8a;">
                <i class="fas fa-chart-bar me-2"></i>Quiz Results
            </h1>
            <p class="text-muted mb-0">
                <?php echo htmlspecialchars($quiz['title']); ?> - <?php echo htmlspecialchars($quiz['subject_title']); ?>
                <?php if ($role === 'admin'): ?>
                    <br><small>Instructor: <?php echo htmlspecialchars($quiz['instructor_name'] ?? 'N/A'); ?></small>
                <?php endif; ?>
            </p>
        </div>
        <div class="btn-group">
            <a href="view.php?id=<?php echo $quiz_id; ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-eye me-1"></i> View Quiz
            </a>
            <a href="?id=<?php echo $quiz_id; ?>&export=csv" class="btn btn-sm" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none;">
                <i class="fas fa-download me-1"></i> Export CSV
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card shadow border-0 h-100" style="border-left: 4px solid #2563eb;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Attempts</h6>
                            <h3 class="mb-0" style="color: #1e3a8a;"><?php echo $total_attempts; ?></h3>
                        </div>
                        <div class="text-primary">
                            <i class="fas fa-users fa-2x"></i>
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
                            <h6 class="text-muted mb-1">Average Score</h6>
                            <h3 class="mb-0" style="color: #1e3a8a;">
                                <?php echo $graded_attempts > 0 ? round($average_score, 1) : 'N/A'; ?>
                            </h3>
                            <small class="text-muted">
                                <?php echo $graded_attempts > 0 ? round($average_percentage, 1) . '%' : ''; ?>
                            </small>
                        </div>
                        <div class="text-success">
                            <i class="fas fa-chart-line fa-2x"></i>
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
                            <h6 class="text-muted mb-1">Highest Score</h6>
                            <h3 class="mb-0" style="color: #1e3a8a;">
                                <?php echo $graded_attempts > 0 ? $highest_score . '/' . $total_points : 'N/A'; ?>
                            </h3>
                            <small class="text-muted">
                                <?php echo $graded_attempts > 0 ? round(($highest_score / max(1, $total_points)) * 100, 1) . '%' : ''; ?>
                            </small>
                        </div>
                        <div class="text-warning">
                            <i class="fas fa-trophy fa-2x"></i>
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
                            <h6 class="text-muted mb-1">Lowest Score</h6>
                            <h3 class="mb-0" style="color: #1e3a8a;">
                                <?php echo $graded_attempts > 0 ? $lowest_score . '/' . $total_points : 'N/A'; ?>
                            </h3>
                            <small class="text-muted">
                                <?php echo $graded_attempts > 0 ? round(($lowest_score / max(1, $total_points)) * 100, 1) . '%' : ''; ?>
                            </small>
                        </div>
                        <div class="text-danger">
                            <i class="fas fa-chart-bar fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Grade Distribution -->
        <div class="col-md-4 mb-4">
            <div class="card shadow border-0 h-100">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0"><i class="fas fa-chart-pie me-2"></i>Grade Distribution</h5>
                </div>
                <div class="card-body">
                    <?php if ($total_attempts == 0): ?>
                        <p class="text-muted">No attempts yet.</p>
                    <?php else: ?>
                        <?php
                        $grade_colors = [
                            '90-100' => 'success',
                            '80-89' => 'primary',
                            '70-79' => 'info',
                            '60-69' => 'warning',
                            '0-59' => 'danger'
                        ];
                        ?>
                        <?php foreach (['90-100', '80-89', '70-79', '60-69', '0-59'] as $range): ?>
                            <?php
                            $percentage = $total_attempts > 0 ? round(($grade_distribution[$range] / $total_attempts) * 100, 1) : 0;
                            $bar_width = $percentage;
                            ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="badge bg-<?php echo $grade_colors[$range]; ?>">
                                        Score: <?php echo $range; ?>%
                                    </span>
                                    <span class="text-muted"><?php echo $grade_distribution[$range]; ?> students (<?php echo $percentage; ?>%)</span>
                                </div>
                                <div class="progress" style="height: 20px; background-color: #e5e7eb;">
                                    <div class="progress-bar bg-<?php echo $grade_colors[$range]; ?>" role="progressbar" 
                                         style="width: <?php echo $bar_width; ?>%;" 
                                         aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Student Results -->
        <div class="col-md-8 mb-4">
            <div class="card shadow border-0 h-100">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0"><i class="fas fa-list me-2"></i>Student Results (<?php echo $total_attempts; ?> attempts)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($results)): ?>
                        <div class="alert alert-info" style="background-color: #dbeafe; color: #1e3a8a; border-color: #2563eb;">
                            <i class="fas fa-info-circle me-2"></i> No students have attempted this quiz yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th style="color: #1e3a8a;">Student</th>
                                        <th style="color: #1e3a8a;">Student ID</th>
                                        <th style="color: #1e3a8a;">Year</th>
                                        <th style="color: #1e3a8a;">Score</th>
                                        <th style="color: #1e3a8a;">Percentage</th>
                                        <th style="color: #1e3a8a;">Grade</th>
                                        <th style="color: #1e3a8a;">Grading Status</th>
                                        <th style="color: #1e3a8a;">Taken At</th>
                                        <th style="color: #1e3a8a;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $result): ?>
                                        <?php
                                        $maxScore = (float)($result['max_score'] ?? 0);
                                        $maxScore = $maxScore > 0 ? $maxScore : $total_points;
                                        $isPending = ($result['grading_status'] ?? 'graded') === 'pending';
                                        $percentage = !$isPending && $maxScore > 0 ? round(($result['score'] / $maxScore) * 100, 1) : null;
                                        if ($isPending) {
                                            $score_range = 'Pending';
                                            $grade_color = 'warning';
                                        } elseif ($percentage >= 90) {
                                            $score_range = '90-100%';
                                            $grade_color = 'success';
                                        } elseif ($percentage >= 80) {
                                            $score_range = '80-89%';
                                            $grade_color = 'primary';
                                        } elseif ($percentage >= 70) {
                                            $score_range = '70-79%';
                                            $grade_color = 'info';
                                        } elseif ($percentage >= 60) {
                                            $score_range = '60-69%';
                                            $grade_color = 'warning';
                                        } else {
                                            $score_range = '0-59%';
                                            $grade_color = 'danger';
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($result['student_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($result['student_email']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($result['student_id_number'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($result['year_level'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="fw-bold"><?php echo $result['score']; ?></span> / <?php echo $maxScore; ?>
                                            </td>
                                            <td>
                                                <?php if ($percentage === null): ?><span class="text-muted">Pending</span><?php else: ?><span class="badge bg-<?php echo $grade_color; ?>"><?php echo $percentage; ?>%</span><?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $grade_color; ?>"><?php echo $score_range; ?></span>
                                            </td>
                                            <td><?= $isPending ? '<span class="badge text-bg-warning">Needs review</span>' : '<span class="badge text-bg-success">Graded</span>' ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($result['taken_at'])); ?></td>
                                            <td>
                                                <a href="result.php?id=<?php echo $result['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i> View
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
</main>

<?php include '../includes/footer.php'; ?>