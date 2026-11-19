<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header('Location: auth/login.php');
    exit;
}

$instructor_id = (int)$_SESSION['user_id'];
$error = '';
$success = '';

// Fetch departments
$departments = $pdo->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name")->fetchAll();

// Handle selected department and pre-populated programs for form re-population on error
$selected_department_id = isset($_POST['department_id']) ? (int)$_POST['department_id'] : 0;
$selected_program_id = isset($_POST['program_id']) ? (int)$_POST['program_id'] : 0;

$programs = [];
if ($selected_department_id > 0) {
    $stmt = $pdo->prepare("SELECT id, name FROM programs WHERE department_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([$selected_department_id]);
    $programs = $stmt->fetchAll();
}

$sections = $pdo->query("SELECT id, name FROM sections ORDER BY name")->fetchAll();
$academicYears = $pdo->query("SELECT id, name, start_date, end_date FROM academic_years WHERE status = 'active' ORDER BY start_date DESC")->fetchAll();
$selectedAcademicYearId = (int)($_POST['academic_year_id'] ?? 0);
if ($selectedAcademicYearId === 0) {
    $today = date('Y-m-d');
    foreach ($academicYears as $academicYear) {
        if ($academicYear['start_date'] <= $today && $academicYear['end_date'] >= $today) {
            $selectedAcademicYearId = (int)$academicYear['id'];
            break;
        }
    }
    if ($selectedAcademicYearId === 0 && $academicYears) {
        $selectedAcademicYearId = (int)$academicYears[0]['id'];
    }
}
$studentsList = $pdo->query("SELECT id, name, email FROM users WHERE role = 'student' AND status = 'active' ORDER BY name")->fetchAll();

function generateClassCode($length = 6) {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) $code .= $chars[random_int(0, strlen($chars)-1)];
    return $code;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $program_id = $_POST['program_id'] ?: null;
    $department_id = (int)($_POST['department_id'] ?? 0);
    $section_id = $_POST['section_id'] ?: null;
    $academic_year_id = (int)($_POST['academic_year_id'] ?? 0);
    $year_level = trim($_POST['year_level'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // schedule
    $days = $_POST['days'] ?? [];
    $start_time = $_POST['start_time'] ?? null;
    $end_time = $_POST['end_time'] ?? null;
    $room = trim($_POST['room'] ?? '');

    $departmentCheck = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND status = 'active'");
    $departmentCheck->execute([$department_id]);
    $departmentValid = (bool)$departmentCheck->fetchColumn();
    $programValid = true;
    if ($program_id) {
        $programCheck = $pdo->prepare("SELECT id FROM programs WHERE id = ? AND department_id = ? AND status = 'active'");
        $programCheck->execute([(int)$program_id, $department_id]);
        $programValid = (bool)$programCheck->fetchColumn();
    }

    $academicYearCheck = $pdo->prepare("SELECT id, name FROM academic_years WHERE id = ? AND status = 'active'");
    $academicYearCheck->execute([$academic_year_id]);
    $selectedAcademicYear = $academicYearCheck->fetch();

    $validSemesters = ['', 'First', 'Second', 'Summer'];
    $semesterValid = in_array($semester, $validSemesters, true);
    $sectionValid = true;
    if ($section_id) {
        if (!$selectedAcademicYear) {
            $sectionValid = false;
        } else {
            $sectionCheck = $pdo->prepare('SELECT id FROM sections WHERE id = ? AND academic_year_id = ?');
            $sectionCheck->execute([(int)$section_id, $academic_year_id]);
            $sectionValid = (bool)$sectionCheck->fetchColumn();
        }
    }

    if ($title === '' || !$departmentValid || !$programValid || !$selectedAcademicYear || !$semesterValid || !$sectionValid) {
        $error = !$departmentValid ? 'Select an active department.' : (!$programValid ? 'Select a program that belongs to the chosen department.' : (!$selectedAcademicYear ? 'Select an active academic year.' : (!$semesterValid ? 'Select a valid semester or leave it unassigned.' : (!$sectionValid ? 'The selected section must belong to the chosen academic year.' : 'Please enter a class name.'))));
    } else {
        // generate unique class code
        $class_code = generateClassCode(6);
        $check = $pdo->prepare('SELECT COUNT(*) FROM subjects WHERE class_code = ?');
        $tries = 0;
        while ($tries < 6) {
            $check->execute([$class_code]);
            if ($check->fetchColumn() == 0) break;
            $class_code = generateClassCode(6);
            $tries++;
        }

        $meetingRoomToken = bin2hex(random_bytes(16));

        // insert subject (class)
        $ins = $pdo->prepare('INSERT INTO subjects (instructor_id, program_id, department_id, section_id, academic_year_id, semester_id, semester, class_code, title, description, status, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, NOW())');
        $status = 'active';
        $ok = $ins->execute([$instructor_id, $program_id, $department_id, $section_id, $academic_year_id, $semester ?: null, $class_code, $title, $description, $status]);
        if ($ok) {
            $subject_id = $pdo->lastInsertId();

            // create schedules table if not exists
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

            $sc = $pdo->prepare('INSERT INTO class_schedules (subject_id, days, start_time, end_time, room, meeting_link) VALUES (?, ?, ?, ?, ?, ?)');
            $days_str = !empty($days) ? implode(',', $days) : null;
            $meeting_link = 'learnalytics-class-' . (int)$subject_id . '-' . $meetingRoomToken;
            $sc->execute([$subject_id, $days_str, $start_time ?: null, $end_time ?: null, $room ?: null, $meeting_link]);

            // enroll selected students
            $selected_students = $_POST['students'] ?? [];
            foreach ($selected_students as $sid) {
                $sid = (int)$sid;
                if ($sid > 0) {
                    $chk = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND subject_id = ?');
                    $chk->execute([$sid, $subject_id]);
                    if ($chk->fetchColumn() == 0) {
                        $pdo->prepare('INSERT INTO enrollments (student_id, subject_id, enrolled_at) VALUES (?, ?, NOW())')->execute([$sid, $subject_id]);
                    }
                }
            }

            // handle CSV import (emails), simple one-per-line with header optional
            if (!empty($_FILES['import_csv']['tmp_name'])) {
                $csv = fopen($_FILES['import_csv']['tmp_name'], 'r');
                if ($csv) {
                    while (($row = fgetcsv($csv)) !== false) {
                        $email = trim($row[0] ?? '');
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $u = $pdo->prepare('SELECT id FROM users WHERE email = ? AND role = "student"');
                            $u->execute([$email]);
                            $user = $u->fetch();
                            if ($user) {
                                $sid = (int)$user['id'];
                                $chk = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND subject_id = ?');
                                $chk->execute([$sid, $subject_id]);
                                if ($chk->fetchColumn() == 0) {
                                    $pdo->prepare('INSERT INTO enrollments (student_id, subject_id, enrolled_at) VALUES (?, ?, NOW())')->execute([$sid, $subject_id]);
                                }
                            }
                        }
                    }
                    fclose($csv);
                }
            }

            // optional: insert metadata like academic_year, semester, year_level into a simple table or store in subject description
            $meta = [];
            if ($academic_year_id) {
                $ay = $pdo->prepare('SELECT name FROM academic_years WHERE id = ?'); $ay->execute([$academic_year_id]); $ayname = $ay->fetchColumn(); if ($ayname) $meta[] = 'Academic Year: '.$ayname;
            }
            if ($semester) $meta[] = 'Semester: '.$semester;
            if ($year_level) $meta[] = 'Year Level: '.$year_level;
            if (!empty($meta)) {
                $stmt = $pdo->prepare("UPDATE subjects SET description = CONCAT(IFNULL(description, ''), '\n\n', ?) WHERE id = ?");
                $stmt->execute([implode("\n", $meta), $subject_id]);
            }

            header('Location: my_classes.php?created=1&title=' . urlencode($title));
            exit;
        } else {
            $error = 'Failed to create class.';
        }
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="background: #f1f5f9; min-height:100vh;">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 fw-bold mb-1"><i class="fas fa-plus me-2"></i>Create Class</h1>
                <p class="mb-0" style="opacity:0.9;">Create and configure a new class — students can join using a generated class code.</p>
            </div>
            <div>
                <a href="my_classes.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i> Back to Classes</a>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-3">
        <div class="col-lg-8">
            <div class="content-card">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Class Name</label>
                        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" required />
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <select name="department_id" id="department_id" class="form-select" required>
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?php echo $d['id']; ?>" <?php echo ($selected_department_id == $d['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($d['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Program</label>
                            <select name="program_id" id="program_id" class="form-select" <?php echo empty($programs) ? 'disabled' : ''; ?>>
                                <?php if (empty($programs) && $selected_department_id <= 0): ?>
                                    <option value="">-- Select Department First --</option>
                                <?php elseif (empty($programs)): ?>
                                    <option value="">-- No programs found --</option>
                                <?php else: ?>
                                    <option value="">-- Select Program --</option>
                                    <?php foreach ($programs as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo ($selected_program_id == $p['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="class_academic_year_id">Academic Year</label>
                        <select name="academic_year_id" id="class_academic_year_id" class="form-select" required>
                            <option value="">-- Select an Active Academic Year --</option>
                            <?php foreach ($academicYears as $academicYear): ?>
                                <option value="<?php echo (int)$academicYear['id']; ?>" <?php echo ($selectedAcademicYearId === (int)$academicYear['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($academicYear['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($academicYears)): ?><div class="form-text text-danger">No active academic years are available. Ask an administrator to add an academic year.</div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="class_semester_id">Semester <span class="text-muted">(optional)</span></label>
                        <select name="semester" id="class_semester_id" class="form-select">
                            <option value="" <?php echo empty($_POST['semester']) ? 'selected' : ''; ?>>-- No Semester --</option>
                            <?php foreach (['First', 'Second', 'Summer'] as $semesterOption): ?>
                                <option value="<?php echo htmlspecialchars($semesterOption); ?>" <?php echo (($_POST['semester'] ?? '') === $semesterOption) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($semesterOption); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Choose First, Second, or Summer, or leave it unassigned. No semester record needs to be created.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Class Description</label>
                        <textarea name="description" class="form-control" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    <hr />
                    <h5>Virtual Class Setup</h5>
                    <p class="text-muted">A unique video room will be created automatically for this class. Instructors and enrolled students can join from LearnAlytics.</p>
                    <div>
                        <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Create Class</button>
                        <a href="my_classes.php" class="btn btn-secondary ms-2">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const deptSelect = document.getElementById('department_id');
    const progSelect = document.getElementById('program_id');
    if (!deptSelect || !progSelect) return;

    const selectedProgramId = <?php echo json_encode((string)($selected_program_id ?? '')); ?>;

    function loadPrograms(deptId, programId = '') {
        progSelect.innerHTML = '';

        if (!deptId) {
            progSelect.disabled = true;
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '-- Select Department First --';
            progSelect.appendChild(opt);
            return;
        }

        progSelect.disabled = true;
        const loadingOpt = document.createElement('option');
        loadingOpt.value = '';
        loadingOpt.textContent = 'Loading programs...';
        progSelect.appendChild(loadingOpt);

        fetch('programs/get_programs.php?department_id=' + encodeURIComponent(deptId))
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                progSelect.innerHTML = '';

                if (Array.isArray(data) && data.length > 0) {
                    const defaultOpt = document.createElement('option');
                    defaultOpt.value = '';
                    defaultOpt.textContent = '-- Select Program --';
                    progSelect.appendChild(defaultOpt);

                    data.forEach(prog => {
                        const opt = document.createElement('option');
                        opt.value = String(prog.id);
                        opt.textContent = prog.name;
                        if (programId && String(prog.id) === String(programId)) {
                            opt.selected = true;
                        }
                        progSelect.appendChild(opt);
                    });
                    progSelect.disabled = false;
                } else {
                    const noOpt = document.createElement('option');
                    noOpt.value = '';
                    noOpt.textContent = '-- No programs found for this department --';
                    progSelect.appendChild(noOpt);
                    progSelect.disabled = true;
                }
            })
            .catch(err => {
                console.error('Error loading programs:', err);
                progSelect.innerHTML = '';
                const errOpt = document.createElement('option');
                errOpt.value = '';
                errOpt.textContent = '-- Error loading programs --';
                progSelect.appendChild(errOpt);
                progSelect.disabled = true;
            });
    }

    deptSelect.addEventListener('change', function() {
        loadPrograms(this.value, '');
    });

    if (deptSelect.value) {
        loadPrograms(deptSelect.value, selectedProgramId);
    }
});
</script>

<?php include 'includes/footer.php'; ?>
