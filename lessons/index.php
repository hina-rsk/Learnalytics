<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Fetch lessons based on role
$lessons = [];
if ($role === 'student') {
    // Students see lessons for their enrolled subjects
    $stmt = $pdo->prepare("SELECT DISTINCT l.*, c.title as subject_title, c.instructor_id,
                            (SELECT COUNT(*) FROM lesson_attachments WHERE lesson_id = l.id) as attachment_count
                            FROM lessons l
                            JOIN subjects c ON l.subject_id = c.id
                            JOIN enrollments e ON c.id = e.subject_id
                            WHERE e.student_id = ?
                            ORDER BY l.created_at DESC");
    $stmt->execute([$user_id]);
    $lessons = $stmt->fetchAll();
} elseif ($role === 'instructor') {
    // Instructors see lessons for their subjects
    $stmt = $pdo->prepare("SELECT l.*, c.title as subject_title, c.instructor_id,
                            (SELECT COUNT(*) FROM lesson_attachments WHERE lesson_id = l.id) as attachment_count
                            FROM lessons l
                            JOIN subjects c ON l.subject_id = c.id
                            WHERE c.instructor_id = ?
                            ORDER BY l.created_at DESC");
    $stmt->execute([$user_id]);
    $lessons = $stmt->fetchAll();
} else { // admin
    // Admins see all lessons
    $stmt = $pdo->prepare("SELECT l.*, c.title as subject_title, c.instructor_id,
                            (SELECT COUNT(*) FROM lesson_attachments WHERE lesson_id = l.id) as attachment_count
                            FROM lessons l
                            JOIN subjects c ON l.subject_id = c.id
                            ORDER BY l.created_at DESC");
    $stmt->execute([]);
    $lessons = $stmt->fetchAll();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">Lessons</h1>
        <?php if ($role === 'instructor' || $role === 'admin'): ?>
            <a href="create.php" class="btn btn-sm" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none;"><i class="fas fa-plus me-1"></i> Create Lesson</a>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <?php if (empty($lessons)): ?>
                <div class="alert alert-info" style="background-color: #dbeafe; color: #1e3a8a; border-color: #2563eb;">
                    <i class="fas fa-info-circle me-2"></i> No lessons available yet.
                </div>
            <?php else: ?>
                <div class="card shadow border-0">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Available Lessons</h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <?php foreach ($lessons as $lesson): ?>
                                <div class="list-group-item border-left" style="border-left: 4px solid #2563eb;">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <a href="view.php?id=<?php echo $lesson['id']; ?>" class="text-decoration-none">
                                                <h6 class="mb-1 fw-bold" style="color: #1e3a8a;"><?php echo htmlspecialchars($lesson['title']); ?></h6>
                                            </a>
                                            <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                                                <span class="badge bg-secondary"><i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($lesson['subject_title']); ?></span>
                                                <?php if (!empty($lesson['topic'])): ?>
                                                    <span class="badge" style="background-color: #e0e7ff; color: #3730a3; font-weight: 600;">
                                                        <i class="fas fa-folder me-1"></i> Topic: <?php echo htmlspecialchars($lesson['topic']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">Created on <?php echo date('M d, Y', strtotime($lesson['created_at'])); ?></small>
                                            <?php if ($lesson['attachment_count'] > 0): ?>
                                                <small class="text-success ms-2"><i class="fas fa-paperclip me-1"></i> <?php echo $lesson['attachment_count']; ?> file<?php echo $lesson['attachment_count'] > 1 ? 's' : ''; ?> attached</small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end">
                                            <a href="view.php?id=<?php echo $lesson['id']; ?>" class="btn btn-sm btn-outline-primary me-1 mb-1"><i class="fas fa-eye me-1"></i> View</a>
                                            <?php if ($role === 'admin' || ($role === 'instructor' && $lesson['instructor_id'] == $user_id)): ?>
                                                <a href="edit.php?id=<?php echo $lesson['id']; ?>" class="btn btn-sm btn-outline-secondary me-1 mb-1"><i class="fas fa-edit me-1"></i> Edit</a>
                                                <button type="button" class="btn btn-sm btn-outline-danger mb-1" data-bs-toggle="modal" data-bs-target="#deleteLessonModal" data-lesson-id="<?php echo $lesson['id']; ?>" data-lesson-title="<?php echo htmlspecialchars($lesson['title']); ?>">
                                                    <i class="fas fa-trash me-1"></i> Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteLessonModal" tabindex="-1" aria-labelledby="deleteLessonModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-sm">
            <div class="modal-header card-header-custom border-0">
                <h5 class="modal-title text-white" id="deleteLessonModalLabel">Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="delete.php" id="deleteLessonForm">
                <div class="modal-body">
                    <p class="mb-0">Are you sure you want to delete <strong id="deleteLessonTitle"></strong>?</p>
                    <p class="small text-muted">This action cannot be undone.</p>
                    <input type="hidden" name="lesson_id" id="deleteLessonId" value="">
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Lesson</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var deleteLessonModal = document.getElementById('deleteLessonModal');
        if (!deleteLessonModal) return;
        deleteLessonModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var lessonId = button.getAttribute('data-lesson-id');
            var lessonTitle = button.getAttribute('data-lesson-title');

            var deleteLessonIdInput = deleteLessonModal.querySelector('#deleteLessonId');
            var deleteLessonTitle = deleteLessonModal.querySelector('#deleteLessonTitle');

            if (deleteLessonIdInput) deleteLessonIdInput.value = lessonId;
            if (deleteLessonTitle) deleteLessonTitle.textContent = lessonTitle;
        });
    });
</script>

<?php include '../includes/footer.php'; ?>
