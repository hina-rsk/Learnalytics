<?php

function quizQuestionValidationError(array $question): ?string
{
    $text = trim((string)($question['text'] ?? $question['question_text'] ?? ''));
    if ($text === '') {
        return 'Every question needs question text.';
    }

    $type = (string)($question['type'] ?? '');
    $allowedTypes = ['multiple_choice', 'checkboxes', 'short_answer', 'paragraph', 'true_false', 'dropdown'];
    if (!in_array($type, $allowedTypes, true)) {
        return 'Choose a supported question type.';
    }

    $options = $question['options'] ?? [];
    if (is_string($options)) {
        $options = json_decode($options, true);
    }
    if (!is_array($options)) {
        $options = [];
    }
    $options = array_values(array_unique(array_filter(
        array_map(static fn($option) => trim((string)$option), $options),
        static fn($option) => $option !== ''
    )));
    if ($type === 'true_false') {
        $options = ['True', 'False'];
    }

    $answer = $question['answer'] ?? $question['correct_answer'] ?? '';
    if (is_string($answer) && $type === 'checkboxes') {
        $decodedAnswer = json_decode($answer, true);
        $answer = is_array($decodedAnswer) ? $decodedAnswer : [];
    }
    if ($type === 'true_false' && is_scalar($answer)) {
        $normalizedAnswer = strtolower(trim((string)$answer));
        if (in_array($normalizedAnswer, ['true', 'false'], true)) {
            $answer = ucfirst($normalizedAnswer);
        }
    }

    if (in_array($type, ['multiple_choice', 'checkboxes', 'dropdown', 'true_false'], true)) {
        if (count($options) < 2) {
            return 'Choice questions need at least two non-empty answer choices.';
        }
        if ($type === 'checkboxes') {
            $answers = is_array($answer)
                ? array_values(array_unique(array_map(static fn($value) => trim((string)$value), $answer)))
                : [];
            if (!$answers) {
                return 'Mark at least one correct answer for each checkbox question.';
            }
            if (array_diff($answers, $options)) {
                return 'Correct answers must match the available answer choices.';
            }
        } elseif (!is_scalar($answer) || !in_array(trim((string)$answer), $options, true)) {
            return 'Select a correct answer from the available choices.';
        }
        return null;
    }

    $manualGrading = !empty($question['manual_grading']);
    $answerText = is_scalar($answer) ? trim((string)$answer) : '';
    if (!$manualGrading && $answerText === '') {
        return 'Add an answer key or explicitly choose manual grading.';
    }
    if ($manualGrading && $answerText !== '') {
        return 'Clear the answer key or turn off manual grading.';
    }

    return null;
}

function quizQuestionsValidationError(array $questions): ?string
{
    if (!$questions) {
        return 'Add at least one question before publishing.';
    }

    foreach (array_values($questions) as $index => $question) {
        if (!is_array($question)) {
            return 'Question ' . ($index + 1) . ' is invalid.';
        }
        $error = quizQuestionValidationError($question);
        if ($error !== null) {
            return 'Question ' . ($index + 1) . ': ' . $error;
        }
    }

    return null;
}
