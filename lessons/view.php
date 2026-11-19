<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? 'student';

$lesson_id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT l.*, c.title as subject_title, c.instructor_id FROM lessons l JOIN subjects c ON l.subject_id = c.id WHERE l.id = ?");
$stmt->execute([$lesson_id]);
$lesson = $stmt->fetch();

if (!$lesson) {
    die("Lesson not found.");
}

if ($role === 'student') {
    $enrollmentCheck = $pdo->prepare('SELECT id FROM enrollments WHERE student_id = ? AND subject_id = ?');
    $enrollmentCheck->execute([$user_id, $lesson['subject_id']]);
    if (!$enrollmentCheck->fetch()) {
        header('Location: ../subjects/view.php?id=' . (int)$lesson['subject_id']);
        exit;
    }
}

$canManage = ($role === 'admin' || ($role === 'instructor' && $lesson['instructor_id'] == $user_id));

// Get lesson attachments
$stmt = $pdo->prepare("SELECT * FROM lesson_attachments WHERE lesson_id = ? ORDER BY uploaded_at ASC");
$stmt->execute([$lesson_id]);
$attachments = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="../subjects/index.php" style="color: #2563eb;">Subjects</a></li>
            <li class="breadcrumb-item"><a href="../subjects/view.php?id=<?php echo $lesson['subject_id']; ?>" style="color: #2563eb;"><?php echo htmlspecialchars($lesson['subject_title']); ?></a></li>
            <li class="breadcrumb-item active" style="color: #1e3a8a;"><?php echo htmlspecialchars($lesson['title']); ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <div>
            <h1 class="h2" style="color: #1e3a8a; margin: 0;"><?php echo htmlspecialchars($lesson['title']); ?></h1>
            <?php if (!empty($lesson['topic'])): ?>
                <div class="mt-2">
                    <span class="badge" style="background-color: #e0e7ff; color: #3730a3; font-size: 0.85rem; font-weight: 600; padding: 0.35rem 0.75rem; border-radius: 6px;">
                        <i class="fas fa-folder me-1"></i> Topic: <?php echo htmlspecialchars($lesson['topic']); ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($canManage): ?>
            <div class="btn-group">
                <a href="edit.php?id=<?php echo $lesson_id; ?>" class="btn btn-sm" style="background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: white; border: none;"><i class="fas fa-edit me-1"></i> Edit</a>
                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteLessonModal" data-lesson-id="<?php echo $lesson_id; ?>" data-lesson-title="<?php echo htmlspecialchars($lesson['title']); ?>">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            </div>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <?php if ($lesson['video_url']): ?>
                <div class="ratio ratio-16x9 mb-4 shadow rounded">
                    <iframe src="<?php echo htmlspecialchars($lesson['video_url']); ?>" title="Lesson Video" allowfullscreen></iframe>
                </div>
            <?php endif; ?>

            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0">Lesson Content</h5>
                </div>
                <div class="card-body">
                    <div class="card-text"><?php echo nl2br(htmlspecialchars($lesson['content'])); ?></div>
                </div>
            </div>

            <?php if (!empty($attachments)): ?>
                <div class="card shadow border-0 mb-4">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">Attached Files</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($attachments as $attachment): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-center p-3 border rounded">
                                        <?php
                                        $icon_class = 'fa-file';
                                        if (in_array($attachment['file_type'], ['pdf'])) {
                                            $icon_class = 'fa-file-pdf';
                                        } elseif (in_array($attachment['file_type'], ['doc', 'docx'])) {
                                            $icon_class = 'fa-file-word';
                                        } elseif (in_array($attachment['file_type'], ['ppt', 'pptx'])) {
                                            $icon_class = 'fa-file-powerpoint';
                                        } elseif (in_array($attachment['file_type'], ['mp4'])) {
                                            $icon_class = 'fa-file-video';
                                        } elseif (in_array($attachment['file_type'], ['xlsx'])) {
                                            $icon_class = 'fa-file-excel';
                                        } elseif (in_array($attachment['file_type'], ['zip'])) {
                                            $icon_class = 'fa-file-archive';
                                        }
                                        ?>
                                        <i class="fas <?php echo $icon_class; ?> fa-2x me-3" style="color: #2563eb;"></i>
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($attachment['file_name']); ?></h6>
                                            <small class="text-muted">
                                                <?php echo strtoupper($attachment['file_type']); ?> • 
                                                <?php echo number_format($attachment['file_size'] / 1024 / 1024, 2); ?> MB
                                            </small>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="button" onclick="openLmsFileViewer('../<?php echo htmlspecialchars($attachment['file_path']); ?>', '<?php echo addslashes($attachment['file_name']); ?>', '<?php echo htmlspecialchars($attachment['file_type']); ?>'); return false;" class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye me-1"></i> View
                                            </button>
                                            <a href="../<?php echo htmlspecialchars($attachment['file_path']); ?>" class="btn btn-outline-primary btn-sm" download>
                                                <i class="fas fa-download me-1"></i> Download
                                            </a>
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
            <form method="POST" action="delete.php">
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
