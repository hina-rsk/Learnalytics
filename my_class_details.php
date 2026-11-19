anti<?php
session_start();
require_once 'config/database.php';
require_once 'includes/enrollment_requests.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: auth/login.php');
    exit;
}

$instructor_id = (int)$_SESSION['user_id'];
$subject_id = (int)($_GET['id'] ?? 0);
if ($subject_id <= 0) {
    header('Location: my_classes.php');
    exit;
}

// verify instructor owns the class
$stmt = $pdo->prepare("
    SELECT sub.*, sec.name AS section_name, ay.name AS academic_year_name
    FROM subjects sub
    LEFT JOIN sections sec ON sec.id = sub.section_id
    LEFT JOIN academic_years ay ON ay.id = sec.academic_year_id
    WHERE sub.id = ? AND sub.instructor_id = ?
");
$stmt->execute([$subject_id, $instructor_id]);
$subject = $stmt->fetch();
if (!$subject) {
    header('Location: my_classes.php');
    exit;
}

ensureEnrollmentRequestsTable($pdo);
if (empty($_SESSION['enrollment_review_csrf'])) {
    $_SESSION['enrollment_review_csrf'] = bin2hex(random_bytes(32));
}

$success_msg = '';
$error_msg = '';

// ensure schedule table exists before querying
$pdo->exec("CREATE TABLE IF NOT EXISTS class_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    days VARCHAR(255),
    start_time TIME,
    end_time TIME,
    room VARCHAR(255),
    meeting_link VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// fetch schedule if exists
$sched = $pdo->prepare('SELECT * FROM class_schedules WHERE subject_id = ? ORDER BY id DESC LIMIT 1');
$sched->execute([$subject_id]);
$schedule = $sched->fetch();

// Handle enrollment review and student removal actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfToken) || !hash_equals($_SESSION['enrollment_review_csrf'], $csrfToken)) {
        $error_msg = 'Your session has expired. Refresh the page and try again.';
    } elseif (isset($_POST['create_meeting'])) {
        if (empty($schedule['meeting_link'])) {
            try {
                $meetingLink = 'learnalytics-class-' . $subject_id . '-' . bin2hex(random_bytes(16));
                if ($schedule) {
                    $pdo->prepare('UPDATE class_schedules SET meeting_link = ? WHERE id = ?')
                        ->execute([$meetingLink, $schedule['id']]);
                } else {
                    $pdo->prepare('INSERT INTO class_schedules (subject_id, meeting_link) VALUES (?, ?)')
                        ->execute([$subject_id, $meetingLink]);
                }
                header('Location: virtual_class.php?id=' . $subject_id);
                exit;
            } catch (Throwable $e) {
                error_log('Virtual meeting creation failed: ' . $e->getMessage());
                $error_msg = 'Could not create the virtual meeting. Please try again.';
            }
        } else {
            header('Location: virtual_class.php?id=' . $subject_id);
            exit;
        }
    } elseif (isset($_POST['review_request'])) {
        $request_id = (int)$_POST['review_request'];
        $decision = $_POST['decision'] ?? '';
        if ($request_id <= 0 || !in_array($decision, ['approve', 'deny'], true)) {
            $error_msg = 'Invalid enrollment request.';
        } else {
            try {
                $pdo->beginTransaction();
                $requestStmt = $pdo->prepare("
                    SELECT id, student_id, status
                    FROM enrollment_requests
                    WHERE id = ? AND subject_id = ?
                    FOR UPDATE
                ");
                $requestStmt->execute([$request_id, $subject_id]);
                $request = $requestStmt->fetch();

                if (!$request || $request['status'] !== 'pending') {
                    $pdo->rollBack();
                    $error_msg = 'This request is no longer pending.';
                } else {
                    if ($decision === 'approve') {
                        $enrollmentCheck = $pdo->prepare('SELECT id FROM enrollments WHERE student_id = ? AND subject_id = ?');
                        $enrollmentCheck->execute([$request['student_id'], $subject_id]);
                        if (!$enrollmentCheck->fetch()) {
                            $pdo->prepare('INSERT INTO enrollments (student_id, subject_id, enrolled_at) VALUES (?, ?, NOW())')
                                ->execute([$request['student_id'], $subject_id]);
                        }
                        $newStatus = 'approved';
                    } else {
                        $newStatus = 'denied';
                    }

                    $pdo->prepare('UPDATE enrollment_requests SET status = ?, reviewed_at = NOW() WHERE id = ?')
                        ->execute([$newStatus, $request_id]);
                    $pdo->commit();
                    $success_msg = $decision === 'approve'
                        ? 'Student approved and added to the class.'
                        : 'Enrollment request declined.';
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Enrollment request review failed: ' . $e->getMessage());
                $error_msg = 'Could not process the enrollment request. Please try again.';
            }
        }
    } elseif (isset($_POST['remove_student'])) {
        $student_id = (int)$_POST['remove_student'];
        if ($student_id > 0) {
            $pdo->prepare('DELETE FROM enrollments WHERE student_id = ? AND subject_id = ?')->execute([$student_id, $subject_id]);
        }
        header('Location: my_class_details.php?id=' . $subject_id);
        exit;
    }
}

// get pending enrollment requests for this class
$pendingRequestsStmt = $pdo->prepare("
    SELECT er.id, er.requested_at, u.name, u.email
    FROM enrollment_requests er
    JOIN users u ON u.id = er.student_id
    WHERE er.subject_id = ? AND er.status = 'pending'
    ORDER BY er.requested_at ASC
");
$pendingRequestsStmt->execute([$subject_id]);
$pendingRequests = $pendingRequestsStmt->fetchAll();

// get enrolled students
$students = $pdo->prepare('SELECT u.id, u.name, u.email, e.enrolled_at FROM enrollments e JOIN users u ON u.id = e.student_id WHERE e.subject_id = ? ORDER BY u.name');
$students->execute([$subject_id]);
$enrolled = $students->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .class-details-page {
        min-height: calc(100vh - var(--navbar-height));
    }

    .class-details-page .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .class-details-page .class-code {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.35rem 0.7rem;
        margin-top: 0.55rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.16);
        color: #fff;
        font-size: 0.82rem;
    }

    .class-details-page .class-details-card,
    .class-details-page .class-people-card {
        overflow: hidden;
        border: 0;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
    }

    .class-details-page .section-card-header {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.7rem 1rem;
        border-bottom: 1px solid #e2e8f0;
        color: var(--primary-blue);
    }

    .class-details-page .section-card-header h2 {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 700;
    }

    .class-details-page .section-card-body {
        padding: 0.85rem 1rem;
    }

    .class-details-page .class-description {
        color: #475569;
        line-height: 1.45;
        white-space: normal;
        margin-bottom: 0.6rem !important;
    }

    .class-details-page .class-meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
        gap: 0.5rem;
        margin-top: 0.65rem;
    }

    .class-details-page .class-meta-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.55rem 0.65rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #f8fafc;
        color: #475569;
        overflow-wrap: anywhere;
        font-size: 0.82rem;
    }

    .class-details-page .class-meta-item i {
        color: var(--secondary-blue);
        flex: 0 0 auto;
    }

    .class-details-page .people-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
    }

    .class-details-page .people-row:last-child {
        border-bottom: 0;
    }

    .class-details-page .people-name {
        color: #1e293b;
        font-weight: 650;
    }

    .class-details-page .people-meta {
        margin-top: 0.2rem;
        color: #64748b;
        font-size: 0.82rem;
        overflow-wrap: anywhere;
    }

    .class-details-page .empty-state {
        padding: 1.25rem 1rem;
        color: #64748b;
        text-align: center;
    }

    .class-details-page .class-details-card {
        margin-bottom: 1rem !important;
    }

    @media (max-width: 575.98px) {
        .class-details-page .page-header {
            padding: 1.25rem;
        }

        .class-details-page .people-row {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4 class-details-page">
    <div class="page-header mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1"><?php echo htmlspecialchars($subject['title']); ?></h1>
            <span class="class-code"><i class="fas fa-key"></i> Class Code: <strong><?php echo htmlspecialchars($subject['class_code'] ?? 'N/A'); ?></strong></span>
        </div>
        <a href="my_classes.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i> Back to My Classes</a>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card class-details-card mb-4">
        <div class="section-card-header">
            <i class="fas fa-info-circle"></i>
            <h2>Class Details</h2>
        </div>
        <div class="section-card-body">
            <p class="class-description mb-0"><?php echo $subject['description'] ? nl2br(htmlspecialchars($subject['description'])) : 'No class description provided.'; ?></p>
            <div class="class-meta-grid">
                <?php if (!empty($subject['academic_year_name'])): ?>
                    <div class="class-meta-item"><i class="fas fa-calendar-alt"></i><span>Academic year: <?php echo htmlspecialchars($subject['academic_year_name']); ?></span></div>
                <?php endif; ?>
                <?php if (!empty($subject['section_name'])): ?>
                    <div class="class-meta-item"><i class="fas fa-users"></i><span>Section: <?php echo htmlspecialchars($subject['section_name']); ?></span></div>
                <?php endif; ?>
                
        
        </div>
    </div>

    <section class="class-people-card mb-4">
        <div class="section-card-header">
            <i class="fas fa-video"></i>
            <h2>Virtual Meeting</h2>
        </div>
        <div class="section-card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <?php if (!empty($schedule['meeting_link'])): ?>
                    <p class="mb-1 fw-semibold">A meeting room is ready for this class.</p>
                    <small class="text-muted">Enrolled students can join from the class page.</small>
                <?php else: ?>
                    <p class="mb-1 fw-semibold">No meeting room has been created for this class.</p>
                    <small class="text-muted">Create a room now so enrolled students can join from their class page.</small>
                <?php endif; ?>
            </div>
            <?php if (!empty($schedule['meeting_link'])): ?>
                <a href="virtual_class.php?id=<?php echo $subject_id; ?>" class="btn btn-success">
                    <i class="fas fa-video me-1"></i>Start Meeting
                </a>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['enrollment_review_csrf']); ?>">
                    <button class="btn btn-primary" type="submit" name="create_meeting" value="1">
                        <i class="fas fa-video me-1"></i>Create and Start Meeting
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-lg-6">
            <section class="class-people-card">
                <div class="section-card-header">
                    <i class="fas fa-user-clock"></i>
                    <h2>Pending Join Requests</h2>
                    <span class="badge rounded-pill bg-warning text-dark ms-auto"><?php echo count($pendingRequests); ?></span>
                </div>
                <?php if (!empty($pendingRequests)): ?>
                    <?php foreach ($pendingRequests as $request): ?>
                        <div class="people-row">
                            <div>
                                <div class="people-name"><?php echo htmlspecialchars($request['name']); ?></div>
                                <div class="people-meta"><?php echo htmlspecialchars($request['email']); ?></div>
                                <div class="people-meta"><i class="far fa-clock me-1"></i>Requested <?php echo date('M d, Y', strtotime($request['requested_at'])); ?></div>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['enrollment_review_csrf']); ?>">
                                    <input type="hidden" name="review_request" value="<?php echo (int)$request['id']; ?>">
                                    <input type="hidden" name="decision" value="approve">
                                    <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-check me-1"></i>Approve</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Decline this student’s class request?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['enrollment_review_csrf']); ?>">
                                    <input type="hidden" name="review_request" value="<?php echo (int)$request['id']; ?>">
                                    <input type="hidden" name="decision" value="deny">
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fas fa-times me-1"></i>Decline</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-check fa-2x mb-2"></i>
                        <p class="mb-0">There are no students waiting for approval.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="class-people-card">
                <div class="section-card-header">
                    <i class="fas fa-user-graduate"></i>
                    <h2>Enrolled Students</h2>
                    <span class="badge rounded-pill bg-primary ms-auto"><?php echo count($enrolled); ?></span>
                </div>
                <?php if (!empty($enrolled)): ?>
                    <?php foreach ($enrolled as $stu): ?>
                        <div class="people-row">
                            <div>
                                <div class="people-name"><?php echo htmlspecialchars($stu['name']); ?></div>
                                <div class="people-meta"><?php echo htmlspecialchars($stu['email']); ?></div>
                                <div class="people-meta"><i class="far fa-calendar-check me-1"></i>Enrolled <?php echo date('M d, Y', strtotime($stu['enrolled_at'])); ?></div>
                            </div>
                            <form method="post" onsubmit="return confirm('Remove this student from the class?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['enrollment_review_csrf']); ?>" />
                                <input type="hidden" name="remove_student" value="<?php echo (int)$stu['id']; ?>" />
                                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fas fa-user-minus me-1"></i>Remove</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users fa-2x mb-2"></i>
                        <p class="mb-0">No students enrolled yet.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>