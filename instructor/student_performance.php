<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/student_performance_lib.php';

$role = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['instructor', 'dean', 'admin'], true)) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$access = spaAnalyticsAccess($pdo, $userId, $role);
$pageTitle = 'Student Performance Analytics - LearnAlytics';
$extraCss = ['assets/css/student-performance.css'];
$errorMessage = '';
$successMessage = $_SESSION['student_performance_success'] ?? '';
unset($_SESSION['student_performance_success']);

if (empty($_SESSION['student_performance_csrf'])) {
    $_SESSION['student_performance_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['student_performance_csrf'];

try {
    $options = spaAnalyticsOptions($pdo, $access);
    [$filters, $filterErrors] = spaNormalizeFilters($_GET, $options);
} catch (Throwable $e) {
    error_log('Student performance options failed: ' . $e->getMessage());
    http_response_code(500);
    $options = [
        'academic_years' => [], 'semesters' => [], 'departments' => [], 'programs' => [],
        'sections' => [], 'subjects' => [], 'students' => [],
        'assessment_types' => ['Prelim', 'Midterm', 'Final', 'Quiz', 'Assignment', 'Examination']
    ];
    $filters = [
        'academic_year_id' => null, 'semester' => null, 'department_id' => null,
        'program_id' => null, 'section_id' => null, 'subject_id' => null,
        'student_id' => null, 'assessment_type' => null, 'report_type' => 'overview'
    ];
    $filterErrors = [];
    $errorMessage = 'Analytics filters could not be loaded. Please refresh the page or contact an administrator.';
}

$riskDepartmentChoices = $options['departments'];
if ($role === 'dean' && !empty($access['department_id'])) {
    $riskDepartmentChoices = array_values(array_filter(
        $riskDepartmentChoices,
        static fn($department) => (int)$department['id'] === (int)$access['department_id']
    ));
}
$riskDepartmentId = null;
if ($role === 'dean') {
    $riskDepartmentId = $access['department_id'];
} elseif ($filters['department_id'] !== null) {
    $riskDepartmentId = $filters['department_id'];
} elseif ($role === 'instructor' && count($riskDepartmentChoices) === 1) {
    $riskDepartmentId = (int)$riskDepartmentChoices[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_risk_threshold'])) {
    $postedToken = $_POST['csrf_token'] ?? '';
    $postedDepartment = trim((string)($_POST['risk_department_id'] ?? ''));
    $postedDepartmentId = $postedDepartment === '' ? null : filter_var($postedDepartment, FILTER_VALIDATE_INT);
    $thresholdValue = filter_var($_POST['risk_threshold'] ?? null, FILTER_VALIDATE_FLOAT);
    $moderateValue = filter_var($_POST['moderate_threshold'] ?? null, FILTER_VALIDATE_FLOAT);
    $allowedRiskDepartments = array_map(static fn($department) => (int)$department['id'], $riskDepartmentChoices);

    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } elseif ($postedDepartmentId === false) {
        $errorMessage = 'Select a department within your authorized scope.';
    } elseif ($postedDepartmentId !== null && !in_array((int)$postedDepartmentId, $allowedRiskDepartments, true)) {
        $errorMessage = 'Select a department within your authorized scope.';
    } elseif ($role === 'instructor' && $postedDepartmentId === null) {
        $errorMessage = 'Select one of your assigned departments before saving a threshold.';
    } elseif ($role === 'dean' && $postedDepartmentId !== $access['department_id']) {
        $errorMessage = 'You can only configure your assigned department.';
    } elseif ($role !== 'admin' && $postedDepartmentId !== $riskDepartmentId) {
        $errorMessage = 'The threshold scope must match your selected analytics department.';
    } elseif ($thresholdValue === false || $thresholdValue < 40 || $thresholdValue > 95) {
        $errorMessage = 'The risk threshold must be between 40% and 95%.';
    } elseif ($moderateValue === false || $moderateValue < $thresholdValue || $moderateValue > 100) {
        $errorMessage = 'The monitoring threshold must be at least the risk threshold and no more than 100%.';
    } else {
        try {
            $configQuery = $postedDepartmentId === null
                ? $pdo->query('SELECT id FROM risk_configurations WHERE department_id IS NULL ORDER BY id LIMIT 1')
                : $pdo->prepare('SELECT id FROM risk_configurations WHERE department_id = ? ORDER BY id LIMIT 1');
            if ($postedDepartmentId !== null) {
                $configQuery->execute([(int)$postedDepartmentId]);
            }
            $configId = $configQuery->fetchColumn();
            if ($configId) {
                $saveConfig = $pdo->prepare(
                    'UPDATE risk_configurations
                     SET grade_threshold = ?, passing_grade = ?, moderate_grade_threshold = ?, updated_by = ?, updated_at = NOW()
                     WHERE id = ?'
                );
                $saveConfig->execute([$thresholdValue, $thresholdValue, $moderateValue, $userId, (int)$configId]);
            } else {
                $saveConfig = $pdo->prepare(
                    'INSERT INTO risk_configurations
                     (department_id, grade_threshold, passing_grade, moderate_grade_threshold, updated_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $saveConfig->execute([$postedDepartmentId, $thresholdValue, $thresholdValue, $moderateValue, $userId]);
            }

            $_SESSION['student_performance_success'] = 'Performance risk thresholds saved.';
            $redirectFilters = array_filter($filters, static fn($value) => $value !== null && $value !== '');
            header('Location: student_performance.php?' . http_build_query($redirectFilters));
            exit;
        } catch (Throwable $e) {
            error_log('Student performance threshold save failed: ' . $e->getMessage());
            $errorMessage = 'The performance risk threshold could not be saved. Please try again.';
        }
    }
}

$loadRiskConfig = static function (?int $departmentId) use ($pdo): array {
    if ($departmentId !== null) {
        $stmt = $pdo->prepare(
            'SELECT grade_threshold, moderate_grade_threshold
             FROM risk_configurations WHERE department_id = ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([$departmentId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($config) {
            return $config;
        }
    }
    $config = $pdo->query(
        'SELECT grade_threshold, moderate_grade_threshold
         FROM risk_configurations WHERE department_id IS NULL ORDER BY id LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    return $config ?: ['grade_threshold' => 75.0, 'moderate_grade_threshold' => 80.0];
};
$riskThreshold = 75.0;
$moderateThreshold = 80.0;
try {
    $riskConfig = $loadRiskConfig($riskDepartmentId);
    $riskThreshold = (float)$riskConfig['grade_threshold'];
    $moderateThreshold = (float)$riskConfig['moderate_grade_threshold'];
} catch (Throwable $e) {
    error_log('Student performance risk configuration read failed: ' . $e->getMessage());
    $errorMessage = $errorMessage ?: 'Risk thresholds could not be loaded from the database.';
}
$formRiskThreshold = $riskThreshold;
$formModerateThreshold = $moderateThreshold;

$data = null;
try {
    $data = spaBuildAnalyticsData($pdo, $access, $_GET, $riskThreshold, $moderateThreshold);
    $filters = $data['filters'];
    $filterErrors = array_merge($filterErrors, $data['filter_errors']);
} catch (Throwable $e) {
    error_log('Student performance analytics query failed: ' . $e->getMessage());
    http_response_code(500);
    $errorMessage = 'Student performance data could not be loaded. Please refresh the page or contact an administrator.';
}

$exportRequested = ($_GET['export'] ?? '') === 'xlsx';
if ($exportRequested && $filters['report_type'] === 'individual' && $filters['student_id'] === null) {
    $errorMessage = 'Select a student filter before exporting an individual student report.';
}
if ($data !== null && $exportRequested && !($filters['report_type'] === 'individual' && $filters['student_id'] === null)) {
    $summaryRows = [
        ['Student Performance Analytics Report'],
        ['Report type', ucfirst($filters['report_type'])],
        ['Generated', date('Y-m-d H:i:s')],
        ['Academic year', $filters['academic_year_id'] ? spaLookupOptionName($options['academic_years'], $filters['academic_year_id']) : 'All'],
        ['Semester', $filters['semester'] ?? 'All'],
        ['Department', $filters['department_id'] ? spaLookupOptionName($options['departments'], $filters['department_id']) : 'All'],
        ['Program', $filters['program_id'] ? spaLookupOptionName($options['programs'], $filters['program_id']) : 'All'],
        ['Section', $filters['section_id'] ? spaLookupOptionName($options['sections'], $filters['section_id']) : 'All'],
        ['Subject', $filters['subject_id'] ? spaLookupOptionName($options['subjects'], $filters['subject_id']) : 'All'],
        ['Assessment type', $filters['assessment_type'] ?? 'All'],
        ['Overall average', $data['summary']['overall_average']],
        ['Students', $data['summary']['total_students']],
        ['Students needing attention', $data['summary']['needing_attention']],
        ['Assessments', $data['summary']['total_assessments']]
    ];
    $studentRows = [[
        'Student ID', 'Student Name', 'Program', 'Section', 'Subject',
        'Prelim %', 'Midterm %', 'Final %', 'Overall Average %', 'Status', 'Trend'
    ]];
    foreach ($data['rows'] as $row) {
        if ($filters['report_type'] === 'individual' && $filters['student_id'] !== null && $row['student_id'] !== $filters['student_id']) {
            continue;
        }
        $studentRows[] = [
            $row['student_number'], $row['student_name'], $row['program_name'], $row['section_name'], $row['subject_title'],
            $row['prelim_grade'], $row['midterm_grade'], $row['final_grade'], $row['overall_average'], $row['status'],
            $data['student_groups'][$row['student_id']]['trend'] ?? 'Insufficient Data'
        ];
    }
    $assessmentRows = [[
        'Student ID', 'Student Name', 'Subject', 'Assessment Type', 'Assessment',
        'Earned Points', 'Maximum Points', 'Percentage', 'Recorded At'
    ]];
    foreach ($data['assessment_records'] as $record) {
        if ($record['source_type'] === 'Gradebook') {
            continue;
        }
        $assessmentRows[] = [
            $record['student_number'], $record['student_name'], $record['subject_title'],
            $record['assessment_type'], $record['title'], $record['score'], $record['max_score'],
            $record['percentage'], $record['assessed_at']
        ];
    }
    $sheets = [
        ['name' => 'Report Summary', 'rows' => $summaryRows],
        ['name' => 'Student Performance', 'rows' => $studentRows],
        ['name' => 'Assessment Scores', 'rows' => $assessmentRows]
    ];
    if ($filters['report_type'] === 'individual' && $filters['student_id'] !== null) {
        $studentGroup = $data['student_groups'][$filters['student_id']] ?? null;
        if ($studentGroup) {
            $pending = spaPendingAssessmentCount($pdo, $access, $filters, $filters['student_id'], $data['assessment_records']);
            $profileReport = [
                ['Individual Student Report'],
                ['Student', $studentGroup['student_name']],
                ['Student ID', $studentGroup['student_number']],
                ['Program', $studentGroup['program_name']],
                ['Year level', $studentGroup['year_level']],
                ['Section', $studentGroup['section_name']],
                ['Academic year', $filters['academic_year_id'] ? spaLookupOptionName($options['academic_years'], $filters['academic_year_id']) : implode(', ', $studentGroup['academic_years'])],
                ['Semester', $filters['semester'] ?? implode(', ', $studentGroup['semesters'])],
                ['Overall average', $studentGroup['average']],
                ['Prelim average', $studentGroup['period_averages']['Prelim']],
                ['Midterm average', $studentGroup['period_averages']['Midterm']],
                ['Final average', $studentGroup['period_averages']['Final']],
                ['Performance status', $studentGroup['status']],
                ['Performance trend', $studentGroup['trend']],
                ['Highest assessment', $studentGroup['highest_score']],
                ['Lowest assessment', $studentGroup['lowest_score']],
                ['Completed assessments', $studentGroup['completed_assessments']],
                ['Pending assessments', $pending]
            ];
            array_unshift($sheets, ['name' => 'Individual Report', 'rows' => $profileReport]);
        }
    } elseif ($filters['report_type'] === 'section') {
        $sectionStudents = [];
        foreach ($data['rows'] as $row) {
            $sectionKey = (int)($row['section_id'] ?? 0);
            $sectionStudents[$sectionKey]['name'] = $row['section_name'];
            $sectionStudents[$sectionKey]['students'][$row['student_id']][] = $row['overall_average'];
        }
        $sectionReport = [[
            'Section', 'Students', 'Average', 'Highest Student Average', 'Lowest Student Average',
            'Needs Attention', 'Excellent', 'Very Satisfactory', 'Satisfactory',
            'Needs Improvement', 'At Risk', 'Insufficient Data'
        ]];
        foreach ($sectionStudents as $section) {
            $studentAverages = array_map('spaAverage', array_values($section['students']));
            $statuses = array_map(
                static fn($average) => spaPerformanceStatus($average, $riskThreshold, $moderateThreshold),
                $studentAverages
            );
            $validAverages = array_values(array_filter($studentAverages, static fn($average) => $average !== null));
            $sectionReport[] = [
                $section['name'],
                count($studentAverages),
                spaAverage($validAverages),
                $validAverages ? max($validAverages) : null,
                $validAverages ? min($validAverages) : null,
                count(array_filter($validAverages, static fn($average) => $average < $riskThreshold)),
                count(array_filter($statuses, static fn($status) => $status === 'Excellent')),
                count(array_filter($statuses, static fn($status) => $status === 'Very Satisfactory')),
                count(array_filter($statuses, static fn($status) => $status === 'Satisfactory')),
                count(array_filter($statuses, static fn($status) => $status === 'Needs Improvement')),
                count(array_filter($statuses, static fn($status) => $status === 'At Risk')),
                count(array_filter($statuses, static fn($status) => $status === 'Insufficient Data'))
            ];
        }
        $sheets[] = ['name' => 'Section Performance', 'rows' => $sectionReport];
    } elseif ($filters['report_type'] === 'subject') {
        $subjectStudents = [];
        foreach ($data['rows'] as $row) {
            $subjectId = (int)$row['subject_id'];
            $subjectStudents[$subjectId]['name'] = $row['subject_title'];
            $subjectStudents[$subjectId]['students'][$row['student_id']] = $row['overall_average'];
        }
        foreach ($data['assessment_records'] as $record) {
            if ($record['source_type'] !== 'Gradebook') {
                $subjectStudents[$record['subject_id']]['name'] = $record['subject_title'];
                $subjectStudents[$record['subject_id']]['assessments'][] = $record['percentage'];
            }
        }
        $subjectReport = [[
            'Subject', 'Students', 'Average', 'Highest Student Average', 'Lowest Student Average',
            'Highest Assessment %', 'Lowest Assessment %', 'Needs Attention'
        ]];
        foreach ($subjectStudents as $subject) {
            $averages = array_values($subject['students']);
            $validAverages = array_values(array_filter($averages, static fn($average) => $average !== null));
            $assessmentScores = $subject['assessments'] ?? [];
            $subjectReport[] = [
                $subject['name'],
                count($averages),
                spaAverage($validAverages),
                $validAverages ? max($validAverages) : null,
                $validAverages ? min($validAverages) : null,
                $assessmentScores ? max($assessmentScores) : null,
                $assessmentScores ? min($assessmentScores) : null,
                count(array_filter($validAverages, static fn($average) => $average < $riskThreshold))
            ];
        }
        $sheets[] = ['name' => 'Subject Performance', 'rows' => $subjectReport];
    }
    try {
        spaSendXlsx('student-performance-report.xlsx', $sheets);
        exit;
    } catch (Throwable $e) {
        error_log('Student performance Excel export failed: ' . $e->getMessage());
        http_response_code(500);
        $errorMessage = 'The Excel report could not be generated. Please try again.';
    }
}

function spaLookupOptionName(array $items, int $selectedId): string
{
    foreach ($items as $item) {
        if ((int)$item['id'] === $selectedId) {
            return (string)$item['name'];
        }
    }
    return 'All';
}

function spaEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function spaFormatPercent($value): string
{
    return $value === null || !is_numeric($value) ? '—' : number_format((float)$value, 1) . '%';
}

$canConfigureRisk = $role === 'admin' || $riskDepartmentId !== null;
$chartPayload = $data === null ? [] : [
    'assessments' => $data['assessment_averages'],
    'periods' => $data['period_comparisons'],
    'sections' => $data['section_comparisons'],
    'subjects' => $data['subject_comparisons'],
    'distribution' => $data['distribution']
];
$detailBaseFilters = $filters;
unset($detailBaseFilters['student_id']);
$detailBaseQuery = array_filter($detailBaseFilters, static fn($value) => $value !== null && $value !== '');
$currentQuery = array_filter($filters, static fn($value) => $value !== null && $value !== '');
$pageActionLabel = 'Grade Management';
$pageActionUrl = '../grades/manage.php';
$pageActionIcon = 'fa-clipboard-check';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4 spa-page">
    <section class="spa-hero mb-4">
        <div>
            <span class="spa-eyebrow">INSTRUCTOR INSIGHTS</span>
            <h1>Student Performance Analytics</h1>
            <p>Monitor individual student performance, academic progress, assessment results, and learning trends.</p>
        </div>
        <div class="spa-hero-actions">
            <span class="spa-updated"><i class="fa-regular fa-clock"></i> Data refreshed <?php echo spaEscape(date('M j, Y g:i A')); ?></span>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-light" type="button" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>Print Report</button>
                <button class="btn btn-outline-light" type="button" onclick="window.print()"><i class="fa-solid fa-file-pdf me-2"></i>Export PDF</button>
                <a class="btn btn-outline-light" href="?<?php echo spaEscape(http_build_query(array_merge($currentQuery, ['export' => 'xlsx']))); ?>">
                    <i class="fa-solid fa-file-excel me-2"></i>Export Excel
                </a>
            </div>
        </div>
    </section>
    <div class="spa-print-meta mb-3">
        <strong>Report scope:</strong>
        Report type: <?php echo spaEscape(ucfirst($filters['report_type'])); ?> ·
        Academic year: <?php echo spaEscape($filters['academic_year_id'] ? spaLookupOptionName($options['academic_years'], $filters['academic_year_id']) : 'All'); ?> ·
        Semester: <?php echo spaEscape($filters['semester'] ?? 'All'); ?> ·
        Department: <?php echo spaEscape($filters['department_id'] ? spaLookupOptionName($options['departments'], $filters['department_id']) : 'All'); ?> ·
        Program: <?php echo spaEscape($filters['program_id'] ? spaLookupOptionName($options['programs'], $filters['program_id']) : 'All'); ?> ·
        Section: <?php echo spaEscape($filters['section_id'] ? spaLookupOptionName($options['sections'], $filters['section_id']) : 'All'); ?> ·
        Subject: <?php echo spaEscape($filters['subject_id'] ? spaLookupOptionName($options['subjects'], $filters['subject_id']) : 'All'); ?> ·
        Student: <?php echo spaEscape($filters['student_id'] ? spaLookupOptionName($options['students'], $filters['student_id']) : 'All'); ?> ·
        Assessment: <?php echo spaEscape($filters['assessment_type'] ?? 'All'); ?>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?php echo spaEscape($successMessage); ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger" role="alert"><?php echo spaEscape($errorMessage); ?></div>
    <?php endif; ?>
    <?php foreach (array_unique($filterErrors) as $filterError): ?>
        <div class="alert alert-warning py-2" role="status"><?php echo spaEscape($filterError); ?></div>
    <?php endforeach; ?>

    <form method="get" class="spa-card spa-filters mb-4">
        <div class="spa-section-heading">
            <div>
                <h2>Analytics Filters</h2>
                <p>Combine filters to focus reports on a specific class, subject, or assessment.</p>
            </div>
            <span class="spa-filter-icon"><i class="fa-solid fa-sliders"></i></span>
        </div>
        <div class="row g-3">
            <?php
            $selectFields = [
                ['academic_year_id', 'Academic Year', $options['academic_years']],
                ['semester', 'Semester', null],
                ['department_id', 'Department', $options['departments']],
                ['program_id', 'Program', $options['programs']],
                ['subject_id', 'Subject', $options['subjects']],
                ['student_id', 'Student', $options['students']],
                ['assessment_type', 'Assessment Type', null]
            ];
            foreach ($selectFields as [$field, $label, $items]):
                $value = $filters[$field] ?? null;
            ?>
                <div class="col-12 col-sm-6 col-xl-3">
                    <label class="form-label" for="filter-<?php echo spaEscape($field); ?>"><?php echo spaEscape($label); ?></label>
                    <select class="form-select" id="filter-<?php echo spaEscape($field); ?>" name="<?php echo spaEscape($field); ?>">
                        <option value="">All <?php echo spaEscape($label); ?>s</option>
                        <?php if ($field === 'semester'): ?>
                            <?php foreach ($options['semesters'] as $semester): ?>
                                <option value="<?php echo spaEscape($semester); ?>" <?php echo $value === $semester ? 'selected' : ''; ?>><?php echo spaEscape($semester); ?></option>
                            <?php endforeach; ?>
                        <?php elseif ($field === 'assessment_type'): ?>
                            <?php foreach ($options['assessment_types'] as $assessmentType): ?>
                                <option value="<?php echo spaEscape($assessmentType); ?>" <?php echo $value === $assessmentType ? 'selected' : ''; ?>><?php echo spaEscape($assessmentType); ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $optionId = (int)$item['id'];
                                $optionName = $field === 'student_id'
                                    ? $item['student_number'] . ' — ' . $item['name']
                                    : $item['name'];
                                ?>
                                <option value="<?php echo $optionId; ?>" <?php echo (int)$value === $optionId ? 'selected' : ''; ?>>
                                    <?php echo spaEscape($optionName); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            <div class="col-12 col-sm-6 col-xl-3">
                <label class="form-label" for="report_type">Report Type</label>
                <select class="form-select" id="report_type" name="report_type">
                    <option value="overview" <?php echo $filters['report_type'] === 'overview' ? 'selected' : ''; ?>>Overview Report</option>
                    <option value="individual" <?php echo $filters['report_type'] === 'individual' ? 'selected' : ''; ?>>Individual Student</option>
                    <option value="section" <?php echo $filters['report_type'] === 'section' ? 'selected' : ''; ?>>Section Performance</option>
                    <option value="subject" <?php echo $filters['report_type'] === 'subject' ? 'selected' : ''; ?>>Subject Performance</option>
                </select>
            </div>
        </div>
        <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="student_performance.php"><i class="fa-solid fa-rotate-left me-1"></i>Reset Filters</a>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply Filters</button>
        </div>
    </form>

    <?php if ($data !== null): ?>
        <section class="spa-summary-grid mb-4" aria-label="Performance summary">
            <article class="spa-summary-card">
                <span class="spa-summary-icon blue"><i class="fa-solid fa-chart-line"></i></span>
                <div><span class="spa-summary-label">Overall Average</span><strong><?php echo spaFormatPercent($data['summary']['overall_average']); ?></strong><small>Based on valid filtered student averages</small></div>
            </article>
            <article class="spa-summary-card">
                <span class="spa-summary-icon indigo"><i class="fa-solid fa-user-graduate"></i></span>
                <div><span class="spa-summary-label">Total Students</span><strong><?php echo number_format($data['summary']['total_students']); ?></strong><small>Students with current enrollments</small></div>
            </article>
            <article class="spa-summary-card">
                <span class="spa-summary-icon amber"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <div><span class="spa-summary-label">Students Needing Attention</span><strong><?php echo number_format($data['summary']['needing_attention']); ?></strong><small>Below <?php echo spaFormatPercent($riskThreshold); ?> average</small></div>
            </article>
            <article class="spa-summary-card">
                <span class="spa-summary-icon cyan"><i class="fa-solid fa-clipboard-list"></i></span>
                <div><span class="spa-summary-label">Total Assessments</span><strong><?php echo number_format($data['summary']['total_assessments']); ?></strong><small>Published or active assessments in scope</small></div>
            </article>
            <article class="spa-summary-card">
                <span class="spa-summary-icon green"><i class="fa-solid fa-arrow-trend-up"></i></span>
                <div><span class="spa-summary-label">Highest Performing Student</span><strong class="spa-student-highlight"><?php echo $data['summary']['highest_student'] ? spaEscape($data['summary']['highest_student']['student_name']) : '—'; ?></strong><small><?php echo $data['summary']['highest_student'] ? spaFormatPercent($data['summary']['highest_student']['average']) : 'No scored records'; ?></small></div>
            </article>
            <article class="spa-summary-card">
                <span class="spa-summary-icon rose"><i class="fa-solid fa-arrow-trend-down"></i></span>
                <div><span class="spa-summary-label">Lowest Performing Student</span><strong class="spa-student-highlight"><?php echo $data['summary']['lowest_student'] ? spaEscape($data['summary']['lowest_student']['student_name']) : '—'; ?></strong><small><?php echo $data['summary']['lowest_student'] ? spaFormatPercent($data['summary']['lowest_student']['average']) : 'No scored records'; ?></small></div>
            </article>
        </section>

        <section class="row g-4 mb-4">
            <div class="col-12 col-xl-8">
                <div class="spa-card h-100">
                    <div class="spa-section-heading">
                        <div><h2>Performance by Assessment</h2><p>Average percentage scores use each assessment’s actual maximum points.</p></div>
                        <span class="spa-chart-icon"><i class="fa-solid fa-chart-column"></i></span>
                    </div>
                    <?php if ($data['assessment_averages']): ?>
                        <div class="spa-chart-wrap"><canvas id="assessmentChart" aria-label="Average score by assessment"></canvas></div>
                    <?php else: ?>
                        <div class="spa-empty"><i class="fa-solid fa-chart-column"></i><span>No completed assessment scores match these filters.</span></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-12 col-xl-4">
                <div class="spa-card h-100">
                    <div class="spa-section-heading">
                        <div><h2>Performance Status</h2><p>Student averages by grading status.</p></div>
                    </div>
                    <div class="spa-chart-wrap spa-chart-wrap-small"><canvas id="distributionChart" aria-label="Student performance status distribution"></canvas></div>
                    <div class="spa-status-legend">
                        <?php foreach ($data['distribution'] as $status => $count): ?>
                            <span><i class="spa-legend-dot status-<?php echo spaEscape(strtolower(str_replace(' ', '-', $status))); ?>"></i><?php echo spaEscape($status); ?> <strong><?php echo (int)$count; ?></strong></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="row g-4 mb-4">
            <div class="col-12 col-xl-6">
                <div class="spa-card h-100">
                    <div class="spa-section-heading"><div><h2>Grading Period Comparison</h2><p>Average performance from recorded prelim, midterm, and final grades.</p></div></div>
                    <div class="spa-chart-wrap spa-chart-wrap-medium"><canvas id="periodChart" aria-label="Prelim, midterm, and final comparison"></canvas></div>
                </div>
            </div>
            <div class="col-12 col-xl-6">
                <div class="spa-card h-100">
                    <div class="spa-section-heading"><div><h2>Academic Performance Comparison</h2><p>Compare filtered section averages.</p></div></div>
                    <?php if ($data['section_comparisons']): ?>
                        <div class="spa-chart-wrap spa-chart-wrap-medium"><canvas id="sectionChart" aria-label="Section performance comparison"></canvas></div>
                    <?php else: ?>
                        <div class="spa-empty"><i class="fa-solid fa-people-group"></i><span>No section averages are available for this selection.</span></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <section class="spa-card mb-4">
            <div class="spa-section-heading"><div><h2>Subject Performance Comparison</h2><p>Average student performance by assigned subject using the same selected filters.</p></div></div>
            <?php if ($data['subject_comparisons']): ?>
                <div class="spa-chart-wrap spa-chart-wrap-medium"><canvas id="subjectComparisonChart" aria-label="Subject performance comparison"></canvas></div>
            <?php else: ?>
                <div class="spa-empty spa-empty-compact"><i class="fa-solid fa-book"></i><span>No subject averages are available for this selection.</span></div>
            <?php endif; ?>
        </section>

        <section class="row g-4 mb-4">
            <div class="col-12 col-xl-7">
                <div class="spa-card h-100">
                    <div class="spa-section-heading">
                        <div><h2>Early Warning &amp; Student Risk Monitoring</h2><p>Students below the configured passing threshold appear here. Missing grades are not counted as failures.</p></div>
                        <span class="spa-risk-threshold">At risk below <?php echo spaFormatPercent($riskThreshold); ?> · Monitor below <?php echo spaFormatPercent(max($riskThreshold, $moderateThreshold)); ?></span>
                    </div>
                    <?php if ($data['risk_rows']): ?>
                        <div class="table-responsive">
                            <table class="table spa-table align-middle mb-0">
                                <thead><tr><th>Student</th><th>ID</th><th>Section</th><th>Subject</th><th>Average</th><th>Trend</th><th>Risk</th></tr></thead>
                                <tbody>
                                <?php foreach ($data['risk_rows'] as $riskRow):
                                    $riskLinkParams = $detailBaseQuery;
                                    $riskLinkParams['student_id'] = $riskRow['student_id'];
                                ?>
                                    <tr>
                                        <td><a class="spa-table-link" href="student_performance_student.php?<?php echo spaEscape(http_build_query($riskLinkParams)); ?>"><?php echo spaEscape($riskRow['student_name']); ?></a></td>
                                        <td><?php echo spaEscape($riskRow['student_number']); ?></td>
                                        <td><?php echo spaEscape($riskRow['section_name']); ?></td>
                                        <td><?php echo spaEscape($riskRow['subject_title']); ?></td>
                                        <td><strong class="<?php echo $riskRow['overall_average'] < $riskThreshold ? 'text-danger' : 'text-warning'; ?>"><?php echo spaFormatPercent($riskRow['overall_average']); ?></strong></td>
                                        <td><?php echo spaEscape($riskRow['trend']); ?></td>
                                        <td><span class="spa-status-badge <?php echo $riskRow['overall_average'] < $riskThreshold && $riskRow['status'] === 'At Risk' ? 'danger' : 'warning'; ?>"><?php echo spaEscape($riskRow['risk_status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="spa-empty spa-empty-compact"><i class="fa-solid fa-circle-check"></i><span>No students are currently below the monitoring threshold.</span></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="spa-card h-100">
                    <div class="spa-section-heading"><div><h2>Risk Threshold Settings</h2><p>Thresholds are saved in the existing institutional risk configuration.</p></div><i class="fa-solid fa-shield-halved spa-heading-icon"></i></div>
                    <?php if ($canConfigureRisk): ?>
                        <form method="post" class="spa-risk-form">
                            <input type="hidden" name="csrf_token" value="<?php echo spaEscape($csrfToken); ?>">
                            <input type="hidden" name="save_risk_threshold" value="1">
                            <input type="hidden" name="risk_department_id" value="<?php echo $riskDepartmentId === null ? '' : (int)$riskDepartmentId; ?>">
                            <div class="spa-scope-note mb-3">
                                <i class="fa-solid fa-building"></i>
                                <?php echo $riskDepartmentId !== null ? spaEscape(spaLookupOptionName($riskDepartmentChoices, $riskDepartmentId)) : 'Global institutional threshold'; ?>
                            </div>
                            <label class="form-label" for="risk_threshold">Needs attention below</label>
                            <div class="input-group mb-3"><input class="form-control" type="number" id="risk_threshold" name="risk_threshold" min="40" max="95" step="0.5" value="<?php echo spaEscape($formRiskThreshold); ?>" required><span class="input-group-text">%</span></div>
                            <label class="form-label" for="moderate_threshold">Satisfactory monitoring threshold</label>
                            <div class="input-group mb-3"><input class="form-control" type="number" id="moderate_threshold" name="moderate_threshold" min="<?php echo spaEscape($formRiskThreshold); ?>" max="100" step="0.5" value="<?php echo spaEscape($formModerateThreshold); ?>" required><span class="input-group-text">%</span></div>
                            <button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i>Save Thresholds</button>
                        </form>
                    <?php else: ?>
                        <div class="spa-empty spa-empty-compact"><i class="fa-solid fa-circle-info"></i><span>Select one of your assigned departments in Analytics Filters to configure its risk threshold.</span></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="spa-card mb-4">
            <div class="spa-section-heading">
                <div><h2>Student Performance Overview</h2><p>Subject-level grades and calculated averages for the current filter selection.</p></div>
                <span class="spa-result-count" id="studentResultCount"><?php echo count($data['rows']); ?> records</span>
            </div>
            <div class="spa-table-controls">
                <div class="spa-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" class="form-control" id="studentSearch" placeholder="Search student name or ID" aria-label="Search student name or ID">
                </div>
                <div class="spa-status-filter">
                    <label for="statusFilter" class="form-label mb-0">Status</label>
                    <select class="form-select" id="statusFilter">
                        <option value="">All statuses</option>
                        <?php foreach (['Excellent', 'Very Satisfactory', 'Satisfactory', 'Needs Improvement', 'At Risk', 'Insufficient Data'] as $status): ?>
                            <option value="<?php echo spaEscape($status); ?>"><?php echo spaEscape($status); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table spa-table align-middle" id="studentPerformanceTable">
                    <thead>
                        <tr>
                            <?php foreach ([
                                'student_number' => 'Student ID',
                                'student_name' => 'Student Name',
                                'program_name' => 'Program',
                                'section_name' => 'Section',
                                'subject_title' => 'Subject',
                                'prelim_grade' => 'Prelim',
                                'midterm_grade' => 'Midterm',
                                'final_grade' => 'Final',
                                'overall_average' => 'Overall Average'
                            ] as $sortKey => $heading): ?>
                                <th><button type="button" class="spa-sort-button" data-sort="<?php echo spaEscape($sortKey); ?>"><?php echo spaEscape($heading); ?><i class="fa-solid fa-sort ms-1"></i></button></th>
                            <?php endforeach; ?>
                            <th>Performance Status</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($data['rows']): foreach ($data['rows'] as $row):
                        $studentLinkParams = $detailBaseQuery;
                        $studentLinkParams['student_id'] = $row['student_id'];
                    ?>
                        <tr data-spa-row data-name="<?php echo spaEscape(strtolower($row['student_name'])); ?>" data-id="<?php echo spaEscape(strtolower($row['student_number'])); ?>" data-status="<?php echo spaEscape($row['status']); ?>" data-student_number="<?php echo spaEscape($row['student_number']); ?>" data-student_name="<?php echo spaEscape($row['student_name']); ?>" data-program_name="<?php echo spaEscape($row['program_name']); ?>" data-section_name="<?php echo spaEscape($row['section_name']); ?>" data-subject_title="<?php echo spaEscape($row['subject_title']); ?>" data-prelim_grade="<?php echo $row['prelim_grade'] === null ? '' : spaEscape($row['prelim_grade']); ?>" data-midterm_grade="<?php echo $row['midterm_grade'] === null ? '' : spaEscape($row['midterm_grade']); ?>" data-final_grade="<?php echo $row['final_grade'] === null ? '' : spaEscape($row['final_grade']); ?>" data-overall_average="<?php echo $row['overall_average'] === null ? '' : spaEscape($row['overall_average']); ?>">
                            <td><?php echo spaEscape($row['student_number']); ?></td>
                            <td><a class="spa-table-link" href="student_performance_student.php?<?php echo spaEscape(http_build_query($studentLinkParams)); ?>"><?php echo spaEscape($row['student_name']); ?></a></td>
                            <td><?php echo spaEscape($row['program_name']); ?></td>
                            <td><?php echo spaEscape($row['section_name']); ?></td>
                            <td><?php echo spaEscape($row['subject_title']); ?></td>
                            <td><?php echo spaFormatPercent($row['prelim_grade']); ?></td>
                            <td><?php echo spaFormatPercent($row['midterm_grade']); ?></td>
                            <td><?php echo spaFormatPercent($row['final_grade']); ?></td>
                            <td><strong><?php echo spaFormatPercent($row['overall_average']); ?></strong></td>
                            <td><span class="spa-status-badge <?php echo $row['overall_average'] === null ? 'neutral' : ($row['overall_average'] < $riskThreshold ? 'danger' : 'success'); ?>"><?php echo spaEscape($row['status']); ?></span></td>
                            <td><a class="btn btn-sm btn-outline-primary" href="student_performance_student.php?<?php echo spaEscape(http_build_query($studentLinkParams)); ?>">View Details</a></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="11" class="text-center py-5 text-muted">No enrolled students match the current filters.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="spa-table-footer">
                <span id="paginationSummary">Showing 0 records</span>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="previousPage">Previous</button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="nextPage">Next</button>
                </div>
            </div>
            <p class="spa-grade-note mb-0">Existing Grade Management final averages are used when available. Otherwise, valid recorded assessment percentages are averaged; ungraded and missing assessments are excluded.</p>
        </section>
    <?php endif; ?>
</main>

<?php if ($data !== null): ?>
    <script type="application/json" id="spaChartData"><?php echo json_encode($chartPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?></script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/student-performance.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
