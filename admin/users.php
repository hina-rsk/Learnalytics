<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/student_account.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$current_user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Administrator';
$success_msg = '';
$error_msg = '';

function logUserActivity($pdo, $actorId, $action, $targetId = null, $details = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO user_activity_logs (user_id, action, target_user_id, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([$actorId, $action, $targetId, $details]);
    } catch (Exception $e) {
        // Audit logging is optional when schema has not yet been migrated.
    }
}

// ============================================================
// Handle POST Actions
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Deactivate/Activate User
    if (isset($_POST['toggle_status'])) {
        $target_id = (int)($_POST['user_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? 'active';
        $validStatuses = ['active', 'inactive'];
        
        if ($target_id > 0 && $target_id !== $current_user_id && in_array($new_status, $validStatuses)) {
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $target_id]);
            logUserActivity($pdo, $current_user_id, $new_status === 'active' ? 'activated_user' : 'deactivated_user', $target_id, "status={$new_status}");
            $success_msg = "User status updated to " . ucfirst($new_status) . " successfully.";
        } elseif ($target_id === $current_user_id) {
            $error_msg = "You cannot change your own status.";
        }
    }

    // Reset Password
    if (isset($_POST['reset_password'])) {
        $target_id = (int)($_POST['user_id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';
        
        if ($target_id > 0 && !empty($new_password) && strlen($new_password) >= 8) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $target_id]);
            logUserActivity($pdo, $current_user_id, 'reset_password', $target_id, null);
            $success_msg = "Password reset successfully.";
        } else {
            $error_msg = "Password must be at least 8 characters.";
        }
    }

    // Archive User (Soft delete - set status to archived)
    if (isset($_POST['archive_user'])) {
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id > 0 && $target_id !== $current_user_id) {
            $stmt = $pdo->prepare("UPDATE users SET status = 'archived' WHERE id = ?");
            $stmt->execute([$target_id]);
            logUserActivity($pdo, $current_user_id, 'archived_user', $target_id, 'status=archived');
            $success_msg = "User archived successfully.";
        } elseif ($target_id === $current_user_id) {
            $error_msg = "You cannot archive your own account.";
        }
    }

    // Create New User
    if (isset($_POST['create_user'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'student';
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
        $new_status = $_POST['new_user_status'] ?? 'active';
        $user_id_number = trim($_POST['user_id_number'] ?? '');
        $validRoles = ['student', 'instructor', 'dean', 'admin'];

        if (!in_array($role, $validRoles, true)) {
            $error_msg = "Please provide valid user details. Password must be at least 8 characters.";
        } elseif ($role === 'student') {
            try {
                $student = createStudentAccount($pdo, $_POST, $new_status);
                logUserActivity($pdo, $current_user_id, 'created_user', $student['user_id'], 'role=student');
                $success_msg = "User created successfully.";
            } catch (Throwable $e) {
                $error_msg = $e->getMessage();
            }
        } elseif (!in_array($new_status, ['active', 'inactive'], true) || empty($name) || empty($email) || empty($password) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $error_msg = "Please provide valid user details. Password must be at least 8 characters.";
        } elseif ($role === 'dean' && empty($department_id)) {
            $error_msg = "A Dean must be assigned to a department.";
        } else {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                $error_msg = "Email already in use by another user.";
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, department_id, student_id_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([$name, $email, $hashed, $role, $department_id, $user_id_number ?: null, $new_status]);
                $createdId = $pdo->lastInsertId();
                logUserActivity($pdo, $current_user_id, 'created_user', $createdId, "role={$role}");
                $success_msg = "User created successfully.";
            }
        }
    }

    // Delete User (Hard delete)
    if (isset($_POST['delete_user'])) {
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id > 0 && $target_id !== $current_user_id) {
            try {
                // Delete related records first
                $pdo->prepare("DELETE FROM enrollments WHERE student_id = ?")->execute([$target_id]);
                $pdo->prepare("DELETE FROM submissions WHERE student_id = ?")->execute([$target_id]);
                $pdo->prepare("DELETE FROM quiz_results WHERE student_id = ?")->execute([$target_id]);
                $pdo->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?")->execute([$target_id, $target_id]);
                $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$target_id]);
                $pdo->prepare("DELETE FROM student_profiles WHERE student_id = ?")->execute([$target_id]);
                
                // Delete subjects taught by this user
                $subjects = $pdo->prepare("SELECT id FROM subjects WHERE instructor_id = ?");
                $subjects->execute([$target_id]);
                foreach ($subjects->fetchAll() as $subject) {
                    $pdo->prepare("DELETE FROM lessons WHERE subject_id = ?")->execute([$subject['id']]);
                    $pdo->prepare("DELETE FROM assignments WHERE subject_id = ?")->execute([$subject['id']]);
                    $pdo->prepare("DELETE FROM quizzes WHERE subject_id = ?")->execute([$subject['id']]);
                    $pdo->prepare("DELETE FROM enrollments WHERE subject_id = ?")->execute([$subject['id']]);
                    $pdo->prepare("DELETE FROM subjects WHERE id = ?")->execute([$subject['id']]);
                }
                
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$target_id]);
                logUserActivity($pdo, $current_user_id, 'deleted_user', $target_id, null);
                $success_msg = "User deleted successfully.";
            } catch (Exception $e) {
                $error_msg = "Error deleting user: " . $e->getMessage();
            }
        } elseif ($target_id === $current_user_id) {
            $error_msg = "You cannot delete your own account.";
        }
    }

    // Update User Info
    if (isset($_POST['update_user'])) {
        $target_id = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'student';
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $user_id_number = trim($_POST['user_id_number'] ?? '');
        $validRoles = ['student', 'instructor', 'dean', 'admin'];
        
        if ($target_id > 0 && !empty($name) && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) && in_array($role, $validRoles)) {
            if ($role === 'dean' && empty($department_id)) {
                $error_msg = "A Dean must be assigned to a department.";
            } else {
                // Check email uniqueness
                $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $check->execute([$email, $target_id]);
                if ($check->fetch()) {
                    $error_msg = "Email already in use by another user.";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, department_id = ?, student_id_number = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $role, $department_id, $user_id_number ?: null, $target_id]);
                    logUserActivity($pdo, $current_user_id, 'updated_user', $target_id, "role={$role}");
                    $success_msg = "User information updated successfully.";
                }
            }
        } else {
            $error_msg = "Please provide valid information.";
        }
    }
}

