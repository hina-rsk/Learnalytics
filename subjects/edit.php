<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$subject_id = (int)($_GET['id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'];
$error = '';
$success = '';

// Fetch subject
$stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ?");
$stmt->execute([$subject_id]);
$subject = $stmt->fetch();

if (!$subject) {
    die("Subject not found.");
}

// Permissions check
if ($role === 'instructor' && (int)$subject['instructor_id'] !== $user_id) {
    http_response_code(403);
    exit("You do not have permission to edit this subject.");
}

if ($role === 'dean') {
    $stmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ? AND role = 'dean'");
    $stmt->execute([$user_id]);
    $deanDepartmentId = (int)$stmt->fetchColumn();
    if ($deanDepartmentId < 1 || (int)$subject['department_id'] !== $deanDepartmentId) {
        http_response_code(403);
        exit('You can only edit subjects belonging to your department.');
    }
}

// Fetch programs and academic years
$programs = $pdo->query("SELECT * FROM programs WHERE status = 'active' ORDER BY name")->fetchAll();

// Fetch sections for current program if set
$sections = [];
if (!empty($subject['program_id'])) {
    $stmt = $pdo->prepare("SELECT s.id, s.name, ay.name as year_name FROM sections s LEFT JOIN academic_years ay ON s.academic_year_id = ay.id WHERE s.program_id = ? ORDER BY s.name");
    $stmt->execute([$subject['program_id']]);
    $sections = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_subject'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
    $section_id = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
    $instructor_id = ($role === 'admin' && !empty($_POST['instructor_id'])) ? (int)$_POST['instructor_id'] : $subject['instructor_id'];

    $department_id = $subject['department_id'];
    if ($program_id && $program_id !== (int)$subject['program_id']) {
        $deptStmt = $pdo->prepare("SELECT department_id FROM programs WHERE id = ?");
        $deptStmt->execute([$program_id]);
        $department_id = $deptStmt->fetchColumn() ?: $department_id;
    }

    if (empty($title)) {
        $error = 'Please enter a subject title.';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE subjects SET title = ?, description = ?, status = ?, program_id = ?, department_id = ?, section_id = ?, instructor_id = ? WHERE id = ?");
            $stmt->execute([$title, $description, $status, $program_id, $department_id, $section_id, $instructor_id, $subject_id]);
            
            header("Location: view.php?id=" . $subject_id);
            exit;
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Subject - LearnAlytics';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;"><i class="fas fa-edit me-2" style="color: #2563eb;"></i>Edit Subject</h1>
        <a href="view.php?id=<?php echo $subject_id; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Subject
        </a>
    </div>

    <div class="row">
        <div class="col-lg-8 col-md-10 mx-auto">
            <div class="card shadow border-0 rounded-3">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0"><i class="fas fa-book me-2"></i>Subject Details</h5>
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label font-weight-bold" style="color: #1e3a8a;">Subject Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required style="border-color: #2563eb;" value="<?php echo htmlspecialchars($_POST['title'] ?? $subject['title']); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold" style="color: #1e3a8a;">Class Code</label>
                            <input type="text" class="form-control bg-light" readonly value="<?php echo htmlspecialchars($subject['class_code'] ?? 'N/A'); ?>">
                            <small class="text-muted">Class code is automatically generated and cannot be changed.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold" style="color: #1e3a8a;">Description</label>
                            <textarea name="description" class="form-control" rows="5" style="border-color: #2563eb;"><?php echo htmlspecialchars($_POST['description'] ?? $subject['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label font-weight-bold" style="color: #1e3a8a;">Status</label>
                                <select name="status" class="form-select" style="border-color: #2563eb;">
                                    <option value="active" <?php echo ($subject['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="draft" <?php echo ($subject['status'] === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                    <option value="archived" <?php echo ($subject['status'] === 'archived') ? 'selected' : ''; ?>>Archived</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label font-weight-bold" style="color: #1e3a8a;">Program</label>
                                <select name="program_id" id="program_id" class="form-select" style="border-color: #2563eb;">
                                    <option value="">Select Program...</option>
                                    <?php foreach ($programs as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo ((int)$subject['program_id'] === (int)$p['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label font-weight-bold" style="color: #1e3a8a;">Section</label>
                            <select name="section_id" id="section_id" class="form-select" style="border-color: #2563eb;">
                                <option value="">Select Section...</option>
                                <?php foreach ($sections as $sec): ?>
                                    <option value="<?php echo $sec['id']; ?>" <?php echo ((int)$subject['section_id'] === (int)$sec['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sec['name']); ?> <?php echo !empty($sec['year_name']) ? '(' . htmlspecialchars($sec['year_name']) . ')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($role === 'admin'): ?>
                            <div class="mb-4">
                                <label class="form-label font-weight-bold" style="color: #1e3a8a;">Instructor</label>
                                <?php
                                $instructors = $pdo->query("SELECT id, name FROM users WHERE role = 'instructor' ORDER BY name")->fetchAll();
                                ?>
                                <select name="instructor_id" class="form-select" style="border-color: #2563eb;">
                                    <option value="">Select Instructor...</option>
                                    <?php foreach ($instructors as $instr): ?>
                                        <option value="<?php echo $instr['id']; ?>" <?php echo ((int)$subject['instructor_id'] === (int)$instr['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($instr['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <a href="view.php?id=<?php echo $subject_id; ?>" class="btn btn-light border px-4">Cancel</a>
                            <button type="submit" name="update_subject" class="btn btn-primary px-4 fw-bold" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); border: none;">
                                <i class="fas fa-save me-1"></i> Save Subject
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.getElementById('program_id').addEventListener('change', function() {
    const programId = this.value;
    const sectionSelect = document.getElementById('section_id');
    
    if (programId) {
        sectionSelect.innerHTML = '<option value="">Select Section...</option>';
        fetch('get_sections.php?program_id=' + programId)
            .then(response => response.json())
            .then(data => {
                sectionSelect.innerHTML = '<option value="">Select Section...</option>';
                data.forEach(section => {
                    const option = document.createElement('option');
                    option.value = section.id;
                    option.textContent = section.name + (section.year_name ? ' (' + section.year_name + ')' : '');
                    sectionSelect.appendChild(option);
                });
            })
            .catch(error => {
                sectionSelect.innerHTML = '<option value="">Select Section...</option>';
            });
    } else {
        sectionSelect.innerHTML = '<option value="">Select Section...</option>';
    }
});
</script>

<?php include '../includes/footer.php'; ?>
