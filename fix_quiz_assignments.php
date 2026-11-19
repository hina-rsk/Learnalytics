<?php
/**
 * Fix quiz_assignments table foreign key constraint
 * Remove the problematic foreign key on target_id since it references multiple tables
 */

require_once 'config/database.php';

echo "Fixing quiz_assignments table...\n\n";

try {
    // Drop the existing table if it exists
    $pdo->exec("DROP TABLE IF EXISTS quiz_assignments");
    echo "[OK] Dropped existing quiz_assignments table\n";
    
    // Create the table WITHOUT foreign key on target_id
    // target_id can reference users, sections, or programs depending on assignment_type
    $pdo->exec("
        CREATE TABLE quiz_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT NOT NULL,
            assignment_type ENUM('subject', 'section', 'program', 'year_level', 'student') NOT NULL DEFAULT 'subject',
            target_id INT NULL DEFAULT NULL COMMENT 'ID of the target (user_id, section_id, program_id) - NULL for subject-wide',
            year_level VARCHAR(50) NULL COMMENT 'Year level for year_level assignment type',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
            INDEX idx_quiz_assignments_quiz (quiz_id),
            INDEX idx_quiz_assignments_type (assignment_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "[OK] Created quiz_assignments table without problematic foreign key\n";
    
    // Insert default assignments for existing quizzes
    $pdo->exec("
        INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id)
        SELECT id, 'subject', NULL FROM quizzes
        WHERE id NOT IN (SELECT DISTINCT quiz_id FROM quiz_assignments WHERE quiz_id IS NOT NULL)
    ");
    echo "[OK] Inserted default assignments for existing quizzes\n";
    
    echo "\n✅ quiz_assignments table fixed successfully!\n";
    echo "Note: The target_id field no longer has a foreign key constraint because it\n";
    echo "references different tables (users, sections, programs) depending on assignment_type.\n";
    echo "Application-level validation should be used to ensure data integrity.\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>