<?php
/**
 * LearnAlytics - Centralized Teacher Analytics & MPS Calculations
 * Single source of truth for MPS formulas, normalization, and status evaluations.
 */

/**
 * Calculate percentage from raw score and total points.
 * Returns float rounded to 1 decimal place or null if invalid/no points.
 */
function calculatePercentage($score, $totalPoints) {
    if ($score === null || $totalPoints === null || (float)$totalPoints <= 0) {
        return null;
    }
    return round(((float)$score / (float)$totalPoints) * 100, 1);
}

/**
 * Determine risk level string from MPS and threshold.
 */
function getRiskLevel($mps, $threshold = 75.0) {
    if ($mps === null) {
        return 'No Data';
    }
    if ($mps >= $threshold) {
        return 'Satisfactory';
    }
    $highRiskCutoff = max(40.0, (float)$threshold - 15.0);
    if ($mps < $highRiskCutoff) {
        return 'High Risk';
    }
    return 'At Risk';
}

/**
 * Get display info (label, CSS classes, color, icon) for a given MPS.
 */
function getPerformanceStatusInfo($mps, $threshold = 75.0) {
    if ($mps === null) {
        return [
            'label'      => 'No Data',
            'badge'      => '<span class="badge" style="background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; font-size:0.75rem; padding:0.4em 0.7em;"><i class="fas fa-minus-circle me-1"></i>No Data</span>',
            'level'      => 'no_data',
            'color'      => '#64748b',
            'badge_bg'   => '#f1f5f9',
            'badge_text' => '#64748b'
        ];
    }

    $mps = (float)$mps;
    $threshold = (float)$threshold;

    if ($mps >= 90.0) {
        return [
            'label'      => 'Excellent',
            'badge'      => '<span class="badge" style="background:#dcfce7; color:#166534; font-size:0.75rem; padding:0.4em 0.7em;"><i class="fas fa-check-circle me-1"></i>Excellent</span>',
            'level'      => 'excellent',
            'color'      => '#16a34a',
            'badge_bg'   => '#dcfce7',
            'badge_text' => '#166534'
        ];
    }

    if ($mps >= 80.0) {
        return [
            'label'      => 'Good',
            'badge'      => '<span class="badge" style="background:#dbeafe; color:#1e40af; font-size:0.75rem; padding:0.4em 0.7em;"><i class="fas fa-thumbs-up me-1"></i>Good</span>',
            'level'      => 'good',
            'color'      => '#2563eb',
            'badge_bg'   => '#dbeafe',
            'badge_text' => '#1e40af'
        ];
    }

    if ($mps >= $threshold) {
        return [
            'label'      => 'Satisfactory',
            'badge'      => '<span class="badge" style="background:#e0f2fe; color:#0369a1; font-size:0.75rem; padding:0.4em 0.7em;"><i class="fas fa-check me-1"></i>Satisfactory</span>',
            'level'      => 'satisfactory',
            'color'      => '#0284c7',
            'badge_bg'   => '#e0f2fe',
            'badge_text' => '#0369a1'
        ];
    }

    $highRiskCutoff = max(40.0, $threshold - 15.0);
    if ($mps >= $highRiskCutoff) {
        return [
            'label'      => 'At Risk',
            'badge'      => '<span class="badge" style="background:#fef3c7; color:#92400e; font-size:0.75rem; padding:0.4em 0.7em;"><i class="fas fa-exclamation-triangle me-1"></i>At Risk</span>',
            'level'      => 'at_risk',
            'color'      => '#d97706',
            'badge_bg'   => '#fef3c7',
            'badge_text' => '#92400e'
        ];
    }

    return [
        'label'      => 'High Risk',
        'badge'      => '<span class="badge" style="background:#fee2e2; color:#991b1b; font-size:0.75rem; padding:0.4em 0.7em;"><i class="fas fa-exclamation-circle me-1"></i>High Risk</span>',
        'level'      => 'high_risk',
        'color'      => '#dc2626',
        'badge_bg'   => '#fee2e2',
        'badge_text' => '#991b1b'
    ];
}

/**
 * Helper to render the badge HTML for an MPS score.
 */
function getPerformanceBadge($mps, $threshold = 75.0) {
    $info = getPerformanceStatusInfo($mps, $threshold);
    return $info['badge'];
}

