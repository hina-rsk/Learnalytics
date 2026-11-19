-- ============================================================
-- Migration: Manual Exam Recording Feature
-- Creates exam_records (assessment header) and exam_scores
-- (per-student score entries) tables.
-- ============================================================

CREATE TABLE IF NOT EXISTS exam_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instructor_id INT NOT NULL,
    subject_id INT NOT NULL,
    section_id INT,
    title VARCHAR(255) NOT NULL,
    exam_date DATE NOT NULL,
    total_items INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS exam_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    score DECIMAL(10,2) NOT NULL DEFAULT 0,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exam_records(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_exam_student (exam_id, student_id)
);

CREATE INDEX IF NOT EXISTS idx_exam_records_instructor ON exam_records(instructor_id);
CREATE INDEX IF NOT EXISTS idx_exam_records_subject ON exam_records(subject_id);
CREATE INDEX IF NOT EXISTS idx_exam_scores_exam ON exam_scores(exam_id);
CREATE INDEX IF NOT EXISTS idx_exam_scores_student ON exam_scores(student_id);
