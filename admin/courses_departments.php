<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'dean', 'instructor'], true)) {
    header("Location: ../auth/login.php");
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$canManageCourses = in_array($userRole, ['admin', 'dean'], true);
$userDepartmentId = null;
if ($userRole === 'dean') {
    $stmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ? AND role = 'dean'");
    $stmt->execute([$userId]);
    $userDepartmentId = (int)$stmt->fetchColumn();
    if ($userDepartmentId < 1) {
        http_response_code(403);
        exit('A department must be assigned to your dean account.');
    }
}

$user_name = $_SESSION['user_name'] ?? 'Administrator';
$success_msg = '';
$error_msg = '';

// ─── Fetch department for AJAX edit ─────────────────────────────────────────
if (isset($_GET['get_department']) && $userRole === 'admin') {
    header('Content-Type: application/json');
    $dId = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, name, description, status FROM departments WHERE id = ?');
    $stmt->execute([$dId]);
    $row = $stmt->fetch();
    echo $row ? json_encode($row) : json_encode(['error' => 'Not found']);
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($userRole !== 'admin' && (isset($_POST['create_department']) || isset($_POST['create_program']) || isset($_POST['update_program']) || isset($_POST['update_program_status']) || isset($_POST['delete_program']) || isset($_POST['update_department']) || isset($_POST['update_department_status']) || isset($_POST['delete_department']))) {
        http_response_code(403);
        exit('You do not have permission to manage departments.');
    }
    if (!$canManageCourses && (isset($_POST['update_course_status']) || isset($_POST['update_instructor']) || isset($_POST['delete_course']))) {
        http_response_code(403);
        exit('You do not have permission to manage courses.');
    }

    if (isset($_POST['create_program'])) {
        $programName = trim($_POST['program_name'] ?? '');
        $programDescription = trim($_POST['program_description'] ?? '');
        $departmentId = (int)($_POST['program_department_id'] ?? 0);

        if ($programName === '') {
            $error_msg = 'Program name is required.';
        } elseif ($departmentId < 1) {
            $error_msg = 'A department is required for every program.';
        } else {
            $departmentCheck = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND status = 'active'");
            $departmentCheck->execute([$departmentId]);
            if (!$departmentCheck->fetchColumn()) {
                $error_msg = 'Select an active department.';
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO programs (name, description, department_id, status) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$programName, $programDescription ?: null, $departmentId, 'active']);
                    $redirectUrl = 'courses_departments.php?tab=programs&saved=1&msg=' . urlencode('Program added successfully.');
                    header('Location: ' . $redirectUrl);
                    exit;
                } catch (PDOException $e) {
                    $error_msg = 'Error adding program: ' . $e->getMessage();
                }
            }
        }
    }

    if (isset($_POST['update_program'])) {
        $programId = (int)($_POST['program_id'] ?? 0);
        $programName = trim($_POST['program_name'] ?? '');
        $programDescription = trim($_POST['program_description'] ?? '');
        $departmentId = (int)($_POST['program_department_id'] ?? 0);

        if ($programId < 1 || $programName === '') {
            $error_msg = 'Program name is required.';
        } elseif ($departmentId < 1) {
            $error_msg = 'A department is required for every program.';
        } else {
            $departmentCheck = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND status = 'active'");
            $departmentCheck->execute([$departmentId]);
            $programCheck = $pdo->prepare('SELECT id FROM programs WHERE id = ?');
            $programCheck->execute([$programId]);

            if (!$departmentCheck->fetchColumn()) {
                $error_msg = 'Select an active department.';
            } elseif (!$programCheck->fetchColumn()) {
                $error_msg = 'Program not found.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('UPDATE programs SET name = ?, description = ?, department_id = ? WHERE id = ?');
                    $stmt->execute([$programName, $programDescription ?: null, $departmentId, $programId]);
                    $stmt = $pdo->prepare('UPDATE subjects SET department_id = ? WHERE program_id = ?');
                    $stmt->execute([$departmentId, $programId]);
                    $stmt = $pdo->prepare('UPDATE student_profiles SET department_id = ? WHERE program_id = ?');
                    $stmt->execute([$departmentId, $programId]);
                    $pdo->commit();
                    $redirectUrl = 'courses_departments.php?tab=programs&saved=1&msg=' . urlencode('Program updated successfully.');
                    header('Location: ' . $redirectUrl);
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error_msg = 'Error updating program: ' . $e->getMessage();
                }
            }
        }
    }

    if (isset($_POST['update_program_status'])) {
        $programId = (int)($_POST['program_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($programId > 0 && in_array($status, ['active', 'inactive'], true)) {
            $programCheck = $pdo->prepare('SELECT id FROM programs WHERE id = ?');
            $programCheck->execute([$programId]);
            if (!$programCheck->fetchColumn()) {
                $error_msg = 'Program not found.';
            } else {
                try {
                    $stmt = $pdo->prepare('UPDATE programs SET status = ? WHERE id = ?');
                    $stmt->execute([$status, $programId]);
                    $statusLabel = $status === 'active' ? 'activated' : 'deactivated';
                    $redirectUrl = 'courses_departments.php?tab=programs&saved=1&msg=' . urlencode('Program ' . $statusLabel . ' successfully.');
                    header('Location: ' . $redirectUrl);
                    exit;
                } catch (PDOException $e) {
                    $error_msg = 'Error updating program status: ' . $e->getMessage();
                }
            }
        }
        if ($error_msg === '') {
            $error_msg = 'Select a valid program status.';
        }
    }

    if (isset($_POST['delete_program'])) {
        $programId = (int)($_POST['program_id'] ?? 0);
        if ($programId < 1) {
            $error_msg = 'Select a valid program.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT id FROM programs WHERE id = ? FOR UPDATE');
                $stmt->execute([$programId]);
                if (!$stmt->fetchColumn()) {
                    $pdo->rollBack();
                    $error_msg = 'Program not found.';
                } else {
                    $stmt = $pdo->prepare("
                        SELECT
                            (SELECT COUNT(*) FROM subjects WHERE program_id = ?) AS course_count,
                            (SELECT COUNT(*) FROM sections WHERE program_id = ?) AS section_count,
                            (SELECT COUNT(*) FROM student_profiles WHERE program_id = ?) AS student_count
                    ");
                    $stmt->execute([$programId, $programId, $programId]);
                    $linkedRecords = $stmt->fetch();
                    $linkedRecordCount = (int)$linkedRecords['course_count']
                        + (int)$linkedRecords['section_count']
                        + (int)$linkedRecords['student_count'];

                    if ($linkedRecordCount > 0) {
                        $pdo->rollBack();
                        $error_msg = 'This program cannot be deleted while courses, sections, or students are linked to it.';
                    } else {
                        $stmt = $pdo->prepare('DELETE FROM programs WHERE id = ?');
                        $stmt->execute([$programId]);
                        $pdo->commit();
                        $redirectUrl = 'courses_departments.php?tab=programs&saved=1&msg=' . urlencode('Program deleted successfully.');
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_msg = 'Error deleting program: ' . $e->getMessage();
            }
        }
    }

    // Create Department
    if (isset($_POST['create_department'])) {
        $dept_name = trim($_POST['department_name'] ?? '');
        $dept_description = trim($_POST['department_description'] ?? '');

        if (empty($dept_name)) {
            $error_msg = 'Department name is required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO departments (name, description) VALUES (?, ?)");
                $stmt->execute([$dept_name, $dept_description]);
                $redirectUrl = 'courses_departments.php?tab=departments&saved=1&msg=' . urlencode('Department created successfully.');
                header('Location: ' . $redirectUrl);
                exit;
            } catch (PDOException $e) {
                $error_msg = 'Error creating department: ' . $e->getMessage();
            }
        }
    }

    // Update Department
    if (isset($_POST['update_department'])) {
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $dept_name = trim($_POST['department_name'] ?? '');
        $dept_description = trim($_POST['department_description'] ?? '');
        $dept_status = in_array($_POST['department_status'] ?? '', ['active', 'inactive']) ? $_POST['department_status'] : 'active';
        if ($dept_id < 1 || empty($dept_name)) {
            $error_msg = 'Department name is required.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE departments SET name = ?, description = ?, status = ? WHERE id = ?");
                $stmt->execute([$dept_name, $dept_description, $dept_status, $dept_id]);
                $redirectUrl = 'courses_departments.php?tab=departments&saved=1&msg=' . urlencode('Department updated successfully.');
                header('Location: ' . $redirectUrl);
                exit;
            } catch (PDOException $e) {
                $error_msg = 'Error updating department: ' . $e->getMessage();
            }
        }
    }

    // Update Department Status
    if (isset($_POST['update_department_status'])) {
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        if ($dept_id > 0) {
            $stmt = $pdo->prepare("UPDATE departments SET status = ? WHERE id = ?");
            $stmt->execute([$status, $dept_id]);
            $redirectUrl = 'courses_departments.php?tab=departments&saved=1&msg=' . urlencode('Department status updated successfully.');
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    // Update Course Status
    if (isset($_POST['update_course_status'])) {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        if ($course_id > 0 && in_array($status, ['active', 'inactive'], true)) {
            $scopeSql = $userRole === 'dean' ? ' AND department_id = ?' : '';
            $params = [$status, $course_id];
            if ($userRole === 'dean') $params[] = $userDepartmentId;
            $stmt = $pdo->prepare("UPDATE subjects SET status = ? WHERE id = ?{$scopeSql}");
            $stmt->execute($params);
            $statusLabel = $status === 'active' ? 'activated' : 'deactivated';
            $redirectUrl = 'courses_departments.php?tab=courses&saved=1&msg=' . urlencode('Course ' . $statusLabel . ' successfully.');
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    // Update Course Instructor
    if (isset($_POST['update_instructor'])) {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $instructor_id = (int)($_POST['instructor_id'] ?? 0);
        if ($course_id > 0 && $instructor_id >= 0) {
            if ($instructor_id > 0) {
                $validInstructor = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'instructor' AND (status = 'active' OR status IS NULL)");
                $validInstructor->execute([$instructor_id]);
                if (!$validInstructor->fetchColumn()) {
                    $error_msg = 'Select a valid active instructor.';
                    $instructor_id = -1;
                }
            }
            if ($instructor_id >= 0) {
                $scopeSql = $userRole === 'dean' ? ' AND department_id = ?' : '';
                $params = [$instructor_id ?: null, $course_id];
                if ($userRole === 'dean') $params[] = $userDepartmentId;
                $stmt = $pdo->prepare("UPDATE subjects SET instructor_id = ? WHERE id = ?{$scopeSql}");
                $stmt->execute($params);
                $redirectUrl = 'courses_departments.php?tab=courses&saved=1&msg=' . urlencode('Course instructor updated successfully.');
                header('Location: ' . $redirectUrl);
                exit;
            }
        }
    }

    // Delete Department
    if (isset($_POST['delete_department'])) {
        $dept_id = (int)($_POST['department_id'] ?? 0);
        if ($dept_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
                $stmt->execute([$dept_id]);
                $redirectUrl = 'courses_departments.php?tab=departments&saved=1&msg=' . urlencode('Department deleted successfully.');
                header('Location: ' . $redirectUrl);
                exit;
            } catch (PDOException $e) {
                $error_msg = 'Error deleting department: ' . $e->getMessage();
            }
        }
    }

    // Delete Course
    if (isset($_POST['delete_course'])) {
        $course_id = (int)($_POST['course_id'] ?? 0);
        if ($course_id > 0) {
            try {
                $scopeSql = $userRole === 'dean' ? ' AND department_id = ?' : '';
                $params = [$course_id];
                if ($userRole === 'dean') $params[] = $userDepartmentId;
                $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?{$scopeSql}");
                $stmt->execute($params);
                $redirectUrl = 'courses_departments.php?tab=courses&saved=1&msg=' . urlencode('Course deleted successfully.');
                header('Location: ' . $redirectUrl);
                exit;
            } catch (PDOException $e) {
                $error_msg = 'Error deleting course: ' . $e->getMessage();
            }
        }
    }
}

// ============================================================
// Statistics
// ============================================================
$stats = [];
if ($userRole === 'admin') {
    $stats['total_departments'] = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
    $stats['active_departments'] = $pdo->query("SELECT COUNT(*) FROM departments WHERE status = 'active'")->fetchColumn();
    $stats['total_courses'] = $pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
    $stats['active_courses'] = $pdo->query("SELECT COUNT(*) FROM subjects WHERE status = 'active'")->fetchColumn();
    $stats['total_enrollments'] = $pdo->query("SELECT COUNT(*) FROM enrollments")->fetchColumn();
} elseif ($userRole === 'dean') {
    $stats['total_departments'] = 1;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE id = ? AND status = 'active'");
    $stmt->execute([$userDepartmentId]);
    $stats['active_departments'] = $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM subjects WHERE department_id = ?');
    $stmt->execute([$userDepartmentId]);
    $stats['total_courses'] = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE department_id = ? AND status = 'active'");
    $stmt->execute([$userDepartmentId]);
    $stats['active_courses'] = $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments e JOIN subjects s ON s.id = e.subject_id WHERE s.department_id = ?');
    $stmt->execute([$userDepartmentId]);
    $stats['total_enrollments'] = $stmt->fetchColumn();
} else {
    $stats = array_fill_keys(['total_departments', 'active_departments', 'total_courses', 'active_courses', 'total_enrollments'], 0);
}

// ============================================================
// Search and Filter Parameters
// ============================================================
$searchTerm = trim($_GET['search'] ?? '');
$filterDepartment = $_GET['department'] ?? '';
$filterInstructor = $_GET['instructor'] ?? '';
$filterProgramType = $_GET['program_type'] ?? '';
$filterYearLevel = $_GET['year_level'] ?? '';
$filterSemester = $_GET['semester'] ?? '';
$filterStatus = $_GET['status'] ?? '';
// toastMsg is read by JS from the URL ?msg= param after a redirect
$toastMsg = isset($_GET['saved'], $_GET['msg']) ? htmlspecialchars(urldecode($_GET['msg'])) : '';
$activeTab = $_GET['tab'] ?? ($userRole === 'instructor' ? 'courses' : 'departments');
if (!in_array($activeTab, ['departments', 'courses', 'programs'], true)
    || $userRole === 'instructor'
    || ($activeTab === 'programs' && $userRole !== 'admin')) {
    $activeTab = 'courses';
}

// Get all departments for filter
$departmentQuery = 'SELECT id, name FROM departments WHERE 1 = 1';
$departmentParams = [];
if ($userRole === 'dean') {
    $departmentQuery .= ' AND id = ?';
    $departmentParams[] = $userDepartmentId;
}
$departmentQuery .= ' ORDER BY name';
$stmt = $pdo->prepare($departmentQuery);
$stmt->execute($departmentParams);
$departments = $stmt->fetchAll();
$activeDepartmentQuery = "SELECT id, name FROM departments WHERE status = 'active'";
if ($userRole === 'dean') {
    $activeDepartmentQuery .= ' AND id = ' . (int)$userDepartmentId;
}
$activeDepartmentQuery .= ' ORDER BY name';
$activeDepartments = $pdo->query($activeDepartmentQuery)->fetchAll();

// Get all instructors for filter
$instructors = $pdo->query("SELECT id, name FROM users WHERE role = 'instructor' AND (status = 'active' OR status IS NULL) ORDER BY name")->fetchAll();
$semesterOptions = $pdo->query("SELECT sem.semester_id, sem.semester_name, ay.name AS academic_year_name FROM semesters sem JOIN academic_years ay ON ay.id = sem.academic_year_id ORDER BY ay.start_date DESC, sem.start_date DESC")->fetchAll();

// ============================================================
// Department Query
// ============================================================
$deptWhere = [];
$deptParams = [];

if ($userRole === 'dean') {
    $deptWhere[] = 'd.id = ?';
    $deptParams[] = $userDepartmentId;
}

if (!empty($searchTerm)) {
    $deptWhere[] = "(d.name LIKE ? OR d.description LIKE ?)";
    $wildcard = "%{$searchTerm}%";
    $deptParams[] = $wildcard;
    $deptParams[] = $wildcard;
}

if (!empty($filterStatus)) {
    $deptWhere[] = "d.status = ?";
    $deptParams[] = $filterStatus;
}

$deptSQL = !empty($deptWhere) ? "WHERE " . implode(" AND ", $deptWhere) : "";

$deptQuery = "
    SELECT 
        d.id, d.name, d.description, d.status, d.created_at,
        COUNT(DISTINCT p.id) as total_programs,
        (
            SELECT COUNT(DISTINCT s2.id)
            FROM subjects s2
            LEFT JOIN programs p2 ON s2.program_id = p2.id
            WHERE s2.department_id = d.id OR p2.department_id = d.id
        ) as total_courses,
        (
            SELECT COUNT(DISTINCT e.student_id)
            FROM enrollments e
            JOIN subjects s2 ON e.subject_id = s2.id
            LEFT JOIN programs p2 ON s2.program_id = p2.id
            WHERE s2.department_id = d.id OR p2.department_id = d.id
        ) as total_students
    FROM departments d
    LEFT JOIN programs p ON p.department_id = d.id
    {$deptSQL}
    GROUP BY d.id, d.name, d.description, d.status, d.created_at
    ORDER BY d.name ASC
";

$stmt = $pdo->prepare($deptQuery);
$stmt->execute($deptParams);
$departmentList = $stmt->fetchAll();

// ============================================================
// Course Query
// ============================================================
$courseWhere = [];
$courseParams = [];

if (!empty($searchTerm)) {
    $courseWhere[] = "(s.title LIKE ? OR s.class_code LIKE ? OR s.description LIKE ? OR u.name LIKE ? OR d.name LIKE ?)";
    $wildcard = "%{$searchTerm}%";
    $courseParams[] = $wildcard;
    $courseParams[] = $wildcard;
    $courseParams[] = $wildcard;
    $courseParams[] = $wildcard;
    $courseParams[] = $wildcard;
}

if (!empty($filterDepartment)) {
    $courseWhere[] = "(s.department_id = ? OR s.program_id IN (SELECT id FROM programs WHERE department_id = ?))";
    $courseParams[] = $filterDepartment;
    $courseParams[] = $filterDepartment;
}

if ($userRole === 'dean') {
    $courseWhere[] = '(s.department_id = ? OR (s.department_id IS NULL AND p2.department_id = ?))';
    $courseParams[] = $userDepartmentId;
    $courseParams[] = $userDepartmentId;
} elseif ($userRole === 'instructor') {
    $courseWhere[] = 's.instructor_id = ?';
    $courseParams[] = $userId;
}

if (!empty($filterInstructor)) {
    $courseWhere[] = "s.instructor_id = ?";
    $courseParams[] = $filterInstructor;
}

if (!empty($filterStatus)) {
    $courseWhere[] = "s.status = ?";
    $courseParams[] = $filterStatus;
}

if (!empty($filterProgramType)) {
    $courseWhere[] = 's.program_type = ?';
    $courseParams[] = $filterProgramType;
}

if (!empty($filterYearLevel)) {
    $courseWhere[] = 's.year_level = ?';
    $courseParams[] = $filterYearLevel;
}

if (!empty($filterSemester)) {
    $courseWhere[] = 's.semester_id = ?';
    $courseParams[] = (int)$filterSemester;
}

$courseSQL = !empty($courseWhere) ? "WHERE " . implode(" AND ", $courseWhere) : "";

$courseQuery = "
    SELECT 
        s.id, s.title, s.class_code, s.description, s.status, s.created_at,
        s.program_id, s.program_type, s.units, s.year_level,
        COALESCE(sem.semester_name, s.semester) AS semester_display,
        ay.name AS semester_academic_year,
        u.id as instructor_id, u.name as instructor_name,
        COALESCE(d.id, p2.department_id) as department_id,
        COALESCE(d.name, pd.name) as department_name,
        COUNT(DISTINCT e.id) as enrollment_count
    FROM subjects s
    LEFT JOIN users u ON u.id = s.instructor_id
    LEFT JOIN departments d ON d.id = s.department_id
    LEFT JOIN programs p2 ON p2.id = s.program_id
    LEFT JOIN departments pd ON pd.id = p2.department_id
    LEFT JOIN semesters sem ON sem.semester_id = s.semester_id
    LEFT JOIN academic_years ay ON ay.id = sem.academic_year_id
    LEFT JOIN enrollments e ON e.subject_id = s.id
    {$courseSQL}
    GROUP BY s.id, s.title, s.class_code, s.description, s.status, s.created_at,
             s.program_id, s.program_type, s.units, s.year_level, s.semester, sem.semester_name, ay.name,
             u.id, u.name, department_id, department_name
    ORDER BY s.title ASC
";

$stmt = $pdo->prepare($courseQuery);
$stmt->execute($courseParams);
$courseList = $stmt->fetchAll();

$programList = [];
if ($userRole === 'admin') {
    $programWhere = [];
    $programParams = [];

    if ($searchTerm !== '') {
        $programWhere[] = '(p.name LIKE ? OR p.description LIKE ? OR d.name LIKE ?)';
        $wildcard = "%{$searchTerm}%";
        $programParams[] = $wildcard;
        $programParams[] = $wildcard;
        $programParams[] = $wildcard;
    }

    if ($filterDepartment !== '') {
        $programWhere[] = 'p.department_id = ?';
        $programParams[] = (int)$filterDepartment;
    }

    if ($filterStatus !== '') {
        $programWhere[] = 'p.status = ?';
        $programParams[] = $filterStatus;
    }

    $programSQL = $programWhere ? 'WHERE ' . implode(' AND ', $programWhere) : '';
    $programQuery = "
        SELECT p.id, p.name, p.description, p.status, p.department_id, d.name AS department_name,
               COUNT(DISTINCT s.id) AS total_courses,
               COUNT(DISTINCT e.student_id) AS total_students
        FROM programs p
        LEFT JOIN departments d ON d.id = p.department_id
        LEFT JOIN subjects s ON s.program_id = p.id
        LEFT JOIN enrollments e ON e.subject_id = s.id
        {$programSQL}
        GROUP BY p.id, p.name, p.description, p.status, p.department_id, d.name
        ORDER BY d.name, p.name
    ";
    $stmt = $pdo->prepare($programQuery);
    $stmt->execute($programParams);
    $programList = $stmt->fetchAll();
}

if (!$canManageCourses) {
    $departmentList = [];
}

// ============================================================
// Enrollment Trends (for chart)
// ============================================================
$enrollmentTrends = $pdo->query("
    SELECT 
        DATE_FORMAT(enrolled_at, '%Y-%m') as month,
        COUNT(*) as count
    FROM enrollments 
    WHERE enrolled_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(enrolled_at, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();

// ============================================================
// Department Performance
// ============================================================
$performanceScope = $userRole === 'dean' ? 'WHERE d.id = ' . (int)$userDepartmentId : '';
$deptPerformance = $pdo->query("
    SELECT 
        d.name,
        (
            SELECT COUNT(DISTINCT e.student_id)
            FROM enrollments e
            JOIN subjects s2 ON e.subject_id = s2.id
            LEFT JOIN programs p2 ON s2.program_id = p2.id
            WHERE s2.department_id = d.id OR p2.department_id = d.id
        ) as student_count,
        (
            SELECT COUNT(*) FROM programs p2 WHERE p2.department_id = d.id
        ) as program_count,
        (
            SELECT COUNT(DISTINCT s2.id)
            FROM subjects s2
            LEFT JOIN programs p2 ON s2.program_id = p2.id
            WHERE s2.department_id = d.id OR p2.department_id = d.id
        ) as course_count
    FROM departments d
    {$performanceScope}
    GROUP BY d.id, d.name
    ORDER BY student_count DESC
    LIMIT 5
")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    :root {
        --primary-blue: #1e3a8a;
        --secondary-blue: #2563eb;
        --light-blue: #3b82f6;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --gold: #f59e0b;
    }

    body { background: #f1f5f9; }

    /* ── Toast Notification ─────────────────────────── */
    #liveToast {
        min-width: 320px;
        border-radius: 12px;
        border: none;
        box-shadow: 0 8px 32px rgba(0,0,0,0.18);
        overflow: hidden;
    }
    #liveToast .toast-header {
        background: linear-gradient(135deg, var(--success) 0%, #34d399 100%);
        color: white;
        border-bottom: none;
        padding: 0.75rem 1rem;
    }
    #liveToast .toast-header .btn-close { filter: brightness(0) invert(1); }
    #liveToast .toast-body { font-size: 0.9rem; padding: 0.85rem 1rem; }

    /* ── Stat Cards ─────────────────────────────────── */
    .stat-card {
        border-radius: 14px;
        border: none;
        overflow: hidden;
        transition: all 0.3s ease;
        height: 100%;
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.12) !important;
    }
    .stat-card .card-body { padding: 1.25rem; }
    .stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
    }
    .stat-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--primary-blue);
    }
    .stat-label {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
    }

    /* ── Filter Section ─────────────────────────────── */
    .filter-section {
        background: white;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        margin-bottom: 1.5rem;
    }
    .search-input {
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem 1rem;
        transition: all 0.2s ease;
    }
    .search-input:focus {
        border-color: var(--secondary-blue);
        box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        outline: none;
    }

    /* ── Tab Navigation ─────────────────────────────── */
    .tab-nav {
        display: flex;
        gap: 0.5rem;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 1.5rem;
    }
    .tab-btn {
        padding: 0.75rem 1.5rem;
        border: none;
        background: none;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: all 0.2s ease;
        text-decoration: none;
    }
    .tab-btn:hover { color: var(--secondary-blue); }
    .tab-btn.active {
        color: var(--secondary-blue);
        border-bottom-color: var(--secondary-blue);
    }

    /* ── Data Tables ────────────────────────────────── */
    .data-table-container {
        background: white;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }
    .data-table { margin-bottom: 0; }
    .data-table thead {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
        color: white;
    }
    .data-table th {
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 1rem;
        border: none;
        white-space: nowrap;
    }
    .data-table td {
        padding: 0.875rem 1rem;
        vertical-align: middle;
        font-size: 0.875rem;
        border-bottom: 1px solid #f1f5f9;
    }
    .data-table tbody tr:hover { background: #f8fafc; }
    .data-table tbody tr:last-child td { border-bottom: none; }

    /* ── Status Badges ──────────────────────────────── */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.3rem 0.75rem;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .status-active  { background: #dcfce7; color: #166534; }
    .status-inactive{ background: #f1f5f9; color: #475569; }
    .status-draft   { background: #fef3c7; color: #78350f; }
    .status-completed{ background: #dbeafe; color: #1e40af; }
    .status-archived{ background: #e2e8f0; color: #475569; }

    /* ── Action Buttons ─────────────────────────────── */
    .action-btn {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        border: none;
        cursor: pointer;
        font-size: 0.8rem;
    }
    .action-btn:hover { transform: scale(1.12); }
    .btn-view   { background: #dbeafe; color: #2563eb; }
    .btn-edit   { background: #fef3c7; color: #d97706; }
    .btn-toggle { background: #dcfce7; color: #059669; }
    .btn-delete { background: #fee2e2; color: #b91c1c; }
    .btn-archive{ background: #f1f5f9; color: #64748b; }

    /* ── Charts ─────────────────────────────────────── */
    .chart-container {
        background: white;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        height: 100%;
    }
    .chart-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--primary-blue);
        margin-bottom: 1rem;
    }

    /* ── Modals ─────────────────────────────────────── */
    .modal-content {
        border-radius: 16px;
        border: none;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    }
    .modal-header {
        border-radius: 16px 16px 0 0;
        padding: 1.25rem 1.5rem;
    }
    .modal-body { padding: 1.5rem; }
    .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #f1f5f9; }

    /* ── Form Section Dividers (inside modal) ───────── */
    .form-section-label {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--secondary-blue);
        border-bottom: 2px solid #e2e8f0;
        padding-bottom: 0.4rem;
        margin-bottom: 0.75rem;
        margin-top: 0.25rem;
    }
    .form-label { font-weight: 600; font-size: 0.85rem; color: #374151; margin-bottom: 0.35rem; }
    .form-control, .form-select {
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.875rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--secondary-blue);
        box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
    }
    .form-control.is-invalid, .form-select.is-invalid {
        border-color: var(--danger);
        box-shadow: 0 0 0 3px rgba(239,68,68,0.1);
    }
    .invalid-feedback { font-size: 0.78rem; display: block; }

    /* ── Instructor avatar chip ─────────────────────── */
    .instructor-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }
    .instructor-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.65rem;
        color: #92400e;
        flex-shrink: 0;
    }

    /* ── Type badge ─────────────────────────────────── */
    .type-badge {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
        background: #ede9fe;
        color: #5b21b6;
    }

    @media (max-width: 768px) {
        .stat-value { font-size: 1.4rem; }
        .stat-icon { width: 44px; height: 44px; font-size: 1.1rem; }
        .data-table { font-size: 0.75rem; }
        .action-btn { width: 28px; height: 28px; }
    }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="background: #f1f5f9; min-height: 100vh;">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold" style="color: var(--primary-blue);">
                <i class="fas fa-building-columns me-2" style="color: var(--secondary-blue);"></i>Courses & Departments
            </h1>
            <p class="text-muted mb-0">Manage academic departments and courses across the institution.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-secondary" onclick="location.reload()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
            <?php if ($canManageCourses): ?>
                <?php if ($userRole === 'admin'): ?>
                    <button class="btn" style="background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--light-blue) 100%); color: white; font-weight: 600;" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                        <i class="fas fa-plus me-1"></i> Add Program
                    </button>
                    <button class="btn" style="background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%); color: var(--primary-blue); font-weight: 600;" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                        <i class="fas fa-plus me-1"></i> Add Department
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Inline Alerts (validation errors only) -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert" style="border-left: 4px solid var(--success); border-radius: 10px;">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert" style="border-left: 4px solid var(--danger); border-radius: 10px;">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Toast Notification (shown after redirect on success) -->
    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
        <div id="liveToast" class="toast align-items-center" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header">
                <i class="fas fa-check-circle me-2"></i>
                <strong class="me-auto">Success</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body" id="toastBody"></div>
        </div>
    </div>

    <?php if ($userRole !== 'instructor'): ?>


    <?php endif; ?>

    <!-- Search & Filter Section -->
    <div class="filter-section">
        <h5 class="fw-bold mb-3" style="color: var(--primary-blue);">
            <i class="fas fa-search me-2"></i>Search & Filter
        </h5>
        <form method="GET" class="row g-3">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
            <div class="<?php echo $activeTab === 'programs' ? 'col-lg-5 col-md-6' : 'col-lg-4 col-md-6'; ?>">
                <label class="form-label small text-muted">Search</label>
                <input type="text" name="search" class="form-control search-input" 
                       placeholder="<?php echo $activeTab === 'programs' ? 'Search programs or departments...' : 'Search by name, code, instructor...'; ?>" 
                       value="<?php echo htmlspecialchars($searchTerm); ?>">
            </div>
            <div class="<?php echo $activeTab === 'programs' ? 'col-lg-3 col-md-4' : 'col-lg-2 col-md-4'; ?>">
                <label class="form-label small text-muted">Department</label>
                <select name="department" class="form-select search-input">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>" <?php echo $filterDepartment == $dept['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($activeTab !== 'programs'): ?>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Instructor</label>
                <select name="instructor" class="form-select search-input">
                    <option value="">All Instructors</option>
                    <?php foreach ($instructors as $inst): ?>
                        <option value="<?php echo $inst['id']; ?>" <?php echo $filterInstructor == $inst['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($inst['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Program Type</label>
                <select name="program_type" class="form-select search-input">
                    <option value="">All Types</option>
                    <?php foreach (['Degree Program', 'Course / Subject', 'Certificate Program', 'Short Course'] as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filterProgramType === $type ? 'selected' : ''; ?>><?php echo htmlspecialchars($type); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Year Level</label>
                <select name="year_level" class="form-select search-input">
                    <option value="">All Year Levels</option>
                    <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year', 'Graduate / Other'] as $year): ?>
                        <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $filterYearLevel === $year ? 'selected' : ''; ?>><?php echo htmlspecialchars($year); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Semester</label>
                <select name="semester" class="form-select search-input">
                    <option value="">All Semesters</option>
                    <?php foreach ($semesterOptions as $semester): ?>
                        <option value="<?php echo (int)$semester['semester_id']; ?>" <?php echo (string)$filterSemester === (string)$semester['semester_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($semester['semester_name'] . ' · ' . $semester['academic_year_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Status</label>
                <select name="status" class="form-select search-input">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <?php if ($activeTab !== 'programs'): ?>
                    <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="archived" <?php echo $filterStatus === 'archived' ? 'selected' : ''; ?>>Archived</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4 d-flex align-items-end">
                <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--light-blue) 100%); color: white; font-weight: 600; width: 100%;">
                    <i class="fas fa-search me-1"></i> Search
                </button>
            </div>
            <div class="col-12">
                <a href="courses_departments.php?tab=<?php echo urlencode($activeTab); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times me-1"></i> Clear Filters
                </a>
                <span class="text-muted ms-2">
                    <?php if ($activeTab === 'departments'): ?>
                        Showing <?php echo count($departmentList); ?> departments
                    <?php elseif ($activeTab === 'programs'): ?>
                        Showing <?php echo count($programList); ?> programs
                    <?php else: ?>
                        Showing <?php echo count($courseList); ?> courses
                    <?php endif; ?>
                </span>
            </div>
        </form>
    </div>

    <!-- Tab Navigation -->
    <?php if ($userRole === 'admin'): ?><div class="tab-nav">
        <a href="?tab=departments&search=<?php echo urlencode($searchTerm); ?>&department=<?php echo urlencode($filterDepartment); ?>&instructor=<?php echo urlencode($filterInstructor); ?>&status=<?php echo urlencode($filterStatus); ?>" 
           class="tab-btn <?php echo $activeTab === 'departments' ? 'active' : ''; ?>">
            <i class="fas fa-building me-1"></i> Departments
        </a>
        <a href="?tab=programs&search=<?php echo urlencode($searchTerm); ?>&department=<?php echo urlencode($filterDepartment); ?>&status=<?php echo urlencode($filterStatus); ?>" 
           class="tab-btn <?php echo $activeTab === 'programs' ? 'active' : ''; ?>">
            <i class="fas fa-graduation-cap me-1"></i> Programs
        </a>
        <a href="?tab=courses&search=<?php echo urlencode($searchTerm); ?>&department=<?php echo urlencode($filterDepartment); ?>&instructor=<?php echo urlencode($filterInstructor); ?>&program_type=<?php echo urlencode($filterProgramType); ?>&year_level=<?php echo urlencode($filterYearLevel); ?>&semester=<?php echo urlencode($filterSemester); ?>&status=<?php echo urlencode($filterStatus); ?>" 
           class="tab-btn <?php echo $activeTab === 'courses' ? 'active' : ''; ?>">
            <i class="fas fa-book me-1"></i> Courses
        </a>
    </div><?php endif; ?>

    <!-- Tab Content -->
    <?php if ($activeTab === 'departments'): ?>
        <!-- Departments Table -->
        <div class="data-table-container">
            <div class="table-responsive">
                <table class="data-table w-100">
                    <thead>
                        <tr>
                            <th>Department Name</th>
                            <th>Description</th>
                            <th>Total Courses</th>
                            <th>Total Students</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departmentList as $dept): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($dept['name']); ?></td>
                                <td class="text-muted" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo htmlspecialchars(substr($dept['description'] ?? '', 0, 60)); ?>
                                </td>
                                <td><span class="badge bg-info"><?php echo $dept['total_courses']; ?></span></td>
                                <td><span class="badge bg-primary"><?php echo $dept['total_students']; ?></span></td>
                                <td>
                                    <span class="status-badge status-<?php echo $dept['status'] ?? 'active'; ?>">
                                        <?php echo ucfirst($dept['status'] ?? 'active'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button class="action-btn btn-view" title="View Details" onclick="viewDepartment(<?php echo $dept['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="action-btn btn-edit" title="Edit" onclick="editDepartment(<?php echo $dept['id']; ?>)">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button class="action-btn btn-delete" title="Delete Department" onclick="deleteDepartment(<?php echo $dept['id']; ?>, '<?php echo htmlspecialchars(addslashes($dept['name'])); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <button class="action-btn btn-toggle" title="<?php echo ($dept['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate'; ?>" onclick="toggleDeptStatus(<?php echo $dept['id']; ?>, '<?php echo $dept['status'] ?? 'active'; ?>')">
                                            <i class="fas fa-<?php echo ($dept['status'] ?? 'active') === 'active' ? 'pause' : 'play'; ?>"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($departmentList)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No departments found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($activeTab === 'courses'): ?>
        <!-- Courses Table -->
        <div class="data-table-container">
            <div class="table-responsive">
                <table class="data-table w-100">
                    <thead>
                        <tr>
                            <th>Course Code</th>
                            <th>Program / Course Name</th>
                            <th>Department</th>
                            <th>Program Type</th>
                            <th>Instructor</th>
                            <th>Units</th>
                            <th>Year Level</th>
                            <th>Semester</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courseList as $course): ?>
                            <tr>
                                <td>
                                    <span class="fw-bold" style="font-family: monospace; font-size: 0.85rem; color: var(--primary-blue);">
                                        <?php echo htmlspecialchars($course['class_code'] ?? '—'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold" style="color: #1e293b;"><?php echo htmlspecialchars($course['title']); ?></div>
                                    <?php if ($course['description']): ?>
                                        <small class="text-muted" style="font-size: 0.72rem; display: block; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars(substr($course['description'], 0, 55)); ?>…</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem;"><?php echo htmlspecialchars($course['department_name'] ?? '—'); ?></span>
                                </td>
                                <td>
                                    <?php if ($course['program_type']): ?>
                                        <span class="type-badge"><?php echo htmlspecialchars($course['program_type']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($course['instructor_name']): ?>
                                        <div class="instructor-chip">
                                            <div class="instructor-avatar">
                                                <?php echo strtoupper(substr($course['instructor_name'], 0, 2)); ?>
                                            </div>
                                            <span style="font-size: 0.82rem;"><?php echo htmlspecialchars($course['instructor_name']); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic" style="font-size: 0.8rem;">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($course['units'] !== null && $course['units'] !== ''): ?>
                                        <span class="fw-semibold" style="color: #374151;"><?php echo htmlspecialchars((float)$course['units'] == intval($course['units']) ? intval($course['units']) : $course['units']); ?></span>
                                        <small class="text-muted"> units</small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap; font-size: 0.8rem;"><?php echo htmlspecialchars($course['year_level'] ?? '—'); ?></td>
                                <td style="white-space: nowrap; font-size: 0.8rem;"><?php echo htmlspecialchars(($course['semester_display'] ?? '—') . (!empty($course['semester_academic_year']) ? ' · ' . $course['semester_academic_year'] : '')); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $course['status']; ?>">
                                        <i class="fas fa-<?php echo $course['status'] === 'active' ? 'circle-check' : 'circle-xmark'; ?> me-1" style="font-size: 0.65rem;"></i>
                                        <?php echo ucfirst($course['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-label="Course actions"><i class="fas fa-ellipsis"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><button class="dropdown-item" type="button" onclick="viewCourse(<?php echo (int)$course['id']; ?>)"><i class="fas fa-eye me-2"></i>View Details</button></li>
                                            <?php if ($canManageCourses): ?>
                                                <li><button class="dropdown-item" type="button" onclick="assignInstructor(<?php echo (int)$course['id']; ?>, <?php echo (int)($course['instructor_id'] ?? 0); ?>)"><i class="fas fa-user-tie me-2"></i>Assign/Reassign Instructor</button></li>
                                                <li><button class="dropdown-item" type="button" onclick="toggleCourseStatus(<?php echo (int)$course['id']; ?>, '<?php echo htmlspecialchars($course['status'], ENT_QUOTES); ?>')"><i class="fas fa-power-off me-2"></i><?php echo $course['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><button class="dropdown-item text-danger" type="button" onclick="deleteCourse(<?php echo (int)$course['id']; ?>)"><i class="fas fa-trash me-2"></i>Delete</button></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($courseList)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">No courses found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($activeTab === 'programs'): ?>
        <!-- Programs Table -->
        <div class="data-table-container">
            <div class="table-responsive">
                <table class="data-table w-100">
                    <thead>
                        <tr>
                            <th>Program Name</th>
                            <th>Department</th>
                            <th>Description</th>
                            <th>Total Courses</th>
                            <th>Total Students</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programList as $program): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($program['name']); ?></td>
                                <td><?php echo htmlspecialchars($program['department_name'] ?? '—'); ?></td>
                                <td class="text-muted" style="max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo htmlspecialchars($program['description'] ?? '—'); ?>
                                </td>
                                <td><span class="badge bg-info"><?php echo (int)$program['total_courses']; ?></span></td>
                                <td><span class="badge bg-primary"><?php echo (int)$program['total_students']; ?></span></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($program['status'] ?? 'active', ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars(ucfirst($program['status'] ?? 'active')); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button class="action-btn btn-view" title="View Program" type="button"
                                            data-name="<?php echo htmlspecialchars($program['name'], ENT_QUOTES); ?>"
                                            data-department="<?php echo htmlspecialchars($program['department_name'] ?? '—', ENT_QUOTES); ?>"
                                            data-description="<?php echo htmlspecialchars($program['description'] ?? '—', ENT_QUOTES); ?>"
                                            data-courses="<?php echo (int)$program['total_courses']; ?>"
                                            data-students="<?php echo (int)$program['total_students']; ?>"
                                            data-status="<?php echo htmlspecialchars($program['status'] ?? 'active', ENT_QUOTES); ?>"
                                            onclick="viewProgram(this)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="action-btn btn-edit" title="Edit Program" type="button"
                                            data-id="<?php echo (int)$program['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($program['name'], ENT_QUOTES); ?>"
                                            data-department-id="<?php echo (int)$program['department_id']; ?>"
                                            data-description="<?php echo htmlspecialchars($program['description'] ?? '', ENT_QUOTES); ?>"
                                            onclick="editProgram(this)">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button class="action-btn btn-delete" title="Delete Program" type="button" onclick="deleteProgram(<?php echo (int)$program['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <button class="action-btn btn-toggle" title="<?php echo ($program['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate'; ?>" type="button"
                                            onclick="toggleProgramStatus(<?php echo (int)$program['id']; ?>, '<?php echo htmlspecialchars($program['status'] ?? 'active', ENT_QUOTES); ?>')">
                                            <i class="fas fa-<?php echo ($program['status'] ?? 'active') === 'active' ? 'pause' : 'play'; ?>"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($programList)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No programs found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($userRole !== 'instructor'): ?>
    <!-- Charts Row -->
    <div class="row g-4 mt-4">
        <div class="col-lg-8">
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-chart-line me-2"></i>Enrollment Trends (Last 6 Months)
                </div>
                <canvas id="enrollmentChart" height="120"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-chart-pie me-2"></i>Department Performance
                </div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Students</th>
                                <th>Courses</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deptPerformance as $dept): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($dept['name']); ?></td>
                                    <td><span class="badge bg-primary"><?php echo $dept['student_count']; ?></span></td>
                                    <td><span class="badge bg-info"><?php echo $dept['course_count']; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- Add Program Modal -->
<div class="modal fade" id="addProgramModal" tabindex="-1" aria-labelledby="addProgramModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--light-blue) 100%);">
                    <h5 class="modal-title text-white" id="addProgramModalTitle"><i class="fas fa-graduation-cap me-2"></i>Add Program</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="program_name" class="form-label">Program Name <span class="text-danger">*</span></label>
                        <input id="program_name" name="program_name" type="text" class="form-control" required maxlength="255" placeholder="e.g. Bachelor of Science in Information Technology">
                    </div>
                    <div class="mb-3">
                        <label for="program_department_id" class="form-label">Department <span class="text-danger">*</span></label>
                        <select id="program_department_id" name="program_department_id" class="form-select" required>
                            <option value="">Select active department</option>
                            <?php foreach ($activeDepartments as $dept): ?>
                                <option value="<?php echo (int)$dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label for="program_description" class="form-label">Description <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea id="program_description" name="program_description" class="form-control" rows="3" placeholder="Describe this program..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_program" value="1" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Add Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Program Modal -->
<div class="modal fade" id="viewProgramModal" tabindex="-1" aria-labelledby="viewProgramModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="viewProgramModalTitle"><i class="fas fa-graduation-cap me-2"></i>Program Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6 id="view_program_name" class="fw-bold text-primary"></h6>
                <p id="view_program_description" class="text-muted mb-3"></p>
                <dl class="row mb-0">
                    <dt class="col-sm-5">Department</dt>
                    <dd class="col-sm-7" id="view_program_department"></dd>
                    <dt class="col-sm-5">Courses</dt>
                    <dd class="col-sm-7" id="view_program_courses"></dd>
                    <dt class="col-sm-5">Students</dt>
                    <dd class="col-sm-7" id="view_program_students"></dd>
                    <dt class="col-sm-5">Status</dt>
                    <dd class="col-sm-7" id="view_program_status"></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<!-- Edit Program Modal -->
<div class="modal fade" id="editProgramModal" tabindex="-1" aria-labelledby="editProgramModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="program_id" id="edit_program_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="editProgramModalTitle"><i class="fas fa-pen me-2"></i>Edit Program</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_program_name" class="form-label">Program Name <span class="text-danger">*</span></label>
                        <input id="edit_program_name" name="program_name" type="text" class="form-control" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label for="edit_program_department_id" class="form-label">Department <span class="text-danger">*</span></label>
                        <select id="edit_program_department_id" name="program_department_id" class="form-select" required>
                            <option value="">Select active department</option>
                            <?php foreach ($activeDepartments as $dept): ?>
                                <option value="<?php echo (int)$dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label for="edit_program_description" class="form-label">Description</label>
                        <textarea id="edit_program_description" name="program_description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_program" value="1" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Program Status Modal -->
<div class="modal fade" id="toggleProgramModal" tabindex="-1" aria-labelledby="toggleProgramModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="program_id" id="toggle_program_id">
                <input type="hidden" name="status" id="toggle_program_status">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="toggleProgramModalTitle"><i class="fas fa-toggle-on me-2"></i>Change Program Status</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="toggle_program_message" class="mb-0"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_program_status" value="1" class="btn btn-success">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Program Modal -->
<div class="modal fade" id="deleteProgramModal" tabindex="-1" aria-labelledby="deleteProgramModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="program_id" id="delete_program_id">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteProgramModalTitle"><i class="fas fa-trash me-2"></i>Delete Program</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Are you sure you want to delete this program? Programs with linked courses, sections, or students cannot be deleted.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_program" value="1" class="btn btn-danger">Delete Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Department Modal -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:34px;height:34px;background:rgba(255,255,255,0.25);border-radius:9px;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-building" style="color: var(--primary-blue);"></i>
                        </div>
                        <h5 class="modal-title mb-0" style="color: var(--primary-blue); font-weight:700;">Add New Department</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Department Name <span class="text-danger">*</span></label>
                        <input type="text" name="department_name" class="form-control" required placeholder="e.g. College of Engineering">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-muted fw-normal" style="font-size:0.78rem;">(optional)</span></label>
                        <textarea name="department_description" class="form-control" rows="3" placeholder="Brief description…"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Cancel</button>
                    <button type="submit" name="create_department" class="btn" style="background:linear-gradient(135deg,var(--warning) 0%,#fbbf24 100%);color:var(--primary-blue);font-weight:700;">
                        <i class="fas fa-plus me-1"></i>Create Department
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Department Modal -->
<div class="modal fade" id="editDepartmentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="editDeptForm">
                <input type="hidden" name="department_id" id="edit_dept_id">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:34px;height:34px;background:rgba(255,255,255,0.25);border-radius:9px;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-pen" style="color: var(--primary-blue);"></i>
                        </div>
                        <h5 class="modal-title mb-0" style="color: var(--primary-blue); font-weight:700;">Edit Department</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="editDeptLoading" class="text-center py-3">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2 mb-0">Loading department…</p>
                    </div>
                    <div id="editDeptFields" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">Department Name <span class="text-danger">*</span></label>
                            <input type="text" name="department_name" id="edit_dept_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="department_description" id="edit_dept_description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="department_status" id="edit_dept_status" class="form-select">
                                <option value="active">✅ Active</option>
                                <option value="inactive">⛔ Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Cancel</button>
                    <button type="submit" name="update_department" class="btn" style="background:linear-gradient(135deg,var(--warning) 0%,#fbbf24 100%);color:var(--primary-blue);font-weight:700;">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Department Status Modal -->
<div class="modal fade" id="toggleDeptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--success) 0%, #34d399 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-toggle-on me-2"></i>Change Department Status</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="department_id" id="toggle_dept_id">
                    <input type="hidden" name="status" id="toggle_dept_status">
                    <p id="toggle_dept_message" class="mb-0"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_department_status" class="btn" style="background: var(--success); color: white;">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Course Status Modal -->
<div class="modal fade" id="toggleCourseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--success) 0%, #34d399 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-toggle-on me-2"></i>Change Course Status</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="course_id" id="toggle_course_id">
                    <input type="hidden" name="status" id="toggle_course_status">
                    <p id="toggle_course_message" class="mb-0"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_course_status" class="btn" style="background: var(--success); color: white;">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Instructor Modal -->
<div class="modal fade" id="assignInstructorModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-user-tie me-2"></i>Assign Instructor</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="course_id" id="assign_course_id">
                    <div class="mb-3">
                        <label class="form-label">Select Instructor</label>
                        <select name="instructor_id" id="reassign_instructor_id" class="form-select">
                            <option value="0">No Instructor Assigned</option>
                            <?php foreach ($instructors as $inst): ?>
                                <option value="<?php echo $inst['id']; ?>"><?php echo htmlspecialchars($inst['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_instructor" class="btn" style="background: var(--warning); color: white;">Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Department Modal -->
<div class="modal fade" id="deleteDeptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-trash me-2"></i>Delete Department</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="department_id" id="delete_dept_id">
                    <p id="delete_dept_message" class="mb-0"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_department" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Course Modal -->
<div class="modal fade" id="deleteCourseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-trash me-2"></i>Delete Course</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="course_id" id="delete_course_id">
                    <p id="delete_course_message" class="mb-0">Are you sure you want to delete this program/course? This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_course" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // Enrollment Chart
    const enrollmentCtx = document.getElementById('enrollmentChart').getContext('2d');
    new Chart(enrollmentCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($enrollmentTrends, 'month')); ?>,
            datasets: [{
                label: 'Enrollments',
                data: <?php echo json_encode(array_column($enrollmentTrends, 'count')); ?>,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#2563eb',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false } }
            }
        }
    });

    function viewProgram(button) {
        const program = button.dataset;
        document.getElementById('view_program_name').textContent = program.name;
        document.getElementById('view_program_description').textContent = program.description || 'No description provided.';
        document.getElementById('view_program_department').textContent = program.department;
        document.getElementById('view_program_courses').textContent = program.courses;
        document.getElementById('view_program_students').textContent = program.students;
        document.getElementById('view_program_status').textContent = program.status.charAt(0).toUpperCase() + program.status.slice(1);
        new bootstrap.Modal(document.getElementById('viewProgramModal')).show();
    }

    function editProgram(button) {
        const program = button.dataset;
        document.getElementById('edit_program_id').value = program.id;
        document.getElementById('edit_program_name').value = program.name;
        document.getElementById('edit_program_department_id').value = program.departmentId;
        document.getElementById('edit_program_description').value = program.description;
        new bootstrap.Modal(document.getElementById('editProgramModal')).show();
    }

    function toggleProgramStatus(id, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        document.getElementById('toggle_program_id').value = id;
        document.getElementById('toggle_program_status').value = newStatus;
        document.getElementById('toggle_program_message').textContent =
            'Are you sure you want to ' + (newStatus === 'active' ? 'activate' : 'deactivate') + ' this program?';
        new bootstrap.Modal(document.getElementById('toggleProgramModal')).show();
    }

    function deleteProgram(id) {
        document.getElementById('delete_program_id').value = id;
        new bootstrap.Modal(document.getElementById('deleteProgramModal')).show();
    }

    // Toggle Department Status
    function toggleDeptStatus(id, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        document.getElementById('toggle_dept_id').value = id;
        document.getElementById('toggle_dept_status').value = newStatus;
        document.getElementById('toggle_dept_message').textContent = 
            'Are you sure you want to ' + (newStatus === 'active' ? 'activate' : 'deactivate') + ' this department?';
        new bootstrap.Modal(document.getElementById('toggleDeptModal')).show();
    }

    // Toggle Course Status
    function toggleCourseStatus(id, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        document.getElementById('toggle_course_id').value = id;
        document.getElementById('toggle_course_status').value = newStatus;
        document.getElementById('toggle_course_message').textContent = 
            'Are you sure you want to ' + (newStatus === 'active' ? 'activate' : 'deactivate') + ' this course?';
        new bootstrap.Modal(document.getElementById('toggleCourseModal')).show();
    }

    // Assign Instructor
    function assignInstructor(courseId, currentInstructorId) {
        document.getElementById('assign_course_id').value = courseId;
        document.getElementById('reassign_instructor_id').value = currentInstructorId || '0';
        new bootstrap.Modal(document.getElementById('assignInstructorModal')).show();
    }

    // View Department
    function viewDepartment(id) {
        window.location.href = '../programs/department_view.php?department_id=' + id;
    }

    // View Course
    function viewCourse(id) {
        window.location.href = '../subjects/view.php?id=' + id;
    }

    // Delete Department
    function deleteDepartment(id, name) {
        document.getElementById('delete_dept_id').value = id;
        document.getElementById('delete_dept_message').textContent = 'Are you sure you want to delete the department "' + name + '"? This action cannot be undone.';
        new bootstrap.Modal(document.getElementById('deleteDeptModal')).show();
    }

    // Delete Course
    function deleteCourse(id) {
        document.getElementById('delete_course_id').value = id;
        document.getElementById('delete_course_message').textContent = 'Are you sure you want to delete this program/course? This action cannot be undone.';
        new bootstrap.Modal(document.getElementById('deleteCourseModal')).show();
    }

    // Edit Department — fetch data then open the modal
    async function editDepartment(id) {
        const modal = new bootstrap.Modal(document.getElementById('editDepartmentModal'));
        document.getElementById('edit_dept_id').value = id;
        document.getElementById('editDeptLoading').style.display = '';
        document.getElementById('editDeptFields').style.display = 'none';
        modal.show();
        try {
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('get_department', '1');
            url.searchParams.set('id', id);
            const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const data = await response.json();
            if (data.error) throw new Error(data.error);
            document.getElementById('edit_dept_name').value        = data.name        || '';
            document.getElementById('edit_dept_description').value = data.description || '';
            document.getElementById('edit_dept_status').value      = data.status      || 'active';
            document.getElementById('editDeptLoading').style.display = 'none';
            document.getElementById('editDeptFields').style.display  = '';
        } catch (err) {
            document.getElementById('editDeptLoading').innerHTML =
                '<p class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Failed to load department. Please try again.</p>';
        }
    }

    // Show toast notification if redirected after a successful save
    (function () {
        const params = new URLSearchParams(window.location.search);
        const msg = params.get('msg');
        if (params.get('saved') === '1' && msg) {
            const toastBody = document.getElementById('toastBody');
            if (toastBody) {
                toastBody.textContent = msg;
                const toastEl = document.getElementById('liveToast');
                const toast = new bootstrap.Toast(toastEl, { delay: 5000 });
                toast.show();
                // Clean the URL so refreshing doesn't re-show the toast
                const cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('saved');
                cleanUrl.searchParams.delete('msg');
                window.history.replaceState({}, '', cleanUrl.toString());
            }
        }
    })();

</script>

<?php include '../includes/footer.php'; ?>