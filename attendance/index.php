<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'instructor') {
    header("Location: ../auth/login.php");
    exit;
}

$instructor_id = (int)$_SESSION['user_id'];
$instructor_name = $_SESSION['user_name'] ?? 'Instructor';

// ============================================================
// Get Parameters
// ============================================================
$selectedSubject = $_GET['subject'] ?? '';
$attendanceDate = $_GET['date'] ?? date('Y-m-d');
$searchTerm = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? '';
if (!in_array($filterStatus, ['present', 'absent', 'late', 'excused'], true)) {
    $filterStatus = '';
}

// ============================================================
// Handle POST Actions
// ============================================================
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Mark Attendance
    if (isset($_POST['mark_attendance'])) {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $attendance_date = $_POST['attendance_date'] ?? date('Y-m-d');
        $attendance_records = $_POST['attendance'] ?? [];
        
        if ($subject_id > 0 && !empty($attendance_records)) {
            try {
                foreach ($attendance_records as $student_id => $status) {
                    if (in_array($status, ['present', 'absent', 'excused'])) {
                        $stmt = $pdo->prepare("
                            INSERT INTO attendance (subject_id, student_id, attendance_date, status, marked_by, marked_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE status = ?, marked_at = NOW()
                        ");
                        $stmt->execute([$subject_id, $student_id, $attendance_date, $status, $instructor_id, $status]);
                    }
                }
                $success_msg = "Attendance marked successfully for " . date('M d, Y', strtotime($attendance_date));
            } catch (Exception $e) {
                $error_msg = "Error marking attendance: " . $e->getMessage();
            }
        }
    }

    // Mark All Present
    if (isset($_POST['mark_all_present'])) {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $attendance_date = $_POST['attendance_date'] ?? date('Y-m-d');
        $student_ids = $_POST['student_ids'] ?? [];
        
        if ($subject_id > 0 && !empty($student_ids)) {
            try {
                foreach ($student_ids as $student_id) {
                    $stmt = $pdo->prepare("
                        INSERT INTO attendance (subject_id, student_id, attendance_date, status, marked_by, marked_at)
                        VALUES (?, ?, ?, 'present', ?, NOW())
                        ON DUPLICATE KEY UPDATE status = 'present', marked_at = NOW()
                    ");
                    $stmt->execute([$subject_id, $student_id, $attendance_date, $instructor_id]);
                }
                $success_msg = "All students marked as present.";
            } catch (Exception $e) {
                $error_msg = "Error: " . $e->getMessage();
            }
        }
    }
}

// ============================================================
// Get Instructor's Subjects
// ============================================================
$mySubjects = $pdo->prepare("
    SELECT id, title, class_code, department_id 
    FROM subjects 
    WHERE instructor_id = ? AND status = 'active'
    ORDER BY title
");
$mySubjects->execute([$instructor_id]);
$mySubjects = $mySubjects->fetchAll();

// ============================================================
// Get Enrolled Students
// ============================================================
$students = [];
$attendanceStats = [
    'total' => 0,
    'present' => 0,
    'absent' => 0,
   
    'excused' => 0,
    'rate' => 0
];

if (!empty($selectedSubject)) {
    // Count total enrolled students in the subject
    $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM enrollments WHERE subject_id = ?");
    $countStmt->execute([$selectedSubject]);
    $attendanceStats['total'] = (int)$countStmt->fetchColumn();

    // Get attendance totals for the selected date
    $summaryStmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM attendance WHERE subject_id = ? AND attendance_date = ? GROUP BY status");
    $summaryStmt->execute([$selectedSubject, $attendanceDate]);
    while ($row = $summaryStmt->fetch()) {
        if (isset($attendanceStats[$row['status']])) {
            $attendanceStats[$row['status']] = (int)$row['count'];
        }
    }

    // Get students enrolled in the subject for the list
    $studentsQuery = "
        SELECT DISTINCT
            u.id, u.name, u.email,
            sp.student_id_number,
            COALESCE(
                (SELECT status FROM attendance 
                 WHERE subject_id = ? AND student_id = u.id AND attendance_date = ?),
                ''
            ) as attendance_status
        FROM enrollments e
        JOIN users u ON u.id = e.student_id
        LEFT JOIN student_profiles sp ON sp.student_id = u.id
        WHERE e.subject_id = ?
    ";
    
    if (!empty($searchTerm)) {
        $studentsQuery .= " AND (u.name LIKE ? OR sp.student_id_number LIKE ?)";
    }

    if ($filterStatus !== '') {
        $studentsQuery .= " AND EXISTS (
            SELECT 1 FROM attendance filtered_attendance
            WHERE filtered_attendance.subject_id = ?
              AND filtered_attendance.student_id = u.id
              AND filtered_attendance.attendance_date = ?
              AND filtered_attendance.status = ?
        )";
    }
    
    $studentsQuery .= " ORDER BY u.name ASC";
    
    $stmt = $pdo->prepare($studentsQuery);
    
    if (!empty($searchTerm)) {
        $wildcard = "%{$searchTerm}%";
        $params = [$selectedSubject, $attendanceDate, $selectedSubject, $wildcard, $wildcard];
    } else {
        $params = [$selectedSubject, $attendanceDate, $selectedSubject];
    }
    if ($filterStatus !== '') {
        $params[] = $selectedSubject;
        $params[] = $attendanceDate;
        $params[] = $filterStatus;
    }
    $stmt->execute($params);
    
    $students = $stmt->fetchAll();
    
    if ($attendanceStats['total'] > 0) {
        $attendanceStats['rate'] = round(($attendanceStats['present'] / $attendanceStats['total']) * 100, 1);
    }
}

// ============================================================
// Get Attendance History for Selected Subject
// ============================================================
$attendanceHistory = [];
if (!empty($selectedSubject)) {
    $historyQuery = $pdo->prepare("
        SELECT 
            attendance_date,
            COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
            COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent,
        
            COUNT(CASE WHEN status = 'excused' THEN 1 END) as excused,
            COUNT(*) as total
        FROM attendance
        WHERE subject_id = ? AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY attendance_date
        ORDER BY attendance_date DESC
        LIMIT 15
    ");
    $historyQuery->execute([$selectedSubject]);
    $attendanceHistory = $historyQuery->fetchAll();
}

// ============================================================
// Get Students with Low Attendance
// ============================================================
$lowAttendanceStudents = [];
if (!empty($selectedSubject)) {
    $lowAttQuery = $pdo->prepare("
        SELECT 
            u.id, u.name, sp.student_id_number,
            COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
            COUNT(*) as total_sessions,
            ROUND((COUNT(CASE WHEN a.status = 'present' THEN 1 END) / COUNT(*)) * 100, 1) as attendance_rate
        FROM enrollments e
        JOIN users u ON u.id = e.student_id
        LEFT JOIN student_profiles sp ON sp.student_id = u.id
        LEFT JOIN attendance a ON a.subject_id = e.subject_id AND a.student_id = u.id
        WHERE e.subject_id = ?
        GROUP BY u.id, u.name, sp.student_id_number
        HAVING attendance_rate < 75
        ORDER BY attendance_rate ASC
        LIMIT 10
    ");
    $lowAttQuery->execute([$selectedSubject]);
    $lowAttendanceStudents = $lowAttQuery->fetchAll();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    :root {
        --primary-blue: #1e3a8a;
        --secondary-blue: #2563eb;
        --light-blue: #3b82f6;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --info: #06b6d4;
    }

    body { background: #f1f5f9; }

    /* Page Header */
    .page-header {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
        border-radius: 16px;
        padding: 1.5rem 2rem;
        color: white;
        margin-bottom: 1.5rem;
    }

    /* Stat Cards */
    .stat-card {
        border-radius: 14px;
        border: none;
        overflow: hidden;
        transition: all 0.3s ease;
        height: 100%;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.12) !important;
    }

    .stat-card .card-body { padding: 1.25rem; }

    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--primary-blue);
    }

    .stat-label {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
    }

    /* Selector Section */
    .selector-section {
        background: white;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        margin-bottom: 1.5rem;
    }

    .form-select-custom {
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem 1rem;
        transition: all 0.2s ease;
    }

    .form-select-custom:focus {
        border-color: var(--secondary-blue);
        box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        outline: none;
    }

    /* Student List */
    .student-list-container {
        background: white;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }

    .student-list {
        max-height: 600px;
        overflow-y: auto;
    }

    .student-item {
        display: flex;
        align-items: center;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        transition: background 0.2s ease;
    }

    .student-item:hover {
        background: #f8fafc;
    }

    .student-item:last-child {
        border-bottom: none;
    }

    .student-avatar {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: #dbeafe;
        color: #2563eb;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.85rem;
        margin-right: 0.75rem;
        flex-shrink: 0;
    }

    .student-info {
        flex: 1;
        min-width: 0;
    }

    .student-name {
        font-weight: 600;
        color: #1e293b;
    }

    .student-id {
        font-size: 0.75rem;
        color: #64748b;
    }

    .student-attendance-rate {
        text-align: right;
        margin-right: 1rem;
        min-width: 60px;
    }

    .attendance-rate-value {
        font-weight: 700;
        font-size: 0.9rem;
    }

    .attendance-rate-good { color: var(--success); }
    .attendance-rate-warning { color: var(--warning); }
    .attendance-rate-danger { color: var(--danger); }

    /* Attendance Buttons */
    .attendance-buttons {
        display: flex;
        gap: 0.25rem;
    }

    .att-btn {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        border: 2px solid #e2e8f0;
        background: white;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
    }

    .att-btn * {
        pointer-events: none;
    }

    .att-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    }

    .att-btn.selected {
        border-width: 3px;
    }

    .att-btn[data-status="present"].selected {
        background: #dcfce7;
        border-color: var(--success);
        color: var(--success);
    }

    .att-btn[data-status="absent"].selected {
        background: #fee2e2;
        border-color: var(--danger);
        color: var(--danger);
    }

    .att-btn[data-status="excused"].selected {
        background: #e0e7ff;
        border-color: var(--info);
        color: var(--info);
    }

    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-present { background: #dcfce7; color: #166534; }
    .status-absent { background: #fee2e2; color: #991b1b; }
    .status-excused { background: #e0e7ff; color: #4338ca; }

    /* Chart */
    .chart-container {
        background: white;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }

    .chart-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--primary-blue);
        margin-bottom: 1rem;
    }

    /* Low Attendance Alert */
    .low-attendance-item {
        padding: 0.75rem;
        border-left: 4px solid var(--danger);
        background: #fef2f2;
        border-radius: 0 8px 8px 0;
        margin-bottom: 0.5rem;
    }

    @media (max-width: 768px) {
        .stat-value { font-size: 1.2rem; }
        .stat-icon { width: 40px; height: 40px; font-size: 1rem; }
        .attendance-buttons { flex-wrap: wrap; }
    }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="background: #f1f5f9; min-height: 100vh;">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 fw-bold mb-1">
                    <i class="fas fa-calendar-check me-2"></i>Attendance Management
                </h1>
                <p class="mb-0" style="opacity: 0.9;">Track and manage student attendance by subject</p>
            </div>
            <div>
                <button class="btn btn-outline-light" onclick="location.reload()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Subject Selector -->
    <div class="selector-section">
        <h5 class="fw-bold mb-3" style="color: var(--primary-blue);">
            <i class="fas fa-filter me-2"></i>Select Class
        </h5>
        <form method="GET" class="row g-3">
            <div class="col-lg-4 col-md-6">
                <label class="form-label small text-muted">Subject</label>
                <select name="subject" class="form-select form-select-custom" onchange="this.form.submit()">
                    <option value="">Select Subject</option>
                    <?php foreach ($mySubjects as $subject): ?>
                        <option value="<?php echo $subject['id']; ?>" <?php echo $selectedSubject == $subject['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($subject['class_code'] . ' - ' . $subject['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-4">
                <label class="form-label small text-muted">Date</label>
                <input type="date" name="date" class="form-control form-select-custom" value="<?php echo htmlspecialchars($attendanceDate); ?>">
            </div>
            <div class="col-lg-3 col-md-4">
                <label class="form-label small text-muted">Search</label>
                <input type="text" name="search" class="form-control form-select-custom" placeholder="Student name or ID..." value="<?php echo htmlspecialchars($searchTerm); ?>">
            </div>
            <div class="col-lg-2 col-md-4 d-flex align-items-end">
                <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--light-blue) 100%); color: white; font-weight: 600; width: 100%;">
                    <i class="fas fa-search me-1"></i> Load
                </button>
            </div>
        </form>
        <div class="mt-3 text-end">
            <?php if (!empty($selectedSubject)): ?>
                <a href="../subjects/view.php?id=<?php echo $selectedSubject; ?>" class="btn btn-outline-secondary me-2">
                    <i class="fas fa-eye me-1"></i> View Class
                </a>
                <a href="../assignments/index.php?subject_id=<?php echo $selectedSubject; ?>" class="btn btn-outline-primary">
                    <i class="fas fa-tasks me-1"></i> View Assignments
                </a>
            <?php else: ?>
                <button type="button" class="btn btn-outline-secondary me-2" disabled>
                    <i class="fas fa-eye me-1"></i> View Class
                </button>
                <button type="button" class="btn btn-outline-primary" disabled>
                    <i class="fas fa-tasks me-1"></i> View Assignments
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($selectedSubject)): ?>
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card stat-card shadow-sm">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon me-3" style="background: #dbeafe; color: #2563eb;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $attendanceStats['total']; ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card stat-card shadow-sm">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon me-3" style="background: #dcfce7; color: #059669;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $attendanceStats['present']; ?></div>
                            <div class="stat-label">Present</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card stat-card shadow-sm">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon me-3" style="background: #fee2e2; color: #dc2626;">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $attendanceStats['absent']; ?></div>
                            <div class="stat-label">Absent</div>
                        </div>
                    </div>
                </div>
            </div>
           
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card stat-card shadow-sm">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon me-3" style="background: #e0e7ff; color: #4f46e5;">
                            <i class="fas fa-file-excel"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $attendanceStats['excused']; ?></div>
                            <div class="stat-label">Excused</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card stat-card shadow-sm">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon me-3" style="background: #dcfce7; color: #059669;">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?php echo $attendanceStats['rate']; ?>%</div>
                            <div class="stat-label">Attendance Rate</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="row g-4">
            <!-- Student List -->
            <div class="col-lg-8">
                <div class="student-list-container">
                    <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                        <h5 class="fw-bold mb-0" style="color: var(--primary-blue);">
                            <i class="fas fa-user-graduate me-2"></i>
                            <?php echo $filterStatus ? ucfirst($filterStatus) . ' Students' : 'Student List (A-Z)'; ?>
                        </h5>
                        <?php if (!$filterStatus): ?>
                            <button type="button" id="btn-mark-all-present" class="btn btn-sm btn-outline-success" onclick="markAllPresent()">
                                <i class="fas fa-check-double me-1"></i> Mark All Present
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex flex-wrap gap-2 p-3 border-bottom">
                        <?php
                            $filterBase = '?subject=' . urlencode((string)$selectedSubject)
                                . '&date=' . urlencode($attendanceDate)
                                . '&search=' . urlencode($searchTerm);
                        ?>
                        <a class="btn btn-sm <?php echo $filterStatus === '' ? 'btn-primary' : 'btn-outline-primary'; ?>"
                           href="<?php echo $filterBase; ?>">All students</a>
                        <?php foreach (['present' => 'Present', 'absent' => 'Absent', 'excused' => 'Excused'] as $statusValue => $statusLabel): ?>
                            <a class="btn btn-sm <?php echo $filterStatus === $statusValue ? 'btn-primary' : 'btn-outline-primary'; ?>"
                               href="<?php echo $filterBase . '&status=' . $statusValue; ?>">
                                <?php echo $statusLabel; ?> (<?php echo (int)$attendanceStats[$statusValue]; ?>)
                            </a>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (!empty($students)): ?>
                        <form method="POST">
                            <input type="hidden" name="subject_id" value="<?php echo $selectedSubject; ?>">
                            <input type="hidden" name="attendance_date" value="<?php echo $attendanceDate; ?>">
                            
                            <div class="student-list">
                                <?php foreach ($students as $student): ?>
                                    <div class="student-item">
                                        <div class="student-avatar">
                                            <?php echo strtoupper(substr($student['name'], 0, 2)); ?>
                                        </div>
                                        <div class="student-info">
                                            <div class="student-name"><?php echo htmlspecialchars($student['name']); ?></div>
                                            <div class="student-id"><?php echo htmlspecialchars($student['student_id_number'] ?? 'N/A'); ?></div>
                                        </div>
                                        <div class="student-attendance-rate">
                                            <?php
                                            // Calculate individual attendance rate
                                            $indivAttQuery = $pdo->prepare("
                                                SELECT 
                                                    COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
                                                    COUNT(*) as total
                                                FROM attendance
                                                WHERE student_id = ? AND subject_id = ?
                                            ");
                                            $indivAttQuery->execute([$student['id'], $selectedSubject]);
                                            $indivAtt = $indivAttQuery->fetch();
                                            $indivRate = $indivAtt['total'] > 0 ? round(($indivAtt['present'] / $indivAtt['total']) * 100, 1) : 100;
                                            $rateClass = $indivRate >= 90 ? 'attendance-rate-good' : ($indivRate >= 75 ? 'attendance-rate-warning' : 'attendance-rate-danger');
                                            ?>
                                            <div class="attendance-rate-value <?php echo $rateClass; ?>"><?php echo $indivRate; ?>%</div>
                                        </div>
                                        <div class="attendance-buttons">
                                            <button type="button" class="att-btn <?php echo ($student['attendance_status'] === 'present' || empty($student['attendance_status'])) ? 'selected' : ''; ?>" 
                                                     data-status="present" data-student="<?php echo $student['id']; ?>" title="Present"
                                                     onclick="selectAttendance('<?php echo $student['id']; ?>', 'present')">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="att-btn <?php echo $student['attendance_status'] === 'absent' ? 'selected' : ''; ?>" 
                                                     data-status="absent" data-student="<?php echo $student['id']; ?>" title="Absent"
                                                     onclick="selectAttendance('<?php echo $student['id']; ?>', 'absent')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <button type="button" class="att-btn <?php echo $student['attendance_status'] === 'excused' ? 'selected' : ''; ?>" 
                                                     data-status="excused" data-student="<?php echo $student['id']; ?>" title="Excused"
                                                     onclick="selectAttendance('<?php echo $student['id']; ?>', 'excused')">
                                                <i class="fas fa-file-alt"></i>
                                            </button>
                                        </div>
                                        <input type="hidden" name="attendance[<?php echo $student['id']; ?>]" value="<?php echo $student['attendance_status'] ?: 'present'; ?>" 
                                               id="attendance-<?php echo $student['id']; ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="p-3 border-top d-flex justify-content-between">
                                <span class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    <?php echo count($students); ?> students • <?php echo date('M d, Y', strtotime($attendanceDate)); ?>
                                </span>
                                <button type="submit" name="mark_attendance" class="btn text-white" style="background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--light-blue) 100%); font-weight: 600; border: none;">
                                    <i class="fas fa-save me-1"></i> Save Attendance
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-user-graduate" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                            <p class="text-muted">No students found for this subject.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Attendance History Chart -->
                <div class="chart-container mb-4">
                    <h5 class="chart-title">
                        <i class="fas fa-chart-bar me-2"></i>Attendance Trends (Last 15 Sessions)
                    </h5>
                    <?php if (!empty($attendanceHistory)): ?>
                        <canvas id="attendanceChart" height="200"></canvas>
                    <?php else: ?>
                        <p class="text-muted text-center py-3">No attendance data available.</p>
                    <?php endif; ?>
                </div>

                <!-- Low Attendance Students -->
                <div class="chart-container">
                    <h5 class="chart-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Low Attendance (< 75%)
                    </h5>
                    <?php if (!empty($lowAttendanceStudents)): ?>
                        <?php foreach ($lowAttendanceStudents as $student): ?>
                            <div class="low-attendance-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-semibold" style="font-size: 0.85rem;"><?php echo htmlspecialchars($student['name']); ?></div>
                                        <small class="text-muted"><?php echo $student['present_count']; ?> / <?php echo $student['total_sessions']; ?> sessions</small>
                                    </div>
                                    <span class="status-badge status-absent"><?php echo $student['attendance_rate']; ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center py-3">All students have good attendance!</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Empty State -->
        <div class="text-center py-5">
            <i class="fas fa-calendar-check" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 1.5rem;"></i>
            <h4 class="text-muted mb-2">Select a Class to Begin</h4>
            <p class="text-muted">Choose a subject above to view students and mark attendance.</p>
        </div>
    <?php endif; ?>
</main>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Global function - called via onclick on each button
window.selectAttendance = function(studentId, status) {
    // Deselect all buttons for this student
    var allBtns = document.querySelectorAll('[data-student="' + studentId + '"]');
    for (var i = 0; i < allBtns.length; i++) {
        allBtns[i].classList.remove('selected');
    }
    // Select the clicked one
    var target = document.querySelector('[data-student="' + studentId + '"][data-status="' + status + '"]');
    if (target) {
        target.classList.add('selected');
    }
    // Update the hidden form input
    var inp = document.getElementById('attendance-' + studentId);
    if (inp) {
        inp.value = status;
    }
};

// Mark All Present button
window.markAllPresent = function() {
    var presentBtns = document.querySelectorAll('[data-status="present"]');
    for (var i = 0; i < presentBtns.length; i++) {
        var sid = presentBtns[i].getAttribute('data-student');
        if (sid) {
            window.selectAttendance(sid, 'present');
        }
    }
};
</script>

<?php if (!empty($attendanceHistory)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    try {
        var attCtx = document.getElementById('attendanceChart');
        if (!attCtx) return;
        new Chart(attCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(function($d) { return date('M d', strtotime($d['attendance_date'])); }, array_reverse(array_column($attendanceHistory, 'attendance_date')))); ?>,
                datasets: [
                    {
                        label: 'Present',
                        data: <?php echo json_encode(array_reverse(array_column($attendanceHistory, 'present'))); ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Absent',
                        data: <?php echo json_encode(array_reverse(array_column($attendanceHistory, 'absent'))); ?>,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    } catch (e) {
        console.error('Chart error:', e);
    }
});
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>