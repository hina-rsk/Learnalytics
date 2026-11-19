<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$assignment_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Fetch Assignment details
$stmt = $pdo->prepare("SELECT a.*, c.title as subject_title, c.instructor_id, u.name as instructor_name FROM assignments a JOIN subjects c ON a.subject_id = c.id JOIN users u ON c.instructor_id = u.id WHERE a.id = ?");
$stmt->execute([$assignment_id]);
$assignment = $stmt->fetch();

if (!$assignment) {
    die("Assignment not found.");
}

if ($role === 'student') {
    $enrollmentCheck = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND subject_id = ?");
    $enrollmentCheck->execute([$user_id, $assignment['subject_id']]);
    if (!$enrollmentCheck->fetch()) {
        http_response_code(403);
        exit('You are not enrolled in this class.');
    }
}

$success_msg = '';
$error_msg = '';
$success_msg = '';
$error_msg = '';
$grade_success_msg = '';
$my_submission = null;

// Fetch Student Override if applicable
$student_override = null;
if ($role === 'student') {
    $stmt = $pdo->prepare("SELECT * FROM submission_overrides WHERE assignment_id = ? AND student_id = ?");
    $stmt->execute([$assignment_id, $user_id]);
    $student_override = $stmt->fetch();
}

$effective_due_date = (!empty($student_override['new_due_date'])) ? $student_override['new_due_date'] : ($assignment['due_date'] ?? null);
$effective_max_attempts = 1;

