<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

// Basic initializations to avoid undefined variable warnings
$message = '';
$error = '';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
$userRole = $_SESSION['user_role'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

// Create department
if ($requestMethod === 'POST' && isset($_POST['create_department'])) {
    if ($userRole !== 'admin') {
        $error = 'Unauthorized access';
    } else {
        $dept_name = trim($_POST['department_name'] ?? '');
        $dept_desc = trim($_POST['department_description'] ?? '');

        if (empty($dept_name)) {
            $error = 'Department name is required';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO departments (name, description) VALUES (?, ?)");
                $stmt->execute([$dept_name, $dept_desc]);
                $message = 'Department created successfully!';
            } catch (PDOException $e) {
                $error = 'Error creating department: ' . $e->getMessage();
            }
        }
    }
}

// Delete department
if ($requestMethod === 'POST' && isset($_POST['delete_department'])) {
    if ($userRole !== 'admin') {
        $error = 'Unauthorized access';
    } else {
        $dept_id = (int)($_POST['department_id'] ?? 0);
        if ($dept_id <= 0) {
            $error = 'Invalid department selected';
        } else {
            try {
                    // Check whether programs/subjects have department_id column in this DB
                    $progHasDept = (bool)$pdo->query("SHOW COLUMNS FROM programs LIKE 'department_id'")->fetch();
                    $subjHasDept = (bool)$pdo->query("SHOW COLUMNS FROM subjects LIKE 'department_id'")->fetch();

                    $progCount = 0;
                    $subjCount = 0;

                    if ($progHasDept) {
                        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM programs WHERE department_id = ?");
                        $stmt->execute([$dept_id]);
                        $progCount = (int)$stmt->fetchColumn();
                    }

                    if ($subjHasDept) {
                        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM subjects WHERE department_id = ?");
                        $stmt->execute([$dept_id]);
                        $subjCount = (int)$stmt->fetchColumn();
                    }

                    if ($progCount > 0 || $subjCount > 0) {
                        $error = 'Cannot delete department: it has ' . $progCount . ' programs and ' . $subjCount . ' subjects linked. Remove or reassign them first.';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
                        $stmt->execute([$dept_id]);
                        $message = 'Department deleted successfully.';
                        // Refresh departments array after delete
                        $departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll() ?: [];
                    }
                } catch (PDOException $e) {
                    $error = 'Error deleting department: ' . $e->getMessage();
                }
        }
    }
}

// Create program
if ($requestMethod === 'POST' && isset($_POST['create_program'])) {
    if ($userRole !== 'admin') {
        $error = 'Unauthorized access';
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);

        if (empty($name)) {
            $error = 'Program name is required';
        } elseif ($department_id < 1) {
            $error = 'A department is required for every program.';
        } else {
            try {
                $departmentCheck = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND status = 'active'");
                $departmentCheck->execute([$department_id]);
                if (!$departmentCheck->fetchColumn()) {
                    throw new RuntimeException('Select an active department.');
                }
                $stmt = $pdo->prepare("INSERT INTO programs (name, description, department_id) VALUES (?, ?, ?)");
                $stmt->execute([$name, $description, $department_id]);
                $message = 'Program created successfully!';
            } catch (Throwable $e) {
                $error = 'Error creating program: ' . $e->getMessage();
            }
        }
    }
}

// Assign an existing program and its linked records to an active department.
if ($requestMethod === 'POST' && isset($_POST['update_program_department'])) {
    if ($userRole !== 'admin') {
        $error = 'Unauthorized access';
    } else {
        $program_id = (int)($_POST['program_id'] ?? 0);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $departmentCheck = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND status = 'active'");
        $departmentCheck->execute([$department_id]);
        if ($program_id < 1 || !$departmentCheck->fetchColumn()) {
            $error = 'Select a valid active department.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE programs SET department_id = ? WHERE id = ?');
                $stmt->execute([$department_id, $program_id]);
                $stmt = $pdo->prepare('UPDATE subjects SET department_id = ? WHERE program_id = ?');
                $stmt->execute([$department_id, $program_id]);
                $stmt = $pdo->prepare('UPDATE student_profiles SET department_id = ? WHERE program_id = ?');
                $stmt->execute([$department_id, $program_id]);
                $pdo->commit();
                $message = 'Program department updated successfully.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Error updating program department: ' . $e->getMessage();
            }
        }
    }
}
// Create academic year
if ($requestMethod === 'POST' && isset($_POST['create_year'])) {
    if ($userRole !== 'admin') {
        $error = 'Unauthorized access';
    } else {
        header('Location: ../academic_year_management.php?add=1');
        exit;
    }
}

