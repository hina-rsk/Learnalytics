<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Check if enhanced quiz columns exist (migration applied)
$columnsQuery = $pdo->query("SHOW COLUMNS FROM quizzes");
$columns = array_column($columnsQuery->fetchAll(), 'Field');
$hasEnhancedColumns = in_array('status', $columns);

// Role-based quiz filtering
switch ($role) {
    case 'instructor':
        // Instructors see only their own quizzes
        if ($hasEnhancedColumns) {
            $stmt = $pdo->prepare("
                SELECT q.*, s.title as subject_title,
                       COUNT(DISTINCT qr.id) as total_submissions,
                       COUNT(DISTINCT qr.student_id) as unique_students
                FROM quizzes q
                JOIN subjects s ON q.subject_id = s.id
                LEFT JOIN quiz_results qr ON q.id = qr.quiz_id
                WHERE q.instructor_id = ?
                GROUP BY q.id, q.title, q.description, q.subject_id, q.status, q.due_date, q.time_limit, q.created_at, s.title
                ORDER BY q.created_at DESC
            ");
            $stmt->execute([$user_id]);
            $quizzes = $stmt->fetchAll();
        } else {
            // Fallback for original schema
            $stmt = $pdo->prepare("
                SELECT q.*, s.title as subject_title,
                       COUNT(DISTINCT qr.id) as total_submissions,
                       COUNT(DISTINCT qr.student_id) as unique_students,
                       'published' as status, NULL as due_date, NULL as time_limit
                FROM quizzes q
                JOIN subjects s ON q.subject_id = s.id
                LEFT JOIN quiz_results qr ON q.id = qr.quiz_id
                WHERE q.instructor_id = ?
                GROUP BY q.id, q.title, q.description, q.subject_id, q.created_at, s.title
                ORDER BY q.created_at DESC
            ");
            $stmt->execute([$user_id]);
            $quizzes = $stmt->fetchAll();
        }
        break;

    case 'admin':
        // Admins see all quizzes with analytics
        if ($hasEnhancedColumns) {
            $stmt = $pdo->query("
                SELECT q.*, s.title as subject_title, u.name as instructor_name,
                       COUNT(DISTINCT qr.id) as total_submissions,
                       COUNT(DISTINCT qr.student_id) as unique_students,
                       AVG(qr.score) as avg_score
                FROM quizzes q
                JOIN subjects s ON q.subject_id = s.id
                LEFT JOIN users u ON q.instructor_id = u.id
                LEFT JOIN quiz_results qr ON q.id = qr.quiz_id
                GROUP BY q.id, s.title, u.name, q.title, q.description, q.subject_id, q.status, q.due_date, q.time_limit, q.created_at
                ORDER BY q.created_at DESC
            ");
            $quizzes = $stmt->fetchAll();
        } else {
            // Fallback for original schema
            $stmt = $pdo->query("
                SELECT q.*, s.title as subject_title, u.name as instructor_name,
                       COUNT(DISTINCT qr.id) as total_submissions,
                       COUNT(DISTINCT qr.student_id) as unique_students,
                       AVG(qr.score) as avg_score,
                       'published' as status, NULL as due_date, NULL as time_limit
                FROM quizzes q
                JOIN subjects s ON q.subject_id = s.id
                LEFT JOIN users u ON q.instructor_id = u.id
                LEFT JOIN quiz_results qr ON q.id = qr.quiz_id
                GROUP BY q.id, s.title, u.name, q.title, q.description, q.subject_id, q.created_at
                ORDER BY q.created_at DESC
            ");
            $quizzes = $stmt->fetchAll();
        }
        break;

    case 'student':
        // Students see only published quizzes assigned to them
        if ($hasEnhancedColumns && in_array('quiz_assignments', $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN))) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT q.*, s.title as subject_title, u.name as instructor_name,
                       (SELECT COUNT(*) FROM quiz_results WHERE quiz_id = q.id AND student_id = ?) as attempts,
                       (SELECT id FROM quiz_results WHERE quiz_id = q.id AND student_id = ? ORDER BY attempt_number DESC LIMIT 1) as last_result_id
                FROM quizzes q
                JOIN subjects s ON q.subject_id = s.id
                JOIN users u ON s.instructor_id = u.id
                JOIN enrollments e ON s.id = e.subject_id
                LEFT JOIN quiz_assignments qa ON q.id = qa.quiz_id
                LEFT JOIN student_profiles sp ON e.student_id = sp.student_id
                WHERE e.student_id = ?
                  AND q.status = 'published'
                  AND q.is_available = 1
                  AND (q.section_id IS NULL OR q.section_id = sp.section_id)
                  AND (
                      qa.id IS NULL
                      OR qa.assignment_type = 'subject'
                      OR (qa.assignment_type = 'section' AND qa.target_id = sp.section_id)
                      OR (qa.assignment_type = 'program' AND qa.target_id = sp.program_id)
                      OR (qa.assignment_type = 'year_level' AND qa.year_level = sp.year_level)
                      OR (qa.assignment_type = 'student' AND qa.target_id = ?)
                  )
                GROUP BY q.id, s.title, u.name, q.title, q.description, q.subject_id, q.status, q.due_date, q.time_limit, q.attempt_limit, q.show_results, q.created_at
                ORDER BY q.created_at DESC
            ");
            $stmt->execute([$user_id, $user_id, $user_id, $user_id]);
            $quizzes = $stmt->fetchAll();
        } else {
            // Fallback for original schema - show all quizzes
            $stmt = $pdo->prepare("
                SELECT DISTINCT q.*, s.title as subject_title, u.name as instructor_name,
                       (SELECT COUNT(*) FROM quiz_results WHERE quiz_id = q.id AND student_id = ?) as attempts,
                       'published' as status, NULL as due_date, NULL as time_limit
                FROM quizzes q
                JOIN subjects s ON q.subject_id = s.id
                JOIN users u ON s.instructor_id = u.id
                JOIN enrollments e ON s.id = e.subject_id
                WHERE e.student_id = ?
                GROUP BY q.id, s.title, u.name, q.title, q.description, q.subject_id, q.created_at
                ORDER BY q.created_at DESC
            ");
            $stmt->execute([$user_id, $user_id]);
            $quizzes = $stmt->fetchAll();
        }
        break;

    default:
        $quizzes = [];
        break;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">
            <?php
            switch ($role) {
                case 'instructor':
                    echo '<i class="fas fa-chalkboard-teacher me-2"></i>My Quizzes';
                    break;
                case 'admin':
                    echo '<i class="fas fa-chart-line me-2"></i>Quiz Analytics & Management';
                    break;
                case 'student':
                    echo '<i class="fas fa-graduation-cap me-2"></i>My Assigned Quizzes';
                    break;
            }
            ?>
        </h1>
        <?php if ($role === 'instructor'): ?>
            <a href="create.php" class="btn btn-sm" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none;">
                <i class="fas fa-plus me-1"></i> Create Quiz
            </a>
        <?php elseif ($role === 'admin'): ?>
            <a href="analytics.php" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-chart-bar me-1"></i> View Analytics
            </a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" style="background-color: #d1fae5; color: #065f46; border-color: #10b981;">
            <i class="fas fa-check-circle me-2"></i> Quiz deleted successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert" style="background-color: #fee2e2; color: #991b1b; border-color: #f87171;">
            <i class="fas fa-exclamation-triangle me-2"></i> Error deleting quiz. Please try again.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif (isset($_GET['published'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" style="background-color: #d1fae5; color: #065f46; border-color: #10b981;">
            <i class="fas fa-check-circle me-2"></i> Quiz published successfully! Students can now access it.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif (isset($_GET['submitted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i> Quiz submitted. Your results will be shown when they are released by your instructor.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif (isset($_GET['results_pending'])): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-hourglass-half me-2"></i> Your quiz results are not available yet.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif (isset($_GET['assigned'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" style="background-color: #d1fae5; color: #065f46; border-color: #10b981;">
            <i class="fas fa-check-circle me-2"></i> Quiz assignments updated successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-12">
            <?php if (empty($quizzes)): ?>
                <div class="alert alert-info" style="background-color: #dbeafe; color: #1e3a8a; border-color: #2563eb;">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php
                    switch ($role) {
                        case 'instructor':
                            echo 'You have not created any quizzes yet. Click "Create Quiz" to get started.';
                            break;
                        case 'admin':
                            echo 'No quizzes exist in the system yet.';
                            break;
                        case 'student':
                            echo 'No quizzes have been assigned to you yet. Check back later!';
                            break;
                    }
                    ?>
                </div>
            <?php else: ?>
                <div class="card shadow border-0">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0">
                            <?php
                            switch ($role) {
                                case 'instructor':
                                    echo 'Your Quizzes';
                                    break;
                                case 'admin':
                                    echo 'All System Quizzes';
                                    break;
                                case 'student':
                                    echo 'Assigned Quizzes';
                                    break;
                            }
                            ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead style="background-color: #eff6ff; border-bottom: 2px solid #2563eb;">
                                    <tr>
                                        <th style="color: #1e3a8a;">Quiz Title</th>
                                        <th style="color: #1e3a8a;">Subject</th>
                                        <?php if ($role === 'admin'): ?>
                                            <th style="color: #1e3a8a;">Instructor</th>
                                        <?php endif; ?>
                                        <th style="color: #1e3a8a;">Status</th>
                                        <?php if ($role !== 'student'): ?>
                                            <th style="color: #1e3a8a;">Questions</th>
                                            <th style="color: #1e3a8a;">Attempts</th>
                                        <?php else: ?>
                                            <th style="color: #1e3a8a;">Questions</th>
                                            <th style="color: #1e3a8a;">Your Attempts</th>
                                        <?php endif; ?>
                                        <?php if ($role === 'student'): ?>
                                            <th style="color: #1e3a8a;">Due Date</th>
                                        <?php endif; ?>
                                        <th style="color: #1e3a8a;">Created</th>
                                        <th style="color: #1e3a8a;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quizzes as $quiz): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($quiz['title']); ?></strong>
                                                <?php if (!empty($quiz['description']) && $role !== 'student'): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars(substr($quiz['description'], 0, 60)); ?>...</small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($quiz['subject_title']); ?></td>
                                            <?php if ($role === 'admin'): ?>
                                                <td><?php echo htmlspecialchars($quiz['instructor_name'] ?? 'N/A'); ?></td>
                                            <?php endif; ?>
                                            <td>
                                                <?php
                                                $status_badges = [
                                                    'draft' => 'secondary',
                                                    'published' => 'success',
                                                    'closed' => 'danger',
                                                    'archived' => 'info'
                                                ];
                                                $badge = $status_badges[$quiz['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $badge; ?>"><?php echo ucfirst($quiz['status']); ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE quiz_id = ?");
                                                $count_stmt->execute([$quiz['id']]);
                                                echo $count_stmt->fetchColumn();
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                if ($role === 'student') {
                                                    echo $quiz['attempts'] ?? 0;
                                                } else {
                                                    echo $quiz['total_submissions'] ?? 0;
                                                }
                                                ?>
                                            </td>
                                            <?php if ($role === 'student'): ?>
                                                <td>
                                                    <?php
                                                    if ($quiz['due_date']) {
                                                        echo date('M d, Y', strtotime($quiz['due_date']));
                                                        if (strtotime($quiz['due_date']) < time() && $quiz['status'] === 'published') {
                                                            echo '<br><small class="text-danger">Expired</small>';
                                                        }
                                                    } else {
                                                        echo 'No deadline';
                                                    }
                                                    ?>
                                                </td>
                                            <?php endif; ?>
                                            <td><?php echo date('M d, Y', strtotime($quiz['created_at'])); ?></td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <?php if ($role === 'instructor'): ?>
                                                        <a href="add_questions.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-primary" title="Add/Edit Questions">
                                                            <i class="fas fa-plus"></i>
                                                        </a>
                                                        <?php if ($quiz['status'] === 'draft'): ?>
                                                            <a href="publish.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-success" title="Publish Quiz" onclick="return confirm('Publish this quiz? Students will be able to access it.')">
                                                                <i class="fas fa-upload"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <a href="assignments.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-info" title="Manage Assignments">
                                                            <i class="fas fa-users"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($role === 'student' && !empty($quiz['last_result_id']) && !empty($quiz['show_results'])): ?>
                                                        <a href="result.php?id=<?php echo (int)$quiz['last_result_id']; ?>" class="btn btn-sm btn-outline-primary" title="View quiz result">
                                                            <i class="fas fa-square-poll-vertical"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="view.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm" style="background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: white; border: none;" title="View Quiz">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($role === 'instructor' || $role === 'admin'): ?>
                                                        <a href="results.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-warning" title="View Results">
                                                            <i class="fas fa-chart-bar"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($role === 'instructor' && $quiz['status'] === 'draft'): ?>
                                                        <a href="delete.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete Quiz" onclick="return confirm('Are you sure you want to delete this quiz? This action cannot be undone.')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($role === 'admin'): ?>
                                                        <a href="delete.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete Quiz" onclick="return confirm('Are you sure you want to delete this quiz? This action cannot be undone.')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>