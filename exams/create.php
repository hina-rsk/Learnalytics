<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header("Location: ../auth/login.php");
    exit;
}

$instructor_id = (int)$_SESSION['user_id'];
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$is_edit = $edit_id > 0;

$errors = [];
$success = '';

// ─── Fetch instructor's subjects ───────────────────────────
$stmt = $pdo->prepare("
    SELECT s.id, s.title, s.section_id, sec.name AS section_name
    FROM subjects s
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE s.instructor_id = ? AND s.status = 'active'
    ORDER BY s.title
");
$stmt->execute([$instructor_id]);
$instructor_subjects = $stmt->fetchAll();

// ─── Load existing exam if editing ─────────────────────────
$exam = null;
$existing_scores = [];
if ($is_edit) {
    $stmt = $pdo->prepare("
        SELECT er.*, s.title AS subject_title, COALESCE(sec.name,'N/A') AS section_name_label
        FROM exam_records er
        INNER JOIN subjects s ON er.subject_id = s.id
        LEFT JOIN sections sec ON er.section_id = sec.id
        WHERE er.id = ? AND er.instructor_id = ?
    ");
    $stmt->execute([$edit_id, $instructor_id]);
    $exam = $stmt->fetch();
    if (!$exam) {
        header("Location: index.php");
        exit;
    }

    // Load existing scores keyed by student_id
    $stmt = $pdo->prepare("SELECT student_id, score FROM exam_scores WHERE exam_id = ?");
    $stmt->execute([$edit_id]);
    foreach ($stmt->fetchAll() as $row) {
        $existing_scores[$row['student_id']] = $row['score'];
    }
}

// ─── POST handler ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title'] ?? '');
    $subject_id  = (int)($_POST['subject_id'] ?? 0);
    $exam_date   = trim($_POST['exam_date'] ?? '');
    $total_items = (int)($_POST['total_items'] ?? 0);
    $scores_raw  = $_POST['scores'] ?? []; // [student_id => score]

    if ($title === '')        $errors[] = 'Exam title is required.';
    if ($subject_id <= 0)    $errors[] = 'Please select a subject.';
    if ($exam_date === '')   $errors[] = 'Exam date is required.';
    if ($total_items <= 0)   $errors[] = 'Total items must be greater than 0.';

    // Verify subject ownership
    if ($subject_id > 0) {
        $chk = $pdo->prepare("SELECT id, section_id FROM subjects WHERE id = ? AND instructor_id = ?");
        $chk->execute([$subject_id, $instructor_id]);
        $subject_row = $chk->fetch();
        if (!$subject_row) {
            $errors[] = 'Invalid subject selected.';
        }
    }

    if (empty($errors)) {
        $section_id = $subject_row['section_id'] ?? null;

        if ($is_edit) {
            // Update exam record
            $pdo->prepare("
                UPDATE exam_records SET title=?, subject_id=?, section_id=?, exam_date=?, total_items=?, updated_at=NOW()
                WHERE id=? AND instructor_id=?
            ")->execute([$title, $subject_id, $section_id, $exam_date, $total_items, $edit_id, $instructor_id]);

            $exam_id = $edit_id;
        } else {
            // Insert exam record
            $stmt = $pdo->prepare("
                INSERT INTO exam_records (instructor_id, subject_id, section_id, title, exam_date, total_items)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$instructor_id, $subject_id, $section_id, $title, $exam_date, $total_items]);
            $exam_id = $pdo->lastInsertId();
        }

        // Upsert scores
        $upsert = $pdo->prepare("
            INSERT INTO exam_scores (exam_id, student_id, score, recorded_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()
        ");

        foreach ($scores_raw as $stu_id => $score_val) {
            $stu_id    = (int)$stu_id;
            $score_val = is_numeric($score_val) ? max(0, min((float)$score_val, $total_items)) : null;
            if ($stu_id > 0 && $score_val !== null) {
                $upsert->execute([$exam_id, $stu_id, $score_val]);
            }
        }

        header("Location: index.php?saved=1");
        exit;
    }
}

// ─── Fetch students for selected subject (for JS / pre-fill) ─
$students_for_subject = [];
$selected_subject_id  = $is_edit ? ($exam['subject_id'] ?? 0) : (isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0);

if ($selected_subject_id > 0) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email,
               COALESCE(sp.student_id_number, CONCAT('STU-', LPAD(u.id,4,'0'))) AS student_id_number
        FROM enrollments e
        INNER JOIN users u ON u.id = e.student_id
        LEFT JOIN student_profiles sp ON sp.student_id = u.id
        WHERE e.subject_id = ?
        ORDER BY u.name
    ");
    $stmt->execute([$selected_subject_id]);
    $students_for_subject = $stmt->fetchAll();
}

$baseUrl  = '..';
$pageTitle = ($is_edit ? 'Edit' : 'Create') . ' Exam Record — LearnAlytics';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    body { background: #f1f5f9; }
    .page-banner {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        border-radius: 16px; padding: 1.6rem 2rem; color: white;
        box-shadow: 0 10px 25px rgba(37,99,235,.18); margin-bottom: 2rem;
    }
    .form-card {
        background: white; border-radius: 16px; border: none;
        box-shadow: 0 4px 15px rgba(0,0,0,.06); padding: 1.75rem 2rem; margin-bottom: 1.5rem;
    }
    .section-label {
        font-weight: 700; color: #1e3a8a; font-size: .8rem;
        text-transform: uppercase; letter-spacing: .7px; margin-bottom: 1rem;
        padding-bottom: .5rem; border-bottom: 2px solid #e2e8f0;
    }
    .score-table { font-size: .875rem; width: 100%; border-collapse: collapse; }
    .score-table thead { background: #f8fafc; }
    .score-table th {
        padding: .7rem 1rem; font-weight: 700; color: #475569;
        text-transform: uppercase; font-size: .72rem; letter-spacing: .5px;
        border-bottom: 2px solid #e2e8f0; white-space: nowrap;
    }
    .score-table td { padding: .6rem 1rem; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
    .score-table tbody tr:hover { background: #f8fafc; }
    .score-input {
        width: 90px; border: 1px solid #d1d5db; border-radius: 8px;
        padding: .35rem .6rem; font-size: .9rem; font-weight: 600;
        text-align: center; transition: border-color .2s;
    }
    .score-input:focus { border-color: #2563eb; outline: none; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
    .mps-display { font-weight: 700; font-size: .88rem; min-width: 60px; display: inline-block; }
    .status-badge { display: inline-block; padding: .22rem .65rem; border-radius: 999px; font-size: .75rem; font-weight: 700; }
    .status-excellent { background:#dcfce7; color:#166534; }
    .status-good      { background:#dbeafe; color:#1e40af; }
    .status-average   { background:#fef3c7; color:#92400e; }
    .status-critical  { background:#fee2e2; color:#991b1b; }
    #loadingStudents { display: none; }
    .progress-bar-mps { height: 6px; border-radius: 3px; background: #e2e8f0; overflow: hidden; }
    .progress-bar-mps div { height: 100%; border-radius: 3px; transition: width .4s; }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="min-height:100vh;">

    <!-- Banner -->
    <div class="page-banner">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <span class="badge bg-white text-primary fw-bold px-3 py-1 mb-2 rounded-pill" style="font-size:.75rem;">Assessment Management</span>
                <h2 class="fw-bold mb-1">
                    <i class="fas fa-<?php echo $is_edit ? 'edit' : 'plus-circle'; ?> me-2"></i>
                    <?php echo $is_edit ? 'Edit Exam Record' : 'Create New Exam Record'; ?>
                </h2>
                <p class="mb-0 text-white-50 small">
                    <?php echo $is_edit ? 'Update exam details and student scores.' : 'Record a manually conducted exam and enter student scores.'; ?>
                </p>
            </div>
            <a href="index.php" class="btn btn-light fw-bold px-4 py-2 rounded-pill text-primary">
                <i class="fas fa-arrow-left me-2"></i>Back to Exams
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger rounded-3 mb-4">
            <i class="fas fa-exclamation-circle me-2"></i>
            <strong>Please fix the following:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" id="examForm">

        <!-- Exam Details -->
        <div class="form-card">
            <div class="section-label"><i class="fas fa-info-circle me-2"></i>Exam Details</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold small text-muted">Exam Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. 1st Periodic Examination"
                        value="<?php echo htmlspecialchars($_POST['title'] ?? $exam['title'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small text-muted">Subject <span class="text-danger">*</span></label>
                    <select name="subject_id" class="form-select" id="subjectSelect" required>
                        <option value="">— Select Subject —</option>
                        <?php foreach ($instructor_subjects as $sub): ?>
                            <option value="<?php echo $sub['id']; ?>"
                                data-section-id="<?php echo $sub['section_id']; ?>"
                                <?php echo ($selected_subject_id == $sub['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sub['title']); ?>
                                <?php echo $sub['section_name'] ? '(' . htmlspecialchars($sub['section_name']) . ')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small text-muted">Exam Date <span class="text-danger">*</span></label>
                    <input type="date" name="exam_date" class="form-control"
                        value="<?php echo htmlspecialchars($_POST['exam_date'] ?? $exam['exam_date'] ?? date('Y-m-d')); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small text-muted">Total Items / Max Score <span class="text-danger">*</span></label>
                    <input type="number" name="total_items" class="form-control" id="totalItemsInput"
                        min="1" max="1000" placeholder="e.g. 50"
                        value="<?php echo htmlspecialchars($_POST['total_items'] ?? $exam['total_items'] ?? ''); ?>" required>
                    <div class="form-text">The maximum possible score for this exam.</div>
                </div>
            </div>
        </div>

        <!-- Student Scores -->
        <div class="form-card">
            <div class="section-label"><i class="fas fa-users me-2"></i>Student Scores</div>

            <div id="loadingStudents" class="text-center py-4">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="text-muted small mt-2">Loading enrolled students…</p>
            </div>

            <div id="noSubjectMsg" class="text-center py-4 text-muted" <?php echo ($selected_subject_id > 0) ? 'style="display:none;"' : ''; ?>>
                <i class="fas fa-arrow-up fa-2x mb-2 d-block"></i>
                <p class="small">Select a subject above to load enrolled students.</p>
            </div>

            <div id="studentsTableWrap" <?php echo ($selected_subject_id == 0) ? 'style="display:none;"' : ''; ?>>
                <div class="mb-3 d-flex align-items-center gap-3 flex-wrap">
                    <div class="input-group input-group-sm" style="max-width:260px;">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="studentSearch" class="form-control border-start-0" placeholder="Search student…" oninput="filterStudents()">
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="clearAllScores()">
                        <i class="fas fa-eraser me-1"></i>Clear All Scores
                    </button>
                    <div class="ms-auto small text-muted" id="scoreCountDisplay"></div>
                </div>

                <div style="overflow-x:auto; max-height:520px; overflow-y:auto;">
                    <table class="score-table">
                        <thead style="position:sticky;top:0;z-index:1;">
                            <tr>
                                <th>#</th>
                                <th>Student Name</th>
                                <th>Student ID</th>
                                <th>Score</th>
                                <th>Out of</th>
                                <th>MPS / Percentage</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody id="studentsTableBody">
                            <?php if (!empty($students_for_subject)): ?>
                                <?php foreach ($students_for_subject as $i => $stu): ?>
                                    <?php $saved_score = $existing_scores[$stu['id']] ?? ''; ?>
                                    <tr class="student-row" data-name="<?php echo strtolower($stu['name']); ?>">
                                        <td class="text-muted"><?php echo $i + 1; ?></td>
                                        <td class="fw-semibold" style="color:#1e3a8a;"><?php echo htmlspecialchars($stu['name']); ?></td>
                                        <td class="text-muted small"><?php echo htmlspecialchars($stu['student_id_number']); ?></td>
                                        <td>
                                            <input type="number" name="scores[<?php echo $stu['id']; ?>]"
                                                class="score-input" min="0"
                                                max="<?php echo $exam['total_items'] ?? 999; ?>"
                                                step="0.01"
                                                value="<?php echo $saved_score !== '' ? htmlspecialchars($saved_score) : ''; ?>"
                                                placeholder="—"
                                                oninput="calcMPS(this)"
                                                data-student-id="<?php echo $stu['id']; ?>">
                                        </td>
                                        <td class="text-muted small total-display">
                                            <?php echo $exam['total_items'] ?? '—'; ?>
                                        </td>
                                        <td>
                                            <span class="mps-display" id="mps-<?php echo $stu['id']; ?>">
                                                <?php
                                                    if ($saved_score !== '' && isset($exam['total_items']) && $exam['total_items'] > 0) {
                                                        echo round(($saved_score / $exam['total_items']) * 100, 1) . '%';
                                                    } else { echo '—'; }
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span id="status-<?php echo $stu['id']; ?>" class="status-badge">
                                                <?php
                                                    if ($saved_score !== '' && isset($exam['total_items']) && $exam['total_items'] > 0) {
                                                        $pct = ($saved_score / $exam['total_items']) * 100;
                                                        if ($pct >= 85) echo '<span class="status-badge status-excellent"><i class="fas fa-check-circle me-1"></i>Excellent</span>';
                                                        elseif ($pct >= 75) echo '<span class="status-badge status-good"><i class="fas fa-thumbs-up me-1"></i>Good</span>';
                                                        elseif ($pct >= 60) echo '<span class="status-badge status-average"><i class="fas fa-exclamation-triangle me-1"></i>Needs Improvement</span>';
                                                        else echo '<span class="status-badge status-critical"><i class="fas fa-exclamation-circle me-1"></i>Critical</span>';
                                                    } else {
                                                        echo '—';
                                                    }
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Submit -->
        <div class="d-flex gap-3 justify-content-end pb-5">
            <a href="index.php" class="btn btn-light rounded-pill px-4 fw-semibold">Cancel</a>
            <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold" id="saveBtn">
                <i class="fas fa-save me-2"></i><?php echo $is_edit ? 'Update Scores' : 'Save Exam Record'; ?>
            </button>
        </div>

    </form>
</main>

<script>
const TOTAL_ITEMS_INPUT = document.getElementById('totalItemsInput');
const SUBJECT_SELECT    = document.getElementById('subjectSelect');
const TABLE_BODY        = document.getElementById('studentsTableBody');

// ── Recalculate MPS for a single row ──
function calcMPS(input) {
    const total = parseFloat(TOTAL_ITEMS_INPUT.value) || 0;
    const score = parseFloat(input.value);
    const stuId = input.dataset.studentId;
    const mpsEl = document.getElementById('mps-' + stuId);
    const statusEl = document.getElementById('status-' + stuId);

    if (!isNaN(score) && total > 0 && input.value.trim() !== '') {
        const pct = Math.round((score / total) * 1000) / 10;
        mpsEl.textContent = pct + '%';
        mpsEl.style.color = pct >= 75 ? '#1e40af' : '#991b1b';

        let badge = '';
        if (pct >= 85)      badge = '<span class="status-badge status-excellent"><i class="fas fa-check-circle me-1"></i>Excellent</span>';
        else if (pct >= 75) badge = '<span class="status-badge status-good"><i class="fas fa-thumbs-up me-1"></i>Good</span>';
        else if (pct >= 60) badge = '<span class="status-badge status-average"><i class="fas fa-exclamation-triangle me-1"></i>Needs Improvement</span>';
        else                badge = '<span class="status-badge status-critical"><i class="fas fa-exclamation-circle me-1"></i>Critical</span>';
        statusEl.innerHTML = badge;
    } else {
        mpsEl.textContent = '—';
        mpsEl.style.color = '';
        statusEl.innerHTML = '—';
    }
    updateScoreCount();
}

// ── Recalculate all rows when total items changes ──
TOTAL_ITEMS_INPUT.addEventListener('input', function () {
    const total = parseFloat(this.value) || 0;
    document.querySelectorAll('.score-input').forEach(inp => calcMPS(inp));
    document.querySelectorAll('.total-display').forEach(td => td.textContent = total > 0 ? total : '—');
    // Update max attr
    document.querySelectorAll('.score-input').forEach(inp => inp.max = total);
});

// ── Load students via AJAX when subject changes ──
SUBJECT_SELECT.addEventListener('change', function () {
    const subjectId = this.value;
    if (!subjectId) {
        document.getElementById('studentsTableWrap').style.display = 'none';
        document.getElementById('noSubjectMsg').style.display = '';
        return;
    }
    document.getElementById('noSubjectMsg').style.display = 'none';
    document.getElementById('loadingStudents').style.display = '';
    document.getElementById('studentsTableWrap').style.display = 'none';

    fetch('get_students.php?subject_id=' + subjectId)
        .then(r => r.json())
        .then(data => {
            document.getElementById('loadingStudents').style.display = 'none';
            document.getElementById('studentsTableWrap').style.display = '';
            if (data.error) {
                TABLE_BODY.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4">${data.error}</td></tr>`;
                return;
            }
            if (data.students.length === 0) {
                TABLE_BODY.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-users fa-2x mb-2 d-block"></i>No students enrolled in this subject.</td></tr>`;
                return;
            }
            const total = parseFloat(TOTAL_ITEMS_INPUT.value) || 0;
            TABLE_BODY.innerHTML = data.students.map((s, i) => `
                <tr class="student-row" data-name="${s.name.toLowerCase()}">
                    <td class="text-muted">${i + 1}</td>
                    <td class="fw-semibold" style="color:#1e3a8a;">${escHtml(s.name)}</td>
                    <td class="text-muted small">${escHtml(s.student_id_number)}</td>
                    <td>
                        <input type="number" name="scores[${s.id}]" class="score-input"
                            min="0" max="${total}" step="0.01" placeholder="—"
                            oninput="calcMPS(this)" data-student-id="${s.id}">
                    </td>
                    <td class="text-muted small total-display">${total > 0 ? total : '—'}</td>
                    <td><span class="mps-display" id="mps-${s.id}">—</span></td>
                    <td><span id="status-${s.id}">—</span></td>
                </tr>
            `).join('');
            updateScoreCount();
        })
        .catch(() => {
            document.getElementById('loadingStudents').style.display = 'none';
            document.getElementById('studentsTableWrap').style.display = '';
            TABLE_BODY.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4">Failed to load students.</td></tr>`;
        });
});

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function filterStudents() {
    const q = document.getElementById('studentSearch').value.toLowerCase();
    document.querySelectorAll('.student-row').forEach(row => {
        row.style.display = row.dataset.name.includes(q) ? '' : 'none';
    });
}

function clearAllScores() {
    document.querySelectorAll('.score-input').forEach(inp => {
        inp.value = '';
        calcMPS(inp);
    });
}

function updateScoreCount() {
    const total = document.querySelectorAll('.score-input').length;
    const filled = Array.from(document.querySelectorAll('.score-input')).filter(i => i.value.trim() !== '').length;
    const el = document.getElementById('scoreCountDisplay');
    if (el) el.textContent = `${filled} / ${total} scores entered`;
}

// Init on edit
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.score-input').forEach(inp => {
        if (inp.value.trim() !== '') calcMPS(inp);
    });
    updateScoreCount();
});
</script>

<?php include '../includes/footer.php'; ?>