// Create section
if ($requestMethod === 'POST' && isset($_POST['create_section'])) {
    $program_id = $_POST['program_id'] ?? '';
    $academic_year_id = $_POST['academic_year_id'] ?? '';
    $semester_id = (int)($_POST['semester_id'] ?? 0);
    $section_name = trim($_POST['section_name'] ?? '');
    
    if (empty($program_id) || empty($academic_year_id) || $semester_id < 1 || empty($section_name)) {
        $error = 'Program, academic year, semester, and section name are required.';
    } else {
        $semesterCheck = $pdo->prepare("SELECT semester_id FROM semesters WHERE semester_id = ? AND academic_year_id = ? AND status = 'active' AND end_date >= CURDATE()");
        $semesterCheck->execute([$semester_id, (int)$academic_year_id]);
        if (!$semesterCheck->fetchColumn()) {
            $error = 'Choose an active semester belonging to the selected academic year.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO sections (program_id, academic_year_id, semester_id, name) VALUES (?, ?, ?, ?)");
                $stmt->execute([$program_id, $academic_year_id, $semester_id, $section_name]);
                $message = 'Section created successfully!';
            } catch (PDOException $e) {
                $error = 'Error creating section: ' . $e->getMessage();
            }
        }
    }
}

// Handle subject creation


