<?php
session_start();
require_once '../config/database.php';
require_once '../includes/enrollment_requests.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ../auth/login.php');
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$error = '';
$success = '';
ensureEnrollmentRequestsTable($pdo);

if (empty($_SESSION['enrollment_request_csrf'])) {
    $_SESSION['enrollment_request_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $code = trim($_POST['class_code'] ?? '');
    if (!is_string($csrfToken) || !hash_equals($_SESSION['enrollment_request_csrf'], $csrfToken)) {
        $error = 'Your session has expired. Refresh the page and try again.';
    } elseif ($code === '') {
        $error = 'Please enter a class code.';
    } else {
        $stmt = $pdo->prepare("SELECT id, title FROM subjects WHERE class_code = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$code]);
        $subject = $stmt->fetch();
        if (!$subject) {
            $error = 'Active class not found. Please check the code and try again.';
        } else {
            $requestResult = requestEnrollment($pdo, $student_id, (int)$subject['id']);
            if ($requestResult === 'already_enrolled') {
                $error = 'You are already enrolled in this class.';
            } elseif ($requestResult === 'pending') {
                $error = 'Your request to join this class is already awaiting instructor approval.';
            } elseif ($requestResult === 'requested') {
                header('Location: join_class.php?requested=1');
                exit;
            }
        }
    }
}

$requestsStmt = $pdo->prepare("
    SELECT er.status, er.requested_at, er.reviewed_at, s.title AS subject_title
    FROM enrollment_requests er
    JOIN subjects s ON s.id = er.subject_id
    WHERE er.student_id = ? AND er.status IN ('pending', 'denied')
    ORDER BY er.requested_at DESC
");
$requestsStmt->execute([$student_id]);
$enrollmentRequests = $requestsStmt->fetchAll();

if (isset($_GET['requested'])) {
    $success = 'Your request was sent to the instructor. You can access the class after it is approved.';
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="min-height:100vh; background:#f1f5f9;">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 fw-bold mb-1"><i class="fas fa-user-plus me-2"></i>Join Class</h1>
                <p class="mb-0" style="opacity:0.9;">Enter the class code provided by your instructor to join.</p>
            </div>
            <div>
                <a href="subjects.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i> Back to Classes</a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="content-card">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['enrollment_request_csrf']); ?>">
                    <div class="mb-3">
                        <label class="form-label">Class Code</label>
                        <input type="text" name="class_code" class="form-control search-input" placeholder="Enter class code" required />
                    </div>
                    <div>
                        <button class="btn btn-primary" type="submit"><i class="fas fa-sign-in-alt me-1"></i> Join</button>
                        <a href="subjects.php" class="btn btn-secondary ms-2">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="content-card">
                <h5 class="mb-3">Join Requests</h5>
                <?php if (empty($enrollmentRequests)): ?>
                    <p class="text-muted mb-0">You have no pending or declined class requests.</p>
                <?php else: ?>
                    <div class="item-list">
                        <?php foreach ($enrollmentRequests as $request): ?>
                            <div class="item-row">
                                <div class="item-info">
                                    <div class="item-title"><?php echo htmlspecialchars($request['subject_title']); ?></div>
                                    <div class="item-meta">Requested <?php echo date('M d, Y', strtotime($request['requested_at'])); ?></div>
                                </div>
                                <span class="badge bg-<?php echo $request['status'] === 'pending' ? 'warning text-dark' : 'secondary'; ?>">
                                    <?php echo $request['status'] === 'pending' ? 'Awaiting approval' : 'Declined'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
