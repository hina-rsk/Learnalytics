<?php
session_start();
require_once '../config/database.php';
require_once '../includes/enrollment_requests.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$subject_id = (int)($_GET['id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'];
$error = '';
$msg = $_GET['msg'] ?? '';

// Fetch Subject details
$stmt = $pdo->prepare("SELECT c.*, u.name as instructor_name, u.email as instructor_email,
    p.name as program_name, d.name as department_name, s.name as section_name,
    ay.name as academic_year_name
FROM subjects c
LEFT JOIN users u ON c.instructor_id = u.id
LEFT JOIN programs p ON c.program_id = p.id
LEFT JOIN departments d ON c.department_id = d.id
LEFT JOIN sections s ON c.section_id = s.id
LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
WHERE c.id = ?");
$stmt->execute([$subject_id]);
$subject = $stmt->fetch();

if (!$subject) {
    die("Subject not found.");
}

if ($role === 'instructor' && (int)$subject['instructor_id'] !== $user_id) {
    http_response_code(403);
    exit('You can only view courses assigned to you.');
}
if ($role === 'dean') {
    $stmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ? AND role = 'dean'");
    $stmt->execute([$user_id]);
    $deanDepartmentId = (int)$stmt->fetchColumn();
    if ($deanDepartmentId < 1 || (int)$subject['department_id'] !== $deanDepartmentId) {
        http_response_code(403);
        exit('You can only view courses belonging to your department.');
    }
}

// Check enrollment for students
$is_enrolled = false;
$enrollment_request_status = null;
if ($role === 'student') {
    ensureEnrollmentRequestsTable($pdo);
    if (empty($_SESSION['subject_enrollment_csrf'])) {
        $_SESSION['subject_enrollment_csrf'] = bin2hex(random_bytes(32));
    }
    $stmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND subject_id = ?");
    $stmt->execute([$user_id, $subject_id]);
    $is_enrolled = $stmt->fetch() ? true : false;
    $requestStmt = $pdo->prepare('SELECT status FROM enrollment_requests WHERE student_id = ? AND subject_id = ?');
    $requestStmt->execute([$user_id, $subject_id]);
    $enrollment_request_status = $requestStmt->fetchColumn() ?: null;
}

// Fetch enrolled students for instructor/admin/dean
$enrolledStudents = [];
if (in_array($role, ['admin', 'instructor', 'dean'])) {
    $stmt = $pdo->prepare("SELECT u.id, u.name, u.email, e.enrolled_at FROM enrollments e JOIN users u ON e.student_id = u.id WHERE e.subject_id = ? ORDER BY e.enrolled_at DESC");
    $stmt->execute([$subject_id]);
    $enrolledStudents = $stmt->fetchAll();
}

// Handle Enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll']) && $role === 'student') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfToken) || !hash_equals($_SESSION['subject_enrollment_csrf'], $csrfToken)) {
        $error = 'Your session has expired. Refresh the page and try again.';
    } elseif ($subject['status'] !== 'active') {
        $error = 'This class is not currently accepting join requests.';
    } else {
        $requestResult = requestEnrollment($pdo, $user_id, $subject_id);
        if ($requestResult === 'already_enrolled') {
            $error = 'You are already enrolled in this class.';
        } else {
            header("Location: view.php?id=$subject_id&msg=enrollment_" . $requestResult);
            exit;
        }
    }
}

$pageTitle = $subject['title'] . ' - LearnAlytics';
include '../includes/header.php';
include '../includes/sidebar.php';