// Fetch data
// Fetch data (ensure arrays to safely iterate in templates)
$programs = $pdo->query("SELECT * FROM programs ORDER BY name")->fetchAll() ?: [];
$academic_years = $pdo->query("SELECT * FROM academic_years ORDER BY start_date DESC")->fetchAll() ?: [];
$semesters = $pdo->query("SELECT sem.semester_id, sem.semester_name, sem.academic_year_id, ay.name AS academic_year_name FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id WHERE sem.status = 'active' AND sem.end_date >= CURDATE() ORDER BY ay.start_date DESC, sem.start_date")->fetchAll() ?: [];
$sections = $pdo->query("
    SELECT s.*, p.name as program_name, ay.name as year_name, sem.semester_name
    FROM sections s 
    JOIN programs p ON s.program_id = p.id 
    JOIN academic_years ay ON s.academic_year_id = ay.id
    LEFT JOIN semesters sem ON sem.semester_id = s.semester_id
    ORDER BY s.id DESC
")->fetchAll() ?: [];

// Fetch departments
// departments table may be empty; ensure array
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll() ?: [];
$departmentsById = array_column($departments, 'name', 'id');
$activeDepartments = $pdo->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name")->fetchAll() ?: [];

// Fetch instructors if admin
$instructors = [];
if ($userRole === 'admin') {
    $instructors = $pdo->query("SELECT id, name FROM users WHERE role = 'instructor' ORDER BY name")->fetchAll() ?: [];
}
?>

<div class="container">
    <h1>Programs & Courses Management</h1>
    
    <div class="mb-3">
        <a href="view.php" class="btn" style="background: #10b981; color: white;" target="_blank">
            <i class="fas fa-eye me-1"></i> View Public Page
        </a>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="dashboard-grid">
        <div class="card">
            <h2>Create Program</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Program Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g., Computer Science" required>
                </div>
                <div class="form-group">
                    <label>Department <span class="text-danger">*</span></label>
                    <select name="department_id" class="form-control" required>
                        <option value="">Select active department</option>
                        <?php foreach ($activeDepartments as $dept): ?>
                        <option value="<?= (int)$dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Program description..."></textarea>
                </div>
                <button type="submit" name="create_program" class="btn btn-primary">Create Program</button>
            </form>
        </div>

        <?php if ($userRole === 'admin'): ?>
            <div class="card">
                <h2>Academic Years</h2>
                <p>Manage academic year dates, status, and overlap validation.</p>
                <a href="../academic_year_management.php" class="btn btn-primary">Open Academic Year Management</a>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Create Section</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Program</label>
                    <select name="program_id" class="form-control" required>
                        <option value="">Select Program</option>
                        <?php foreach ($programs as $program): ?>
                        <option value="<?= $program['id'] ?>"><?= htmlspecialchars($program['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Academic Year</label>
                    <select name="academic_year_id" class="form-control" required>
                        <option value="">Select Academic Year</option>
                        <?php foreach ($academic_years as $year): ?>
                        <option value="<?= $year['id'] ?>"><?= htmlspecialchars($year['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Semester</label>
                    <select name="semester_id" class="form-control" required>
                        <option value="">Select Semester</option>
                        <?php foreach ($semesters as $semester): ?>
                        <option value="<?= (int)$semester['semester_id'] ?>" data-academic-year="<?= (int)$semester['academic_year_id'] ?>">
                            <?= htmlspecialchars($semester['semester_name'] . ' · ' . $semester['academic_year_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Section Name</label>
                    <input type="text" name="section_name" class="form-control" placeholder="e.g., Section A" required>
                </div>
                <button type="submit" name="create_section" class="btn btn-primary">Create Section</button>
            </form>
        </div>

        <div class="card">
            <h2>Subjects</h2>
            <p>Manage subjects in the dedicated subject management pages to avoid duplication.</p>
            <a href="../subjects/create.php" class="btn btn-warning">Create Subject</a>
            <a href="../subjects/index.php" class="btn btn-outline-primary">Manage Subjects</a>
        </div>

        <div class="card">
            <h2>Create Department</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Department Name</label>
                    <input type="text" name="department_name" class="form-control" placeholder="e.g., Computer Science Department" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="department_description" class="form-control" rows="3" placeholder="Department description..."></textarea>
                </div>
                <button type="submit" name="create_department" class="btn btn-primary">Create Department</button>
            </form>
        </div>

        <div class="card">
            <h2>Existing Departments</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($departments as $dept): ?>
                            <tr>
                                <td><?= htmlspecialchars($dept['name']) ?></td>
                                <td><?= htmlspecialchars($dept['description'] ?? '-') ?></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('Delete this department? This cannot be undone.');" style="display:inline">
                                        <input type="hidden" name="department_id" value="<?= (int)$dept['id'] ?>">
                                        <button type="submit" name="delete_department" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Existing Items Tables -->
        <div class="card">
            <h2>Existing Programs</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($programs as $program): ?>
                    <tr>
                        <td><?= htmlspecialchars($program['name']) ?></td>
                        <td><?= htmlspecialchars($program['description'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($program['department_id'] ? ($departmentsById[$program['department_id']] ?? 'Unknown department') : 'Unassigned') ?></td>
                        <td><span class="badge badge-<?= $program['status'] ?>"><?= htmlspecialchars($program['status']) ?></span></td>
                        <td>
                            <form method="POST" class="d-flex gap-2 align-items-center">
                                <input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
                                <select name="department_id" class="form-select form-select-sm" required aria-label="Department for <?= htmlspecialchars($program['name']) ?>">
                                    <option value="">Select department</option>
                                    <?php foreach ($activeDepartments as $dept): ?>
                                        <option value="<?= (int)$dept['id'] ?>" <?= (int)$program['department_id'] === (int)$dept['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dept['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="update_program_department" class="btn btn-sm btn-primary">Save</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Existing Academic Years</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Period</th>
                        <th>Current</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($academic_years as $year): ?>
                    <tr>
                        <td><?= htmlspecialchars($year['name']) ?></td>
                        <td><?= htmlspecialchars($year['start_date'] . ' to ' . $year['end_date']) ?></td>
                        <td><?= $year['is_current'] ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-secondary">No</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Existing Sections</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Program</th>
                        <th>Academic Year</th>
                        <th>Semester</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sections as $section): ?>
                    <tr>
                        <td><?= htmlspecialchars($section['name']) ?></td>
                        <td><?= htmlspecialchars($section['program_name']) ?></td>
                        <td><?= htmlspecialchars($section['year_name']) ?></td>
                        <td><?= htmlspecialchars($section['semester_name'] ?? 'Unassigned') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Existing Subjects</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Semester</th>
                        <th>Description</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $subjects = $pdo->query("SELECT sub.*, COALESCE(sub.semester, sem.semester_name) AS semester_name, COALESCE(class_year.name, ay.name) AS semester_year_name FROM subjects sub LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN academic_years ay ON ay.id = sem.academic_year_id LEFT JOIN academic_years class_year ON class_year.id = sub.academic_year_id ORDER BY sub.id DESC LIMIT 10")->fetchAll();
                    foreach ($subjects as $subject): ?>
                    <tr>
                        <td><?= htmlspecialchars($subject['title']) ?></td>
                        <td><?= htmlspecialchars($subject['semester_name'] ? $subject['semester_name'] . ' · ' . $subject['semester_year_name'] : 'Unassigned') ?></td>
                        <td><?= htmlspecialchars($subject['description'] ?? '-') ?></td>
                        <td><span class="badge badge-<?= $subject['status'] ?>"><?= htmlspecialchars($subject['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.card {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.card h2 {
    margin-top: 0;
    color: #333;
    border-bottom: 2px solid #007bff;
    padding-bottom: 10px;
}
.card h3 {
    margin-top: 30px;
    font-size: 1.1em;
    color: #555;
}
.form-group {
    margin-bottom: 15px;
}
.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}
.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}
.form-row {
    display: flex;
    gap: 15px;
}
.form-row .form-group {
    flex: 1;
}
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
}
.btn-primary {
    background: #007bff;
    color: white;
}
.table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}
.table th, .table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}
.table th {
    background: #f8f9fa;
    font-weight: 600;
}
.badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
}
.badge-active { background: #d4edda; color: #155724; }
.badge-inactive { background: #f8d7da; color: #721c24; }
.badge-success { background: #d4edda; color: #155724; }
.badge-secondary { background: #e2e3e5; color: #383d41; }
.alert {
    padding: 12px 20px;
    border-radius: 4px;
    margin-bottom: 20px;
}
.alert-success { background: #d4edda; color: #155724; }
.alert-danger { background: #f8d7da; color: #721c24; }
</style>

<script>
document.getElementById('subject_program_id').addEventListener('change', function() {
    const programId = this.value;
    const sectionSelect = document.getElementById('subject_section_id');
    
    if (programId) {
        sectionSelect.innerHTML = '<option value="">Loading sections...</option>';
        
        fetch('../subjects/get_sections.php?program_id=' + programId)
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>