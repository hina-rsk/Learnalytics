<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/question_validation.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? '';
$quizId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

if ($role !== 'instructor' || $quizId < 1) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT q.*, s.title AS subject_title, s.academic_year_id AS subject_academic_year_id,
            s.semester AS subject_semester, sem.semester_name AS subject_semester_name
     FROM quizzes q
     JOIN subjects s ON s.id = q.subject_id
     LEFT JOIN semesters sem ON sem.semester_id = s.semester_id
     WHERE q.id = ? AND q.instructor_id = ?'
);
$stmt->execute([$quizId, $userId]);
$quiz = $stmt->fetch();
if (!$quiz) {
    http_response_code(404);
    exit('Quiz not found.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request data.']);
        exit;
    }

    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($payload['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.']);
        exit;
    }

    if (($quiz['status'] ?? 'draft') !== 'draft') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Only draft quizzes can be edited.']);
        exit;
    }

    $quizData = $payload['quiz'] ?? [];
    $questions = $payload['questions'] ?? [];
    if (!is_array($quizData) || !is_array($questions)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Quiz information or questions are invalid.']);
        exit;
    }

    $title = trim((string)($quizData['title'] ?? ''));
    $description = trim((string)($quizData['description'] ?? ''));
    $instructions = trim((string)($quizData['instructions'] ?? ''));
    $sectionId = (int)($quizData['section_id'] ?? 0);
    $academicYearId = (int)($quizData['academic_year_id'] ?? 0);
    $semester = in_array(($quizData['semester'] ?? ''), ['1st Semester', '2nd Semester'], true)
        ? $quizData['semester'] : null;
    $isAvailable = !empty($quizData['is_available']) ? 1 : 0;
    $openEnabled = !empty($quizData['availability_enabled']) ? 1 : 0;
    $opensAt = trim((string)($quizData['opens_at'] ?? ''));
    $dueDate = trim((string)($quizData['due_date'] ?? ''));
    $timerEnabled = !empty($quizData['timer_enabled']) ? 1 : 0;
    $requestedTimeLimit = (int)($quizData['time_limit'] ?? 0);
    if ($timerEnabled && ($requestedTimeLimit < 1 || $requestedTimeLimit > 1440)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Set a time limit between 1 and 1440 minutes.']);
        exit;
    }
    $timeLimit = $timerEnabled ? $requestedTimeLimit : null;
    $attemptLimit = max(0, min(100, (int)($quizData['attempt_limit'] ?? 1)));
    $showResults = !empty($quizData['show_results']) ? 1 : 0;
    $afterReview = !empty($quizData['results_after_review']) ? 1 : 0;
    $showCorrectAnswers = !empty($quizData['show_correct_answers']) ? 1 : 0;
    $showFeedback = !empty($quizData['show_feedback']) ? 1 : 0;
    $shuffleQuestions = !empty($quizData['shuffle_questions']) ? 1 : 0;
    $shuffleChoices = !empty($quizData['shuffle_choices']) ? 1 : 0;
    $oneQuestionPerPage = !empty($quizData['one_question_per_page']) ? 1 : 0;
    $assignmentChanged = !empty($payload['assignment_changed']);
    $resultsReleaseAt = trim((string)($quizData['results_release_at'] ?? ''));
    $action = (string)($payload['action'] ?? 'save');

    if (!in_array($action, ['save', 'publish'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unsupported quiz action.']);
        exit;
    }

    if ($title === '' || strlen($title) > 255) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Enter a quiz title of 255 characters or fewer.']);
        exit;
    }

    $formatDate = static function (string $value): ?string {
        if ($value === '') {
            return null;
        }
        $date = DateTime::createFromFormat('Y-m-d\TH:i', $value);
        return $date ? $date->format('Y-m-d H:i:s') : null;
    };
    $opensAtValue = $formatDate($opensAt);
    $dueDateValue = $formatDate($dueDate);
    $resultsReleaseValue = $formatDate($resultsReleaseAt);
    if (($opensAt !== '' && $opensAtValue === null) || ($dueDate !== '' && $dueDateValue === null) ||
        ($resultsReleaseAt !== '' && $resultsReleaseValue === null)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Enter valid availability and results dates.']);
        exit;
    }
    if ($opensAtValue && $dueDateValue && strtotime($dueDateValue) <= strtotime($opensAtValue)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'The closing date must be after the opening date.']);
        exit;
    }

    $validSectionIds = [];
    $sectionStmt = $pdo->prepare(
        'SELECT DISTINCT sec.id
         FROM sections sec
         WHERE sec.id = (SELECT section_id FROM subjects WHERE id = ?)
            OR sec.id IN (
                SELECT sp.section_id
                FROM student_profiles sp
                JOIN enrollments e ON e.student_id = sp.student_id
                WHERE e.subject_id = ? AND sp.section_id IS NOT NULL
            )'
    );
    $sectionStmt->execute([(int)$quiz['subject_id'], (int)$quiz['subject_id']]);
    $validSectionIds = array_map('intval', $sectionStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($sectionId && !in_array($sectionId, $validSectionIds, true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Select a section assigned to this subject.']);
        exit;
    }
    if ($academicYearId > 0) {
        $yearStmt = $pdo->prepare('SELECT id FROM academic_years WHERE id = ?');
        $yearStmt->execute([$academicYearId]);
        if (!$yearStmt->fetchColumn()) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Select a valid academic year.']);
            exit;
        }
    }

    $cleanQuestions = [];
    $allowedTypes = ['multiple_choice', 'checkboxes', 'short_answer', 'paragraph', 'true_false', 'dropdown'];
    foreach (array_values($questions) as $index => $question) {
        if (!is_array($question)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Every question must be valid before publishing.']);
            exit;
        }
        $text = trim((string)($question['text'] ?? ''));
        $type = (string)($question['type'] ?? 'multiple_choice');
        if (!in_array($type, $allowedTypes, true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Choose a supported question type.']);
            exit;
        }
        $options = array_values(array_unique(array_filter(array_map(
            static fn($option) => trim((string)$option),
            is_array($question['options'] ?? null) ? $question['options'] : []
        ), static fn($option) => $option !== '')));
        if ($type === 'true_false') {
            $options = ['True', 'False'];
        }
        $choiceType = in_array($type, ['multiple_choice', 'checkboxes', 'dropdown', 'true_false'], true);
        if ($action === 'publish' && $text === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Every question needs question text before publishing.']);
            exit;
        }
        if ($action === 'publish' && $choiceType && count($options) < 2) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Choice questions need at least two answer choices.']);
            exit;
        }
        $answer = $question['answer'] ?? '';
        if ($type === 'checkboxes') {
            $answer = array_values(array_intersect(
                $options,
                array_map(static fn($value) => trim((string)$value), is_array($answer) ? $answer : [])
            ));
        } elseif ($choiceType) {
            $normalizedAnswer = !is_array($answer) ? trim((string)$answer) : '';
            if ($type === 'true_false' && in_array(strtolower($normalizedAnswer), ['true', 'false'], true)) {
                $normalizedAnswer = ucfirst(strtolower($normalizedAnswer));
            }
            $answer = in_array($normalizedAnswer, $options, true) ? $normalizedAnswer : '';
        } else {
            $answer = is_scalar($answer) ? trim((string)$answer) : '';
        }
        if ($action === 'publish' && $choiceType && ($answer === '' || $answer === [])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Mark the correct answer for each choice question before publishing.']);
            exit;
        }
        $manualGrading = in_array($type, ['short_answer', 'paragraph'], true) &&
            !empty($question['manual_grading']);
        if ($manualGrading) {
            $answer = '';
        }
        $imageUrl = trim((string)($question['image_url'] ?? ''));
        if ($imageUrl !== '' && (!filter_var($imageUrl, FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string)parse_url($imageUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Question images must use a valid HTTP or HTTPS URL.']);
            exit;
        }
        $cleanQuestions[] = [
            'text' => $text,
            'type' => $type,
            'options' => $options,
            'answer' => $answer,
            'manual_grading' => $manualGrading ? 1 : 0,
            'points' => max(0, min(10000, (int)($question['points'] ?? 1))),
            'required' => !empty($question['required']) ? 1 : 0,
            'explanation' => trim((string)($question['explanation'] ?? '')),
            'feedback_correct' => trim((string)($question['feedback_correct'] ?? '')),
            'feedback_incorrect' => trim((string)($question['feedback_incorrect'] ?? '')),
            'image_url' => $imageUrl,
            'position' => $index + 1
        ];
    }
    if ($action === 'publish' && count($cleanQuestions) === 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Add at least one question before publishing.']);
        exit;
    }
    if ($action === 'publish') {
        $validationError = quizQuestionsValidationError($cleanQuestions);
        if ($validationError !== null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $validationError]);
            exit;
        }
    }

    try {
        $pdo->beginTransaction();
        $updateQuiz = $pdo->prepare(
            'UPDATE quizzes SET title = ?, description = ?, instructions = ?, section_id = ?,
                    academic_year_id = ?, semester = ?, is_available = ?, availability_enabled = ?, opens_at = ?,
                    due_date = ?, timer_enabled = ?, time_limit = ?, attempt_limit = ?,
                    show_results = ?, results_after_review = ?, show_correct_answers = ?,
                    show_feedback = ?, results_release_at = ?, shuffle_questions = ?,
                    shuffle_choices = ?, one_question_per_page = ?
             WHERE id = ? AND instructor_id = ?'
        );
        $updateQuiz->execute([
            $title, $description, $instructions, $sectionId ?: null, $academicYearId ?: null,
            $semester, $isAvailable, $openEnabled, $opensAtValue, $dueDateValue, $timerEnabled, $timeLimit,
            $attemptLimit, $showResults, $afterReview, $showCorrectAnswers, $showFeedback,
            $resultsReleaseValue, $shuffleQuestions, $shuffleChoices, $oneQuestionPerPage,
            $quizId, $userId
        ]);

        $pdo->prepare('DELETE FROM questions WHERE quiz_id = ?')->execute([$quizId]);
        $insertQuestion = $pdo->prepare(
            'INSERT INTO questions
                (quiz_id, question_text, type, correct_answer, options, manual_grading, points, is_required,
                 explanation, feedback_correct, feedback_incorrect, image_url, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($cleanQuestions as $question) {
            $answer = is_array($question['answer']) ? json_encode($question['answer']) : $question['answer'];
            $insertQuestion->execute([
                $quizId, $question['text'], $question['type'], $answer,
                json_encode($question['options']), $question['manual_grading'], $question['points'], $question['required'],
                $question['explanation'], $question['feedback_correct'], $question['feedback_incorrect'],
                $question['image_url'] ?: null, $question['position']
            ]);
        }

        $assignmentCount = $pdo->prepare('SELECT COUNT(*) FROM quiz_assignments WHERE quiz_id = ?');
        $assignmentCount->execute([$quizId]);
        if ($assignmentChanged || (int)$assignmentCount->fetchColumn() === 0) {
            if ($assignmentChanged) {
                $pdo->prepare('DELETE FROM quiz_assignments WHERE quiz_id = ?')->execute([$quizId]);
            }
            if ($sectionId > 0) {
                $pdo->prepare(
                    "INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id)
                     VALUES (?, 'section', ?)"
                )->execute([$quizId, $sectionId]);
            } else {
                $pdo->prepare(
                    "INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id)
                     VALUES (?, 'subject', NULL)"
                )->execute([$quizId]);
            }
        }

        if ($action === 'publish') {
            $publish = $pdo->prepare(
                "UPDATE quizzes SET status = 'published', published_at = NOW()
                 WHERE id = ? AND instructor_id = ? AND status = 'draft'"
            );
            $publish->execute([$quizId, $userId]);
        }
        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'status' => $action === 'publish' ? 'published' : 'draft',
            'total_points' => array_sum(array_column($cleanQuestions, 'points')),
            'question_count' => count($cleanQuestions)
        ]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Quiz builder save failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'The quiz could not be saved. Please try again.']);
    }
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$sectionsStmt = $pdo->prepare(
    'SELECT DISTINCT sec.id, sec.name, p.name AS program_name
     FROM sections sec
     LEFT JOIN programs p ON p.id = sec.program_id
     WHERE sec.id = (SELECT section_id FROM subjects WHERE id = ?)
        OR sec.id IN (
            SELECT sp.section_id FROM student_profiles sp
            JOIN enrollments e ON e.student_id = sp.student_id
            WHERE e.subject_id = ? AND sp.section_id IS NOT NULL
        )
     ORDER BY p.name, sec.name'
);
$sectionsStmt->execute([(int)$quiz['subject_id'], (int)$quiz['subject_id']]);
$sections = $sectionsStmt->fetchAll();
$existingAssignmentsStmt = $pdo->prepare(
    "SELECT assignment_type, target_id FROM quiz_assignments WHERE quiz_id = ?"
);
$existingAssignmentsStmt->execute([$quizId]);
$existingAssignments = $existingAssignmentsStmt->fetchAll();
$selectedSectionId = (int)($quiz['section_id'] ?? 0);
if ($selectedSectionId === 0 && count($existingAssignments) === 1 &&
    $existingAssignments[0]['assignment_type'] === 'section') {
    $selectedSectionId = (int)$existingAssignments[0]['target_id'];
}
$assignmentDefaultLabel = 'All enrolled sections';
if ($existingAssignments) {
    $assignmentTypes = array_count_values(array_column($existingAssignments, 'assignment_type'));
    if (!empty($assignmentTypes['section']) && $selectedSectionId === 0) {
        $assignmentDefaultLabel = $assignmentTypes['section'] . ' assigned sections';
    } elseif (!empty($assignmentTypes['student'])) {
        $assignmentDefaultLabel = $assignmentTypes['student'] . ' selected students';
    } elseif (!empty($assignmentTypes['program'])) {
        $assignmentDefaultLabel = $assignmentTypes['program'] . ' assigned programs';
    }
}
$academicYears = $pdo->query('SELECT id, name FROM academic_years ORDER BY start_date DESC')->fetchAll();
$selectedAcademicYearId = (int)($quiz['academic_year_id'] ?? 0) ?: (int)($quiz['subject_academic_year_id'] ?? 0);
$selectedSemester = in_array(($quiz['semester'] ?? ''), ['1st Semester', '2nd Semester'], true)
    ? $quiz['semester']
    : (in_array(($quiz['subject_semester'] ?? ''), ['1st Semester', '2nd Semester'], true)
        ? $quiz['subject_semester'] : ($quiz['subject_semester_name'] ?? ''));

