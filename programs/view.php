<?php
session_start();
require_once '../config/database.php';
require_once '../includes/enrollment_requests.php';

$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['user_role'] ?? 'guest';
if ($user_id && $role === 'student') {
    ensureEnrollmentRequestsTable($pdo);
    if (empty($_SESSION['program_enrollment_csrf'])) {
        $_SESSION['program_enrollment_csrf'] = bin2hex(random_bytes(32));
    }
}

// Handle enrollment - enroll in all subjects of the section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll']) && $user_id && $role === 'student') {
    $section_id = $_POST['section_id'] ?? '';
    
    if (!empty($section_id)) {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['program_enrollment_csrf'], $_POST['csrf_token'])) {
            $error = 'Your session has expired. Refresh the page and try again.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM subjects WHERE section_id = ? AND status = 'active'");
                $stmt->execute([$section_id]);
                $sectionSubjects = $stmt->fetchAll();

                if (empty($sectionSubjects)) {
                    $error = 'No subjects available in this section.';
                } else {
                    $requestedCount = 0;
                    $pendingCount = 0;
                    $enrolledCount = 0;
                    foreach ($sectionSubjects as $sub) {
                        $result = requestEnrollment($pdo, (int)$user_id, (int)$sub['id']);
                        if ($result === 'requested') {
                            $requestedCount++;
                        } elseif ($result === 'pending') {
                            $pendingCount++;
                        } elseif ($result === 'already_enrolled') {
                            $enrolledCount++;
                        }
                    }

                    if ($requestedCount > 0) {
                        $message = "Join requests sent to instructors for $requestedCount subject(s). Approval is required before you can access them.";
                    } elseif ($pendingCount > 0) {
                        $message = "Your requests for $pendingCount subject(s) are already awaiting instructor approval.";
                    } else {
                        $message = 'You are already enrolled in all subjects in this section.';
                    }
                }
            } catch (PDOException $e) {
                error_log('Section enrollment requests failed: ' . $e->getMessage());
                $error = 'Could not send enrollment requests. Please try again.';
            }
        }
    }
}

// Fetch all active programs with their sections and academic years
$programs = $pdo->query("
    SELECT p.id, p.name, p.description, p.status,
           ay.name as academic_year_name,
           s.id as section_id, s.name as section_name,
           (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e JOIN subjects sub ON e.subject_id = sub.id WHERE sub.section_id = s.id) as enrolled_count,
           (SELECT COUNT(*) FROM subjects sub WHERE sub.section_id = s.id) as subject_count
    FROM programs p
    LEFT JOIN sections s ON p.id = s.program_id
    LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
    WHERE p.status = 'active'
    ORDER BY p.name, s.name
")->fetchAll();

// Group by program
$groupedPrograms = [];
foreach ($programs as $prog) {
    $progId = $prog['id'];
    if (!isset($groupedPrograms[$progId])) {
        $groupedPrograms[$progId] = [
            'id' => $prog['id'],
            'name' => $prog['name'],
            'description' => $prog['description'],
            'status' => $prog['status'],
            'sections' => []
        ];
    }
    if ($prog['section_id']) {
        $groupedPrograms[$progId]['sections'][] = [
            'id' => $prog['section_id'],
            'name' => $prog['section_name'],
            'academic_year' => $prog['academic_year_name'],
            'enrolled_count' => $prog['enrolled_count'],
            'subject_count' => $prog['subject_count']
        ];
    }
}

// Check if user is enrolled in any subjects of these sections
$enrolledSections = [];
$pendingSections = [];
if ($user_id) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.id 
        FROM sections s
        JOIN subjects sub ON sub.section_id = s.id
        JOIN enrollments e ON e.subject_id = sub.id
        WHERE e.student_id = ?
    ");
    $stmt->execute([$user_id]);
    while ($row = $stmt->fetch()) {
        $enrolledSections[] = $row['id'];
    }
    if ($role === 'student') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT s.id
            FROM sections s
            JOIN subjects sub ON sub.section_id = s.id
            JOIN enrollment_requests er ON er.subject_id = sub.id
            WHERE er.student_id = ? AND er.status = 'pending'
        ");
        $stmt->execute([$user_id]);
        while ($row = $stmt->fetch()) {
            $pendingSections[] = $row['id'];
        }
    }
}

include '../includes/header.php';
?>

<main class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
                <h1 class="h2" style="color: #1e3a8a;">Programs & Sections</h1>
            </div>

            <?php if (isset($message)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (empty($groupedPrograms)): ?>
                <div class="alert alert-info">No programs available at the moment.</div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($groupedPrograms as $program): ?>
                        <div class="col-12">
                            <div class="card shadow-sm border-0">
                                <div class="card-header bg-white border-0" style="border-left: 4px solid #2563eb;">
                                    <h3 class="mb-0" style="color: #1e3a8a;"><?= htmlspecialchars($program['name']) ?></h3>
                                </div>
                                <div class="card-body">
                                    <?php if ($program['description']): ?>
                                        <p class="text-muted"><?= htmlspecialchars($program['description']) ?></p>
                                    <?php endif; ?>
                                    
                                    <?php if (empty($program['sections'])): ?>
                                        <p class="text-muted">No sections available for this program.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr style="background: #f8f9fa;">
                                                        <th>Section</th>
                                                        <th>Academic Year</th>
                                                        <th>Enrolled Students</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($program['sections'] as $section): ?>
                                                        <tr>
                                                            <td><strong><?= htmlspecialchars($section['name']) ?></strong></td>
                                                            <td><?= htmlspecialchars($section['academic_year'] ?? 'N/A') ?></td>
                                                            <td>
                                                                <span class="badge" style="background: #2563eb; color: white;">
                                                                    <?= intval($section['enrolled_count']) ?> students
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php if (in_array($section['id'], $enrolledSections)): ?>
                                                                    <span class="badge" style="background: #10b981; color: white;">
                                                                        <i class="fas fa-check-circle me-1"></i> Enrolled
                                                                    </span>
                                                                <?php elseif (in_array($section['id'], $pendingSections)): ?>
                                                                    <span class="badge bg-warning text-dark">
                                                                        <i class="fas fa-clock me-1"></i> Awaiting Approval
                                                                    </span>
                                                                <?php elseif ($user_id && $role === 'student'): ?>
                                                                    <form method="POST" style="display: inline;">
                                                                        <input type="hidden" name="section_id" value="<?= $section['id'] ?>">
                                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['program_enrollment_csrf']) ?>">
                                                                        <button type="submit" name="enroll" class="btn btn-sm" style="background: #2563eb; color: white; border: none;">
                                                                            <i class="fas fa-user-plus me-1"></i> Enroll
                                                                        </button>
                                                                    </form>
                                                                <?php elseif (!$user_id): ?>
                                                                    <a href="../auth/login.php" class="btn btn-sm" style="background: #6c757d; color: white; border: none;">
                                                                        <i class="fas fa-sign-in-alt me-1"></i> Login to Enroll
                                                                    </a>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                                <a href="section.php?id=<?= $section['id'] ?>" class="btn btn-sm btn-outline-primary ms-1">
                                                                    <i class="fas fa-eye me-1"></i> View
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<style>
.badge {
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 13px;
}
</style>

<?php include '../includes/footer.php'; ?>