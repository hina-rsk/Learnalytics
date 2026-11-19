<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'student') {
    header('Location: ../auth/login.php');
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare("
    SELECT a.id, a.title, a.description, a.due_date, a.points, a.status,
           s.title AS subject_title, sub.id AS submission_id, sub.submitted_at,
           sub.grade, sub.feedback
    FROM enrollments e
    JOIN assignments a ON a.subject_id = e.subject_id
    JOIN subjects s ON s.id = e.subject_id
    LEFT JOIN submissions sub
        ON sub.assignment_id = a.id
        AND sub.student_id = e.student_id
        AND sub.is_latest = 1
    WHERE e.student_id = ?
    ORDER BY a.due_date IS NULL, a.due_date ASC, a.created_at DESC
");
$stmt->execute([$student_id]);
$assignments = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;"><i class="fas fa-tasks me-2"></i>Assignments</h1>
    </div>

    <?php if (empty($assignments)): ?>
        <div class="card shadow-sm border-0">
            <div class="card-body text-center py-5">
                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No assignments available</h5>
                <p class="text-muted mb-0">Assignments from your enrolled classes will appear here.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($assignments as $assignment): ?>
                <?php
                    if ($assignment['grade'] !== null) {
                        $statusLabel = 'Graded';
                        $statusClass = 'success';
                    } elseif ($assignment['submitted_at'] !== null) {
                        $statusLabel = 'Submitted';
                        $statusClass = 'primary';
                    } else {
                        $statusLabel = 'Not submitted';
                        $statusClass = 'warning';
                    }
                ?>
                <div class="col-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                <div class="flex-grow-1">
                                    <h5 class="mb-1" style="color:#1e3a8a;"><?php echo htmlspecialchars($assignment['title']); ?></h5>
                                    <div class="text-muted small mb-2"><?php echo htmlspecialchars($assignment['subject_title']); ?></div>
                                    <p class="mb-2"><?php echo nl2br(htmlspecialchars($assignment['description'] ?? 'No additional instructions provided.')); ?></p>
                                    <div class="small text-muted">
                                        <span class="me-3"><i class="fas fa-calendar-alt me-1"></i>
                                            <?php echo $assignment['due_date'] ? 'Due ' . date('M j, Y g:i A', strtotime($assignment['due_date'])) : 'No deadline'; ?>
                                        </span>
                                        <span><i class="fas fa-star me-1"></i><?php echo htmlspecialchars($assignment['points'] ?? ''); ?> points</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                                    <a class="btn btn-sm btn-outline-primary" href="../assignments/view.php?id=<?php echo (int)$assignment['id']; ?>">
                                        <i class="fas fa-eye me-1"></i>View / Submit
                                    </a>
                                </div>
                            </div>
                            <?php if ($assignment['feedback']): ?>
                                <div class="alert alert-light border mt-3 mb-0 py-2">
                                    <strong>Instructor feedback:</strong> <?php echo nl2br(htmlspecialchars($assignment['feedback'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php include '../includes/footer.php'; ?>