$questionsStmt = $pdo->prepare('SELECT * FROM questions WHERE quiz_id = ? ORDER BY position, id');
$questionsStmt->execute([$quizId]);
$questions = [];
foreach ($questionsStmt->fetchAll() as $row) {
    $correctAnswer = (string)($row['correct_answer'] ?? '');
    if (($row['type'] ?? '') === 'true_false' &&
        in_array(strtolower(trim($correctAnswer)), ['true', 'false'], true)) {
        $correctAnswer = ucfirst(strtolower(trim($correctAnswer)));
    }
    if (($row['type'] ?? '') === 'checkboxes') {
        $decodedAnswer = json_decode($correctAnswer, true);
        $correctAnswer = is_array($decodedAnswer) ? $decodedAnswer : [];
    }
    $questions[] = [
        'text' => $row['question_text'],
        'type' => $row['type'],
        'options' => json_decode($row['options'] ?? '[]', true) ?: [],
        'answer' => $correctAnswer,
        'manual_grading' => (bool)($row['manual_grading'] ?? 0),
        'points' => (int)($row['points'] ?? 1),
        'required' => (bool)($row['is_required'] ?? true),
        'explanation' => $row['explanation'] ?? '',
        'feedback_correct' => $row['feedback_correct'] ?? '',
        'feedback_incorrect' => $row['feedback_incorrect'] ?? '',
        'image_url' => $row['image_url'] ?? ''
    ];
}
if (!$questions) {
    $questions[] = [
        'text' => '', 'type' => 'multiple_choice', 'options' => ['', ''],
        'answer' => '', 'manual_grading' => false, 'points' => 1, 'required' => true, 'explanation' => '',
        'feedback_correct' => '', 'feedback_incorrect' => '', 'image_url' => ''
    ];
}

