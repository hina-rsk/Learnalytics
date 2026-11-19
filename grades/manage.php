<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'instructor') {
    header('Location: ../auth/login.php');
    exit;
}

$instructorId = (int)$_SESSION['user_id'];
$subjectsStmt = $pdo->prepare('SELECT id, title, class_code FROM subjects WHERE instructor_id = ? ORDER BY title');
$subjectsStmt->execute([$instructorId]);
$subjects = $subjectsStmt->fetchAll();

if (empty($_SESSION['grade_management_csrf'])) {
    $_SESSION['grade_management_csrf'] = bin2hex(random_bytes(32));
}

$selectedSubjectId = (int)($_POST['subject_id'] ?? $_GET['subject_id'] ?? 0);
$selectedSubject = null;
foreach ($subjects as $subject) {
    if ((int)$subject['id'] === $selectedSubjectId) {
        $selectedSubject = $subject;
        break;
    }
}

$successMessage = $_SESSION['grade_management_success'] ?? '';
unset($_SESSION['grade_management_success']);
$errorMessage = '';
$submittedGrades = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subject_id'], $_POST['grades'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfToken) || !hash_equals($_SESSION['grade_management_csrf'], $csrfToken)) {
        $errorMessage = 'Your session has expired. Refresh the page and try again.';
    } elseif (!$selectedSubject) {
        $errorMessage = 'Select one of your assigned classes.';
    } elseif (!is_array($_POST['grades'] ?? null)) {
        $errorMessage = 'No grade entries were submitted.';
    } else {
        $submittedGrades = $_POST['grades'];
        $gradeColumns = ['prelim' => 'prelim_grade', 'midterm' => 'midterm_grade', 'final' => 'final_grade'];
        $pdo->beginTransaction();
        try {
            $ownedSubjectStmt = $pdo->prepare('SELECT id FROM subjects WHERE id = ? AND instructor_id = ? FOR UPDATE');
            $ownedSubjectStmt->execute([$selectedSubjectId, $instructorId]);
            if (!$ownedSubjectStmt->fetch()) {
                throw new DomainException('This class is no longer assigned to your account.');
            }

            $enrolledStmt = $pdo->prepare('SELECT student_id FROM enrollments WHERE subject_id = ? FOR UPDATE');
            $enrolledStmt->execute([$selectedSubjectId]);
            $enrolledIds = array_fill_keys(array_map('intval', $enrolledStmt->fetchAll(PDO::FETCH_COLUMN)), true);

            $validatedGrades = [];
            foreach ($submittedGrades as $studentId => $periodGrades) {
                $studentId = filter_var($studentId, FILTER_VALIDATE_INT);
                if (!$studentId || !isset($enrolledIds[$studentId]) || !is_array($periodGrades)) {
                    throw new DomainException('A submitted student is not enrolled in this class. Refresh the page and try again.');
                }

                $validatedGrades[$studentId] = [];
                foreach ($gradeColumns as $period => $column) {
                    $value = trim((string)($periodGrades[$period] ?? ''));
                    if ($value === '') {
                        $validatedGrades[$studentId][$column] = null;
                    } elseif (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $value) || (float)$value > 100) {
                        throw new DomainException('Grades must be numbers from 0 to 100, with up to two decimal places.');
                    } else {
                        $validatedGrades[$studentId][$column] = number_format((float)$value, 2, '.', '');
                    }
                }
            }

            $saveStmt = $pdo->prepare(" 
                INSERT INTO student_grades
                    (student_id, instructor_id, subject_id, prelim_grade, midterm_grade, final_grade, final_average)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    instructor_id = VALUES(instructor_id),
                    prelim_grade = VALUES(prelim_grade),
                    midterm_grade = VALUES(midterm_grade),
                    final_grade = VALUES(final_grade),
                    final_average = VALUES(final_average),
                    updated_at = CURRENT_TIMESTAMP
            ");

            foreach ($validatedGrades as $studentId => $periodGrades) {
                $periodValues = array_values($periodGrades);
                $finalAverage = in_array(null, $periodValues, true)
                    ? null
                    : number_format(array_sum(array_map('floatval', $periodValues)) / 3, 2, '.', '');
                $saveStmt->execute([
                    $studentId,
                    $instructorId,
                    $selectedSubjectId,
                    $periodGrades['prelim_grade'],
                    $periodGrades['midterm_grade'],
                    $periodGrades['final_grade'],
                    $finalAverage
                ]);
            }

            $pdo->commit();
            $_SESSION['grade_management_success'] = 'Grades saved for ' . count($validatedGrades) . ' student(s).';
            header('Location: manage.php?subject_id=' . $selectedSubjectId);
            exit;
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Grade management save failed: ' . $e->getMessage());
            $errorMessage = 'Grades could not be saved. Please try again.';
        }
    }
}

