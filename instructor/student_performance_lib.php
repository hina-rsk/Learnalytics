<?php

function spaAnalyticsAccess(PDO $pdo, int $userId, string $role): array
{
    if ($role === 'instructor') {
        return ['condition' => 's.instructor_id = ?', 'params' => [$userId], 'department_id' => null];
    }

    if ($role === 'dean') {
        $stmt = $pdo->prepare('SELECT department_id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $departmentId = (int)$stmt->fetchColumn();
        return [
            'condition' => $departmentId > 0 ? 's.department_id = ?' : '1 = 0',
            'params' => $departmentId > 0 ? [$departmentId] : [],
            'department_id' => $departmentId > 0 ? $departmentId : null
        ];
    }

    return ['condition' => '1 = 1', 'params' => [], 'department_id' => null];
}

function spaDimensionJoins(): string
{
    return "
        LEFT JOIN student_profiles sp ON sp.student_id = u.id
        LEFT JOIN sections sec ON sec.id = COALESCE(sp.section_id, s.section_id)
        LEFT JOIN programs p ON p.id = COALESCE(sp.program_id, s.program_id, sec.program_id)
        LEFT JOIN departments d ON d.id = COALESCE(sp.department_id, s.department_id, p.department_id)
        LEFT JOIN semesters sem ON sem.semester_id = COALESCE(s.semester_id, sec.semester_id)
    ";
}

function spaNormalizeSemesterLabel(?string $semester): ?string
{
    $value = strtolower(trim((string)$semester));
    if (in_array($value, ['first', '1st', '1st semester', 'first semester', 'first sem', 'semester 1'], true)) {
        return '1st Semester';
    }
    if (in_array($value, ['second', '2nd', '2nd semester', 'second semester', 'second sem', 'semester 2'], true)) {
        return '2nd Semester';
    }
    return trim((string)$semester) !== '' ? trim((string)$semester) : null;
}

function spaAnalyticsOptions(PDO $pdo, array $access): array
{
    $sql = "
        SELECT DISTINCT
            ay.id AS academic_year_id, ay.name AS academic_year_name,
            COALESCE(sem.semester_name, s.semester) AS semester_name,
            d.id AS department_id, d.name AS department_name,
            p.id AS program_id, p.name AS program_name,
            COALESCE(sp.section_id, s.section_id) AS section_id,
            sec.name AS section_name,
            s.id AS subject_id, s.title AS subject_title,
            u.id AS student_id,
            COALESCE(NULLIF(sp.student_id_number, ''), NULLIF(u.student_id_number, ''),
                     CONCAT('STU-', LPAD(u.id, 4, '0'))) AS student_number,
            u.name AS student_name
        FROM enrollments e
        INNER JOIN subjects s ON s.id = e.subject_id
        INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
        " . spaDimensionJoins() . "
        LEFT JOIN academic_years ay
            ON ay.id = COALESCE(s.academic_year_id, sec.academic_year_id, sem.academic_year_id)
        WHERE {$access['condition']}
        ORDER BY s.title, u.name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($access['params']);
    $sourceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $options = [
        'academic_years' => [],
        'semesters' => [],
        'departments' => [],
        'programs' => [],
        'sections' => [],
        'subjects' => [],
        'students' => [],
        'assessment_types' => ['Prelim', 'Midterm', 'Final', 'Quiz', 'Assignment', 'Examination']
    ];
    $quizOptionsSql = "
        SELECT DISTINCT
            COALESCE(q.academic_year_id, s.academic_year_id, sec.academic_year_id, sem.academic_year_id) AS academic_year_id,
            ay.name AS academic_year_name,
            COALESCE(NULLIF(q.semester, ''), sem.semester_name, s.semester) AS semester_name
        FROM quizzes q
        INNER JOIN subjects s ON s.id = q.subject_id
        INNER JOIN enrollments e ON e.subject_id = s.id
        INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
        " . spaDimensionJoins() . "
        LEFT JOIN academic_years ay
            ON ay.id = COALESCE(q.academic_year_id, s.academic_year_id, sec.academic_year_id, sem.academic_year_id)
        WHERE {$access['condition']} AND q.status = 'published'
    ";
    $quizOptionsStmt = $pdo->prepare($quizOptionsSql);
    $quizOptionsStmt->execute($access['params']);
    $quizOptionRows = $quizOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
    $dimensionKeys = [
        'academic_years' => ['academic_year_id', 'academic_year_name'],
        'departments' => ['department_id', 'department_name'],
        'programs' => ['program_id', 'program_name'],
        'sections' => ['section_id', 'section_name'],
        'subjects' => ['subject_id', 'subject_title'],
        'students' => ['student_id', 'student_name']
    ];

    foreach ($sourceRows as $row) {
        foreach ($dimensionKeys as $key => [$idField, $nameField]) {
            if ($row[$idField] !== null && $row[$nameField] !== null) {
                $id = (string)$row[$idField];
                $options[$key][$id] = ['id' => (int)$row[$idField], 'name' => (string)$row[$nameField]];
                if ($key === 'students') {
                    $options[$key][$id]['student_number'] = (string)$row['student_number'];
                }
            }
        }
        if ($row['semester_name'] !== null && $row['semester_name'] !== '') {
            $semesterLabel = spaNormalizeSemesterLabel((string)$row['semester_name']);
            $options['semesters'][$semesterLabel] = $semesterLabel;
        }
    }
    foreach ($quizOptionRows as $row) {
        if ($row['academic_year_id'] !== null && $row['academic_year_name'] !== null) {
            $options['academic_years'][(string)$row['academic_year_id']] = [
                'id' => (int)$row['academic_year_id'],
                'name' => (string)$row['academic_year_name']
            ];
        }
        if ($row['semester_name'] !== null && $row['semester_name'] !== '') {
            $semesterLabel = spaNormalizeSemesterLabel((string)$row['semester_name']);
            $options['semesters'][$semesterLabel] = $semesterLabel;
        }
    }

    foreach (['academic_years', 'departments', 'programs', 'sections', 'subjects', 'students'] as $key) {
        $options[$key] = array_values($options[$key]);
        usort($options[$key], static function ($left, $right) {
            return strcasecmp((string)$left['name'], (string)$right['name']);
        });
    }
    $options['semesters'] = array_values($options['semesters']);
    sort($options['semesters'], SORT_NATURAL | SORT_FLAG_CASE);

    return $options;
}

function spaNormalizeFilters(array $rawFilters, array $options): array
{
    $filters = [];
    $errors = [];
    $idOptions = [
        'academic_year_id' => 'academic_years',
        'department_id' => 'departments',
        'program_id' => 'programs',
        'section_id' => 'sections',
        'subject_id' => 'subjects',
        'student_id' => 'students'
    ];

    foreach ($idOptions as $field => $optionKey) {
        $value = trim((string)($rawFilters[$field] ?? ''));
        if ($value === '') {
            $filters[$field] = null;
            continue;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        $allowedIds = array_map(static fn($item) => (int)$item['id'], $options[$optionKey]);
        if ($validated === false || !in_array((int)$validated, $allowedIds, true)) {
            $filters[$field] = null;
            $errors[] = 'An unavailable filter was cleared.';
            continue;
        }
        $filters[$field] = (int)$validated;
    }

    $semester = spaNormalizeSemesterLabel(trim((string)($rawFilters['semester'] ?? ''))) ?? '';
    if ($semester !== '' && !in_array($semester, $options['semesters'], true)) {
        $semester = '';
        $errors[] = 'An unavailable semester filter was cleared.';
    }
    $filters['semester'] = $semester !== '' ? $semester : null;

    $assessmentType = trim((string)($rawFilters['assessment_type'] ?? ''));
    if ($assessmentType !== '' && !in_array($assessmentType, $options['assessment_types'], true)) {
        $assessmentType = '';
        $errors[] = 'An unavailable assessment filter was cleared.';
    }
    $filters['assessment_type'] = $assessmentType !== '' ? $assessmentType : null;

    $reportType = trim((string)($rawFilters['report_type'] ?? 'overview'));
    $filters['report_type'] = in_array($reportType, ['overview', 'individual', 'section', 'subject'], true)
        ? $reportType
        : 'overview';

    return [$filters, $errors];
}

function spaAnalyticsWhere(array $access, array $filters): array
{
    $conditions = [$access['condition']];
    $params = $access['params'];
    $filterConditions = [
        'academic_year_id' => ['COALESCE(s.academic_year_id, sec.academic_year_id, sem.academic_year_id) = ?', 'academic_year_id'],
        'department_id' => ['COALESCE(sp.department_id, s.department_id, p.department_id) = ?', 'department_id'],
        'program_id' => ['COALESCE(sp.program_id, s.program_id, sec.program_id) = ?', 'program_id'],
        'section_id' => ['COALESCE(sp.section_id, s.section_id) = ?', 'section_id'],
        'subject_id' => ['s.id = ?', 'subject_id'],
        'student_id' => ['e.student_id = ?', 'student_id']
    ];

    foreach ($filterConditions as $filterKey => [$condition, $parameterKey]) {
        if (!empty($filters[$filterKey])) {
            $conditions[] = $condition;
            $params[] = $filters[$parameterKey];
        }
    }
    if (!empty($filters['semester'])) {
        $semester = spaNormalizeSemesterLabel((string)$filters['semester']);
        if ($semester === '1st Semester') {
            $conditions[] = "LOWER(TRIM(COALESCE(sem.semester_name, s.semester))) IN ('first', '1st', '1st semester', 'first semester', 'first sem', 'semester 1')";
        } elseif ($semester === '2nd Semester') {
            $conditions[] = "LOWER(TRIM(COALESCE(sem.semester_name, s.semester))) IN ('second', '2nd', '2nd semester', 'second semester', 'second sem', 'semester 2')";
        } else {
            $conditions[] = 'COALESCE(sem.semester_name, s.semester) = ?';
            $params[] = $semester;
        }
    }

    return [implode(' AND ', $conditions), $params];
}

function spaTermSqlCondition(array $filters, string $academicYearExpression, string $semesterExpression): array
{
    $conditions = [];
    $params = [];
    if (!empty($filters['academic_year_id'])) {
        $conditions[] = $academicYearExpression . ' = ?';
        $params[] = (int)$filters['academic_year_id'];
    }
    if (!empty($filters['semester'])) {
        $semester = spaNormalizeSemesterLabel((string)$filters['semester']);
        if ($semester === '1st Semester') {
            $conditions[] = "LOWER(TRIM(" . $semesterExpression . ")) IN ('first', '1st', '1st semester', 'first semester', 'first sem', 'semester 1')";
        } elseif ($semester === '2nd Semester') {
            $conditions[] = "LOWER(TRIM(" . $semesterExpression . ")) IN ('second', '2nd', '2nd semester', 'second semester', 'second sem', 'semester 2')";
        } else {
            $conditions[] = $semesterExpression . ' = ?';
            $params[] = $semester;
        }
    }
    return [$conditions ? implode(' AND ', $conditions) : '1 = 1', $params];
}

function spaFetchGradeRows(PDO $pdo, array $access, array $filters): array
{
    $scopeFilters = $filters;
    unset($scopeFilters['academic_year_id'], $scopeFilters['semester']);
    [$where, $params] = spaAnalyticsWhere($access, $scopeFilters);
    $sql = "
        SELECT DISTINCT
            u.id AS student_id,
            u.name AS student_name,
            instructor.name AS instructor_name,
            COALESCE(NULLIF(sp.student_id_number, ''), NULLIF(u.student_id_number, ''),
                     CONCAT('STU-', LPAD(u.id, 4, '0'))) AS student_number,
            sp.year_level,
            COALESCE(sp.program_id, s.program_id, sec.program_id) AS program_id,
            p.name AS program_name,
            COALESCE(sp.section_id, s.section_id) AS section_id,
            COALESCE(sec.name, 'Unassigned') AS section_name,
            COALESCE(sp.department_id, s.department_id, p.department_id) AS department_id,
            d.name AS department_name,
            s.id AS subject_id,
            s.title AS subject_title,
            COALESCE(s.academic_year_id, sec.academic_year_id, sem.academic_year_id) AS academic_year_id,
            ay.name AS academic_year_name,
            COALESCE(sem.semester_name, s.semester) AS semester_name,
            g.prelim_grade,
            g.midterm_grade,
            g.final_grade,
            g.final_average,
            g.updated_at AS grade_updated_at
        FROM enrollments e
        INNER JOIN subjects s ON s.id = e.subject_id
        INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
        LEFT JOIN users instructor ON instructor.id = s.instructor_id
        " . spaDimensionJoins() . "
        LEFT JOIN academic_years ay
            ON ay.id = COALESCE(s.academic_year_id, sec.academic_year_id, sem.academic_year_id)
        LEFT JOIN student_grades g
            ON g.student_id = e.student_id AND g.subject_id = s.id AND g.instructor_id = s.instructor_id
        WHERE {$where}
        ORDER BY u.name, s.title
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function spaFetchMatchingQuizEnrollmentKeys(PDO $pdo, array $access, array $filters): array
{
    if ($filters['academic_year_id'] === null && $filters['semester'] === null) {
        return [];
    }

    $scopeFilters = $filters;
    unset($scopeFilters['academic_year_id'], $scopeFilters['semester']);
    [$where, $params] = spaAnalyticsWhere($access, $scopeFilters);
    $subjectAcademicYear = 'COALESCE(s.academic_year_id, sec.academic_year_id, sem.academic_year_id)';
    $subjectSemester = 'COALESCE(sem.semester_name, s.semester)';
    [$termWhere, $termParams] = spaTermSqlCondition(
        $filters,
        'COALESCE(q.academic_year_id, ' . $subjectAcademicYear . ')',
        "COALESCE(NULLIF(q.semester, ''), " . $subjectSemester . ')'
    );
    $sql = "
        SELECT DISTINCT e.student_id, s.id AS subject_id
        FROM quizzes q
        INNER JOIN subjects s ON s.id = q.subject_id
        INNER JOIN enrollments e ON e.subject_id = s.id
        INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
        " . spaDimensionJoins() . "
        LEFT JOIN quiz_assignments qa ON qa.quiz_id = q.id
        WHERE {$where}
            AND {$termWhere}
            AND q.status = 'published'
            AND (q.section_id IS NULL OR q.section_id = COALESCE(sp.section_id, s.section_id))
            AND (
                qa.id IS NULL OR qa.assignment_type = 'subject'
                OR (qa.assignment_type = 'section' AND qa.target_id = COALESCE(sp.section_id, s.section_id))
                OR (qa.assignment_type = 'program' AND qa.target_id = COALESCE(sp.program_id, s.program_id, sec.program_id))
                OR (qa.assignment_type = 'year_level' AND qa.year_level = sp.year_level)
                OR (qa.assignment_type = 'student' AND qa.target_id = e.student_id)
            )
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $termParams));
    $keys = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $keys[(int)$row['student_id'] . ':' . (int)$row['subject_id']] = true;
    }
    return $keys;
}

