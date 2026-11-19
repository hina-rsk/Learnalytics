<?php
session_start();
require_once '../config/database.php';

// Modern Quiz Creation Wizard
// Version: 2026-06-03
// Features: Multi-step form, responsive design, all question types, assignment targeting

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? '';

if ($role !== 'instructor') {
    header('Location: index.php');
    exit;
}

// Helper functions
function hasColumn(PDO $pdo, string $table, string $column): bool {
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

function ensureQuizAssignmentsTable(PDO $pdo): bool {
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
        error_log('Error creating quiz_assignments table: ' . $e->getMessage());
        return false;
    }
}

// Check schema capabilities
$hasEnhancedQuizSchema = hasColumn($pdo, 'quizzes', 'status');
$hasQuizAssignments = in_array('quiz_assignments', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));

// Load subjects for this instructor
$stmt = $pdo->prepare('SELECT id, title FROM subjects WHERE instructor_id = ? ORDER BY title');
$stmt->execute([$user_id]);
$subjects = $stmt->fetchAll();

// Initialize form state
$current_step = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$error = '';
$success = '';

// Load form data from session if exists
$form_data = $_SESSION['quiz_form'] ?? [];

// Pre-populate subject_id from GET parameter if set and not already in form_data
if (isset($_GET['subject_id']) && !isset($form_data['subject_id'])) {
    $get_subject_id = (int)$_GET['subject_id'];
    if ($get_subject_id > 0) {
        $form_data['subject_id'] = $get_subject_id;
        $_SESSION['quiz_form']['subject_id'] = $get_subject_id;
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Step 1: Quiz Information
    if ($current_step == 1 && isset($_POST['save_step_1'])) {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $time_limit = !empty($_POST['time_limit']) ? (int)$_POST['time_limit'] : null;
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        
        // Validate step 1
        if ($subject_id <= 0) {
            $error = 'Please select a subject.';
        } elseif (empty($title)) {
            $error = 'Please enter a quiz title.';
        } else {
            // Verify subject ownership
            $stmt = $pdo->prepare('SELECT id, instructor_id FROM subjects WHERE id = ? AND instructor_id = ?');
            $stmt->execute([$subject_id, $user_id]);
            
            if (!$stmt->fetch()) {
                $error = 'You do not have permission to create a quiz for this subject.';
            } else {
                // Save step 1 data
                $_SESSION['quiz_form']['subject_id'] = $subject_id;
                $_SESSION['quiz_form']['title'] = $title;
                $_SESSION['quiz_form']['description'] = $description;
                $_SESSION['quiz_form']['time_limit'] = $time_limit;
                $_SESSION['quiz_form']['due_date'] = $due_date;
                $current_step = 2;
                $form_data = $_SESSION['quiz_form'];
            }
        }
    }
    
    // Step 2: Quiz Settings
    elseif ($current_step == 2 && isset($_POST['save_step_2'])) {
        if (!isset($_SESSION['quiz_form']['subject_id'])) {
            $error = 'Session data lost. Please start over.';
            $current_step = 1;
        } else {
            // Save step 2 data
            $_SESSION['quiz_form']['attempt_limit'] = (int)($_POST['attempt_limit'] ?? 1);
            $_SESSION['quiz_form']['show_results'] = isset($_POST['show_results']) ? 1 : 0;
            $_SESSION['quiz_form']['shuffle_questions'] = isset($_POST['shuffle_questions']) ? 1 : 0;
            $_SESSION['quiz_form']['passing_score'] = (int)($_POST['passing_score'] ?? 0);
            $current_step = 3;
            $form_data = $_SESSION['quiz_form'];
        }
    }
    
    // Step 3: Quiz Assignment & Publishing
    elseif ($current_step == 3 && isset($_POST['create_quiz'])) {
        if (!isset($_SESSION['quiz_form']['subject_id'])) {
            $error = 'Session data lost. Please start over.';
            $current_step = 1;
        } else {
            try {
                $form_data = $_SESSION['quiz_form'];
                
                // Ensure quiz_assignments table exists
                if ($hasEnhancedQuizSchema && !ensureQuizAssignmentsTable($pdo)) {
                    throw new Exception('Unable to create or access quiz_assignments table.');
                }
                
                // Use the logged-in user's ID as the instructor_id
                // We've already verified at login and at subject selection that this user is an instructor
                // and that they own the subject (step 1 verification at lines 87-91)
                $instructor_id = $user_id;
                
                // Final verification: ensure the subject still exists and belongs to this instructor
                $stmt = $pdo->prepare('SELECT id, instructor_id FROM subjects WHERE id = ? AND instructor_id = ?');
                $stmt->execute([$form_data['subject_id'], $instructor_id]);
                $subject = $stmt->fetch();
                
                if (!$subject) {
                    throw new Exception('Subject not found or access denied. Please try again.');
                }
                
                // Verify instructor still exists in users table (for FK constraint)
                $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
                $stmt->execute([$instructor_id]);
                
                if (!$stmt->fetch()) {
                    throw new Exception('Instructor account not found. Please contact support.');
                }
                
                $pdo->beginTransaction();
                
                // Create the quiz
                if ($hasEnhancedQuizSchema) {
                    $stmt = $pdo->prepare('
                        INSERT INTO quizzes 
                        (subject_id, instructor_id, title, description, status, due_date, time_limit, attempt_limit, show_results, shuffle_questions) 
                        VALUES (?, ?, ?, ?, "draft", ?, ?, ?, ?, ?)
                    ');
                    
                    $stmt->execute([
                        (int)$form_data['subject_id'],
                        (int)$instructor_id,
                        $form_data['title'],
                        $form_data['description'] ?? '',
                        $form_data['due_date'] ?? null,
                        $form_data['time_limit'] ?? null,
                        $form_data['attempt_limit'] ?? 1,
                        $form_data['show_results'] ?? 0,
                        $form_data['shuffle_questions'] ?? 0
                    ]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO quizzes (subject_id, title) VALUES (?, ?)');
                    $stmt->execute([(int)$form_data['subject_id'], $form_data['title']]);
                }
                
                $quiz_id = (int)$pdo->lastInsertId();
                
                // Handle assignments
                if ($hasQuizAssignments && $hasEnhancedQuizSchema) {
                    $hasSpecificAssignment = false;

                    // Assign to specific students if selected
                    if (!empty($_POST['student_ids']) && is_array($_POST['student_ids'])) {
                        $studentStmt = $pdo->prepare('INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id) VALUES (?, "student", ?)');
                        foreach ($_POST['student_ids'] as $student_id) {
                            $student_id = (int)$student_id;
                            if ($student_id > 0) {
                                $studentStmt->execute([$quiz_id, $student_id]);
                                $hasSpecificAssignment = true;
                            }
                        }
                    }

                    // Assign to sections if selected
                    if (!empty($_POST['section_ids']) && is_array($_POST['section_ids'])) {
                        $sectionStmt = $pdo->prepare('INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id) VALUES (?, "section", ?)');
                        foreach ($_POST['section_ids'] as $section_id) {
                            $section_id = (int)$section_id;
                            if ($section_id > 0) {
                                $sectionStmt->execute([$quiz_id, $section_id]);
                                $hasSpecificAssignment = true;
                            }
                        }
                    }

                    // Assign to programs if selected
                    if (!empty($_POST['program_ids']) && is_array($_POST['program_ids'])) {
                        $programStmt = $pdo->prepare('INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id) VALUES (?, "program", ?)');
                        foreach ($_POST['program_ids'] as $program_id) {
                            $program_id = (int)$program_id;
                            if ($program_id > 0) {
                                $programStmt->execute([$quiz_id, $program_id]);
                                $hasSpecificAssignment = true;
                            }
                        }
                    }

                    if (!$hasSpecificAssignment) {
                        $stmt = $pdo->prepare('INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id) VALUES (?, "subject", NULL)');
                        $stmt->execute([$quiz_id]);
                    }
                }
                
                $pdo->commit();
                
                // Clear form session and redirect
                unset($_SESSION['quiz_form']);
                header('Location: add_questions.php?id=' . $quiz_id . '&created=1');
                exit;
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Quiz creation error: ' . $e->getMessage());
                $error = 'Error creating quiz: ' . $e->getMessage();
                $current_step = 3;
            }
        }
    }
    
    // Go back step
    elseif (isset($_POST['prev_step'])) {
        $current_step = max(1, $current_step - 1);
    }
}

// Fetch sections, programs, and enrolled students for selected subject
$sections = [];
$programs = [];
$enrolled_students = [];
if (!empty($form_data['subject_id'])) {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT s.id, s.name, p.name AS program_name, ay.name AS academic_year
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
    $stmt->execute([$form_data['subject_id']]);
    $sections = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT DISTINCT p.id, p.name FROM programs p ORDER BY p.name");
    $stmt->execute();
    $programs = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.id, u.name, u.email, sp.student_id_number, sp.year_level
         FROM users u
         JOIN enrollments e ON u.id = e.student_id
         LEFT JOIN student_profiles sp ON u.id = sp.student_id
         WHERE e.subject_id = ?
         ORDER BY u.name"
    );
    $stmt->execute([$form_data['subject_id']]);
    $enrolled_students = $stmt->fetchAll();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    :root {
        --primary-blue: #1e3a8a;
        --secondary-blue: #2563eb;
        --light-blue: #3b82f6;
        --primary-yellow: #fbbf24;
        --secondary-yellow: #f59e0b;
    }
    
    .wizard-container {
        max-width: 900px;
        margin: 0 auto;
        padding: 30px 20px;
    }
    
    .wizard-header {
        text-align: center;
        margin-bottom: 50px;
    }
    
    .wizard-header h1 {
        color: var(--primary-blue);
        font-weight: 700;
        margin-bottom: 10px;
    }
    
    .wizard-header p {
        color: #666;
        font-size: 1.1rem;
    }
    
    .wizard-steps {
        display: flex;
        justify-content: space-between;
        margin-bottom: 40px;
        position: relative;
    }
    
    .wizard-steps::before {
        content: '';
        position: absolute;
        top: 20px;
        left: 0;
        right: 0;
        height: 2px;
        background-color: #e0e0e0;
        z-index: 0;
    }
    
    .step-item {
        flex: 1;
        text-align: center;
        position: relative;
        z-index: 1;
    }
    
    .step-number {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background-color: white;
        border: 3px solid #ddd;
        font-weight: 700;
        color: #999;
        margin-bottom: 10px;
        transition: all 0.3s ease;
    }
    
    .step-item.active .step-number {
        border-color: var(--secondary-blue);
        background-color: var(--secondary-blue);
        color: white;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
    }
    
    .step-item.completed .step-number {
        border-color: #22c55e;
        background-color: #22c55e;
        color: white;
    }
    
    .step-title {
        font-weight: 600;
        color: #333;
        font-size: 0.95rem;
    }
    
    .step-item:not(.active):not(.completed) .step-title {
        color: #999;
    }
    
    .wizard-content {
        background: white;
        border-radius: 12px;
        padding: 40px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        margin-bottom: 30px;
    }
    
    .form-group {
        margin-bottom: 25px;
    }
    
    .form-label {
        display: block;
        font-weight: 600;
        color: var(--primary-blue);
        margin-bottom: 8px;
        font-size: 0.95rem;
    }
    
    .form-label .required {
        color: #ef4444;
    }
    
    .form-control, .form-select {
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        padding: 12px 15px;
        font-size: 1rem;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: var(--secondary-blue);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        outline: none;
    }
    
    .form-text {
        display: block;
        margin-top: 6px;
        color: #666;
        font-size: 0.9rem;
    }
    
    .checkbox-group {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 15px;
    }
    
    .checkbox-item {
        display: flex;
        align-items: center;
        padding: 12px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .checkbox-item:hover {
        border-color: var(--secondary-blue);
        background-color: #f0f9ff;
    }
    
    .checkbox-item input[type="checkbox"] {
        margin-right: 10px;
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: var(--secondary-blue);
    }
    
    .checkbox-item label {
        margin: 0;
        cursor: pointer;
        flex: 1;
    }
    
    .radio-group {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    
    .radio-item {
        display: flex;
        align-items: center;
        padding: 12px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .radio-item:hover {
        border-color: var(--secondary-blue);
        background-color: #f0f9ff;
    }
    
    .radio-item input[type="radio"] {
        margin-right: 10px;
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: var(--secondary-blue);
    }
    
    .radio-item label {
        margin: 0;
        cursor: pointer;
        flex: 1;
    }
    
    .wizard-actions {
        display: flex;
        justify-content: space-between;
        gap: 15px;
        margin-top: 30px;
    }
    
    .btn-wizard {
        padding: 12px 30px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-primary-wizard {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
        color: white;
    }
    
    .btn-primary-wizard:hover {
        background: linear-gradient(135deg, #152e4d 0%, #1e40af 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    }
    
    .btn-secondary-wizard {
        background-color: #e5e7eb;
        color: #333;
    }
    
    .btn-secondary-wizard:hover {
        background-color: #d1d5db;
    }
    
    .btn-wizard:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }
    
    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .alert-error {
        background-color: #fee2e2;
        border-left: 4px solid #ef4444;
        color: #991b1b;
    }
    
    .alert-success {
        background-color: #dcfce7;
        border-left: 4px solid #22c55e;
        color: #15803d;
    }
    
    .section-group {
        background-color: #f9fafb;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .section-group h5 {
        color: var(--primary-blue);
        font-weight: 600;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--secondary-blue);
    }
    
    @media (max-width: 768px) {
        .wizard-container {
            padding: 20px 15px;
        }
        
        .wizard-content {
            padding: 25px 20px;
        }
        
        .wizard-steps {
            flex-direction: column;
            gap: 20px;
        }
        
        .wizard-steps::before {
            left: 25px;
            height: 100%;
            width: 2px;
            top: 0;
            right: auto;
            background-color: #e0e0e0;
        }
        
        .step-item {
            text-align: left;
            padding-left: 80px;
        }
        
        .step-number {
            position: absolute;
            left: 0;
        }
        
        .checkbox-group {
            grid-template-columns: 1fr;
        }
        
        .wizard-actions {
            flex-direction: column;
        }
        
        .btn-wizard {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="wizard-container">
        <!-- Header -->
        <div class="wizard-header">
            <h1><i class="fas fa-clipboard-list me-2"></i>Create a New Quiz</h1>
            <p>Follow the steps to set up your quiz and configure its settings</p>
        </div>
        
        <!-- Progress Steps -->
        <div class="wizard-steps">
            <div class="step-item <?php echo ($current_step == 1) ? 'active' : ($current_step > 1 ? 'completed' : ''); ?>">
                <div class="step-number">
                    <?php echo ($current_step > 1) ? '<i class="fas fa-check"></i>' : '1'; ?>
                </div>
                <div class="step-title">Quiz Information</div>
            </div>
            <div class="step-item <?php echo ($current_step == 2) ? 'active' : ($current_step > 2 ? 'completed' : ''); ?>">
                <div class="step-number">
                    <?php echo ($current_step > 2) ? '<i class="fas fa-check"></i>' : '2'; ?>
                </div>
                <div class="step-title">Quiz Settings</div>
            </div>
            <div class="step-item <?php echo ($current_step == 3) ? 'active' : ''; ?>">
                <div class="step-number">3</div>
                <div class="step-title">Assignment & Publish</div>
            </div>
        </div>
        
        <!-- Error/Success Messages -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Step 1: Quiz Information -->
        <?php if ($current_step == 1): ?>
        <form method="POST" class="wizard-content">
            <input type="hidden" name="step" value="1">
            
            <div class="form-group">
                <label class="form-label" for="subject_id">Select Subject <span class="required">*</span></label>
                <select name="subject_id" id="subject_id" class="form-select" required>
                    <option value="">-- Choose a subject --</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?php echo $subject['id']; ?>" <?php echo (isset($form_data['subject_id']) && $form_data['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($subject['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">Select the subject where this quiz will be used</small>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="title">Quiz Title <span class="required">*</span></label>
                <input type="text" name="title" id="title" class="form-control" maxlength="255" 
                       value="<?php echo htmlspecialchars($form_data['title'] ?? ''); ?>" required>
                <small class="form-text">Enter a descriptive title for your quiz (e.g., "Chapter 5 Assessment")</small>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="description">Description</label>
                <textarea name="description" id="description" class="form-control" rows="4"><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
                <small class="form-text">Add instructions or additional context for students</small>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="time_limit">Time Limit (minutes)</label>
                <input type="number" name="time_limit" id="time_limit" class="form-control" min="1" max="480" 
                       value="<?php echo htmlspecialchars($form_data['time_limit'] ?? ''); ?>">
                <small class="form-text">Leave blank for unlimited time</small>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="due_date">Due Date & Time</label>
                <input type="datetime-local" name="due_date" id="due_date" class="form-control"
                       value="<?php echo htmlspecialchars($form_data['due_date'] ?? ''); ?>">
                <small class="form-text">When the quiz should be closed for submissions</small>
            </div>
            
            <div class="wizard-actions">
                <a href="index.php" class="btn-wizard btn-secondary-wizard">
                    <i class="fas fa-times"></i>Cancel
                </a>
                <button type="submit" name="save_step_1" class="btn-wizard btn-primary-wizard">
                    Next Step<i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </form>
        <?php endif; ?>
        
        <!-- Step 2: Quiz Settings -->
        <?php if ($current_step == 2): ?>
        <form method="POST" class="wizard-content">
            <input type="hidden" name="step" value="2">
            
            <div class="section-group">
                <h5><i class="fas fa-cog me-2"></i>Attempt Settings</h5>
                
                <div class="form-group">
                    <label class="form-label" for="attempt_limit">Number of Attempts</label>
                    <?php $attempt_limit = $form_data['attempt_limit'] ?? 1; ?>
                    <select name="attempt_limit" id="attempt_limit" class="form-select">
                        <option value="1" <?php echo ($attempt_limit == 1) ? 'selected' : ''; ?>>Single Attempt (Once Only)</option>
                        <option value="2" <?php echo ($attempt_limit == 2) ? 'selected' : ''; ?>>2 Attempts</option>
                        <option value="3" <?php echo ($attempt_limit == 3) ? 'selected' : ''; ?>>3 Attempts</option>
                        <option value="5" <?php echo ($attempt_limit == 5) ? 'selected' : ''; ?>>5 Attempts</option>
                        <option value="0" <?php echo ($attempt_limit == 0) ? 'selected' : ''; ?>>Unlimited Attempts</option>
                    </select>
                </div>
            </div>
            
            <div class="section-group">
                <h5><i class="fas fa-eye me-2"></i>Display Settings</h5>
                
                <div class="form-group">
                    <div class="checkbox-item">
                        <input type="checkbox" name="show_results" id="show_results" value="1" 
                               <?php echo ($form_data['show_results'] ?? 0) ? 'checked' : ''; ?>>
                        <label for="show_results">Show Results After Submission</label>
                    </div>
                    <small class="form-text mt-2" style="display: block; margin-left: 38px;">Students will see their scores and answers after completing the quiz</small>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-item">
                        <input type="checkbox" name="shuffle_questions" id="shuffle_questions" value="1"
                               <?php echo ($form_data['shuffle_questions'] ?? 0) ? 'checked' : ''; ?>>
                        <label for="shuffle_questions">Randomize Question Order</label>
                    </div>
                    <small class="form-text mt-2" style="display: block; margin-left: 38px;">Each student will see questions in a different random order</small>
                </div>
            </div>
            
            <div class="section-group">
                <h5><i class="fas fa-chart-bar me-2"></i>Grading</h5>
                
                <div class="form-group">
                    <label class="form-label" for="passing_score">Passing Score (%)</label>
                    <input type="number" name="passing_score" id="passing_score" class="form-control" 
                           min="0" max="100" value="<?php echo htmlspecialchars($form_data['passing_score'] ?? '0'); ?>">
                    <small class="form-text">Percentage score required to pass (informational)</small>
                </div>
            </div>
            
            <div class="wizard-actions">
                <button type="submit" name="prev_step" class="btn-wizard btn-secondary-wizard">
                    <i class="fas fa-arrow-left"></i>Back
                </button>
                <button type="submit" name="save_step_2" class="btn-wizard btn-primary-wizard">
                    Next Step<i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </form>
        <?php endif; ?>
        
        <!-- Step 3: Assignment & Publish -->
        <?php if ($current_step == 3): ?>
        <form method="POST" class="wizard-content">
            <input type="hidden" name="step" value="3">
            
            <div class="section-group">
                <h5><i class="fas fa-bullseye me-2"></i>Quiz Assignment</h5>
                <p style="color: #666; margin-bottom: 20px;">Select specific students, sections, or programs, or leave all options blank to assign this quiz to all students in the subject.</p>
                
                <?php if (!empty($enrolled_students)): ?>
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-user-check me-1"></i>Select Specific Students</label>
                    <div class="checkbox-group">
                        <?php foreach ($enrolled_students as $student): ?>
                        <div class="checkbox-item">
                            <input type="checkbox" name="student_ids[]" id="student_<?php echo $student['id']; ?>" 
                                   value="<?php echo $student['id']; ?>">
                            <label for="student_<?php echo $student['id']; ?>">
                                <strong><?php echo htmlspecialchars($student['name']); ?></strong>
                                <?php if (!empty($student['student_id_number'])): ?>
                                    <br><small style="color: #6b7280;">ID: <?php echo htmlspecialchars($student['student_id_number']); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($student['year_level'])): ?>
                                    <small style="color: #9ca3af;">(<?php echo htmlspecialchars($student['year_level']); ?>)</small>
                                <?php endif; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <small class="form-text">Check individual students to assign this quiz only to them</small>
                </div>
                <?php endif; ?>

                <?php if (!empty($sections)): ?>
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-users me-1"></i>Sections to Assign</label>
                    <div class="checkbox-group">
                        <?php foreach ($sections as $section): ?>
                        <div class="checkbox-item">
                            <input type="checkbox" name="section_ids[]" id="section_<?php echo $section['id']; ?>" 
                                   value="<?php echo $section['id']; ?>">
                            <label for="section_<?php echo $section['id']; ?>">
                                <?php echo htmlspecialchars($section['name']); ?>
                                <br><small style="color: #999;"><?php echo htmlspecialchars($section['program_name']); ?></small>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <small class="form-text">Select specific sections</small>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($programs)): ?>
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-graduation-cap me-1"></i>Programs to Assign</label>
                    <div class="checkbox-group">
                        <?php foreach ($programs as $program): ?>
                        <div class="checkbox-item">
                            <input type="checkbox" name="program_ids[]" id="program_<?php echo $program['id']; ?>" 
                                   value="<?php echo $program['id']; ?>">
                            <label for="program_<?php echo $program['id']; ?>">
                                <?php echo htmlspecialchars($program['name']); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="section-group">
                <h5><i class="fas fa-info-circle me-2"></i>Summary</h5>
                <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid var(--secondary-blue);">
                    <p><strong>Title:</strong> <?php echo htmlspecialchars($form_data['title'] ?? ''); ?></p>
                    <p><strong>Subject:</strong> 
                        <?php 
                        $subject_title = '';
                        $selected_subject_id = $form_data['subject_id'] ?? null;
                        foreach ($subjects as $subject) {
                            if ($selected_subject_id && $subject['id'] == $selected_subject_id) {
                                $subject_title = $subject['title'];
                                break;
                            }
                        }
                        echo htmlspecialchars($subject_title);
                        ?>
                    </p>
                    <p><strong>Time Limit:</strong> <?php echo (!empty($form_data['time_limit'])) ? $form_data['time_limit'] . ' minutes' : 'Unlimited'; ?></p>
                    <p><strong>Attempts Allowed:</strong> <?php echo (isset($form_data['attempt_limit']) && $form_data['attempt_limit'] == 0) ? 'Unlimited' : ($form_data['attempt_limit'] ?? 1); ?></p>
                    <p><strong>Show Results:</strong> <?php echo (!empty($form_data['show_results'])) ? 'Yes' : 'No'; ?></p>
                    <p><strong>Shuffle Questions:</strong> <?php echo (!empty($form_data['shuffle_questions'])) ? 'Yes' : 'No'; ?></p>
                </div>
            </div>
            
            <div class="wizard-actions">
                <button type="submit" name="prev_step" class="btn-wizard btn-secondary-wizard">
                    <i class="fas fa-arrow-left"></i>Back
                </button>
                <button type="submit" name="create_quiz" class="btn-wizard btn-primary-wizard">
                    <i class="fas fa-check-circle"></i>Create Quiz & Add Questions
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
