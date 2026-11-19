<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'instructor' && $_SESSION['user_role'] !== 'admin')) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];
$error = '';
$lesson_id = $_GET['id'] ?? null;
if (!$lesson_id) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT l.*, c.instructor_id, c.title as subject_title FROM lessons l JOIN subjects c ON l.subject_id = c.id WHERE l.id = ?");
$stmt->execute([$lesson_id]);
$lesson = $stmt->fetch();

if (!$lesson) {
    die('Lesson not found.');
}

if ($role === 'instructor' && $lesson['instructor_id'] != $user_id) {
    die('Unauthorized access to edit this lesson.');
}

if ($role === 'instructor') {
    $stmt = $pdo->prepare("SELECT id, title FROM subjects WHERE instructor_id = ? ORDER BY title");
    $stmt->execute([$user_id]);
} else {
    $stmt = $pdo->query("SELECT id, title FROM subjects ORDER BY title");
}
$subjects = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_lesson'])) {
    $title = trim($_POST['title']);
    $topic = !empty(trim($_POST['topic'] ?? '')) ? trim($_POST['topic']) : NULL;
    $content = trim($_POST['content']);
    $subject_id = $_POST['subject_id'];
    $video_url = $_POST['video_url'] ?? null;
    $file_paths = [];
    
    if (!empty($_FILES['lesson_files']['name'][0])) {
        $allowed_extensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'mp4', 'txt', 'xlsx', 'zip'];
        $upload_dir = '../uploads/lessons/';
        $max_file_size = 50 * 1024 * 1024;

        foreach ($_FILES['lesson_files']['name'] as $key => $file_name) {
            if (empty($file_name)) {
                continue;
            }
            $file_tmp = $_FILES['lesson_files']['tmp_name'][$key];
            $file_error = $_FILES['lesson_files']['error'][$key];
            $file_size = $_FILES['lesson_files']['size'][$key];
            
            if ($file_error === UPLOAD_ERR_OK) {
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                if (!in_array($file_ext, $allowed_extensions)) {
                    $error = 'Invalid file type for "' . $file_name . '". Allowed types: ' . implode(', ', $allowed_extensions);
                    break;
                } elseif ($file_size > $max_file_size) {
                    $error = 'File "' . $file_name . '" exceeds 50MB limit.';
                    break;
                } else {
                    $unique_name = time() . '_' . uniqid() . '_' . $key . '.' . $file_ext;
                    $upload_path = $upload_dir . $unique_name;
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        $file_paths[] = [
                            'original_name' => $file_name,
                            'path' => 'uploads/lessons/' . $unique_name,
                            'size' => $file_size,
                            'type' => $file_ext,
                        ];
                    } else {
                        $error = 'Failed to upload file "' . $file_name . '". Please try again.';
                        break;
                    }
                }
            } elseif ($file_error !== UPLOAD_ERR_NO_FILE) {
                $error = 'Upload error for file "' . $file_name . '": ' . $file_error;
                break;
            }
        }
    }

    if (!$error) {
        try {
            $stmt = $pdo->prepare("UPDATE lessons SET subject_id = ?, title = ?, topic = ?, content = ?, video_url = ? WHERE id = ?");
            $stmt->execute([$subject_id, $title, $topic, $content, $video_url, $lesson_id]);

            if (!empty($file_paths)) {
                $attachmentStmt = $pdo->prepare("INSERT INTO lesson_attachments (lesson_id, file_name, file_path, file_size, file_type) VALUES (?, ?, ?, ?, ?)");
                foreach ($file_paths as $file) {
                    $attachmentStmt->execute([$lesson_id, $file['original_name'], $file['path'], $file['size'], $file['type']]);
                }
            }

            header('Location: view.php?id=' . $lesson_id);
            exit;
        } catch (PDOException $e) {
            $error = 'Error updating lesson: ' . $e->getMessage();
        }
    }
}

// Fetch existing topics for suggestions
$existing_topics = $pdo->query("SELECT DISTINCT topic FROM lessons WHERE topic IS NOT NULL AND topic != '' ORDER BY topic ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">Edit Lesson</h1>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow border-0">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0">Lesson Details</h5>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Select Subject</label>
                            <select name="subject_id" class="form-select" required style="border-color: #2563eb;">
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo $lesson['subject_id'] == $subject['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($subject['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Lesson Title</label>
                            <input type="text" name="title" value="<?php echo htmlspecialchars($lesson['title']); ?>" class="form-control" required style="border-color: #2563eb;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;"><i class="fas fa-folder me-1" style="color: #2563eb;"></i> Topic (Optional)</label>
                            <input type="text" name="topic" value="<?php echo htmlspecialchars($lesson['topic'] ?? ''); ?>" list="existingTopics" class="form-control" placeholder="e.g. Module 1: Web Development Fundamentals" style="border-color: #2563eb;">
                            <datalist id="existingTopics">
                                <?php foreach ($existing_topics as $t): ?>
                                    <option value="<?php echo htmlspecialchars($t['topic']); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="form-text">Type a new topic or select an existing topic to organize this lesson.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Content</label>
                            <textarea name="content" class="form-control" rows="8" required style="border-color: #2563eb;"><?php echo htmlspecialchars($lesson['content']); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Video URL (Optional)</label>
                            <input type="url" name="video_url" value="<?php echo htmlspecialchars($lesson['video_url']); ?>" class="form-control" placeholder="https://youtu.be/..." style="border-color: #2563eb;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Add Files (Optional)</label>
                            <input type="file" name="lesson_files[]" class="form-control" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.mp4,.txt,.xlsx,.zip" style="border-color: #2563eb;">
                            <div class="form-text">Attach additional files to this lesson. Allowed formats: PDF, DOC, DOCX, PPT, PPTX, MP4, TXT, XLSX, ZIP.</div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" name="update_lesson" class="btn" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none; font-weight: 600;">Update Lesson</button>
                            <a href="view.php?id=<?php echo $lesson_id; ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>