function spaMakeRecord(array $row, string $assessmentType, string $sourceType): ?array
{
    if (!is_numeric($row['score'] ?? null) || !is_numeric($row['max_score'] ?? null)) {
        return null;
    }
    $score = (float)$row['score'];
    $maximum = (float)$row['max_score'];
    if ($score < 0 || $maximum <= 0) {
        return null;
    }

    return [
        'assessment_id' => (string)$row['assessment_id'],
        'source_id' => (int)$row['source_id'],
        'source_type' => $sourceType,
        'assessment_type' => $assessmentType,
        'title' => (string)$row['title'],
        'student_id' => (int)$row['student_id'],
        'student_name' => (string)$row['student_name'],
        'student_number' => (string)$row['student_number'],
        'subject_id' => (int)$row['subject_id'],
        'subject_title' => (string)$row['subject_title'],
        'section_id' => $row['section_id'] !== null ? (int)$row['section_id'] : null,
        'section_name' => (string)$row['section_name'],
        'academic_year_id' => $row['record_academic_year_id'] !== null ? (int)$row['record_academic_year_id'] : null,
        'semester_name' => $row['record_semester_name'] ?? null,
        'score' => $score,
        'max_score' => $maximum,
        'percentage' => ($score / $maximum) * 100,
        'assessed_at' => $row['assessed_at'] ?? null
    ];
}

