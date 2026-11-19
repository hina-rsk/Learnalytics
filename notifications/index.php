<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle Mark All as Read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header("Location: index.php");
    exit;
}

// Fetch all notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">Notifications</h1>
        <form method="POST">
            <button type="submit" name="mark_all_read" class="btn btn-sm" style="background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: white; border: none;"><i class="fas fa-check-double me-1"></i> Mark All as Read</button>
        </form>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0">Your Notifications</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($notifications as $notif): ?>
                            <div class="list-group-item border-0 py-3 px-4 <?php echo !$notif['is_read'] ? 'fw-bold' : ''; ?>" style="border-left: 4px solid <?php echo !$notif['is_read'] ? '#2563eb' : 'transparent'; ?>">
                                <div class="d-flex w-100 justify-content-between">
                                    <p class="mb-1" style="color: <?php echo !$notif['is_read'] ? '#1e3a8a' : '#666'; ?>"><?php echo htmlspecialchars($notif['message']); ?></p>
                                    <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($notifications)): ?>
                            <div class="p-5 text-center text-muted">No notifications found.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
