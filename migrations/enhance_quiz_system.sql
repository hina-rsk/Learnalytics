-- Migration: Enhance Quiz Management System with Role-Based Permissions
-- This migration adds support for quiz assignments, publishing, scheduling, and analytics

-- Add new columns to quizzes table for enhanced functionality
ALTER TABLE quizzes ADD COLUMN IF NOT EXISTS instructor_id INT AFTER subject_id;

-- Populate instructor_id from the subject's instructor_id for existing quizzes
UPDATE quizzes q
JOIN subjects s ON q.subject_id = s.id
SET q.instructor_id = s.instructor_id
WHERE q.instructor_id IS NULL;

-- Add description column
ALTER TABLE quizzes ADD COLUMN IF NOT EXISTS description TEXT AFTER title;
ALTER TABLE quizzes ADD COLUMN IF NOT EXISTS status ENUM('draft', 'published', 'closed', 'archived') DEFAULT 'draft' AFTER description;
ALTER TABLE quizzes ADD COLUMN IF NOT EXISTS due_date DATETIME NULL AFTER status;
ALTER TABLE quizzes ADD COLUMN IF NOT EXISTS time_limit INT NULL COMMENT 'Time limit in minutes' AFTER due_date;
ALTER TABLE quizzes ADD COLUMN IF NOT EXISTS attempt_limit INT DEFAULT 1 COMMENT 'Number of attempts allowed (0 = unlimited)' AFTER time_limit;
ALTER TABLE quizzes ADD COLUMN IF NOT EXISTS show_results BOOLEAN DEFAULT TRUE COMMENT 'Show results to students after submission' AFTER attempt_limit;
ALTER TABLE quizzes ADD COLUMN IF NOT EXISTS shuffle_questions BOOLEAN DEFAULT FALSE AFTER show_results;
ALTER TABLE quizzes ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER shuffle_questions;

-- Create quiz_assignments table for assigning quizzes to specific groups
-- Note: target_id has no foreign key because it references different tables (users, sections, programs)
-- depending on assignment_type. Application-level validation ensures data integrity.
CREATE TABLE IF NOT EXISTS quiz_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    assignment_type ENUM('subject', 'section', 'program', 'year_level', 'student') NOT NULL DEFAULT 'subject',
    target_id INT NULL DEFAULT NULL COMMENT 'ID of the target (user_id, section_id, program_id) - NULL for subject-wide',
    year_level VARCHAR(50) NULL COMMENT 'Year level for year_level assignment type',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    INDEX idx_quiz_assignments_quiz (quiz_id),
    INDEX idx_quiz_assignments_type (assignment_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add columns to quiz_results for enhanced tracking
ALTER TABLE quiz_results ADD COLUMN IF NOT EXISTS attempt_number INT DEFAULT 1 AFTER score;
ALTER TABLE quiz_results ADD COLUMN IF NOT EXISTS time_spent INT NULL COMMENT 'Time spent in seconds' AFTER attempt_number;
ALTER TABLE quiz_results ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL AFTER time_spent;
ALTER TABLE quiz_results ADD COLUMN IF NOT EXISTS answers TEXT NULL COMMENT 'JSON of student answers' AFTER ip_address;

-- Create quiz_analytics view for administrator monitoring
CREATE OR REPLACE VIEW quiz_analytics AS
SELECT 
    q.id as quiz_id,
    q.title as quiz_title,
    q.status,
    q.due_date,
    s.title as subject_title,
    u.name as instructor_name,
    u.id as instructor_id,
    COUNT(DISTINCT qr.student_id) as total_attempts,
    COUNT(DISTINCT qr.id) as total_submissions,
    AVG(qr.score) as average_score,
    MAX(qr.score) as highest_score,
    MIN(qr.score) as lowest_score,
    q.created_at,
    q.published_at
FROM quizzes q
LEFT JOIN subjects s ON q.subject_id = s.id
LEFT JOIN users u ON q.instructor_id = u.id
LEFT JOIN quiz_results qr ON q.id = qr.quiz_id
GROUP BY q.id, q.title, q.status, q.due_date, s.title, u.name, u.id, q.created_at, q.published_at;

-- Create student quiz access view
CREATE OR REPLACE VIEW student_quiz_access AS
SELECT DISTINCT
    q.id as quiz_id,
    q.title,
    q.description,
    q.status,
    q.due_date,
    q.time_limit,
    q.attempt_limit,
    q.show_results,
    s.id as subject_id,
    s.title as subject_title,
    s.instructor_id,
    u.name as instructor_name
FROM quizzes q
JOIN subjects s ON q.subject_id = s.id
JOIN users u ON s.instructor_id = u.id
JOIN enrollments e ON s.id = e.subject_id
JOIN users student ON e.student_id = student.id
LEFT JOIN quiz_assignments qa ON q.id = qa.quiz_id
LEFT JOIN sections sec ON qa.target_id = sec.id AND qa.assignment_type = 'section'
LEFT JOIN programs p ON qa.target_id = p.id AND qa.assignment_type = 'program'
LEFT JOIN student_profiles sp ON qa.target_id = sp.student_id AND qa.assignment_type = 'student'
WHERE student.role = 'student'
  AND q.status = 'published'
  AND (
        qa.id IS NULL  -- Assigned to entire subject
        OR (qa.assignment_type = 'section' AND sec.id = sp.section_id)
        OR (qa.assignment_type = 'program' AND p.id = sp.program_id)
        OR (qa.assignment_type = 'year_level' AND q.id IN (
            SELECT q2.id FROM quizzes q2
            JOIN quiz_assignments qa2 ON q2.id = qa2.quiz_id
            WHERE qa2.assignment_type = 'year_level' AND qa2.year_level = sp.year_level
        ))
        OR (qa.assignment_type = 'student' AND sp.student_id = student.id)
  );

-- Insert default quiz assignment for existing quizzes (assign to entire subject)
INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id)
SELECT id, 'subject', NULL FROM quizzes
WHERE id NOT IN (SELECT quiz_id FROM quiz_assignments);