// Fetch submission history for student
$submission_history = [];
if ($role === 'student') {
    $stmt = $pdo->prepare(" 
        SELECT sub.*, f.status AS review_status, f.score AS review_score,
               f.maximum_score AS review_maximum_score, f.allow_resubmission
        FROM submissions sub
        LEFT JOIN assignment_feedback f ON f.submission_id = sub.id
        WHERE sub.assignment_id = ? AND sub.student_id = ?
        ORDER BY sub.attempt_number ASC
    ");
    $stmt->execute([$assignment_id, $user_id]);
    $submission_history = $stmt->fetchAll();
    $my_submission = !empty($submission_history) ? end($submission_history) : null;
    if ($my_submission && $my_submission['review_status'] === 'returned_for_revision' && !empty($my_submission['allow_resubmission'])) {
        $effective_max_attempts = 2;
    }
}

$current_attempt_count = count($submission_history);
$now = new DateTime();
$dueDateObj = $effective_due_date ? new DateTime($effective_due_date) : null;
$graceHours = (int)($assignment['late_grace_period_hours'] ?? 0);
$deadlineWithGrace = $dueDateObj ? (clone $dueDateObj)->modify("+{$graceHours} hours") : null;

$isPastDue = $dueDateObj ? ($now > $dueDateObj) : false;
$isPastGrace = $deadlineWithGrace ? ($now > $deadlineWithGrace) : false;

// Compute Status Label & Badge
if (!$dueDateObj) {
    $status_label = 'Open';
    $status_badge = '<span class="badge bg-success px-3 py-2"><i class="fas fa-unlock me-1"></i> Open</span>';
} elseif (!$isPastDue) {
    if (($dueDateObj->getTimestamp() - $now->getTimestamp()) <= 86400) {
        $status_label = 'Deadline Approaching';
        $status_badge = '<span class="badge bg-warning text-dark px-3 py-2"><i class="fas fa-clock me-1"></i> Deadline Approaching</span>';
    } else {
        $status_label = 'Open';
        $status_badge = '<span class="badge bg-success px-3 py-2"><i class="fas fa-check-circle me-1"></i> Open</span>';
    }
} elseif (!$isPastGrace || !empty($assignment['allow_late_submission']) || $student_override) {
    $status_label = 'Late Submission Allowed';
    $status_badge = '<span class="badge bg-info text-dark px-3 py-2"><i class="fas fa-exclamation-triangle me-1"></i> Late Submission Allowed</span>';
} else {
    $status_label = 'Closed';
    $status_badge = '<span class="badge bg-danger px-3 py-2"><i class="fas fa-lock me-1"></i> Closed</span>';
}

// Backend Submission Eligibility Check
$can_submit = true;
$submission_blocked_reason = '';

if ($role === 'student') {
    if ($current_attempt_count >= $effective_max_attempts) {
        $can_submit = false;
        $submission_blocked_reason = 'This assignment can only be submitted once.';
    } elseif ($isPastDue && !$isPastGrace && empty($assignment['allow_late_submission']) && !$student_override) {
        $can_submit = false;
        $submission_blocked_reason = 'The submission deadline has passed. This assignment is now closed.';
    }
}

// Student Submit / Resubmit POST Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assignment']) && $role === 'student') {
    if (!$can_submit) {
        $error_msg = $submission_blocked_reason;
    } else {
        $content = trim($_POST['content'] ?? '');
        $file_path = null;

        if (!empty($_FILES['submission_file']['name'])) {
            $allowed_extensions = ['pdf', 'docx', 'pptx', 'jpg', 'jpeg', 'png'];
            $upload_dir = '../uploads/submissions/';
            $file_name = $_FILES['submission_file']['name'];
            $file_tmp = $_FILES['submission_file']['tmp_name'];
            $file_error = $_FILES['submission_file']['error'];
            $file_size = $_FILES['submission_file']['size'];

            if ($file_error === UPLOAD_ERR_OK) {
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                if (!in_array($file_ext, $allowed_extensions)) {
                    $error_msg = 'Invalid submission file type. Allowed: PDF, DOCX, PPTX, JPG, JPEG, PNG.';
                } elseif ($file_size > 20 * 1024 * 1024) {
                    $error_msg = 'Submission file must be under 20MB.';
                } else {
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $unique_name = time() . '_' . uniqid() . '.' . $file_ext;
                    $upload_path = $upload_dir . $unique_name;
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        $file_path = 'uploads/submissions/' . $unique_name;
                    } else {
                        $error_msg = 'Failed to upload submission file.';
                    }
                }
            } elseif ($file_error !== UPLOAD_ERR_NO_FILE) {
                $error_msg = 'File upload error. Please try again.';
            }
        }

        if (empty($content) && empty($file_path)) {
            $error_msg = 'Please provide submission text or attach a file.';
        }

        if (!$error_msg) {
            $next_attempt = $current_attempt_count + 1;

            // Mark previous attempts is_latest = 0
            $stmt = $pdo->prepare("UPDATE submissions SET is_latest = 0 WHERE assignment_id = ? AND student_id = ?");
            $stmt->execute([$assignment_id, $user_id]);

            // Insert single allowed attempt
            $stmt = $pdo->prepare("INSERT INTO submissions (assignment_id, student_id, content, file_path, attempt_number, is_latest, submitted_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
            $stmt->execute([$assignment_id, $user_id, $content, $file_path, $next_attempt]);
            $success_msg = 'Assignment submitted successfully!';

            // Refresh student history
            $stmt = $pdo->prepare(" 
                SELECT sub.*, f.status AS review_status, f.score AS review_score,
                       f.maximum_score AS review_maximum_score, f.allow_resubmission
                FROM submissions sub
                LEFT JOIN assignment_feedback f ON f.submission_id = sub.id
                WHERE sub.assignment_id = ? AND sub.student_id = ?
                ORDER BY sub.attempt_number ASC
            ");
            $stmt->execute([$assignment_id, $user_id]);
            $submission_history = $stmt->fetchAll();
            $my_submission = end($submission_history);
            $current_attempt_count = count($submission_history);
        }
    }
}

// Instructor Grade POST Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_submission']) && $role === 'instructor') {
    $submission_id = intval($_POST['submission_id'] ?? 0);
    $grade = trim($_POST['grade'] ?? '');
    $feedback = trim($_POST['feedback'] ?? '');
    if (!$submission_id || !is_numeric($grade) || $grade < 0 || $grade > 100) {
        $error_msg = 'Please enter a valid grade between 0 and 100 before saving.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT s.id FROM submissions s
            JOIN assignments a ON s.assignment_id = a.id
            JOIN subjects c ON a.subject_id = c.id
            WHERE s.id = ? AND a.id = ? AND c.instructor_id = ?"
        );
        $stmt->execute([$submission_id, $assignment_id, $user_id]);
        $valid = $stmt->fetch();

        if ($valid) {
            $stmt = $pdo->prepare("UPDATE submissions SET grade = ?, feedback = ? WHERE id = ?");
            $stmt->execute([$grade, $feedback !== '' ? $feedback : null, $submission_id]);
            $grade_success_msg = "Grade saved successfully.";
        } else {
            $error_msg = 'You are not authorized to grade this submission.';
        }
    }
}