// ============================================================
// Dashboard Statistics
// ============================================================

$stats = [];
$stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$stats['active_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active' OR status IS NULL")->fetchColumn();
$stats['inactive_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'inactive'")->fetchColumn();
$stats['total_students'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$stats['total_instructors'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'instructor'")->fetchColumn();
$stats['total_admins'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$stats['total_deans'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'dean'")->fetchColumn();
$stats['pending_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
$stats['suspended_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'")->fetchColumn();
try {
    $stats['total_activity_logs'] = $pdo->query("SELECT COUNT(*) FROM user_activity_logs")->fetchColumn();
} catch (Exception $e) {
    $stats['total_activity_logs'] = 0;
}

// Fetch departments for modals
$departments = $pdo->query("SELECT id, name FROM departments WHERE status = 'active' OR status IS NULL ORDER BY name")->fetchAll();

// ============================================================
// Search and Filter Parameters
// ============================================================

$searchTerm = trim($_GET['search'] ?? '');
$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortOrder = $_GET['order'] ?? 'DESC';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Valid sort columns
$validSortColumns = ['name', 'email', 'role', 'status', 'created_at'];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'created_at';
}
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// ============================================================
// Build Query
// ============================================================

$whereClauses = [];
$params = [];

if (!empty($searchTerm)) {
    $whereClauses[] = "(u.name LIKE ? OR u.email LIKE ? OR sp.student_id_number LIKE ? OR d.name LIKE ? OR p.name LIKE ?)";
    $wildcard = "%{$searchTerm}%";
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
}

if (!empty($filterRole)) {
    $whereClauses[] = "u.role = ?";
    $params[] = $filterRole;
}

if (!empty($filterStatus)) {
    $whereClauses[] = "(u.status = ? OR (u.status IS NULL AND ? = 'active'))";
    $params[] = $filterStatus;
    $params[] = $filterStatus;
}

if (!empty($filterDateFrom)) {
    $whereClauses[] = "DATE(u.created_at) >= ?";
    $params[] = $filterDateFrom;
}

if (!empty($filterDateTo)) {
    $whereClauses[] = "DATE(u.created_at) <= ?";
    $params[] = $filterDateTo;
}

$whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Main query with joins for student profile data and direct department for deans
$query = "
    SELECT 
        u.id, u.name, u.email, u.role, 
        COALESCE(u.status, 'active') as status,
        u.created_at, u.updated_at,
        u.student_id_number,
        sp.year_level,
        COALESCE(ud.name, sp_d.name) as department_name,
        sp_p.name as program_name
    FROM users u
    LEFT JOIN student_profiles sp ON sp.student_id = u.id
    LEFT JOIN programs sp_p ON sp_p.id = sp.program_id
    LEFT JOIN departments sp_d ON sp_d.id = sp.department_id
    LEFT JOIN departments ud ON ud.id = u.department_id
    {$whereSQL}
    ORDER BY u.{$sortBy} {$sortOrder}
    LIMIT ? OFFSET ?
";

// Count query
$countQuery = "
    SELECT COUNT(*) 
    FROM users u
    LEFT JOIN student_profiles sp ON sp.student_id = u.id
    LEFT JOIN programs sp_p ON sp_p.id = sp.program_id
    LEFT JOIN departments sp_d ON sp_d.id = sp.department_id
    LEFT JOIN departments ud ON ud.id = u.department_id
    {$whereSQL}
";

try {
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $perPage);

    $stmt = $pdo->prepare($query);
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $users = $stmt->fetchAll();
} catch (Exception $e) {
    $error_msg = "Database error: " . $e->getMessage();
    $users = [];
    $totalRecords = 0;
    $totalPages = 0;
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
        width: 52px;
        height: 52px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
    }

    .stat-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--primary-blue);
    }

    .stat-label {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
    }

    /* Search & Filter */
    .filter-section {
        background: white;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        margin-bottom: 1.5rem;
    }

    .search-input {
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem 1rem;
        transition: all 0.2s ease;
    }

    .search-input:focus {
        border-color: var(--secondary-blue);
        box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        outline: none;
    }

    /* Table */
    .user-table-container {
        background: white;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }

    .user-table {
        margin-bottom: 0;
    }

    .user-table thead {
        background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
        color: white;
    }

    .user-table th {
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 1rem;
        border: none;
        cursor: pointer;
        user-select: none;
    }

    .user-table th:hover {
        background: rgba(255,255,255,0.1);
    }

    .user-table th .sort-icon {
        margin-left: 0.25rem;
        font-size: 0.65rem;
    }

    .user-table td {
        padding: 0.875rem 1rem;
        vertical-align: middle;
        font-size: 0.875rem;
        border-bottom: 1px solid #f1f5f9;
    }

    .user-table tbody tr:hover {
        background: #f8fafc;
    }

    .user-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* User Avatar */
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.85rem;
        margin-right: 0.75rem;
        flex-shrink: 0;
    }

    .user-info {
        display: flex;
        align-items: center;
    }

    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.3rem 0.7rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-active { background: #dcfce7; color: #166534; }
    .status-inactive { background: #f1f5f9; color: #475569; }
    .status-pending { background: #fef3c7; color: #78350f; }
    .status-suspended { background: #fee2e2; color: #991b1b; }
    .status-archived { background: #e2e8f0; color: #475569; }

    /* Role Badges */
    .role-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.3rem 0.7rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .role-student { background: #dbeafe; color: #1e40af; }
    .role-instructor { background: #fef3c7; color: #78350f; }
    .role-admin { background: #e0e7ff; color: #4338ca; }
    .role-dean { background: #f3e8ff; color: #6b21a8; }

    /* Dean field requirement indicator */
    #deanDeptNote, #editDeanDeptNote {
        font-size: 0.78rem;
        color: #6b21a8;
        display: none;
        margin-top: 0.25rem;
    }

    /* Action Buttons */
    .action-btn {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        border: none;
        cursor: pointer;
        font-size: 0.8rem;
    }

    .action-btn:hover {
        transform: scale(1.1);
    }

    .btn-view { background: #dbeafe; color: #2563eb; }
    .btn-edit { background: #fef3c7; color: #d97706; }
    .btn-toggle { background: #dcfce7; color: #059669; }
    .btn-reset { background: #e0e7ff; color: #4f46e5; }
    .btn-archive { background: #f1f5f9; color: #64748b; }
    .btn-delete { background: #fee2e2; color: #dc2626; }

    /* Pagination */
    .pagination .page-link {
        border: none;
        color: var(--secondary-blue);
        border-radius: 8px;
        margin: 0 2px;
        padding: 0.5rem 0.875rem;
    }

    .pagination .page-link:hover {
        background: #dbeafe;
    }

    .pagination .page-item.active .page-link {
        background: var(--secondary-blue);
        color: white;
    }

    /* Modal */
    .modal-content {
        border-radius: 14px;
        border: none;
    }

    .modal-header {
        border-radius: 14px 14px 0 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .stat-value { font-size: 1.4rem; }
        .stat-icon { width: 44px; height: 44px; font-size: 1.1rem; }
        .user-table { font-size: 0.8rem; }
        .action-btn { width: 28px; height: 28px; }
    }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="background: #f1f5f9; min-height: 100vh;">
    <!-- Page Header -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold" style="color: var(--primary-blue);">
                <i class="fas fa-users-cog me-2" style="color: var(--secondary-blue);"></i>User Management
            </h1>
            <p class="text-muted mb-0">Manage all system users, roles, and permissions.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="user_audit.php" class="btn btn-outline-info">
                <i class="fas fa-history me-1"></i> Audit Logs
            </a>
            <button class="btn btn-outline-secondary" onclick="location.reload()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
            <button class="btn" style="background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%); color: var(--primary-blue); font-weight: 600;" data-bs-toggle="modal" data-bs-target="#createUserModal">
                <i class="fas fa-user-plus me-1"></i> Add User
            </button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert" style="border-left: 4px solid var(--success); border-radius: 10px;">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert" style="border-left: 4px solid var(--danger); border-radius: 10px;">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>


    <!-- Search & Filter Section -->
    <div class="filter-section">
        <h5 class="fw-bold mb-3" style="color: var(--primary-blue);">
            <i class="fas fa-search me-2"></i>Search & Filter Users
        </h5>
        <form method="GET" class="row g-3">
            <div class="col-lg-4 col-md-6">
                <label class="form-label small text-muted">Search</label>
                <input type="text" name="search" class="form-control search-input" 
                       placeholder="Name, Email, ID, Program, Department..." 
                       value="<?php echo htmlspecialchars($searchTerm); ?>">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted">Role</label>
                <select name="role" class="form-select search-input">
                    <option value="">All Roles</option>
                    <option value="student" <?php echo $filterRole === 'student' ? 'selected' : ''; ?>>Students</option>
                    <option value="instructor" <?php echo $filterRole === 'instructor' ? 'selected' : ''; ?>>Instructors</option>
                    <option value="dean" <?php echo $filterRole === 'dean' ? 'selected' : ''; ?>>Deans</option>
                    <option value="admin" <?php echo $filterRole === 'admin' ? 'selected' : ''; ?>>Admins</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--secondary-blue) 0%, var(--light-blue) 100%); color: white; font-weight: 600;">
                    <i class="fas fa-search me-1"></i>   Search
                </button>
                <a href="users.php" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Clear Filters
                </a>
                <span class="text-muted ms-auto align-self-center">
                    Showing <?php echo count($users); ?> of <?php echo number_format($totalRecords); ?> users
                </span>
            </div>
        </form>
    </div>

    <!-- Users Table -->
    <div class="user-table-container">
        <div class="table-responsive">
            <table class="user-table w-100">
                <thead>
                    <tr>
                        <th data-sort="name">
                            User <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th data-sort="email">
                            Email <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th data-sort="role">
                            Role <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th data-sort="status">
                            Status <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th>Program / Dept</th>
                        <th data-sort="created_at">
                            Joined <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <?php
                                        $avatarBg = match($user['role']) {
                                            'student' => '#dbeafe',
                                            'instructor' => '#fef3c7',
                                            'dean' => '#f3e8ff',
                                            default => '#e0e7ff'
                                        };
                                        $avatarColor = match($user['role']) {
                                            'student' => '#2563eb',
                                            'instructor' => '#d97706',
                                            'dean' => '#6b21a8',
                                            default => '#4f46e5'
                                        };
                                    ?>
                                    <div class="user-avatar" style="background: <?php echo $avatarBg; ?>; color: <?php echo $avatarColor; ?>;">
                                        <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                                    </div>
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($user['name']); ?></div>
                                        <?php if ($user['student_id_number']): ?>
                                            <small class="text-muted">ID: <?php echo htmlspecialchars($user['student_id_number']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="text-muted"><?php echo htmlspecialchars($user['email']); ?></td>
                            <td>
                                <span class="role-badge role-<?php echo $user['role']; ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $user['status']; ?>">
                                    <?php echo ucfirst($user['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($user['program_name']): ?>
                                    <div class="small"><?php echo htmlspecialchars($user['program_name']); ?></div>
                                <?php endif; ?>
                                <?php if ($user['department_name']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($user['department_name']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button class="action-btn btn-view" title="View Profile" onclick="viewUser(<?php echo $user['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="action-btn btn-edit" title="Edit User" onclick="editUser(<?php echo $user['id']; ?>, '<?php echo addslashes($user['name']); ?>', '<?php echo addslashes($user['email']); ?>', '<?php echo $user['role']; ?>', <?php echo (int)($user['department_id'] ?? 0); ?>, '<?php echo addslashes($user['student_id_number'] ?? ''); ?>')">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button class="action-btn btn-toggle" title="<?php echo $user['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>" onclick="toggleStatus(<?php echo $user['id']; ?>, '<?php echo $user['status']; ?>')">
                                        <i class="fas fa-<?php echo $user['status'] === 'active' ? 'pause' : 'play'; ?>"></i>
                                    </button>
                                    <button class="action-btn btn-reset" title="Reset Password" onclick="resetPassword(<?php echo $user['id']; ?>)">
                                        <i class="fas fa-key"></i>
                                    </button>
                                    <button class="action-btn btn-archive" title="Archive" onclick="archiveUser(<?php echo $user['id']; ?>)">
                                        <i class="fas fa-archive"></i>
                                    </button>
                                    <button class="action-btn btn-delete" title="Delete" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo addslashes($user['name']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</main>

<!-- Create User Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" id="createUserForm">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6" id="createNameGroup">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="create_user_name" class="form-control" placeholder="e.g. Juan Dela Cruz" required>
                        </div>
                        <div class="col-md-6" id="createIdGroup">
                            <label class="form-label fw-semibold">User ID / ID Number</label>
                            <input type="text" name="user_id_number" id="create_user_id_number" class="form-control" placeholder="e.g. DEAN-2026-001">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" placeholder="user@institution.edu" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" id="create_password" class="form-control" minlength="8" placeholder="Min. 8 characters" required>
                        </div>
                        <div class="col-md-6" id="createConfirmPasswordGroup" hidden>
                            <label class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" id="create_confirm_password" class="form-control" minlength="8" autocomplete="new-password">
                        </div>
                        <div class="col-md-6" id="createStudentIdGroup" hidden>
                            <label class="form-label fw-semibold">Student ID <span class="text-danger">*</span></label>
                            <input type="text" name="student_id" id="create_student_id" class="form-control" maxlength="50" autocomplete="off">
                        </div>
                        <div class="col-md-6" id="createFirstNameGroup" hidden>
                            <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" id="create_first_name" class="form-control" maxlength="100">
                        </div>
                        <div class="col-md-6" id="createMiddleInitialGroup" hidden>
                            <label class="form-label fw-semibold">Middle Initial</label>
                            <input type="text" name="middle_initial" id="create_middle_initial" class="form-control" maxlength="5">
                        </div>
                        <div class="col-md-6" id="createLastNameGroup" hidden>
                            <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" id="create_last_name" class="form-control" maxlength="100">
                        </div>
                        <div class="col-md-6" id="createDobGroup" hidden>
                            <label class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label>
                            <input type="date" name="date_of_birth" id="create_date_of_birth" class="form-control">
                        </div>
                        <div class="col-md-6" id="createGenderGroup" hidden>
                            <label class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
                            <select name="gender" id="create_gender" class="form-select">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                                <option value="Prefer not to say">Prefer not to say</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="createContactGroup" hidden>
                            <label class="form-label fw-semibold">Contact Number <span class="text-danger">*</span></label>
                            <input type="tel" name="contact_number" id="create_contact_number" class="form-control" maxlength="20">
                        </div>
                        <div class="col-md-6" id="createYearGroup" hidden>
                            <label class="form-label fw-semibold">Year Level <span class="text-danger">*</span></label>
                            <select name="year_level" id="create_year_level" class="form-select">
                                <option value="">Select Year Level</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                            <select name="role" id="create_role" class="form-select" required>
                                <option value="student">Student</option>
                                <option value="instructor">Instructor</option>
                                <option value="dean">Dean</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="new_user_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="createDepartmentGroup">
                            <label class="form-label fw-semibold" id="createDeptLabel">Department</label>
                            <select name="department_id" id="create_department_id" class="form-select">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="deanDeptNote"><i class="fas fa-info-circle me-1"></i>Required for Dean role.</div>
                        </div>
                        <div class="col-md-6" id="createProgramGroup" hidden>
                            <label class="form-label fw-semibold">Program</label>
                            <select name="program_id" id="create_program_id" class="form-select" disabled>
                                <option value="">-- Select Department First --</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_user" class="btn" style="background: var(--secondary-blue); color: white;"><i class="fas fa-user-plus me-1"></i>Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-pen me-2"></i>Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_user_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">User ID / ID Number</label>
                            <input type="text" name="user_id_number" id="edit_user_id_number" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="edit_user_email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                            <select name="role" id="edit_user_role" class="form-select" required>
                                <option value="student">Student</option>
                                <option value="instructor">Instructor</option>
                                <option value="dean">Dean</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" id="editDeptLabel">Department</label>
                            <select name="department_id" id="edit_department_id" class="form-select">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="editDeanDeptNote"><i class="fas fa-info-circle me-1"></i>Required for Dean role.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Program</label>
                            <select name="program_id" id="edit_program_id" class="form-select" disabled>
                                <option value="">-- Select Department First --</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_user" class="btn" style="background: var(--warning); color: white;"><i class="fas fa-save me-1"></i>Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-key me-2"></i>Reset Password</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="reset_user_id">
                    <p class="text-muted mb-3">Enter a new password for this user.</p>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" minlength="8" required>
                        <small class="text-muted">Minimum 8 characters</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reset_password" class="btn" style="background: #4f46e5; color: white;">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Status Modal -->
<div class="modal fade" id="toggleStatusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--success) 0%, #34d399 100%);">
                    <h5 class="modal-title text-white" id="toggleStatusTitle"><i class="fas fa-toggle-on me-2"></i>Change Status</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="toggle_user_id">
                    <input type="hidden" name="new_status" id="toggle_new_status">
                    <p id="toggleStatusMessage" class="mb-0"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="toggle_status" class="btn" style="background: var(--success); color: white;">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Archive User Modal -->
<div class="modal fade" id="archiveUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, #64748b 0%, #94a3b8 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-archive me-2"></i>Archive User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="archive_user_id">
                    <p class="mb-0">Are you sure you want to archive this user? They will no longer be able to access the system.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="archive_user" class="btn" style="background: #64748b; color: white;">Archive User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete User Modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--danger) 0%, #f87171 100%);">
                    <h5 class="modal-title text-white"><i class="fas fa-exclamation-triangle me-2"></i>Delete User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="delete_user_id">
                    <p class="mb-2"><strong>This action cannot be undone!</strong></p>
                    <p class="text-muted mb-0">All data associated with this user will be permanently deleted.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_user" class="btn btn-danger">Delete Permanently</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Sorting functionality
    document.querySelectorAll('.user-table th[data-sort]').forEach(th => {
        th.addEventListener('click', () => {
            const sort = th.getAttribute('data-sort');
            const urlParams = new URLSearchParams(window.location.search);
            const currentOrder = urlParams.get('order') || 'DESC';
            const newOrder = currentOrder === 'DESC' ? 'ASC' : 'DESC';
            urlParams.set('sort', sort);
            urlParams.set('order', newOrder);
            window.location.href = '?' + urlParams.toString();
        });
    });

    // View User
    function viewUser(id) {
        window.location.href = 'user_performance.php?user_id=' + id;
    }

    // Edit User
    function editUser(id, name, email, role, departmentId, userIdNumber) {
        document.getElementById('edit_user_id').value = id;
        document.getElementById('edit_user_name').value = name;
        document.getElementById('edit_user_email').value = email;
        document.getElementById('edit_user_id_number').value = userIdNumber || '';
        document.getElementById('edit_user_role').value = role;
        // Set department
        const editDeptSel = document.getElementById('edit_department_id');
        editDeptSel.value = departmentId || '';
        // Trigger dean note & program load
        handleRoleChange('edit_user_role', 'editDeptLabel', 'editDeanDeptNote', 'edit_department_id', 'edit_program_id');
        if (departmentId) loadPrograms(departmentId, 'edit_program_id', 0);
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    }

    // Handle role change to enforce Dean requirements
    function handleRoleChange(roleId, labelId, noteId, deptId, progId) {
        const role = document.getElementById(roleId).value;
        const label = document.getElementById(labelId);
        const note = document.getElementById(noteId);
        const deptSel = document.getElementById(deptId);
        if (role === 'dean') {
            label.innerHTML = 'Department <span class="text-danger">*</span>';
            note.style.display = 'block';
            deptSel.setAttribute('required', 'required');
        } else {
            label.innerHTML = 'Department';
            note.style.display = 'none';
            deptSel.removeAttribute('required');
        }
    }

    function syncCreateRoleFields() {
        const role = document.getElementById('create_role').value;
        const isStudent = role === 'student';
        const isDean = role === 'dean';
        document.getElementById('createNameGroup').hidden = isStudent;
        document.getElementById('createIdGroup').hidden = isStudent;
        document.getElementById('create_user_name').required = !isStudent;
        document.getElementById('createConfirmPasswordGroup').hidden = !isStudent;
        document.getElementById('create_confirm_password').required = isStudent;

        const studentFields = [
            'createStudentIdGroup', 'createFirstNameGroup', 'createMiddleInitialGroup',
            'createLastNameGroup', 'createDobGroup', 'createGenderGroup',
            'createContactGroup', 'createYearGroup'
        ];
        studentFields.forEach(groupId => {
            const group = document.getElementById(groupId);
            group.hidden = !isStudent;
            const input = group.querySelector('input, select');
            input.disabled = !isStudent;
            input.required = isStudent && groupId !== 'createMiddleInitialGroup';
        });

        document.getElementById('createDepartmentGroup').hidden = !isStudent && !isDean;
        document.getElementById('create_department_id').required = isStudent || isDean;
        document.getElementById('create_department_id').disabled = !isStudent && !isDean;
        document.getElementById('createProgramGroup').hidden = !isStudent;
        document.getElementById('create_program_id').required = isStudent;
        document.getElementById('create_program_id').disabled = !isStudent;
        document.getElementById('create_user_id_number').required = false;
        if (!isStudent && document.getElementById('create_user_id_number').value === document.getElementById('create_student_id').value) {
            document.getElementById('create_user_id_number').value = '';
        }
        handleRoleChange('create_role', 'createDeptLabel', 'deanDeptNote', 'create_department_id', 'create_program_id');
        document.getElementById('create_department_id').required = isStudent || isDean;
        document.getElementById('createProgramGroup').hidden = !isStudent;
    }

    document.getElementById('create_student_id').addEventListener('input', function() {
        document.getElementById('create_user_id_number').value = this.value.trim();
    });
    document.getElementById('createUserForm').addEventListener('submit', function(event) {
        if (document.getElementById('create_role').value !== 'student') return;
        const fullName = [
            document.getElementById('create_first_name').value.trim(),
            document.getElementById('create_middle_initial').value.trim(),
            document.getElementById('create_last_name').value.trim()
        ].filter(Boolean).join(' ');
        document.getElementById('create_user_name').value = fullName;
        document.getElementById('create_user_id_number').value = document.getElementById('create_student_id').value.trim();
        const password = document.getElementById('create_password').value;
        const confirmPassword = document.getElementById('create_confirm_password').value;
        document.getElementById('create_password').setCustomValidity('');
        if (password !== confirmPassword) {
            event.preventDefault();
            document.getElementById('create_confirm_password').setCustomValidity('Passwords do not match.');
            document.getElementById('create_confirm_password').reportValidity();
            return;
        }
        document.getElementById('create_confirm_password').setCustomValidity('');
    });

    // Load programs for a given department
    function loadPrograms(departmentId, progSelectId, selectedProgramId) {
        const progSel = document.getElementById(progSelectId);
        if (!departmentId) {
            progSel.innerHTML = '<option value="">-- Select Department First --</option>';
            progSel.disabled = true;
            return;
        }
        progSel.disabled = true;
        progSel.innerHTML = '<option value="">Loading...</option>';
        fetch('../programs/get_programs.php?department_id=' + departmentId)
            .then(r => r.json())
            .then(data => {
                const programs = Array.isArray(data) ? data : (data.programs ?? []);
                progSel.innerHTML = '<option value="">-- Select Program --</option>';
                if (programs.length > 0) {
                    programs.forEach(p => {
                        const opt = document.createElement('option');
                        opt.value = p.id;
                        opt.textContent = p.name;
                        if (selectedProgramId && p.id == selectedProgramId) opt.selected = true;
                        progSel.appendChild(opt);
                    });
                    progSel.disabled = false;
                } else {
                    progSel.innerHTML = '<option value="">-- No Programs Found --</option>';
                }
            })
            .catch(() => {
                progSel.innerHTML = '<option value="">-- Error Loading Programs --</option>';
            });
    }

    // Wire up Create User modal
    document.getElementById('create_role').addEventListener('change', function() {
        syncCreateRoleFields();
    });
    document.getElementById('create_department_id').addEventListener('change', function() {
        loadPrograms(this.value, 'create_program_id', 0);
    });
    syncCreateRoleFields();

    // Wire up Edit User modal
    document.getElementById('edit_user_role').addEventListener('change', function() {
        handleRoleChange('edit_user_role', 'editDeptLabel', 'editDeanDeptNote', 'edit_department_id', 'edit_program_id');
    });
    document.getElementById('edit_department_id').addEventListener('change', function() {
        loadPrograms(this.value, 'edit_program_id', 0);
    });

    // Reset Password
    function resetPassword(id) {
        document.getElementById('reset_user_id').value = id;
        new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
    }

    // Toggle Status
    function toggleStatus(id, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        document.getElementById('toggle_user_id').value = id;
        document.getElementById('toggle_new_status').value = newStatus;
        document.getElementById('toggleStatusTitle').innerHTML = 
            '<i class="fas fa-toggle-on me-2"></i>' + (newStatus === 'active' ? 'Activate' : 'Deactivate') + ' User';
        document.getElementById('toggleStatusMessage').textContent = 
            'Are you sure you want to ' + (newStatus === 'active' ? 'activate' : 'deactivate') + ' this user?';
        new bootstrap.Modal(document.getElementById('toggleStatusModal')).show();
    }

    // Archive User
    function archiveUser(id) {
        document.getElementById('archive_user_id').value = id;
        new bootstrap.Modal(document.getElementById('archiveUserModal')).show();
    }

    // Delete User
    function deleteUser(id, name) {
        document.getElementById('delete_user_id').value = id;
        const modal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
        modal.show();
    }
</script>

<?php include '../includes/footer.php'; ?>