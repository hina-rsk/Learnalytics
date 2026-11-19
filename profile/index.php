<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle Profile Update
$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = $_POST['name'];
    $bio = $_POST['bio'];

    $stmt = $pdo->prepare("UPDATE users SET name = ?, bio = ? WHERE id = ?");
    if ($stmt->execute([$name, $bio, $user_id])) {
        $_SESSION['user_name'] = $name;
        $success_msg = "Profile updated successfully!";
    }
}

// Fetch user profile
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">Profile Settings</h1>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success" style="background-color: #d1fae5; color: #065f46; border-color: #6ee7b7;">
            <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-4 mb-4">
            <div class="card shadow border-0 text-center py-5" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);">
                <div class="card-body">
                    <i class="fas fa-user-circle fa-7x mb-4" style="color: #2563eb;"></i>
                    <h5 class="card-title" style="color: #1e3a8a;"><?php echo htmlspecialchars($user['name']); ?></h5>
                    <p class="card-text text-muted small"><?php echo ucfirst($user['role']); ?></p>
                    <p class="card-text text-muted small"><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($user['email']); ?></p>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow border-0 mb-4">
                <div class="card-header card-header-custom">
                    <h5 class="card-title mb-0">Update Profile Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Full Name</label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required style="border-color: #2563eb;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Email Address</label>
                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" disabled style="border-color: #2563eb;">
                            <small class="text-muted">Email cannot be changed.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="color: #1e3a8a;">Bio</label>
                            <textarea name="bio" class="form-control" rows="5" style="border-color: #2563eb;"><?php echo htmlspecialchars($user['bio']); ?></textarea>
                        </div>
                        <button type="submit" name="update_profile" class="btn" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #1e3a8a; border: none; font-weight: 600;">Save Changes</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