// Instructor Teacher Override POST Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student_override']) && ($role === 'instructor' || $role === 'admin')) {
    $target_student_id = intval($_POST['target_student_id'] ?? 0);
    $override_attempts = intval($_POST['override_attempts'] ?? 3);
    $override_due_date = !empty($_POST['override_due_date']) ? $_POST['override_due_date'] : null;
    $override_notes    = trim($_POST['override_notes'] ?? '');

    if ($target_student_id > 0) {
        $stmt = $pdo->prepare("SELECT id FROM submission_overrides WHERE assignment_id = ? AND student_id = ?");
        $stmt->execute([$assignment_id, $target_student_id]);
        $existingOverride = $stmt->fetch();

        if ($existingOverride) {
            $stmt = $pdo->prepare("UPDATE submission_overrides SET allowed_attempts = ?, new_due_date = ?, notes = ? WHERE id = ?");
            $stmt->execute([$override_attempts, $override_due_date, $override_notes, $existingOverride['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO submission_overrides (assignment_id, student_id, allowed_attempts, new_due_date, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$assignment_id, $target_student_id, $override_attempts, $override_due_date, $override_notes]);
        }
        $grade_success_msg = "Student resubmission override saved successfully!";
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="../subjects/index.php" style="color: #2563eb;">Subjects</a></li>
            <li class="breadcrumb-item"><a href="../subjects/view.php?id=<?php echo $assignment['subject_id']; ?>" style="color: #2563eb;"><?php echo htmlspecialchars($assignment['subject_title']); ?></a></li>
            <li class="breadcrumb-item active" style="color: #1e3a8a;"><?php echo htmlspecialchars($assignment['title']); ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <div>
            <h1 class="h2" style="color: #1e3a8a; margin: 0;"><?php echo htmlspecialchars($assignment['title']); ?></h1>
            <div class="mt-2 d-flex align-items-center gap-2 flex-wrap">
                <?php echo $status_badge; ?>
                <span class="badge bg-secondary px-3 py-2"><i class="fas fa-calendar-alt me-1"></i> Due: <?php echo date('M d, Y H:i', strtotime($effective_due_date)); ?></span>
            </div>
        </div>
        <?php if ($role === 'instructor' || $role === 'admin'): ?>
            <div>
                <button type="button" class="btn btn-sm btn-primary fw-semibold rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#teacherOverrideModal">
                    <i class="fas fa-user-clock me-1"></i> Student Resubmission Override
                </button>
            </div>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0">Assignment Description</h5>
                </div>
                <div class="card-body">
                    <div class="card-text"><?php echo nl2br(htmlspecialchars($assignment['description'])); ?></div>
                    <?php if (!empty($assignment['file_path'] ?? '')):
                        $filePath = htmlspecialchars($assignment['file_path']);
                        $fileName = basename($assignment['file_path']);
                        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    ?>
                        <div class="mt-3 p-3 bg-light rounded border d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-paperclip fa-lg text-primary me-1"></i>
                                <div>
                                    <strong style="color: #1e3a8a;" class="d-block mb-0"><?php echo htmlspecialchars($fileName); ?></strong>
                                    <small class="text-muted">Attached Course Resource</small>
                                </div>
                            </div>
                            <div class="btn-group">
                                <button type="button" onclick="openLmsFileViewer('../<?php echo $filePath; ?>', '<?php echo addslashes($fileName); ?>', '<?php echo $fileExt; ?>'); return false;" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye me-1"></i> View
                                </button>
                                <a href="../<?php echo $filePath; ?>" download class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-download me-1"></i> Download
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($role === 'student'): ?>
                <div class="card shadow border-0 mb-4">
                    <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Submit Your Work</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($success_msg): ?>
                            <div class="alert alert-success" style="background-color: #d1fae5; color: #065f46; border-color: #6ee7b7;">
                                <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($error_msg): ?>
                            <div class="alert alert-danger" style="background-color: #fee2e2; color: #991b1b; border-color: #fca5a5;">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$can_submit): ?>
                            <div class="alert alert-warning border-warning text-dark p-3 rounded-3 mb-3">
                                <i class="fas fa-ban fa-lg text-danger me-2"></i>
                                <strong>Submission Restrictions Active:</strong><br>
                                <span class="small"><?php echo htmlspecialchars($submission_blocked_reason); ?></span>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="content" class="form-label" style="color: #1e3a8a;">Submission Text</label>
                                <textarea name="content" id="content" class="form-control" rows="4" <?php echo !$can_submit ? 'disabled' : ''; ?> style="border-color: #2563eb;" placeholder="Write your submission content here..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="submission_file" class="form-label" style="color: #1e3a8a;">Attach File (Optional)</label>
                                <input type="file" name="submission_file" id="submission_file" class="form-control" accept=".pdf,.docx,.pptx,.jpg,.jpeg,.png" <?php echo !$can_submit ? 'disabled' : ''; ?> style="border-color: #2563eb;">
                                <div class="form-text">Allowed: PDF, DOCX, PPTX, JPG, JPEG, PNG. Max size 20MB.</div>
                            </div>

                            <button type="submit" name="submit_assignment" class="btn fw-bold px-4 py-2" <?php echo !$can_submit ? 'disabled' : ''; ?> style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none;">
                                <i class="fas fa-paper-plane me-1"></i> Submit Assignment
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Submission History Section for Students -->
                <?php if (!empty($submission_history)): ?>
                    <div class="card shadow border-0 mb-4">
                        <div class="card-header card-header-custom">
                            <h5 class="card-title mb-0"><i class="fas fa-history me-2"></i>Submission History</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" style="font-size:0.88rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Attempt</th>
                                            <th>Date Submitted</th>
                                            <th>Submitted Content</th>
                                            <th>Score / Grade</th>
                                            <th>Status</th>
                                            <th>Review</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($submission_history as $hist): ?>
                                            <tr class="<?php echo $hist['is_latest'] ? 'table-primary-subtle' : ''; ?>">
                                                <td>
                                                    <span class="badge bg-primary rounded-pill">Attempt <?php echo $hist['attempt_number']; ?></span>
                                                    <?php if ($hist['is_latest']): ?>
                                                        <span class="badge bg-success ms-1">Latest</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M d, Y H:i', strtotime($hist['submitted_at'])); ?></td>
                                                <td>
                                                    <?php if (!empty($hist['file_path'])):
                                                        $hPath = htmlspecialchars($hist['file_path']);
                                                        $hName = basename($hist['file_path']);
                                                        $hExt = strtolower(pathinfo($hName, PATHINFO_EXTENSION));
                                                    ?>
                                                        <button type="button" onclick="openLmsFileViewer('../<?php echo $hPath; ?>', '<?php echo addslashes($hName); ?>', '<?php echo $hExt; ?>'); return false;" class="btn btn-sm btn-outline-primary py-0 px-2">
                                                            <i class="fas fa-eye me-1"></i> View File
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted">Text content</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($hist['review_score'] !== null || $hist['grade'] !== null): ?>
                                                        <?php $historyScore = $hist['review_score'] ?? $hist['grade']; ?>
                                                        <?php $historyMaximum = $hist['review_maximum_score'] ?? 100; ?>
                                                        <strong class="text-success"><?php echo htmlspecialchars($historyScore); ?> / <?php echo htmlspecialchars($historyMaximum); ?></strong>
                                                    <?php else: ?>
                                                        <span class="text-muted">Pending Grade</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($hist['review_status'])): ?>
                                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $hist['review_status']))); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-light text-dark border"><?php echo $hist['attempt_number'] > 1 ? 'Resubmitted' : 'Submitted'; ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($hist['review_status']) || $hist['grade'] !== null): ?>
                                                        <a class="btn btn-sm btn-outline-primary" href="review.php?submission_id=<?php echo (int)$hist['id']; ?>"><i class="fas fa-eye me-1"></i>View Review</a>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not reviewed</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php elseif ($role === 'instructor' && $assignment['instructor_id'] == $user_id): ?>
                <div class="card shadow border-0 mb-4">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Submissions</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($grade_success_msg): ?>
                            <div class="alert alert-success" style="background-color: #d1fae5; color: #065f46; border-color: #6ee7b7;">
                                <i class="fas fa-check-circle me-2"></i><?php echo $grade_success_msg; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($error_msg): ?>
                            <div class="alert alert-danger" style="background-color: #fee2e2; color: #991b1b; border-color: #fca5a5;">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_msg; ?>
                            </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead style="background-color: #eff6ff; border-bottom: 2px solid #2563eb;">
                                    <tr>
                                        <th style="color: #1e3a8a;">Student</th>
                                        <th style="color: #1e3a8a;">Submitted At</th>
                                        <th style="color: #1e3a8a;">Submission Content</th>
                                        <th style="color: #1e3a8a;">Grade</th>
                                        <th style="color: #1e3a8a;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                     // Count submission summary stats
                                     $stmtTotal = $pdo->prepare("SELECT COUNT(DISTINCT e.student_id) as total FROM enrollments e WHERE e.subject_id = ?");
                                     $stmtTotal->execute([$assignment['subject_id']]);
                                     $totalStudents = (int)($stmtTotal->fetchColumn() ?: 0);

                                     $stmtSub = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as submitted FROM submissions WHERE assignment_id = ?");
                                     $stmtSub->execute([$assignment_id]);
                                     $submittedCount = (int)($stmtSub->fetchColumn() ?: 0);

                                     $notSubmittedCount = max(0, $totalStudents - $submittedCount);
                                    ?>

                                    <!-- Submission Summary Stats -->
                                    <div class="row g-3 mb-4">
                                        <div class="col-4">
                                            <div class="rounded-3 p-3 text-center" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1px solid #bfdbfe;">
                                                <div class="fw-bold" style="font-size: 1.6rem; color: #1e3a8a; line-height: 1;"><?php echo $totalStudents; ?></div>
                                                <div class="mt-1" style="font-size: 0.75rem; color: #3b82f6; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;"><i class="fas fa-users me-1"></i>Total Students</div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="rounded-3 p-3 text-center" style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border: 1px solid #6ee7b7;">
                                                <div class="fw-bold" style="font-size: 1.6rem; color: #065f46; line-height: 1;"><?php echo $submittedCount; ?></div>
                                                <div class="mt-1" style="font-size: 0.75rem; color: #059669; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;"><i class="fas fa-check-circle me-1"></i>Submitted</div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="rounded-3 p-3 text-center" style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border: 1px solid #fca5a5;">
                                                <div class="fw-bold" style="font-size: 1.6rem; color: #991b1b; line-height: 1;"><?php echo $notSubmittedCount; ?></div>
                                                <div class="mt-1" style="font-size: 0.75rem; color: #ef4444; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;"><i class="fas fa-clock me-1"></i>Not Yet Submitted</div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Progress Bar -->
                                    <?php if ($totalStudents > 0): ?>
                                    <div class="mb-4">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <small class="text-muted fw-semibold">Submission Progress</small>
                                            <small class="fw-bold" style="color: #2563eb;"><?php echo round(($submittedCount / $totalStudents) * 100); ?>%</small>
                                        </div>
                                        <div class="progress" style="height: 8px; border-radius: 99px;">
                                            <div class="progress-bar" role="progressbar"
                                                style="width: <?php echo round(($submittedCount / $totalStudents) * 100); ?>%; background: linear-gradient(90deg, #2563eb 0%, #10b981 100%); border-radius: 99px;"
                                                aria-valuenow="<?php echo $submittedCount; ?>"
                                                aria-valuemin="0"
                                                aria-valuemax="<?php echo $totalStudents; ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php
                                     $stmt = $pdo->prepare("SELECT s.*, u.name as student_name FROM submissions s JOIN users u ON s.student_id = u.id WHERE s.assignment_id = ? ORDER BY s.submitted_at DESC");
                                     $stmt->execute([$assignment_id]);
                                     $submissions = $stmt->fetchAll();
                                     foreach ($submissions as $sub):
                                         $gradeVal = $sub['grade'] !== null ? (int)$sub['grade'] : null;
                                         $gradeBg = 'linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%)';
                                         $gradeColor = '#1e3a8a';
                                         if ($gradeVal !== null) {
                                             if ($gradeVal >= 90) {
                                                 $gradeBg = 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
                                                 $gradeColor = 'white';
                                             } elseif ($gradeVal >= 80) {
                                                 $gradeBg = 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%)';
                                                 $gradeColor = 'white';
                                             } elseif ($gradeVal >= 70) {
                                                 $gradeBg = 'linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%)';
                                                 $gradeColor = '#1e3a8a';
                                             } elseif ($gradeVal >= 60) {
                                                 $gradeBg = 'linear-gradient(135deg, #f87171 0%, #ef4444 100%)';
                                                 $gradeColor = 'white';
                                             } else {
                                                 $gradeBg = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
                                                 $gradeColor = 'white';
                                             }
                                         }
                                     ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($sub['student_name']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($sub['submitted_at'])); ?></td>
                                            <td>
                                                <div class="d-flex flex-column gap-1">
                                                    <?php if (!empty($sub['file_path'])):
                                                        $subFilePath = htmlspecialchars($sub['file_path']);
                                                        $subFileName = basename($sub['file_path']);
                                                        $subFileExt = strtolower(pathinfo($subFileName, PATHINFO_EXTENSION));
                                                    ?>
                                                        <div class="btn-group align-self-start mb-1">
                                                            <button type="button" onclick="openLmsFileViewer('../<?php echo $subFilePath; ?>', '<?php echo addslashes($sub['student_name'] . ' - ' . $subFileName); ?>', '<?php echo $subFileExt; ?>'); return false;" class="btn btn-sm btn-primary">
                                                                <i class="fas fa-eye me-1"></i> View File
                                                            </button>
                                                            <a href="../<?php echo $subFilePath; ?>" download class="btn btn-sm btn-outline-primary" title="Download File">
                                                                <i class="fas fa-download"></i>
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($sub['content'])): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary align-self-start" data-bs-toggle="modal" data-bs-target="#subTextModal<?php echo $sub['id']; ?>">
                                                            <i class="fas fa-file-alt me-1"></i> View Text
                                                        </button>
                                                        
                                                        <!-- Modal for Submission Text -->
                                                        <div class="modal fade text-start" id="subTextModal<?php echo $sub['id']; ?>" tabindex="-1" aria-labelledby="subTextModalLabel<?php echo $sub['id']; ?>" aria-hidden="true">
                                                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                                                <div class="modal-content border-0 shadow">
                                                                    <div class="modal-header text-white" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);">
                                                                        <h5 class="modal-title" id="subTextModalLabel<?php echo $sub['id']; ?>">
                                                                            <i class="fas fa-file-alt me-2"></i> Submission Text - <?php echo htmlspecialchars($sub['student_name']); ?>
                                                                        </h5>
                                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                    </div>
                                                                    <div class="modal-body p-4">
                                                                        <div class="mb-3 d-flex justify-content-between text-muted small border-bottom pb-2">
                                                                            <span><strong>Submitted on:</strong> <?php echo date('M d, Y H:i', strtotime($sub['submitted_at'])); ?></span>
                                                                            <?php if (!empty($sub['file_path'])): ?>
                                                                                <span><strong>Attachment:</strong> <a href="../<?php echo htmlspecialchars($sub['file_path']); ?>" target="_blank">Download file</a></span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <div class="p-3 rounded border" style="white-space: pre-wrap; font-family: inherit; color: #374151; background-color: #f8fafc; max-height: 400px; overflow-y: auto;">
                                                                            <?php echo htmlspecialchars($sub['content']); ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer bg-light">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (empty($sub['file_path']) && empty($sub['content'])): ?>
                                                        <span style="color: #6b7280;">No content submitted</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><span class="badge" style="background: <?php echo $gradeBg; ?>; color: <?php echo $gradeColor; ?>;"><?php echo $gradeVal !== null ? $gradeVal . '/100' : 'Pending'; ?></span></td>
                                            <td style="min-width: 260px;">
                                                <a href="review.php?submission_id=<?php echo (int)$sub['id']; ?>" class="btn btn-sm btn-outline-primary w-100 mb-2">
                                                    <i class="fas fa-pen-to-square me-1"></i>Open Annotation Review
                                                </a>
                                                <form method="POST" class="d-flex flex-column gap-2">
                                                    <input type="hidden" name="submission_id" value="<?php echo $sub['id']; ?>">
                                                    <div class="input-group input-group-sm">
                                                        <input type="number" name="grade" class="form-control form-control-sm" min="0" max="100" placeholder="0-100" required value="<?php echo $gradeVal !== null ? $gradeVal : ''; ?>" style="border-color: #2563eb;">
                                                        <span class="input-group-text" style="background-color: #eff6ff; color: #1e3a8a;">/100</span>
                                                    </div>
                                                    <textarea name="feedback" class="form-control form-control-sm" rows="2" placeholder="Feedback (optional)"><?php echo htmlspecialchars($sub['feedback']); ?></textarea>
                                                    <button type="submit" name="grade_submission" class="btn btn-sm" style="background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: white; border: none;">Save Grade</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($submissions)): ?>
                                        <tr><td colspan="5" class="text-center" style="color: #6b7280;">No submissions yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: white;">
                    <h5 class="card-title mb-0"><i class="fas fa-info-circle me-2"></i>Assignment Details</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span class="text-muted"><i class="fas fa-book me-2" style="color: #2563eb;"></i>Subject</span>
                            <span class="fw-semibold text-end"><?php echo htmlspecialchars($assignment['subject_title']); ?></span>
                        </li>
                        <?php if (isset($assignment['instructor_name'])): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span class="text-muted"><i class="fas fa-user-tie me-2" style="color: #2563eb;"></i>Instructor</span>
                                <span class="fw-semibold text-end"><?php echo htmlspecialchars($assignment['instructor_name']); ?></span>
                            </li>
                        <?php endif; ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span class="text-muted"><i class="fas fa-star me-2" style="color: #2563eb;"></i>Max Score</span>
                            <span class="badge bg-primary rounded-pill"><?php echo $assignment['points'] ?? 100; ?> Points</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span class="text-muted"><i class="fas fa-calendar-alt me-2" style="color: #2563eb;"></i>Due Date</span>
                            <span class="fw-semibold text-end text-danger"><?php echo date('M d, Y H:i', strtotime($assignment['due_date'])); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span class="text-muted"><i class="fas fa-clock me-2" style="color: #2563eb;"></i>Posted On</span>
                            <span class="fw-semibold text-end"><?php echo date('M d, Y', strtotime($assignment['created_at'])); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span class="text-muted"><i class="fas fa-info me-2" style="color: #2563eb;"></i>Status</span>
                            <span class="badge bg-success"><?php echo ucfirst(htmlspecialchars($assignment['status'])); ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</main>

