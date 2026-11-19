<?php
$baseUrl = str_replace('\\','/', str_replace(realpath($_SERVER['DOCUMENT_ROOT']), '', realpath(__DIR__ . '/../')));
$baseUrl = rtrim($baseUrl, '/');

$unreadNotifications = 0;
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/database.php';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $unreadNotifications = (int)$stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'LearnAlytics'); ?></title>
    <!-- Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=GA_MEASUREMENT_ID"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', 'GA_MEASUREMENT_ID');
    </script>
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/css.php?f=style.css">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/css.php?f=main.css">
    <?php if (!empty($extraCss) && is_array($extraCss)): foreach ($extraCss as $cssPath): ?>
        <link rel="stylesheet" href="<?php echo $baseUrl . '/css.php?f=' . str_replace('assets/css/', '', ltrim($cssPath, '/')); ?>">
    <?php endforeach; endif; ?>
</head>
<body class="bg-light">

    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom shadow-sm">
        <div class="container-fluid">
            <!-- Hamburger toggle (mobile) -->
            <button class="sidebar-toggle-btn" id="sidebarToggle" aria-label="Open navigation" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="<?php
                $role = $_SESSION['user_role'] ?? 'student';
                if ($role === 'admin') {
                    echo $baseUrl . '/admin_dashboard.php';
                } elseif ($role === 'instructor') {
                    echo $baseUrl . '/instructor_dashboard.php';
                } elseif ($role === 'dean') {
                    echo $baseUrl . '/dean_dashboard.php';
                } else {
                    echo $baseUrl . '/student_dashboard.php';
                }
            ?>">
                <img src="<?php echo $baseUrl; ?>/img.php?f=kcc_logo.png" alt="KCC Logo" style="height: 36px; margin-right: 10px; border-radius: 6px; object-fit: cover;">
                <span class="fw-bold tracking-tight">LearnAlytics</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php if (!empty($pageActionLabel) && !empty($pageActionUrl)): ?>
                            <li class="nav-item d-flex align-items-center me-2">
                                <a class="btn btn-sm btn-outline-light" href="<?php echo htmlspecialchars($pageActionUrl); ?>">
                                    <?php if (!empty($pageActionIcon)): ?>
                                        <i class="fas <?php echo htmlspecialchars($pageActionIcon); ?> me-1"></i>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($pageActionLabel); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link position-relative" href="<?php echo $baseUrl; ?>/notifications/index.php">
                                <i class="fas fa-bell"></i>
                                <?php if ($unreadNotifications > 0): ?>
                                    <span class="badge badge-custom position-absolute top-0 start-100 translate-middle p-1 rounded-circle" style="font-size:0.7rem; min-width:18px;">
                                        <?php echo $unreadNotifications > 99 ? '99+' : $unreadNotifications; ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user-circle me-1"></i>
                                <span class="d-none d-sm-inline fw-semibold"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                                <span class="badge bg-white text-primary text-uppercase px-2 py-1 ms-1 d-none d-md-inline-block" style="font-size:0.65rem; font-weight:700; letter-spacing:0.04em; border-radius:999px;">
                                    <?php echo htmlspecialchars($_SESSION['user_role'] ?? 'User'); ?>
                                </span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-menu-item" href="<?php echo $baseUrl; ?>/profile/index.php">
                                    <i class="fas fa-user me-2 text-muted"></i>Profile
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-menu-item" href="<?php echo $baseUrl; ?>/auth/logout.php">
                                    <i class="fas fa-sign-out-alt me-2 text-danger"></i>Logout
                                </a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo $baseUrl; ?>/auth/login.php">Login</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- App layout wrapper -->
    <div class="app-layout">
