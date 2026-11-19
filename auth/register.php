<?php
session_start();
require_once '../config/database.php';

if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'];
    if ($role === 'admin') {
        header("Location: ../admin_dashboard.php");
    } elseif ($role === 'instructor') {
        header("Location: ../instructor_dashboard.php");
    } else {
        header("Location: ../student_dashboard.php");
    }
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $role = $_POST['role'];

    // Basic validation
    if (empty($name) || empty($email) || empty($password) || empty($role)) {
        $error = "All fields are required.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Email already exists.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$name, $email, $hashed_password, $role])) {
                $success = "Registration successful! You can now <a href='login.php'>login</a>.";
            } else {
                $error = "An error occurred. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - LearnAlytics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css.php?f=style.css">
</head>
<body class="d-flex align-items-center py-4 bg-stat-blue" style="min-height: 100vh;">
    <main class="form-signin w-100 m-auto" style="max-width: 400px;">
        <div class="card shadow-lg border-0">
            <div class="card-header bg-stat-blue text-white p-4">
                <div class="text-center mb-3">
                    <img src="../img.php?f=kcc_logo.png" alt="KCC Logo" class="logo-small">
                </div>
                <h1 class="h4 mb-0 fw-bold text-center">LearnAlytics Registration</h1>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show alert-danger-soft" role="alert">
                            <strong>Error!</strong> <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show alert-success-soft" role="alert">
                            <strong>Success!</strong> <?php echo $success; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <div class="form-floating mb-3">
                        <input type="text" name="name" class="form-control border-2 border-primary-blue" id="floatingName" placeholder="Full Name" required>
                        <label for="floatingName" class="text-primary-blue">Full Name</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="email" name="email" class="form-control border-2 border-primary-blue" id="floatingInput" placeholder="name@example.com" required>
                        <label for="floatingInput" class="text-primary-blue">Email address</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="password" name="password" class="form-control border-2 border-primary-blue" id="floatingPassword" placeholder="Password" required>
                        <label for="floatingPassword" class="text-primary-blue">Password</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-primary-blue">Role</label>
                        <select name="role" class="form-select border-2 border-primary-blue" required>
                            <option value="student">Student</option>
                            <option value="instructor">Instructor</option>
                        </select>
                    </div>
                    <button class="w-100 btn btn-lg fw-bold btn-gradient-yellow" type="submit">Create Account</button>
                    <p class="mt-3 text-center"><a href="login.php" class="text-primary-blue text-no-underline fw-bold">Already have an account? Login here</a></p>
                </form>
            </div>
        </div>
    </main>
    <!-- Bootstrap Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
