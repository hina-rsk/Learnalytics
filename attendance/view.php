<?php
session_start();

// Check authentication FIRST - before any output
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];
$subject_id = $_GET['subject_id'] ?? '';

// Validate subject_id - before any output
if (empty($subject_id)) {
    header("Location: index.php");
    exit;
}

// Verify access - before any output
if ($role === 'student') {
    $stmt = $pdo->prepare("
        SELECT s.id FROM subjects s
        JOIN enrollments e ON s.id = e.subject_id
        WHERE s.id = ? AND e.student_id = ?
    ");
    $stmt->execute([$subject_id, $user_id]);
    if (!$stmt->fetch()) {
        header("Location: index.php");
        exit;
    }
} elseif ($role === 'instructor') {
    $stmt = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND instructor_id = ?");
    $stmt->execute([$subject_id, $user_id]);
    if (!$stmt->fetch()) {
        header("Location: index.php");
        exit;
    }
}

// Now safe to include header (after all redirects are done)
require_once '../includes/header.php';
require_once '../includes/sidebar.php';

// Get subject info
$stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ?");
$stmt->execute([$subject_id]);
$subject = $stmt->fetch();

// Get attendance data
if ($role === 'student') {
    $stmt = $pdo->prepare("
        SELECT * FROM attendance 
        WHERE subject_id = ? AND student_id = ?
        ORDER BY attendance_date DESC
    ");
    $stmt->execute([$subject_id, $user_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT a.*, u.name as student_name
        FROM attendance a
        JOIN users u ON a.student_id = u.id
        WHERE a.subject_id = ?
        ORDER BY a.attendance_date DESC, u.name
    ");
    $stmt->execute([$subject_id]);
}
$attendance_records = $stmt->fetchAll();

// Calculate summary
$summary = [
    'total' => count($attendance_records),
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'excused' => 0
];

foreach ($attendance_records as $record) {
    $summary[$record['status']]++;
}

$attendance_percentage = $summary['total'] > 0 ? round(($summary['present'] / $summary['total']) * 100, 1) : 0;
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">
            <i class="fas fa-calendar-check me-2"></i> Attendance Details
        </h1>
        <a href="index.php" class="btn btn-outline-primary">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>

    <div class="row mb-4">
        <div class="col-lg-12">
            <div class="card shadow border-0">
                <div class="card-header" style="background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: white;">
                    <h5 class="card-title mb-0"><?= htmlspecialchars($subject['title']) ?></h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 text-center">
                            <div class="mb-3">
                                <small class="text-muted d-block">Total Days</small>
                                <h3 style="color: #2563eb;"><?= $summary['total'] ?></h3>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="mb-3">
                                <small class="text-muted d-block">Present</small>
                                <h3 style="color: #10b981;"><?= $summary['present'] ?></h3>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="mb-3">
                                <small class="text-muted d-block">Absent</small>
                                <h3 style="color: #ef4444;"><?= $summary['absent'] ?></h3>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="mb-3">
                                <small class="text-muted d-block">Attendance Rate</small>
                                <h3 style="color: #f59e0b;"><?= $attendance_percentage ?>%</h3>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="progress" style="height: 30px;">
                            <div class="progress-bar" role="progressbar" 
                                 style="width: <?= $attendance_percentage ?>%; background: linear-gradient(135deg, #10b981 0%, #059669 100%);" 
                                 aria-valuenow="<?= $attendance_percentage ?>" aria-valuemin="0" aria-valuemax="100">
                                <?= $attendance_percentage ?>% Present
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow border-0">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0">Attendance Records</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($attendance_records)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead style="background: #f8f9fa;">
                                <tr>
                                    <th style="color: #1e3a8a; font-weight: 600;">Date</th>
                                    <?php if ($role !== 'student'): ?>
                                    <th style="color: #1e3a8a; font-weight: 600;">Student</th>
                                    <?php endif; ?>
                                    <th style="color: #1e3a8a; font-weight: 600;">Status</th>
                                    <th style="color: #1e3a8a; font-weight: 600;">Notes</th>
                                    <th style="color: #1e3a8a; font-weight: 600;">Marked At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance_records as $record): 
                                    $status_colors = [
                                        'present' => '#10b981',
                                        'absent' => '#ef4444',
                                        'late' => '#f59e0b',
                                        'excused' => '#8b5cf6'
                                    ];
                                    $color = $status_colors[$record['status']] ?? '#6c757d';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= date('M d, Y (l)', strtotime($record['attendance_date'])) ?></strong>
                                    </td>
                                    <?php if ($role !== 'student'): ?>
                                    <td><?= htmlspecialchars($record['student_name']) ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="badge" style="background: <?= $color ?>; color: white; padding: 6px 12px;">
                                            <?= ucfirst($record['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($record['notes'])): ?>
                                            <small><?= htmlspecialchars($record['notes']) ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= date('M d, H:i', strtotime($record['marked_at'])) ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i> No attendance records found.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.card-header-custom {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border-left: 4px solid #2563eb;
    color: #1e3a8a;
    font-weight: 600;
}
</style>

<?php include '../includes/footer.php'; ?>
