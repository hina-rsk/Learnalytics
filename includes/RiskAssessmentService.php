<?php
/**
 * LearnAlytics - Centralized Risk Assessment Service
 * Single source of truth for at-risk student identification,
 * multi-factor risk score calculation, explainable risk reasons,
 * configurable thresholds, and intervention tracking.
 */

class RiskAssessmentService {

    private static function quizScorePercentage(float $score, int $questionCount, ?string $description): ?float {
        if ($questionCount <= 0 && $description !== null && stripos($description, 'Quiz Title:') === 0) {
            preg_match_all('/^Q\d+\./m', $description, $matches);
            $questionCount = count($matches[0]);
        }

        if ($questionCount <= 0) {
            return null;
        }

        return round(min(100.0, max(0.0, ($score / $questionCount) * 100.0)), 1);
    }

    /**
     * Get active risk configuration thresholds.
     */
    public static function getThresholds(PDO $pdo, ?int $department_id = null): array {
        $default = [
            'grade_threshold'              => 75.0,
            'attendance_threshold'         => 80.0,
            'quiz_threshold'               => 75.0,
            'decline_threshold'            => 10.0,
            'passing_grade'                => 75.0,
            'moderate_grade_threshold'     => 80.0,
            'moderate_attendance_threshold'=> 85.0,
        ];

        try {
            // First check department-specific config
            if ($department_id) {
                $stmt = $pdo->prepare("SELECT * FROM risk_configurations WHERE department_id = ? LIMIT 1");
                $stmt->execute([$department_id]);
                $deptCfg = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($deptCfg) {
                    return [
                        'grade_threshold'              => (float)$deptCfg['grade_threshold'],
                        'attendance_threshold'         => (float)$deptCfg['attendance_threshold'],
                        'quiz_threshold'               => (float)$deptCfg['quiz_threshold'],
                        'decline_threshold'            => (float)$deptCfg['decline_threshold'],
                        'passing_grade'                => (float)$deptCfg['passing_grade'],
                        'moderate_grade_threshold'     => (float)($deptCfg['moderate_grade_threshold'] ?? 80.0),
                        'moderate_attendance_threshold'=> (float)($deptCfg['moderate_attendance_threshold'] ?? 85.0),
                        'id'                           => (int)$deptCfg['id'],
                        'department_id'                => (int)$deptCfg['department_id'],
                        'updated_at'                   => $deptCfg['updated_at'],
                    ];
                }
            }

            // Fallback to global config (department_id IS NULL)
            $stmt = $pdo->query("SELECT * FROM risk_configurations WHERE department_id IS NULL LIMIT 1");
            $globalCfg = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($globalCfg) {
                return [
                    'grade_threshold'              => (float)$globalCfg['grade_threshold'],
                    'attendance_threshold'         => (float)$globalCfg['attendance_threshold'],
                    'quiz_threshold'               => (float)$globalCfg['quiz_threshold'],
                    'decline_threshold'            => (float)$globalCfg['decline_threshold'],
                    'passing_grade'                => (float)$globalCfg['passing_grade'],
                    'moderate_grade_threshold'     => (float)($globalCfg['moderate_grade_threshold'] ?? 80.0),
                    'moderate_attendance_threshold'=> (float)($globalCfg['moderate_attendance_threshold'] ?? 85.0),
                    'id'                           => (int)$globalCfg['id'],
                    'department_id'                => null,
                    'updated_at'                   => $globalCfg['updated_at'],
                ];
            }
        } catch (Exception $e) {
            // In case table not yet created
        }

        return $default;
    }