/**
 * Calculate total available points for an online quiz.
 */
function getQuizTotalPoints($pdo, $quizId, $description = null) {
    static $cache = [];
    if (isset($cache[$quizId])) {
        return $cache[$quizId];
    }

    // 1. Check questions table
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM questions WHERE quiz_id = ?");
    $stmt->execute([$quizId]);
    $count = (int)$stmt->fetchColumn();

    // 2. If 0, check structured description format (e.g. Q1. ...)
    if ($count === 0 && !empty($description) && stripos($description, 'Quiz Title:') === 0) {
        preg_match_all('/^Q\d+\./m', $description, $matches);
        $count = count($matches[0]);
    }

    // 3. Fallback to highest recorded score or 1
    if ($count === 0) {
        $stmt = $pdo->prepare("SELECT MAX(score) FROM quiz_results WHERE quiz_id = ?");
        $stmt->execute([$quizId]);
        $maxScore = (int)$stmt->fetchColumn();
        $count = $maxScore > 0 ? $maxScore : 1;
    }

    $cache[$quizId] = max(1, $count);
    return $cache[$quizId];
}

/**
 * Fetch and normalize all valid assessment records (Online Quizzes + Manual Exams).
 * Every item in the returned array adheres to Requirement 10:
 * - assessment_id (string: 'quiz_X' or 'manual_Y')
 * - title
 * - type ('online' or 'manual')
 * - subject_id
 * - section_id
 * - student_id
 * - score
 * - total_points
 * - percentage
 * - date
 */
