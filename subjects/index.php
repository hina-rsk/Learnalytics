<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Handle Search and Filter
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$program_filter = $_GET['program_id'] ?? '';

$params = [];
if ($role === 'student') {
    $query = "SELECT c.*, u.name as instructor_name, p.name as program_name, s.name as section_name 
        FROM subjects c 
        JOIN users u ON c.instructor_id = u.id 
        LEFT JOIN programs p ON c.program_id = p.id
        LEFT JOIN sections s ON c.section_id = s.id
        JOIN enrollments e ON c.id = e.subject_id
        WHERE e.student_id = ?";
    $params[] = $user_id;

    if ($search) {
        $query .= " AND (c.title LIKE ? OR c.description LIKE ? OR u.name LIKE ? OR p.name LIKE ? OR s.name LIKE ? )";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($status) {
        $query .= " AND c.status = ?";
        $params[] = $status;
    } else {
        $query .= " AND c.status != 'archived'";
    }

    if ($program_filter) {
        $query .= " AND c.program_id = ?";
        $params[] = $program_filter;
    }
} else {
    $query = "SELECT c.*, u.name as instructor_name, p.name as program_name, s.name as section_name 
        FROM subjects c 
        JOIN users u ON c.instructor_id = u.id 
        LEFT JOIN programs p ON c.program_id = p.id
        LEFT JOIN sections s ON c.section_id = s.id
        WHERE 1=1";

    if ($search) {
        $query .= " AND (c.title LIKE ? OR c.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($status) {
        $query .= " AND c.status = ?";
        $params[] = $status;
    } else {
        $query .= " AND c.status != 'archived'";
    }

    if ($program_filter) {
        $query .= " AND c.program_id = ?";
        $params[] = $program_filter;
    }
}

// Fetch programs for filter
$programs = $pdo->query("SELECT * FROM programs ORDER BY name")->fetchAll();

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$subjects = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <div>
            <h1 class="h2" style="color: #1e3a8a;">Subjects</h1>
            <?php if ($role === 'student'): ?>
                <p class="mb-0 text-muted small">Showing only the classes you are enrolled in. Want to join another class? Use the class code.</p>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <?php if ($role === 'student'): ?>
                <a href="join.php" class="btn btn-sm" style="background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: white; border: none;"><i class="fas fa-sign-in-alt me-1"></i> Join Class</a>
            <?php endif; ?>
            <?php if ($role === 'instructor' || $role === 'admin'): ?>
                <a href="create.php" class="btn btn-sm" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none;"><i class="fas fa-plus me-1"></i> Create Subject</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Form -->
    <form class="row g-3 mb-4" method="GET">
        <div class="col-md-3">
            <input type="text" name="search" class="form-control" placeholder="Search subjects..." value="<?php echo htmlspecialchars($search); ?>" style="border-color: #2563eb;">
        </div>
        <div class="col-md-3">
            <select name="program_id" class="form-select" style="border-color: #2563eb;">
                <option value="">All Programs</option>
                <?php foreach ($programs as $prog): ?>
                    <option value="<?php echo $prog['id']; ?>" <?php echo $program_filter == $prog['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prog['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select" style="border-color: #2563eb;">
                <option value="">All Statuses</option>
                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="archived" <?php echo $status === 'archived' ? 'selected' : ''; ?>>Archived</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn w-100" style="background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: white; border: none;">Filter</button>
        </div>
    </form>

    <div class="row row-cols-1 row-cols-md-3 g-4">
        <?php foreach ($subjects as $subject): ?>
            <div class="col">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge" style="background: <?php echo $subject['status'] === 'active' ? 'linear-gradient(135deg, #10b981 0%, #059669 100%)' : ($subject['status'] === 'draft' ? 'linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%)' : ($subject['status'] === 'archived' ? 'linear-gradient(135deg, #6b7280 0%, #4b5563 100%)' : 'linear-gradient(135deg, #6b7280 0%, #4b5563 100%)')); ?>; color: white;">
                                <?php echo ucfirst($subject['status']); ?>
                            </span>
                        </div>
                        <h5 class="card-title" style="color: #1e3a8a;"><?php echo htmlspecialchars($subject['title']); ?></h5>
                        <p class="card-text text-muted small"><?php echo htmlspecialchars(substr($subject['description'], 0, 100)) . '...'; ?></p>
                        <?php if (!empty($subject['program_name'])): ?>
                            <p class="card-text mb-1"><small class="text-muted"><i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($subject['program_name']); ?>
                                <?php if (!empty($subject['section_name'])): ?> - <?php echo htmlspecialchars($subject['section_name']); ?><?php endif; ?>
                            </small></p>
                        <?php endif; ?>
                        <p class="card-text mb-1"><small class="text-muted"><i class="fas fa-user-tie me-1"></i> <?php echo htmlspecialchars($subject['instructor_name']); ?></small></p>
                    </div>
                    <div class="card-footer bg-white border-0 pb-3">
                        <a href="view.php?id=<?php echo $subject['id']; ?>" class="btn btn-sm w-100" style="background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: white; border: none;">View Details</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($subjects)): ?>
            <div class="col-12 text-center py-5">
                <?php if ($role === 'student'): ?>
                    <p class="text-muted mb-3">You are not enrolled in any subjects yet.</p>
                    <a href="join.php" class="btn btn-sm" style="background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: white; border: none;"><i class="fas fa-sign-in-alt me-1"></i> Join a Class with Code</a>
                <?php else: ?>
                    <p class="text-muted">No subjects found.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
