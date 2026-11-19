<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/analytics_functions.php';

// Auth Guard: Logged-in instructor only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header("Location: ../auth/login.php");
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Instructor';

// Filters from GET
$filter_subject = isset($_GET['subject_id']) && $_GET['subject_id'] !== '' ? (int)$_GET['subject_id'] : null;
$filter_section = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? (int)$_GET['section_id'] : null;
$filter_quiz    = isset($_GET['quiz_id']) && $_GET['quiz_id'] !== '' ? (int)$_GET['quiz_id'] : null;
$threshold      = isset($_GET['threshold']) && is_numeric($_GET['threshold']) ? (float)$_GET['threshold'] : 75.0;

// Clamp threshold to reasonable bounds
if ($threshold < 40.0) $threshold = 40.0;
if ($threshold > 95.0) $threshold = 95.0;

// Fetch teacher's active subjects
$stmt = $pdo->prepare("
    SELECT s.id, s.title, s.section_id, sec.name as section_name, p.name as program_name
    FROM subjects s
    LEFT JOIN sections sec ON s.section_id = sec.id
    LEFT JOIN programs p ON p.id = s.program_id
    WHERE s.instructor_id = ? AND s.status = 'active'
    ORDER BY s.title ASC
");
$stmt->execute([$user_id]);
$teacher_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
$teacher_subject_ids = array_column($teacher_subjects, 'id');
if (empty($teacher_subject_ids)) {
    $teacher_subject_ids = [0];
}

// Fetch teacher's sections (from subjects or enrollments)
$stmt = $pdo->prepare("
    SELECT DISTINCT sec.id, sec.name
    FROM sections sec
    INNER JOIN subjects s ON s.section_id = sec.id
    WHERE s.instructor_id = ?
    UNION
    SELECT DISTINCT sec.id, sec.name
    FROM sections sec
    INNER JOIN student_profiles sp ON sp.section_id = sec.id
    INNER JOIN enrollments e ON e.student_id = sp.student_id
    INNER JOIN subjects s ON e.subject_id = s.id
    WHERE s.instructor_id = ?
    ORDER BY name ASC
");
$stmt->execute([$user_id, $user_id]);
$teacher_sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch teacher's quizzes (online)
$stmt = $pdo->prepare("
    SELECT q.id, q.title, s.title as subject_title
    FROM quizzes q
    INNER JOIN subjects s ON q.subject_id = s.id
    WHERE s.instructor_id = ?
    ORDER BY q.title ASC
");
$stmt->execute([$user_id]);
$teacher_quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch teacher's manual exam records
$stmt = $pdo->prepare("
    SELECT er.id, er.title, er.total_items, s.title as subject_title
    FROM exam_records er
    INNER JOIN subjects s ON er.subject_id = s.id
    WHERE er.instructor_id = ?
    ORDER BY er.exam_date DESC, er.title ASC
");
$stmt->execute([$user_id]);
$teacher_manual_exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// Core Normalized Assessment Engine (Single Source of Truth)
// -------------------------------------------------------------
$filter_options = [
    'subject_id' => $filter_subject,
    'section_id' => $filter_section,
    'quiz_id'    => $filter_quiz,
];

// Normalized assessment submissions
$normalizedAssessments = getTeacherNormalizedAssessments($pdo, $user_id, $filter_options);

// All exams in teacher's scope (including unsubmitted)
$allExamsList = getTeacherExamsList($pdo, $user_id, $filter_options, $normalizedAssessments);

// -------------------------------------------------------------
// 1. Overall Summary Stats
// -------------------------------------------------------------

// Total Students: Distinct enrolled students in filtered scope
$studentsSql = "
    SELECT COUNT(DISTINCT e.student_id)
    FROM enrollments e
    INNER JOIN subjects s ON e.subject_id = s.id
    WHERE s.instructor_id = ?
";
$studentsParams = [$user_id];
if ($filter_subject) {
    $studentsSql .= " AND s.id = ?";
    $studentsParams[] = $filter_subject;
}
if ($filter_section) {
    $studentsSql .= " AND s.section_id = ?";
    $studentsParams[] = $filter_section;
}
if ($filter_quiz) {
    // If specific quiz filtered, limit to students enrolled in that quiz's subject
    $studentsSql .= " AND s.id = (SELECT subject_id FROM quizzes WHERE id = ?)";
    $studentsParams[] = $filter_quiz;
}
$stmt = $pdo->prepare($studentsSql);
$stmt->execute($studentsParams);
$total_students = (int)$stmt->fetchColumn();

// Total Exams in filtered scope
$total_exams = count($allExamsList);

// Overall MPS
$overall_mps_val = calculateOverallMPS($normalizedAssessments);
$overall_mps_display = $overall_mps_val !== null ? number_format($overall_mps_val, 1) . '%' : 'N/A';

// -------------------------------------------------------------
// 2. Section Performance Data
// -------------------------------------------------------------
// Find all unique sections enrolled for the teacher's filtered subjects
$secSql = "
    SELECT 
        s.section_id as sec_id,
        COALESCE(sec.name, 'Unassigned') as section_name,
        COUNT(DISTINCT e.student_id) as student_count
    FROM subjects s
    LEFT JOIN sections sec ON s.section_id = sec.id
    LEFT JOIN enrollments e ON e.subject_id = s.id
    WHERE s.instructor_id = ?
";
$secParams = [$user_id];
if ($filter_subject) {
    $secSql .= " AND s.id = ?";
    $secParams[] = $filter_subject;
}
if ($filter_section) {
    $secSql .= " AND s.section_id = ?";
    $secParams[] = $filter_section;
}
$secSql .= " GROUP BY s.section_id, COALESCE(sec.name, 'Unassigned') ORDER BY section_name ASC";

$stmt = $pdo->prepare($secSql);
$stmt->execute($secParams);
$rawSections = $stmt->fetchAll(PDO::FETCH_ASSOC);

$section_perf_data = [];
foreach ($rawSections as $sec) {
    $secStats = calculateSectionMPS($sec['sec_id'], $normalizedAssessments);
    $section_perf_data[] = [
        'sec_id'        => $sec['sec_id'],
        'section_name'  => $sec['section_name'],
        'student_count' => (int)$sec['student_count'],
        'mps'           => $secStats['mps'],
    ];
}

// -------------------------------------------------------------
// 3. Subject Performance Data
// -------------------------------------------------------------
$subSql = "
    SELECT 
        s.id as subject_id,
        s.title as subject_title,
        COALESCE(sec.name, 'Unassigned') as section_name,
        COUNT(DISTINCT e.student_id) as student_count
    FROM subjects s
    LEFT JOIN sections sec ON s.section_id = sec.id
    LEFT JOIN enrollments e ON e.subject_id = s.id
    WHERE s.instructor_id = ?
";
$subParams = [$user_id];
if ($filter_subject) {
    $subSql .= " AND s.id = ?";
    $subParams[] = $filter_subject;
}
if ($filter_section) {
    $subSql .= " AND s.section_id = ?";
    $subParams[] = $filter_section;
}
$subSql .= " GROUP BY s.id, s.title, COALESCE(sec.name, 'Unassigned') ORDER BY s.title ASC";

$stmt = $pdo->prepare($subSql);
$stmt->execute($subParams);
$rawSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$subject_perf_data = [];
foreach ($rawSubjects as $sub) {
    $subStats = calculateSubjectMPS($sub['subject_id'], $normalizedAssessments);
    $subject_perf_data[] = [
        'subject_id'    => $sub['subject_id'],
        'subject_title' => $sub['subject_title'],
        'section_name'  => $sub['section_name'],
        'student_count' => (int)$sub['student_count'],
        'mps'           => $subStats['mps'],
        'highest_score' => $subStats['highest_score'],
        'lowest_score'  => $subStats['lowest_score'],
    ];
}

// Highest / Lowest Subject (Only considering subjects with valid recorded scores)
$subjectsWithScores = array_filter($subject_perf_data, function($s) {
    return $s['mps'] !== null;
});

if (!empty($subjectsWithScores)) {
    usort($subjectsWithScores, function($a, $b) {
        return $b['mps'] <=> $a['mps'];
    });
    $highest_subject = $subjectsWithScores[0]['subject_title'] . " (" . number_format($subjectsWithScores[0]['mps'], 1) . "%)";
    $lowest_subject  = end($subjectsWithScores)['subject_title'] . " (" . number_format(end($subjectsWithScores)['mps'], 1) . "%)";
} else {
    $highest_subject = 'N/A';
    $lowest_subject  = 'N/A';
}

// -------------------------------------------------------------
// 4. Students Needing Attention (Unique Students)
// -------------------------------------------------------------
// Group normalized assessments by student
$studentGroups = [];
foreach ($normalizedAssessments as $item) {
    $stId = $item['student_id'];
    if (!isset($studentGroups[$stId])) {
        $studentGroups[$stId] = [
            'student_id'    => $stId,
            'student_name'  => $item['student_name'],
            'student_email' => $item['student_email'],
            'section_name'  => $item['section_name'],
            'assessments'   => [],
            'subject_scores'=> []
        ];
    }
    $studentGroups[$stId]['assessments'][] = $item;
    $studentGroups[$stId]['subject_scores'][$item['subject_title']][] = (float)$item['percentage'];
}

$studentsNeedingAttention = [];
foreach ($studentGroups as $stId => $group) {
    $scores = array_column($group['assessments'], 'percentage');
    $studentOverallMPS = round(array_sum($scores) / count($scores), 1);

    // Identify all subjects at risk (< threshold)
    $atRiskSubjects = [];
    foreach ($group['subject_scores'] as $subTitle => $subScores) {
        $subAvg = round(array_sum($subScores) / count($subScores), 1);
        if ($subAvg < $threshold) {
            $atRiskSubjects[] = $subTitle;
        }
    }

    // A student needs attention if their overall MPS is below threshold OR they struggle in any subject
    if ($studentOverallMPS < $threshold || !empty($atRiskSubjects)) {
        $riskLevel = getRiskLevel($studentOverallMPS, $threshold);
        $studentsNeedingAttention[] = [
            'student_id'       => $stId,
            'student_name'     => $group['student_name'],
            'student_email'    => $group['student_email'],
            'section_name'     => $group['section_name'],
            'mps'              => $studentOverallMPS,
            'risk_level'       => $riskLevel,
            'at_risk_subjects' => !empty($atRiskSubjects) ? $atRiskSubjects : array_keys($group['subject_scores']),
            'exams_taken'      => count($group['assessments'])
        ];
    }
}

// Sort by MPS ascending (lowest score first)
usort($studentsNeedingAttention, function($a, $b) {
    return $a['mps'] <=> $b['mps'];
});
$at_risk_count = count($studentsNeedingAttention);

// Sort exams list: exams with scores first (by MPS descending), followed by unrecorded exams
usort($allExamsList, function($a, $b) {
    if ($a['attempts_count'] > 0 && $b['attempts_count'] === 0) return -1;
    if ($a['attempts_count'] === 0 && $b['attempts_count'] > 0) return 1;
    return ($b['mps'] ?? 0) <=> ($a['mps'] ?? 0);
});

// Prepare chart datasets: only include exams that have recorded scores
$chartExams = array_filter($allExamsList, function($e) {
    return $e['attempts_count'] > 0 && $e['mps'] !== null;
});
$chartLabels = array_values(array_column($chartExams, 'title'));
$chartMps = array_values(array_map(function($e) {
    return (float)round($e['mps'], 1);
}, $chartExams));

$baseUrl = '..';
$pageTitle = 'Teacher Analytics — LearnAlytics';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    :root {
        --primary-blue: #1e3a8a;
        --secondary-blue: #2563eb;
        --light-bg: #f8fafc;
    }
    body { background: #f1f5f9; }
    
    .analytics-banner {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        border-radius: 16px;
        padding: 1.8rem 2rem;
        color: white;
        box-shadow: 0 10px 25px rgba(37,99,235,0.15);
        margin-bottom: 2rem;
    }

    .stat-card {
        border-radius: 14px;
        border: none;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        height: 100%;
        background: white;
    }
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    .stat-icon {
        width: 50px; height: 50px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.3rem; flex-shrink: 0;
    }
    .stat-val { font-size: 1.75rem; font-weight: 800; color: #1e3a8a; line-height: 1.2; }
    .stat-lbl { font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; }

    .content-card {
        background: white;
        border-radius: 16px;
        border: none;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        margin-bottom: 2rem;
        overflow: hidden;
    }
    .card-hdr {
        padding: 1.2rem 1.5rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex; align-items: center; justify-content: space-between;
    }
    .card-hdr h5 { font-weight: 700; color: #1e3a8a; margin: 0; font-size: 1rem; }

    .table-custom { font-size: 0.88rem; margin: 0; width: 100%; }
    .table-custom thead { background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
    .table-custom th { padding: 0.85rem 1rem; font-weight: 700; color: #475569; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.5px; }
    .table-custom td { padding: 0.85rem 1rem; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
    .table-custom tbody tr:hover { background: #f8fafc; }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="min-height: 100vh;">

    <!-- Banner -->
    <div class="analytics-banner">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <span class="badge bg-white text-primary fw-bold px-3 py-1 mb-2 rounded-pill text-uppercase" style="font-size:0.75rem;">Teacher Analytics</span>
                <h2 class="fw-bold mb-1"><i class="fas fa-chart-line me-2"></i>Performance Dashboard</h2>
                <p class="mb-0 text-white-50 small">Standardized MPS calculation per exam, section/subject performance, and unique student risk monitoring.</p>
            </div>
            <div>
                <button onclick="window.print()" class="btn btn-light btn-sm fw-bold px-3 py-2 rounded-pill text-primary shadow-sm">
                    <i class="fas fa-print me-1"></i> Print Report
                </button>
            </div>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="card border-0 shadow-sm rounded-4 mb-4" style="background: white;">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center" id="analyticsFilterForm">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-book me-1 text-primary"></i>Subject Filter</label>
                    <select name="subject_id" class="form-select form-select-sm" onchange="document.getElementById('analyticsFilterForm').submit()">
                        <option value="">All Assigned Subjects</option>
                        <?php foreach ($teacher_subjects as $s): ?>
                            <option value="<?php echo $s['id']; ?>" <?php echo $filter_subject == $s['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-users me-1 text-primary"></i>Section Filter</label>
                    <select name="section_id" class="form-select form-select-sm" onchange="document.getElementById('analyticsFilterForm').submit()">
                        <option value="">All Assigned Sections</option>
                        <?php foreach ($teacher_sections as $sec): ?>
                            <option value="<?php echo $sec['id']; ?>" <?php echo $filter_section == $sec['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sec['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-poll-h me-1 text-primary"></i>Assessment Filter</label>
                    <select name="quiz_id" class="form-select form-select-sm" onchange="document.getElementById('analyticsFilterForm').submit()">
                        <option value="">All Assessments</option>
                        <?php if (!empty($teacher_quizzes)): ?>
                            <optgroup label="Online Quizzes">
                                <?php foreach ($teacher_quizzes as $q): ?>
                                    <option value="<?php echo $q['id']; ?>" <?php echo $filter_quiz == $q['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($q['title']); ?> (<?php echo htmlspecialchars($q['subject_title']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                    <div class="form-text mt-1"><i class="fas fa-external-link-alt me-1"></i><a href="../exams/index.php" class="text-primary text-decoration-none small">Manage Manual Exam Records</a></div>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1"><i class="fas fa-sliders-h me-1 text-primary"></i>Risk Threshold (MPS %)</label>
                    <div class="input-group input-group-sm">
                        <input type="number" name="threshold" class="form-control" value="<?php echo htmlspecialchars($threshold); ?>" min="40" max="95" step="1" required>
                        <button type="submit" class="btn btn-primary fw-bold px-3">Apply</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- 1. Summary Cards Row -->
    <div class="row g-3 mb-4">
        <!-- Overall MPS -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stat-card p-3 d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:#e0e7ff; color:#3730a3;"><i class="fas fa-chart-pie"></i></div>
                <div>
                    <div class="stat-val"><?php echo $overall_mps_display; ?></div>
                    <div class="stat-lbl">Overall MPS</div>
                </div>
            </div>
        </div>

        <!-- Total Students -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stat-card p-3 d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:#dbeafe; color:#1e40af;"><i class="fas fa-user-graduate"></i></div>
                <div>
                    <div class="stat-val"><?php echo number_format($total_students); ?></div>
                    <div class="stat-lbl">Total Students</div>
                </div>
            </div>
        </div>

        <!-- Students Needing Attention (Unique) -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stat-card p-3 d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:#fee2e2; color:#991b1b;"><i class="fas fa-exclamation-triangle"></i></div>
                <div>
                    <div class="stat-val <?php echo $at_risk_count > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo number_format($at_risk_count); ?></div>
                    <div class="stat-lbl">Needing Attention</div>
                </div>
            </div>
        </div>

        <!-- Total Exams -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stat-card p-3 d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:#fef3c7; color:#92400e;"><i class="fas fa-file-alt"></i></div>
                <div>
                    <div class="stat-val"><?php echo number_format($total_exams); ?></div>
                    <div class="stat-lbl">Total Exams</div>
                </div>
            </div>
        </div>

        <!-- Highest Subject -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stat-card p-3 d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:#dcfce7; color:#166534;"><i class="fas fa-arrow-up"></i></div>
                <div style="min-width:0;">
                    <div class="stat-val text-truncate" style="font-size:1.05rem;" title="<?php echo htmlspecialchars($highest_subject); ?>">
                        <?php echo htmlspecialchars($highest_subject); ?>
                    </div>
                    <div class="stat-lbl">Highest Subject</div>
                </div>
            </div>
        </div>

        <!-- Lowest Subject -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="stat-card p-3 d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:#ffedd5; color:#9a3412;"><i class="fas fa-arrow-down"></i></div>
                <div style="min-width:0;">
                    <div class="stat-val text-truncate" style="font-size:1.05rem;" title="<?php echo htmlspecialchars($lowest_subject); ?>">
                        <?php echo htmlspecialchars($lowest_subject); ?>
                    </div>
                    <div class="stat-lbl">Lowest Subject</div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. MPS per Exam & Chart -->
    <div class="row g-4 mb-4">
        <!-- Table Column -->
        <div class="col-lg-7">
            <div class="content-card h-100 mb-0">
                <div class="card-hdr">
                    <h5><i class="fas fa-poll-h me-2 text-primary"></i>MPS per Exam</h5>
                    <span class="badge bg-light text-muted border"><?php echo count($allExamsList); ?> Assessment<?php echo count($allExamsList) !== 1 ? 's' : ''; ?></span>
                </div>
                <div class="table-responsive">
                    <?php if (empty($allExamsList)): ?>
                        <div class="text-center py-5 text-muted small">
                            <i class="fas fa-folder-open fa-2x mb-2 text-secondary opacity-50"></i>
                            <p class="mb-0">No exam records found for the selected filter.</p>
                        </div>
                    <?php else: ?>
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Exam Title</th>
                                    <th>Type</th>
                                    <th>Subject</th>
                                    <th>Section</th>
                                    <th class="text-center">Recorded</th>
                                    <th class="text-center">MPS</th>
                                    <th class="text-center">Performance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allExamsList as $row): ?>
                                    <tr>
                                        <td class="fw-bold" style="color:#1e3a8a;"><?php echo htmlspecialchars($row['title']); ?></td>
                                        <td>
                                            <?php if ($row['type'] === 'manual'): ?>
                                                <span class="badge" style="background:#ede9fe; color:#5b21b6; font-size:.72rem;"><i class="fas fa-clipboard-list me-1"></i>Manual</span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#dbeafe; color:#1e40af; font-size:.72rem;"><i class="fas fa-laptop me-1"></i>Online</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['subject_title']); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['section_name'] ?? 'All Sections'); ?></span></td>
                                        <td class="text-center fw-semibold"><?php echo number_format($row['attempts_count']); ?></td>
                                        <td class="text-center fw-bold">
                                            <?php if ($row['attempts_count'] > 0 && $row['mps'] !== null): ?>
                                                <span style="color:#2563eb;"><?php echo number_format($row['mps'], 1); ?>%</span>
                                            <?php else: ?>
                                                <span class="text-muted fw-normal">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?php echo getPerformanceBadge($row['mps'], $threshold); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Chart Column -->
        <div class="col-lg-5">
            <div class="content-card h-100 mb-0 p-3 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold m-0" style="font-size:0.95rem; color:#1e3a8a;"><i class="fas fa-chart-bar me-2 text-primary"></i>MPS Chart by Exam</h5>
                    <span class="small text-muted" style="font-size:0.75rem;">Scale: 0–100%</span>
                </div>
                
                <?php if (empty($chartLabels)): ?>
                    <div class="my-auto text-center py-5 text-muted small">
                        <i class="fas fa-chart-bar fa-3x mb-2 text-secondary opacity-50"></i>
                        <p class="mb-1 fw-bold">No exam submissions recorded yet</p>
                        <p class="text-muted" style="font-size:0.75rem;">Exams without recorded scores are not plotted as 0%.</p>
                    </div>
                <?php else: ?>
                    <div style="flex-grow:1; min-height: 250px; position: relative;">
                        <canvas id="examMpsChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 3. Section & Subject Performance -->
    <div class="row g-4 mb-4">
        <!-- Section Performance -->
        <div class="col-lg-6">
            <div class="content-card h-100 mb-0">
                <div class="card-hdr">
                    <h5><i class="fas fa-users me-2 text-primary"></i>Section Performance</h5>
                </div>
                <div class="table-responsive">
                    <?php if (empty($section_perf_data)): ?>
                        <div class="text-center py-4 text-muted small">No section data available for this filter.</div>
                    <?php else: ?>
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Section</th>
                                    <th class="text-center">Enrolled Students</th>
                                    <th class="text-center">Average MPS</th>
                                    <th class="text-center">Performance Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($section_perf_data as $sec): ?>
                                    <tr>
                                        <td class="fw-bold" style="color:#1e3a8a;"><?php echo htmlspecialchars($sec['section_name']); ?></td>
                                        <td class="text-center"><?php echo number_format($sec['student_count']); ?></td>
                                        <td class="text-center fw-bold">
                                            <?php echo $sec['mps'] !== null ? number_format($sec['mps'], 1) . '%' : '<span class="text-muted fw-normal">N/A</span>'; ?>
                                        </td>
                                        <td class="text-center"><?php echo getPerformanceBadge($sec['mps'], $threshold); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Subject Performance -->
        <div class="col-lg-6">
            <div class="content-card h-100 mb-0">
                <div class="card-hdr">
                    <h5><i class="fas fa-book me-2 text-primary"></i>Subject Performance</h5>
                </div>
                <div class="table-responsive">
                    <?php if (empty($subject_perf_data)): ?>
                        <div class="text-center py-4 text-muted small">No subject data available.</div>
                    <?php else: ?>
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th class="text-center">Students</th>
                                    <th class="text-center">Average MPS</th>
                                    <th class="text-center">Highest / Lowest</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subject_perf_data as $sub): ?>
                                    <tr>
                                        <td class="fw-bold" style="color:#1e3a8a;"><?php echo htmlspecialchars($sub['subject_title']); ?></td>
                                        <td class="text-center"><?php echo number_format($sub['student_count']); ?></td>
                                        <td class="text-center fw-bold" style="color:#2563eb;">
                                            <?php echo $sub['mps'] !== null ? number_format($sub['mps'], 1) . '%' : '<span class="text-muted fw-normal">N/A</span>'; ?>
                                        </td>
                                        <td class="text-center small text-muted">
                                            <?php if ($sub['mps'] !== null): ?>
                                                <?php echo number_format($sub['highest_score'], 1); ?>% / <?php echo number_format($sub['lowest_score'], 1); ?>%
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?php echo getPerformanceBadge($sub['mps'], $threshold); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. Students Needing Attention (Unique Students) -->
    <div class="content-card mb-4" style="border-top: 4px solid #ef4444;">
        <div class="card-hdr" style="background:#fef2f2;">
            <div class="d-flex align-items-center gap-2">
                <h5 class="text-danger fw-bold mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>Students Needing Attention (MPS &lt; <?php echo htmlspecialchars($threshold); ?>%)
                </h5>
                <span class="badge bg-danger rounded-pill"><?php echo $at_risk_count; ?> Unique Student<?php echo $at_risk_count !== 1 ? 's' : ''; ?></span>
            </div>
            <span class="small text-muted">Calculated from actual recorded assessment submissions</span>
        </div>
        <div class="table-responsive">
            <?php if (empty($studentsNeedingAttention)): ?>
                <div class="p-4 text-center text-success fw-semibold">
                    <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
                    All students are currently performing at or above the threshold (<?php echo htmlspecialchars($threshold); ?>%). Great job!
                </div>
            <?php else: ?>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Email</th>
                            <th>Subjects at Risk</th>
                            <th>Section</th>
                            <th class="text-center">Overall MPS</th>
                            <th class="text-center">Risk Level</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studentsNeedingAttention as $st): ?>
                            <tr>
                                <td class="fw-bold" style="color:#1e3a8a;"><?php echo htmlspecialchars($st['student_name']); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($st['student_email']); ?></td>
                                <td>
                                    <?php foreach ($st['at_risk_subjects'] as $subTitle): ?>
                                        <span class="badge bg-light text-dark border me-1 mb-1">
                                            <i class="fas fa-book me-1 text-primary"></i><?php echo htmlspecialchars($subTitle); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($st['section_name']); ?></span></td>
                                <td class="text-center fw-bold text-danger"><?php echo number_format($st['mps'], 1); ?>%</td>
                                <td class="text-center"><?php echo getPerformanceBadge($st['mps'], $threshold); ?></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="viewStudentDetails(<?php echo $st['student_id']; ?>, '<?php echo addslashes($st['student_name']); ?>')">
                                        <i class="fas fa-chart-line me-1"></i> View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</main>

<!-- Student Performance Details Modal -->
<div class="modal fade" id="studentDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);">
                <h5 class="modal-title fw-bold" id="studentModalTitle"><i class="fas fa-user-graduate me-2"></i>Student Performance Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="studentModalBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="text-muted small mt-2">Loading student performance analytics...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Render MPS per Exam Chart
    const examLabels = <?php echo json_encode($chartLabels); ?>;
    const examMps = <?php echo json_encode($chartMps); ?>;
    const thresholdVal = <?php echo json_encode($threshold); ?>;

    const ctx = document.getElementById('examMpsChart');
    if (ctx && examLabels.length > 0) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: examLabels,
                datasets: [{
                    label: 'MPS (%)',
                    data: examMps,
                    backgroundColor: examMps.map(v => {
                        if (v >= thresholdVal) return 'rgba(37, 99, 235, 0.85)';
                        if (v >= Math.max(40, thresholdVal - 15)) return 'rgba(245, 158, 11, 0.85)';
                        return 'rgba(239, 68, 68, 0.85)';
                    }),
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'MPS: ' + context.parsed.y.toFixed(1) + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        max: 100, 
                        ticks: { 
                            callback: v => v + '%',
                            stepSize: 20
                        },
                        grid: {
                            color: '#e2e8f0'
                        }
                    },
                    x: { 
                        ticks: { 
                            font: { size: 11 },
                            maxRotation: 45,
                            minRotation: 0
                        },
                        grid: { display: false }
                    }
                }
            }
        });
    }
});

function viewStudentDetails(studentId, studentName) {
    document.getElementById('studentModalTitle').innerHTML = '<i class="fas fa-user-graduate me-2"></i>' + studentName + ' — Performance History';
    const modalBody = document.getElementById('studentModalBody');
    modalBody.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="text-muted small mt-2">Loading performance data...</p>
        </div>
    `;

    const modal = new bootstrap.Modal(document.getElementById('studentDetailModal'));
    modal.show();

    const threshold = <?php echo json_encode($threshold); ?>;

    fetch('get_student_analytics.php?student_id=' + studentId + '&threshold=' + threshold)
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                modalBody.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
                return;
            }

            const typeTag = (src) => src === 'manual'
                ? '<span class="badge" style="background:#ede9fe;color:#5b21b6;font-size:.68rem;"><i class="fas fa-clipboard-list me-1"></i>Manual</span>'
                : '<span class="badge" style="background:#dbeafe;color:#1e40af;font-size:.68rem;"><i class="fas fa-laptop me-1"></i>Online</span>';

            const scoreDisplay = (q) => `${q.raw_score}/${q.total_items} (${q.score.toFixed(1)}%)`;

            // Subject breakdown rows
            let subjectBreakdownHtml = '';
            if (data.subject_breakdown && data.subject_breakdown.length > 0) {
                subjectBreakdownHtml = `
                    <h6 class="fw-bold text-primary border-bottom pb-2 mt-3"><i class="fas fa-book-reader me-2"></i>Subject Breakdown</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle" style="font-size:.85rem;">
                            <thead class="table-light">
                                <tr><th>Subject</th><th class="text-center">Exams Taken</th><th class="text-center">Average MPS</th><th class="text-center">Status</th></tr>
                            </thead>
                            <tbody>
                                ${data.subject_breakdown.map(sb => `
                                    <tr>
                                        <td class="fw-semibold">${sb.subject_title}</td>
                                        <td class="text-center">${sb.exams_count}</td>
                                        <td class="text-center fw-bold ${sb.mps < threshold ? 'text-danger' : 'text-primary'}">${sb.mps.toFixed(1)}%</td>
                                        <td class="text-center">${sb.badge}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }

            let html = `
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded text-center border">
                            <div class="text-muted small fw-bold text-uppercase">Overall MPS</div>
                            <div class="h3 fw-bold text-primary mb-0">${data.avg_quiz !== 'N/A' ? data.avg_quiz.toFixed(1) + '%' : 'N/A'}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded text-center border">
                            <div class="text-muted small fw-bold text-uppercase">Exams Recorded</div>
                            <div class="h3 fw-bold text-secondary mb-0">${data.quiz_count}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded text-center border">
                            <div class="text-muted small fw-bold text-uppercase">Academic Status</div>
                            <div class="h5 fw-bold ${data.status_class} mb-0 mt-1">${data.status_label}</div>
                        </div>
                    </div>
                </div>

                ${subjectBreakdownHtml}

                <h6 class="fw-bold text-primary border-bottom pb-2 mt-3"><i class="fas fa-history me-2"></i>Assessment Score History</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle" style="font-size:.85rem;">
                        <thead class="table-light">
                            <tr><th>Assessment Title</th><th>Type</th><th>Subject</th><th class="text-center">Score / Points</th><th class="text-center">MPS %</th><th>Date Taken</th></tr>
                        </thead>
                        <tbody>
                            ${data.quizzes.length === 0
                                ? '<tr><td colspan="6" class="text-center text-muted py-3">No recorded submissions for this student.</td></tr>'
                                : data.quizzes.map(q => `
                                <tr>
                                    <td class="fw-semibold">${q.quiz_title}</td>
                                    <td>${typeTag(q.source_type)}</td>
                                    <td>${q.subject_title}</td>
                                    <td class="text-center">${q.raw_score} / ${q.total_items}</td>
                                    <td class="text-center fw-bold ${q.score < threshold ? 'text-danger' : 'text-success'}">${q.score.toFixed(1)}%</td>
                                    <td class="text-muted small">${q.taken_at}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>

                <div class="p-3 rounded border bg-light mt-3">
                    <h6 class="fw-bold text-primary mb-2"><i class="fas fa-lightbulb me-2"></i>Recommendations & Insights</h6>
                    <ul class="mb-0 small text-muted ps-3">
                        ${data.recommendations.map(rec => `<li class="mb-1">${rec}</li>`).join('')}
                    </ul>
                </div>
            `;
            modalBody.innerHTML = html;
        })
        .catch(err => {
            modalBody.innerHTML = '<div class="alert alert-danger">Failed to load student analytics details.</div>';
        });
}
</script>

<?php include '../includes/footer.php'; ?>