function getTeacherNormalizedAssessments($pdo, $instructorId, $filters = []) {
    $filter_subject = !empty($filters['subject_id']) ? (int)$filters['subject_id'] : null;
    $filter_section = !empty($filters['section_id']) ? (int)$filters['section_id'] : null;
    $filter_quiz    = !empty($filters['quiz_id']) ? (int)$filters['quiz_id'] : null;

    $normalized = [];

    // --- 1. Online Quizzes ---
    $sqlQuiz = "
        SELECT 
            qr.id as result_id,
            qr.quiz_id,
            qr.student_id,
            qr.score,
            qr.max_score,
            qr.grading_status,
            qr.attempt_number,
            qr.taken_at,
            q.title as quiz_title,
            q.description as quiz_description,
            q.subject_id,
            s.title as subject_title,
            COALESCE(q.section_id, s.section_id) as subject_section_id,
            COALESCE(sec.name, 'Unassigned') as section_name,
            u.name as student_name,
            u.email as student_email
        FROM quiz_results qr
        INNER JOIN quizzes q ON qr.quiz_id = q.id
        INNER JOIN subjects s ON q.subject_id = s.id
        INNER JOIN users u ON qr.student_id = u.id
        LEFT JOIN sections sec ON COALESCE(q.section_id, s.section_id) = sec.id
        WHERE s.instructor_id = ? AND qr.grading_status = 'graded'
    ";
    $paramsQuiz = [$instructorId];

    if ($filter_subject) {
        $sqlQuiz .= " AND s.id = ?";
        $paramsQuiz[] = $filter_subject;
    }
    if ($filter_section) {
        $sqlQuiz .= " AND s.section_id = ?";
        $paramsQuiz[] = $filter_section;
    }
    if ($filter_quiz) {
        $sqlQuiz .= " AND q.id = ?";
        $paramsQuiz[] = $filter_quiz;
    }

    $sqlQuiz .= " ORDER BY qr.taken_at DESC";
    $stmtQuiz = $pdo->prepare($sqlQuiz);
    $stmtQuiz->execute($paramsQuiz);
    $quizRows = $stmtQuiz->fetchAll(PDO::FETCH_ASSOC);

    // Keep latest attempt per student per quiz
    $seenStudentQuizzes = [];
    foreach ($quizRows as $row) {
        $key = $row['quiz_id'] . '_' . $row['student_id'];
        if (isset($seenStudentQuizzes[$key])) {
            continue;
        }
        $seenStudentQuizzes[$key] = true;

        $totalPoints = (float)($row['max_score'] ?? 0);
        $totalPoints = $totalPoints > 0 ? $totalPoints : getQuizTotalPoints($pdo, $row['quiz_id'], $row['quiz_description']);
        $score = (float)$row['score'];
        $pct = calculatePercentage($score, $totalPoints);

        $normalized[] = [
            'assessment_id'     => 'quiz_' . $row['quiz_id'],
            'raw_assessment_id' => (int)$row['quiz_id'],
            'title'             => $row['quiz_title'],
            'type'              => 'online',
            'type_label'        => 'Online',
            'subject_id'        => (int)$row['subject_id'],
            'subject_title'     => $row['subject_title'],
            'section_id'        => $row['subject_section_id'] ? (int)$row['subject_section_id'] : null,
            'section_name'      => $row['section_name'],
            'student_id'        => (int)$row['student_id'],
            'student_name'      => $row['student_name'],
            'student_email'     => $row['student_email'],
            'score'             => $score,
            'total_points'      => (float)$totalPoints,
            'percentage'        => $pct,
            'date'              => $row['taken_at']
        ];
    }

    // --- 2. Manual Exams (omitted if specific online quiz filter is active) ---
    if (!$filter_quiz) {
        $sqlManual = "
            SELECT 
                es.id as score_id,
                es.exam_id,
                es.student_id,
                es.score,
                es.recorded_at,
                es.updated_at,
                er.title as exam_title,
                er.total_items,
                er.subject_id,
                er.section_id as exam_section_id,
                s.title as subject_title,
                COALESCE(sec.name, s_sec.name, 'Unassigned') as section_name,
                COALESCE(er.section_id, s.section_id) as resolved_section_id,
                u.name as student_name,
                u.email as student_email
            FROM exam_scores es
            INNER JOIN exam_records er ON es.exam_id = er.id
            INNER JOIN subjects s ON er.subject_id = s.id
            INNER JOIN users u ON es.student_id = u.id
            LEFT JOIN sections sec ON er.section_id = sec.id
            LEFT JOIN sections s_sec ON s.section_id = s_sec.id
            WHERE er.instructor_id = ?
        ";
        $paramsManual = [$instructorId];

        if ($filter_subject) {
            $sqlManual .= " AND er.subject_id = ?";
            $paramsManual[] = $filter_subject;
        }
        if ($filter_section) {
            $sqlManual .= " AND (er.section_id = ? OR (er.section_id IS NULL AND s.section_id = ?))";
            $paramsManual[] = $filter_section;
            $paramsManual[] = $filter_section;
        }

        $sqlManual .= " ORDER BY COALESCE(es.recorded_at, es.updated_at) DESC";
        $stmtManual = $pdo->prepare($sqlManual);
        $stmtManual->execute($paramsManual);
        $manualRows = $stmtManual->fetchAll(PDO::FETCH_ASSOC);

        foreach ($manualRows as $row) {
            $totalPoints = (int)$row['total_items'] > 0 ? (int)$row['total_items'] : 100;
            $score = (float)$row['score'];
            $pct = calculatePercentage($score, $totalPoints);

            $normalized[] = [
                'assessment_id'     => 'manual_' . $row['exam_id'],
                'raw_assessment_id' => (int)$row['exam_id'],
                'title'             => $row['exam_title'],
                'type'              => 'manual',
                'type_label'        => 'Manual',
                'subject_id'        => (int)$row['subject_id'],
                'subject_title'     => $row['subject_title'],
                'section_id'        => $row['resolved_section_id'] ? (int)$row['resolved_section_id'] : null,
                'section_name'      => $row['section_name'],
                'student_id'        => (int)$row['student_id'],
                'student_name'      => $row['student_name'],
                'student_email'     => $row['student_email'],
                'score'             => $score,
                'total_points'      => (float)$totalPoints,
                'percentage'        => $pct,
                'date'              => $row['recorded_at'] ?: $row['updated_at']
            ];
        }
    }

    return $normalized;
}

/**
 * Fetch all available assessments (quizzes and manual exams) in the teacher's scope,
 * including those without any student submissions yet.
 */
