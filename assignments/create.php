<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'instructor' && $_SESSION['user_role'] !== 'admin')) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'];
$error = '';
$success = '';

$preselected_subject_id = (int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);

// Fetch instructor's subjects
if ($role === 'instructor') {
    $stmt = $pdo->prepare("SELECT id, title, class_code FROM subjects WHERE instructor_id = ? ORDER BY title ASC");
    $stmt->execute([$user_id]);
} else {
    $stmt = $pdo->query("SELECT id, title, class_code FROM subjects ORDER BY title ASC");
}
$subjects = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_assignment'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;
    $points = (int)($_POST['points'] ?? 100);
    $status = $_POST['status'] ?? 'active';
    $file_path = NULL;

    if (empty($title)) {
        $error = 'Please enter an assignment title.';
    } elseif ($subject_id <= 0) {
        $error = 'Please select a subject for this assignment.';
    } else {
        // Verify ownership if instructor
        if ($role === 'instructor') {
            $checkStmt = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND instructor_id = ?");
            $checkStmt->execute([$subject_id, $user_id]);
            if (!$checkStmt->fetch()) {
                $error = 'You do not have permission to add assignments to this subject.';
            }
        }

        if (!$error && !empty($_FILES['assignment_file']['name'])) {
            $allowed_ext = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'zip', 'txt', 'xlsx'];
            $file_name = $_FILES['assignment_file']['name'];
            $file_tmp = $_FILES['assignment_file']['tmp_name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if (!in_array($file_ext, $allowed_ext)) {
                $error = 'Invalid file type. Allowed extensions: ' . implode(', ', $allowed_ext);
            } else {
                $upload_dir = '../uploads/assignments/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $new_file_name = time() . '_' . uniqid() . '.' . $file_ext;
                $destination = $upload_dir . $new_file_name;
                if (move_uploaded_file($file_tmp, $destination)) {
                    $file_path = 'uploads/assignments/' . $new_file_name;
                } else {
                    $error = 'Failed to upload attached file.';
                }
            }
        }

        if (!$error) {
            try {
                $stmt = $pdo->prepare("INSERT INTO assignments (subject_id, title, description, points, status, file_path, due_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$subject_id, $title, $description, $points, $status, $file_path, $due_date]);
                
                header("Location: ../subjects/view.php?id=" . $subject_id . "&msg=assignment_created");
                exit;
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Create Assignment';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;"><i class="fas fa-plus-circle me-2" style="color: #2563eb;"></i>Create New Assignment</h1>
        <a href="<?php echo $preselected_subject_id > 0 ? '../subjects/view.php?id=' . $preselected_subject_id : 'index.php'; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>

    <div class="row">
        <div class="col-lg-8 col-md-10 mx-auto">
            <div class="card shadow border-0 rounded-3">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0"><i class="fas fa-file-signature me-2"></i>Assignment Information</h5>
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label font-weight-bold" style="color: #1e3a8a;">Subject / Class <span class="text-danger">*</span></label>
                            <select name="subject_id" class="form-select" required style="border-color: #2563eb;">
                                <option value="">Select a subject...</option>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo $preselected_subject_id === (int)$s['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['title']); ?> (<?php echo htmlspecialchars($s['class_code'] ?? 'No Code'); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold" style="color: #1e3a8a;">Assignment Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" placeholder="e.g. Essay on Software Design Patterns" required style="border-color: #2563eb;" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold" style="color: #1e3a8a;">Instructions / Description</label>
                            <textarea name="description" class="form-control" rows="5" placeholder="Detailed instructions for students..." style="border-color: #2563eb;"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label font-weight-bold" style="color: #1e3a8a;">Due Date & Time</label>
                                <input type="datetime-local" name="due_date" class="form-control" style="border-color: #2563eb;" value="<?php echo htmlspecialchars($_POST['due_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label font-weight-bold" style="color: #1e3a8a;">Total Points</label>
                                <input type="number" name="points" class="form-control" min="1" max="1000" value="<?php echo (int)($_POST['points'] ?? 100); ?>" style="border-color: #2563eb;">
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label font-weight-bold" style="color: #1e3a8a;">Status</label>
                                <select name="status" class="form-select" style="border-color: #2563eb;">
                                    <option value="active" selected>Active</option>
                                    <option value="draft">Draft</option>
                                    <option value="archived">Archived</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label font-weight-bold" style="color: #1e3a8a;">Attachment (Optional)</label>
                                <input type="file" name="assignment_file" class="form-control" style="border-color: #2563eb;">
                                <small class="text-muted">Accepted: PDF, DOCX, PPTX, ZIP, XLSX (Max 25MB)</small>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="<?php echo $preselected_subject_id > 0 ? '../subjects/view.php?id=' . $preselected_subject_id : 'index.php'; ?>" class="btn btn-light border px-4">Cancel</a>
                            <button type="submit" name="create_assignment" class="btn btn-primary px-4 fw-bold" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); border: none;">
                                <i class="fas fa-check me-1"></i> Save Assignment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
