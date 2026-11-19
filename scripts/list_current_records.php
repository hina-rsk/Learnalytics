<?php
require_once __DIR__ . '/../config/database.php';

echo "=== DEPARTMENTS ===\n";
print_r($pdo->query("SELECT * FROM departments")->fetchAll());

echo "=== PROGRAMS ===\n";
print_r($pdo->query("SELECT * FROM programs")->fetchAll());

echo "=== USERS ===\n";
print_r($pdo->query("SELECT id, name, email, role, department_id FROM users")->fetchAll());

echo "=== SUBJECTS ===\n";
print_r($pdo->query("SELECT id, title, class_code, department_id, program_id, instructor_id FROM subjects")->fetchAll());

echo "=== STUDENT PROFILES ===\n";
print_r($pdo->query("SELECT sp.id, sp.student_id, sp.student_id_number, sp.department_id, sp.program_id, sp.year_level, u.name FROM student_profiles sp JOIN users u ON u.id = sp.student_id")->fetchAll());