function spaExamPeriod(string $title): ?string
{
    foreach (['Prelim', 'Midterm', 'Final'] as $period) {
        if (stripos($title, $period) !== false) {
            return $period;
        }
    }
    return null;
}

function spaFetchAssessmentRecords(PDO $pdo, array $access, array $filters): array
{
    $scopeFilters = $filters;
    unset($scopeFilters['academic_year_id'], $scopeFilters['semester']);
    [$where, $params] = spaAnalyticsWhere($access, $scopeFilters);
    $dimensionJoins = spaDimensionJoins();
    $subjectAcademicYear = 'COALESCE(s.academic_year_id, sec.academic_year_id, sem.academic_year_id)';
    $subjectSemester = 'COALESCE(sem.semester_name, s.semester)';
    [$subjectTermWhere, $subjectTermParams] = spaTermSqlCondition($filters, $subjectAcademicYear, $subjectSemester);
    $quizAcademicYear = 'COALESCE(q.academic_year_id, ' . $subjectAcademicYear . ')';
    $quizSemester = "COALESCE(NULLIF(q.semester, ''), " . $subjectSemester . ')';
    [$quizTermWhere, $quizTermParams] = spaTermSqlCondition($filters, $quizAcademicYear, $quizSemester);
    $records = [];

    $examSql = "
        SELECT DISTINCT
            CONCAT('examination_', er.id) AS assessment_id, er.id AS source_id,
            er.title, es.student_id, u.name AS student_name,
            COALESCE(NULLIF(sp.student_id_number, ''), NULLIF(u.student_id_number, ''),
                     CONCAT('STU-', LPAD(u.id, 4, '0'))) AS student_number,
            er.subject_id, s.title AS subject_title,
            COALESCE(sp.section_id, s.section_id) AS section_id,
            COALESCE(sec.name, 'Unassigned') AS section_name,
            {$subjectAcademicYear} AS record_academic_year_id,
            {$subjectSemester} AS record_semester_name,
            es.score, er.total_items AS max_score,
            COALESCE(es.recorded_at, es.updated_at) AS assessed_at
        FROM exam_scores es
        INNER JOIN exam_records er ON er.id = es.exam_id
        INNER JOIN subjects s ON s.id = er.subject_id
        INNER JOIN enrollments e ON e.subject_id = s.id AND e.student_id = es.student_id
        INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
        {$dimensionJoins}
        WHERE {$where}
            AND er.instructor_id = s.instructor_id
            AND (er.section_id IS NULL OR er.section_id = COALESCE(sp.section_id, s.section_id))
            AND {$subjectTermWhere}
    ";
    $stmt = $pdo->prepare($examSql);
    $stmt->execute(array_merge($params, $subjectTermParams));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $period = spaExamPeriod((string)$row['title']);
        $record = spaMakeRecord($row, $period ?? 'Examination', 'Examination');
        if ($record !== null) {
            $records[] = $record;
        }
    }

    $quizSql = "
        SELECT DISTINCT
            CONCAT('quiz_', q.id) AS assessment_id, q.id AS source_id,
            q.title, qr.student_id, u.name AS student_name,
            COALESCE(NULLIF(sp.student_id_number, ''), NULLIF(u.student_id_number, ''),
                     CONCAT('STU-', LPAD(u.id, 4, '0'))) AS student_number,
            q.subject_id, s.title AS subject_title,
            COALESCE(sp.section_id, s.section_id) AS section_id,
            COALESCE(sec.name, 'Unassigned') AS section_name,
            COALESCE(q.academic_year_id, {$subjectAcademicYear}) AS record_academic_year_id,
            COALESCE(NULLIF(q.semester, ''), {$subjectSemester}) AS record_semester_name,
            qr.score,
            COALESCE(NULLIF(qr.max_score, 0),
                (SELECT COALESCE(SUM(question.points), 0) FROM questions question WHERE question.quiz_id = q.id)
            ) AS max_score,
            qr.taken_at AS assessed_at
        FROM quiz_results qr
        INNER JOIN quizzes q ON q.id = qr.quiz_id AND q.status = 'published'
        INNER JOIN subjects s ON s.id = q.subject_id
        INNER JOIN enrollments e ON e.subject_id = s.id AND e.student_id = qr.student_id
        INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
        {$dimensionJoins}
        LEFT JOIN quiz_assignments qa ON qa.quiz_id = q.id
        WHERE {$where}
            AND {$quizTermWhere}
            AND (q.section_id IS NULL OR q.section_id = COALESCE(sp.section_id, s.section_id))
            AND (
                qa.id IS NULL OR qa.assignment_type = 'subject'
                OR (qa.assignment_type = 'section' AND qa.target_id = COALESCE(sp.section_id, s.section_id))
                OR (qa.assignment_type = 'program' AND qa.target_id = COALESCE(sp.program_id, s.program_id, sec.program_id))
                OR (qa.assignment_type = 'year_level' AND qa.year_level = sp.year_level)
                OR (qa.assignment_type = 'student' AND qa.target_id = e.student_id)
            )
            AND qr.grading_status = 'graded'
            AND NOT EXISTS (
                SELECT 1 FROM quiz_results newer
                WHERE newer.quiz_id = qr.quiz_id AND newer.student_id = qr.student_id
                    AND newer.grading_status = 'graded'
                    AND (
                        COALESCE(newer.attempt_number, 1) > COALESCE(qr.attempt_number, 1)
                        OR (COALESCE(newer.attempt_number, 1) = COALESCE(qr.attempt_number, 1) AND newer.id > qr.id)
                    )
            )
    ";
    $stmt = $pdo->prepare($quizSql);
    $stmt->execute(array_merge($params, $quizTermParams));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $record = spaMakeRecord($row, 'Quiz', 'Quiz');
        if ($record !== null) {
            $records[] = $record;
        }
    }

    $assignmentSql = "
        SELECT DISTINCT
            CONCAT('assignment_', a.id) AS assessment_id, a.id AS source_id,
            a.title, sub.student_id, u.name AS student_name,
            COALESCE(NULLIF(sp.student_id_number, ''), NULLIF(u.student_id_number, ''),
                     CONCAT('STU-', LPAD(u.id, 4, '0'))) AS student_number,
            a.subject_id, s.title AS subject_title,
            COALESCE(sp.section_id, s.section_id) AS section_id,
            COALESCE(sec.name, 'Unassigned') AS section_name,
            {$subjectAcademicYear} AS record_academic_year_id,
            {$subjectSemester} AS record_semester_name,
            CASE WHEN f.status = 'checked' AND f.score IS NOT NULL THEN f.score ELSE sub.grade END AS score,
            CASE WHEN f.status = 'checked' AND f.maximum_score > 0 THEN f.maximum_score ELSE a.points END AS max_score,
            COALESCE(f.reviewed_at, sub.submitted_at) AS assessed_at,
            f.status AS review_status, f.feedback_id
        FROM submissions sub
        INNER JOIN assignments a ON a.id = sub.assignment_id
        INNER JOIN subjects s ON s.id = a.subject_id
        INNER JOIN enrollments e ON e.subject_id = s.id AND e.student_id = sub.student_id
        INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
        {$dimensionJoins}
        LEFT JOIN assignment_feedback f ON f.submission_id = sub.id
        WHERE {$where} AND {$subjectTermWhere}
            AND NOT EXISTS (
                SELECT 1 FROM submissions newer
                WHERE newer.assignment_id = sub.assignment_id AND newer.student_id = sub.student_id
                    AND (
                        COALESCE(newer.attempt_number, 1) > COALESCE(sub.attempt_number, 1)
                        OR (COALESCE(newer.attempt_number, 1) = COALESCE(sub.attempt_number, 1) AND newer.id > sub.id)
                    )
            )
    ";
    $stmt = $pdo->prepare($assignmentSql);
    $stmt->execute(array_merge($params, $subjectTermParams));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['feedback_id'] !== null && ($row['review_status'] !== 'checked' || $row['score'] === null)) {
            continue;
        }
        $record = spaMakeRecord($row, 'Assignment', 'Assignment');
        if ($record !== null) {
            $records[] = $record;
        }
    }

    return $records;
}

