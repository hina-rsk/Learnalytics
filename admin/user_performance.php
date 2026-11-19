<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'dean'], true)) {
    header('Location: ../auth/login.php');
    exit;
}

$isDean = $_SESSION['user_role'] === 'dean';
$viewerDepartmentId = null;
if ($isDean) {
    $stmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ? AND role = 'dean'");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $viewerDepartmentId = (int)$stmt->fetchColumn();
    if ($viewerDepartmentId < 1) {
        http_response_code(403);
        exit('A department must be assigned to your Dean account.');
    }
}

if (empty($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    header('Location: ' . ($isDean ? '../dean_dashboard.php?tab=students' : 'users.php'));
    exit;
}

$targetUserId = (int)$_GET['user_id'];
$stmt = $pdo->prepare("SELECT id, name, email, role, created_at FROM users WHERE id = ?");
$stmt->execute([$targetUserId]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: ' . ($isDean ? '../dean_dashboard.php?tab=students' : 'users.php'));
    exit;
}

if ($isDean) {
    if ($user['role'] === 'student') {
        $profileStmt = $pdo->prepare('SELECT student_id FROM student_profiles WHERE student_id = ? AND department_id = ?');
        $profileStmt->execute([$targetUserId, $viewerDepartmentId]);
        if (!$profileStmt->fetchColumn()) {
            http_response_code(403);
            exit('You can only view students in your assigned department.');
        }
    } elseif ($user['role'] === 'instructor') {
        $instructorScopeStmt = $pdo->prepare('SELECT id FROM subjects WHERE instructor_id = ? AND department_id = ? LIMIT 1');
        $instructorScopeStmt->execute([$targetUserId, $viewerDepartmentId]);
        if (!$instructorScopeStmt->fetchColumn()) {
            http_response_code(403);
            exit('You can only view instructors who teach in your assigned department.');
        }
    } else {
        http_response_code(403);
        exit('You can only view students and instructors in your assigned department.');
    }
}

$stats = [];
$recentGrades = [];
$courseGradeAverages = ['prelim' => null, 'midterm' => null, 'final' => null];
$gradeDistribution = ['0-59' => 0, '60-69' => 0, '70-79' => 0, '80-89' => 0, '90-100' => 0];
$recentCourses = [];
$additionalInfo = [];
$studentCourseScope = $isDean ? ' AND c.department_id = ?' : '';
$studentScopeParams = $isDean ? [$viewerDepartmentId] : [];

if ($user['role'] === 'student') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN subjects c ON c.id = e.subject_id WHERE e.student_id = ?{$studentCourseScope}");
    $stmt->execute(array_merge([$targetUserId], $studentScopeParams));
    $stats['enrolled_courses'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT a.id) FROM assignments a
         JOIN subjects c ON a.subject_id = c.id
         JOIN enrollments e ON c.id = e.subject_id
            WHERE e.student_id = ?{$studentCourseScope}"
    );
        $stmt->execute(array_merge([$targetUserId], $studentScopeParams));
    $totalAssignments = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM submissions s
         JOIN assignments a ON s.assignment_id = a.id
            JOIN subjects c ON c.id = a.subject_id
            WHERE s.student_id = ? AND s.grade IS NOT NULL{$studentCourseScope}"
    );
        $stmt->execute(array_merge([$targetUserId], $studentScopeParams));
    $stats['graded_submissions'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT AVG(
            CAST(s.grade AS DECIMAL(5,2))
        ) AS avg_score
        FROM submissions s
        JOIN assignments a ON a.id = s.assignment_id
        JOIN subjects c ON c.id = a.subject_id
        WHERE s.student_id = ? AND s.grade IS NOT NULL{$studentCourseScope}"
    );
    $stmt->execute(array_merge([$targetUserId], $studentScopeParams));
    $avgScore = round((float)$stmt->fetchColumn(), 1);

    $stats['average_grade'] = $avgScore > 0 ? $avgScore : 0;
    $stats['completion_rate'] = $totalAssignments > 0 ? round(($stats['graded_submissions'] / $totalAssignments) * 100, 1) : 0;

    $stmt = $pdo->prepare(
        "SELECT c.title AS subject_title, g.prelim_grade, g.midterm_grade, g.final_grade, g.updated_at
         FROM student_grades g
         JOIN subjects c ON c.id = g.subject_id
         WHERE g.student_id = ? AND (g.prelim_grade IS NOT NULL OR g.midterm_grade IS NOT NULL OR g.final_grade IS NOT NULL){$studentCourseScope}
         ORDER BY g.updated_at DESC, c.title ASC
         LIMIT 10"
    );
        $stmt->execute(array_merge([$targetUserId], $studentScopeParams));
    $recentGrades = $stmt->fetchAll();

    $stmt = $pdo->prepare(
            "SELECT AVG(g.prelim_grade) AS prelim_average,
                    AVG(g.midterm_grade) AS midterm_average,
                    AVG(g.final_grade) AS final_average
             FROM student_grades g
             JOIN subjects c ON c.id = g.subject_id
             WHERE g.student_id = ?{$studentCourseScope}"
        );
        $stmt->execute(array_merge([$targetUserId], $studentScopeParams));
        $gradeAverages = $stmt->fetch();
        $courseGradeAverages = [
            'prelim' => $gradeAverages['prelim_average'] !== null ? round((float)$gradeAverages['prelim_average'], 2) : null,
            'midterm' => $gradeAverages['midterm_average'] !== null ? round((float)$gradeAverages['midterm_average'], 2) : null,
            'final' => $gradeAverages['final_average'] !== null ? round((float)$gradeAverages['final_average'], 2) : null
        ];

        $stmt = $pdo->prepare(
            "SELECT g.prelim_grade, g.midterm_grade, g.final_grade
             FROM student_grades g
             JOIN subjects c ON c.id = g.subject_id
             WHERE g.student_id = ?{$studentCourseScope}"
        );
        $stmt->execute(array_merge([$targetUserId], $studentScopeParams));
        foreach ($stmt->fetchAll() as $courseGrades) {
            foreach (['prelim_grade', 'midterm_grade', 'final_grade'] as $gradeColumn) {
                if ($courseGrades[$gradeColumn] === null) {
                    continue;
                }
                $score = (float)$courseGrades[$gradeColumn];
                $gradeBand = $score < 60 ? '0-59' : ($score < 70 ? '60-69' : ($score < 80 ? '70-79' : ($score < 90 ? '80-89' : '90-100')));
                $gradeDistribution[$gradeBand]++;
            }
        }

        $stmt = $pdo->prepare(
            "SELECT c.id, c.title, c.description, u.name AS instructor_name, e.enrolled_at,
                    g.prelim_grade, g.midterm_grade, g.final_grade
         FROM subjects c
         JOIN enrollments e ON c.id = e.subject_id
         JOIN users u ON c.instructor_id = u.id
         LEFT JOIN student_grades g ON g.subject_id = c.id AND g.student_id = e.student_id
            WHERE e.student_id = ?{$studentCourseScope}
         ORDER BY c.title ASC
         LIMIT 8"
    );
        $stmt->execute(array_merge([$targetUserId], $studentScopeParams));
    $additionalInfo['enrolledCourses'] = $stmt->fetchAll();
} elseif ($user['role'] === 'instructor') {
    $instructorCourseScope = $isDean ? ' AND c.department_id = ?' : '';
    $instructorScopeParams = $isDean ? [$viewerDepartmentId] : [];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects c WHERE c.instructor_id = ?{$instructorCourseScope}");
    $stmt->execute(array_merge([$targetUserId], $instructorScopeParams));
    $stats['subject_count'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT e.student_id) FROM enrollments e
         JOIN subjects c ON e.subject_id = c.id
         WHERE c.instructor_id = ?{$instructorCourseScope}"
    );
    $stmt->execute(array_merge([$targetUserId], $instructorScopeParams));
    $stats['total_students'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM assignments a
         JOIN subjects c ON a.subject_id = c.id
         WHERE c.instructor_id = ?{$instructorCourseScope}"
    );
    $stmt->execute(array_merge([$targetUserId], $instructorScopeParams));
    $stats['assignments'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM submissions s
         JOIN assignments a ON s.assignment_id = a.id
         JOIN subjects c ON a.subject_id = c.id
         WHERE c.instructor_id = ? AND s.grade IS NOT NULL{$instructorCourseScope}"
    );
    $stmt->execute(array_merge([$targetUserId], $instructorScopeParams));
    $stats['graded_submissions'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT AVG(
            CAST(s.grade AS DECIMAL(5,2))
        ) AS avg_score
        FROM submissions s
        JOIN assignments a ON s.assignment_id = a.id
        JOIN subjects c ON a.subject_id = c.id
        WHERE c.instructor_id = ? AND s.grade IS NOT NULL{$instructorCourseScope}"
    );
    $stmt->execute(array_merge([$targetUserId], $instructorScopeParams));
    $avgScore = round((float)$stmt->fetchColumn(), 1);

    $stats['average_student_score'] = $avgScore > 0 ? $avgScore : 0;

    $stmt = $pdo->prepare(
        "SELECT c.title, c.status, COUNT(e.id) AS enrolled_students
         FROM subjects c
         LEFT JOIN enrollments e ON e.subject_id = c.id
        WHERE c.instructor_id = ?{$instructorCourseScope}
         GROUP BY c.id
         ORDER BY enrolled_students DESC
         LIMIT 8"
    );
    $stmt->execute(array_merge([$targetUserId], $instructorScopeParams));
    $additionalInfo['courseSummary'] = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT c.title AS subject_title, COUNT(s.id) AS graded_count FROM submissions s
         JOIN assignments a ON s.assignment_id = a.id
         JOIN subjects c ON a.subject_id = c.id
         WHERE c.instructor_id = ? AND s.grade IS NOT NULL{$instructorCourseScope}
         GROUP BY c.id
         ORDER BY graded_count DESC
         LIMIT 5"
    );
    $stmt->execute(array_merge([$targetUserId], $instructorScopeParams));
    $recentCourses = $stmt->fetchAll();
} else {
    $stats['managed_users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['active_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active' OR status IS NULL")->fetchColumn();
    $stats['pending_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
    $stats['recent_activity'] = [];
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <style>
        .user-performance-stat .card-body {
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            gap: 0.5rem;
        }

        .user-performance-stat .dashboard-card-title {
            margin: 0;
            line-height: 1.35;
        }

        .user-performance-stat .dashboard-card-value {
            font-size: clamp(1.75rem, 2.4vw, 2.5rem);
            line-height: 1.1;
        }
    </style>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <div>
            <h1 class="h2" style="color: #1e3a8a;">Monitoring: <?php echo htmlspecialchars($user['name']); ?></h1>
            <p class="text-muted mb-0">Role: <?php echo ucfirst(htmlspecialchars($user['role'])); ?> · Joined <?php echo date('M d, Y', strtotime($user['created_at'])); ?></p>
        </div>
        <div>
            <?php if ($isDean): ?>
                <a href="../dean_dashboard.php?tab=<?php echo $user['role'] === 'instructor' ? 'faculty' : 'students'; ?>" class="btn btn-outline-secondary me-2">
                    Back to <?php echo $user['role'] === 'instructor' ? 'Faculty' : 'Students'; ?>
                </a>
                <a href="../dean_dashboard.php" class="btn btn-primary">Back to Dean Dashboard</a>
            <?php else: ?>
                <a href="users.php" class="btn btn-outline-secondary me-2">Back to Users</a>
                <a href="../admin_dashboard.php" class="btn btn-primary">Back to Dashboard</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($user['role'] === 'student'): ?>
        <!-- Analytics Section -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Course Grade Progress</h5>
                    </div>
                    <div class="card-body">
                        <div style="position: relative; height: 300px; margin-bottom: 30px;">
                            <?php if (count(array_filter($courseGradeAverages, static function ($grade) { return $grade !== null; })) > 0): ?>
                                <canvas id="performanceChart"></canvas>
                            <?php else: ?>
                                <p class="text-center text-muted py-5 mb-0">Course grades are not available yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Grade Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div style="position: relative; height: 250px;">
                            <?php if (array_sum($gradeDistribution) > 0): ?>
                                <canvas id="gradeDistributionChart"></canvas>
                            <?php else: ?>
                                <p class="text-center text-muted py-5 mb-0">Course grades are not available yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Enrolled Courses</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($additionalInfo['enrolledCourses'])): ?>
                            <div class="list-group dashboard-summary-list">
                                <?php foreach ($additionalInfo['enrolledCourses'] as $course): ?>
                                    <div class="list-group-item p-3">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($course['title']); ?></h6>
                                        <p class="mb-1 text-muted small"><?php echo htmlspecialchars($course['description']); ?></p>
                                        <small class="text-muted">Instructor: <?php echo htmlspecialchars($course['instructor_name']); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">This student is not enrolled in any courses.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Recent Grades</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($recentGrades)): ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Course</th>
                                            <th>Prelim</th>
                                            <th>Midterm</th>
                                            <th>Final</th>
                                            <th>Updated</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentGrades as $grade): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($grade['subject_title']); ?></td>
                                                <?php foreach (['prelim_grade', 'midterm_grade', 'final_grade'] as $gradeColumn): ?>
                                                    <td><?php echo $grade[$gradeColumn] !== null ? htmlspecialchars(number_format((float)$grade[$gradeColumn], 2)) : '<span class="text-muted">Not yet graded</span>'; ?></td>
                                                <?php endforeach; ?>
                                                <td><?php echo htmlspecialchars(date('M d, Y', strtotime($grade['updated_at']))); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">No course grades available yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($user['role'] === 'instructor'): ?>
        <!-- Analytics Section for Instructors -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Course Performance Analytics</h5>
                    </div>
                    <div class="card-body">
                        <div style="position: relative; height: 300px;">
                            <canvas id="coursePerformanceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Grading Workload</h5>
                    </div>
                    <div class="card-body">
                        <div style="position: relative; height: 250px;">
                            <canvas id="gradingWorkloadChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Course Enrollment Summary</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($additionalInfo['courseSummary'])): ?>
                            <div class="list-group dashboard-summary-list">
                                <?php foreach ($additionalInfo['courseSummary'] as $course): ?>
                                    <div class="list-group-item p-3">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($course['title']); ?></h6>
                                                <small class="text-muted">Status: <?php echo ucfirst(htmlspecialchars($course['status'])); ?></small>
                                            </div>
                                            <span class="badge bg-primary rounded-pill"><?php echo $course['enrolled_students']; ?> students</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">This instructor has no published courses yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Recent Graded Work</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentCourses)): ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($recentCourses as $course): ?>
                                    <li class="list-group-item border-0 p-3 d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($course['subject_title']); ?></strong>
                                            <div class="small text-muted">Graded submissions: <?php echo $course['graded_count']; ?></div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">No graded submissions found for this instructor.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="card dashboard-card shadow-sm bg-white h-100">
                    <div class="card-body">
                        <div class="dashboard-card-title">Managed Users</div>
                        <div class="dashboard-card-value"><?php echo $stats['managed_users']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card dashboard-card shadow-sm bg-white h-100">
                    <div class="card-body">
                        <div class="dashboard-card-title">Active Accounts</div>
                        <div class="dashboard-card-value"><?php echo $stats['active_users']; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card dashboard-card shadow-sm bg-white h-100">
                    <div class="card-body">
                        <div class="dashboard-card-title">Pending Users</div>
                        <div class="dashboard-card-value"><?php echo $stats['pending_users']; ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card shadow-sm border-0 p-4 mb-4">
            <h5 class="mb-3">Admin Overview</h5>
            <p class="text-muted">This administrator account controls system access and can review audit logs in the User Management module.</p>
        </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($user['role'] === 'student'): ?>
        // Student Performance Chart
        if (document.getElementById('performanceChart')) {
            const perfCtx = document.getElementById('performanceChart').getContext('2d');
            new Chart(perfCtx, {
                type: 'line',
                data: {
                    labels: ['Prelim', 'Midterm', 'Final'],
                    datasets: [{
                        label: 'Average Course Grade',
                        data: <?php echo json_encode(array_values($courseGradeAverages), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                        borderColor: 'rgb(37, 99, 235)',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    },
                    scales: {
                        y: { beginAtZero: true, max: 100 }
                    }
                }
            });
        }

        // Grade Distribution Chart
        if (document.getElementById('gradeDistributionChart')) {
            const gradeCounts = <?php echo json_encode($gradeDistribution, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            
            const gradeCtx = document.getElementById('gradeDistributionChart').getContext('2d');
            new Chart(gradeCtx, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(gradeCounts).map((band) => band + '%'),
                    datasets: [{
                        data: Object.values(gradeCounts),
                        backgroundColor: [
                            'rgba(239, 68, 68, 0.7)',
                            'rgba(249, 115, 22, 0.7)',
                            'rgba(251, 191, 36, 0.7)',
                            'rgba(59, 130, 246, 0.7)',
                            'rgba(34, 197, 94, 0.7)'
                        ],
                        borderColor: ['#ef4444', '#f97316', '#fbbf24', '#3b82f6', '#22c55e'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }
    <?php else: ?>
        // Instructor Course Performance Chart
        if (document.getElementById('coursePerformanceChart')) {
            const courses = <?php echo json_encode(array_map(fn($c) => ['title' => $c['title'], 'students' => $c['enrolled_students']], $additionalInfo['courseSummary'] ?? [])); ?>;
            const cpCtx = document.getElementById('coursePerformanceChart').getContext('2d');
            new Chart(cpCtx, {
                type: 'bar',
                data: {
                    labels: courses.map(c => c.title.substring(0, 15)),
                    datasets: [{
                        label: 'Enrolled Students',
                        data: courses.map(c => c.students),
                        backgroundColor: 'rgba(37, 99, 235, 0.7)',
                        borderColor: 'rgb(30, 58, 138)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }

        // Grading Workload Chart
        if (document.getElementById('gradingWorkloadChart')) {
            const gradingData = <?php echo json_encode(array_map(fn($c) => ['title' => $c['subject_title'], 'count' => $c['graded_count']], $recentCourses ?? [])); ?>;
            const gwCtx = document.getElementById('gradingWorkloadChart').getContext('2d');
            new Chart(gwCtx, {
                type: 'doughnut',
                data: {
                    labels: gradingData.map(g => g.title.substring(0, 12)),
                    datasets: [{
                        data: gradingData.map(g => g.count),
                        backgroundColor: [
                            'rgba(59, 130, 246, 0.7)',
                            'rgba(34, 197, 94, 0.7)',
                            'rgba(251, 191, 36, 0.7)',
                            'rgba(249, 115, 22, 0.7)',
                            'rgba(139, 92, 246, 0.7)'
                        ],
                        borderColor: ['#3b82f6', '#22c55e', '#fbbf24', '#f97316', '#8b5cf6'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }
    <?php endif; ?>
});
</script>

<?php include '../includes/footer.php'; ?>
