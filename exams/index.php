<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header("Location: ../auth/login.php");
    exit;
}

$instructor_id = (int)$_SESSION['user_id'];

// Handle delete action
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    // Verify ownership
    $chk = $pdo->prepare("SELECT id FROM exam_records WHERE id = ? AND instructor_id = ?");
    $chk->execute([$del_id, $instructor_id]);
    if ($chk->fetch()) {
        $pdo->prepare("DELETE FROM exam_records WHERE id = ?")->execute([$del_id]);
    }
    header("Location: index.php?deleted=1");
    exit;
}

// Fetch all exams belonging to this instructor with aggregates
$stmt = $pdo->prepare("
    SELECT er.id, er.title, er.exam_date, er.total_items,
           s.title AS subject_title,
           COALESCE(sec.name, 'N/A') AS section_name,
           COUNT(es.id) AS students_recorded,
           ROUND(AVG(es.score / er.total_items * 100), 1) AS avg_mps
    FROM exam_records er
    INNER JOIN subjects s ON er.subject_id = s.id
    LEFT JOIN sections sec ON er.section_id = sec.id
    LEFT JOIN exam_scores es ON es.exam_id = er.id
    WHERE er.instructor_id = ?
    GROUP BY er.id, er.title, er.exam_date, er.total_items, s.title, sec.name
    ORDER BY er.exam_date DESC, er.created_at DESC
");
$stmt->execute([$instructor_id]);
$exams = $stmt->fetchAll();

$baseUrl = '..';
$pageTitle = 'Exam Records — LearnAlytics';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    :root { --pri: #1e3a8a; --sec: #2563eb; }
    body { background: #f1f5f9; }

    .page-banner {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        border-radius: 16px;
        padding: 1.6rem 2rem;
        color: white;
        box-shadow: 0 10px 25px rgba(37,99,235,.18);
        margin-bottom: 2rem;
    }

    .content-card {
        background: white;
        border-radius: 16px;
        border: none;
        box-shadow: 0 4px 15px rgba(0,0,0,.06);
        overflow: hidden;
    }
    .card-hdr {
        padding: 1.1rem 1.5rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex; align-items: center; justify-content: space-between;
    }
    .card-hdr h5 { font-weight: 700; color: #1e3a8a; margin: 0; }

    .table-exams { font-size: .875rem; margin: 0; width: 100%; }
    .table-exams thead { background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
    .table-exams th { padding: .8rem 1rem; font-weight: 700; color: #475569; text-transform: uppercase; font-size: .72rem; letter-spacing: .5px; white-space: nowrap; }
    .table-exams td { padding: .8rem 1rem; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
    .table-exams tbody tr:hover { background: #f8fafc; }

    .mps-pill {
        display: inline-block;
        font-weight: 700;
        font-size: .82rem;
        padding: .25rem .75rem;
        border-radius: 999px;
    }
    .mps-excellent { background: #dcfce7; color: #166534; }
    .mps-good      { background: #dbeafe; color: #1e40af; }
    .mps-average   { background: #fef3c7; color: #92400e; }
    .mps-critical  { background: #fee2e2; color: #991b1b; }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="min-height:100vh;">

    <!-- Banner -->
    <div class="page-banner">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <span class="badge bg-white text-primary fw-bold px-3 py-1 mb-2 rounded-pill" style="font-size:.75rem;">Assessment Management</span>
                <h2 class="fw-bold mb-1"><i class="fas fa-clipboard-list me-2"></i>Exam Records</h2>
                <p class="mb-0 text-white-50 small">Create and manage manually recorded exam scores for your classes.</p>
            </div>
            <a href="create.php" class="btn btn-light fw-bold px-4 py-2 rounded-pill text-primary shadow-sm">
                <i class="fas fa-plus-circle me-2"></i>New Exam Record
            </a>
        </div>
    </div>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>Exam record deleted successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i>Exam scores saved successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Exam Table -->
    <div class="content-card">
        <div class="card-hdr">
            <h5><i class="fas fa-list-alt me-2 text-primary"></i>All Recorded Exams</h5>
            <span class="badge bg-primary rounded-pill"><?php echo count($exams); ?> Exam<?php echo count($exams) !== 1 ? 's' : ''; ?></span>
        </div>
        <div style="overflow-x:auto;">
            <?php if (empty($exams)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-clipboard fa-3x text-muted mb-3 d-block"></i>
                    <p class="text-muted fw-semibold mb-2">No exam records yet.</p>
                    <p class="text-muted small mb-4">Create your first exam record to start tracking student scores.</p>
                    <a href="create.php" class="btn btn-primary rounded-pill px-4">
                        <i class="fas fa-plus me-2"></i>Create Exam Record
                    </a>
                </div>
            <?php else: ?>
                <table class="table-exams">
                    <thead>
                        <tr>
                            <th>Exam Title</th>
                            <th>Subject</th>
                           
                            <th>Date</th>
                            <th>Total Items</th>
                            <th>Students Recorded</th>
                            <th>Average MPS</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exams as $exam): ?>
                            <?php
                                $mps = (float)($exam['avg_mps'] ?? 0);
                                if ($mps >= 85) $cls = 'mps-excellent';
                                elseif ($mps >= 75) $cls = 'mps-good';
                                elseif ($mps >= 60) $cls = 'mps-average';
                                else $cls = 'mps-critical';
                            ?>
                            <tr>
                                <td class="fw-bold" style="color:#1e3a8a;"><?php echo htmlspecialchars($exam['title']); ?></td>
                                <td><?php echo htmlspecialchars($exam['subject_title']); ?></td>
                                <td class="text-muted small"><?php echo date('M d, Y', strtotime($exam['exam_date'])); ?></td>
                                <td><?php echo number_format($exam['total_items']); ?></td>
                                <td><span class="badge bg-primary rounded-pill"><?php echo $exam['students_recorded']; ?></span></td>
                                <td>
                                    <?php if ($exam['students_recorded'] > 0): ?>
                                        <span class="mps-pill <?php echo $cls; ?>"><?php echo $mps; ?>%</span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="create.php?edit=<?php echo $exam['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3" title="Edit / View Scores">
                                            <i class="fas fa-edit me-1"></i>Edit
                                        </a>
                                        <a href="scores.php?exam_id=<?php echo $exam['id']; ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3" title="View Score Sheet">
                                            <i class="fas fa-table me-1"></i>Scores
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2"
                                            onclick="confirmDelete(<?php echo $exam['id']; ?>, '<?php echo addslashes($exam['title']); ?>')" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</main>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-danger text-white rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-0">Are you sure you want to delete the exam <strong id="deleteExamName"></strong>? This will permanently remove all recorded scores.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                <a id="deleteConfirmBtn" href="#" class="btn btn-danger rounded-pill px-4">Delete</a>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, name) {
    document.getElementById('deleteExamName').textContent = '"' + name + '"';
    document.getElementById('deleteConfirmBtn').href = 'index.php?delete=' + id;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>
