<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'student') {
    header('Location: ../auth/login.php');
    exit;
}

$student_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT a.title, s.title AS subject_title, sub.grade, sub.feedback, sub.submitted_at
    FROM submissions sub
    JOIN assignments a ON a.id = sub.assignment_id
    JOIN subjects s ON s.id = a.subject_id
    WHERE sub.student_id = ? AND sub.grade IS NOT NULL
      AND sub.is_latest = 1
    ORDER BY sub.submitted_at DESC
");
$stmt->execute([$student_id]);
$assignment_results = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT qr.id, qr.score, qr.taken_at, q.title, q.description, s.title AS subject_title,
           (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) AS question_count
    FROM quiz_results qr
    JOIN quizzes q ON q.id = qr.quiz_id
    JOIN subjects s ON s.id = q.subject_id
    WHERE qr.student_id = ?
    ORDER BY qr.taken_at DESC
");
$stmt->execute([$student_id]);
$quiz_results = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT er.title, er.exam_date, er.total_items, es.score, s.title AS subject_title
    FROM exam_scores es
    JOIN exam_records er ON er.id = es.exam_id
    JOIN subjects s ON s.id = er.subject_id
    JOIN enrollments e ON e.subject_id = er.subject_id AND e.student_id = es.student_id
    WHERE es.student_id = ?
    ORDER BY er.exam_date DESC, er.created_at DESC
");
$stmt->execute([$student_id]);
$exam_results = $stmt->fetchAll();

$has_results = !empty($assignment_results) || !empty($quiz_results) || !empty($exam_results);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;"><i class="fas fa-chart-bar me-2"></i>Assessment Results</h1>
    </div>

    <?php if (!$has_results): ?>
        <div class="card shadow-sm border-0">
            <div class="card-body text-center py-5">
                <i class="fas fa-clipboard-check fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No assessment results yet</h5>
                <p class="text-muted mb-0">Completed and graded assessments will appear here.</p>
            </div>
        </div>
    <?php else: ?>
        <?php if (!empty($assignment_results)): ?>
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom"><h5 class="card-title mb-0">Assignment Results</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Assignment</th><th>Subject</th><th>Score</th><th>Feedback</th><th>Submitted</th></tr></thead>
                        <tbody>
                            <?php foreach ($assignment_results as $result): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($result['title']); ?></td>
                                    <td><?php echo htmlspecialchars($result['subject_title']); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($result['grade']); ?></td>
                                    <td><?php echo htmlspecialchars($result['feedback'] ?: 'No feedback provided.'); ?></td>
                                    <td><?php echo $result['submitted_at'] ? date('M j, Y', strtotime($result['submitted_at'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($quiz_results)): ?>
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom"><h5 class="card-title mb-0">Quiz Results</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Quiz</th><th>Subject</th><th>Score</th><th>Date Taken</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($quiz_results as $result): ?>
                                <?php
                                    $question_count = 0;
                                    if (!empty($result['description']) && strpos($result['description'], 'Quiz Title:') === 0) {
                                        preg_match_all('/^Q\d+\./m', $result['description'], $question_matches);
                                        $question_count = count($question_matches[0]);
                                    } else {
                                        $question_count = (int)$result['question_count'];
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($result['title']); ?></td>
                                    <td><?php echo htmlspecialchars($result['subject_title']); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($result['score']); ?><?php echo $question_count > 0 ? '/' . $question_count : ''; ?></td>
                                    <td><?php echo $result['taken_at'] ? date('M j, Y g:i A', strtotime($result['taken_at'])) : '—'; ?></td>
                                    <td><a class="btn btn-sm btn-outline-primary" href="../quizzes/result.php?id=<?php echo (int)$result['id']; ?>">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($exam_results)): ?>
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom"><h5 class="card-title mb-0">Examination Results</h5></div>
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Examination</th><th>Subject</th><th>Score</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php foreach ($exam_results as $result): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($result['title']); ?></td>
                                    <td><?php echo htmlspecialchars($result['subject_title']); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($result['score']); ?>/<?php echo htmlspecialchars($result['total_items']); ?></td>
                                    <td><?php echo $result['exam_date'] ? date('M j, Y', strtotime($result['exam_date'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php include '../includes/footer.php'; ?>
