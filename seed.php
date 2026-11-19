<?php
// Seed demo users, courses, and enrollments into the database
// Uses centralized database configuration

require_once __DIR__ . '/config/database.php';

try {
    // Delete existing demo data
    $pdo->exec("DELETE FROM enrollments WHERE subject_id IN (SELECT id FROM subjects WHERE instructor_id IN (SELECT id FROM users WHERE email = 'john@edulearn.com'))");
    $pdo->exec("DELETE FROM subjects WHERE instructor_id IN (SELECT id FROM users WHERE email IN ('admin@edulearn.com', 'john@edulearn.com'))");
    $pdo->exec("DELETE FROM users WHERE email IN ('admin@edulearn.com', 'john@edulearn.com', 'jane@edulearn.com')");
    
    // Insert demo users with hashed passwords
    $users = [
        ['name' => 'Admin User', 'email' => 'admin@edulearn.com', 'password' => 'admin123', 'role' => 'admin'],
        ['name' => 'John Instructor', 'email' => 'john@edulearn.com', 'password' => 'john123', 'role' => 'instructor'],
        ['name' => 'Jane Student', 'email' => 'jane@edulearn.com', 'password' => 'jane123', 'role' => 'student'],
    ];
    
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
    
    foreach ($users as $u) {
        $hashedPassword = password_hash($u['password'], PASSWORD_BCRYPT);
        $stmt->execute([$u['name'], $u['email'], $hashedPassword, $u['role']]);
    }
    
    echo "<p style='color: green;'>✓ Demo users created successfully!</p>";

    $stmt = $pdo->prepare("SELECT id FROM departments WHERE name = ? LIMIT 1");
    $stmt->execute(['School of Computing']);
    $department_id = $stmt->fetchColumn();
    if (!$department_id) {
        $stmt = $pdo->prepare("INSERT INTO departments (name, description, status) VALUES (?, ?, 'active')");
        $stmt->execute(['School of Computing', 'Demo department for computing programs.']);
        $department_id = $pdo->lastInsertId();
    }
    
    // Get the instructor and student IDs
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute(['john@edulearn.com']);
    $instructor = $stmt->fetch();
    $instructor_id = $instructor['id'];
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute(['jane@edulearn.com']);
    $student = $stmt->fetch();
    $student_id = $student['id'];
    
    // Create demo programs and sections
    $programs = [
        ['name' => 'Bachelor of Science in Computer Science', 'description' => 'A comprehensive program covering software development, algorithms, and systems design.'],
        ['name' => 'Certificate in Web Development', 'description' => 'Learn to build modern web applications with latest frameworks and technologies.'],
    ];
    
    $stmt = $pdo->prepare("INSERT INTO programs (name, description, department_id, status) VALUES (?, ?, ?, 'active')");
    $program_ids = [];
    
    foreach ($programs as $program) {
        $stmt->execute([$program['name'], $program['description'], $department_id]);
        $program_ids[] = $pdo->lastInsertId();
    }
    
    echo "<p style='color: green;'>✓ Demo programs created successfully!</p>";
    
    // Create demo academic years
    $academicYears = [
        ['name' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_current' => 1],
    ];
    
    $stmt = $pdo->prepare("INSERT INTO academic_years (name, start_date, end_date, is_current) VALUES (?, ?, ?, ?)");
    $academic_year_ids = [];
    
    foreach ($academicYears as $ay) {
        $stmt->execute([$ay['name'], $ay['start_date'], $ay['end_date'], $ay['is_current']]);
        $academic_year_ids[] = $pdo->lastInsertId();
    }
    
    echo "<p style='color: green;'>✓ Demo academic year created successfully!</p>";
    
    // Create demo sections
    $sections = [
        ['program_id' => $program_ids[0], 'academic_year_id' => $academic_year_ids[0], 'name' => 'Section A'],
        ['program_id' => $program_ids[0], 'academic_year_id' => $academic_year_ids[0], 'name' => 'Section B'],
        ['program_id' => $program_ids[1], 'academic_year_id' => $academic_year_ids[0], 'name' => 'Web Dev Batch 1'],
    ];
    
    $stmt = $pdo->prepare("INSERT INTO sections (program_id, academic_year_id, name) VALUES (?, ?, ?)");
    $section_ids = [];
    
    foreach ($sections as $section) {
        $stmt->execute([$section['program_id'], $section['academic_year_id'], $section['name']]);
        $section_ids[] = $pdo->lastInsertId();
    }
    
    echo "<p style='color: green;'>✓ Demo sections created successfully!</p>";
    
    // Create demo courses with sections
    $subjects = [
        ['title' => 'Introduction to PHP', 'description' => 'Learn the basics of PHP programming language. This course covers variables, functions, loops, and database connectivity.', 'status' => 'active', 'program_id' => $program_ids[0], 'section_id' => $section_ids[0]],
        ['title' => 'Web Development 101', 'description' => 'Master HTML, CSS, and JavaScript to build responsive websites. Create modern web applications from scratch.', 'status' => 'active', 'program_id' => $program_ids[1], 'section_id' => $section_ids[2]],
        ['title' => 'Database Management', 'description' => 'Learn MySQL database design, queries, and optimization. Build efficient data storage solutions.', 'status' => 'active', 'program_id' => $program_ids[0], 'section_id' => $section_ids[0]],
        ['title' => 'Advanced JavaScript', 'description' => 'Dive deep into JavaScript ES6+, async programming, and modern frameworks. Build scalable applications.', 'status' => 'active', 'program_id' => $program_ids[1], 'section_id' => $section_ids[2]],
        ['title' => 'Python Fundamentals', 'description' => 'Get started with Python programming. Learn data types, functions, OOP, and file handling.', 'status' => 'draft', 'program_id' => $program_ids[0], 'section_id' => $section_ids[1]],
        ['title' => 'Web Security Basics', 'description' => 'Understand common web vulnerabilities and how to protect your applications. SQL injection, XSS, CSRF prevention.', 'status' => 'completed', 'program_id' => $program_ids[1], 'section_id' => $section_ids[2]],
    ];
    
    $stmt = $pdo->prepare("INSERT INTO subjects (instructor_id, program_id, department_id, section_id, title, description, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $subject_ids = [];
    
    foreach ($subjects as $subject) {
        $stmt->execute([$instructor_id, $subject['program_id'], $department_id, $subject['section_id'], $subject['title'], $subject['description'], $subject['status']]);
        $subject_ids[] = $pdo->lastInsertId();
    }
    
    echo "<p style='color: green;'>✓ Demo subjects (6 classes) created successfully!</p>";
    
    // Enroll student in the active subjects
    $stmt = $pdo->prepare("INSERT INTO enrollments (student_id, subject_id) VALUES (?, ?)");
    
    // Only enroll in first 4 subjects (active ones, not draft or completed)
    for ($i = 0; $i < 4; $i++) {
        $stmt->execute([$student_id, $subject_ids[$i]]);
    }
    
    echo "<p style='color: green;'>✓ Student enrolled in 4 active courses!</p>";
    
    // Create demo assignments
    $tomorrow = date('Y-m-d H:i:s', strtotime('+1 day'));
    $nextweek = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    $assignments = [
        ['subject_id' => $subject_ids[0], 'title' => 'PHP Basics Quiz', 'description' => 'Create a PHP script that handles form submissions and validates user input.', 'due_date' => $nextweek],
        ['subject_id' => $subject_ids[1], 'title' => 'Build a Responsive Website', 'description' => 'Create a responsive HTML/CSS website that works on mobile, tablet, and desktop.', 'due_date' => $nextweek],
        ['subject_id' => $subject_ids[2], 'title' => 'Database Design Project', 'description' => 'Design and implement a MySQL database schema for a simple e-commerce application.', 'due_date' => $tomorrow],
    ];
    
    $stmt = $pdo->prepare("INSERT INTO assignments (subject_id, title, description, due_date) VALUES (?, ?, ?, ?)");
    
    foreach ($assignments as $assignment) {
        $stmt->execute([$assignment['subject_id'], $assignment['title'], $assignment['description'], $assignment['due_date']]);
    }
    
    echo "<p style='color: green;'>✓ Demo assignments created successfully!</p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