<?php if ($role === 'instructor' || $role === 'admin'):
    // Fetch enrolled students for override modal
    $stmt = $pdo->prepare("SELECT u.id, u.name, u.email FROM users u JOIN enrollments e ON u.id = e.student_id WHERE e.subject_id = ? ORDER BY u.name ASC");
    $stmt->execute([$assignment['subject_id']]);
    $enrolled_students = $stmt->fetchAll();
?>
<!-- Teacher Student Override Modal -->
<div class="modal fade" id="teacherOverrideModal" tabindex="-1" aria-labelledby="teacherOverrideModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header text-white" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);">
                <h5 class="modal-title fw-bold" id="teacherOverrideModalLabel"><i class="fas fa-user-clock me-2"></i>Student Resubmission Override</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">Grant a specific student custom resubmission attempts or an extended deadline without changing settings for other students.</p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color: #1e3a8a;">Select Student <span class="text-danger">*</span></label>
                        <select name="target_student_id" class="form-select" required style="border-color: #2563eb;">
                            <option value="">-- Select Student --</option>
                            <?php foreach ($enrolled_students as $st): ?>
                                <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?> (<?php echo htmlspecialchars($st['email']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color: #1e3a8a;">Allowed Max Attempts for Student</label>
                        <input type="number" name="override_attempts" class="form-control" value="5" min="1" max="10" required style="border-color: #2563eb;">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color: #1e3a8a;">Individual Extended Deadline (Optional)</label>
                        <input type="datetime-local" name="override_due_date" class="form-control" style="border-color: #2563eb;">
                        <small class="text-muted">Leave blank to use the standard assignment deadline.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color: #1e3a8a;">Override Notes / Reason (Optional)</label>
                        <textarea name="override_notes" class="form-control" rows="2" placeholder="e.g., Extended due to medical excuse" style="border-color: #2563eb;"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_student_override" class="btn btn-primary fw-bold px-4">
                        <i class="fas fa-save me-1"></i> Save Student Override
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
