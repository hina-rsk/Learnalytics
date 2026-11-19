<?php
session_start();
require_once '../config/database.php';
require_once '../includes/enrollment_requests.php';

$section_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['user_role'] ?? 'guest';
if ($user_id && $role === 'student') {
    ensureEnrollmentRequestsTable($pdo);
    if (empty($_SESSION['section_enrollment_csrf'])) {
        $_SESSION['section_enrollment_csrf'] = bin2hex(random_bytes(32));
    }
}

// Fetch section details
$stmt = $pdo->prepare("
    SELECT s.*, p.name as program_name, p.description as program_description, ay.name as academic_year_name
    FROM sections s
    JOIN programs p ON s.program_id = p.id
    JOIN academic_years ay ON s.academic_year_id = ay.id
    WHERE s.id = ?
");
$stmt->execute([$section_id]);
$section = $stmt->fetch();

if (!$section) {
    die("Section not found.");
}

// Handle enrollment - enroll in all subjects of this section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll']) && $user_id && $role === 'student') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfToken) || !hash_equals($_SESSION['section_enrollment_csrf'], $csrfToken)) {
        $error = 'Your session has expired. Refresh the page and try again.';
    } else {
      try {
        // Get all subjects in this section
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE section_id = ? AND status = 'active'");
        $stmt->execute([$section_id]);
        $sectionSubjects = $stmt->fetchAll();
        
        if (empty($sectionSubjects)) {
            $error = 'No subjects available in this section to enroll in.';
        } else {
            $requestedCount = 0;
            $pendingCount = 0;
            $enrolledCount = 0;
            foreach ($sectionSubjects as $sub) {
                $result = requestEnrollment($pdo, (int)$user_id, (int)$sub['id']);
                if ($result === 'requested') {
                    $requestedCount++;
                } elseif ($result === 'pending') {
                    $pendingCount++;
                } elseif ($result === 'already_enrolled') {
                    $enrolledCount++;
                }
            }
            
            if ($requestedCount > 0) {
                $message = "Join requests sent to instructors for $requestedCount subject(s). Approval is required before you can access them.";
            } elseif ($pendingCount > 0) {
                $message = "Your requests are already awaiting instructor approval.";
            } else {
                $message = 'You are already enrolled in all subjects in this section.';
            }
        }
    } catch (PDOException $e) {
        error_log('Section enrollment requests failed: ' . $e->getMessage());
        $error = 'Could not send enrollment requests. Please try again.';
      }
    }
}

// Handle unenroll - unenroll from all subjects in this section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unenroll']) && $user_id && $role === 'student') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfToken) || !hash_equals($_SESSION['section_enrollment_csrf'], $csrfToken)) {
        $error = 'Your session has expired. Refresh the page and try again.';
    } else {
      try {
        // Get all subjects in this section
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE section_id = ?");
        $stmt->execute([$section_id]);
        $sectionSubjects = $stmt->fetchAll();
        
        foreach ($sectionSubjects as $sub) {
            $deleteStmt = $pdo->prepare("DELETE FROM enrollments WHERE student_id = ? AND subject_id = ?");
            $deleteStmt->execute([$user_id, $sub['id']]);
        }
        $message = 'Successfully unenrolled from all subjects in this section.';
    } catch (PDOException $e) {
        error_log('Section unenrollment failed: ' . $e->getMessage());
        $error = 'Could not unenroll from the section. Please try again.';
      }
    }
}

// Check enrollment status - check if enrolled in any subject in this section
$is_enrolled = false;
$has_pending_request = false;
if ($user_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM enrollments e
        JOIN subjects s ON e.subject_id = s.id
        WHERE e.student_id = ? AND s.section_id = ?
    ");
    $stmt->execute([$user_id, $section_id]);
    $is_enrolled = (int)$stmt->fetchColumn() > 0;
    if ($role === 'student') {
        $requestStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM enrollment_requests er
            JOIN subjects s ON s.id = er.subject_id
            WHERE er.student_id = ? AND s.section_id = ? AND er.status = 'pending'
        ");
        $requestStmt->execute([$user_id, $section_id]);
        $has_pending_request = (int)$requestStmt->fetchColumn() > 0;
    }
}