function spaCountAssessments(PDO $pdo, array $access, array $filters): int
{
    $scopeFilters = $filters;
    unset($scopeFilters['academic_year_id'], $scopeFilters['semester']);
    [$where, $params] = spaAnalyticsWhere($access, $scopeFilters);
    $subjectAcademicYear = 'COALESCE(s.academic_year_id, sec.academic_year_id, sem.academic_year_id)';
    $subjectSemester = 'COALESCE(sem.semester_name, s.semester)';
    [$subjectTermWhere, $subjectTermParams] = spaTermSqlCondition($filters, $subjectAcademicYear, $subjectSemester);
    [$quizTermWhere, $quizTermParams] = spaTermSqlCondition(
        $filters,
        'COALESCE(q.academic_year_id, ' . $subjectAcademicYear . ')',
        "COALESCE(NULLIF(q.semester, ''), " . $subjectSemester . ')'
    );
    $assessmentKeys = [];
    $dimensionJoins = spaDimensionJoins();
    $typeFilter = $filters['assessment_type'];

    if ($typeFilter === null || $typeFilter === 'Quiz') {
        $quizSql = "
            SELECT DISTINCT CONCAT('quiz_', q.id) AS assessment_key
            FROM quizzes q
            INNER JOIN subjects s ON s.id = q.subject_id
            INNER JOIN enrollments e ON e.subject_id = s.id
            INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
            {$dimensionJoins}
            LEFT JOIN quiz_assignments qa ON qa.quiz_id = q.id
            WHERE {$where} AND {$quizTermWhere}
                AND q.status = 'published'
                AND (q.section_id IS NULL OR q.section_id = COALESCE(sp.section_id, s.section_id))
                AND (
                    qa.id IS NULL OR qa.assignment_type = 'subject'
                    OR (qa.assignment_type = 'section' AND qa.target_id = COALESCE(sp.section_id, s.section_id))
                    OR (qa.assignment_type = 'program' AND qa.target_id = COALESCE(sp.program_id, s.program_id, sec.program_id))
                    OR (qa.assignment_type = 'year_level' AND qa.year_level = sp.year_level)
                    OR (qa.assignment_type = 'student' AND qa.target_id = e.student_id)
                )
        ";
        $stmt = $pdo->prepare($quizSql);
        $stmt->execute(array_merge($params, $quizTermParams));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $assessmentKeys[(string)$key] = true;
        }
    }

    if ($typeFilter === null || in_array($typeFilter, ['Prelim', 'Midterm', 'Final', 'Examination'], true)) {
        $examSql = "
            SELECT DISTINCT CONCAT('examination_', er.id) AS assessment_key, er.title
            FROM exam_records er
            INNER JOIN subjects s ON s.id = er.subject_id
            INNER JOIN enrollments e ON e.subject_id = s.id
            INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
            {$dimensionJoins}
            WHERE {$where}
                AND er.instructor_id = s.instructor_id
                AND (er.section_id IS NULL OR er.section_id = COALESCE(sp.section_id, s.section_id))
                AND {$subjectTermWhere}
        ";
        $stmt = $pdo->prepare($examSql);
        $stmt->execute(array_merge($params, $subjectTermParams));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $examType = spaExamPeriod((string)$row['title']) ?? 'Examination';
            if ($typeFilter === null || $typeFilter === $examType) {
                $assessmentKeys[(string)$row['assessment_key']] = true;
            }
        }
    }

    if ($typeFilter === null || $typeFilter === 'Assignment') {
        $assignmentSql = "
            SELECT DISTINCT CONCAT('assignment_', a.id) AS assessment_key
            FROM assignments a
            INNER JOIN subjects s ON s.id = a.subject_id
            INNER JOIN enrollments e ON e.subject_id = s.id
            INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
            {$dimensionJoins}
            WHERE {$where}
                AND {$subjectTermWhere}
                AND a.status IN ('active', 'completed')
        ";
        $stmt = $pdo->prepare($assignmentSql);
        $stmt->execute(array_merge($params, $subjectTermParams));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $assessmentKeys[(string)$key] = true;
        }
    }

    return count($assessmentKeys);
}

