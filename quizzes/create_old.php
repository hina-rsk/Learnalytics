<?php
session_start();
require_once '../config/database.php';

// Quiz Creation Wizard - Modern Implementation
// Version: 2026-06-03
// Last Updated: Multi-step wizard with modern responsive design

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? '';

// Only instructors can create quizzes
if ($role !== 'instructor') {
    header('Location: index.php');
    exit;
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = ? 
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function ensureQuizAssignmentsTable(PDO $pdo): bool
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT NOT NULL,
            assignment_type ENUM('subject', 'section', 'program', 'year_level', 'student') NOT NULL DEFAULT 'subject',
            target_id INT NULL DEFAULT NULL,
            year_level VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
            INDEX idx_quiz_assignments_quiz (quiz_id),
            INDEX idx_quiz_assignments_type (assignment_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

$hasEnhancedQuizSchema = hasColumn($pdo, 'quizzes', 'status');
$hasQuizAssignments = in_array('quiz_assignments', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));

$stmt = $pdo->prepare('SELECT id, title FROM subjects WHERE instructor_id = ? ORDER BY title');
$stmt->execute([$user_id]);
$subjects = $stmt->fetchAll();

$selected_subject_id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : (isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0);
$sections = [];
if ($selected_subject_id > 0) {
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name, p.name AS program_name, ay.name AS academic_year
         FROM sections s
         JOIN programs p ON s.program_id = p.id
         JOIN academic_years ay ON s.academic_year_id = ay.id
         WHERE s.id IN (
             SELECT DISTINCT sp.section_id
             FROM student_profiles sp
             JOIN enrollments e ON sp.student_id = e.student_id
             WHERE e.subject_id = ? AND sp.section_id IS NOT NULL
         )
         ORDER BY p.name, s.name"
    );
    $stmt->execute([$selected_subject_id]);
    $sections = $stmt->fetchAll();
}

$programs = $pdo->query("SELECT id, name FROM programs WHERE status = 'active' ORDER BY name")->fetchAll();

