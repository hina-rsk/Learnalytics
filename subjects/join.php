<?php
session_start();
require_once '../config/database.php';
require_once '../includes/enrollment_requests.php';

$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['user_role'] ?? 'guest';

$message = '';
$error = '';
if ($user_id && $role === 'student' && empty($_SESSION['class_code_request_csrf'])) {
    $_SESSION['class_code_request_csrf'] = bin2hex(random_bytes(32));
}

// Handle class code submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_class'])) {
    $class_code = strtoupper(trim($_POST['class_code'] ?? ''));
    
    if (empty($class_code)) {
        $error = 'Please enter a class code.';
    } elseif (!$user_id) {
        header("Location: ../auth/login.php");
        exit;
    } elseif ($role !== 'student') {
        $error = 'Only students can join classes using a class code.';
    } elseif (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['class_code_request_csrf'], $_POST['csrf_token'])) {
        $error = 'Your session has expired. Refresh the page and try again.';
    } else {
        try {
            ensureEnrollmentRequestsTable($pdo);
            $stmt = $pdo->prepare("SELECT id, title FROM subjects WHERE class_code = ? AND status = 'active'");
            $stmt->execute([$class_code]);
            $subject = $stmt->fetch();
            
            if (!$subject) {
                $error = 'Active class code not found. Please check the code and try again.';
            } else {
                $requestResult = requestEnrollment($pdo, (int)$user_id, (int)$subject['id']);
                if ($requestResult === 'already_enrolled') {
                    $error = 'You are already enrolled in this class.';
                } elseif ($requestResult === 'pending') {
                    $error = 'Your request to join this class is already awaiting instructor approval.';
                } else {
                    $message = 'Your request to join ' . htmlspecialchars($subject['title']) . ' has been sent to the instructor.';
                }
            }
        } catch (PDOException $e) {
            error_log('Class enrollment request failed: ' . $e->getMessage());
            $error = 'Could not send the join request. Please try again.';
        }
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="row justify-content-center py-5">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow-lg border-0">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <i class="fas fa-school fa-3x" style="color: #2563eb;"></i>
                        <h2 class="mt-3" style="color: #1e3a8a;">Join a Class</h2>
                        <p class="text-muted">Enter the class code given by your teacher</p>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <?= $message ?>
                            <a href="../subjects/index.php" class="btn btn-sm btn-outline-primary ms-2">
                                Go to Subjects
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$user_id): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-sign-in-alt me-2"></i>
                            Please <a href="../auth/login.php">login</a> to join a class.
                        </div>
                    <?php elseif ($role !== 'student'): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            This feature is for students only.
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['class_code_request_csrf']) ?>">
                            <div class="mb-3">
                                <label class="form-label" style="color: #1e3a8a; font-weight: 500;">Class Code</label>
                                <input type="text" 
                                       name="class_code" 
                                       class="form-control form-control-lg text-center" 
                                       placeholder="e.g. ABC123" 
                                       style="font-size: 1.5rem; letter-spacing: 3px; text-transform: uppercase;"
                                       maxlength="6"
                                       required
                                       value="<?= isset($_POST['class_code']) ? htmlspecialchars($_POST['class_code']) : '' ?>">
                                <div class="form-text text-center">Ask your teacher for the 6-character code</div>
                            </div>
                            <button type="submit" name="join_class" class="btn w-100 py-3" style="background: #2563eb; color: white; border: none; font-size: 1.1rem;">
                                <i class="fas fa-sign-in-alt me-2"></i> Join Class
                            </button>
                        </form>
                    <?php endif; ?>

                    <div class="mt-4 text-center">
                        <a href="../subjects/index.php" class="text-decoration-none" style="color: #2563eb;">
                            <i class="fas fa-arrow-left me-1"></i> Back to Subjects
                        </a>
                    </div>
                </div>
            </div>

            <!-- Recent Enrollments -->
            <?php if ($user_id && $role === 'student'): ?>
                <?php
                $stmt = $pdo->prepare("
                    SELECT s.id, s.title, s.class_code, e.enrolled_at
                    FROM enrollments e
                    JOIN subjects s ON e.subject_id = s.id
                    WHERE e.student_id = ?
                    ORDER BY e.enrolled_at DESC
                    LIMIT 5
                ");
                $stmt->execute([$user_id]);
                $recentEnrollments = $stmt->fetchAll();
                ?>
                
                <?php if (!empty($recentEnrollments)): ?>
                    <div class="card shadow-sm border-0 mt-4">
                        <div class="card-header bg-white border-0">
                            <h6 class="mb-0" style="color: #1e3a8a;">Your Recent Classes</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($recentEnrollments as $enroll): ?>
                                    <a href="../subjects/view.php?id=<?= $enroll['id'] ?>" class="list-group-item list-group-item-action border-0">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="fas fa-book me-2" style="color: #2563eb;"></i>
                                                <?= htmlspecialchars($enroll['title']) ?>
                                            </div>
                                            <small class="text-muted"><?= date('M d', strtotime($enroll['enrolled_at'])) ?></small>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<style>
.form-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
}
</style>

<?php include '../includes/footer.php'; ?>