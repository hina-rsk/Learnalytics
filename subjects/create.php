<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor' && $_SESSION['user_role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Fetch programs, academic years, and sections
$programs = $pdo->query("SELECT * FROM programs ORDER BY name")->fetchAll();
$academic_years = $pdo->query("SELECT * FROM academic_years ORDER BY start_date DESC")->fetchAll();
$semesters = $pdo->query("SELECT sem.semester_id, sem.semester_name, sem.academic_year_id, ay.name AS academic_year_name FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id WHERE sem.status = 'active' AND sem.end_date >= CURDATE() ORDER BY ay.start_date DESC, sem.start_date")->fetchAll();

// Pre-select section from URL
$pre_selected_section = $_GET['section_id'] ?? null;
if ($pre_selected_section) {
    $stmt = $pdo->prepare("SELECT program_id FROM sections WHERE id = ?");
    $stmt->execute([$pre_selected_section]);
    $pre_selected_program = $stmt->fetchColumn();
}

// Generate unique class code
function generateClassCode($pdo) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[rand(0, strlen($chars) - 1)];
        }
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE class_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

// Handle subject creation
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_subject'])) {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $instructor_id = ($role === 'instructor') ? $user_id : $_POST['instructor_id'];
    $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
    $section_id = !empty($_POST['section_id']) ? $_POST['section_id'] : null;
    $semester_id = (int)($_POST['semester_id'] ?? 0);
    $class_code = generateClassCode($pdo);

    $department_id = null;
    if ($program_id) {
        $departmentStmt = $pdo->prepare("SELECT p.department_id FROM programs p JOIN departments d ON d.id = p.department_id WHERE p.id = ? AND p.status = 'active' AND d.status = 'active'");
        $departmentStmt->execute([$program_id]);
        $department_id = $departmentStmt->fetchColumn() ?: null;
    }
    if (!$department_id) {
        $error = 'Select a program that belongs to an active department.';
    }

    $semesterStmt = $pdo->prepare("SELECT semester_id, academic_year_id FROM semesters WHERE semester_id = ? AND status = 'active' AND end_date >= CURDATE()");
    $semesterStmt->execute([$semester_id]);
    $selectedSemester = $semesterStmt->fetch();
    if (!$selectedSemester) {
        $error = 'Select an active semester.';
    } elseif ($section_id) {
        $sectionStmt = $pdo->prepare('SELECT id FROM sections WHERE id = ? AND program_id = ? AND academic_year_id = ? AND (semester_id IS NULL OR semester_id = ?)');
        $sectionStmt->execute([(int)$section_id, (int)$program_id, (int)$selectedSemester['academic_year_id'], $semester_id]);
        if (!$sectionStmt->fetchColumn()) {
            $error = 'The selected section must belong to this program and semester academic year.';
        }
    }

    // Validate instructor exists
    if (empty($instructor_id) || !is_numeric($instructor_id)) {
        $error = 'Please select a valid instructor.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([(int)$instructor_id]);
        if (!$stmt->fetch()) {
            $error = 'Selected instructor does not exist.';
        }
    }

    if (!$error) {
        $stmt = $pdo->prepare("INSERT INTO subjects (title, description, instructor_id, program_id, department_id, section_id, semester_id, class_code, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
        $stmt->execute([$title, $description, $instructor_id, $program_id, $department_id, $section_id, $semester_id, $class_code]);
        
        header("Location: index.php");
        exit;
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">Create New Subject</h1>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow border-0">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0">Subject Information</h5>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Subject Title</label>
                            <input type="text" name="title" class="form-control" required style="border-color: #2563eb;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Description</label>
                            <textarea name="description" class="form-control" rows="5" required style="border-color: #2563eb;"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Program (Optional)</label>
                            <select name="program_id" id="program_id" class="form-select" style="border-color: #2563eb;">
                                <option value="">Select a Program</option>
                                <?php foreach ($programs as $prog): ?>
                                    <option value="<?php echo $prog['id']; ?>" <?= (isset($pre_selected_program) && $pre_selected_program == $prog['id']) ? 'selected' : '' ?>><?php echo htmlspecialchars($prog['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Section (Optional)</label>
                            <select name="section_id" id="section_id" class="form-select" style="border-color: #2563eb;">
                                <option value="">Select a Section</option>
                                <?php if ($pre_selected_section): ?>
                                    <option value="<?= $pre_selected_section ?>" selected>Section ID: <?= $pre_selected_section ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Semester <span class="text-danger">*</span></label>
                            <select name="semester_id" class="form-select" required style="border-color: #2563eb;">
                                <option value="">Select an active semester</option>
                                <?php foreach ($semesters as $semester): ?>
                                    <option value="<?php echo (int)$semester['semester_id']; ?>" <?php echo (int)($_POST['semester_id'] ?? 0) === (int)$semester['semester_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($semester['semester_name'] . ' · ' . $semester['academic_year_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$semesters): ?><div class="form-text text-danger">No active semesters are available. Ask the Dean to create or activate a semester.</div><?php endif; ?>
                        </div>
                        <?php if ($role === 'admin'): ?>
                            <div class="mb-3">
                                <label class="form-label" style="color: #1e3a8a;">Instructor <span style="color: red;">*</span></label>
                                <?php
                                $instructors = $pdo->query("SELECT id, name FROM users WHERE role = 'instructor' ORDER BY name")->fetchAll();
                                if (empty($instructors)):
                                ?>
                                    <div class="alert alert-warning" role="alert">
                                        No instructors available. <a href="../admin/users.php">Create an instructor</a> first.
                                    </div>
                                    <select name="instructor_id" class="form-select" disabled style="border-color: #2563eb;">
                                        <option value="">No instructors available</option>
                                    </select>
                                <?php else: ?>
                                    <select name="instructor_id" class="form-select" required style="border-color: #2563eb;">
                                        <option value="">Select an Instructor</option>
                                        <?php foreach ($instructors as $instr): ?>
                                            <option value="<?php echo $instr['id']; ?>"><?php echo htmlspecialchars($instr['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex gap-2">
                            <button type="submit" name="create_subject" class="btn" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none; font-weight: 600;">Create Subject</button>
                            <a href="index.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>

<script>
document.getElementById('program_id').addEventListener('change', function() {
    const programId = this.value;
    const sectionSelect = document.getElementById('section_id');
    
    if (programId) {
        sectionSelect.innerHTML = '<option value="">Select a Section</option>';
        
        fetch('get_sections.php?program_id=' + programId)
            .then(response => response.json())
            .then(data => {
                sectionSelect.innerHTML = '<option value="">Select a Section</option>';
                data.forEach(section => {
                    const option = document.createElement('option');
                    option.value = section.id;
                    option.textContent = section.name + ' (' + section.year_name + ')';
                    sectionSelect.appendChild(option);
                });
            })
            .catch(error => {
                sectionSelect.innerHTML = '<option value="">Select a Section</option>';
            });
    } else {
        sectionSelect.innerHTML = '<option value="">Select a Section</option>';
    }
});
</script>
