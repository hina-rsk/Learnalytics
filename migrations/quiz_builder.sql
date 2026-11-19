-- Quiz builder fields. Apply after the base schema and enhance_quiz_system.sql.

ALTER TABLE quizzes
    ADD COLUMN IF NOT EXISTS instructions TEXT NULL,
    ADD COLUMN IF NOT EXISTS section_id INT NULL,
    ADD COLUMN IF NOT EXISTS academic_year_id INT NULL,
    ADD COLUMN IF NOT EXISTS semester VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS is_available TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS availability_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS opens_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS timer_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS results_after_review TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS show_correct_answers TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS show_feedback TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS results_release_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS shuffle_choices TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS one_question_per_page TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE questions
    MODIFY COLUMN type ENUM(
        'multiple_choice',
        'checkboxes',
        'short_answer',
        'paragraph',
        'true_false',
        'dropdown'
    ) NOT NULL,
    ADD COLUMN IF NOT EXISTS points INT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS is_required TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS explanation TEXT NULL,
    ADD COLUMN IF NOT EXISTS feedback_correct TEXT NULL,
    ADD COLUMN IF NOT EXISTS feedback_incorrect TEXT NULL,
    ADD COLUMN IF NOT EXISTS image_url VARCHAR(2048) NULL,
    ADD COLUMN IF NOT EXISTS manual_grading TINYINT(1) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS position INT NOT NULL DEFAULT 0;

UPDATE questions
SET manual_grading = 1
WHERE type IN ('short_answer', 'paragraph')
    AND manual_grading IS NULL
    AND TRIM(COALESCE(correct_answer, '')) = '';

UPDATE questions SET manual_grading = 0 WHERE manual_grading IS NULL;

ALTER TABLE questions
    MODIFY COLUMN manual_grading TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE quiz_results
    MODIFY COLUMN score DECIMAL(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS max_score DECIMAL(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS grading_status ENUM('pending', 'graded') NOT NULL DEFAULT 'graded',
    ADD COLUMN IF NOT EXISTS response_scores TEXT NULL,
    ADD COLUMN IF NOT EXISTS feedback TEXT NULL;

CREATE TABLE IF NOT EXISTS quiz_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    assignment_type ENUM('subject', 'section', 'program', 'year_level', 'student') NOT NULL DEFAULT 'subject',
    target_id INT NULL DEFAULT NULL,
    year_level VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    INDEX idx_quiz_assignments_quiz (quiz_id),
    INDEX idx_quiz_assignments_type (assignment_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing quizzes remain assigned to the entire subject unless they already
-- have a more specific assignment.
INSERT INTO quiz_assignments (quiz_id, assignment_type, target_id)
SELECT q.id, 'subject', NULL
FROM quizzes q
LEFT JOIN quiz_assignments qa ON qa.quiz_id = q.id
WHERE qa.id IS NULL;