function spaPerformanceStatus(?float $average, float $riskThreshold, float $moderateThreshold): string
{
    if ($average === null) {
        return 'Insufficient Data';
    }
    if ($average < $riskThreshold) {
        return $average >= max(0, $riskThreshold - 10) ? 'Needs Improvement' : 'At Risk';
    }
    if ($average >= 90) {
        return 'Excellent';
    }
    if ($average >= max($riskThreshold + 5, $moderateThreshold)) {
        return 'Very Satisfactory';
    }
    if ($average >= $riskThreshold) {
        return 'Satisfactory';
    }
    return 'Satisfactory';
}

function spaAverage(array $values): ?float
{
    $valid = array_values(array_filter($values, static fn($value) => $value !== null && is_numeric($value)));
    return $valid ? array_sum(array_map('floatval', $valid)) / count($valid) : null;
}

function spaAssessmentTrend(array $records): string
{
    $records = array_values(array_filter(
        $records,
        static fn($record) => $record['source_type'] !== 'Gradebook' && !empty($record['assessed_at'])
    ));
    usort($records, static fn($left, $right) => strcmp((string)$left['assessed_at'], (string)$right['assessed_at']));
    if (count($records) < 2) {
        return 'Insufficient Data';
    }
    $sliceSize = min(3, (int)floor(count($records) / 2));
    $early = array_slice($records, 0, $sliceSize);
    $recent = array_slice($records, -$sliceSize);
    $delta = (float)spaAverage(array_column($recent, 'percentage')) - (float)spaAverage(array_column($early, 'percentage'));
    if ($delta >= 2) {
        return 'Improving';
    }
    if ($delta <= -2) {
        return 'Declining';
    }
    return 'Consistent';
}