// Fetch subjects associated with this section
$stmt = $pdo->prepare("
    SELECT sub.id, sub.title, sub.description, sub.status, sub.class_code, u.name as instructor_name,
           (SELECT COUNT(*) FROM lessons WHERE subject_id = sub.id) as lesson_count,
           (SELECT COUNT(*) FROM assignments WHERE subject_id = sub.id) as assignment_count,
           (SELECT COUNT(*) FROM quizzes WHERE subject_id = sub.id) as quiz_count,
           (SELECT COUNT(*) FROM enrollments WHERE subject_id = sub.id) as student_count
    FROM subjects sub
    LEFT JOIN users u ON sub.instructor_id = u.id
    WHERE sub.section_id = ?
    ORDER BY sub.title ASC
");
$stmt->execute([$section_id]);
$sectionSubjects = $stmt->fetchAll();

// Check if user is enrolled in any of these subjects
$enrolledSubjectIds = [];
if ($user_id && $role === 'student') {
    $stmt = $pdo->prepare("SELECT subject_id FROM enrollments WHERE student_id = ?");
    $stmt->execute([$user_id]);
    while ($row = $stmt->fetch()) {
        $enrolledSubjectIds[] = $row['subject_id'];
    }
}

// Fetch enrolled students (admin/instructor only) - students in any subject of this section
$enrolledStudents = [];
if (in_array($role, ['admin', 'instructor'])) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name, u.email, MAX(e.enrolled_at) as enrolled_at
        FROM enrollments e
        JOIN users u ON e.student_id = u.id
        JOIN subjects s ON e.subject_id = s.id
        WHERE s.section_id = ?
        GROUP BY u.id
        ORDER BY enrolled_at DESC
    ");
    $stmt->execute([$section_id]);
    $enrolledStudents = $stmt->fetchAll();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="../programs/view.php" style="color: #2563eb;">Programs</a></li>
            <li class="breadcrumb-item active" style="color: #1e3a8a;"><?= htmlspecialchars($section['name']) ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <div>
            <h1 class="h2" style="color: #1e3a8a;"><?= htmlspecialchars($section['name']) ?></h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($section['program_name']) ?> · <?= htmlspecialchars($section['academic_year_name']) ?></p>
        </div>
    </div>

    <?php if (isset($message)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Program Info -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-0">
                    <h5 class="mb-0" style="color: #1e3a8a;">Program Information</h5>
                </div>
                <div class="card-body">
                    <h4><?= htmlspecialchars($section['program_name']) ?></h4>
                    <?php if ($section['program_description']): ?>
                        <p class="text-muted"><?= htmlspecialchars($section['program_description']) ?></p>
                    <?php endif; ?>
                    
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <p><strong><i class="fas fa-calendar me-2"></i>Academic Year:</strong> <?= htmlspecialchars($section['academic_year_name']) ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong><i class="fas fa-users me-2"></i>Enrolled Students:</strong> <?= count($enrolledStudents) ?></p>
                            <p><strong><i class="fas fa-book me-2"></i>Subjects:</strong> <?= count($sectionSubjects) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Subjects in this Section -->
            <div class="card shadow-sm border-0 mt-4">
                <div class="card-header bg-white border-0" style="border-left: 4px solid #2563eb;">
                    <h5 class="mb-0" style="color: #1e3a8a;"><i class="fas fa-book me-2"></i>Subjects in this Section</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($sectionSubjects)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No subjects added to this section yet.</p>
                            <?php if (in_array($role, ['admin', 'instructor'])): ?>
                                <a href="../subjects/create.php?section_id=<?= $section_id ?>" class="btn" style="background: #2563eb; color: white; border: none;">
                                    <i class="fas fa-plus me-1"></i> Add Subject
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($sectionSubjects as $subject): ?>
                                <div class="col-md-6">
                                    <div class="card h-100 border" style="border-left: 3px solid <?= $subject['status'] === 'active' ? '#10b981' : '#fbbf24' ?> !important;">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <span class="badge" style="background: <?= $subject['status'] === 'active' ? '#10b981' : '#fbbf24' ?>; color: white;">
                                                    <?= ucfirst($subject['status']) ?>
                                                </span>
                                                <?php if (!empty($subject['class_code'])): ?>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($subject['class_code']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <h6 class="card-title" style="color: #1e3a8a;"><?= htmlspecialchars($subject['title']) ?></h6>
                                            <p class="card-text small text-muted"><?= htmlspecialchars(substr($subject['description'] ?? '', 0, 80)) ?>...</p>
                                            
                                            <div class="row text-center mt-3">
                                                <div class="col-4">
                                                    <div class="text-center">
                                                        <div class="fw-bold" style="color: #2563eb;"><?= $subject['lesson_count'] ?></div>
                                                        <small class="text-muted">Lessons</small>
                                                    </div>
                                                </div>
                                                <div class="col-4">
                                                    <div class="text-center">
                                                        <div class="fw-bold" style="color: #2563eb;"><?= $subject['assignment_count'] ?></div>
                                                        <small class="text-muted">Assignments</small>
                                                    </div>
                                                </div>
                                                <div class="col-4">
                                                    <div class="text-center">
                                                        <div class="fw-bold" style="color: #2563eb;"><?= $subject['student_count'] ?></div>
                                                        <small class="text-muted">Students</small>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mt-3">
                                                <small class="text-muted"><i class="fas fa-user-tie me-1"></i> <?= htmlspecialchars($subject['instructor_name'] ?? 'No instructor') ?></small>
                                            </div>
                                        </div>
                                        <div class="card-footer bg-white border-0">
                                            <a href="../subjects/view.php?id=<?= $subject['id'] ?>" class="btn btn-sm w-100" style="background: #2563eb; color: white; border: none;">
                                                <i class="fas fa-eye me-1"></i> View Subject
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Enrollment Action -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-0">
                    <h5 class="mb-0" style="color: #1e3a8a;">Enrollment</h5>
                </div>
                <div class="card-body text-center">
                    <?php if ($is_enrolled): ?>
                        <div class="alert alert-success mb-0">
                            <i class="fas fa-check-circle fa-2x mb-3"></i>
                            <h5>You're Enrolled!</h5>
                            <p class="mb-0">You are a student in this section.</p>
                        </div>
                        <form method="POST" class="mt-3">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['section_enrollment_csrf']); ?>">
                            <button type="submit" name="unenroll" class="btn btn-outline-danger w-100">
                                <i class="fas fa-sign-out-alt me-1"></i> Unenroll
                            </button>
                        </form>
                    <?php elseif ($user_id && $role === 'student' && $has_pending_request): ?>
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-clock fa-2x mb-3"></i>
                            <h5>Approval Pending</h5>
                            <p class="mb-0">Your class requests are awaiting approval from the instructors.</p>
                        </div>
                    <?php elseif ($user_id && $role === 'student'): ?>
                        <p class="text-muted">Request access to the active classes in this section. Each instructor must approve your request.</p>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['section_enrollment_csrf']); ?>">
                            <button type="submit" name="enroll" class="btn w-100" style="background: #2563eb; color: white; border: none;">
                                <i class="fas fa-user-plus me-1"></i> Request to Join
                            </button>
                        </form>
                    <?php elseif (!$user_id): ?>
                        <p class="text-muted">Please login to enroll in this section.</p>
                        <a href="../auth/login.php" class="btn w-100" style="background: #2563eb; color: white; border: none;">
                            <i class="fas fa-sign-in-alt me-1"></i> Login
                        </a>
                    <?php else: ?>
                        <p class="text-muted">Enrollment is not available for your role.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (in_array($role, ['admin', 'instructor'])): ?>
                <div class="card shadow-sm border-0 mt-4">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0" style="color: #1e3a8a;"><i class="fas fa-users me-2"></i>Enrolled Students</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($enrolledStudents)): ?>
                            <div class="p-4 text-center text-muted">No students enrolled yet.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th>Student</th>
                                            <th>Email</th>
                                            <th>Enrolled Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($enrolledStudents as $student): ?>
                                            <tr>
                                                <td>
                                                    <a href="../admin/user_performance.php?id=<?= $student['id'] ?>" class="text-decoration-none" style="color: #2563eb;">
                                                        <i class="fas fa-user me-1"></i> <?= htmlspecialchars($student['name']) ?>
                                                    </a>
                                                </td>
                                                <td><?= htmlspecialchars($student['email']) ?></td>
                                                <td><?= date('M d, Y', strtotime($student['enrolled_at'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>