$students = [];
$quizResults = [];
if ($selectedSubject) {
    $studentsStmt = $pdo->prepare(" 
        SELECT u.id AS student_id, u.name AS student_name, g.grade_id,
               COALESCE(sp.student_id_number, u.student_id_number, CONCAT('STU-', LPAD(u.id, 4, '0'))) AS student_id_number,
               g.prelim_grade, g.midterm_grade, g.final_grade, g.final_average
        FROM enrollments e
        INNER JOIN users u ON u.id = e.student_id
        LEFT JOIN student_profiles sp ON sp.student_id = u.id
        LEFT JOIN student_grades g ON g.student_id = e.student_id AND g.subject_id = e.subject_id
        WHERE e.subject_id = ?
        ORDER BY u.name ASC
    ");
    $studentsStmt->execute([$selectedSubjectId]);
    $students = $studentsStmt->fetchAll();

    $quizResultsStmt = $pdo->prepare(
        'SELECT qr.id, qr.score, qr.max_score, qr.grading_status, qr.taken_at,
                q.title AS quiz_title, u.name AS student_name
         FROM quiz_results qr
         JOIN quizzes q ON q.id = qr.quiz_id
         JOIN users u ON u.id = qr.student_id
         WHERE q.subject_id = ? AND q.instructor_id = ?
         ORDER BY qr.taken_at DESC'
    );
    $quizResultsStmt->execute([$selectedSubjectId, $instructorId]);
    $quizResults = $quizResultsStmt->fetchAll();
}

$pageTitle = 'Grade Management - LearnAlytics';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="min-height:100vh;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3" style="border-bottom:2px solid #2563eb;">
        <div>
            <h1 class="h2 mb-1" style="color:#1e3a8a;">Grade Management</h1>
            <p class="text-muted mb-0">Manage course grades for students enrolled in your classes.</p>
        </div>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="status">
            <?php echo htmlspecialchars($successMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-12 col-md-8 col-lg-6">
            <label for="subject_id" class="form-label fw-semibold">Class or subject</label>
            <select class="form-select" id="subject_id" name="subject_id" required>
                <option value="">Select a class</option>
                <?php foreach ($subjects as $subject): ?>
                    <option value="<?php echo (int)$subject['id']; ?>" <?php echo $selectedSubjectId === (int)$subject['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($subject['title'] . ($subject['class_code'] ? ' (' . $subject['class_code'] . ')' : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-primary"><i class="fas fa-users me-1"></i>View Students</button></div>
    </form>

    <?php if (empty($subjects)): ?>
        <div class="alert alert-info">No classes are currently assigned to you.</div>
    <?php elseif ($selectedSubject): ?>
        <section class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
                <h2 class="h5 mb-0" style="color:#1e3a8a;"><?php echo htmlspecialchars($selectedSubject['title']); ?> <span class="text-muted fw-normal">· <?php echo count($students); ?> students</span></h2>
                <input type="search" class="form-control" id="studentSearch" placeholder="Search name or student ID" aria-label="Search students" style="max-width:280px;">
            </div>
            <?php if (empty($students)): ?>
                <div class="card-body text-center py-5 text-muted">No students are enrolled in this class yet.</div>
            <?php else: ?>
                <form method="post" id="gradeForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['grade_management_csrf']); ?>">
                    <input type="hidden" name="subject_id" value="<?php echo $selectedSubjectId; ?>">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Student Name</th>
                                    <th>Student ID</th>
                                    <th style="min-width:120px;">Prelim</th>
                                    <th style="min-width:120px;">Midterm</th>
                                    <th style="min-width:120px;">Final</th>
                                    <th style="min-width:130px;">Final Average</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="studentGradeRows">
                                <?php foreach ($students as $student): ?>
                                    <?php
                                    $studentId = (int)$student['student_id'];
                                    $hasSavedGrade = $student['grade_id'] !== null;
                                    $isReadOnly = $hasSavedGrade && $_SERVER['REQUEST_METHOD'] !== 'POST';
                                    $gradeValues = $student;
                                    if (isset($submittedGrades[$studentId]) && is_array($submittedGrades[$studentId])) {
                                        foreach (['prelim' => 'prelim_grade', 'midterm' => 'midterm_grade', 'final' => 'final_grade'] as $period => $column) {
                                            if (array_key_exists($period, $submittedGrades[$studentId])) {
                                                $gradeValues[$column] = $submittedGrades[$studentId][$period];
                                            }
                                        }
                                    }
                                    $averageGrades = [$gradeValues['prelim_grade'], $gradeValues['midterm_grade'], $gradeValues['final_grade']];
                                    $hasCompleteAverage = count(array_filter($averageGrades, function ($grade) {
                                        return $grade !== null && $grade !== '';
                                    })) === 3;
                                    $initialAverage = $hasCompleteAverage
                                        ? number_format(array_sum(array_map('floatval', $averageGrades)) / 3, 2)
                                        : null;
                                    ?>
                                    <tr class="student-grade-row">
                                        <td class="fw-semibold"><?php echo htmlspecialchars($student['student_name']); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($student['student_id_number']); ?></td>
                                        <?php foreach (['prelim', 'midterm', 'final'] as $period): ?>
                                            <?php $column = $period . '_grade'; ?>
                                            <td>
                                                <input class="form-control form-control-sm grade-input" type="number" name="grades[<?php echo $studentId; ?>][<?php echo $period; ?>]" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((string)($gradeValues[$column] ?? '')); ?>" aria-label="<?php echo ucfirst($period); ?> grade for <?php echo htmlspecialchars($student['student_name']); ?>" <?php echo $isReadOnly ? 'readonly' : ''; ?>>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="fw-semibold text-primary grade-average" aria-live="polite"><?php echo $initialAverage !== null ? htmlspecialchars($initialAverage) : 'Not Yet Graded'; ?></td>
                                        <td>
                                            <?php if ($hasSavedGrade): ?>
                                                <button class="btn btn-sm btn-outline-primary grade-edit-button" type="button" aria-label="Edit grades for <?php echo htmlspecialchars($student['student_name']); ?>">Edit</button>
                                            <?php else: ?>
                                                <span class="text-muted small">New</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-end p-3 border-top">
                        <button class="btn btn-primary" type="submit" name="save_grades" value="1" id="saveGradesButton">
                            <i class="fas fa-save me-1"></i>Save Grades
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
        <section class="card shadow-sm border-0 mt-4">
            <div class="card-header bg-white py-3"><h2 class="h5 mb-0" style="color:#1e3a8a;">Quiz results</h2></div>
            <?php if (empty($quizResults)): ?>
                <div class="card-body text-muted">No quiz submissions have been recorded for this class.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Quiz</th><th>Student</th><th>Score</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($quizResults as $quizResult): ?>
                            <tr>
                                <td><?= htmlspecialchars($quizResult['quiz_title']) ?></td>
                                <td><?= htmlspecialchars($quizResult['student_name']) ?></td>
                                <td><?= htmlspecialchars((string)$quizResult['score']) ?> / <?= htmlspecialchars((string)$quizResult['max_score']) ?></td>
                                <td><?= ($quizResult['grading_status'] ?? 'graded') === 'pending' ? '<span class="badge text-bg-warning">Needs review</span>' : '<span class="badge text-bg-success">Graded</span>' ?></td>
                                <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($quizResult['taken_at']))) ?></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="../quizzes/result.php?id=<?= (int)$quizResult['id'] ?>">Review</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<script>
document.querySelectorAll('.student-grade-row').forEach((row) => {
    const inputs = row.querySelectorAll('.grade-input');
    const average = row.querySelector('.grade-average');
    const editButton = row.querySelector('.grade-edit-button');
    const updateAverage = () => {
        const values = Array.from(inputs).map((input) => input.value.trim());
        if (values.some((value) => value === '') || Array.from(inputs).some((input) => !input.validity.valid)) {
            average.textContent = 'Not Yet Graded';
            return;
        }
        average.textContent = (values.reduce((sum, value) => sum + Number(value), 0) / 3).toFixed(2);
    };
    inputs.forEach((input) => input.addEventListener('input', updateAverage));
    if (editButton) {
        editButton.addEventListener('click', () => {
            const isEditing = editButton.dataset.editing !== 'true';
            inputs.forEach((input) => { input.readOnly = !isEditing; });
            editButton.dataset.editing = String(isEditing);
            editButton.textContent = isEditing ? 'Done' : 'Edit';
        });
    }
    updateAverage();
});

const searchInput = document.getElementById('studentSearch');
if (searchInput) {
    searchInput.addEventListener('input', () => {
        const query = searchInput.value.trim().toLowerCase();
        document.querySelectorAll('.student-grade-row').forEach((row) => {
            row.hidden = !row.textContent.toLowerCase().includes(query);
        });
    });
}

const gradeForm = document.getElementById('gradeForm');
if (gradeForm) {
    gradeForm.addEventListener('submit', (event) => {
        if (!gradeForm.reportValidity()) {
            event.preventDefault();
            return;
        }
        const button = document.getElementById('saveGradesButton');
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving...';
    });
}
</script>

<?php include '../includes/footer.php'; ?>