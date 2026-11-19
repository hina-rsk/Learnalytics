<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$quiz_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'];
$mode = $_GET['mode'] ?? 'interactive';

$stmt = $pdo->prepare("SELECT q.*, c.title AS subject_title, c.instructor_id AS subject_instructor_id FROM quizzes q JOIN subjects c ON q.subject_id = c.id WHERE q.id = ?");
$stmt->execute([$quiz_id]);
$quiz = $stmt->fetch();

if (!$quiz) {
    die("Quiz not found.");
}

// Students can only view published quizzes assigned to them
if ($role === 'student') {
    // Check published status
    if ((isset($quiz['status']) && $quiz['status'] !== 'published') || empty($quiz['is_available'])) {
        header("Location: index.php");
        exit;
    }
    // Check enrollment
    $enrollStmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND subject_id = ?");
    $enrollStmt->execute([$user_id, $quiz['subject_id']]);
    if (!$enrollStmt->fetch()) {
        header("Location: index.php");
        exit;
    }
    $profileStmt = $pdo->prepare("SELECT section_id, program_id, year_level FROM student_profiles WHERE student_id = ?");
    $profileStmt->execute([$user_id]);
    $student_profile = $profileStmt->fetch() ?: [];
    if (!empty($quiz['section_id']) && (int)$quiz['section_id'] !== (int)($student_profile['section_id'] ?? 0)) {
        header("Location: index.php");
        exit;
    }

    // Check specific assignments if any exist
    $assignStmt = $pdo->prepare("SELECT assignment_type, target_id, year_level FROM quiz_assignments WHERE quiz_id = ?");
    $assignStmt->execute([$quiz_id]);
    $assignments = $assignStmt->fetchAll();

    if (!empty($assignments)) {
        $has_access = false;
        foreach ($assignments as $assignment) {
            if ($assignment['assignment_type'] === 'subject') {
                $has_access = true;
                break;
            } elseif ($assignment['assignment_type'] === 'section' && $assignment['target_id'] == ($student_profile['section_id'] ?? null)) {
                $has_access = true;
                break;
            } elseif ($assignment['assignment_type'] === 'program' && $assignment['target_id'] == ($student_profile['program_id'] ?? null)) {
                $has_access = true;
                break;
            } elseif ($assignment['assignment_type'] === 'year_level' && $assignment['year_level'] == ($student_profile['year_level'] ?? null)) {
                $has_access = true;
                break;
            } elseif ($assignment['assignment_type'] === 'student' && $assignment['target_id'] == $user_id) {
                $has_access = true;
                break;
            }
        }
        if (!$has_access) {
            header("Location: index.php");
            exit;
        }
    }
}

// Check if this is a structured quiz
$is_structured = !empty($quiz['description']) && strpos($quiz['description'], 'Quiz Title:') === 0;

// Determine whether instructor/admin can view student quiz results for this quiz
$can_view_results = ($role === 'admin') || ($role === 'instructor' && $quiz['subject_instructor_id'] == $user_id);

$total_questions = 0;
if ($is_structured) {
    preg_match_all('/^Q\d+\./m', $quiz['description'], $matches);
    $total_questions = count($matches[0]);
} else {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE quiz_id = ?");
    $countStmt->execute([$quiz_id]);
    $total_questions = (int)$countStmt->fetchColumn();
}

$quiz_results = [];
if ($can_view_results) {
    $stmt = $pdo->prepare("SELECT qr.*, u.name AS student_name FROM quiz_results qr JOIN users u ON qr.student_id = u.id WHERE qr.quiz_id = ? ORDER BY qr.taken_at DESC");
    $stmt->execute([$quiz_id]);
    $quiz_results = $stmt->fetchAll();
}