    /**
     * Save or update risk configuration thresholds.
     */
    public static function saveThresholds(PDO $pdo, ?int $department_id, array $data, ?int $user_id = null): bool {
        $gradeThresh      = max(40.0, min(100.0, (float)($data['grade_threshold'] ?? 75.0)));
        $attThresh        = max(40.0, min(100.0, (float)($data['attendance_threshold'] ?? 80.0)));
        $quizThresh       = max(40.0, min(100.0, (float)($data['quiz_threshold'] ?? 75.0)));
        $declineThresh    = max(1.0,  min(50.0,  (float)($data['decline_threshold'] ?? 10.0)));
        $passingGrade     = max(40.0, min(100.0, (float)($data['passing_grade'] ?? 75.0)));
        $modGradeThresh   = max($gradeThresh, min(100.0, (float)($data['moderate_grade_threshold'] ?? 80.0)));
        $modAttThresh     = max($attThresh,   min(100.0, (float)($data['moderate_attendance_threshold'] ?? 85.0)));

        $checkStmt = $pdo->prepare("SELECT id FROM risk_configurations WHERE " . ($department_id ? "department_id = ?" : "department_id IS NULL"));
        $checkStmt->execute($department_id ? [$department_id] : []);
        $existingId = $checkStmt->fetchColumn();

        if ($existingId) {
            $stmt = $pdo->prepare("
                UPDATE risk_configurations
                SET grade_threshold = ?, attendance_threshold = ?, quiz_threshold = ?, decline_threshold = ?,
                    passing_grade = ?, moderate_grade_threshold = ?, moderate_attendance_threshold = ?,
                    updated_by = ?, updated_at = NOW()
                WHERE id = ?
            ");
            return $stmt->execute([$gradeThresh, $attThresh, $quizThresh, $declineThresh, $passingGrade, $modGradeThresh, $modAttThresh, $user_id, $existingId]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO risk_configurations
                    (department_id, grade_threshold, attendance_threshold, quiz_threshold, decline_threshold,
                     passing_grade, moderate_grade_threshold, moderate_attendance_threshold, updated_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            return $stmt->execute([$department_id, $gradeThresh, $attThresh, $quizThresh, $declineThresh, $passingGrade, $modGradeThresh, $modAttThresh, $user_id]);
        }
    }

    /**
     * Centralized Student Risk Assessment Function.
     * Computes all actual measurable academic metrics from DB:
     * - Course grades (Prelim, Midterm, and Final)
     * - Quiz results
     * - Attendance records (present, absent, late)
     * - Performance trend (previous vs current average, delta %)
     * - Low-performing / failing courses
     * - Transparent overall risk score (0-100) & classification
     * - Explainable bullet points: "Why is this student at risk?"
     * - Recommended interventions
     */
    public static function calculateStudentRisk(PDO $pdo, int $student_id, ?int $department_id = null, bool $saveSnapshot = false): array {
        $thresholds = self::getThresholds($pdo, $department_id);

        // 1. Fetch Student Profile and Program/Department info
        $profileStmt = $pdo->prepare("
            SELECT sp.*, u.name, u.email, u.profile_picture,
                   p.name AS program_name, p.id AS prog_id,
                   d.name AS department_name, d.id AS dept_id
            FROM users u
            LEFT JOIN student_profiles sp ON sp.student_id = u.id
            LEFT JOIN programs p ON p.id = sp.program_id
            LEFT JOIN departments d ON d.id = COALESCE(sp.department_id, u.department_id)
            WHERE u.id = ?
        ");
        $profileStmt->execute([$student_id]);
        $student = $profileStmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            return [
                'student_id' => $student_id,
                'found'      => false,
                'risk_level' => 'low',
                'risk_score' => 0,
            ];
        }

        // 2. Fetch All Course Enrollments with Course Details
        $coursesStmt = $pdo->prepare("
            SELECT s.id AS subject_id, s.title AS course_title, s.class_code,
                   s.units, s.semester, s.year_level,
                   u.name AS instructor_name
            FROM enrollments e
            INNER JOIN subjects s ON s.id = e.subject_id
            LEFT JOIN users u ON u.id = s.instructor_id
            WHERE e.student_id = ?
            ORDER BY s.title ASC
        ");
        $coursesStmt->execute([$student_id]);
        $courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Collect assignment and exam scores for the assessment trend
        // Check assignments & submissions
        $subStmt = $pdo->prepare("
            SELECT sub.assignment_id, sub.grade, sub.submitted_at,
                   a.subject_id, a.title AS assignment_title, a.points
            FROM submissions sub
            INNER JOIN assignments a ON a.id = sub.assignment_id
            WHERE sub.student_id = ? AND sub.grade IS NOT NULL
            ORDER BY sub.submitted_at ASC
        ");
        $subStmt->execute([$student_id]);
        $submissions = $subStmt->fetchAll(PDO::FETCH_ASSOC);

        // Check exam scores
        $examStmt = $pdo->prepare("
            SELECT es.score, es.recorded_at,
                   er.id AS exam_id, er.subject_id, er.title AS exam_title, er.total_items
            FROM exam_scores es
            INNER JOIN exam_records er ON er.id = es.exam_id
            WHERE es.student_id = ?
            ORDER BY er.exam_date ASC, es.recorded_at ASC
        ");
        $examStmt->execute([$student_id]);
        $examScores = $examStmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate assignment & exam percentages
        $allAcademicGrades = [];

        foreach ($submissions as $sub) {
            $raw = $sub['grade'];
            $pct = null;
            if (is_numeric($raw)) {
                $maxPts = !empty($sub['points']) && (float)$sub['points'] > 0 ? (float)$sub['points'] : 100.0;
                $pct = min(100.0, max(0.0, ((float)$raw / $maxPts) * 100.0));
            } else {
                // Letter grades mapping
                $letterMap = [
                    'A' => 95, 'A-' => 91, 'B+' => 88, 'B' => 85, 'B-' => 82,
                    'C+' => 79, 'C' => 76, 'C-' => 73, 'D+' => 70, 'D' => 67, 'F' => 50
                ];
                $clean = strtoupper(trim($raw));
                if (isset($letterMap[$clean])) {
                    $pct = (float)$letterMap[$clean];
                }
            }
            if ($pct !== null) {
                $allAcademicGrades[] = [
                    'score'       => $pct,
                    'date'        => $sub['submitted_at'],
                    'type'        => 'assignment',
                    'title'       => $sub['assignment_title'],
                    'subject_id'  => (int)$sub['subject_id'],
                ];
            }
        }

        foreach ($examScores as $ex) {
            $totalItems = !empty($ex['total_items']) && (float)$ex['total_items'] > 0 ? (float)$ex['total_items'] : 100.0;
            $pct = min(100.0, max(0.0, ((float)$ex['score'] / $totalItems) * 100.0));
            $allAcademicGrades[] = [
                'score'       => $pct,
                'date'        => $ex['recorded_at'],
                'type'        => 'exam',
                'title'       => $ex['exam_title'],
                'subject_id'  => (int)$ex['subject_id'],
            ];
        }

        // Course performance is based on the grades entered by instructors, not assignment scores.
        $courseGradesStmt = $pdo->prepare("
            SELECT sg.subject_id, sg.prelim_grade, sg.midterm_grade, sg.final_grade
            FROM student_grades sg
            INNER JOIN enrollments e
                ON e.student_id = sg.student_id AND e.subject_id = sg.subject_id
            WHERE sg.student_id = ?
        ");
        $courseGradesStmt->execute([$student_id]);
        $courseGradesMap = [];
        foreach ($courseGradesStmt->fetchAll(PDO::FETCH_ASSOC) as $courseGrade) {
            $periodScores = [];
            foreach (['prelim', 'midterm', 'final'] as $period) {
                $gradeColumn = $period . '_grade';
                if ($courseGrade[$gradeColumn] !== null && is_numeric($courseGrade[$gradeColumn])) {
                    $periodScores[$period] = (float)$courseGrade[$gradeColumn];
                }
            }
            if ($periodScores) {
                $courseGradesMap[(int)$courseGrade['subject_id']] = $periodScores;
            }
        }

        // 4. Collect Quiz Results
        $quizStmt = $pdo->prepare("
            SELECT qr.id AS result_id, qr.quiz_id, qr.score, qr.taken_at,
                   q.title AS quiz_title, q.subject_id, q.description,
                   (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) AS question_count,
                   s.title AS course_title
            FROM quiz_results qr
            INNER JOIN quizzes q ON q.id = qr.quiz_id
            INNER JOIN subjects s ON s.id = q.subject_id
            WHERE qr.student_id = ?
            ORDER BY qr.taken_at ASC
        ");
        $quizStmt->execute([$student_id]);
        $quizzes = $quizStmt->fetchAll(PDO::FETCH_ASSOC);

        $quizScores = [];
        $quizList   = [];
        foreach ($quizzes as $q) {
            $score = self::quizScorePercentage(
                (float)$q['score'],
                (int)$q['question_count'],
                $q['description'] ?? null
            );
            if ($score === null) {
                continue;
            }
            $quizScores[] = $score;

            $qStatus = 'good';
            if ($score < $thresholds['quiz_threshold']) {
                $qStatus = 'at_risk';
            } elseif ($score < $thresholds['moderate_grade_threshold']) {
                $qStatus = 'low';
            }

            $quizList[] = [
                'result_id'    => (int)$q['result_id'],
                'quiz_id'      => (int)$q['quiz_id'],
                'title'        => $q['quiz_title'],
                'course_title' => $q['course_title'],
                'date'         => $q['taken_at'],
                'score'        => $score,
                'percentage'   => $score,
                'status'       => $qStatus,
            ];
        }

        // 5. Collect Attendance Data
        $attStmt = $pdo->prepare("
            SELECT a.subject_id, a.attendance_date, a.status,
                   s.title AS course_title
            FROM attendance a
            INNER JOIN subjects s ON s.id = a.subject_id
            WHERE a.student_id = ?
            ORDER BY a.attendance_date ASC
        ");
        $attStmt->execute([$student_id]);
        $attendanceRecords = $attStmt->fetchAll(PDO::FETCH_ASSOC);

        $attTotal   = count($attendanceRecords);
        $attPresent = 0;
        $attAbsent  = 0;
        $attLate    = 0;
        $attExcused = 0;
        $courseAttendanceMap = [];

        foreach ($attendanceRecords as $att) {
            $st = strtolower($att['status']);
            $subId = (int)$att['subject_id'];
            if (!isset($courseAttendanceMap[$subId])) {
                $courseAttendanceMap[$subId] = ['present' => 0, 'total' => 0];
            }
            $courseAttendanceMap[$subId]['total']++;

            if ($st === 'present') {
                $attPresent++;
                $courseAttendanceMap[$subId]['present']++;
            } elseif ($st === 'absent') {
                $attAbsent++;
            } elseif ($st === 'late') {
                $attLate++;
                // In educational analytics, late often counts as 0.5 or present with attendance mark
                $courseAttendanceMap[$subId]['present'] += 0.5;
            } elseif ($st === 'excused') {
                $attExcused++;
                $courseAttendanceMap[$subId]['present']++;
            }
        }

        $attendanceRate = $attTotal > 0 ? round((($attPresent + ($attLate * 0.5) + $attExcused) / $attTotal) * 100, 1) : null;

        // 6. Calculate course-grade and quiz averages independently.
        $courseAverages = [];
        $periodGradeTotals = ['prelim' => 0.0, 'midterm' => 0.0, 'final' => 0.0];
        $periodGradeCounts = ['prelim' => 0, 'midterm' => 0, 'final' => 0];
        foreach ($courseGradesMap as $periodScores) {
            $courseAverages[] = array_sum($periodScores) / count($periodScores);
            foreach ($periodScores as $period => $score) {
                $periodGradeTotals[$period] += $score;
                $periodGradeCounts[$period]++;
            }
        }
        $gradeAverage = $courseAverages
            ? round(array_sum($courseAverages) / count($courseAverages), 1)
            : null;

        $quizAverage = null;
        if (!empty($quizScores)) {
            $quizAverage = round(array_sum($quizScores) / count($quizScores), 1);
        }

        // Keep the displayed overall grade and grade-risk factor tied to course grades.
        $overallAcademicScore = $gradeAverage;

        // 7. Calculate course-grade trend by grading period, not assignment activity.
        $allAssessmentEvents = [];
        foreach ($allAcademicGrades as $g) {
            $allAssessmentEvents[] = [
                'score' => $g['score'],
                'date'  => $g['date'],
            ];
        }
        foreach ($quizList as $q) {
            $allAssessmentEvents[] = [
                'score' => $q['score'],
                'date'  => $q['date'],
            ];
        }
        usort($allAssessmentEvents, function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        $previousAverage = null;
        $currentAverage  = $overallAcademicScore;
        $trendChange     = 0.0;
        $trendDirection  = 'stable';

        $periodAverages = [];
        foreach (['prelim', 'midterm', 'final'] as $period) {
            if ($periodGradeCounts[$period] > 0) {
                $periodAverages[] = round($periodGradeTotals[$period] / $periodGradeCounts[$period], 1);
            }
        }
        if ($periodAverages) {
            $currentAverage = end($periodAverages);
        }
        if (count($periodAverages) >= 2) {
            $previousAverage = $periodAverages[count($periodAverages) - 2];
            $trendChange = round($currentAverage - $previousAverage, 1);

            if ($trendChange <= -1 * $thresholds['decline_threshold']) {
                $trendDirection = 'declining';
            } elseif ($trendChange >= 4.0) {
                $trendDirection = 'improving';
            } else {
                $trendDirection = 'stable';
            }
        } elseif (count($periodAverages) === 1) {
            $previousAverage = $currentAverage;
        }

        // 8. Analyze Each Course (highlight courses contributing to risk)
        $detailedCourses = [];
        $failedCoursesCount = 0;

        foreach ($courses as $c) {
            $cid = (int)$c['subject_id'];
            $cScores = $courseGradesMap[$cid] ?? [];
            $cAvg = !empty($cScores) ? round(array_sum($cScores) / count($cScores), 1) : null;

            $cAttData = $courseAttendanceMap[$cid] ?? null;
            $cAttRate = ($cAttData && $cAttData['total'] > 0) ? round(($cAttData['present'] / $cAttData['total']) * 100, 1) : null;

            $isCourseAtRisk = false;
            $courseStatus = 'Good';

            if ($cAvg !== null) {
                if ($cAvg < $thresholds['passing_grade']) {
                    $isCourseAtRisk = true;
                    $courseStatus = 'At Risk';
                    $failedCoursesCount++;
                } elseif ($cAvg < $thresholds['moderate_grade_threshold']) {
                    $courseStatus = 'Moderate';
                }
            }

            if ($cAttRate !== null) {
                if ($cAttRate < $thresholds['attendance_threshold']) {
                    $isCourseAtRisk = true;
                    if ($courseStatus !== 'At Risk') {
                        $courseStatus = 'At Risk (Attendance)';
                    }
                } elseif ($cAttRate < $thresholds['moderate_attendance_threshold'] && $courseStatus === 'Good') {
                    $courseStatus = 'Moderate (Attendance)';
                }
            }

            $detailedCourses[] = [
                'subject_id'      => $cid,
                'course_title'    => $c['course_title'],
                'class_code'      => $c['class_code'] ?? '—',
                'instructor_name' => $c['instructor_name'] ?? 'Unassigned',
                'grade'           => $cAvg,
                'attendance_rate' => $cAttRate,
                'status'          => $courseStatus,
                'is_at_risk'      => $isCourseAtRisk,
            ];
        }

        // 9. Risk Score Calculation (0 to 100 composite risk score)
        // Higher score = Higher Risk severity.
        // We also calculate status for each factor table.
        $riskScore = 0;
        $reasons = [];
        $recommendedInterventions = [];

        // --- Factor A: Grades ---
        $gradeFactorStatus = 'Good';
        if ($overallAcademicScore !== null) {
            if ($overallAcademicScore < $thresholds['grade_threshold']) {
                $gradeFactorStatus = 'At Risk';
                $shortfall = $thresholds['grade_threshold'] - $overallAcademicScore;
                $riskScore += min(35, 20 + ($shortfall * 1.5));
                $reasons[] = "Overall academic average ({$overallAcademicScore}%) is below the configured threshold ({$thresholds['grade_threshold']}%).";
                $recommendedInterventions[] = 'Academic Advising';
                $recommendedInterventions[] = 'Tutoring';
            } elseif ($overallAcademicScore < $thresholds['moderate_grade_threshold']) {
                $gradeFactorStatus = 'Moderate';
                $riskScore += 15;
            }
        } else {
            // No academic scores recorded yet
            $gradeFactorStatus = 'No Data';
        }

        // --- Factor B: Quiz Results ---
        $quizFactorStatus = 'Good';
        if ($quizAverage !== null) {
            if ($quizAverage < $thresholds['quiz_threshold']) {
                $quizFactorStatus = 'At Risk';
                $quizShortfall = $thresholds['quiz_threshold'] - $quizAverage;
                $riskScore += min(25, 15 + ($quizShortfall * 1.0));
                $reasons[] = "Quiz average ({$quizAverage}%) is below the configured threshold ({$thresholds['quiz_threshold']}%).";
                if (!in_array('Tutoring', $recommendedInterventions)) $recommendedInterventions[] = 'Tutoring';
                if (!in_array('Study Plan', $recommendedInterventions)) $recommendedInterventions[] = 'Study Plan';
            } elseif ($quizAverage < $thresholds['moderate_grade_threshold']) {
                $quizFactorStatus = 'Moderate';
                $riskScore += 10;
            }
        } else {
            $quizFactorStatus = 'No Data';
        }

        // --- Factor C: Attendance ---
        $attendanceFactorStatus = 'Good';
        if ($attendanceRate !== null) {
            if ($attendanceRate < $thresholds['attendance_threshold']) {
                $attendanceFactorStatus = 'At Risk';
                $attShortfall = $thresholds['attendance_threshold'] - $attendanceRate;
                $riskScore += min(25, 15 + ($attShortfall * 1.0));
                $reasons[] = "Attendance rate ({$attendanceRate}%) is below the configured threshold ({$thresholds['attendance_threshold']}%), with {$attAbsent} absence(s) and {$attLate} late record(s).";
                $recommendedInterventions[] = 'Attendance Intervention';
                $recommendedInterventions[] = 'Parent/Guardian Notification';
            } elseif ($attendanceRate < $thresholds['moderate_attendance_threshold']) {
                $attendanceFactorStatus = 'Moderate';
                $riskScore += 10;
            }
        } else {
            $attendanceFactorStatus = 'No Data';
        }

        // --- Factor D: Performance Trend ---
        $trendFactorStatus = 'Stable';
        if ($trendDirection === 'declining') {
            $trendFactorStatus = 'Declining';
            $declineAbs = abs($trendChange);
            $riskScore += min(20, 10 + ($declineAbs * 0.8));
            $reasons[] = "Academic performance declined by {$declineAbs}% compared with previous period.";
            if (!in_array('Faculty Consultation', $recommendedInterventions)) $recommendedInterventions[] = 'Faculty Consultation';
            if (!in_array('Academic Monitoring', $recommendedInterventions)) $recommendedInterventions[] = 'Academic Monitoring';
        } elseif ($trendDirection === 'improving') {
            $trendFactorStatus = 'Improving';
            $riskScore = max(0, $riskScore - 10);
        }

        // --- Factor E: Low-performing Courses ---
        if ($failedCoursesCount > 0) {
            $riskScore += min(20, $failedCoursesCount * 10);
            $reasons[] = "{$failedCoursesCount} course(s) currently below the passing threshold ({$thresholds['passing_grade']}%).";
            if (!in_array('Faculty Consultation', $recommendedInterventions)) $recommendedInterventions[] = 'Faculty Consultation';
        }

        $riskScore = (int)round(max(0, min(100, $riskScore)));

        // Determine Overall Risk Level
        // 🔴 At Risk: Score >= 55 OR triggers critical thresholds (grade < 75%, attendance < 80%, critical decline, or 2+ risk reasons)
        // 🟡 Moderate Risk: Score >= 25 OR grade < moderate_grade_threshold (80%) OR attendance < moderate_attendance_threshold (85%) OR quiz < moderate_grade_threshold
        // 🟢 Low Risk: All metrics consistently in good standing
        $isAtRisk = (
            $riskScore >= 55 ||
            ($overallAcademicScore !== null && $overallAcademicScore < $thresholds['grade_threshold']) ||
            ($attendanceRate !== null && $attendanceRate < $thresholds['attendance_threshold']) ||
            ($trendDirection === 'declining' && abs($trendChange) >= $thresholds['decline_threshold']) ||
            $failedCoursesCount >= 1 ||
            count($reasons) >= 2
        );

        $isModerate = (
            $riskScore >= 25 ||
            ($overallAcademicScore !== null && $overallAcademicScore < $thresholds['moderate_grade_threshold']) ||
            ($attendanceRate !== null && $attendanceRate < $thresholds['moderate_attendance_threshold']) ||
            ($quizAverage !== null && $quizAverage < $thresholds['moderate_grade_threshold']) ||
            $trendDirection === 'declining'
        );

        if ($isAtRisk) {
            $riskLevel = 'at_risk';
        } elseif ($isModerate) {
            $riskLevel = 'moderate';
        } else {
            $riskLevel = 'low';
        }

        if (empty($reasons)) {
            $reasons[] = "Student is performing consistently within or above academic thresholds.";
        }

        if (empty($recommendedInterventions)) {
            $recommendedInterventions[] = 'Regular Academic Monitoring';
        }

        // 10. Fetch Intervention Records for this student
        $interventions = self::getStudentInterventions($pdo, $student_id);

        // 11. Fetch Historical Risk Assessments
        $historyStmt = $pdo->prepare("
            SELECT id, assessed_at, overall_score, risk_level, grade_avg, attendance_rate, reasons
            FROM risk_assessments
            WHERE student_id = ?
            ORDER BY assessed_at DESC
            LIMIT 10
        ");
        $historyStmt->execute([$student_id]);
        $riskHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'found'                      => true,
            'student_id'                 => $student_id,
            'student_id_number'          => $student['student_id_number'] ?? '—',
            'name'                       => $student['name'],
            'email'                      => $student['email'],
            'program_name'               => $student['program_name'] ?? 'Unassigned',
            'program_id'                 => $student['program_id'] ?? null,
            'department_id'              => $student['dept_id'] ?? null,
            'department_name'            => $student['department_name'] ?? '—',
            'year_level'                 => $student['year_level'] ?? '—',
            'enrollment_status'          => $student['enrollment_status'] ?? 'Active',
            'profile_picture'            => $student['profile_picture'] ?? null,

            // Overall risk status
            'risk_level'                 => $riskLevel,            // 'at_risk', 'moderate', 'low'
            'risk_score'                 => $riskScore,            // 0 - 100
            'risk_badge_label'           => $riskLevel === 'at_risk' ? 'At Risk' : ($riskLevel === 'moderate' ? 'Moderate Risk' : 'Low Risk'),
            'risk_badge_class'           => $riskLevel === 'at_risk' ? 'b-risk' : ($riskLevel === 'moderate' ? 'b-mid' : 'b-ok'),
            'risk_color'                 => $riskLevel === 'at_risk' ? '#dc2626' : ($riskLevel === 'moderate' ? '#d97706' : '#16a34a'),
            'reasons'                    => $reasons,
            'recommended_interventions'  => $recommendedInterventions,

            // Factor breakdown
            'factor_breakdown'           => [
                [
                    'factor' => 'Grades',
                    'score'  => $overallAcademicScore !== null ? $overallAcademicScore . '%' : 'No Data',
                    'raw'    => $overallAcademicScore,
                    'status' => $gradeFactorStatus,
                ],
                [
                    'factor' => 'Quiz Results',
                    'score'  => $quizAverage !== null ? $quizAverage . '%' : 'No Data',
                    'raw'    => $quizAverage,
                    'status' => $quizFactorStatus,
                ],
                [
                    'factor' => 'Attendance',
                    'score'  => $attendanceRate !== null ? $attendanceRate . '%' : 'No Data',
                    'raw'    => $attendanceRate,
                    'status' => $attendanceFactorStatus,
                ],
                [
                    'factor' => 'Performance Trend',
                    'score'  => ($trendChange >= 0 ? '+' : '') . $trendChange . '%',
                    'raw'    => $trendChange,
                    'status' => ucfirst($trendFactorStatus),
                ],
            ],

            // Academic performance metrics
            'overall_grade'              => $overallAcademicScore,
            'grade_average'              => $gradeAverage,
            'quiz_average'               => $quizAverage,
            'previous_average'           => $previousAverage,
            'current_average'            => $currentAverage,
            'trend_change'               => $trendChange,
            'trend_direction'            => $trendDirection,

            // Attendance details
            'attendance_rate'            => $attendanceRate,
            'attendance_present'         => $attPresent,
            'attendance_absent'          => $attAbsent,
            'attendance_late'            => $attLate,
            'attendance_excused'         => $attExcused,
            'attendance_total'           => $attTotal,
            'attendance_records'         => $attendanceRecords,

            // Course & quiz details
            'courses'                    => $detailedCourses,
            'failed_courses_count'       => $failedCoursesCount,
            'quizzes'                    => $quizList,
            'assessment_events'          => $allAssessmentEvents,

            // Interventions & historical records
            'interventions'              => $interventions,
            'risk_history'               => $riskHistory,
            'thresholds_used'            => $thresholds,
        ];

        // 12. Save snapshot if requested and not recently saved
        if ($saveSnapshot) {
            self::saveRiskSnapshot($pdo, $result);
        }

        return $result;
    }

    /**
     * Save a historical snapshot in risk_assessments.
     */
    public static function saveRiskSnapshot(PDO $pdo, array $riskData): void {
        try {
            $studentId = (int)$riskData['student_id'];
            $deptId    = !empty($riskData['department_id']) ? (int)$riskData['department_id'] : null;

            // Only insert if no assessment recorded within the last 12 hours for this student
            $checkStmt = $pdo->prepare("
                SELECT id FROM risk_assessments 
                WHERE student_id = ? AND assessed_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
                LIMIT 1
            ");
            $checkStmt->execute([$studentId]);
            if ($checkStmt->fetchColumn()) {
                return; // Already has a recent snapshot
            }

            $stmt = $pdo->prepare("
                INSERT INTO risk_assessments (
                    student_id, department_id, assessed_at, overall_score, risk_level,
                    grade_avg, previous_grade_avg, grade_change_pct, quiz_avg,
                    attendance_rate, absent_count, late_count, present_count,
                    failing_courses_count, trend_direction, reasons, recommended_intervention
                ) VALUES (
                    ?, ?, NOW(), ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?
                )
            ");

            $reasonsText = implode("\n", $riskData['reasons'] ?? []);
            $recText     = implode(", ", $riskData['recommended_interventions'] ?? []);

            $stmt->execute([
                $studentId,
                $deptId,
                (int)$riskData['risk_score'],
                $riskData['risk_level'],
                $riskData['overall_grade'],
                $riskData['previous_average'],
                $riskData['trend_change'],
                $riskData['quiz_average'],
                $riskData['attendance_rate'],
                (int)$riskData['attendance_absent'],
                (int)$riskData['attendance_late'],
                (int)$riskData['attendance_present'],
                (int)$riskData['failed_courses_count'],
                $riskData['trend_direction'],
                $reasonsText,
                $recText,
            ]);
        } catch (Exception $e) {
            // Silently pass snapshot save failures to avoid blocking page load
        }
    }

    /**
     * Fetch all interventions for a student.
     */
    public static function getStudentInterventions(PDO $pdo, int $student_id): array {
        try {
            $stmt = $pdo->prepare("
                SELECT si.*, u.name AS assigned_by_name
                FROM student_interventions si
                LEFT JOIN users u ON u.id = si.assigned_by
                WHERE si.student_id = ?
                ORDER BY si.scheduled_date DESC, si.created_at DESC
            ");
            $stmt->execute([$student_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Record a new student intervention.
     */
    public static function recordIntervention(PDO $pdo, array $data): int {
        $studentId   = (int)$data['student_id'];
        $assignedBy  = !empty($data['assigned_by']) ? (int)$data['assigned_by'] : null;
        $type        = trim($data['intervention_type'] ?? 'Academic Advising');
        $status      = trim($data['status'] ?? 'recommended');
        $schedDate   = !empty($data['scheduled_date']) ? $data['scheduled_date'] : date('Y-m-d');
        $notes       = trim($data['notes'] ?? '');
        $gradeBefore = !empty($data['grade_before']) ? (float)$data['grade_before'] : null;
        $gradeAfter  = !empty($data['grade_after_2wk']) ? (float)$data['grade_after_2wk'] : null;
        $gradeCurr   = !empty($data['grade_current']) ? (float)$data['grade_current'] : null;

        $stmt = $pdo->prepare("
            INSERT INTO student_interventions (
                student_id, assigned_by, intervention_type, status,
                scheduled_date, notes, grade_before, grade_after_2wk, grade_current, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $studentId, $assignedBy, $type, $status,
            $schedDate, $notes, $gradeBefore, $gradeAfter, $gradeCurr
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Update an intervention's status and notes.
     */
    public static function updateIntervention(PDO $pdo, int $intervention_id, string $status, ?string $notes = null, ?float $gradeCurrent = null): bool {
        $completedDate = ($status === 'completed') ? date('Y-m-d') : null;
        $stmt = $pdo->prepare("
            UPDATE student_interventions
            SET status = ?,
                notes = COALESCE(?, notes),
                grade_current = COALESCE(?, grade_current),
                completed_date = CASE WHEN ? = 'completed' THEN COALESCE(completed_date, NOW()) ELSE completed_date END,
                updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$status, $notes, $gradeCurrent, $status, $intervention_id]);
    }

    /**
     * Get all students in a department with their calculated risk metrics.
     */
    public static function getDepartmentStudents(PDO $pdo, int $department_id, ?string $riskFilter = null, ?int $programId = null): array {
        $sql = "
            SELECT DISTINCT u.id AS student_id
            FROM users u
            INNER JOIN student_profiles sp ON sp.student_id = u.id
            WHERE (sp.department_id = ? OR (sp.department_id IS NULL AND u.department_id = ?))
              AND u.role = 'student'
        ";
        $params = [$department_id, $department_id];

        if ($programId && $programId > 0) {
            $sql .= " AND sp.program_id = ?";
            $params[] = $programId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $studentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $results = [];
        foreach ($studentIds as $sId) {
            $risk = self::calculateStudentRisk($pdo, (int)$sId, $department_id, false);
            if ($risk['found']) {
                $results[] = $risk;
            }
        }

        // Sort: Critical at-risk first, then by risk_score DESC, then average ASC
        usort($results, function($a, $b) {
            $order = ['at_risk' => 3, 'moderate' => 2, 'low' => 1];
            $diff = ($order[$b['risk_level']] ?? 0) - ($order[$a['risk_level']] ?? 0);
            if ($diff !== 0) return $diff;
            return $b['risk_score'] <=> $a['risk_score'];
        });

        // Apply filter if specified
        if ($riskFilter && in_array($riskFilter, ['at_risk', 'moderate', 'low'])) {
            $results = array_values(array_filter($results, fn($s) => $s['risk_level'] === $riskFilter));
        }

        return $results;
    }

    /**
     * Get Department-level summary counts and statistics.
     */
    public static function getDepartmentSummary(PDO $pdo, int $department_id): array {
        $students = self::getDepartmentStudents($pdo, $department_id);

        $atRiskCount   = 0;
        $moderateCount = 0;
        $lowRiskCount  = 0;
        $improvingCount = 0;
        $decliningCount = 0;

        $totalGradeSum = 0;
        $gradeCount    = 0;
        $totalQuizSum  = 0;
        $quizCount     = 0;
        $totalAttSum   = 0;
        $attCount      = 0;

        foreach ($students as $s) {
            if ($s['risk_level'] === 'at_risk') {
                $atRiskCount++;
            } elseif ($s['risk_level'] === 'moderate') {
                $moderateCount++;
            } else {
                $lowRiskCount++;
            }

            if ($s['trend_direction'] === 'improving') $improvingCount++;
            if ($s['trend_direction'] === 'declining') $decliningCount++;

            if ($s['overall_grade'] !== null) {
                $totalGradeSum += $s['overall_grade'];
                $gradeCount++;
            }
            if ($s['quiz_average'] !== null) {
                $totalQuizSum += $s['quiz_average'];
                $quizCount++;
            }
            if ($s['attendance_rate'] !== null) {
                $totalAttSum += $s['attendance_rate'];
                $attCount++;
            }
        }

        $totalAssessed = count($students);

        return [
            'total_students'      => $totalAssessed,
            'at_risk_count'       => $atRiskCount,
            'moderate_count'      => $moderateCount,
            'low_risk_count'      => $lowRiskCount,
            'improving_count'     => $improvingCount,
            'declining_count'     => $decliningCount,
            'avg_grade'           => $gradeCount > 0 ? round($totalGradeSum / $gradeCount, 1) : null,
            'avg_quiz_score'      => $quizCount > 0 ? round($totalQuizSum / $quizCount, 1) : null,
            'avg_attendance'      => $attCount > 0 ? round($totalAttSum / $attCount, 1) : null,
            'students'            => $students,
        ];
    }

    /**
     * Course-Level At-Risk Analysis.
     */
    public static function getCourseRiskAnalysis(PDO $pdo, int $course_id, ?int $department_id = null): array {
        $thresholds = self::getThresholds($pdo, $department_id);

        $stmt = $pdo->prepare("
            SELECT s.id, s.title, s.class_code, u.name AS instructor_name
            FROM subjects s
            LEFT JOIN users u ON u.id = s.instructor_id
            WHERE s.id = ?
        ");
        $stmt->execute([$course_id]);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$course) return [];

        // Fetch enrolled students
        $enrolledStmt = $pdo->prepare("SELECT student_id FROM enrollments WHERE subject_id = ?");
        $enrolledStmt->execute([$course_id]);
        $studentIds = $enrolledStmt->fetchAll(PDO::FETCH_COLUMN);

        $totalEnrolled = count($studentIds);
        $courseGrades = [];
        $courseQuizzes = [];
        $courseAttendance = [];
        $atRiskStudents = [];

        foreach ($studentIds as $sid) {
            $studentRisk = self::calculateStudentRisk($pdo, (int)$sid, $department_id, false);
            // Find this course in student's course list
            $thisCourse = null;
            foreach ($studentRisk['courses'] as $c) {
                if ($c['subject_id'] == $course_id) {
                    $thisCourse = $c;
                    break;
                }
            }

            if ($thisCourse) {
                if ($thisCourse['grade'] !== null) $courseGrades[] = $thisCourse['grade'];
                if ($thisCourse['attendance_rate'] !== null) $courseAttendance[] = $thisCourse['attendance_rate'];
                if ($thisCourse['is_at_risk'] || $studentRisk['risk_level'] === 'at_risk') {
                    $atRiskStudents[] = [
                        'student_id'        => $sid,
                        'name'              => $studentRisk['name'],
                        'student_id_number' => $studentRisk['student_id_number'],
                        'grade'             => $thisCourse['grade'],
                        'attendance_rate'   => $thisCourse['attendance_rate'],
                        'overall_risk'      => $studentRisk['risk_level'],
                        'reasons'           => $studentRisk['reasons'],
                    ];
                }
            }
        }

        // Fetch quiz results for this subject
        $qStmt = $pdo->prepare("
            SELECT qr.score, q.description,
                   (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) AS question_count
            FROM quiz_results qr
            INNER JOIN quizzes q ON q.id = qr.quiz_id
            WHERE q.subject_id = ?
        ");
        $qStmt->execute([$course_id]);
        $quizScores = [];
        foreach ($qStmt->fetchAll(PDO::FETCH_ASSOC) as $quizResult) {
            $percentage = self::quizScorePercentage(
                (float)$quizResult['score'],
                (int)$quizResult['question_count'],
                $quizResult['description'] ?? null
            );
            if ($percentage !== null) {
                $quizScores[] = $percentage;
            }
        }

        return [
            'course_id'        => $course_id,
            'title'            => $course['title'],
            'class_code'       => $course['class_code'],
            'instructor_name'  => $course['instructor_name'] ?? 'Unassigned',
            'total_students'   => $totalEnrolled,
            'avg_grade'        => count($courseGrades) > 0 ? round(array_sum($courseGrades) / count($courseGrades), 1) : null,
            'avg_quiz_score'   => count($quizScores) > 0 ? round(array_sum($quizScores) / count($quizScores), 1) : null,
            'avg_attendance'   => count($courseAttendance) > 0 ? round(array_sum($courseAttendance) / count($courseAttendance), 1) : null,
            'at_risk_count'    => count($atRiskStudents),
            'at_risk_students' => $atRiskStudents,
        ];
    }
}
