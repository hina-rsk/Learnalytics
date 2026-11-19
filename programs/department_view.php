<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

// Only admin/dean allowed
$userRole = $_SESSION['user_role'] ?? null;
if ($userRole !== 'admin') {
    echo "<div class='container'><div class='alert alert-danger'>Access denied.</div></div>";
    exit;
}

$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll() ?: [];
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

$programs = [];
$subjects = [];
$subjectCounts = [];
$studentsGrouped = [];
$subjectTitle = '';
$schemaWarning = '';
$departmentName = '';
$departmentStudents = [];

$hasProgramDept = (bool)$pdo->query("SHOW COLUMNS FROM programs LIKE 'department_id'")->fetch();

if ($department_id) {
    $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
    $stmt->execute([$department_id]);
    $departmentName = $stmt->fetchColumn() ?: '';

    if ($hasProgramDept) {
        $stmt = $pdo->prepare("SELECT * FROM programs WHERE department_id = ? ORDER BY name");
        $stmt->execute([$department_id]);
        $programs = $stmt->fetchAll() ?: [];
    } else {
        $schemaWarning = 'Department-course mapping is not available in your database schema. Showing all programs instead.';
        $programs = $pdo->query("SELECT * FROM programs ORDER BY name")->fetchAll() ?: [];
    }

    $stmt = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.email, sp.student_id_number
            FROM enrollments e
            JOIN users u ON u.id = e.student_id
            LEFT JOIN student_profiles sp ON sp.student_id = u.id
            JOIN subjects s ON s.id = e.subject_id
            WHERE s.department_id = ?
            ORDER BY u.name");
    $stmt->execute([$department_id]);
    $departmentStudents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($program_id) {
    $stmt = $pdo->prepare("SELECT s.id, s.title, COUNT(e.id) AS enrolled_count FROM subjects s LEFT JOIN enrollments e ON s.id = e.subject_id WHERE s.program_id = ? GROUP BY s.id ORDER BY s.title");
    $stmt->execute([$program_id]);
    $subjectCounts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $subjectIds = array_column($subjectCounts, 'id');
    if (!empty($subjectIds)) {
        $stmt = $pdo->prepare("SELECT id, title FROM subjects WHERE id IN (" . implode(',', array_fill(0, count($subjectIds), '?')) . ") ORDER BY title");
        $stmt->execute($subjectIds);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if ($subject_id) {
    $stmt = $pdo->prepare("SELECT title FROM subjects WHERE id = ?");
    $stmt->execute([$subject_id]);
    $subjectTitle = $stmt->fetchColumn() ?: '';
    // Fetch enrolled students and their section/year info grouped
    $sql = "SELECT u.id as student_id, u.name as student_name, sec.id as section_id, sec.name as section_name, ay.id as year_id, ay.name as year_name
            FROM enrollments e
            JOIN users u ON e.student_id = u.id
            JOIN subjects s ON e.subject_id = s.id
            LEFT JOIN sections sec ON s.section_id = sec.id
            LEFT JOIN academic_years ay ON sec.academic_year_id = ay.id
            WHERE s.id = ?
            ORDER BY ay.start_date DESC, sec.name, u.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$subject_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Group by year_name -> section_name
    foreach ($rows as $r) {
        $year = $r['year_name'] ?? 'Unknown Year';
        $section = $r['section_name'] ?? 'Unassigned Section';
        $studentsGrouped[$year][$section][] = ['id' => $r['student_id'], 'name' => $r['student_name']];
    }
}
?>
<div class="container mt-4">
    <h1>Department / Programs / Subjects — Dean View</h1>
    <div class="row">
        <div class="col-md-4">
            <div class="card p-3 mb-3">
                <h5>Select Department</h5>
                <ul class="list-group">
                    <?php foreach ($departments as $d): ?>
                    <li class="list-group-item <?php if ($department_id === (int)$d['id']) echo 'active'; ?>">
                        <a href="?department_id=<?= (int)$d['id'] ?>" style="text-decoration:none; color:inherit;"><?= htmlspecialchars($d['name']) ?></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($department_id): ?>
            <div class="card p-3">
                <h5>Programs</h5>
                <ul class="list-group">
                    <?php if (empty($programs)): ?>
                    <li class="list-group-item">No programs for this department.</li>
                    <?php endif; ?>
                    <?php foreach ($programs as $p): ?>
                    <li class="list-group-item <?php if ($program_id === (int)$p['id']) echo 'active'; ?>">
                        <a href="?department_id=<?= $department_id ?>&program_id=<?= (int)$p['id'] ?>" style="text-decoration:none; color:inherit;"><?= htmlspecialchars($p['name']) ?></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-8">
            <?php if ($schemaWarning): ?>
                <div class="alert alert-warning"><?= htmlspecialchars($schemaWarning) ?></div>
            <?php endif; ?>
            <?php if ($department_id): ?>
            <div class="card p-3 mb-3">
                <h5>Students Enrolled in <?= htmlspecialchars($departmentName ?: 'Department') ?></h5>
                <?php if (empty($departmentStudents)): ?>
                    <div class="alert alert-info">No students enrolled in this department.</div>
                <?php else: ?>
                    <ul class="list-group">
                        <?php foreach ($departmentStudents as $stu): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><?= htmlspecialchars($stu['name']) ?></span>
                                <?php if (!empty($stu['student_id_number'])): ?>
                                    <span class="badge bg-secondary rounded-pill"><?= htmlspecialchars($stu['student_id_number']) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($program_id): ?>
            <div class="card p-3 mb-3">
                <h5>Subjects in Program</h5>
                <ul class="list-group">
                    <?php if (empty($subjectCounts)): ?>
                    <li class="list-group-item">No subjects found.</li>
                    <?php endif; ?>
                    <?php foreach ($subjectCounts as $s): ?>
                    <li class="list-group-item <?php if ($subject_id === (int)$s['id']) echo 'active'; ?> d-flex justify-content-between align-items-center">
                        <a href="?department_id=<?= $department_id ?>&program_id=<?= $program_id ?>&subject_id=<?= (int)$s['id'] ?>" style="text-decoration:none; color:inherit;"><?= htmlspecialchars($s['title']) ?></a>
                        <span class="badge bg-secondary rounded-pill"><?= (int)$s['enrolled_count'] ?> enrolled</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($subject_id): ?>
            <div class="card p-3">
                <h5>Enrolled Students for <?= htmlspecialchars($subjectTitle ?: 'Selected Subject') ?></h5>
                <?php if (empty($studentsGrouped)): ?>
                    <div class="alert alert-info">No students enrolled in this subject.</div>
                <?php else: ?>
                    <?php foreach ($studentsGrouped as $yearName => $sections): ?>
                        <h6><?= htmlspecialchars($yearName) ?></h6>
                        <?php foreach ($sections as $sectionName => $students): ?>
                            <strong><?= htmlspecialchars($sectionName) ?></strong>
                            <ul>
                                <?php foreach ($students as $stu): ?>
                                    <li><?= htmlspecialchars($stu['name']) ?> (ID: <?= (int)$stu['id'] ?>)</li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
