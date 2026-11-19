<?php
session_start();
require_once __DIR__ . '/config/database.php';

$role = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id']) || $role !== 'admin') {
    if (isset($_SESSION['user_id']) && $role === 'dean') {
        header('Location: dean_dashboard.php');
        exit;
    }
    header('Location: auth/login.php');
    exit;
}
$canManage = $role === 'admin';
if (empty($_SESSION['academic_year_management_csrf'])) {
    $_SESSION['academic_year_management_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$today = date('Y-m-d');
$editId = (int)($_GET['edit'] ?? 0);
$selectedYearId = (int)($_GET['academic_year_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$isValidDate = static function ($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        exit('Only administrators can change academic years.');
    }
    $csrf = $_POST['csrf_token'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['academic_year_management_csrf'], $csrf)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (isset($_POST['set_year_status'])) {
        $yearId = (int)$_POST['set_year_status'];
        $newStatus = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'inactive';
        try {
            $pdo->beginTransaction();
            $yearStmt = $pdo->prepare('SELECT id, start_date, end_date FROM academic_years WHERE id = ? FOR UPDATE');
            $yearStmt->execute([$yearId]);
            $year = $yearStmt->fetch();
            if (!$year) {
                throw new RuntimeException('Academic year not found.');
            }
            $isCurrent = $newStatus === 'active' && $year['start_date'] <= $today && $year['end_date'] >= $today;
            if ($isCurrent) {
                $pdo->exec('UPDATE academic_years SET is_current = 0');
            }
            $pdo->prepare('UPDATE academic_years SET status = ?, is_current = ? WHERE id = ?')
                ->execute([$newStatus, $isCurrent ? 1 : 0, $yearId]);
            $pdo->commit();
            $message = $newStatus === 'active' ? 'Academic year activated.' : 'Academic year deactivated.';
            $selectedYearId = $yearId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not update academic year status.';
        }
    } elseif (isset($_POST['save_academic_year'])) {
        $yearId = (int)($_POST['academic_year_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $allowOverlap = !empty($_POST['allow_overlap']);

        if ($name === '' || $startDate === '' || $endDate === '') {
            $error = 'Academic year name, start date, and end date are required.';
        } elseif (mb_strlen($name) > 255 || mb_strlen($description) > 5000) {
            $error = 'Academic year name or description is too long.';
        } elseif (!$isValidDate($startDate) || !$isValidDate($endDate)) {
            $error = 'Enter valid start and end dates.';
        } elseif ($endDate <= $startDate) {
            $error = 'The end date must be later than the start date.';
        } else {
            $duplicate = $pdo->prepare('SELECT id FROM academic_years WHERE name = ? AND id <> ? LIMIT 1');
            $duplicate->execute([$name, $yearId]);
            if ($duplicate->fetchColumn()) {
                $error = 'An academic year with this name already exists.';
            } else {
                $overlap = $pdo->prepare('SELECT id, name FROM academic_years WHERE id <> ? AND start_date <= ? AND end_date >= ? ORDER BY start_date LIMIT 1');
                $overlap->execute([$yearId, $endDate, $startDate]);
                $overlappingYear = $overlap->fetch();
                if ($overlappingYear && !$allowOverlap) {
                    $error = 'The date range overlaps ' . $overlappingYear['name'] . '. Check Allow overlap to proceed intentionally.';
                } else {
                    try {
                        $isCurrent = $status === 'active' && $startDate <= $today && $endDate >= $today;
                        $pdo->beginTransaction();
                        if ($isCurrent) $pdo->exec('UPDATE academic_years SET is_current = 0');
                        if ($yearId > 0) {
                            $stmt = $pdo->prepare('UPDATE academic_years SET name = ?, start_date = ?, end_date = ?, description = ?, status = ?, is_current = ? WHERE id = ?');
                            $stmt->execute([$name, $startDate, $endDate, $description ?: null, $status, $isCurrent ? 1 : 0, $yearId]);
                            $selectedYearId = $yearId;
                            $message = 'Academic year updated successfully.';
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO academic_years (name, start_date, end_date, description, status, is_current) VALUES (?, ?, ?, ?, ?, ?)');
                            $stmt->execute([$name, $startDate, $endDate, $description ?: null, $status, $isCurrent ? 1 : 0]);
                            $selectedYearId = (int)$pdo->lastInsertId();
                            $message = 'Academic year created successfully.';
                        }
                        $pdo->commit();
                        $editId = 0;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('Academic year save failed: ' . $e->getMessage());
                        $error = 'Could not save the academic year. Please try again.';
                    }
                }
            }
        }
    }
}

$editYear = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT * FROM academic_years WHERE id = ?');
    $editStmt->execute([$editId]);
    $editYear = $editStmt->fetch() ?: null;
}

$where = [];
$params = [];
if ($filterStatus !== '' && in_array($filterStatus, ['active', 'inactive', 'upcoming', 'completed'], true)) {
    if ($filterStatus === 'inactive') {
        $where[] = "ay.status = 'inactive'";
    } elseif ($filterStatus === 'upcoming') {
        $where[] = "ay.status = 'active' AND CURDATE() < ay.start_date";
    } elseif ($filterStatus === 'completed') {
        $where[] = "ay.status = 'active' AND CURDATE() > ay.end_date";
    } else {
        $where[] = "ay.status = 'active' AND CURDATE() BETWEEN ay.start_date AND ay.end_date";
    }
}
if ($search !== '') {
    $where[] = '(ay.name LIKE ? OR ay.description LIKE ?)';
    $wildcard = '%' . $search . '%';
    $params[] = $wildcard;
    $params[] = $wildcard;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$listStmt = $pdo->prepare(" 
    SELECT ay.*,
           CASE WHEN ay.status = 'inactive' THEN 'inactive'
                WHEN CURDATE() < ay.start_date THEN 'upcoming'
                WHEN CURDATE() > ay.end_date THEN 'completed'
                ELSE 'active' END AS display_status,
           COUNT(DISTINCT sem.semester_id) AS semester_count,
           COUNT(DISTINCT sec.id) AS section_count,
           COUNT(DISTINCT sub.id) AS course_count
    FROM academic_years ay
    LEFT JOIN semesters sem ON sem.academic_year_id = ay.id
    LEFT JOIN sections sec ON sec.academic_year_id = ay.id
    LEFT JOIN subjects sub ON sub.academic_year_id = ay.id OR sub.semester_id = sem.semester_id OR sub.section_id = sec.id
    {$whereSql}
    GROUP BY ay.id
    ORDER BY ay.start_date DESC, ay.id DESC
");
$listStmt->execute($params);
$academicYears = $listStmt->fetchAll();

$selectedYear = null;
$selectedSemesters = [];
$selectedStats = null;
if ($selectedYearId > 0) {
    $selectedStmt = $pdo->prepare('SELECT * FROM academic_years WHERE id = ?');
    $selectedStmt->execute([$selectedYearId]);
    $selectedYear = $selectedStmt->fetch() ?: null;
    if ($selectedYear) {
        $semesterStmt = $pdo->prepare(" 
            SELECT sem.*, CASE WHEN sem.status = 'inactive' THEN 'inactive' WHEN CURDATE() < sem.start_date THEN 'upcoming' WHEN CURDATE() > sem.end_date THEN 'completed' ELSE 'active' END AS display_status
            FROM semesters sem WHERE sem.academic_year_id = ? ORDER BY sem.start_date
        ");
        $semesterStmt->execute([$selectedYearId]);
        $selectedSemesters = $semesterStmt->fetchAll();
        $statsStmt = $pdo->prepare(" 
            SELECT COUNT(DISTINCT sec.id) AS section_count,
                   COUNT(DISTINCT sub.id) AS course_count,
                   COUNT(DISTINCT e.student_id) AS student_count,
                   COUNT(DISTINCT a.id) AS assignment_count,
                   COUNT(DISTINCT att.id) AS attendance_count,
                   COUNT(DISTINCT g.grade_id) AS grade_count
            FROM academic_years ay
            LEFT JOIN sections sec ON sec.academic_year_id = ay.id
            LEFT JOIN subjects sub ON sub.academic_year_id = ay.id OR sub.semester_id IN (SELECT semester_id FROM semesters WHERE academic_year_id = ay.id) OR sub.section_id = sec.id
            LEFT JOIN enrollments e ON e.subject_id = sub.id
            LEFT JOIN assignments a ON a.subject_id = sub.id
            LEFT JOIN attendance att ON att.subject_id = sub.id
            LEFT JOIN student_grades g ON g.subject_id = sub.id
            WHERE ay.id = ?
        ");
        $statsStmt->execute([$selectedYearId]);
        $selectedStats = $statsStmt->fetch();
    }
}

$formValues = $editYear ?: [
    'id' => 0,
    'name' => $_POST['name'] ?? '',
    'start_date' => $_POST['start_date'] ?? '',
    'end_date' => $_POST['end_date'] ?? '',
    'description' => $_POST['description'] ?? '',
    'status' => $_POST['status'] ?? 'active'
];
$showForm = $canManage && ($editYear || isset($_GET['add']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_academic_year'])));
$pageTitle = 'Academic Year Management - LearnAlytics';
$extraCss = ['assets/css/academic-year-management.css'];
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4 academic-year-page">
    <div class="academic-year-heading">
        <div>
            <h1 class="h2 mb-1">Academic Year Management</h1>
            <p class="text-muted mb-0"><?php echo $canManage ? 'Create and manage academic year configurations.' : 'View academic years and their associated semesters.'; ?></p>
        </div>
        <?php if ($canManage): ?><a href="?add=1" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Add Academic Year</a><?php endif; ?>
    </div>

    <?php if ($message): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($showForm): ?>
        <section class="academic-year-form-panel mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0"><?php echo $editYear ? 'Edit Academic Year' : 'Add Academic Year'; ?></h2>
                <a class="btn-close" href="academic_year_management.php" aria-label="Close"></a>
            </div>
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['academic_year_management_csrf']); ?>">
                <input type="hidden" name="academic_year_id" value="<?php echo (int)$formValues['id']; ?>">
                <div class="col-md-6"><label class="form-label" for="yearName">Academic year name</label><input id="yearName" name="name" class="form-control" maxlength="255" required value="<?php echo htmlspecialchars($formValues['name']); ?>" placeholder="2027-2028"></div>
                <div class="col-md-3"><label class="form-label" for="yearStart">Start date</label><input id="yearStart" name="start_date" class="form-control" type="date" required value="<?php echo htmlspecialchars($formValues['start_date']); ?>"></div>
                <div class="col-md-3"><label class="form-label" for="yearEnd">End date</label><input id="yearEnd" name="end_date" class="form-control" type="date" required value="<?php echo htmlspecialchars($formValues['end_date']); ?>"></div>
                <div class="col-md-8"><label class="form-label" for="yearDescription">Description <span class="text-muted fw-normal">(optional)</span></label><textarea id="yearDescription" name="description" class="form-control" rows="2" maxlength="5000"><?php echo htmlspecialchars($formValues['description'] ?? ''); ?></textarea></div>
                <div class="col-md-4"><label class="form-label" for="yearStatus">Availability</label><select id="yearStatus" name="status" class="form-select"><option value="active" <?php echo $formValues['status'] === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo $formValues['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
                <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="allow_overlap" value="1"><span class="form-check-label">Allow date overlap with another academic year (only check when intentional)</span></label></div>
                <div class="col-12 d-flex justify-content-end gap-2"><a href="academic_year_management.php" class="btn btn-outline-secondary">Cancel</a><button type="submit" name="save_academic_year" value="1" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Academic Year</button></div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($selectedYear && $selectedStats): ?>
        <section class="academic-year-overview mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div><h2 class="h5 mb-1"><?php echo htmlspecialchars($selectedYear['name']); ?></h2><p class="small text-muted mb-0"><?php echo htmlspecialchars($selectedYear['start_date'] . ' to ' . $selectedYear['end_date']); ?></p></div>
            </div>
            <div class="academic-year-stat-grid">
                <?php foreach (['section_count' => 'Sections', 'course_count' => 'Courses', 'student_count' => 'Enrolled Students', 'assignment_count' => 'Assignments', 'attendance_count' => 'Attendance Records', 'grade_count' => 'Grade Records'] as $key => $label): ?>
                    <div class="academic-year-stat"><strong><?php echo (int)$selectedStats[$key]; ?></strong><span><?php echo $label; ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="table-responsive mt-3"><table class="table table-sm align-middle mb-0"><thead><tr><th>Semester</th><th>Dates</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($selectedSemesters as $semester): ?><tr><td><?php echo htmlspecialchars($semester['semester_name']); ?></td><td><?php echo htmlspecialchars($semester['start_date'] . ' to ' . $semester['end_date']); ?></td><td><?php echo htmlspecialchars(ucfirst($semester['display_status'])); ?></td></tr><?php endforeach; ?>
                <?php if (!$selectedSemesters): ?><tr><td colspan="3" class="text-muted">No semesters have been created for this academic year.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>

    <form method="get" class="academic-year-filters">
        <input type="search" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search academic year">
        <select name="status" class="form-select"><option value="">All statuses</option><?php foreach (['active'=>'Active','upcoming'=>'Upcoming','completed'=>'Completed','inactive'=>'Inactive'] as $value=>$label): ?><option value="<?php echo $value; ?>" <?php echo $filterStatus===$value?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-primary" type="submit"><i class="fas fa-filter me-1"></i>Filter</button>
    </form>

    <section class="academic-year-table-wrap"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Academic Year</th><th>Start Date</th><th>End Date</th><th>Semesters</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($academicYears as $year): ?>
                <tr>
                    <td><a href="?academic_year_id=<?php echo (int)$year['id']; ?>" class="fw-semibold text-decoration-none"><?php echo htmlspecialchars($year['name']); ?></a></td>
                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($year['start_date']))); ?></td><td><?php echo htmlspecialchars(date('M j, Y', strtotime($year['end_date']))); ?></td>
                    <td><?php echo (int)$year['semester_count']; ?></td>
                    <td><span class="academic-year-status status-<?php echo htmlspecialchars($year['display_status']); ?>"><?php echo htmlspecialchars(ucfirst($year['display_status'])); ?></span></td>
                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($year['created_at']))); ?></td>
                    <td><div class="d-flex flex-wrap gap-1"><a href="?academic_year_id=<?php echo (int)$year['id']; ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="fas fa-eye"></i></a><?php if ($canManage): ?><a href="?edit=<?php echo (int)$year['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pen"></i></a><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['academic_year_management_csrf']); ?>"><input type="hidden" name="set_year_status" value="<?php echo (int)$year['id']; ?>"><input type="hidden" name="new_status" value="<?php echo $year['status'] === 'active' ? 'inactive' : 'active'; ?>"><button class="btn btn-sm <?php echo $year['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success'; ?>" type="submit" title="<?php echo $year['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>"><i class="fas <?php echo $year['status'] === 'active' ? 'fa-pause' : 'fa-play'; ?>"></i></button></form><?php endif; ?></div></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$academicYears): ?><tr><td colspan="7" class="text-center text-muted py-5">No academic years match these filters.</td></tr><?php endif; ?>
        </tbody>
    </table></div></section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