function spaBuildAnalyticsData(PDO $pdo, array $access, array $rawFilters, float $riskThreshold, float $moderateThreshold): array
{
    $options = spaAnalyticsOptions($pdo, $access);
    [$filters, $filterErrors] = spaNormalizeFilters($rawFilters, $options);
    $gradeRows = spaFetchGradeRows($pdo, $access, $filters);
    $assessmentRecords = spaFetchAssessmentRecords($pdo, $access, $filters);
    $matchesTerm = static function ($academicYearId, $semesterName) use ($filters): bool {
        if ($filters['academic_year_id'] !== null && (int)$academicYearId !== (int)$filters['academic_year_id']) {
            return false;
        }
        if ($filters['semester'] !== null && spaNormalizeSemesterLabel((string)$semesterName) !== $filters['semester']) {
            return false;
        }
        return true;
    };
    $assessmentRecords = array_values(array_filter($assessmentRecords, static function ($record) use ($matchesTerm) {
        return $matchesTerm($record['academic_year_id'], $record['semester_name']);
    }));
    $matchingStudentSubjects = spaFetchMatchingQuizEnrollmentKeys($pdo, $access, $filters);
    foreach ($assessmentRecords as $record) {
        $matchingStudentSubjects[$record['student_id'] . ':' . $record['subject_id']] = true;
    }
    if ($filters['academic_year_id'] !== null || $filters['semester'] !== null) {
        $gradeRows = array_values(array_filter($gradeRows, static function ($row) use ($matchesTerm, $matchingStudentSubjects) {
            $key = (int)$row['student_id'] . ':' . (int)$row['subject_id'];
            return $matchesTerm($row['academic_year_id'], $row['semester_name']) || isset($matchingStudentSubjects[$key]);
        }));
    }

    foreach ($gradeRows as $row) {
        if (!$matchesTerm($row['academic_year_id'], $row['semester_name'])) {
            continue;
        }
        foreach ([
            'Prelim' => 'prelim_grade',
            'Midterm' => 'midterm_grade',
            'Final' => 'final_grade'
        ] as $period => $field) {
            if ($row[$field] !== null && is_numeric($row[$field]) && (float)$row[$field] >= 0 && (float)$row[$field] <= 100) {
                $assessmentRecords[] = [
                    'assessment_id' => 'period_' . $row['subject_id'] . '_' . strtolower($period),
                    'source_id' => (int)$row['subject_id'],
                    'source_type' => 'Gradebook',
                    'assessment_type' => $period,
                    'title' => $period . ' Grade',
                    'student_id' => (int)$row['student_id'],
                    'student_name' => (string)$row['student_name'],
                    'student_number' => (string)$row['student_number'],
                    'subject_id' => (int)$row['subject_id'],
                    'subject_title' => (string)$row['subject_title'],
                    'section_id' => $row['section_id'] !== null ? (int)$row['section_id'] : null,
                    'section_name' => (string)$row['section_name'],
                    'score' => (float)$row[$field],
                    'max_score' => 100.0,
                    'percentage' => (float)$row[$field],
                    'assessed_at' => $row['grade_updated_at']
                ];
            }
        }
    }

    $selectedRecords = array_values(array_filter($assessmentRecords, static function ($record) use ($filters) {
        return $filters['assessment_type'] === null || $record['assessment_type'] === $filters['assessment_type'];
    }));
    $allByStudentSubject = [];
    $selectedByStudentSubject = [];
    foreach ($assessmentRecords as $record) {
        $key = $record['student_id'] . ':' . $record['subject_id'];
        $allByStudentSubject[$key][] = $record;
    }
    foreach ($selectedRecords as $record) {
        $key = $record['student_id'] . ':' . $record['subject_id'];
        $selectedByStudentSubject[$key][] = $record;
    }

    $rows = [];
    foreach ($gradeRows as $gradeRow) {
        $key = (int)$gradeRow['student_id'] . ':' . (int)$gradeRow['subject_id'];
        $allScores = $allByStudentSubject[$key] ?? [];
        $selectedScores = $selectedByStudentSubject[$key] ?? [];
        $gradeContextMatches = $matchesTerm($gradeRow['academic_year_id'], $gradeRow['semester_name']);
        $periodGrades = [];
        foreach ([
            'Prelim' => 'prelim_grade',
            'Midterm' => 'midterm_grade',
            'Final' => 'final_grade'
        ] as $period => $field) {
            $grade = $gradeContextMatches && $gradeRow[$field] !== null && is_numeric($gradeRow[$field])
                ? (float)$gradeRow[$field]
                : null;
            $periodScores = array_values(array_filter($allScores, static function ($record) use ($period) {
                return $record['assessment_type'] === $period && $record['source_type'] !== 'Gradebook';
            }));
            $periodGrades[$period] = $grade !== null
                ? $grade
                : spaAverage(array_column($periodScores, 'percentage'));
        }

        $selectedType = $filters['assessment_type'];
        if (in_array($selectedType, ['Prelim', 'Midterm', 'Final'], true)) {
            $average = $periodGrades[$selectedType];
        } elseif (in_array($selectedType, ['Quiz', 'Assignment', 'Examination'], true)) {
            $average = spaAverage(array_column($selectedScores, 'percentage'));
        } else {
            $officialAverage = $gradeContextMatches && $gradeRow['final_average'] !== null && is_numeric($gradeRow['final_average'])
                ? (float)$gradeRow['final_average']
                : null;
            $hasAllPeriods = count(array_filter($periodGrades, static fn($value) => $value !== null)) === 3;
            if ($officialAverage !== null) {
                $average = $officialAverage;
            } elseif ($hasAllPeriods && $gradeRow['prelim_grade'] !== null && $gradeRow['midterm_grade'] !== null && $gradeRow['final_grade'] !== null) {
                $average = (float)spaAverage(array_values($periodGrades));
            } else {
                $externalScores = array_values(array_filter($allScores, static fn($record) => $record['source_type'] !== 'Gradebook'));
                $average = spaAverage(array_column($externalScores, 'percentage'));
            }
        }

        $rows[] = [
            'student_id' => (int)$gradeRow['student_id'],
            'student_number' => (string)$gradeRow['student_number'],
            'student_name' => (string)$gradeRow['student_name'],
            'year_level' => $gradeRow['year_level'] ?? '',
            'program_id' => $gradeRow['program_id'] !== null ? (int)$gradeRow['program_id'] : null,
            'program_name' => $gradeRow['program_name'] ?? 'Unassigned',
            'section_id' => $gradeRow['section_id'] !== null ? (int)$gradeRow['section_id'] : null,
            'section_name' => (string)$gradeRow['section_name'],
            'department_id' => $gradeRow['department_id'] !== null ? (int)$gradeRow['department_id'] : null,
            'department_name' => $gradeRow['department_name'] ?? 'Unassigned',
            'subject_id' => (int)$gradeRow['subject_id'],
            'subject_title' => (string)$gradeRow['subject_title'],
            'instructor_name' => $gradeRow['instructor_name'] ?? 'Unassigned',
            'academic_year_name' => $gradeRow['academic_year_name'] ?? '',
            'semester_name' => $gradeRow['semester_name'] ?? '',
            'prelim_grade' => $periodGrades['Prelim'],
            'midterm_grade' => $periodGrades['Midterm'],
            'final_grade' => $periodGrades['Final'],
            'official_average' => $gradeRow['final_average'] !== null ? (float)$gradeRow['final_average'] : null,
            'overall_average' => $average,
            'status' => spaPerformanceStatus($average, $riskThreshold, $moderateThreshold)
        ];
    }

    $studentGroups = [];
    foreach ($rows as $row) {
        $studentId = $row['student_id'];
        if (!isset($studentGroups[$studentId])) {
            $studentGroups[$studentId] = [
                'student_id' => $studentId,
                'student_number' => $row['student_number'],
                'student_name' => $row['student_name'],
                'year_level' => $row['year_level'],
                'program_name' => $row['program_name'],
                'section_name' => $row['section_name'],
                'department_name' => $row['department_name'],
                'academic_years' => [],
                'semesters' => [],
                'subject_rows' => [],
                'average' => null,
                'status' => 'Insufficient Data',
                'trend' => 'Insufficient Data',
                'completed_assessments' => 0,
                'highest_score' => null,
                'lowest_score' => null
            ];
        }
        $studentGroups[$studentId]['subject_rows'][] = $row;
        if ($row['academic_year_name'] !== '') {
            $studentGroups[$studentId]['academic_years'][$row['academic_year_name']] = true;
        }
        if ($row['semester_name'] !== '') {
            $studentGroups[$studentId]['semesters'][$row['semester_name']] = true;
        }
    }

    $recordsByStudent = [];
    foreach ($selectedRecords as $record) {
        $recordsByStudent[$record['student_id']][] = $record;
    }
    foreach ($studentGroups as $studentId => &$group) {
        $subjectAverages = array_column($group['subject_rows'], 'overall_average');
        $group['average'] = spaAverage($subjectAverages);
        $group['status'] = spaPerformanceStatus($group['average'], $riskThreshold, $moderateThreshold);
        $studentRecords = $recordsByStudent[$studentId] ?? [];
        $group['trend'] = spaAssessmentTrend($studentRecords);
        $external = array_values(array_filter($studentRecords, static fn($record) => $record['source_type'] !== 'Gradebook'));
        $group['completed_assessments'] = count(array_unique(array_column($external, 'assessment_id')));
        $scores = array_column($external, 'percentage');
        $group['highest_score'] = $scores ? max($scores) : null;
        $group['lowest_score'] = $scores ? min($scores) : null;
        $group['period_averages'] = [];
        foreach (['Prelim', 'Midterm', 'Final'] as $period) {
            $group['period_averages'][$period] = spaAverage(array_column(
                array_map(static function ($row) use ($period) {
                    return ['value' => $row[strtolower($period) . '_grade']];
                }, $group['subject_rows']),
                'value'
            ));
        }
        $group['academic_years'] = array_keys($group['academic_years']);
        $group['semesters'] = array_keys($group['semesters']);
    }
    unset($group);

    foreach ($rows as &$row) {
        $row['trend'] = $studentGroups[$row['student_id']]['trend'];
    }
    unset($row);

    $validStudentAverages = array_values(array_filter(
        array_column($studentGroups, 'average'),
        static fn($value) => $value !== null
    ));
    $rankedStudents = array_values(array_filter($studentGroups, static fn($group) => $group['average'] !== null));
    usort($rankedStudents, static fn($left, $right) => $left['average'] <=> $right['average']);
    $summary = [
        'overall_average' => spaAverage($validStudentAverages),
        'total_students' => count($studentGroups),
        'needing_attention' => count(array_filter($studentGroups, static fn($group) => $group['average'] !== null && $group['average'] < $riskThreshold)),
        'total_assessments' => spaCountAssessments($pdo, $access, $filters),
        'highest_student' => $rankedStudents ? end($rankedStudents) : null,
        'lowest_student' => $rankedStudents ? reset($rankedStudents) : null
    ];

    $riskRows = array_values(array_filter(
        $rows,
        static fn($row) => $row['overall_average'] !== null && $row['overall_average'] < max($riskThreshold, $moderateThreshold)
    ));
    foreach ($riskRows as &$riskRow) {
        $riskRow['risk_status'] = $riskRow['overall_average'] < $riskThreshold
            ? $riskRow['status']
            : 'Needs Monitoring';
    }
    unset($riskRow);
    usort($riskRows, static fn($left, $right) => $left['overall_average'] <=> $right['overall_average']);

    $assessmentGroups = [];
    foreach ($selectedRecords as $record) {
        if ($record['source_type'] === 'Gradebook') {
            continue;
        }
        $key = $record['assessment_id'] . ':' . $record['subject_id'];
        if (!isset($assessmentGroups[$key])) {
            $assessmentGroups[$key] = [
                'id' => $key,
                'title' => $record['title'],
                'subject_title' => $record['subject_title'],
                'type' => $record['assessment_type'],
                'scores' => []
            ];
        }
        $assessmentGroups[$key]['scores'][] = (float)$record['percentage'];
    }
    $assessmentAverages = [];
    foreach ($assessmentGroups as $group) {
        $assessmentAverages[] = [
            'title' => $group['title'],
            'subject_title' => $group['subject_title'],
            'type' => $group['type'],
            'average' => spaAverage($group['scores']),
            'count' => count($group['scores'])
        ];
    }
    usort($assessmentAverages, static fn($left, $right) => strcasecmp($left['title'], $right['title']));

    $periodComparisons = [];
    foreach (['Prelim', 'Midterm', 'Final'] as $period) {
        $perStudent = [];
        foreach ($rows as $row) {
            if ($row[strtolower($period) . '_grade'] !== null) {
                $perStudent[$row['student_id']][] = $row[strtolower($period) . '_grade'];
            }
        }
        $periodComparisons[$period] = spaAverage(array_map('spaAverage', array_values($perStudent)));
    }

    $sectionStudentScores = [];
    $subjectStudentScores = [];
    foreach ($rows as $row) {
        if ($row['overall_average'] === null) {
            continue;
        }
        $sectionKey = $row['section_id'] ?? 0;
        $sectionStudentScores[$sectionKey][$row['student_id']][] = $row['overall_average'];
        $subjectStudentScores[$row['subject_id']]['title'] = $row['subject_title'];
        $subjectStudentScores[$row['subject_id']]['scores'][] = $row['overall_average'];
    }
    $sectionComparisons = [];
    foreach ($sectionStudentScores as $sectionId => $students) {
        $studentAverages = array_map('spaAverage', array_values($students));
        $sectionName = 'Unassigned';
        foreach ($rows as $row) {
            if ((int)($row['section_id'] ?? 0) === (int)$sectionId) {
                $sectionName = $row['section_name'];
                break;
            }
        }
        $sectionComparisons[] = [
            'id' => (int)$sectionId,
            'name' => $sectionName,
            'average' => spaAverage($studentAverages)
        ];
    }
    $subjectComparisons = [];
    foreach ($subjectStudentScores as $subject) {
        $subjectComparisons[] = ['name' => $subject['title'], 'average' => spaAverage($subject['scores'])];
    }

    $distribution = [];
    foreach (['Excellent', 'Very Satisfactory', 'Satisfactory', 'Needs Improvement', 'At Risk', 'Insufficient Data'] as $status) {
        $distribution[$status] = count(array_filter($studentGroups, static fn($group) => $group['status'] === $status));
    }

    return [
        'options' => $options,
        'filters' => $filters,
        'filter_errors' => $filterErrors,
        'grade_rows' => $gradeRows,
        'rows' => $rows,
        'assessment_records' => $selectedRecords,
        'all_assessment_records' => $assessmentRecords,
        'student_groups' => $studentGroups,
        'risk_rows' => $riskRows,
        'summary' => $summary,
        'assessment_averages' => $assessmentAverages,
        'period_comparisons' => $periodComparisons,
        'section_comparisons' => $sectionComparisons,
        'subject_comparisons' => $subjectComparisons,
        'distribution' => $distribution
    ];
}

