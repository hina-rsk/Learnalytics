<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Only instructors can manage quiz assignments
if ($role !== 'instructor') {
    header("Location: index.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$quiz_id = (int)$_GET['id'];

// Verify quiz belongs to this instructor
$stmt = $pdo->prepare("
    SELECT q.*, s.title as subject_title, s.instructor_id
    FROM quizzes q
    JOIN subjects s ON q.subject_id = s.id
    WHERE q.id = ?
");
$stmt->execute([$quiz_id]);
$quiz = $stmt->fetch();

if (!$quiz || $quiz['instructor_id'] != $user_id) {
    $_SESSION['error'] = "Quiz not found or access denied.";
    header("Location: index.php");
    exit;
}

// Get current assignments
$stmt = $pdo->prepare("
    SELECT qa.*, 
           CASE 
               WHEN qa.assignment_type = 'section' THEN s.name
               WHEN qa.assignment_type = 'program' THEN p.name
               WHEN qa.assignment_type = 'student' THEN u.name
               ELSE qa.year_level
           END as target_name
    FROM quiz_assignments qa
    LEFT JOIN sections s ON qa.target_id = s.id AND qa.assignment_type = 'section'
    LEFT JOIN programs p ON qa.target_id = p.id AND qa.assignment_type = 'program'
    LEFT JOIN users u ON qa.target_id = u.id AND qa.assignment_type = 'student'
    WHERE qa.quiz_id = ?
    ORDER BY qa.assignment_type, target_name
");
$stmt->execute([$quiz_id]);
$assignments = $stmt->fetchAll();

// Get available sections for this subject
$stmt = $pdo->prepare("
    SELECT DISTINCT s.id, s.name, p.name as program_name
    FROM sections s
    JOIN programs p ON s.program_id = p.id
    WHERE s.id IN (
        SELECT DISTINCT sp.section_id 
        FROM student_profiles sp
        JOIN enrollments e ON sp.student_id = e.student_id
        WHERE e.subject_id = ? AND sp.section_id IS NOT NULL
    )
      AND s.id NOT IN (
          SELECT target_id FROM quiz_assignments WHERE quiz_id = ? AND assignment_type = 'section'
      )
    ORDER BY p.name, s.name
");
$stmt->execute([$quiz['subject_id'], $quiz_id]);
$available_sections = $stmt->fetchAll();

// Get available students enrolled in this subject who are not yet assigned individually
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.name, u.email, sp.student_id_number, sp.year_level
    FROM users u
    JOIN enrollments e ON u.id = e.student_id
    LEFT JOIN student_profiles sp ON u.id = sp.student_id
    WHERE e.subject_id = ?
      AND u.id NOT IN (
          SELECT target_id FROM quiz_assignments WHERE quiz_id = ? AND assignment_type = 'student' AND target_id IS NOT NULL
      )
    ORDER BY u.name
");
$stmt->execute([$quiz['subject_id'], $quiz_id]);
$students = $stmt->fetchAll();

// Handle adding assignments
$assignment_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_section'])) {
        $section_id = (int)$_POST['section_id'];
        // Check if not already assigned
        $check = $pdo->prepare("SELECT id FROM quiz_assignments WHERE quiz_id = ? AND assignment_type = 'section' AND target_id = ?");
        $check->execute([$quiz_id, $section_id]);
        if (!$check->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id) VALUES (?, 'section', ?)");
            $stmt->execute([$quiz_id, $section_id]);
        }
    } elseif (isset($_POST['add_class'])) {
        $class_code = strtoupper(trim($_POST['class_code'] ?? ''));
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND class_code = ?");
        $stmt->execute([$quiz['subject_id'], $class_code]);

        if (!$stmt->fetch()) {
            $assignment_error = 'Class not found. Enter the class code for this quiz subject.';
        } else {
            $check = $pdo->prepare("SELECT id FROM quiz_assignments WHERE quiz_id = ? AND assignment_type = 'subject'");
            $check->execute([$quiz_id]);
            if (!$check->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id) VALUES (?, 'subject', NULL)");
                $stmt->execute([$quiz_id]);
            }
        }
    } elseif (isset($_POST['add_student'])) {
        $student_id = (int)$_POST['student_id'];
        $check = $pdo->prepare("SELECT id FROM quiz_assignments WHERE quiz_id = ? AND assignment_type = 'student' AND target_id = ?");
        $check->execute([$quiz_id, $student_id]);
        if (!$check->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id) VALUES (?, 'student', ?)");
            $stmt->execute([$quiz_id, $student_id]);
        }
    } elseif (isset($_POST['remove_assignment'])) {
        $assignment_id = (int)$_POST['assignment_id'];
        // Don't allow removing the default subject assignment if it's the only one
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_assignments WHERE quiz_id = ?");
        $stmt->execute([$quiz_id]);
        $total = $stmt->fetchColumn();
        
        if ($total > 1) {
            $stmt = $pdo->prepare("DELETE FROM quiz_assignments WHERE id = ? AND quiz_id = ?");
            $stmt->execute([$assignment_id, $quiz_id]);
        }
    }
    
    if ($assignment_error === '') {
        header("Location: assignments.php?id=" . $quiz_id);
        exit;
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php" style="color: #2563eb;">Quizzes</a></li>
                    <li class="breadcrumb-item active" style="color: #1e3a8a;">Manage Assignments</li>
                </ol>
            </nav>
            <h1 class="h2" style="color: #1e3a8a;">
                <i class="fas fa-users-cog me-2"></i>Manage Quiz Assignments
            </h1>
            <p class="text-muted mb-0">
                <?php echo htmlspecialchars($quiz['title']); ?> - <?php echo htmlspecialchars($quiz['subject_title']); ?>
            </p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Quizzes
        </a>
    </div>

    <?php if ($quiz['status'] === 'published'): ?>
        <div class="alert alert-warning" style="background-color: #fef3c7; color: #92400e; border-color: #f59e0b;">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Quiz is Published:</strong> Changes to assignments will affect which students can access this quiz.
        </div>
    <?php endif; ?>

    <?php if ($assignment_error): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($assignment_error); ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Current Assignments -->
        <div class="col-lg-6">
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0"><i class="fas fa-list-check me-2"></i>Current Assignments (<?php echo count($assignments); ?>)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($assignments)): ?>
                        <p class="text-muted">No specific assignments. Quiz is available to all students in the subject.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($assignments as $assignment): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <?php
                                        $badge_class = 'secondary';
                                        $icon = 'fa-question';
                                        $name = 'Unknown assignment';
                                        switch ($assignment['assignment_type']) {
                                            case 'subject':
                                                $badge_class = 'primary';
                                                $icon = 'fa-book';
                                                $name = 'All Students in Subject';
                                                break;
                                            case 'section':
                                                $badge_class = 'info';
                                                $icon = 'fa-users';
                                                $name = 'Section: ' . ($assignment['target_name'] ?? 'Unknown');
                                                break;
                                            case 'program':
                                                $badge_class = 'success';
                                                $icon = 'fa-graduation-cap';
                                                $name = 'Program: ' . ($assignment['target_name'] ?? 'Unknown');
                                                break;
                                            case 'year_level':
                                                $badge_class = 'warning';
                                                $icon = 'fa-layer-group';
                                                $name = 'Year Level: ' . ($assignment['year_level'] ?? 'Unknown');
                                                break;
                                            case 'student':
                                                $badge_class = 'danger';
                                                $icon = 'fa-user';
                                                $name = 'Student: ' . ($assignment['target_name'] ?? 'Unknown');
                                                break;
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $badge_class; ?> me-2">
                                            <i class="fas <?php echo $icon; ?>"></i>
                                        </span>
                                        <span><?php echo htmlspecialchars($name); ?></span>
                                    </div>
                                    <?php if ($assignment['assignment_type'] !== 'subject'): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this assignment?');">
                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                            <button type="submit" name="remove_assignment" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Add Assignments -->
        <div class="col-lg-6">
            <!-- Add Sections -->
            <?php if (!empty($available_sections)): ?>
                <div class="card shadow border-0 mb-4">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0"><i class="fas fa-users me-2"></i>Add Section</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <select name="section_id" class="form-select" required style="border-color: #2563eb;">
                                    <option value="">Select a section...</option>
                                    <?php foreach ($available_sections as $section): ?>
                                        <option value="<?php echo $section['id']; ?>">
                                            <?php echo htmlspecialchars($section['name']); ?> (<?php echo htmlspecialchars($section['program_name']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="add_section" class="btn btn-outline-info btn-sm">
                                <i class="fas fa-plus me-1"></i>Add Section
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Add Class -->
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0"><i class="fas fa-chalkboard me-2"></i>Add Class</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label for="class_code" class="form-label">Class Code</label>
                            <input type="text" id="class_code" name="class_code" class="form-control" required maxlength="10" placeholder="Enter class code" style="border-color: #2563eb;" value="<?php echo htmlspecialchars($_POST['class_code'] ?? ''); ?>">
                        </div>
                        <button type="submit" name="add_class" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-plus me-1"></i>Add Class
                        </button>
                    </form>
                </div>
            </div>

            <!-- Add Specific Student -->
            <?php if (!empty($students)): ?>
                <div class="card shadow border-0 mb-4">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0"><i class="fas fa-user-plus me-2"></i>Add Specific Student</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <select name="student_id" class="form-select" required style="border-color: #2563eb;">
                                    <option value="">Select a student...</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?php echo $student['id']; ?>">
                                            <?php echo htmlspecialchars($student['name']); ?> (<?php echo htmlspecialchars($student['student_id_number']); ?> - <?php echo $student['year_level']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="add_student" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-plus me-1"></i>Add Student
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card shadow border-0">
                <div class="card-body">
                    <div class="alert alert-info mb-0" style="background-color: #dbeafe; color: #1e3a8a; border-color: #2563eb;">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Assignment Rules:</strong>
                        <ul class="mb-0 mt-2">
                            <li>By default, quizzes are assigned to all students in the subject.</li>
                            <li>Add specific classes, sections, or students to restrict access.</li>
                            <li>When specific assignments exist, only those groups/students can access the quiz.</li>
                            <li>You can assign by year level to target all students in a particular year.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>