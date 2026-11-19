<?php
session_start();
require_once '../../config/database.php';

// Verify admin access
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get search parameters
$searchTerm = trim($_GET['search'] ?? '');
$program = $_GET['program'] ?? '';
$department = $_GET['department'] ?? '';
$yearLevel = $_GET['year_level'] ?? '';
$status = $_GET['status'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$perPage = (int)($_GET['per_page'] ?? 10);

// Validate inputs
$page = max(1, $page);
$perPage = min(100, max(1, $perPage));

$offset = ($page - 1) * $perPage;

// Build base query
$query = "
    SELECT 
        u.id,
        u.name,
        u.email,
        sp.student_id_number,
        sp.year_level,
        sp.enrollment_status,
        p.name AS program_name,
        d.name AS department_name,
        COUNT(DISTINCT e.subject_id) AS enrolled_subjects,
        COUNT(DISTINCT i.id) AS instructor_count,
        u.created_at
    FROM users u
    LEFT JOIN student_profiles sp ON u.id = sp.student_id
    LEFT JOIN programs p ON sp.program_id = p.id
    LEFT JOIN departments d ON p.department_id = d.id
    LEFT JOIN enrollments e ON u.id = e.student_id
    LEFT JOIN subjects s ON e.subject_id = s.id
    LEFT JOIN (
        SELECT DISTINCT instructor_id FROM subjects 
        WHERE id IN (SELECT subject_id FROM enrollments WHERE student_id = u.id)
    ) i ON 1=1
    WHERE u.role = 'student'
";

$params = [];

// Add search term filter
if (!empty($searchTerm)) {
    $query .= " AND (
        u.name LIKE ? 
        OR u.email LIKE ? 
        OR sp.student_id_number LIKE ?
    )";
    $searchWildcard = '%' . $searchTerm . '%';
    $params[] = $searchWildcard;
    $params[] = $searchWildcard;
    $params[] = $searchWildcard;
}

// Add program filter
if (!empty($program)) {
    $query .= " AND p.id = ?";
    $params[] = (int)$program;
}

// Add department filter
if (!empty($department)) {
    $query .= " AND d.id = ?";
    $params[] = (int)$department;
}

// Add year level filter
if (!empty($yearLevel)) {
    $query .= " AND sp.year_level = ?";
    $params[] = $yearLevel;
}

// Add enrollment status filter
if (!empty($status)) {
    $query .= " AND sp.enrollment_status = ?";
    $params[] = $status;
}

// Add grouping and ordering
$query .= " GROUP BY u.id ORDER BY u.created_at DESC";

// Get total count
$countQuery = str_replace(
    'SELECT 
        u.id,
        u.name,
        u.email,
        sp.student_id_number,
        sp.year_level,
        sp.enrollment_status,
        p.name AS program_name,
        d.name AS department_name,
        COUNT(DISTINCT e.subject_id) AS enrolled_subjects,
        COUNT(DISTINCT i.id) AS instructor_count,
        u.created_at',
    'SELECT COUNT(DISTINCT u.id) as total',
    $query
);
$countQuery = str_replace(' GROUP BY u.id ORDER BY u.created_at DESC', '', $countQuery);

$stmt = $pdo->prepare($countQuery);
$stmt->execute($params);
$totalResult = $stmt->fetch();
$total = $totalResult['total'] ?? 0;
$totalPages = ceil($total / $perPage);

// Get paginated results
$query .= " LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'data' => $students,
    'pagination' => [
        'current_page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages
    ]
]);