$dateInput = static function ($value): string {
    return $value ? date('Y-m-d\TH:i', strtotime($value)) : '';
};
$totalPoints = array_sum(array_map(static fn($item) => (int)$item['points'], $questions));
$pageTitle = 'Build Quiz - LearnAlytics';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
.quiz-builder{max-width:1120px;margin:0 auto;padding-bottom:48px;color:#1f2937}
.builder-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin:8px 0 24px}
.builder-heading h1{color:#1e3a8a;font-size:1.75rem;font-weight:750;margin:0}
.builder-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 3px 14px #0f172a0b;margin-bottom:18px}
.builder-card-head{padding:18px 22px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between}
.builder-card-head h2{font-size:1.08rem;color:#1e3a8a;font-weight:700;margin:0}
.builder-card-body{padding:22px}
.builder-card .form-label{font-size:.9rem;font-weight:650;color:#334155}
.builder-card .form-control,.builder-card .form-select{border-color:#cbd5e1;border-radius:8px;min-height:42px}
.builder-card .form-control:focus,.builder-card .form-select:focus{border-color:#3b82f6;box-shadow:0 0 0 3px #3b82f620}
.question-card{border:1px solid #dbe3ee;border-left:5px solid #2563eb;background:#fff;border-radius:12px;padding:20px;margin:16px 0;box-shadow:0 3px 12px #0f172a0d}
.question-top{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.drag-handle{cursor:grab;color:#94a3b8;padding:8px}
.question-text{font-size:1.05rem!important;font-weight:600}
.choice-row{display:flex;align-items:center;gap:9px;margin:8px 0}
.choice-row input[type=text]{flex:1}
.answer-key{margin-top:16px;padding:14px;background:#f0f7ff;border-radius:9px}
.question-actions{display:flex;justify-content:space-between;align-items:center;border-top:1px solid #edf2f7;margin-top:18px;padding-top:12px}
.question-card.dragging{opacity:.45}
.save-status{font-size:.86rem;color:#64748b;min-height:22px}
.sticky-actions{position:sticky;bottom:0;background:#ffffffed;backdrop-filter:blur(8px);padding:12px 0;border-top:1px solid #e2e8f0;z-index:5}
.preview-question{background:#f8fafc;padding:14px;border-radius:8px;margin:12px 0}
@media(max-width:768px){.builder-heading{flex-direction:column}.question-top{align-items:flex-start;flex-wrap:wrap}.builder-card-body{padding:16px}}
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
<div class="quiz-builder">
    <div class="builder-heading">
        <div>
            <a href="index.php" class="small text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Back to quizzes</a>
            <h1 class="mt-2">Add Questions to: <?= htmlspecialchars($quiz['title']) ?></h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($quiz['subject_title']) ?></p>
        </div>
        <button type="button" class="btn btn-outline-primary" id="previewButton"><i class="fas fa-eye me-2"></i>Preview Quiz</button>
    </div>

    <section class="builder-card">
        <div class="builder-card-head">
            <h2><i class="fas fa-list-check me-2"></i>Questions <span class="badge text-bg-primary ms-1" id="questionCount"><?= count($questions) ?></span></h2>
            <span class="fw-semibold text-primary">Total: <span id="totalPoints"><?= $totalPoints ?></span> points</span>
        </div>
        <div class="builder-card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <p class="text-muted small mb-0">Drag questions to reorder them. Changes save automatically.</p>
                <label class="btn btn-sm btn-outline-secondary mb-0"><i class="fas fa-file-import me-1"></i>Import JSON<input type="file" id="importQuestions" accept=".json,application/json" hidden></label>
            </div>
            <div id="questions"></div>
            <button type="button" class="btn btn-primary mt-2" id="addQuestion"><i class="fas fa-plus me-2"></i>Add Question</button>
            <button type="button" class="btn btn-link mt-2" id="reviewAnswerKey"><i class="fas fa-key me-1"></i>Review answer key</button>
        </div>
    </section>

    <div class="sticky-actions d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="save-status" id="saveStatus" role="status">All changes saved</span>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-primary" id="saveQuestions"><i class="fas fa-floppy-disk me-2"></i>Save Questions</button>
            <button type="button" class="btn btn-outline-secondary" id="previewBottom"><i class="fas fa-eye me-2"></i>Preview</button>
            <a href="index.php" class="btn btn-primary"><i class="fas fa-arrow-left me-2"></i>Back to Quizzes</a>
        </div>
    </div>
</div>
</main>

<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5">Student preview</h2><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body" id="previewContent"></div>
        <div class="modal-footer"><span class="text-muted small me-auto">Preview answers are not submitted or saved.</span><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Close preview</button><button class="btn btn-primary" id="simulateSubmit">Submit preview</button></div>
    </div></div>
</div>

<script>
const csrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;
const quizId = <?= (int)$quizId ?>;
const quizDefaults = <?= json_encode([
    'title' => $quiz['title'],
    'description' => $quiz['description'] ?? '',
    'instructions' => $quiz['instructions'] ?? '',
    'section_id' => $selectedSectionId ?: (int)($quiz['section_id'] ?? 0),
    'academic_year_id' => $selectedAcademicYearId,
    'semester' => $selectedSemester,
    'is_available' => (int)($quiz['is_available'] ?? 1),
    'availability_enabled' => (int)($quiz['availability_enabled'] ?? 0),
    'opens_at' => $dateInput($quiz['opens_at'] ?? null),
    'due_date' => $dateInput($quiz['due_date'] ?? null),
    'timer_enabled' => (int)($quiz['timer_enabled'] ?? 0),
    'time_limit' => (string)($quiz['time_limit'] ?? ''),
    'attempt_limit' => (string)($quiz['attempt_limit'] ?? 1),
    'show_results' => (int)($quiz['show_results'] ?? 0),
    'results_after_review' => (int)($quiz['results_after_review'] ?? 0),
    'show_correct_answers' => (int)($quiz['show_correct_answers'] ?? 0),
    'show_feedback' => (int)($quiz['show_feedback'] ?? 0),
    'results_release_at' => $dateInput($quiz['results_release_at'] ?? null),
    'shuffle_questions' => (int)($quiz['shuffle_questions'] ?? 0),
    'shuffle_choices' => (int)($quiz['shuffle_choices'] ?? 0),
    'one_question_per_page' => (int)($quiz['one_question_per_page'] ?? 0)
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const questionTypes = {
    multiple_choice: 'Multiple Choice', checkboxes: 'Checkboxes (Multiple Answers)',
    short_answer: 'Short Answer', paragraph: 'Paragraph',
    true_false: 'True or False', dropdown: 'Dropdown'
};
let questions = <?= json_encode($questions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
let draggedCard = null;
let saveTimer;
let isSaving = false;
let saveAgain = false;
let previewTimerInterval = null;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
}
function questionOptions(q) {
    if (q.type === 'true_false') return ['True', 'False'];
    if (!Array.isArray(q.options)) q.options = [];
    while (q.options.length < 2 && ['multiple_choice','checkboxes','dropdown'].includes(q.type)) q.options.push('');
    return q.options;
}
function answerEditor(q, index) {
    const choice = ['multiple_choice','checkboxes','dropdown','true_false'].includes(q.type);
    if (!choice) {
        const manual = !!q.manual_grading;
        return `<div class="answer-key">
            <div class="form-check mb-2"><input class="form-check-input manual-grading-input" type="checkbox" id="manual-grading-${index}" data-index="${index}" ${manual ? 'checked' : ''}><label class="form-check-label fw-semibold" for="manual-grading-${index}">Grade this response manually</label></div>
            <div class="${manual ? 'd-none' : ''}"><label class="form-label" for="answer-key-${index}">Answer key <span class="text-danger">*</span></label><textarea class="form-control" id="answer-key-${index}" data-field="answer" data-index="${index}" rows="2" placeholder="Enter the expected answer" ${manual ? '' : 'required'}>${escapeHtml(q.answer || '')}</textarea><small class="text-muted">A matching answer is scored automatically.</small></div>
            ${manual ? '<small class="text-muted">This question will remain pending until an instructor reviews the response.</small>' : ''}
        </div>`;
    }
    const options = questionOptions(q);
    return `<div class="answer-key"><strong class="d-block mb-2"><i class="fas fa-key me-1"></i>Mark the correct ${q.type === 'checkboxes' ? 'answers' : 'answer'}</strong>${options.map((option, oi) => {
        const checked = Array.isArray(q.answer) ? q.answer.includes(option) : q.answer === option;
        const inputType = q.type === 'checkboxes' ? 'checkbox' : 'radio';
        return `<div class="form-check mb-1"><input class="form-check-input correct-option" type="${inputType}" name="correct-${index}" data-index="${index}" data-qindex="${index}" data-option="${oi}" ${checked ? 'checked' : ''} ${q.type === 'checkboxes' ? '' : `value="${oi}"`}><label class="form-check-label">${escapeHtml(option || `Choice ${oi + 1}`)}</label></div>`;
    }).join('')}</div>`;
}
function renderQuestions() {
    const host = document.getElementById('questions');
    host.innerHTML = questions.map((q, index) => {
        questionOptions(q);
        const choices = ['multiple_choice','checkboxes','dropdown'].includes(q.type);
        const optionsHtml = q.type === 'true_false' ? '<p class="text-muted small mb-0">Students will choose True or False.</p>' : choices ? `<div class="mb-2"><label class="form-label">Answer choices</label><div class="choices">${q.options.map((option, oi) => `<div class="choice-row"><i class="fas ${q.type === 'checkboxes' ? 'fa-square-check' : 'fa-circle-dot'} text-secondary"></i><input class="form-control choice-input" data-index="${index}" data-option="${oi}" value="${escapeHtml(option)}" placeholder="Choice ${oi + 1}"><button type="button" class="btn btn-sm btn-link text-danger remove-choice" data-index="${index}" data-option="${oi}" aria-label="Remove choice"><i class="fas fa-trash"></i></button></div>`).join('')}</div><button class="btn btn-sm btn-link ps-0 add-choice" type="button" data-index="${index}"><i class="fas fa-plus me-1"></i>Add another option</button></div>` : `<div class="text-muted small">${q.type === 'paragraph' ? 'Students will provide a longer written response.' : 'Students will enter a short response.'}</div>`;
        return `<article class="question-card" draggable="true" data-index="${index}">
            <div class="question-top"><span class="drag-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span><strong>Question ${index + 1}</strong><div class="ms-auto" style="min-width:min(100%,260px)"><select class="form-select type-select" data-index="${index}" aria-label="Question type">${Object.entries(questionTypes).map(([type,label]) => `<option value="${type}" ${q.type===type?'selected':''}>${label}</option>`).join('')}</select></div></div>
            <textarea class="form-control question-text" data-field="text" data-index="${index}" rows="2" placeholder="Enter your question" aria-label="Question text">${escapeHtml(q.text)}</textarea>
            <div class="row g-2 mt-2"><div class="col-md-8"><label class="form-label small" for="image-${index}"><i class="fas fa-image me-1"></i>Question image URL (optional)</label><input class="form-control form-control-sm image-input" id="image-${index}" data-index="${index}" type="url" value="${escapeHtml(q.image_url || '')}" placeholder="https://..."></div><div class="col-md-4"><label class="form-label small" for="points-${index}">Points</label><input class="form-control form-control-sm points-input" id="points-${index}" data-index="${index}" type="number" min="0" max="10000" value="${Number(q.points)||0}"></div></div>
            <div class="mt-3">${optionsHtml}</div>${answerEditor(q,index)}
            <div class="row g-2 mt-2"><div class="col-md-6"><label class="form-label small" for="correct-feedback-${index}">Feedback for a correct answer</label><textarea class="form-control form-control-sm correct-feedback-input" id="correct-feedback-${index}" data-index="${index}" rows="2">${escapeHtml(q.feedback_correct || '')}</textarea></div><div class="col-md-6"><label class="form-label small" for="incorrect-feedback-${index}">Feedback for an incorrect answer</label><textarea class="form-control form-control-sm incorrect-feedback-input" id="incorrect-feedback-${index}" data-index="${index}" rows="2">${escapeHtml(q.feedback_incorrect || '')}</textarea></div></div>
            <div class="mt-2"><label class="form-label small" for="explanation-${index}">General explanation (optional)</label><textarea class="form-control form-control-sm explanation-input" id="explanation-${index}" data-index="${index}" rows="2">${escapeHtml(q.explanation || '')}</textarea></div>
            <div class="question-actions"><label class="form-check mb-0"><input class="form-check-input required-input" type="checkbox" data-index="${index}" ${q.required?'checked':''}><span class="form-check-label">Required</span></label><div><button type="button" class="btn btn-sm btn-outline-secondary duplicate-question" data-index="${index}"><i class="far fa-copy me-1"></i>Duplicate</button> <button type="button" class="btn btn-sm btn-outline-danger delete-question" data-index="${index}"><i class="far fa-trash-can me-1"></i>Delete</button></div></div>
        </article>`;
    }).join('');
    document.getElementById('questionCount').textContent = questions.length;
    const points = questions.reduce((sum, q) => sum + (Number(q.points) || 0), 0);
    document.getElementById('totalPoints').textContent = points;
    return points;
}
function collectQuiz() {
    return {...quizDefaults};
}
function updateQuestionTotals() {
    const points = questions.reduce((sum, q) => sum + (Number(q.points) || 0), 0);
    document.getElementById('questionCount').textContent = questions.length;
    document.getElementById('totalPoints').textContent = points;
    return points;
}
function scheduleSave() {
    document.getElementById('saveStatus').textContent = 'Unsaved changes…';
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveQuiz, 700);
}
async function saveQuiz() {
    clearTimeout(saveTimer);
    if (isSaving) {
        saveAgain = true;
        return;
    }
    isSaving = true;
    const status = document.getElementById('saveStatus');
    status.textContent = 'Saving…';
    status.classList.remove('text-danger');
    const quizPayload = collectQuiz();
    try {
        const response = await fetch(`add_questions.php?id=${quizId}`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({csrf:csrfToken, action:'save', quiz:quizPayload, questions})
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Unable to save changes.');
        status.textContent = `Saved · ${result.question_count} question${result.question_count===1?'':'s'} · ${result.total_points} points`;
    } catch (error) {
        status.textContent = error.message;
        status.classList.add('text-danger');
    } finally {
        isSaving = false;
        if (saveAgain) {
            saveAgain = false;
            saveQuiz();
        }
    }
}
function addQuestion(question = null, after = null) {
    const fresh = question || {text:'',type:'multiple_choice',options:['',''],answer:'',manual_grading:false,points:1,required:true,explanation:'',feedback_correct:'',feedback_incorrect:'',image_url:''};
    questions.splice(after === null ? questions.length : after + 1, 0, JSON.parse(JSON.stringify(fresh)));
    renderQuestions();
    scheduleSave();
    document.querySelectorAll('.question-text')[after === null ? questions.length - 1 : after + 1]?.focus();
}
function showPreview() {
    const data = collectQuiz();
    const body = document.getElementById('previewContent');
    const previewQuestions = questions.map(question => ({...question, options: [...(question.options || [])]}));
    if (data.shuffle_questions) previewQuestions.sort(() => Math.random() - 0.5);
    if (data.shuffle_choices) {
        previewQuestions.forEach(question => {
            question.options = question.type === 'true_false'
                ? ['True', 'False'].sort(() => Math.random() - 0.5)
                : question.options.sort(() => Math.random() - 0.5);
        });
    }
    const previewCards = previewQuestions.map((q,i)=>`<div class="preview-question" data-preview-index="${i}"><div class="d-flex justify-content-between gap-3"><strong>${i+1}. ${escapeHtml(q.text || 'Untitled question')}</strong><span class="text-muted text-nowrap">${Number(q.points)||0} pts</span></div>${q.image_url?`<img class="img-fluid rounded mt-2" src="${escapeHtml(q.image_url)}" alt="Question image">`:''}${['multiple_choice','checkboxes','dropdown','true_false'].includes(q.type)?(q.type==='dropdown'?`<select class="form-select mt-3"><option>Select an answer</option>${questionOptions(q).map(o=>`<option>${escapeHtml(o)}</option>`).join('')}</select>`:questionOptions(q).map(o=>`<div class="form-check mt-2"><input class="form-check-input" type="${q.type==='checkboxes'?'checkbox':'radio'}" name="preview-${i}"><label class="form-check-label">${escapeHtml(o||'Choice')}</label></div>`).join('')):`<textarea class="form-control mt-3" rows="${q.type==='paragraph'?4:2}" placeholder="Your answer"></textarea>`}</div>`).join('');
    body.innerHTML = `<div class="border-top border-4 border-primary rounded-top p-3 mb-3"><h3 class="h4 text-primary">${escapeHtml(data.title || 'Untitled quiz')}</h3><p>${escapeHtml(data.description || '')}</p>${data.instructions ? `<div class="alert alert-info mb-0"><strong>Instructions</strong><br>${escapeHtml(data.instructions)}</div>` : ''}<div class="small text-muted mt-2">${questions.length} questions · ${questions.reduce((sum,q)=>sum+(Number(q.points)||0),0)} points ${data.timer_enabled ? `· <span id="previewTimer">Time limit: ${escapeHtml(data.time_limit)} min</span>` : ''}</div></div>${previewCards}${data.one_question_per_page && previewQuestions.length > 1 ? '<div class="d-flex justify-content-between align-items-center mt-3"><button type="button" class="btn btn-outline-secondary" id="previewPrevious">Previous</button><span id="previewProgress" class="small text-muted"></span><button type="button" class="btn btn-outline-primary" id="previewNext">Next</button></div>' : ''}`;
    if (data.one_question_per_page && previewQuestions.length > 1) {
        let current = 0;
        const cards = [...body.querySelectorAll('.preview-question')];
        const updatePreviewPage = () => {
            cards.forEach((card, index) => card.classList.toggle('d-none', index !== current));
            body.querySelector('#previewProgress').textContent = `Question ${current + 1} of ${cards.length}`;
            body.querySelector('#previewPrevious').disabled = current === 0;
            body.querySelector('#previewNext').classList.toggle('d-none', current === cards.length - 1);
        };
        body.querySelector('#previewPrevious').addEventListener('click', () => { current--; updatePreviewPage(); });
        body.querySelector('#previewNext').addEventListener('click', () => { current++; updatePreviewPage(); });
        updatePreviewPage();
    }
    clearInterval(previewTimerInterval);
    const timer = body.querySelector('#previewTimer');
    if (timer && Number(data.time_limit) > 0) {
        const deadline = Date.now() + Number(data.time_limit) * 60000;
        const updateTimer = () => {
            const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            timer.textContent = `Time limit: ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
        };
        updateTimer();
        previewTimerInterval = window.setInterval(updateTimer, 1000);
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal')).show();
}

document.getElementById('questions').addEventListener('input', event => {
    const index = Number(event.target.dataset.index);
    if (!Number.isInteger(index) || !questions[index]) return;
    if (event.target.matches('[data-field="text"]')) questions[index].text = event.target.value;
    if (event.target.matches('.choice-input')) {
        const optionIndex = Number(event.target.dataset.option);
        const previous = questions[index].options[optionIndex];
        const next = event.target.value;
        questions[index].options[optionIndex] = next;
        if (Array.isArray(questions[index].answer)) {
            questions[index].answer = questions[index].answer.map(answer => answer === previous ? next : answer);
        } else if (questions[index].answer === previous) {
            questions[index].answer = next;
        }
        const answerLabel = document.querySelector(`.correct-option[data-qindex="${index}"][data-option="${optionIndex}"]`);
        if (answerLabel) answerLabel.nextElementSibling.textContent = next || `Choice ${optionIndex + 1}`;
    }
    if (event.target.matches('.points-input')) questions[index].points = Number(event.target.value) || 0;
    if (event.target.matches('.image-input')) questions[index].image_url = event.target.value;
    if (event.target.matches('.explanation-input')) questions[index].explanation = event.target.value;
    if (event.target.matches('.correct-feedback-input')) questions[index].feedback_correct = event.target.value;
    if (event.target.matches('.incorrect-feedback-input')) questions[index].feedback_incorrect = event.target.value;
    if (event.target.matches('[data-field="answer"]')) questions[index].answer = event.target.value;
    if (event.target.matches('.points-input')) updateQuestionTotals();
    scheduleSave();
});
document.getElementById('questions').addEventListener('change', event => {
    const index = Number(event.target.dataset.index);
    if (event.target.matches('.type-select')) {
        const previousType = questions[index].type;
        const nextType = event.target.value;
        const previousIsWritten = ['short_answer', 'paragraph'].includes(previousType);
        const nextIsWritten = ['short_answer', 'paragraph'].includes(nextType);
        const previousAnswer = questions[index].answer;
        const previousManualGrading = !!questions[index].manual_grading;
        questions[index].type = nextType;
        questions[index].answer = nextType === 'checkboxes' ? [] : (previousIsWritten && nextIsWritten ? previousAnswer : '');
        questions[index].manual_grading = nextIsWritten && previousIsWritten ? previousManualGrading : false;
        if (nextType === 'true_false') questions[index].options = ['True','False'];
        else questionOptions(questions[index]);
        renderQuestions(); scheduleSave();
    } else if (event.target.matches('.required-input')) {
        questions[index].required = event.target.checked; scheduleSave();
    } else if (event.target.matches('.manual-grading-input')) {
        questions[index].manual_grading = event.target.checked;
        if (event.target.checked) questions[index].answer = '';
        renderQuestions(); scheduleSave();
    } else if (event.target.matches('.correct-option')) {
        const option = questions[index].options[Number(event.target.dataset.option)];
        if (questions[index].type === 'checkboxes') {
            const answers = Array.isArray(questions[index].answer) ? questions[index].answer : [];
            questions[index].answer = event.target.checked ? [...new Set([...answers, option])] : answers.filter(item => item !== option);
        } else questions[index].answer = option;
        scheduleSave();
    }
});
document.getElementById('questions').addEventListener('click', event => {
    const add = event.target.closest('.add-choice');
    const remove = event.target.closest('.remove-choice');
    const duplicate = event.target.closest('.duplicate-question');
    const removeQuestion = event.target.closest('.delete-question');
    if (add) { const i=Number(add.dataset.index); questions[i].options.push(''); renderQuestions(); scheduleSave(); }
    if (remove) {
        const i=Number(remove.dataset.index), oi=Number(remove.dataset.option);
        if (questions[i].options.length <= 2) return;
        const removed=questions[i].options.splice(oi,1)[0];
        if (Array.isArray(questions[i].answer)) questions[i].answer=questions[i].answer.filter(item=>item!==removed);
        else if (questions[i].answer===removed) questions[i].answer='';
        renderQuestions(); scheduleSave();
    }
    if (duplicate) addQuestion(questions[Number(duplicate.dataset.index)], Number(duplicate.dataset.index));
    if (removeQuestion) {
        if (!window.confirm('Delete this question? This cannot be undone.')) return;
        questions.splice(Number(removeQuestion.dataset.index),1); renderQuestions(); scheduleSave();
    }
});
document.getElementById('questions').addEventListener('dragstart', event => {
    const card=event.target.closest('.question-card'); if (!card) return;
    draggedCard=card; card.classList.add('dragging'); event.dataTransfer.effectAllowed='move';
});
document.getElementById('questions').addEventListener('dragend', () => { if(draggedCard) draggedCard.classList.remove('dragging'); draggedCard=null; });
document.getElementById('questions').addEventListener('dragover', event => {
    event.preventDefault(); const target=event.target.closest('.question-card');
    if (!target || !draggedCard || target===draggedCard) return;
    const rect=target.getBoundingClientRect();
    document.getElementById('questions').insertBefore(draggedCard, event.clientY < rect.top+rect.height/2 ? target : target.nextSibling);
});
document.getElementById('questions').addEventListener('drop', event => {
    event.preventDefault();
    const order=[...document.querySelectorAll('.question-card')].map(card=>questions[Number(card.dataset.index)]);
    questions=order; renderQuestions(); scheduleSave();
});
document.getElementById('addQuestion').addEventListener('click',()=>addQuestion());
document.getElementById('previewButton').addEventListener('click',showPreview);
document.getElementById('previewBottom').addEventListener('click',showPreview);
document.getElementById('previewModal').addEventListener('hidden.bs.modal',()=>clearInterval(previewTimerInterval));
document.getElementById('reviewAnswerKey').addEventListener('click',event=>{
    const panels=[...document.querySelectorAll('.answer-key')];
    const shouldHide=panels.some(panel=>!panel.classList.contains('d-none'));
    panels.forEach(panel=>panel.classList.toggle('d-none',shouldHide));
    event.currentTarget.innerHTML=shouldHide?'<i class="fas fa-key me-1"></i>Review answer key':'<i class="fas fa-eye-slash me-1"></i>Hide answer key';
});
document.getElementById('saveQuestions').addEventListener('click',()=>saveQuiz());
document.getElementById('simulateSubmit').addEventListener('click',()=>window.alert('Preview submitted. This simulation did not save a student attempt or affect grades.'));
document.getElementById('importQuestions').addEventListener('change', async event=>{
    const file=event.target.files[0]; if(!file) return;
    try {
        const parsed=JSON.parse(await file.text());
        const imported=Array.isArray(parsed)?parsed:parsed.questions;
        if(!Array.isArray(imported)) throw new Error('Expected a JSON array or an object with a questions array.');
        const typeMap={'Multiple Choice':'multiple_choice','Checkboxes':'checkboxes','Short Answer':'short_answer','Paragraph':'paragraph','True or False':'true_false','Dropdown':'dropdown'};
        imported.forEach(item=>{
            const manualGrading=!!item.manual_grading;
            questions.push({text:item.text||item.question_text||'',type:typeMap[item.type]||item.type||'multiple_choice',options:item.options||['',''],answer:manualGrading?'':(item.answer||item.correct_answer||''),manual_grading:manualGrading,points:Number(item.points)||1,required:item.required!==false,explanation:item.explanation||'',feedback_correct:item.feedback_correct||'',feedback_incorrect:item.feedback_incorrect||'',image_url:item.image_url||''});
        });
        renderQuestions(); scheduleSave();
    } catch(error) { window.alert(`Could not import questions: ${error.message}`); }
    event.target.value='';
});
renderQuestions();
</script>
<?php include '../includes/footer.php'; ?>
