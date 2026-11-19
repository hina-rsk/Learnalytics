<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$resultId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? '';
$stmt = $pdo->prepare(
    'SELECT qr.*, q.title AS quiz_title, q.subject_id, q.show_results,
            q.results_after_review, q.show_correct_answers, q.show_feedback,
            q.results_release_at, s.title AS subject_title, s.instructor_id,
            u.name AS student_name
     FROM quiz_results qr
     JOIN quizzes q ON q.id = qr.quiz_id
     JOIN subjects s ON s.id = q.subject_id
     JOIN users u ON u.id = qr.student_id
     WHERE qr.id = ?'
);
$stmt->execute([$resultId]);
$result = $stmt->fetch();
if (!$result) {
    http_response_code(404);
    exit('Result not found.');
}

$isStudent = $role === 'student' && (int)$result['student_id'] === $userId;
$isInstructor = $role === 'instructor' && (int)$result['instructor_id'] === $userId;
$isAdmin = $role === 'admin';
if (!$isStudent && !$isInstructor && !$isAdmin) {
    http_response_code(403);
    exit('You do not have permission to view this result.');
}

$questionsStmt = $pdo->prepare('SELECT * FROM questions WHERE quiz_id = ? ORDER BY position, id');
$questionsStmt->execute([(int)$result['quiz_id']]);
$questions = $questionsStmt->fetchAll();
$studentAnswers = json_decode((string)($result['answers'] ?? '{}'), true) ?: [];
$responseScores = json_decode((string)($result['response_scores'] ?? '{}'), true) ?: [];
$gradingStatus = $result['grading_status'] ?? 'graded';

if ($isStudent && (
    empty($result['show_results']) ||
    ($result['results_after_review'] && $gradingStatus !== 'graded') ||
    (!empty($result['results_release_at']) && strtotime($result['results_release_at']) > time()) ||
    $gradingStatus === 'pending'
)) {
    header('Location: index.php?results_pending=1');
    exit;
}

