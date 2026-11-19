<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('Only students can submit quizzes.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Submit your answers from the quiz page.');
}

$userId = (int)$_SESSION['user_id'];
$quizId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if (!hash_equals($_SESSION['quiz_csrf'][$quizId] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('This quiz session expired. Return to the quiz and try again.');
}

$stmt = $pdo->prepare(
    "SELECT q.*, s.title AS subject_title
     FROM quizzes q
     JOIN subjects s ON s.id = q.subject_id
     WHERE q.id = ? AND q.status = 'published' AND q.is_available = 1"
);
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();
if (!$quiz) {
    http_response_code(404);
    exit('Quiz not found or not available.');
}

$enrollmentStmt = $pdo->prepare('SELECT id FROM enrollments WHERE student_id = ? AND subject_id = ?');
$enrollmentStmt->execute([$userId, $quiz['subject_id']]);
if (!$enrollmentStmt->fetch()) {
    http_response_code(403);
    exit('You are not enrolled in this subject.');
}

$profileStmt = $pdo->prepare('SELECT section_id, program_id, year_level FROM student_profiles WHERE student_id = ?');
$profileStmt->execute([$userId]);
$profile = $profileStmt->fetch() ?: [];
if (!empty($quiz['section_id']) && (int)$quiz['section_id'] !== (int)($profile['section_id'] ?? 0)) {
    http_response_code(403);
    exit('This quiz is assigned to a different section.');
}

$assignmentStmt = $pdo->prepare(
    'SELECT assignment_type, target_id, year_level FROM quiz_assignments WHERE quiz_id = ?'
);
$assignmentStmt->execute([$quizId]);
$assignments = $assignmentStmt->fetchAll();
if ($assignments) {
    $hasAccess = false;
    foreach ($assignments as $assignment) {
        if ($assignment['assignment_type'] === 'subject' ||
            ($assignment['assignment_type'] === 'section' && (int)$assignment['target_id'] === (int)($profile['section_id'] ?? 0)) ||
            ($assignment['assignment_type'] === 'program' && (int)$assignment['target_id'] === (int)($profile['program_id'] ?? 0)) ||
            ($assignment['assignment_type'] === 'year_level' && $assignment['year_level'] === ($profile['year_level'] ?? null)) ||
            ($assignment['assignment_type'] === 'student' && (int)$assignment['target_id'] === $userId)) {
            $hasAccess = true;
            break;
        }
    }
    if (!$hasAccess) {
        http_response_code(403);
        exit('This quiz has not been assigned to you.');
    }
}

$now = time();
if (!empty($quiz['availability_enabled']) && !empty($quiz['opens_at']) && strtotime($quiz['opens_at']) > $now) {
    http_response_code(403);
    exit('This quiz is not open yet.');
}
if (!empty($quiz['due_date']) && strtotime($quiz['due_date']) < $now) {
    http_response_code(403);
    exit('This quiz has closed.');
}

$attemptStmt = $pdo->prepare('SELECT COUNT(*) FROM quiz_results WHERE quiz_id = ? AND student_id = ?');
$attemptStmt->execute([$quizId, $userId]);
$attemptCount = (int)$attemptStmt->fetchColumn();
$attemptLimit = (int)($quiz['attempt_limit'] ?? 1);
if ($attemptLimit > 0 && $attemptCount >= $attemptLimit) {
    http_response_code(403);
    exit('You have reached the maximum number of attempts for this quiz.');
}

$questionStmt = $pdo->prepare('SELECT * FROM questions WHERE quiz_id = ? ORDER BY position, id');
$questionStmt->execute([$quizId]);
$questions = $questionStmt->fetchAll();
if (!$questions) {
    http_response_code(422);
    exit('This quiz does not have any questions.');
}

$startedAt = (int)($_SESSION['quiz_started'][$quizId] ?? 0);
if ($startedAt === 0) {
    http_response_code(403);
    exit('Start this quiz from its student page before submitting.');
}
$timeSpent = max(0, $now - $startedAt);
$timedOut = !empty($quiz['timer_enabled']) && !empty($quiz['time_limit']) &&
    $timeSpent >= (int)$quiz['time_limit'] * 60;
if ($timedOut && $timeSpent > ((int)$quiz['time_limit'] * 60) + 60) {
    http_response_code(403);
    exit('The time limit for this quiz has passed.');
}

$normalize = static function ($value): string {
    $value = trim((string)$value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
};
$score = 0.0;
$maxScore = 0.0;
$pendingManualGrade = false;
$answers = [];
$responseScores = [];

foreach ($questions as $question) {
    $questionId = (int)$question['id'];
    $key = 'q' . $questionId;
    $userAnswer = $_POST[$key] ?? '';
    if (!is_array($userAnswer)) {
        $userAnswer = trim((string)$userAnswer);
    } else {
        $userAnswer = array_values(array_unique(array_map('strval', $userAnswer)));
    }
    if (in_array($question['type'], ['multiple_choice', 'checkboxes', 'dropdown'], true)) {
        $validOptions = json_decode((string)($question['options'] ?? '[]'), true) ?: [];
        if ($question['type'] === 'checkboxes' && is_array($userAnswer)) {
            $userAnswer = array_values(array_intersect($validOptions, $userAnswer));
        } elseif ($question['type'] !== 'checkboxes' && !in_array($userAnswer, $validOptions, true)) {
            $userAnswer = '';
        }
    }
    $answers[$questionId] = $userAnswer;

    $required = !array_key_exists('is_required', $question) || (int)$question['is_required'] === 1;
    $isBlank = is_array($userAnswer) ? count($userAnswer) === 0 : $userAnswer === '';
    if ($required && $isBlank && !$timedOut) {
        $_SESSION['quiz_error'] = 'Please answer all required questions.';
        header('Location: view.php?id=' . $quizId);
        exit;
    }

    $points = max(0, (float)($question['points'] ?? 1));
    $maxScore += $points;
    $responseScores[$questionId] = 0;
    if ($isBlank) {
        continue;
    }

    $type = $question['type'];
    $correctAnswer = (string)($question['correct_answer'] ?? '');
    if ($type === 'checkboxes') {
        $correctList = json_decode($correctAnswer, true);
        if (is_array($correctList) && is_array($userAnswer)) {
            $expected = array_map($normalize, $correctList);
            $given = array_map($normalize, $userAnswer);
            sort($expected);
            sort($given);
            if ($expected === $given) {
                $score += $points;
                $responseScores[$questionId] = $points;
            }
        }
    } elseif (in_array($type, ['multiple_choice', 'true_false', 'dropdown'], true)) {
        if (!is_array($userAnswer) && $normalize($userAnswer) === $normalize($correctAnswer)) {
            $score += $points;
            $responseScores[$questionId] = $points;
        }
    } elseif (in_array($type, ['short_answer', 'paragraph'], true) &&
        (!empty($question['manual_grading']) || $correctAnswer === '')) {
        $pendingManualGrade = true;
    } elseif ($correctAnswer !== '') {
        if (!is_array($userAnswer) && $normalize($userAnswer) === $normalize($correctAnswer)) {
            $score += $points;
            $responseScores[$questionId] = $points;
        }
    } else {
        $pendingManualGrade = true;
    }
}

$maxAttemptStmt = $pdo->prepare('SELECT MAX(attempt_number) FROM quiz_results WHERE quiz_id = ? AND student_id = ?');
$maxAttemptStmt->execute([$quizId, $userId]);
$attemptNumber = ((int)$maxAttemptStmt->fetchColumn()) + 1;
$needsReview = $pendingManualGrade || !empty($quiz['results_after_review']);

try {
    $insert = $pdo->prepare(
        'INSERT INTO quiz_results
            (quiz_id, student_id, score, max_score, grading_status, attempt_number,
             time_spent, ip_address, answers, response_scores)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $quizId, $userId, $score, $maxScore, $needsReview ? 'pending' : 'graded',
        $attemptNumber, $timeSpent, $_SERVER['REMOTE_ADDR'] ?? null, json_encode($answers),
        json_encode($responseScores)
    ]);
} catch (PDOException $exception) {
    error_log('Quiz submission failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Your quiz could not be submitted. Please try again or contact your instructor.');
}

unset($_SESSION['quiz_started'][$quizId], $_SESSION['quiz_csrf'][$quizId]);
$resultId = (int)$pdo->lastInsertId();
if (!empty($quiz['show_results']) && empty($quiz['results_after_review']) &&
    empty($quiz['results_release_at']) && !$needsReview) {
    header('Location: result.php?id=' . $resultId);
} else {
    header('Location: index.php?submitted=1');
}
exit;
