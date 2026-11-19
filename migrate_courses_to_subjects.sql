-- Rename courses table to subjects
RENAME TABLE courses TO subjects;

-- Update foreign keys in related tables
ALTER TABLE enrollments CHANGE course_id subject_id INT NOT NULL;
ALTER TABLE lessons CHANGE course_id subject_id INT NOT NULL;
ALTER TABLE assignments CHANGE course_id subject_id INT NOT NULL;
ALTER TABLE quizzes CHANGE course_id subject_id INT NOT NULL;

-- If you have group chat tables, update those as well
-- Example:
-- RENAME TABLE course_group_chats TO subject_group_chats;
-- ALTER TABLE subject_group_chats CHANGE course_id subject_id INT NOT NULL;
-- RENAME TABLE course_group_members TO subject_group_members;
-- RENAME TABLE course_group_messages TO subject_group_messages;
