<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

if ($role !== 'student') {
    header('Location: ../' . ($role === 'instructor' ? 'instructor_dashboard.php' : 'index.php'));
    exit;
}

$courseGradesStmt = $pdo->prepare(" 
    SELECT s.title AS subject_title, COALESCE(grader.name, instructor.name) AS instructor_name,
           g.prelim_grade, g.midterm_grade, g.final_grade
    FROM enrollments e
    INNER JOIN subjects s ON s.id = e.subject_id
    LEFT JOIN student_grades g ON g.student_id = e.student_id AND g.subject_id = e.subject_id
    LEFT JOIN users grader ON grader.id = g.instructor_id
    LEFT JOIN users instructor ON instructor.id = s.instructor_id
    WHERE e.student_id = ?
    ORDER BY s.title ASC
");
$courseGradesStmt->execute([$user_id]);
$courseGrades = $courseGradesStmt->fetchAll();

// Fetch assignment grades for students.
$grades = [];
$stmt = $pdo->prepare(" 
    SELECT s.*, a.title AS assignment_title, c.title AS subject_title,
           f.feedback_id AS review_feedback_id, f.maximum_score AS review_maximum_score
    FROM submissions s
    JOIN assignments a ON s.assignment_id = a.id
    JOIN subjects c ON a.subject_id = c.id
    LEFT JOIN assignment_feedback f ON f.submission_id = s.id
    WHERE s.student_id = ? AND s.grade IS NOT NULL
");
$stmt->execute([$user_id]);
$grades = $stmt->fetchAll();

$quizGradesStmt = $pdo->prepare(
    "SELECT qr.id, qr.score,
            COALESCE(NULLIF(qr.max_score, 0),
                (SELECT COALESCE(SUM(points), 0) FROM questions WHERE quiz_id = qr.quiz_id)
            ) AS max_score,
            qr.taken_at, q.title AS quiz_title, s.title AS subject_title
     FROM quiz_results qr
     JOIN quizzes q ON q.id = qr.quiz_id
     JOIN subjects s ON s.id = q.subject_id
     WHERE qr.student_id = ?
       AND q.status = 'published'
       AND q.show_results = 1
       AND qr.grading_status = 'graded'
       AND (q.results_release_at IS NULL OR q.results_release_at <= NOW())
     ORDER BY qr.taken_at DESC"
);
$quizGradesStmt->execute([$user_id]);
$quizGrades = $quizGradesStmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">Grades</h1>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0">Course Grades</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead style="background-color: #eff6ff; border-bottom: 2px solid #2563eb;">
                                <tr>
                                    <th style="color: #1e3a8a;">Subject</th>
                                    <th style="color: #1e3a8a;">Instructor</th>
                                    <th style="color: #1e3a8a;">Prelim</th>
                                    <th style="color: #1e3a8a;">Midterm</th>
                                    <th style="color: #1e3a8a;">Final</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courseGrades as $courseGrade): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($courseGrade['subject_title']); ?></td>
                                        <td><?php echo htmlspecialchars($courseGrade['instructor_name'] ?? 'Not assigned'); ?></td>
                                        <?php foreach (['prelim_grade', 'midterm_grade', 'final_grade'] as $period): ?>
                                            <td><?php echo $courseGrade[$period] !== null ? htmlspecialchars(number_format((float)$courseGrade[$period], 2)) : '<span class="text-muted">Not Yet Graded</span>'; ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($courseGrades)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted">You are not enrolled in any subjects yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom"><h2 class="h5 mb-0">Quiz Results</h2></div>
                <div class="card-body">
                    <?php if (empty($quizGrades)): ?>
                        <p class="text-muted mb-0">Released quiz results will appear here.</p>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                            <thead style="background-color:#eff6ff;border-bottom:2px solid #2563eb;"><tr><th>Quiz</th><th>Subject</th><th>Score</th><th>Submitted</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($quizGrades as $quizGrade): ?>
                                <?php $maximum = (float)$quizGrade['max_score']; $scorePercent = $maximum > 0 ? round(((float)$quizGrade['score'] / $maximum) * 100, 1) : 0; ?>
                                <tr>
                                    <td><?= htmlspecialchars($quizGrade['quiz_title']) ?></td>
                                    <td><?= htmlspecialchars($quizGrade['subject_title']) ?></td>
                                    <td><?= htmlspecialchars((string)$quizGrade['score']) ?> / <?= htmlspecialchars((string)$maximum) ?> points <span class="text-muted">(<?= $scorePercent ?>%)</span></td>
                                    <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($quizGrade['taken_at']))) ?></td>
                                    <td><a class="btn btn-sm btn-outline-primary" href="../quizzes/result.php?id=<?= (int)$quizGrade['id'] ?>">View result</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