$title = $_POST['title'] ?? '';
$description = $_POST['description'] ?? '';
$due_date = $_POST['due_date'] ?? '';
$time_limit = $_POST['time_limit'] ?? '';
$attempt_limit = isset($_POST['attempt_limit']) ? (int)$_POST['attempt_limit'] : 1;
$show_results = isset($_POST['show_results']) ? 1 : 0;
$shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_quiz'])) {
    $title = trim($title);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $description = trim($description);
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $time_limit = !empty($_POST['time_limit']) ? (int)$_POST['time_limit'] : null;
    $attempt_limit = isset($_POST['attempt_limit']) ? (int)$_POST['attempt_limit'] : 1;
    $show_results = isset($_POST['show_results']) ? 1 : 0;
    $shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;

    if ($subject_id <= 0) {
        $error = 'Please select a subject.';
    } elseif ($title === '') {
        $error = 'Please enter a quiz title.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM subjects WHERE id = ? AND instructor_id = ?');
        $stmt->execute([$subject_id, $user_id]);
        $subject = $stmt->fetch();

        if (!$subject) {
            $error = 'You do not have permission to create a quiz for this subject.';
        } else {
            try {
                if ($hasEnhancedQuizSchema && !$hasQuizAssignments) {
                    if (!ensureQuizAssignmentsTable($pdo)) {
                        throw new Exception('Unable to create or access quiz_assignments table.');
                    }
                    $hasQuizAssignments = true;
                }

                // Use the logged-in user's ID as the instructor_id
                // We've already verified at login and at subject selection that this user is an instructor
                // and that they own the subject (verification at lines 107-112)
                $instructor_id = $user_id;

                // Final verification: ensure the subject still exists and belongs to this instructor
                $stmt = $pdo->prepare('SELECT id, instructor_id FROM subjects WHERE id = ? AND instructor_id = ?');
                $stmt->execute([$subject_id, $instructor_id]);
                $subjectData = $stmt->fetch();

                if (!$subjectData) {
                    throw new Exception('Subject not found or access denied. Please try again.');
                }

                // Verify instructor still exists in users table (for FK constraint)
                $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
                $stmt->execute([$instructor_id]);

                if (!$stmt->fetch()) {
                    throw new Exception('Instructor account not found. Please contact support.');
                }

                $pdo->beginTransaction();

                if ($hasEnhancedQuizSchema) {
                    $stmt = $pdo->prepare('INSERT INTO quizzes (subject_id, instructor_id, title, description, status, due_date, time_limit, attempt_limit, show_results, shuffle_questions) VALUES (?, ?, ?, ?, \'draft\', ?, ?, ?, ?, ?)');
                    
                    // Log exact values being inserted
                    error_log('Quiz INSERT about to execute: subject_id=' . var_export($subject_id, true) . ', instructor_id=' . var_export($instructor_id, true) . ', title=' . var_export($title, true));
                    
                    $stmt->execute([(int)$subject_id, (int)$instructor_id, $title, $description, $due_date, $time_limit, $attempt_limit, $show_results, $shuffle_questions]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO quizzes (subject_id, title) VALUES (?, ?)');
                    $stmt->execute([$subject_id, $title]);
                }

                $quiz_id = (int)$pdo->lastInsertId();

                if ($hasQuizAssignments && $hasEnhancedQuizSchema) {
                    $stmt = $pdo->prepare('INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id) VALUES (?, \'subject\', NULL)');
                    $stmt->execute([$quiz_id]);

                    if (!empty($_POST['section_ids']) && is_array($_POST['section_ids'])) {
                        $sectionStmt = $pdo->prepare('INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id) VALUES (?, \'section\', ?)');
                        foreach ($_POST['section_ids'] as $section_id) {
                            $section_id = (int)$section_id;
                            if ($section_id > 0) {
                                $sectionStmt->execute([$quiz_id, $section_id]);
                            }
                        }
                    }

                    if (!empty($_POST['program_ids']) && is_array($_POST['program_ids'])) {
                        $programStmt = $pdo->prepare('INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id) VALUES (?, \'program\', ?)');
                        foreach ($_POST['program_ids'] as $program_id) {
                            $program_id = (int)$program_id;
                            if ($program_id > 0) {
                                $programStmt->execute([$quiz_id, $program_id]);
                            }
                        }
                    }

                    if (!empty($_POST['year_levels']) && is_array($_POST['year_levels'])) {
                        $yearStmt = $pdo->prepare('INSERT INTO quiz_assignments (quiz_id, assignment_type, year_level) VALUES (?, \'year_level\', ?)');
                        foreach ($_POST['year_levels'] as $year_level) {
                            $year_level = trim($year_level);
                            if ($year_level !== '') {
                                $yearStmt->execute([$quiz_id, $year_level]);
                            }
                        }
                    }
                }

                $pdo->commit();
                header('Location: add_questions.php?id=' . $quiz_id . '&created=1');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $debugDetails = '';
                if (isset($subject_id)) {
                    $debugDetails .= 'Subject ID: ' . $subject_id . '. ';
                }
                if (isset($instructor_id)) {
                    $debugDetails .= 'Instructor ID: ' . $instructor_id . '. ';
                    $checkStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
                    $checkStmt->execute([$instructor_id]);
                    $userExists = $checkStmt->fetch();
                    $debugDetails .= 'User exists: ' . ($userExists ? 'Yes (' . htmlspecialchars($userExists['role']) . ')' : 'No') . '. ';
                }
                if (isset($subject_id)) {
                    $subjStmt = $pdo->prepare('SELECT instructor_id FROM subjects WHERE id = ?');
                    $subjStmt->execute([$subject_id]);
                    $subjData = $subjStmt->fetch();
                    $debugDetails .= 'Subject instructor_id: ' . ($subjData['instructor_id'] ?? 'NULL') . '. ';
                }

                $error = 'Error creating quiz: ' . $e->getMessage() . ' <br>Debug: ' . $debugDetails;
            }
        }
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;"><i class="fas fa-plus-circle me-2"></i>Create New Quiz</h1>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Quizzes
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="background-color: #fee2e2; color: #991b1b; border-color: #f87171;">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="row">
            <div class="col-lg-8">
                <div class="card shadow border-0 mb-4">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0"><i class="fas fa-cog me-2"></i>Quiz Settings</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Select Subject <span class="text-danger">*</span></label>
                            <select name="subject_id" id="subject_id" class="form-select" required style="border-color: #2563eb;">
                                <option value="">Choose a subject...</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>" <?php echo $selected_subject_id === (int)$subject['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Quiz Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required style="border-color: #2563eb;" placeholder="Enter quiz title" value="<?php echo htmlspecialchars($title); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Description (Optional)</label>
                            <textarea name="description" class="form-control" rows="3" style="border-color: #2563eb;" placeholder="Add instructions or description for students..."><?php echo htmlspecialchars($description); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="card shadow border-0 mb-4">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0"><i class="fas fa-sliders-h me-2"></i>Quiz Options</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" style="color: #1e3a8a;">Due Date</label>
                                <input type="datetime-local" name="due_date" class="form-control" style="border-color: #2563eb;" value="<?php echo htmlspecialchars($due_date); ?>">
                                <small class="text-muted">Leave empty for no deadline</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" style="color: #1e3a8a;">Time Limit (minutes)</label>
                                <input type="number" name="time_limit" class="form-control" min="1" max="180" style="border-color: #2563eb;" placeholder="e.g., 30" value="<?php echo htmlspecialchars($time_limit); ?>">
                                <small class="text-muted">Leave empty for no time limit</small>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" style="color: #1e3a8a;">Attempt Limit</label>
                                <select name="attempt_limit" class="form-select" style="border-color: #2563eb;">
                                    <option value="1" <?php echo $attempt_limit === 1 ? 'selected' : ''; ?>>One attempt only</option>
                                    <option value="2" <?php echo $attempt_limit === 2 ? 'selected' : ''; ?>>Two attempts</option>
                                    <option value="3" <?php echo $attempt_limit === 3 ? 'selected' : ''; ?>>Three attempts</option>
                                    <option value="0" <?php echo $attempt_limit === 0 ? 'selected' : ''; ?>>Unlimited attempts</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" name="show_results" id="show_results" value="1" <?php echo $show_results ? 'checked' : ''; ?> style="border-color: #2563eb; accent-color: #2563eb;">
                                    <label class="form-check-label" style="color: #1e3a8a;" for="show_results">
                                        Show results to students after submission
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="shuffle_questions" id="shuffle_questions" value="1" <?php echo $shuffle_questions ? 'checked' : ''; ?> style="border-color: #2563eb; accent-color: #2563eb;">
                                    <label class="form-check-label" style="color: #1e3a8a;" for="shuffle_questions">
                                        Shuffle question order for each student
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow border-0 mb-4">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0"><i class="fas fa-users me-2"></i>Assign To</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">By default, quizzes are assigned to all students in the subject. Add specific sections, programs, or year levels to narrow the assignment.</p>

                        <?php if (!empty($sections)): ?>
                            <div class="mb-3">
                                <label class="form-label" style="color: #1e3a8a;">Specific Sections</label>
                                <?php foreach ($sections as $section): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="section_ids[]" value="<?php echo $section['id']; ?>" id="section_<?php echo $section['id']; ?>" style="border-color: #2563eb; accent-color: #2563eb;">
                                        <label class="form-check-label" for="section_<?php echo $section['id']; ?>" style="color: #1e3a8a;">
                                            <?php echo htmlspecialchars($section['name']); ?><?php echo $section['program_name'] ? ' - ' . htmlspecialchars($section['program_name']) : ''; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Programs</label>
                            <?php foreach ($programs as $program): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="program_ids[]" value="<?php echo $program['id']; ?>" id="program_<?php echo $program['id']; ?>" style="border-color: #2563eb; accent-color: #2563eb;">
                                    <label class="form-check-label" for="program_<?php echo $program['id']; ?>" style="color: #1e3a8a;">
                                        <?php echo htmlspecialchars($program['name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Year Levels</label>
                            <?php foreach (['1st Year','2nd Year','3rd Year','4th Year','Other'] as $year_level): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="year_levels[]" value="<?php echo $year_level; ?>" id="year_<?php echo htmlspecialchars($year_level); ?>" style="border-color: #2563eb; accent-color: #2563eb;">
                                    <label class="form-check-label" for="year_<?php echo htmlspecialchars($year_level); ?>" style="color: #1e3a8a;">
                                        <?php echo $year_level; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card shadow border-0">
                    <div class="card-body">
                        <div class="alert alert-info" style="background-color: #dbeafe; color: #1e3a8a; border-color: #2563eb;">
                            <i class="fas fa-info-circle me-2"></i>
                            <small>Once the quiz is created you can add questions and publish it to students.</small>
                        </div>

                        <button type="submit" name="create_quiz" class="btn w-100" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none; font-weight: 600;">
                            <i class="fas fa-plus me-2"></i>Create Quiz
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary w-100 mt-2">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </form>
</main>

<script>
    document.getElementById('subject_id').addEventListener('change', function () {
        var subjectId = this.value;
        if (subjectId) {
            window.location.href = 'create.php?subject_id=' + subjectId;
        }
    });
</script>

<?php include '../includes/footer.php'; ?>
