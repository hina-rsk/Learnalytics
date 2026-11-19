<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header("Location: ../auth/login.php");
    exit;
}

$instructor_id = (int)$_SESSION['user_id'];
$exam_id       = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;

if ($exam_id <= 0) {
    header("Location: index.php");
    exit;
}

// Verify ownership & load exam
$stmt = $pdo->prepare("
    SELECT er.*, s.title AS subject_title, COALESCE(sec.name,'N/A') AS section_name
    FROM exam_records er
    INNER JOIN subjects s ON er.subject_id = s.id
    LEFT JOIN sections sec ON er.section_id = sec.id
    WHERE er.id = ? AND er.instructor_id = ?
");
$stmt->execute([$exam_id, $instructor_id]);
$exam = $stmt->fetch();

if (!$exam) {
    header("Location: index.php");
    exit;
}

// Load all scores for this exam
$stmt = $pdo->prepare("
    SELECT es.student_id, es.score, es.recorded_at, es.updated_at,
           u.name AS student_name,
           COALESCE(sp.student_id_number, CONCAT('STU-', LPAD(u.id,4,'0'))) AS student_id_number
    FROM exam_scores es
    INNER JOIN users u ON u.id = es.student_id
    LEFT JOIN student_profiles sp ON sp.student_id = u.id
    WHERE es.exam_id = ?
    ORDER BY u.name ASC
");
$stmt->execute([$exam_id]);
$scores = $stmt->fetchAll();

$baseUrl  = '..';
$pageTitle = 'Score Sheet — ' . $exam['title'] . ' — LearnAlytics';
include '../includes/header.php';
include '../includes/sidebar.php';

function getStatusBadge($pct) {
    if ($pct >= 85) return '<span class="status-badge status-excellent"><i class="fas fa-check-circle me-1"></i>Excellent</span>';
    if ($pct >= 75) return '<span class="status-badge status-good"><i class="fas fa-thumbs-up me-1"></i>Good</span>';
    if ($pct >= 60) return '<span class="status-badge status-average"><i class="fas fa-exclamation-triangle me-1"></i>Needs Improvement</span>';
    return '<span class="status-badge status-critical"><i class="fas fa-exclamation-circle me-1"></i>Critical</span>';
}
?>

<style>
    body { background: #f1f5f9; }
    .page-banner {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        border-radius: 16px; padding: 1.6rem 2rem; color: white;
        box-shadow: 0 10px 25px rgba(37,99,235,.18); margin-bottom: 2rem;
    }
    .info-badge {
        background: rgba(255,255,255,.15);
        border-radius: 8px; padding: .4rem .8rem; font-size: .82rem;
        display: inline-flex; align-items: center; gap: .4rem; color: rgba(255,255,255,.9);
    }
    .content-card {
        background: white; border-radius: 16px; border: none;
        box-shadow: 0 4px 15px rgba(0,0,0,.06); overflow: hidden;
    }
    .card-hdr {
        padding: 1rem 1.5rem; border-bottom: 1px solid #f1f5f9;
        display: flex; align-items: center; justify-content: space-between;
    }
    .card-hdr h5 { font-weight: 700; color: #1e3a8a; margin: 0; }
    .score-table { font-size: .875rem; width: 100%; border-collapse: collapse; }
    .score-table thead { background: #f8fafc; }
    .score-table th {
        padding: .8rem 1rem; font-weight: 700; color: #475569;
        text-transform: uppercase; font-size: .72rem; letter-spacing: .5px;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    .score-table td { padding: .75rem 1rem; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
    .score-table tbody tr:hover { background: #f8fafc; }
    .status-badge { display: inline-block; padding: .22rem .65rem; border-radius: 999px; font-size: .75rem; font-weight: 700; }
    .status-excellent { background:#dcfce7; color:#166534; }
    .status-good      { background:#dbeafe; color:#1e40af; }
    .status-average   { background:#fef3c7; color:#92400e; }
    .status-critical  { background:#fee2e2; color:#991b1b; }
    .stat-mini { border-radius:12px; padding:.9rem 1.2rem; text-align:center; }
    .stat-mini .val { font-size:1.6rem; font-weight:800; color:#1e3a8a; line-height:1.2; }
    .stat-mini .lbl { font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#64748b; }

    @media print {
        .page-banner .btn, .no-print, nav.navbar, #sidebar { display: none !important; }
        body { background: white !important; }
        .content-card { box-shadow: none !important; }
    }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="min-height:100vh;">

    <!-- Banner -->
    <div class="page-banner">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <span class="badge bg-white text-primary fw-bold px-3 py-1 mb-2 rounded-pill" style="font-size:.75rem;">Score Sheet</span>
                <h2 class="fw-bold mb-2"><?php echo htmlspecialchars($exam['title']); ?></h2>
                <div class="d-flex flex-wrap gap-2">
                    <span class="info-badge"><i class="fas fa-book"></i><?php echo htmlspecialchars($exam['subject_title']); ?></span>
                    <span class="info-badge"><i class="fas fa-users"></i><?php echo htmlspecialchars($exam['section_name']); ?></span>
                    <span class="info-badge"><i class="fas fa-calendar"></i><?php echo date('F d, Y', strtotime($exam['exam_date'])); ?></span>
                    <span class="info-badge"><i class="fas fa-list-ol"></i><?php echo $exam['total_items']; ?> items</span>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap no-print">
                <a href="create.php?edit=<?php echo $exam_id; ?>" class="btn btn-light fw-bold rounded-pill px-3 py-2 text-primary">
                    <i class="fas fa-edit me-1"></i>Edit Scores
                </a>
                <button onclick="window.print()" class="btn btn-outline-light fw-bold rounded-pill px-3 py-2">
                    <i class="fas fa-print me-1"></i>Print
                </button>
                <a href="index.php" class="btn btn-outline-light fw-bold rounded-pill px-3 py-2">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <?php
        $total = $exam['total_items'];
        $count = count($scores);
        $avg_mps = 0;
        $highest = 0;
        $lowest  = PHP_INT_MAX;
        foreach ($scores as $s) {
            $pct = $total > 0 ? round(($s['score'] / $total) * 100, 1) : 0;
            $avg_mps += $pct;
            if ($s['score'] > $highest) $highest = (float)$s['score'];
            if ($s['score'] < $lowest)  $lowest  = (float)$s['score'];
        }
        $avg_mps = $count > 0 ? round($avg_mps / $count, 1) : 0;
        if ($count === 0) { $highest = 0; $lowest = 0; }
    ?>

    <!-- Summary Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-mini content-card">
                <div class="val"><?php echo $count; ?></div>
                <div class="lbl">Students Recorded</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-mini content-card">
                <div class="val" style="color:<?php echo $avg_mps >= 75 ? '#1e40af' : '#991b1b'; ?>;"><?php echo $avg_mps; ?>%</div>
                <div class="lbl">Average MPS</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-mini content-card">
                <div class="val" style="color:#166534;"><?php echo $count > 0 ? $highest . '/' . $total : '—'; ?></div>
                <div class="lbl">Highest Score</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-mini content-card">
                <div class="val" style="color:#991b1b;"><?php echo $count > 0 ? $lowest . '/' . $total : '—'; ?></div>
                <div class="lbl">Lowest Score</div>
            </div>
        </div>
    </div>

    <!-- Score Sheet Table -->
    <div class="content-card">
        <div class="card-hdr">
            <h5><i class="fas fa-table me-2 text-primary"></i>Score Sheet</h5>
            <div class="no-print">
                <input type="text" class="form-control form-control-sm" placeholder="Search student…" id="searchInput" style="max-width:200px;" oninput="filterTable()">
            </div>
        </div>
        <div style="overflow-x:auto;">
            <?php if (empty($scores)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                    <p class="text-muted fw-semibold">No scores recorded yet.</p>
                    <a href="create.php?edit=<?php echo $exam_id; ?>" class="btn btn-primary rounded-pill px-4">
                        <i class="fas fa-edit me-2"></i>Enter Scores
                    </a>
                </div>
            <?php else: ?>
                <table class="score-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Name</th>
                            <th>Student ID</th>
                            <th>Exam Title</th>
                            <th>Score</th>
                            <th>Total Items</th>
                            <th>MPS / Percentage</th>
                            <th>Performance Status</th>
                        </tr>
                    </thead>
                    <tbody id="scoreTableBody">
                        <?php foreach ($scores as $i => $s): ?>
                            <?php
                                $pct = $total > 0 ? round(($s['score'] / $total) * 100, 1) : 0;
                            ?>
                            <tr class="score-row" data-name="<?php echo strtolower($s['student_name']); ?>">
                                <td class="text-muted"><?php echo $i + 1; ?></td>
                                <td class="fw-bold" style="color:#1e3a8a;"><?php echo htmlspecialchars($s['student_name']); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($s['student_id_number']); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars($exam['title']); ?></td>
                                <td class="fw-bold"><?php echo number_format($s['score'], 1); ?></td>
                                <td><?php echo number_format($total); ?></td>
                                <td class="fw-bold" style="color:<?php echo $pct >= 75 ? '#1e40af' : '#991b1b'; ?>;">
                                    <?php echo $pct; ?>%
                                </td>
                                <td><?php echo getStatusBadge($pct); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</main>

<script>
function filterTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.score-row').forEach(r => {
        r.style.display = r.dataset.name.includes(q) ? '' : 'none';
    });
}
</script>

<?php include '../includes/footer.php'; ?>
