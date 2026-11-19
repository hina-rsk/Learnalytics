<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/question_validation.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'instructor') {
    header('Location: index.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$quizId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$stmt = $pdo->prepare(
    'SELECT q.*, s.title AS subject_title,
            (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) AS question_count
     FROM quizzes q
     JOIN subjects s ON s.id = q.subject_id
     WHERE q.id = ? AND q.instructor_id = ?'
);
$stmt->execute([$quizId, $userId]);
$quiz = $stmt->fetch();
if (!$quiz) {
    header('Location: index.php?error=not_found');
    exit;
}
if (($quiz['status'] ?? '') !== 'draft') {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['publish_quiz_csrf'])) {
    $_SESSION['publish_quiz_csrf'] = bin2hex(random_bytes(32));
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['publish_quiz_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Reload this page and try again.';
    } else {
        $questionsStmt = $pdo->prepare(
            'SELECT question_text, type, correct_answer, options, manual_grading
             FROM questions WHERE quiz_id = ? ORDER BY position, id'
        );
        $questionsStmt->execute([$quizId]);
        $validationError = quizQuestionsValidationError($questionsStmt->fetchAll(PDO::FETCH_ASSOC));
        if ($validationError !== null) {
            $error = $validationError . ' Complete each question and its answer key, or explicitly choose manual grading for written responses.';
        } else {
            $publish = $pdo->prepare(
                "UPDATE quizzes SET status = 'published', published_at = NOW()
                 WHERE id = ? AND instructor_id = ? AND status = 'draft'"
            );
            $publish->execute([$quizId, $userId]);
            if ($publish->rowCount() === 1) {
                header('Location: index.php?published=1');
                exit;
            }
            $error = 'This quiz could not be published. Reload and verify its current status.';
        }
    }
}

$pageTitle = 'Publish Quiz - LearnAlytics';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="row justify-content-center"><div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header card-header-custom"><h1 class="h5 mb-0">Confirm quiz publication</h1></div>
            <div class="card-body">
                <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <p>Publishing makes this quiz visible to its assigned students according to its availability settings.</p>
                <dl class="row mb-4">
                    <dt class="col-sm-4">Quiz</dt><dd class="col-sm-8"><?= htmlspecialchars($quiz['title']) ?></dd>
                    <dt class="col-sm-4">Subject</dt><dd class="col-sm-8"><?= htmlspecialchars($quiz['subject_title']) ?></dd>
                    <dt class="col-sm-4">Questions</dt><dd class="col-sm-8"><?= (int)$quiz['question_count'] ?></dd>
                    <dt class="col-sm-4">Deadline</dt><dd class="col-sm-8"><?= !empty($quiz['due_date']) ? htmlspecialchars(date('M d, Y g:i A', strtotime($quiz['due_date']))) : 'No closing date' ?></dd>
                </dl>
                <form method="post" class="d-flex gap-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['publish_quiz_csrf']) ?>">
                    <a class="btn btn-outline-secondary" href="add_questions.php?id=<?= $quizId ?>">Go back</a>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-cloud-arrow-up me-2"></i>Publish Quiz</button>
                </form>
            </div>
        </div>
    </div></div>
</main>
<?php include '../includes/footer.php'; ?>
