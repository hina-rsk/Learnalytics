<?php
/**
 * Migration to create tables supporting At-Risk Student Identification,
 * Configurable Thresholds, Historical Risk Snapshots, and Intervention Tracking.
 */
require_once __DIR__ . '/../config/database.php';

try {
    // 1. Risk Configurations Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS risk_configurations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            department_id INT NULL,
            grade_threshold DECIMAL(5,2) DEFAULT 75.00,
            attendance_threshold DECIMAL(5,2) DEFAULT 80.00,
            quiz_threshold DECIMAL(5,2) DEFAULT 75.00,
            decline_threshold DECIMAL(5,2) DEFAULT 10.00,
            passing_grade DECIMAL(5,2) DEFAULT 75.00,
            moderate_grade_threshold DECIMAL(5,2) DEFAULT 80.00,
            moderate_attendance_threshold DECIMAL(5,2) DEFAULT 85.00,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dept_config (department_id),
            FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Seed default global configuration if none exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM risk_configurations WHERE department_id IS NULL");
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("
            INSERT INTO risk_configurations 
                (department_id, grade_threshold, attendance_threshold, quiz_threshold, decline_threshold, passing_grade, moderate_grade_threshold, moderate_attendance_threshold)
            VALUES 
                (NULL, 75.00, 80.00, 75.00, 10.00, 75.00, 80.00, 85.00)
        ");
    }

    // 2. Risk Assessments (Historical snapshots)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS risk_assessments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            department_id INT NULL,
            assessed_at DATETIME NOT NULL,
            overall_score INT NOT NULL DEFAULT 0,
            risk_level ENUM('low', 'moderate', 'at_risk') NOT NULL DEFAULT 'low',
            grade_avg DECIMAL(5,2) DEFAULT NULL,
            previous_grade_avg DECIMAL(5,2) DEFAULT NULL,
            grade_change_pct DECIMAL(5,2) DEFAULT 0.00,
            quiz_avg DECIMAL(5,2) DEFAULT NULL,
            attendance_rate DECIMAL(5,2) DEFAULT NULL,
            absent_count INT DEFAULT 0,
            late_count INT DEFAULT 0,
            present_count INT DEFAULT 0,
            failing_courses_count INT DEFAULT 0,
            trend_direction ENUM('improving', 'stable', 'declining') DEFAULT 'stable',
            reasons TEXT DEFAULT NULL,
            recommended_intervention TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_student_assessed (student_id, assessed_at),
            INDEX idx_dept_risk (department_id, risk_level),
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 3. Student Interventions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_interventions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            assigned_by INT NULL,
            intervention_type VARCHAR(100) NOT NULL,
            status ENUM('recommended', 'scheduled', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'recommended',
            scheduled_date DATE NULL,
            completed_date DATE NULL,
            notes TEXT NULL,
            grade_before DECIMAL(5,2) NULL,
            grade_after_2wk DECIMAL(5,2) NULL,
            grade_current DECIMAL(5,2) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_student_intervention (student_id),
            INDEX idx_intervention_status (status),
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
    exit(1);
}