if ($is_structured && $mode === 'structured') {
    // Display structured quiz in Google Forms style
    ?>
    <main class="flex-grow-1 py-4">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card shadow-sm">
                        <div class="card-header" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white;">
                            <h4 class="mb-0"><i class="fas fa-file-alt me-2"></i>Structured Quiz View</h4>
                        </div>
                        <div class="card-body">
                            <div class="mb-4">
                                <a href="?id=<?php echo $quiz_id; ?>&mode=interactive" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-edit me-1"></i>Take Interactive Quiz
                                </a>
                            </div>
                            <div class="structured-quiz-content" style="font-family: 'Arial', sans-serif; line-height: 1.6;">
                                <pre style="white-space: pre-wrap; font-family: inherit;"><?php echo htmlspecialchars($quiz['description']); ?></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <?php
    include '../includes/footer.php';
    exit;
}

// Check if this is a structured quiz
$is_structured = !empty($quiz['description']) && strpos($quiz['description'], 'Quiz Title:') === 0;

// If this is a structured quiz and the interactive mode is requested,
// parse the generated description into question objects so students
// can take the quiz interactively even though questions aren't in DB.
if ($is_structured && $mode === 'interactive') {
    $questions = [];
    $lines = preg_split('/\r?\n/', $quiz['description']);
    $qList = [];
    $currentIndex = -1;

    foreach ($lines as $line) {
        $trim = trim($line);
        if (preg_match('/^Q(\d+)\.\s*(.*)$/i', $trim, $m)) {
            $currentIndex++;
            $qList[$currentIndex] = ['question_text' => $m[2], 'options' => []];
        } elseif (preg_match('/^[A-Z]\.\s*(.*)$/', $trim, $m) && $currentIndex >= 0) {
            $qList[$currentIndex]['options'][] = $m[1];
        } elseif (stripos($trim, 'Answer Key:') === 0) {
            // stop collecting options; answer keys processed below
            break;
        }
    }

    // parse answer key lines
    $answerKeys = [];
    $inKey = false;
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($inKey) {
            if ($trim === '') continue;
            // each line is a letter like A, B, C
            $answerKeys[] = strtoupper(substr($trim, 0, 1));
        }
        if (stripos($trim, 'Answer Key:') === 0) {
            $inKey = true;
        }
    }

    // build a questions array similar to DB rows
    foreach ($qList as $i => $q) {
        $opts = $q['options'];
        $correctLetter = $answerKeys[$i] ?? null;
        $correctText = null;
        if ($correctLetter !== null) {
            $idx = ord($correctLetter) - 65;
            if (isset($opts[$idx])) $correctText = $opts[$idx];
        }
        $questions[] = [
            'id' => -($i + 1),
            'question_text' => $q['question_text'],
            'type' => 'multiple_choice',
            'options' => json_encode($opts),
            'correct_answer' => $correctText
        ];
    }
} else {
    // Fetch questions from DB for standard quizzes
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE quiz_id = ? ORDER BY position, id");
    $stmt->execute([$quiz_id]);
    $questions = $stmt->fetchAll();
}
if ($role === 'student' && !empty($quiz['shuffle_questions'])) {
    shuffle($questions);
}
$oneQuestionPerPage = $role === 'student' && !empty($quiz['one_question_per_page']);