if (empty($_SESSION['quiz_result_csrf'])) {
    $_SESSION['quiz_result_csrf'] = bin2hex(random_bytes(32));
}
$gradeError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isInstructor) {
    if (!hash_equals($_SESSION['quiz_result_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $gradeError = 'Your session expired. Reload this page and try again.';
    } elseif ($gradingStatus !== 'pending') {
        $gradeError = 'This attempt has already been graded.';
    } else {
        $manualPoints = $_POST['manual_points'] ?? [];
        if (!is_array($manualPoints)) {
            $manualPoints = [];
        }
        $updatedScores = $responseScores;
        $manualQuestions = [];
        foreach ($questions as $question) {
            $type = $question['type'];
            $answerKey = (string)($question['correct_answer'] ?? '');
            $studentAnswer = $studentAnswers[(string)$question['id']] ?? '';
            $hasResponse = is_array($studentAnswer) ? count($studentAnswer) > 0 : trim((string)$studentAnswer) !== '';
            if ($hasResponse && in_array($type, ['short_answer', 'paragraph'], true) &&
                (!empty($question['manual_grading']) || $answerKey === '')) {
                $manualQuestions[(int)$question['id']] = max(0, (float)($question['points'] ?? 1));
            }
        }
        $validated = [];
        foreach ($manualQuestions as $questionId => $pointsAvailable) {
            if (!isset($manualPoints[$questionId]) || !is_numeric($manualPoints[$questionId])) {
                $gradeError = 'Enter a score for every written response.';
                break;
            }
            $awarded = (float)$manualPoints[$questionId];
            if ($awarded < 0 || $awarded > $pointsAvailable) {
                $gradeError = 'A written-response score is outside its allowed point range.';
                break;
            }
            $validated[$questionId] = $awarded;
            $updatedScores[$questionId] = $awarded;
        }
        if ($gradeError === '') {
            $updatedTotal = array_sum(array_map('floatval', $updatedScores));
            $feedback = trim((string)($_POST['instructor_feedback'] ?? ''));
            $update = $pdo->prepare(
                "UPDATE quiz_results
                 SET score = ?, grading_status = 'graded', response_scores = ?, feedback = ?
                 WHERE id = ? AND quiz_id = ? AND grading_status = 'pending'"
            );
            $update->execute([
                $updatedTotal, json_encode($updatedScores), $feedback ?: null,
                $resultId, (int)$result['quiz_id']
            ]);
            header('Location: result.php?id=' . $resultId . '&graded=1');
            exit;
        }
    }
}

$totalPoints = (float)($result['max_score'] ?? 0);
if ($totalPoints <= 0) {
    $totalPoints = array_sum(array_map(static fn($question) => (float)($question['points'] ?? 1), $questions));
}
$percentage = $totalPoints > 0 ? round(((float)$result['score'] / $totalPoints) * 100, 1) : 0;
$releaseAllowed = empty($result['results_release_at']) || strtotime($result['results_release_at']) <= time();
$canReviewAnswers = !empty($result['show_correct_answers']) && $releaseAllowed && $gradingStatus === 'graded';
$canShowFeedback = !empty($result['show_feedback']) && $releaseAllowed;
$pageTitle = 'Quiz Result - LearnAlytics';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="container-fluid" style="max-width:1000px">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 pb-3 border-bottom border-primary">
            <div><a href="index.php" class="small text-decoration-none">Quizzes</a><h1 class="h2 text-primary mt-2 mb-0"><?= htmlspecialchars($result['quiz_title']) ?> · Result</h1></div>
            <a href="index.php" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2"></i>Back to quizzes</a>
        </div>
        <?php if (isset($_GET['graded'])): ?><div class="alert alert-success">Grade saved. The student can now view their results according to the quiz settings.</div><?php endif; ?>
        <?php if ($gradeError !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($gradeError) ?></div><?php endif; ?>
        <section class="card shadow-sm border-0 mb-4">
            <div class="card-body text-center py-4">
                <p class="text-muted mb-1"><?= htmlspecialchars($result['subject_title']) ?> · <?= htmlspecialchars($result['student_name']) ?></p>
                <?php if ($gradingStatus === 'pending'): ?>
                    <span class="badge text-bg-warning fs-6 mb-2">Awaiting instructor review</span>
                    <p class="mb-0">Objective score: <?= htmlspecialchars((string)$result['score']) ?> / <?= htmlspecialchars((string)$totalPoints) ?> points</p>
                <?php else: ?>
                    <div class="display-4 fw-bold text-primary"><?= $percentage ?>%</div>
                    <p class="fs-5 mb-0"><?= htmlspecialchars((string)$result['score']) ?> / <?= htmlspecialchars((string)$totalPoints) ?> points</p>
                <?php endif; ?>
                <small class="text-muted">Submitted <?= htmlspecialchars(date('M d, Y g:i A', strtotime($result['taken_at']))) ?></small>
            </div>
        </section>

        <?php if ($isInstructor && $gradingStatus === 'pending'): ?>
            <form method="post" class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white"><h2 class="h5 mb-0 text-primary">Review written responses</h2></div>
                <div class="card-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['quiz_result_csrf']) ?>">
                    <?php $hasManualResponse = false; foreach ($questions as $question): ?>
                        <?php
                        $qId = (int)$question['id'];
                        $qAnswer = $studentAnswers[(string)$qId] ?? '';
                        $qKey = (string)($question['correct_answer'] ?? '');
                        $isManualResponse = in_array($question['type'], ['short_answer', 'paragraph'], true) &&
                            (!empty($question['manual_grading']) || $qKey === '') &&
                            (is_array($qAnswer) ? count($qAnswer) > 0 : trim((string)$qAnswer) !== '');
                        if (!$isManualResponse) continue;
                        $hasManualResponse = true;
                        ?>
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between"><strong><?= htmlspecialchars($question['question_text']) ?></strong><span class="text-muted"><?= (int)($question['points'] ?? 1) ?> points possible</span></div>
                            <p class="mt-3 mb-2"><?= nl2br(htmlspecialchars(is_array($qAnswer) ? implode(', ', $qAnswer) : (string)$qAnswer)) ?></p>
                            <label class="form-label" for="manual-<?= $qId ?>">Points awarded</label>
                            <input class="form-control" id="manual-<?= $qId ?>" type="number" step="0.5" min="0" max="<?= (float)($question['points'] ?? 1) ?>" name="manual_points[<?= $qId ?>]" value="<?= htmlspecialchars((string)($responseScores[$qId] ?? 0)) ?>" required>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$hasManualResponse): ?><p class="text-muted">No written responses require manual grading. Submit to release the objective score.</p><?php endif; ?>
                    <label class="form-label" for="instructorFeedback">Feedback for student</label>
                    <textarea class="form-control mb-3" id="instructorFeedback" name="instructor_feedback" rows="3"><?= htmlspecialchars($result['feedback'] ?? '') ?></textarea>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-check me-2"></i>Save grade and feedback</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($canShowFeedback && !empty($result['feedback'])): ?>
            <div class="alert alert-info"><strong>Instructor feedback</strong><br><?= nl2br(htmlspecialchars($result['feedback'])) ?></div>
        <?php endif; ?>

        <?php if ($canReviewAnswers || $isInstructor || $isAdmin): ?>
            <h2 class="h5 text-primary mt-4">Answer review</h2>
            <?php foreach ($questions as $index => $question): ?>
                <?php
                $qId = (int)$question['id'];
                $answer = $studentAnswers[(string)$qId] ?? '';
                $key = (string)($question['correct_answer'] ?? '');
                $decodedKey = json_decode($key, true);
                $correctText = is_array($decodedKey) ? implode(', ', $decodedKey) : $key;
                $questionFeedback = (float)($responseScores[$qId] ?? 0) > 0
                    ? ($question['feedback_correct'] ?? '')
                    : ($question['feedback_incorrect'] ?? '');
                if ($questionFeedback === '') {
                    $questionFeedback = $question['explanation'] ?? '';
                }
                ?>
                <article class="card border-0 shadow-sm mb-3"><div class="card-body">
                    <div class="d-flex justify-content-between gap-3"><strong><?= ($index + 1) ?>. <?= htmlspecialchars($question['question_text']) ?></strong><span class="text-muted text-nowrap"><?= htmlspecialchars((string)($responseScores[$qId] ?? 0)) ?> / <?= (int)($question['points'] ?? 1) ?> pts</span></div>
                    <p class="mt-3 mb-1"><span class="text-muted">Your response:</span> <?= nl2br(htmlspecialchars(is_array($answer) ? implode(', ', $answer) : (string)$answer)) ?></p>
                    <?php if ($canReviewAnswers && $correctText !== ''): ?><p class="mb-1 text-success"><strong>Correct answer:</strong> <?= htmlspecialchars($correctText) ?></p><?php endif; ?>
                    <?php if ($canShowFeedback && $questionFeedback !== ''): ?><p class="mb-0 text-muted"><strong>Feedback:</strong> <?= nl2br(htmlspecialchars($questionFeedback)) ?></p><?php endif; ?>
                </div></article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>
<?php include '../includes/footer.php'; ?>