function getTeacherExamsList($pdo, $instructorId, $filters = [], $normalizedAssessments = []) {
    $filter_subject = !empty($filters['subject_id']) ? (int)$filters['subject_id'] : null;
    $filter_section = !empty($filters['section_id']) ? (int)$filters['section_id'] : null;
    $filter_quiz    = !empty($filters['quiz_id']) ? (int)$filters['quiz_id'] : null;

    $exams = [];

    // 1. Online Quizzes
    $sqlQuiz = "
        SELECT 
            q.id,
            q.title,
            q.subject_id,
            s.title as subject_title,
            s.section_id as subject_section_id,
            COALESCE(sec.name, 'Unassigned') as section_name,
            q.description
        FROM quizzes q
        INNER JOIN subjects s ON q.subject_id = s.id
        LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE s.instructor_id = ?
    ";
    $paramsQuiz = [$instructorId];

    if ($filter_subject) {
        $sqlQuiz .= " AND s.id = ?";
        $paramsQuiz[] = $filter_subject;
    }
    if ($filter_section) {
        $sqlQuiz .= " AND s.section_id = ?";
        $paramsQuiz[] = $filter_section;
    }
    if ($filter_quiz) {
        $sqlQuiz .= " AND q.id = ?";
        $paramsQuiz[] = $filter_quiz;
    }

    $sqlQuiz .= " ORDER BY q.id DESC";
    $stmtQuiz = $pdo->prepare($sqlQuiz);
    $stmtQuiz->execute($paramsQuiz);
    $quizzes = $stmtQuiz->fetchAll(PDO::FETCH_ASSOC);

    foreach ($quizzes as $q) {
        $uid = 'quiz_' . $q['id'];
        $stats = calculateExamMPS($uid, $normalizedAssessments);

        $exams[] = [
            'uid'            => $uid,
            'id'             => (int)$q['id'],
            'title'          => $q['title'],
            'type'           => 'online',
            'type_label'     => 'Online',
            'subject_id'     => (int)$q['subject_id'],
            'subject_title'  => $q['subject_title'],
            'section_id'     => $q['subject_section_id'] ? (int)$q['subject_section_id'] : null,
            'section_name'   => $q['section_name'],
            'attempts_count' => $stats['attempts_count'],
            'mps'            => $stats['mps'],
            'highest_score'  => $stats['highest_score'],
            'lowest_score'   => $stats['lowest_score'],
        ];
    }

    // 2. Manual Exam Records (omitted if specific online quiz filter is active)
    if (!$filter_quiz) {
        $sqlManual = "
            SELECT 
                er.id,
                er.title,
                er.total_items,
                er.subject_id,
                er.section_id as exam_section_id,
                s.title as subject_title,
                COALESCE(sec.name, s_sec.name, 'Unassigned') as section_name,
                COALESCE(er.section_id, s.section_id) as resolved_section_id
            FROM exam_records er
            INNER JOIN subjects s ON er.subject_id = s.id
            LEFT JOIN sections sec ON er.section_id = sec.id
            LEFT JOIN sections s_sec ON s.section_id = s_sec.id
            WHERE er.instructor_id = ?
        ";
        $paramsManual = [$instructorId];

        if ($filter_subject) {
            $sqlManual .= " AND er.subject_id = ?";
            $paramsManual[] = $filter_subject;
        }
        if ($filter_section) {
            $sqlManual .= " AND (er.section_id = ? OR (er.section_id IS NULL AND s.section_id = ?))";
            $paramsManual[] = $filter_section;
            $paramsManual[] = $filter_section;
        }

        $sqlManual .= " ORDER BY er.id DESC";
        $stmtManual = $pdo->prepare($sqlManual);
        $stmtManual->execute($paramsManual);
        $manualExams = $stmtManual->fetchAll(PDO::FETCH_ASSOC);

        foreach ($manualExams as $m) {
            $uid = 'manual_' . $m['id'];
            $stats = calculateExamMPS($uid, $normalizedAssessments);

            $exams[] = [
                'uid'            => $uid,
                'id'             => (int)$m['id'],
                'title'          => $m['title'],
                'type'           => 'manual',
                'type_label'     => 'Manual',
                'subject_id'     => (int)$m['subject_id'],
                'subject_title'  => $m['subject_title'],
                'section_id'     => $m['resolved_section_id'] ? (int)$m['resolved_section_id'] : null,
                'section_name'   => $m['section_name'],
                'attempts_count' => $stats['attempts_count'],
                'mps'            => $stats['mps'],
                'highest_score'  => $stats['highest_score'],
                'lowest_score'   => $stats['lowest_score'],
            ];
        }
    }

    return $exams;
}

/**
 * Calculate MPS and metrics for a specific exam from normalized assessments.
 */
