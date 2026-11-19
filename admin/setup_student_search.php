<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$message = '';
$error = '';

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initialize_profiles'])) {
    try {
        // First, create the student_profiles table if it doesn't exist
        $createTableSQL = "
            CREATE TABLE IF NOT EXISTS student_profiles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL UNIQUE,
                student_id_number VARCHAR(50) UNIQUE NOT NULL,
                year_level ENUM('1st Year', '2nd Year', '3rd Year', '4th Year', 'Other') DEFAULT '1st Year',
                enrollment_status ENUM('Active', 'Inactive', 'Graduated', 'Suspended', 'Dropped') DEFAULT 'Active',
                program_id INT,
                section_id INT,
                advisor_id INT,
                bio TEXT,
                profile_picture VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL,
                FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL,
                FOREIGN KEY (advisor_id) REFERENCES users(id) ON DELETE SET NULL
            );
        ";
        
        $pdo->exec($createTableSQL);
        
        // Now populate student profiles for existing students without profiles
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO student_profiles (student_id, student_id_number, year_level, enrollment_status)
            SELECT 
                u.id,
                CONCAT('STU', u.id),
                '1st Year',
                'Active'
            FROM users u
            WHERE u.role = 'student' 
            AND u.id NOT IN (SELECT student_id FROM student_profiles WHERE student_id IS NOT NULL)
        ");
        $stmt->execute();
        
        $rowsInserted = $pdo->query("SELECT COUNT(*) FROM student_profiles")->fetchColumn();
        $message = "Student profiles initialized successfully! Total profiles: " . $rowsInserted;
        
    } catch (Exception $e) {
        $error = "Error initializing profiles: " . $e->getMessage();
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 2px solid #2563eb;">
        <h1 class="h2" style="color: #1e3a8a;">Setup Student Search System</h1>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success" style="background-color: #d1fae5; color: #065f46; border-color: #6ee7b7;">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" style="background-color: #fee2e2; color: #991b1b; border-color: #fca5a5;">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="card dashboard-card shadow-sm mb-4">
        <div class="card-header card-header-custom">
            <h5 class="card-title mb-0">Initialize Student Profiles</h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-4">
                This setup wizard will create the <strong>student_profiles</strong> table and populate it with existing student data.
                This is required for the Smart Student Search system to function properly.
            </p>

            <div class="alert alert-info" style="background-color: #dbeafe; color: #0c4a6e; border-color: #93c5fd;">
                <i class="fas fa-info-circle me-2"></i>
                <strong>What this will do:</strong>
                <ul class="mb-0 mt-2">
                    <li>Create the <code>student_profiles</code> table</li>
                    <li>Generate unique Student IDs for all existing students</li>
                    <li>Set default values for Year Level (1st Year) and Status (Active)</li>
                    <li>Create necessary indexes for fast searching</li>
                </ul>
            </div>

            <form method="POST" class="mt-4">
                <button type="submit" name="initialize_profiles" class="btn btn-primary btn-lg">
                    <i class="fas fa-rocket me-2"></i>Initialize Student Profiles
                </button>
                <p class="text-muted mt-3">
                    <small>This operation can be performed multiple times safely - existing data will not be duplicated.</small>
                </p>
            </form>
        </div>
    </div>

    <div class="card dashboard-card shadow-sm">
        <div class="card-header card-header-custom">
            <h5 class="card-title mb-0">Manual Profile Updates</h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">After initialization, you can manually update student profiles:</p>
            
            <div class="row g-3">
                <div class="col-md-6">
                    <a href="../admin/users.php" class="btn btn-outline-primary w-100">
                        <i class="fas fa-users me-2"></i>Manage All Users
                    </a>
                </div>
                <div class="col-md-6">
                    <a href="../admin/student_search.php" class="btn btn-outline-primary w-100">
                        <i class="fas fa-magnifying-glass me-2"></i>Smart Student Search
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