if ($role === 'student' && !$is_enrolled):
?>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <span class="badge bg-primary mb-2"><?php echo htmlspecialchars($subject['class_code'] ?? 'SUBJECT'); ?></span>
            <h1 class="h3" style="color:#1e3a8a;"><?php echo htmlspecialchars($subject['title']); ?></h1>
            <p class="text-muted"><?php echo nl2br(htmlspecialchars($subject['description'] ?? '')); ?></p>
            <p><strong>Instructor:</strong> <?php echo htmlspecialchars($subject['instructor_name'] ?? 'Unassigned'); ?></p>
            <?php if ($msg === 'enrollment_requested'): ?>
                <div class="alert alert-success">Your request was sent to the instructor. You can access class materials after approval.</div>
            <?php elseif ($enrollment_request_status === 'pending' || $msg === 'enrollment_pending'): ?>
                <div class="alert alert-warning">Your request is awaiting instructor approval. Class materials will be available once approved.</div>
            <?php elseif ($msg === 'enrollment_denied'): ?>
                <div class="alert alert-secondary">Your previous request was declined. You may submit a new request if needed.</div>
            <?php endif; ?>
            <?php if ($subject['status'] === 'active' && $enrollment_request_status !== 'pending'): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['subject_enrollment_csrf']); ?>">
                    <button type="submit" name="enroll" class="btn btn-primary">
                        <i class="fas fa-user-plus me-1"></i> Request to Join
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php
include '../includes/footer.php';
exit;
endif;
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="subject-header mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge rounded-pill bg-primary px-3 py-1"><?php echo htmlspecialchars($subject['class_code'] ?? 'SUBJECT'); ?></span>
                <?php if (!empty($subject['status'])): ?>
                    <span class="badge rounded-pill text-white px-3 py-1" style="background: <?php echo $subject['status'] === 'archived' ? '#6b7280' : ($subject['status'] === 'draft' ? '#64748b' : '#10b981'); ?>;">
                        <i class="fas fa-circle me-1" style="font-size: 0.5rem; vertical-align: middle;"></i><?php echo ucfirst(htmlspecialchars($subject['status'])); ?>
                    </span>
                <?php endif; ?>
            </div>
            <h1 class="subject-page-title"><?php echo htmlspecialchars($subject['title']); ?></h1>
        </div>

        <div class="subject-header-actions d-flex align-items-center gap-2">
            <?php if ($role === 'student' && !$is_enrolled && $enrollment_request_status !== 'pending' && $subject['status'] === 'active'): ?>
                <form method="POST">
                    <button type="submit" name="enroll" class="btn btn-sm subject-action-btn"><i class="fas fa-user-plus me-1"></i> Request to Join</button>
                </form>
            <?php elseif ($role === 'student' && !$is_enrolled && $enrollment_request_status === 'pending'): ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i> Awaiting Instructor Approval</span>
            <?php elseif ($role === 'student' && !$is_enrolled && $subject['status'] === 'archived'): ?>
                <span class="badge subject-status-badge archived"><i class="fas fa-archive me-1"></i> Archived</span>
            <?php elseif ($role === 'student' && $is_enrolled): ?>
                <span class="badge subject-status-badge active"><i class="fas fa-check me-1"></i> Enrolled</span>
            <?php elseif (($role === 'instructor' && $subject['instructor_id'] == $user_id) || $role === 'admin'): ?>
                <a href="edit.php?id=<?php echo $subject_id; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm fw-semibold">
                    <i class="fas fa-edit me-1"></i> Edit Subject
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($msg === 'assignment_created'): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>Assignment created successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($msg === 'assignment_updated'): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>Assignment updated successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($msg === 'enrollment_requested'): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>Your request was sent to the instructor. You can access the class after approval.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Subject Overview Card -->
            <div class="card shadow border-0 mb-4 course-content rounded-3 overflow-hidden">
                <div class="card-header card-header-custom d-flex align-items-center justify-content-between">
                    <h5 class="card-title mb-0"><i class="fas fa-info-circle me-2"></i>Subject Overview</h5>
                </div>
                <div class="card-body p-4">
                    <p class="card-text text-secondary mb-4" style="line-height: 1.7; font-size: 0.95rem;">
                        <?php echo !empty($subject['description']) ? nl2br(htmlspecialchars($subject['description'])) : 'No description provided for this subject.'; ?>
                    </p>
                    
                    <div class="subject-meta-grid">
                        <div class="subject-meta-chip">
                            <div class="subject-meta-chip-icon"><i class="fas fa-user-tie"></i></div>
                            <div>
                                <div class="subject-meta-chip-label">Instructor</div>
                                <div class="subject-meta-chip-value"><?php echo htmlspecialchars($subject['instructor_name'] ?? 'Unassigned'); ?></div>
                            </div>
                        </div>

                        <?php if (!empty($subject['class_code'])): ?>
                            <div class="subject-meta-chip">
                                <div class="subject-meta-chip-icon"><i class="fas fa-key"></i></div>
                                <div class="flex-grow-1">
                                    <div class="subject-meta-chip-label">Class Code</div>
                                    <div class="subject-meta-chip-value d-flex align-items-center gap-2">
                                        <span class="text-primary fw-bold"><?php echo htmlspecialchars($subject['class_code']); ?></span>
                                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 rounded-2" title="Copy Class Code" onclick="copyClassCode('<?php echo htmlspecialchars($subject['class_code']); ?>', this)">
                                            <i class="far fa-copy me-1"></i>Copy
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($subject['program_name'])): ?>
                            <div class="subject-meta-chip">
                                <div class="subject-meta-chip-icon"><i class="fas fa-graduation-cap"></i></div>
                                <div>
                                    <div class="subject-meta-chip-label">Program</div>
                                    <div class="subject-meta-chip-value"><?php echo htmlspecialchars($subject['program_name']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($subject['department_name'])): ?>
                            <div class="subject-meta-chip">
                                <div class="subject-meta-chip-icon"><i class="fas fa-building"></i></div>
                                <div>
                                    <div class="subject-meta-chip-label">Department</div>
                                    <div class="subject-meta-chip-value"><?php echo htmlspecialchars($subject['department_name']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($subject['section_name'])): ?>
                            <div class="subject-meta-chip">
                                <div class="subject-meta-chip-icon"><i class="fas fa-users flex-shrink-0"></i></div>
                                <div>
                                    <div class="subject-meta-chip-label">Section</div>
                                    <div class="subject-meta-chip-value"><?php echo htmlspecialchars($subject['section_name']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($subject['academic_year_name'])): ?>
                            <div class="subject-meta-chip">
                                <div class="subject-meta-chip-icon"><i class="fas fa-calendar-alt"></i></div>
                                <div>
                                    <div class="subject-meta-chip-label">Academic Year</div>
                                    <div class="subject-meta-chip-value"><?php echo htmlspecialchars($subject['academic_year_name']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Course Content Tabs Card -->
            <div class="card shadow border-0 mb-4 rounded-3 overflow-hidden">
                <div class="card-body p-4">
                    <!-- Tab Navigation -->
                    <div class="tab-nav mb-4">
                        <button type="button" class="tab-btn active" id="btn-tab-assignments" onclick="showCourseTab('assignments', this)">
                            <i class="fas fa-tasks me-1"></i> Assignments
                        </button>
                        <button type="button" class="tab-btn" id="btn-tab-quizzes" onclick="showCourseTab('quizzes', this)">
                            <i class="fas fa-question-circle me-1"></i> Quizzes
                        </button>
                        <button type="button" class="tab-btn" id="btn-tab-lessons" onclick="showCourseTab('lessons', this)">
                            <i class="fas fa-graduation-cap me-1"></i> Lessons
                        </button>
                        <button type="button" class="tab-btn" id="btn-tab-attendance" onclick="showCourseTab('attendance', this)">
                            <i class="fas fa-calendar-check me-1"></i> Attendance
                        </button>
                    </div>

                    <div id="courseTabsContent">
                        <!-- Assignments Tab (First / Active) -->
                        <div class="course-tab-pane" id="tab-pane-assignments" style="display: block;">
                            <?php if (($role === 'instructor' && $subject['instructor_id'] == $user_id) || $role === 'admin'): ?>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold text-secondary mb-0"><i class="fas fa-tasks me-1"></i> Class Assignments</h6>
                                    <a href="../assignments/create.php?subject_id=<?php echo $subject_id; ?>" class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3 shadow-sm">
                                        <i class="fas fa-plus me-1"></i> Create Assignment
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php
                            $stmt = $pdo->prepare("SELECT a.*, 
                                (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id) as submission_count,
                                (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id AND grade IS NULL) as pending_grades
                            FROM assignments a 
                            WHERE a.subject_id = ? 
                            ORDER BY a.due_date ASC");
                            $stmt->execute([$subject_id]);
                            $assignments = $stmt->fetchAll();
                            ?>

                            <?php if (empty($assignments)): ?>
                                <div class="p-4 text-center text-muted bg-light rounded-3 border">
                                    <i class="fas fa-clipboard-list fa-2x mb-2 text-warning opacity-50"></i>
                                    <p class="mb-0">No assignments posted for this subject yet.</p>
                                </div>
                            <?php else: ?>
                                <div>
                                    <?php foreach ($assignments as $assignment): ?>
                                        <div class="course-item-card">
                                            <div>
                                                <a href="../assignments/view.php?id=<?php echo $assignment['id']; ?>" class="text-decoration-none">
                                                    <h6 class="course-item-title"><?php echo htmlspecialchars($assignment['title']); ?></h6>
                                                </a>
                                                <div class="course-item-meta">
                                                    <?php if (!empty($assignment['due_date'])): ?>
                                                        Due: <?php echo date('M d, Y', strtotime($assignment['due_date'])); ?>
                                                    <?php else: ?>
                                                        No Due Date
                                                    <?php endif; ?>
                                                    • <?php echo (int)($assignment['points'] ?? 100); ?> pts
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="course-item-badge-box">
                                                    <div class="course-item-number-pill"><?php echo (int)($assignment['submission_count'] ?? 0); ?></div>
                                                    <div class="course-item-badge-label">Submitted</div>
                                                </div>
                                                <a href="../assignments/view.php?id=<?php echo $assignment['id']; ?>" class="course-item-btn-view">
                                                    <i class="far fa-eye"></i> View
                                                </a>
                                                <?php if (($role === 'instructor' && $subject['instructor_id'] == $user_id) || $role === 'admin'): ?>
                                                    <a href="../assignments/edit.php?id=<?php echo $assignment['id']; ?>" class="btn btn-sm btn-outline-secondary rounded-3 px-2 py-1 ms-1" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Quizzes Tab -->
                        <div class="course-tab-pane" id="tab-pane-quizzes" style="display: none;">
                            <?php if (($role === 'instructor' && $subject['instructor_id'] == $user_id) || $role === 'admin'): ?>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold text-secondary mb-0"><i class="fas fa-question-circle me-1"></i> Subject Quizzes</h6>
                                    <a href="../quizzes/create.php?subject_id=<?php echo $subject_id; ?>" class="btn btn-sm btn-purple text-white fw-bold rounded-pill px-3 shadow-sm" style="background: #8b5cf6;">
                                        <i class="fas fa-plus me-1"></i> Create Quiz
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php
                            if ($role === 'student') {
                                $stmt = $pdo->prepare("
                                    SELECT DISTINCT q.*,
                                        (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count
                                    FROM quizzes q
                                    LEFT JOIN quiz_assignments qa ON q.id = qa.quiz_id
                                    LEFT JOIN student_profiles sp ON sp.student_id = ?
                                    WHERE q.subject_id = ?
                                      AND (q.status = 'published' OR q.status IS NULL)
                                      AND (
                                          qa.id IS NULL
                                          OR qa.assignment_type = 'subject'
                                          OR (qa.assignment_type = 'section' AND qa.target_id = sp.section_id)
                                          OR (qa.assignment_type = 'program' AND qa.target_id = sp.program_id)
                                          OR (qa.assignment_type = 'year_level' AND qa.year_level = sp.year_level)
                                          OR (qa.assignment_type = 'student' AND qa.target_id = ?)
                                      )
                                    ORDER BY q.created_at DESC
                                ");
                                $stmt->execute([$user_id, $subject_id, $user_id]);
                                $quizzes = $stmt->fetchAll();
                            } else {
                                $stmt = $pdo->prepare("SELECT q.*, 
                                    (SELECT COUNT(*) FROM quiz_results WHERE quiz_id = q.id) as submission_count,
                                    (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count
                                FROM quizzes q 
                                WHERE q.subject_id = ? 
                                ORDER BY q.created_at DESC");
                                $stmt->execute([$subject_id]);
                                $quizzes = $stmt->fetchAll();
                            }
                            ?>

                            <?php if (empty($quizzes)): ?>
                                <div class="p-4 text-center text-muted bg-light rounded-3 border">
                                    <i class="fas fa-vial fa-2x mb-2 text-info opacity-50"></i>
                                    <p class="mb-0">No quizzes available for this subject yet.</p>
                                </div>
                            <?php else: ?>
                                <div>
                                    <?php foreach ($quizzes as $quiz): ?>
                                        <div class="course-item-card">
                                            <div>
                                                <a href="../quizzes/view.php?id=<?php echo $quiz['id']; ?>" class="text-decoration-none">
                                                    <h6 class="course-item-title"><?php echo htmlspecialchars($quiz['title']); ?></h6>
                                                </a>
                                                <div class="course-item-meta">
                                                    <?php echo (int)($quiz['question_count'] ?? 0); ?> questions
                                                    <?php if (!empty($quiz['time_limit'])): ?>
                                                        • <?php echo (int)$quiz['time_limit']; ?> mins
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="course-item-badge-box">
                                                    <div class="course-item-number-pill"><?php echo (int)($quiz['submission_count'] ?? 0); ?></div>
                                                    <div class="course-item-badge-label">Submitted</div>
                                                </div>
                                                <a href="../quizzes/view.php?id=<?php echo $quiz['id']; ?>" class="course-item-btn-view">
                                                    <i class="far fa-eye"></i> View
                                                </a>
                                                <?php if (($role === 'instructor' && $subject['instructor_id'] == $user_id) || $role === 'admin'): ?>
                                                    <a href="../quizzes/add_questions.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-secondary rounded-3 px-2 py-1 ms-1" title="Questions">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Lessons Tab -->
                        <div class="course-tab-pane" id="tab-pane-lessons" style="display: none;">
                            <?php if (($role === 'instructor' && $subject['instructor_id'] == $user_id) || $role === 'admin'): ?>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold text-secondary mb-0"><i class="fas fa-list me-1"></i> Course Lessons</h6>
                                    <a href="../lessons/create.php?subject_id=<?php echo $subject_id; ?>" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm">
                                        <i class="fas fa-plus me-1"></i> Add Lesson
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php
                            try {
                                $stmt = $pdo->prepare("SELECT l.*, (SELECT COUNT(*) FROM lesson_attachments WHERE lesson_id = l.id) as attachment_count FROM lessons l WHERE l.subject_id = ? ORDER BY l.topic ASC, l.created_at ASC");
                                $stmt->execute([$subject_id]);
                                $lessons = $stmt->fetchAll();
                            } catch (PDOException $e) {
                                $stmt = $pdo->prepare("SELECT l.*, 0 as attachment_count FROM lessons l WHERE l.subject_id = ? ORDER BY l.created_at ASC");
                                $stmt->execute([$subject_id]);
                                $lessons = $stmt->fetchAll();
                            }
                            ?>

                            <?php if (empty($lessons)): ?>
                                <div class="p-4 text-center text-muted bg-light rounded-3 border">
                                    <i class="fas fa-book-reader fa-2x mb-2 text-primary opacity-50"></i>
                                    <p class="mb-0">No lessons available yet for this subject.</p>
                                </div>
                            <?php else: ?>
                                <div>
                                    <?php foreach ($lessons as $lesson): ?>
                                        <div class="course-item-card">
                                            <div>
                                                <a href="../lessons/view.php?id=<?php echo $lesson['id']; ?>" class="text-decoration-none">
                                                    <h6 class="course-item-title"><?php echo htmlspecialchars($lesson['title']); ?></h6>
                                                </a>
                                                <div class="course-item-meta">
                                                    Posted: <?php echo date('M d, Y', strtotime($lesson['created_at'])); ?>
                                                    <?php if (!empty($lesson['topic'])): ?>
                                                        • Topic: <?php echo htmlspecialchars($lesson['topic']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="course-item-badge-box">
                                                    <div class="course-item-number-pill"><?php echo (int)($lesson['attachment_count'] ?? 0); ?></div>
                                                    <div class="course-item-badge-label">Files</div>
                                                </div>
                                                <a href="../lessons/view.php?id=<?php echo $lesson['id']; ?>" class="course-item-btn-view">
                                                    <i class="far fa-eye"></i> View
                                                </a>
                                                <?php if (($role === 'instructor' && $subject['instructor_id'] == $user_id) || $role === 'admin'): ?>
                                                    <a href="../lessons/edit.php?id=<?php echo $lesson['id']; ?>" class="btn btn-sm btn-outline-secondary rounded-3 px-2 py-1 ms-1" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Attendance Tab -->
                        <div class="course-tab-pane" id="tab-pane-attendance" style="display: none;">
                            <?php if (($role === 'instructor' && $subject['instructor_id'] == $user_id) || $role === 'admin'): ?>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold text-secondary mb-0"><i class="fas fa-calendar-check me-1"></i> Class Attendance Sessions</h6>
                                    <a href="../attendance/index.php?subject=<?php echo $subject_id; ?>" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm">
                                        <i class="fas fa-plus me-1"></i> Record Attendance
                                    </a>
                                </div>
                                <?php
                                $attStmt = $pdo->prepare("
                                    SELECT attendance_date,
                                        COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
                                        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
                                        COUNT(CASE WHEN status = 'late' THEN 1 END) as late_count,
                                        COUNT(*) as total_records
                                    FROM attendance
                                    WHERE subject_id = ?
                                    GROUP BY attendance_date
                                    ORDER BY attendance_date DESC
                                ");
                                $attStmt->execute([$subject_id]);
                                $attRecords = $attStmt->fetchAll();
                                ?>
                                <?php if (empty($attRecords)): ?>
                                    <div class="p-4 text-center text-muted bg-light rounded-3 border">
                                        <i class="fas fa-calendar-times fa-2x mb-2 text-success opacity-50"></i>
                                        <p class="mb-0">No attendance recorded for this class yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <?php foreach ($attRecords as $att): ?>
                                            <div class="course-item-card">
                                                <div>
                                                    <h6 class="course-item-title">Session: <?php echo date('M d, Y', strtotime($att['attendance_date'])); ?></h6>
                                                    <div class="course-item-meta">
                                                        Present: <?php echo (int)$att['present_count']; ?> • Absent: <?php echo (int)$att['absent_count']; ?> • Late: <?php echo (int)$att['late_count']; ?>
                                                    </div>
                                                </div>
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="course-item-badge-box">
                                                        <div class="course-item-number-pill" style="background: #d1fae5; color: #065f46;"><?php echo (int)$att['present_count']; ?></div>
                                                        <div class="course-item-badge-label">Present</div>
                                                    </div>
                                                    <a href="../attendance/index.php?subject=<?php echo $subject_id; ?>&date=<?php echo $att['attendance_date']; ?>" class="course-item-btn-view">
                                                        <i class="far fa-eye"></i> View
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Student View for Attendance -->
                                <?php
                                $studentAttStmt = $pdo->prepare("
                                    SELECT * FROM attendance
                                    WHERE subject_id = ? AND student_id = ?
                                    ORDER BY attendance_date DESC
                                ");
                                $studentAttStmt->execute([$subject_id, $user_id]);
                                $studentAttRecords = $studentAttStmt->fetchAll();
                                ?>
                                <?php if (empty($studentAttRecords)): ?>
                                    <div class="p-4 text-center text-muted bg-light rounded-3 border">
                                        <i class="fas fa-calendar-minus fa-2x mb-2 text-muted opacity-50"></i>
                                        <p class="mb-0">No attendance recorded for you in this class yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <?php foreach ($studentAttRecords as $sAtt): ?>
                                            <div class="course-item-card">
                                                <div>
                                                    <h6 class="course-item-title">Date: <?php echo date('M d, Y', strtotime($sAtt['attendance_date'])); ?></h6>
                                                    <div class="course-item-meta">
                                                        Status: <span class="badge <?php echo $sAtt['status'] === 'present' ? 'bg-success' : ($sAtt['status'] === 'late' ? 'bg-warning text-dark' : 'bg-danger'); ?>"><?php echo ucfirst(htmlspecialchars($sAtt['status'])); ?></span>
                                                    </div>
                                                </div>
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="course-item-badge-box">
                                                        <div class="course-item-number-pill" style="background: <?php echo $sAtt['status'] === 'present' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $sAtt['status'] === 'present' ? '#065f46' : '#991b1b'; ?>;">
                                                            <i class="fas <?php echo $sAtt['status'] === 'present' ? 'fa-check' : 'fa-times'; ?>"></i>
                                                        </div>
                                                        <div class="course-item-badge-label"><?php echo ucfirst(htmlspecialchars($sAtt['status'])); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enrolled Students Card Sidebar -->
        <div class="col-lg-4">
            <div class="card shadow border-0 rounded-3 overflow-hidden sticky-top" style="top: 80px;">
                <div class="card-header card-header-custom d-flex align-items-center justify-content-between">
                    <h5 class="card-title mb-0"><i class="fas fa-users me-2"></i>Enrolled Students</h5>
                    <span class="badge bg-white text-primary rounded-pill px-2 py-1 fw-bold"><?php echo count($enrolledStudents); ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($enrolledStudents)): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="fas fa-user-slash fa-2x mb-2 text-secondary opacity-50"></i>
                            <p class="mb-0">No students enrolled in this class yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                                <thead class="table-light sticky-top" style="z-index: 2;">
                                    <tr>
                                        <th style="white-space: nowrap;">Student</th>
                                        <th style="white-space: nowrap;">Email</th>
                                        <th style="white-space: nowrap;">Enrolled</th>
                                        <?php if (in_array($role, ['instructor', 'admin', 'dean'])): ?>
                                            <th class="text-end" style="white-space: nowrap;">Action</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrolledStudents as $student): 
                                        $perfUrl = ($role === 'instructor') 
                                            ? "../instructor/analytics.php?subject_id={$subject_id}" 
                                            : "../admin/user_performance.php?user_id={$student['id']}";
                                    ?>
                                        <tr>
                                            <td style="white-space: nowrap;">
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="rounded-circle bg-primary-subtle text-primary fw-bold d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px; font-size: 0.8rem; background: #e0e7ff; color: #3730a3;">
                                                        <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                                                    </div>
                                                    <a href="<?php echo $perfUrl; ?>" class="text-decoration-none fw-bold text-dark hover-primary" style="white-space: nowrap;">
                                                        <?php echo htmlspecialchars($student['name']); ?>
                                                    </a>
                                                </div>
                                            </td>
                                            <td class="text-muted small" style="max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($student['email']); ?>">
                                                <?php echo htmlspecialchars($student['email']); ?>
                                            </td>
                                            <td class="text-muted small" style="white-space: nowrap;">
                                                <?php echo date('M d, Y', strtotime($student['enrolled_at'])); ?>
                                            </td>
                                            <?php if (in_array($role, ['instructor', 'admin', 'dean'])): ?>
                                                <td class="text-end" style="white-space: nowrap;">
                                                    <a href="<?php echo $perfUrl; ?>" class="btn btn-xs btn-outline-primary rounded-pill py-0 px-2" title="View Analytics">
                                                        <i class="fas fa-chart-line"></i>
                                                    </a>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function showCourseTab(tabName, btn) {
    document.querySelectorAll('.course-tab-pane').forEach(function(pane) {
        pane.style.display = 'none';
    });
    document.querySelectorAll('.tab-nav .tab-btn').forEach(function(b) {
        b.classList.remove('active');
    });
    var target = document.getElementById('tab-pane-' + tabName);
    if (target) {
        target.style.display = 'block';
    }
    if (btn) {
        btn.classList.add('active');
    }
}

function copyClassCode(code, btn) {
    navigator.clipboard.writeText(code).then(() => {
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check text-success me-1"></i>Copied!';
        btn.classList.replace('btn-outline-secondary', 'btn-outline-success');
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.classList.replace('btn-outline-success', 'btn-outline-secondary');
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy: ', err);
    });
}
</script>

<?php include '../includes/footer.php'; ?>
