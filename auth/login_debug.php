<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
require_once '../config/database.php';

if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $captchaAnswer = strtoupper(trim($_POST['captcha_answer'] ?? ''));
    $captcha = $_SESSION['login_captcha'] ?? null;
    unset($_SESSION['login_captcha']);

    if (
        !is_array($captcha)
        || !isset($captcha['answer'], $captcha['created_at'])
        || time() - (int)$captcha['created_at'] > 600
        || !hash_equals((string)$captcha['answer'], $captchaAnswer)
    ) {
        $error = 'The CAPTCHA is incorrect or expired. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];

            $role = $user['role'];
            if ($role === 'admin') {
                header("Location: ../admin_dashboard.php");
            } elseif ($role === 'instructor') {
                header("Location: ../instructor_dashboard.php");
            } elseif ($role === 'dean') {
                header("Location: ../dean_dashboard.php");
            } else {
                header("Location: ../student_dashboard.php");
            }
            exit;
        } else {
            $error = "Invalid email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - LearnAlytics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/pages/login.css">
</head>
<body class="login-page">
    <main class="login-container">
        <div class="login-card">
            <div class="login-card-header">
                <div class="text-center">
                    <img src="../assets/css/kcc_logo.png" alt="KCC Logo" class="login-brand">
                </div>
                <h1 class="login-title">LearnAlytics Login</h1>
                <div class="login-sub">Securely sign in to your account</div>
            </div>
            <div class="login-card-body">
                <form method="POST">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <strong>Error!</strong> <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <div class="form-floating mb-3">
                        <input type="email" name="email" class="form-control" id="floatingInput" placeholder="name@example.com" required>
                        <label for="floatingInput">Email address</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="password" name="password" class="form-control" id="floatingPassword" placeholder="Password" required>
                        <label for="floatingPassword">Password</label>
                    </div>
                    <div class="login-captcha">
                        <label for="captchaAnswer" class="form-label fw-semibold">Security Check</label>
                        <div class="login-captcha-challenge">
                            <img id="captchaImage" src="captcha.php?purpose=login" alt="CAPTCHA challenge">
                            <button type="button" class="btn-captcha-refresh" id="captchaRefresh" aria-label="Get a new CAPTCHA">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                        <input type="text" name="captcha_answer" id="captchaAnswer" class="form-control"
                               placeholder="Enter the 6 characters shown" required maxlength="6"
                               autocomplete="off" autocapitalize="characters" spellcheck="false">
                        <small class="text-muted">The CAPTCHA expires after 10 minutes.</small>
                    </div>
                    <button class="btn-login" type="submit">Sign in</button>
                    <div class="login-footer-links">
                        <p class="mt-3"><a href="register_student.php">Don't have an account? Register here</a></p>
                    </div>
                </form>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('captchaRefresh').addEventListener('click', function () {
            document.getElementById('captchaImage').src = 'captcha.php?purpose=login&refresh=' + Date.now();
            document.getElementById('captchaAnswer').value = '';
        });
    </script>
</body>
</html>