// Check if student has already taken this quiz (respecting attempt_limit)
$has_taken = false;
$attempt_count = 0;
$can_take_quiz = true;
if ($role === 'student') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_results WHERE quiz_id = ? AND student_id = ?");
    $stmt->execute([$quiz_id, $user_id]);
    $attempt_count = (int)$stmt->fetchColumn();
    $attempt_limit = isset($quiz['attempt_limit']) ? (int)$quiz['attempt_limit'] : 1;
    $has_taken = $attempt_count > 0;
    // 0 = unlimited; otherwise check if limit is reached
    if ($attempt_limit > 0 && $attempt_count >= $attempt_limit) {
        $can_take_quiz = false;
    }
    if (!empty($quiz['availability_enabled']) && !empty($quiz['opens_at']) && strtotime($quiz['opens_at']) > time()) {
        $can_take_quiz = false;
    }
    if (!empty($quiz['due_date']) && strtotime($quiz['due_date']) < time()) {
        $can_take_quiz = false;
    }
    if (!empty($quiz['timer_enabled']) && !empty($_SESSION['quiz_started'][$quiz_id]) &&
        time() > $_SESSION['quiz_started'][$quiz_id] + ((int)$quiz['time_limit'] * 60)) {
        $can_take_quiz = false;
    }
    if ($can_take_quiz) {
        $_SESSION['quiz_started'][$quiz_id] = $_SESSION['quiz_started'][$quiz_id] ?? time();
        $_SESSION['quiz_csrf'][$quiz_id] = bin2hex(random_bytes(32));
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php" style="color: #2563eb;">Quizzes</a></li>
            <li class="breadcrumb-item"><a href="../subjects/view.php?id=<?php echo $quiz['subject_id']; ?>" style="color: #2563eb;"><?php echo htmlspecialchars($quiz['subject_title']); ?></a></li>
            <li class="breadcrumb-item active" style="color: #1e3a8a;"><?php echo htmlspecialchars($quiz['title']); ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
            <h1 class="h2" style="color: #1e3a8a;"><?php echo htmlspecialchars($quiz['title']); ?></h1>
            <?php if ($role === 'instructor' || $role === 'admin'): ?>
            <a href="add_questions.php?id=<?php echo $quiz_id; ?>" class="btn btn-outline-primary">
                <i class="fas fa-plus me-1"></i>Add Questions
            </a>
            <?php endif; ?>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <?php if ($has_taken): ?>
                <div class="alert alert-info" style="background-color: #dbeafe; color: #1e3a8a; border-color: #2563eb;">
                    <i class="fas fa-info-circle me-2"></i>You have already submitted this quiz.
                </div>
            <?php endif; ?>

            <?php if ($can_view_results && !empty($quiz_results)): ?>
                <div class="card shadow border-0 mb-4">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Student Results</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th style="color: #1e3a8a;">Student</th>
                                        <th style="color: #1e3a8a;">Score</th>
                                        <th style="color: #1e3a8a;">Percentage</th>
                                        <th style="color: #1e3a8a;">Taken At</th>
                                        <th style="color: #1e3a8a;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quiz_results as $resultRow): ?>
                                        <?php $percentage = $total_questions > 0 ? round(($resultRow['score'] / $total_questions) * 100, 1) : 0; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($resultRow['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($resultRow['score']); ?>/<?php echo $total_questions; ?></td>
                                            <td><?php echo $percentage; ?>%</td>
                                            <td><?php echo date('M d, Y H:i', strtotime($resultRow['taken_at'])); ?></td>
                                            <td>
                                                <a href="result.php?id=<?php echo $resultRow['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($questions)): ?>
                <div class="alert alert-warning" style="background-color: #fef3c7; color: #92400e; border-color: #f59e0b;">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php if ($role === 'instructor' || $role === 'admin'): ?>
                        This quiz has no questions yet. <a href="add_questions.php?id=<?php echo $quiz_id; ?>" class="alert-link">Add some questions</a> to make it available.
                    <?php else: ?>
                        This quiz has no questions yet. Please check back later.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="card shadow border-0 mb-4">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Quiz Information</h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">
                            This quiz contains <?php echo count($questions); ?> question(s).
                            <?php if (!empty($quiz['instructions'])): ?><br><strong>Instructions:</strong> <?php echo nl2br(htmlspecialchars($quiz['instructions'])); ?><?php endif; ?>
                            <?php if (!empty($quiz['timer_enabled']) && !empty($quiz['time_limit'])): ?><br><strong>Time limit:</strong> <?php echo (int)$quiz['time_limit']; ?> minutes<?php endif; ?>
                            <?php if (!empty($quiz['due_date'])): ?><br><strong>Deadline:</strong> <?php echo date('M d, Y g:i A', strtotime($quiz['due_date'])); ?><?php endif; ?>
                        </p>
                        <?php if ($role !== 'student'): ?>
                            <p class="card-text"><small class="text-muted">Only students can submit this quiz. Instructors can review answers and scores below.</small></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($role === 'student'): ?>
                <?php if (!$can_take_quiz): ?>
                    <div class="alert" style="background-color: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444;">
                        <i class="fas fa-ban me-2"></i>
                        <?php
                        if (!empty($quiz['availability_enabled']) && !empty($quiz['opens_at']) && strtotime($quiz['opens_at']) > time()) {
                            echo 'This quiz opens on ' . date('M d, Y g:i A', strtotime($quiz['opens_at'])) . '.';
                        } elseif (!empty($quiz['due_date']) && strtotime($quiz['due_date']) < time()) {
                            echo 'This quiz has expired and is no longer available.';
                        } elseif (!empty($quiz['timer_enabled']) && !empty($_SESSION['quiz_started'][$quiz_id]) &&
                            time() > $_SESSION['quiz_started'][$quiz_id] + ((int)$quiz['time_limit'] * 60)) {
                            echo 'The time limit for this quiz has passed.';
                        } else {
                            echo 'You have used all ' . htmlspecialchars($attempt_count) . ' attempt(s) for this quiz.';
                        }
                        ?>
                    </div>
                <?php else: ?>
                <form method="POST" action="submit.php?id=<?php echo $quiz_id; ?>" <?php echo $oneQuestionPerPage ? 'novalidate' : ''; ?>>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['quiz_csrf'][$quiz_id] ?? ''); ?>">
                    <?php if (!empty($quiz['timer_enabled']) && !empty($quiz['time_limit'])): ?>
                        <div class="alert alert-info d-flex justify-content-between align-items-center" id="quizTimer">
                            <span><i class="fas fa-clock me-2"></i>Time remaining</span><strong id="quizTimerValue"></strong>
                        </div>
                    <?php endif; ?>
                    <?php if ($has_taken): ?>
                        <div class="alert alert-warning mb-3" style="background-color: #fef3c7; color: #92400e; border-color: #f59e0b;">
                            <i class="fas fa-redo me-2"></i>
                            You have taken this quiz <?php echo $attempt_count; ?> time(s).
                            <?php if (isset($quiz['attempt_limit']) && $quiz['attempt_limit'] > 0): ?>
                                <?php echo ($quiz['attempt_limit'] - $attempt_count); ?> attempt(s) remaining.
                            <?php else: ?>
                                Unlimited attempts allowed.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($questions as $index => $q): ?>
                        <div class="card shadow border-0 mb-4 student-question" data-question-index="<?php echo $index; ?>">
                            <div class="card-body">
                                <h6 class="card-title mb-3" style="color: #1e3a8a;">Question <?php echo $index + 1; ?>: <?php echo htmlspecialchars($q['question_text']); ?> <span class="text-muted fw-normal">(<?php echo (int)($q['points'] ?? 1); ?> pts)</span></h6>
                                <?php if (!empty($q['image_url'])): ?><img src="<?php echo htmlspecialchars($q['image_url']); ?>" alt="Question image" class="img-fluid rounded mb-3"><?php endif; ?>
                                <?php $isRequired = !isset($q['is_required']) || (int)$q['is_required'] === 1; ?>
                                <?php if ($q['type'] === 'multiple_choice'): ?>
                                    <?php $options = json_decode($q['options'], true); ?>
                                    <?php if (!empty($quiz['shuffle_choices']) && is_array($options)) shuffle($options); ?>
                                    <?php foreach (($options ?: []) as $opt): ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="q<?php echo $q['id']; ?>" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $isRequired ? 'required' : ''; ?> style="border-color: #2563eb; accent-color: #2563eb;">
                                            <label class="form-check-label" style="color: #1e3a8a;"><?php echo htmlspecialchars($opt); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php elseif ($q['type'] === 'checkboxes'): ?>
                                    <?php $options = json_decode($q['options'], true) ?: []; ?>
                                    <?php if (!empty($quiz['shuffle_choices'])) shuffle($options); ?>
                                    <?php foreach ($options as $opt): ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="q<?php echo $q['id']; ?>[]" value="<?php echo htmlspecialchars($opt); ?>" data-required-group="<?php echo $isRequired ? (int)$q['id'] : ''; ?>" style="border-color: #2563eb; accent-color: #2563eb;">
                                            <label class="form-check-label" style="color: #1e3a8a;"><?php echo htmlspecialchars($opt); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php elseif ($q['type'] === 'dropdown'): ?>
                                    <?php $options = json_decode($q['options'], true) ?: []; ?>
                                    <?php if (!empty($quiz['shuffle_choices'])) shuffle($options); ?>
                                    <select name="q<?php echo $q['id']; ?>" class="form-select" <?php echo $isRequired ? 'required' : ''; ?>>
                                        <option value="">Select an answer</option>
                                        <?php foreach ($options as $opt): ?><option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option><?php endforeach; ?>
                                    </select>
                                <?php elseif ($q['type'] === 'true_false'): ?>
                                    <?php $booleanOptions = ['True', 'False']; if (!empty($quiz['shuffle_choices'])) shuffle($booleanOptions); ?>
                                    <?php foreach ($booleanOptions as $option): ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="q<?php echo $q['id']; ?>" value="<?php echo $option; ?>" <?php echo $isRequired ? 'required' : ''; ?> style="border-color: #2563eb; accent-color: #2563eb;">
                                            <label class="form-check-label" style="color: #1e3a8a;"><?php echo $option; ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <textarea name="q<?php echo $q['id']; ?>" class="form-control" rows="<?php echo $q['type'] === 'paragraph' ? 6 : 3; ?>" <?php echo $isRequired ? 'required' : ''; ?> style="border-color: #2563eb;"></textarea>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($oneQuestionPerPage): ?>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <button type="button" class="btn btn-outline-secondary" id="questionPrevious"><i class="fas fa-arrow-left me-2"></i>Previous</button>
                            <span class="text-muted" id="questionProgress"></span>
                            <button type="button" class="btn btn-outline-primary" id="questionNext">Next<i class="fas fa-arrow-right ms-2"></i></button>
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-lg mb-5" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none; font-weight: 600;"><i class="fas fa-paper-plane me-2"></i> Review and Submit Quiz</button>
                </form>
                <script>
                (function () {
                    var quizForm = document.querySelector('form[action^="submit.php"]');
                    if (!quizForm) return;
                    quizForm.addEventListener('submit', function (event) {
                        var questionCards = [...quizForm.querySelectorAll('.student-question')];
                        for (var card of questionCards) {
                            card.style.display = '';
                            var invalidControl = [...card.querySelectorAll('input,select,textarea')]
                                .find(function (control) {
                                    if (control.type === 'checkbox' && control.dataset.requiredGroup) {
                                        var group = [...quizForm.querySelectorAll('[data-required-group="' + control.dataset.requiredGroup + '"]')];
                                        var hasAnswer = group.some(function (choice) { return choice.checked; });
                                        control.setCustomValidity(hasAnswer ? '' : 'Select at least one answer.');
                                        return !hasAnswer;
                                    }
                                    return control.willValidate && !control.checkValidity();
                                });
                            if (invalidControl) {
                                invalidControl.reportValidity();
                                event.preventDefault();
                                return;
                            }
                        }
                        var groupIds = [...new Set([...quizForm.querySelectorAll('[data-required-group]')]
                            .map(function (input) { return input.dataset.requiredGroup; }).filter(Boolean))];
                        for (var groupId of groupIds) {
                            var choices = [...quizForm.querySelectorAll('[data-required-group="' + groupId + '"]')];
                            var firstChoice = choices[0];
                            if (!choices.some(function (choice) { return choice.checked; })) {
                                firstChoice.setCustomValidity('Select at least one answer.');
                                firstChoice.reportValidity();
                                event.preventDefault();
                                return;
                            }
                            firstChoice.setCustomValidity('');
                        }
                    });
                    var questionCards = [...quizForm.querySelectorAll('.student-question')];
                    if (questionCards.length > 0 && <?= $oneQuestionPerPage ? 'true' : 'false' ?>) {
                        var currentQuestion = 0;
                        var showQuestion = function () {
                            questionCards.forEach(function (card, index) {
                                card.style.display = index === currentQuestion ? '' : 'none';
                            });
                            document.getElementById('questionProgress').textContent =
                                'Question ' + (currentQuestion + 1) + ' of ' + questionCards.length;
                            document.getElementById('questionPrevious').disabled = currentQuestion === 0;
                            document.getElementById('questionNext').classList.toggle('d-none', currentQuestion === questionCards.length - 1);
                        };
                        document.getElementById('questionPrevious').addEventListener('click', function () {
                            currentQuestion = Math.max(0, currentQuestion - 1); showQuestion();
                        });
                        document.getElementById('questionNext').addEventListener('click', function () {
                            currentQuestion = Math.min(questionCards.length - 1, currentQuestion + 1); showQuestion();
                        });
                        showQuestion();
                    }
                    var timerValue = document.getElementById('quizTimerValue');
                    if (timerValue) {
                        var deadline = <?= (int)($_SESSION['quiz_started'][$quiz_id] ?? time()) * 1000 ?> + <?= (int)($quiz['time_limit'] ?? 0) ?> * 60000;
                        var timerSubmitted = false;
                        var timerInterval = null;
                        var tick = function () {
                            if (timerSubmitted) return;
                            var seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
                            timerValue.textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
                            if (seconds === 0) {
                                timerSubmitted = true;
                                timerValue.textContent = 'Time is up — submitting…';
                                window.clearInterval(timerInterval);
                                quizForm.submit();
                            }
                        };
                        tick();
                        timerInterval = window.setInterval(tick, 1000);
                    }
                })();
                </script>
                <?php endif; ?>
                <?php else: ?>
                    <!-- Instructors/Admins: read-only view of questions -->
                    <?php foreach ($questions as $index => $q): ?>
                        <div class="card shadow border-0 mb-4">
                            <div class="card-body">
                                <h6 class="card-title mb-3" style="color: #1e3a8a;">Question <?php echo $index + 1; ?>: <?php echo htmlspecialchars($q['question_text']); ?></h6>
                                <?php if ($q['type'] === 'multiple_choice'): ?>
                                    <?php $options = json_decode($q['options'], true); ?>
                                    <?php foreach ($options as $opt): ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" disabled style="border-color: #2563eb;">
                                            <label class="form-check-label" style="color: #1e3a8a;"><?php echo htmlspecialchars($opt); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!empty($q['correct_answer'])): ?>
                                        <div class="mt-2"><small class="text-success">Correct Answer: <?php echo htmlspecialchars($q['correct_answer']); ?></small></div>
                                    <?php endif; ?>
                                <?php elseif ($q['type'] === 'true_false'): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" disabled>
                                        <label class="form-check-label" style="color: #1e3a8a;">True</label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" disabled>
                                        <label class="form-check-label" style="color: #1e3a8a;">False</label>
                                    </div>
                                    <?php if (!empty($q['correct_answer'])): ?>
                                        <div class="mt-2"><small class="text-success">Correct Answer: <?php echo htmlspecialchars($q['correct_answer']); ?></small></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <textarea class="form-control" rows="3" disabled style="border-color: #2563eb;"><?php echo htmlspecialchars($q['correct_answer'] ?? ''); ?></textarea>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
