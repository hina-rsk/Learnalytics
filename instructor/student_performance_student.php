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
$pageTitle = 'Student Performance Details - LearnAlytics';
$extraCss = ['assets/css/student-performance.css'];
$errorMessage = '';
$studentId = filter_var($_GET['student_id'] ?? null, FILTER_VALIDATE_INT);

function spaDetailEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function spaDetailPercent($value): string
{
    return $value === null || !is_numeric($value) ? '—' : number_format((float)$value, 1) . '%';
}

function spaDetailOptionName(array $items, int $selectedId): string
{
    foreach ($items as $item) {
        if ((int)$item['id'] === $selectedId) {
            return (string)$item['name'];
        }
    }
    return 'All available years';
}

$options = [];
$filters = [];
$data = null;
$group = null;
$pendingAssessments = 0;
try {
    $options = spaAnalyticsOptions($pdo, $access);
    [$filters] = spaNormalizeFilters($_GET, $options);
    if ($studentId === false || $studentId === null || empty($filters['student_id'])) {
        http_response_code(404);
        $errorMessage = 'This student is unavailable in your authorized analytics scope.';
    } else {
        $riskThreshold = 75.0;
        $moderateThreshold = 80.0;
        if (!empty($filters['department_id'])) {
            $configStmt = $pdo->prepare(
                'SELECT grade_threshold, moderate_grade_threshold
                 FROM risk_configurations WHERE department_id = ? ORDER BY id LIMIT 1'
            );
            $configStmt->execute([$filters['department_id']]);
            $config = $configStmt->fetch(PDO::FETCH_ASSOC);
            if ($config) {
                $riskThreshold = (float)$config['grade_threshold'];
                $moderateThreshold = (float)$config['moderate_grade_threshold'];
            }
        } else {
            $config = $pdo->query(
                'SELECT grade_threshold, moderate_grade_threshold
                 FROM risk_configurations WHERE department_id IS NULL ORDER BY id LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            if ($config) {
                $riskThreshold = (float)$config['grade_threshold'];
                $moderateThreshold = (float)$config['moderate_grade_threshold'];
            }
        }

        $data = spaBuildAnalyticsData($pdo, $access, $_GET, $riskThreshold, $moderateThreshold);
        $filters = $data['filters'];
        $studentId = (int)$filters['student_id'];
        $group = $data['student_groups'][$studentId] ?? null;
        if (!$group) {
            http_response_code(404);
            $errorMessage = 'This student is unavailable in your authorized analytics scope.';
        } else {
            $pendingAssessments = spaPendingAssessmentCount(
                $pdo,
                $access,
                $filters,
                $studentId,
                $data['assessment_records']
            );
        }
    }
} catch (Throwable $e) {
    error_log('Student performance detail query failed: ' . $e->getMessage());
    http_response_code(500);
    $errorMessage = 'Student performance details could not be loaded. Please refresh the page or contact an administrator.';
}

$filterQuery = array_filter($filters, static fn($value) => $value !== null && $value !== '');
$detailQuery = $filterQuery;
unset($detailQuery['student_id']);
$detailUrl = 'student_performance.php' . ($detailQuery ? '?' . http_build_query($detailQuery) : '');
$exportQuery = $filterQuery;
$exportQuery['report_type'] = 'individual';
$exportUrl = 'student_performance.php?' . http_build_query(array_merge($exportQuery, ['export' => 'xlsx']));

$pageActionLabel = 'Back to Analytics';
$pageActionUrl = $detailUrl;
$pageActionIcon = 'fa-arrow-left';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4 spa-page">
    <?php if ($errorMessage !== ''): ?>
        <div class="spa-card">
            <div class="alert alert-danger mb-3" role="alert"><?php echo spaDetailEscape($errorMessage); ?></div>
            <a class="btn btn-primary" href="<?php echo spaDetailEscape($detailUrl); ?>"><i class="fa-solid fa-arrow-left me-1"></i>Back to Analytics</a>
        </div>
    <?php elseif ($group && $data !== null): ?>
        <?php
        $subjectRows = $group['subject_rows'];
        $profileRow = $subjectRows[0];
        $studentRecords = array_values(array_filter(
            $data['assessment_records'],
            static fn($record) => (int)$record['student_id'] === $studentId
        ));
        $comparisonFilters = $_GET;
        unset($comparisonFilters['student_id']);
        $comparisonData = spaBuildAnalyticsData($pdo, $access, $comparisonFilters, $riskThreshold, $moderateThreshold);
        $sectionAverage = null;
        foreach ($comparisonData['section_comparisons'] as $comparison) {
            if ((int)$comparison['id'] === (int)($profileRow['section_id'] ?? 0)) {
                $sectionAverage = $comparison['average'];
                break;
            }
        }
        $chartData = [
            'records' => $studentRecords,
            'comparison' => [
                'student' => $group['average'],
                'section' => $sectionAverage
            ]
        ];
        ?>
        <section class="spa-hero mb-4">
            <div>
                <a class="spa-detail-back" href="<?php echo spaDetailEscape($detailUrl); ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to Student Performance</a>
                <h1 class="mt-2"><?php echo spaDetailEscape($group['student_name']); ?></h1>
                <p><?php echo spaDetailEscape($group['student_number']); ?> · Individual student performance report</p>
            </div>
            <div class="spa-hero-actions">
                <span class="spa-updated"><i class="fa-regular fa-clock"></i> Report generated <?php echo spaDetailEscape(date('M j, Y g:i A')); ?></span>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-light" type="button" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>Print Report</button>
                    <button class="btn btn-outline-light" type="button" onclick="window.print()"><i class="fa-solid fa-file-pdf me-2"></i>Export PDF</button>
                    <a class="btn btn-outline-light" href="<?php echo spaDetailEscape($exportUrl); ?>"><i class="fa-solid fa-file-excel me-2"></i>Export Excel</a>
                </div>
            </div>
        </section>

        <section class="spa-card mb-4">
            <div class="spa-section-heading"><div><h2>Student vs Section Average</h2><p>Compare the selected student’s overall performance with the filtered section average.</p></div></div>
            <?php if ($group['average'] !== null && $sectionAverage !== null): ?>
                <div class="spa-chart-wrap spa-chart-wrap-medium"><canvas id="studentComparisonChart" aria-label="Student average compared with section average"></canvas></div>
            <?php else: ?>
                <div class="spa-empty spa-empty-compact"><i class="fa-solid fa-people-arrows-left-right"></i><span>Student and section averages require recorded assessment data.</span></div>
            <?php endif; ?>
        </section>

        <section class="spa-card mb-4">
            <div class="spa-section-heading">
                <div><h2>Student Information</h2><p>Enrollment and academic context for the selected reporting period.</p></div>
                <span class="spa-status-badge <?php echo $group['average'] !== null && $group['average'] < $riskThreshold ? 'danger' : ($group['average'] === null ? 'neutral' : 'success'); ?>"><?php echo spaDetailEscape($group['status']); ?></span>
            </div>
            <div class="spa-profile-grid">
                <?php foreach ([
                    'Student ID' => $group['student_number'],
                    'Program' => $profileRow['program_name'],
                    'Year Level' => $profileRow['year_level'] ?: 'Not recorded',
                    'Section' => $profileRow['section_name'],
                    'Academic Year' => $filters['academic_year_id']
                        ? spaDetailEscape(spaDetailOptionName($options['academic_years'], (int)$filters['academic_year_id']))
                        : (implode(', ', $group['academic_years']) ?: 'All available years'),
                    'Semester' => $filters['semester'] ?? (implode(', ', $group['semesters']) ?: 'All available semesters')
                ] as $label => $value): ?>
                    <div class="spa-profile-item"><span><?php echo spaDetailEscape($label); ?></span><strong><?php echo spaDetailEscape($value); ?></strong></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="spa-summary-grid mb-4">
            <article class="spa-summary-card"><span class="spa-summary-icon blue"><i class="fa-solid fa-chart-line"></i></span><div><span class="spa-summary-label">Overall Average</span><strong><?php echo spaDetailPercent($group['average']); ?></strong><small>Filtered student average</small></div></article>
            <article class="spa-summary-card"><span class="spa-summary-icon indigo"><i class="fa-solid fa-book-open"></i></span><div><span class="spa-summary-label">Prelim Average</span><strong><?php echo spaDetailPercent($group['period_averages']['Prelim']); ?></strong><small>Recorded prelim grades</small></div></article>
            <article class="spa-summary-card"><span class="spa-summary-icon cyan"><i class="fa-solid fa-book-open"></i></span><div><span class="spa-summary-label">Midterm Average</span><strong><?php echo spaDetailPercent($group['period_averages']['Midterm']); ?></strong><small>Recorded midterm grades</small></div></article>
            <article class="spa-summary-card"><span class="spa-summary-icon green"><i class="fa-solid fa-graduation-cap"></i></span><div><span class="spa-summary-label">Final Average</span><strong><?php echo spaDetailPercent($group['period_averages']['Final']); ?></strong><small>Recorded final grades</small></div></article>
            <article class="spa-summary-card"><span class="spa-summary-icon amber"><i class="fa-solid fa-arrow-up"></i></span><div><span class="spa-summary-label">Highest Assessment</span><strong><?php echo spaDetailPercent($group['highest_score']); ?></strong><small>Valid scored records</small></div></article>
            <article class="spa-summary-card"><span class="spa-summary-icon rose"><i class="fa-solid fa-arrow-down"></i></span><div><span class="spa-summary-label">Lowest Assessment</span><strong><?php echo spaDetailPercent($group['lowest_score']); ?></strong><small>Valid scored records</small></div></article>
            <article class="spa-summary-card"><span class="spa-summary-icon blue"><i class="fa-solid fa-circle-check"></i></span><div><span class="spa-summary-label">Completed Assessments</span><strong><?php echo number_format($group['completed_assessments']); ?></strong><small>Graded assessments</small></div></article>
            <article class="spa-summary-card"><span class="spa-summary-icon amber"><i class="fa-regular fa-clock"></i></span><div><span class="spa-summary-label">Pending Assessments</span><strong><?php echo number_format($pendingAssessments); ?></strong><small>Available and not yet graded</small></div></article>
        </section>

        <section class="row g-4 mb-4">
            <div class="col-12 col-xl-8">
                <div class="spa-card h-100">
                    <div class="spa-section-heading"><div><h2>Performance Progress</h2><p>Recorded percentages across exams, quizzes, assignments, and grading periods.</p></div><span class="spa-chart-icon"><i class="fa-solid fa-chart-line"></i></span></div>
                    <?php if ($studentRecords): ?>
                        <div class="spa-chart-wrap"><canvas id="studentTrendChart" aria-label="Student performance progress throughout the semester"></canvas></div>
                    <?php else: ?>
                        <div class="spa-empty"><i class="fa-solid fa-chart-line"></i><span>No completed scores are available for this student in the selected filters.</span></div>
                    <?php endif; ?>
                    <div class="spa-grade-note">Performance trend: <strong><?php echo spaDetailEscape($group['trend']); ?></strong>. Missing and pending assessments are not plotted as zero.</div>
                </div>
            </div>
            <div class="col-12 col-xl-4">
                <div class="spa-card h-100">
                    <div class="spa-section-heading"><div><h2>Assessment Snapshot</h2><p>Average percentage by scored assessment.</p></div></div>
                    <?php if ($studentRecords): ?>
                        <div class="table-responsive">
                            <table class="table spa-table align-middle mb-0">
                                <thead><tr><th>Assessment</th><th>Type</th><th>Score</th></tr></thead>
                                <tbody>
                                <?php foreach ($studentRecords as $record): ?>
                                    <tr>
                                        <td><?php echo spaDetailEscape($record['title']); ?></td>
                                        <td><?php echo spaDetailEscape($record['assessment_type']); ?></td>
                                        <td><strong><?php echo spaDetailPercent($record['percentage']); ?></strong><small class="d-block text-muted"><?php echo spaDetailEscape($record['score']); ?> / <?php echo spaDetailEscape($record['max_score']); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="spa-empty spa-empty-compact"><i class="fa-solid fa-clipboard-list"></i><span>No scored assessments.</span></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="spa-card mb-4">
            <div class="spa-section-heading"><div><h2>Subject Performance</h2><p>Grades and subject averages for this student.</p></div></div>
            <div class="table-responsive">
                <table class="table spa-table align-middle mb-0">
                    <thead><tr><th>Subject</th><th>Instructor</th><th>Prelim</th><th>Midterm</th><th>Final</th><th>Final Average</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($subjectRows as $subjectRow): ?>
                        <?php $subjectAverage = $subjectRow['official_average']; ?>
                        <tr>
                            <td><strong><?php echo spaDetailEscape($subjectRow['subject_title']); ?></strong></td>
                            <td><?php echo spaDetailEscape($subjectRow['instructor_name']); ?></td>
                            <td><?php echo spaDetailPercent($subjectRow['prelim_grade']); ?></td>
                            <td><?php echo spaDetailPercent($subjectRow['midterm_grade']); ?></td>
                            <td><?php echo spaDetailPercent($subjectRow['final_grade']); ?></td>
                            <td><?php echo spaDetailPercent($subjectAverage); ?></td>
                            <td><span class="spa-status-badge <?php echo $subjectAverage === null ? 'neutral' : ($subjectAverage < $riskThreshold ? 'danger' : 'success'); ?>"><?php echo spaDetailEscape(spaPerformanceStatus($subjectAverage, $riskThreshold, $moderateThreshold)); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php if ($group && $data !== null): ?>
    <script type="application/json" id="spaStudentChartData"><?php echo json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?></script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/student-performance.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
