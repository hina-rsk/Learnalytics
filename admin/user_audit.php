<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit;
}

$current_user_id = (int)$_SESSION['user_id'];
$error_msg = '';

$searchAction = trim($_GET['action'] ?? '');
$searchActor = trim($_GET['actor'] ?? '');
$searchTarget = trim($_GET['target'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$whereClauses = [];
$params = [];

if ($searchAction !== '') {
    $whereClauses[] = 'ual.action LIKE ?';
    $params[] = "%{$searchAction}%";
}

if ($searchActor !== '') {
    $whereClauses[] = 'actor.name LIKE ?';
    $params[] = "%{$searchActor}%";
}

if ($searchTarget !== '') {
    $whereClauses[] = 'target.name LIKE ?';
    $params[] = "%{$searchTarget}%";
}

if ($dateFrom !== '') {
    $whereClauses[] = 'DATE(ual.created_at) >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $whereClauses[] = 'DATE(ual.created_at) <= ?';
    $params[] = $dateTo;
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$totalRecords = 0;
$logs = [];

try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM user_activity_logs ual
         LEFT JOIN users actor ON actor.id = ual.user_id
         LEFT JOIN users target ON target.id = ual.target_user_id
         {$whereSQL}"
    );
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT ual.*, actor.name AS actor_name, target.name AS target_name
         FROM user_activity_logs ual
         LEFT JOIN users actor ON actor.id = ual.user_id
         LEFT JOIN users target ON target.id = ual.target_user_id
         {$whereSQL}
         ORDER BY ual.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $logs = $stmt->fetchAll();
} catch (Exception $e) {
    $error_msg = 'Unable to load audit logs: ' . $e->getMessage();
}

$totalPages = max(1, ceil($totalRecords / $perPage));

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    .audit-card {
        border-radius: 14px;
        border: none;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .audit-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
    .audit-table th { background: #1e3a8a; color: white; }
    .audit-table td, .audit-table th { vertical-align: middle; }
    .badge-action { display: inline-flex; padding: 0.35rem 0.65rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
    .badge-action-created_user { background: #dbeafe; color: #1d4ed8; }
    .badge-action-updated_user { background: #dcfce7; color: #047857; }
    .badge-action-activated_user, .badge-action-deactivated_user, .badge-action-archived_user { background: #fef3c7; color: #7c2d12; }
    .badge-action-reset_password { background: #fee2e2; color: #b91c1c; }
    .badge-action-deleted_user { background: #fee2e2; color: #b91c1c; }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4" style="background: #f1f5f9; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-3 mb-4">
        <div>
            <h1 class="h2 fw-bold" style="color: #1e3a8a;">
                <i class="fas fa-history me-2" style="color: #2563eb;"></i>Audit Trail
            </h1>
            <p class="text-muted mb-0">Track administrative activity and user lifecycle events.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="users.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Users
            </a>
            <button class="btn btn-outline-secondary" onclick="location.reload();">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
        </div>
    </div>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert" style="border-left: 4px solid #dc2626; border-radius: 10px;">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4 audit-card">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Action</label>
                    <input type="text" class="form-control" name="action" value="<?php echo htmlspecialchars($searchAction); ?>" placeholder="created_user, deleted_user">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Performed By</label>
                    <input type="text" class="form-control" name="actor" value="<?php echo htmlspecialchars($searchActor); ?>" placeholder="Admin name">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Target User</label>
                    <input type="text" class="form-control" name="target" value="<?php echo htmlspecialchars($searchTarget); ?>" placeholder="Target user name">
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted">From</label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted">To</label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">Filter Logs</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4 audit-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover audit-table mb-0">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Action</th>
                            <th>Performed By</th>
                            <th>Target</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No audit entries found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo date('M d, Y H:i', strtotime($log['created_at'])); ?></td>
                                    <td>
                                        <span class="badge-action badge-action-<?php echo htmlspecialchars($log['action']); ?>">
                                            <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($log['action']))); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['actor_name'] ?? 'System'); ?></td>
                                    <td><?php echo htmlspecialchars($log['target_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($log['details'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="d-flex justify-content-center">
            <ul class="pagination">
                <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a></li>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</main>

<?php include '../includes/footer.php'; ?>