function spaPendingAssessmentCount(PDO $pdo, array $access, array $filters, int $studentId, array $assessmentRecords): int
{
    $studentFilters = $filters;
    unset($studentFilters['student_id'], $studentFilters['academic_year_id'], $studentFilters['semester']);
    [$where, $params] = spaAnalyticsWhere($access, $studentFilters);
    $dimensionJoins = spaDimensionJoins();
    $subjectAcademicYear = 'COALESCE(s.academic_year_id, sec.academic_year_id, sem.academic_year_id)';
    $subjectSemester = 'COALESCE(sem.semester_name, s.semester)';
    [$subjectTermWhere, $subjectTermParams] = spaTermSqlCondition($filters, $subjectAcademicYear, $subjectSemester);
    [$quizTermWhere, $quizTermParams] = spaTermSqlCondition(
        $filters,
        'COALESCE(q.academic_year_id, ' . $subjectAcademicYear . ')',
        "COALESCE(NULLIF(q.semester, ''), " . $subjectSemester . ')'
    );
    $definitions = [];

    $examSql = "
        SELECT DISTINCT CONCAT('examination_', er.id) AS assessment_key, er.title
        FROM exam_records er
        INNER JOIN subjects s ON s.id = er.subject_id
        INNER JOIN enrollments e ON e.subject_id = s.id AND e.student_id = ?
        INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
        {$dimensionJoins}
        WHERE {$where}
            AND er.instructor_id = s.instructor_id
            AND (er.section_id IS NULL OR er.section_id = COALESCE(sp.section_id, s.section_id))
            AND {$subjectTermWhere}
    ";
    $stmt = $pdo->prepare($examSql);
    $stmt->execute(array_merge([$studentId], $params, $subjectTermParams));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $period = spaExamPeriod((string)$row['title']);
        if ($filters['assessment_type'] === null || $filters['assessment_type'] === ($period ?? 'Examination')) {
            $definitions[$row['assessment_key']] = true;
        }
    }

    if ($filters['assessment_type'] === null || $filters['assessment_type'] === 'Quiz') {
        $quizSql = "
            SELECT DISTINCT CONCAT('quiz_', q.id) AS assessment_key
            FROM quizzes q
            INNER JOIN subjects s ON s.id = q.subject_id
            INNER JOIN enrollments e ON e.subject_id = s.id AND e.student_id = ?
            INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
            " . spaDimensionJoins() . "
            LEFT JOIN quiz_assignments qa ON qa.quiz_id = q.id
            WHERE {$where}
                AND {$quizTermWhere}
                AND q.status = 'published' AND q.is_available = 1
                AND (q.availability_enabled = 0 OR q.opens_at IS NULL OR q.opens_at <= NOW())
                AND (q.due_date IS NULL OR q.due_date >= NOW())
                AND (q.section_id IS NULL OR q.section_id = COALESCE(sp.section_id, s.section_id))
                AND (
                    qa.id IS NULL OR qa.assignment_type = 'subject'
                    OR (qa.assignment_type = 'section' AND qa.target_id = COALESCE(sp.section_id, s.section_id))
                    OR (qa.assignment_type = 'program' AND qa.target_id = COALESCE(sp.program_id, s.program_id, sec.program_id))
                    OR (qa.assignment_type = 'year_level' AND qa.year_level = sp.year_level)
                    OR (qa.assignment_type = 'student' AND qa.target_id = e.student_id)
                )
        ";
        $stmt = $pdo->prepare($quizSql);
        $stmt->execute(array_merge([$studentId], $params, $quizTermParams));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $definitions[(string)$key] = true;
        }
    }

    if ($filters['assessment_type'] === null || $filters['assessment_type'] === 'Assignment') {
        $assignmentSql = "
            SELECT DISTINCT CONCAT('assignment_', a.id) AS assessment_key
            FROM assignments a
            INNER JOIN subjects s ON s.id = a.subject_id
            INNER JOIN enrollments e ON e.subject_id = s.id AND e.student_id = ?
            INNER JOIN users u ON u.id = e.student_id AND u.role = 'student'
            " . spaDimensionJoins() . "
            WHERE {$where}
                AND {$subjectTermWhere}
                AND a.status = 'active'
                AND (a.due_date IS NULL OR a.due_date >= NOW() OR a.allow_late_submission = 1)
        ";
        $stmt = $pdo->prepare($assignmentSql);
        $stmt->execute(array_merge([$studentId], $params, $subjectTermParams));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $definitions[(string)$key] = true;
        }
    }

    $completed = [];
    foreach ($assessmentRecords as $record) {
        if ((int)$record['student_id'] === $studentId && $record['source_type'] !== 'Gradebook') {
            $completed[$record['assessment_id']] = true;
        }
    }
    return count(array_diff_key($definitions, $completed));
}

function spaXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function spaExcelColumn(int $number): string
{
    $column = '';
    while ($number > 0) {
        $number--;
        $column = chr(65 + ($number % 26)) . $column;
        $number = intdiv($number, 26);
    }
    return $column;
}

function spaWorksheetXml(array $rows): string
{
    $xmlRows = '';
    foreach (array_values($rows) as $rowIndex => $row) {
        $rowNumber = $rowIndex + 1;
        $cells = '';
        foreach (array_values($row) as $columnIndex => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $reference = spaExcelColumn($columnIndex + 1) . $rowNumber;
            if (is_int($value) || is_float($value)) {
                $cells .= '<c r="' . $reference . '"><v>' . (string)$value . '</v></c>';
            } else {
                $cells .= '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">' . spaXml((string)$value) . '</t></is></c>';
            }
        }
        $xmlRows .= '<row r="' . $rowNumber . '">' . $cells . '</row>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $xmlRows . '</sheetData></worksheet>';
}

function spaSendXlsx(string $filename, array $sheets): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Excel export requires PHP ZipArchive support.');
    }
    $path = tempnam(sys_get_temp_dir(), 'lms_analytics_');
    if ($path === false) {
        throw new RuntimeException('A temporary workbook could not be created.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        unlink($path);
        throw new RuntimeException('The Excel workbook could not be opened.');
    }

    $sheetOverrides = '';
    $sheetRelations = '';
    $workbookSheets = '';
    foreach (array_values($sheets) as $index => $sheet) {
        $sheetNumber = $index + 1;
        $sheetOverrides .= '<Override PartName="/xl/worksheets/sheet' . $sheetNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $sheetRelations .= '<Relationship Id="rId' . $sheetNumber . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetNumber . '.xml"/>';
        $workbookSheets .= '<sheet name="' . spaXml(substr((string)$sheet['name'], 0, 31)) . '" sheetId="' . $sheetNumber . '" r:id="rId' . $sheetNumber . '"/>';
        $zip->addFromString('xl/worksheets/sheet' . $sheetNumber . '.xml', spaWorksheetXml($sheet['rows']));
    }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . $sheetOverrides . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $workbookSheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $sheetRelations . '</Relationships>');
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    unlink($path);
}