function calculateExamMPS($examUid, $normalizedAssessments) {
    $scores = [];
    foreach ($normalizedAssessments as $item) {
        if ($item['assessment_id'] === $examUid && $item['percentage'] !== null) {
            $scores[] = (float)$item['percentage'];
        }
    }

    if (empty($scores)) {
        return [
            'attempts_count' => 0,
            'mps'            => null,
            'highest_score'  => null,
            'lowest_score'   => null,
        ];
    }

    return [
        'attempts_count' => count($scores),
        'mps'            => round(array_sum($scores) / count($scores), 1),
        'highest_score'  => max($scores),
        'lowest_score'   => min($scores),
    ];
}

/**
 * Calculate student MPS across their assessments in the filtered scope.
 */
function calculateStudentMPS($studentId, $normalizedAssessments) {
    $scores = [];
    foreach ($normalizedAssessments as $item) {
        if ((int)$item['student_id'] === (int)$studentId && $item['percentage'] !== null) {
            $scores[] = (float)$item['percentage'];
        }
    }

    if (empty($scores)) {
        return null;
    }

    return round(array_sum($scores) / count($scores), 1);
}

/**
 * Calculate subject performance from enrolled students and normalized assessments.
 * Implements Scenario D: Student A = 80%, Student B = 70%, Student C = 90% -> Subject MPS = 80%.
 */
function calculateSubjectMPS($subjectId, $normalizedAssessments) {
    $studentAssessments = [];
    $rawScores = [];

    foreach ($normalizedAssessments as $item) {
        if ((int)$item['subject_id'] === (int)$subjectId && $item['percentage'] !== null) {
            $studentId = $item['student_id'];
            $studentAssessments[$studentId][] = (float)$item['percentage'];
            $rawScores[] = (float)$item['percentage'];
        }
    }

    if (empty($studentAssessments)) {
        return [
            'mps'           => null,
            'highest_score' => null,
            'lowest_score'  => null,
            'student_count' => 0
        ];
    }

    // Average per student
    $studentAverages = [];
    foreach ($studentAssessments as $scores) {
        $studentAverages[] = array_sum($scores) / count($scores);
    }

    $subjectMPS = round(array_sum($studentAverages) / count($studentAverages), 1);

    return [
        'mps'           => $subjectMPS,
        'highest_score' => round(max($rawScores), 1),
        'lowest_score'  => round(min($rawScores), 1),
        'student_count' => count($studentAverages)
    ];
}

/**
 * Calculate section performance from normalized assessments.
 */
function calculateSectionMPS($sectionId, $normalizedAssessments) {
    $studentAssessments = [];
    $rawScores = [];

    foreach ($normalizedAssessments as $item) {
        $matches = false;
        if ($sectionId === null || $sectionId === 'unassigned') {
            $matches = ($item['section_id'] === null);
        } else {
            $matches = ((int)$item['section_id'] === (int)$sectionId);
        }

        if ($matches && $item['percentage'] !== null) {
            $studentId = $item['student_id'];
            $studentAssessments[$studentId][] = (float)$item['percentage'];
            $rawScores[] = (float)$item['percentage'];
        }
    }

    if (empty($studentAssessments)) {
        return [
            'mps'           => null,
            'highest_score' => null,
            'lowest_score'  => null,
            'student_count' => 0
        ];
    }

    $studentAverages = [];
    foreach ($studentAssessments as $scores) {
        $studentAverages[] = array_sum($scores) / count($scores);
    }

    return [
        'mps'           => round(array_sum($studentAverages) / count($studentAverages), 1),
        'highest_score' => round(max($rawScores), 1),
        'lowest_score'  => round(min($rawScores), 1),
        'student_count' => count($studentAverages)
    ];
}

/**
 * Calculate overall MPS across all filtered normalized assessments.
 */
function calculateOverallMPS($normalizedAssessments) {
    $studentAssessments = [];
    foreach ($normalizedAssessments as $item) {
        if ($item['percentage'] !== null) {
            $studentAssessments[$item['student_id']][] = (float)$item['percentage'];
        }
    }

    if (empty($studentAssessments)) {
        return null;
    }

    $studentAverages = [];
    foreach ($studentAssessments as $scores) {
        $studentAverages[] = array_sum($scores) / count($scores);
    }

    return round(array_sum($studentAverages) / count($studentAverages), 1);
}
