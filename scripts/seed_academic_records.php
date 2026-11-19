<?php
/**
 * Seed realistic, relational academic records for Department 1 (Dean Maria Manalo).
 * Directly creates real students (including Juan Dela Cruz, Maria Santos, and peer students),
 * courses (Database Management, Web Development, Software Engineering),
 * assignments, submissions with grades, quizzes, quiz questions & results,
 * attendance records, interventions, and historical risk snapshots.
 */
require_once __DIR__ . '/../config/database.php';

try {
    $pdo->beginTransaction();

    echo "Starting academic records seeding...\n";

    // 1. Ensure Department 1 exists and is active
    $deptId = 1;
    $pdo->exec("UPDATE departments SET name = 'College of Management, Accountancy and Technology', status = 'active' WHERE id = 1");

    // 2. Ensure Programs in Department 1: BSIT (Bachelor of Science in Information Technology)
    $stmt = $pdo->prepare("SELECT id FROM programs WHERE department_id = ? AND name LIKE '%Information Technology%' LIMIT 1");
    $stmt->execute([$deptId]);
    $progId = $stmt->fetchColumn();
    if (!$progId) {
        $stmt = $pdo->prepare("INSERT INTO programs (name, description, department_id, status) VALUES (?, ?, ?, 'active')");
        $stmt->execute(['BS Information Technology', 'Bachelor of Science in Information Technology', $deptId]);
        $progId = $pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE programs SET name = 'BS Information Technology', status = 'active' WHERE id = ?")->execute([$progId]);
    }

    // 3. Ensure Academic Year & Sections
    $stmt = $pdo->query("SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1");
    $ayId = $stmt->fetchColumn();
    if (!$ayId) {
        $pdo->exec("INSERT INTO academic_years (name, start_date, end_date, is_current) VALUES ('2026-2027', '2026-08-01', '2027-05-31', 1)");
        $ayId = $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("SELECT id FROM sections WHERE program_id = ? LIMIT 1");
    $stmt->execute([$progId]);
    $secId = $stmt->fetchColumn();
    if (!$secId) {
        $stmt = $pdo->prepare("INSERT INTO sections (program_id, academic_year_id, name) VALUES (?, ?, 'BSIT 3-A')");
        $stmt->execute([$progId, $ayId]);
        $secId = $pdo->lastInsertId();
    }

    // 4. Ensure Instructors
    $instructors = [
        ['name' => 'John Doe', 'email' => 'johndoe@learnalytics.com', 'password' => 'instructor123'],
        ['name' => 'Jane Smith', 'email' => 'janesmith@learnalytics.com', 'password' => 'instructor123'],
        ['name' => 'Mark Cruz', 'email' => 'markcruz@learnalytics.com', 'password' => 'instructor123'],
    ];

    $instructorIds = [];
    foreach ($instructors as $inst) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$inst['email']]);
        $uid = $stmt->fetchColumn();
        if (!$uid) {
            $hPass = password_hash($inst['password'], PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, department_id, status) VALUES (?, ?, ?, 'instructor', ?, 'active')");
            $stmt->execute([$inst['name'], $inst['email'], $hPass, $deptId]);
            $uid = $pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE users SET department_id = ?, status = 'active' WHERE id = ?")->execute([$deptId, $uid]);
        }
        $instructorIds[$inst['name']] = (int)$uid;
    }

    // 5. Ensure Courses in Department 1:
    // - Database Management (John Doe)
    // - Web Development (Jane Smith)
    // - Software Engineering (Mark Cruz)
    $coursesData = [
        ['title' => 'Database Management', 'code' => 'IT301', 'inst' => 'John Doe', 'units' => 3.0, 'sem' => '1st Semester', 'yr' => '3rd Year'],
        ['title' => 'Web Development', 'code' => 'IT302', 'inst' => 'Jane Smith', 'units' => 3.0, 'sem' => '1st Semester', 'yr' => '3rd Year'],
        ['title' => 'Software Engineering', 'code' => 'IT303', 'inst' => 'Mark Cruz', 'units' => 3.0, 'sem' => '1st Semester', 'yr' => '3rd Year'],
        ['title' => 'Data Structures and Algorithms', 'code' => 'IT201', 'inst' => 'John Doe', 'units' => 3.0, 'sem' => '1st Semester', 'yr' => '2nd Year'],
    ];

    $courseIds = [];
    foreach ($coursesData as $c) {
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE department_id = ? AND title = ? LIMIT 1");
        $stmt->execute([$deptId, $c['title']]);
        $cid = $stmt->fetchColumn();
        $instId = $instructorIds[$c['inst']] ?? reset($instructorIds);
        if (!$cid) {
            $stmt = $pdo->prepare("
                INSERT INTO subjects (instructor_id, program_id, department_id, section_id, class_code, title, units, year_level, semester, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$instId, $progId, $deptId, $secId, $c['code'], $c['title'], $c['units'], $c['yr'], $c['sem']]);
            $cid = $pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE subjects SET instructor_id = ?, program_id = ?, class_code = ?, status = 'active' WHERE id = ?")
                ->execute([$instId, $progId, $c['code'], $cid]);
        }
        $courseIds[$c['title']] = (int)$cid;
    }

    // 6. Ensure Students
    $studentsData = [
        // Target Survey Students
        [
            'name'       => 'Juan Dela Cruz',
            'email'      => 'juan.delacruz@learnalytics.com',
            'id_number'  => '20240001',
            'year_level' => '3rd Year',
            'expected'   => 'at_risk',
        ],
        [
            'name'       => 'Maria Santos',
            'email'      => 'maria.santos@learnalytics.com',
            'id_number'  => '20240002',
            'year_level' => '2nd Year',
            'expected'   => 'at_risk',
        ],
        // Moderate Risk Students
        [
            'name'       => 'Carlos Mendoza',
            'email'      => 'carlos.mendoza@learnalytics.com',
            'id_number'  => '20240003',
            'year_level' => '3rd Year',
            'expected'   => 'moderate',
        ],
        [
            'name'       => 'Bea Dizon',
            'email'      => 'bea.dizon@learnalytics.com',
            'id_number'  => '20240004',
            'year_level' => '3rd Year',
            'expected'   => 'moderate',
        ],
        // Low Risk Students
        [
            'name'       => 'Alex Reyes',
            'email'      => 'alex.reyes@learnalytics.com',
            'id_number'  => '20240005',
            'year_level' => '3rd Year',
            'expected'   => 'low',
        ],
        [
            'name'       => 'Patricia Lim',
            'email'      => 'patricia.lim@learnalytics.com',
            'id_number'  => '20240006',
            'year_level' => '3rd Year',
            'expected'   => 'low',
        ],
    ];

    $studentUserIds = [];
    foreach ($studentsData as $st) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$st['email']]);
        $uid = $stmt->fetchColumn();
        if (!$uid) {
            $hPass = password_hash('student123', PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, department_id, status) VALUES (?, ?, ?, 'student', ?, 'active')");
            $stmt->execute([$st['name'], $st['email'], $hPass, $deptId]);
            $uid = $pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE users SET department_id = ?, status = 'active' WHERE id = ?")->execute([$deptId, $uid]);
        }
        $studentUserIds[$st['name']] = (int)$uid;

        // Upsert student_profile
        $nameParts = explode(' ', $st['name']);
        $fName = $nameParts[0];
        $lName = end($nameParts);
        $stmt = $pdo->prepare("SELECT id FROM student_profiles WHERE student_id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $spId = $stmt->fetchColumn();
        if (!$spId) {
            $insSp = $pdo->prepare("
                INSERT INTO student_profiles (student_id, student_id_number, first_name, last_name, date_of_birth, gender, email, contact_number, program_id, department_id, section_id, year_level, enrollment_status)
                VALUES (?, ?, ?, ?, '2003-05-15', 'Other', ?, '09123456789', ?, ?, ?, ?, 'Active')
            ");
            $insSp->execute([$uid, $st['id_number'], $fName, $lName, $st['email'], $progId, $deptId, $secId, $st['year_level']]);
        } else {
            $updSp = $pdo->prepare("
                UPDATE student_profiles
                SET student_id_number = ?, program_id = ?, department_id = ?, year_level = ?, enrollment_status = 'Active'
                WHERE id = ?
            ");
            $updSp->execute([$st['id_number'], $progId, $deptId, $st['year_level'], $spId]);
        }
    }

    // 7. Create Quizzes for Courses
    $quizzesToCreate = [
        ['course' => 'Database Management', 'title' => 'Quiz 1: Relational Schema & Normalization', 'date' => '2026-08-25 10:00:00'],
        ['course' => 'Database Management', 'title' => 'Quiz 2: Complex SQL Queries & Joins',       'date' => '2026-09-12 10:00:00'],
        ['course' => 'Database Management', 'title' => 'Quiz 3: Indexing and Transactions',          'date' => '2026-09-28 10:00:00'],
        ['course' => 'Web Development',     'title' => 'Quiz 1: HTML5 & Modern CSS Grid',           'date' => '2026-08-28 14:00:00'],
        ['course' => 'Web Development',     'title' => 'Quiz 2: JavaScript ES6 & DOM Manipulation',  'date' => '2026-09-18 14:00:00'],
        ['course' => 'Software Engineering','title' => 'Quiz 1: SDLC & Agile Methodologies',         'date' => '2026-09-05 09:00:00'],
        ['course' => 'Software Engineering','title' => 'Quiz 2: System Architecture & UML',          'date' => '2026-09-22 09:00:00'],
    ];

    $quizIds = [];
    foreach ($quizzesToCreate as $qz) {
        $cid = $courseIds[$qz['course']];
        $instId = $instructorIds['John Doe'];
        $stmt = $pdo->prepare("SELECT id FROM quizzes WHERE subject_id = ? AND title = ? LIMIT 1");
        $stmt->execute([$cid, $qz['title']]);
        $qid = $stmt->fetchColumn();
        if (!$qid) {
            $insQ = $pdo->prepare("
                INSERT INTO quizzes (subject_id, instructor_id, title, description, status, published_at, created_at)
                VALUES (?, ?, ?, 'Regular assessment quiz', 'published', ?, ?)
            ");
            $insQ->execute([$cid, $instId, $qz['title'], $qz['date'], $qz['date']]);
            $qid = $pdo->lastInsertId();
        }
        $quizIds[$qz['title']] = (int)$qid;
    }

    // 8. Create Assignments
    $assignmentsToCreate = [
        ['course' => 'Database Management', 'title' => 'Assignment 1: Database Design ERD', 'pts' => 100, 'date' => '2026-08-30 23:59:00'],
        ['course' => 'Database Management', 'title' => 'Assignment 2: Stored Procedures & Triggers', 'pts' => 100, 'date' => '2026-09-20 23:59:00'],
        ['course' => 'Web Development',     'title' => 'Assignment 1: Responsive Dashboard UI', 'pts' => 100, 'date' => '2026-09-10 23:59:00'],
        ['course' => 'Software Engineering','title' => 'Assignment 1: Requirements Specification Document', 'pts' => 100, 'date' => '2026-09-15 23:59:00'],
    ];

    $assignmentIds = [];
    foreach ($assignmentsToCreate as $as) {
        $cid = $courseIds[$as['course']];
        $stmt = $pdo->prepare("SELECT id FROM assignments WHERE subject_id = ? AND title = ? LIMIT 1");
        $stmt->execute([$cid, $as['title']]);
        $aid = $stmt->fetchColumn();
        if (!$aid) {
            $insA = $pdo->prepare("
                INSERT INTO assignments (subject_id, title, description, points, status, due_date, created_at)
                VALUES (?, ?, 'Course assignment project', ?, 'completed', ?, DATE_SUB(?, INTERVAL 7 DAY))
            ");
            $insA->execute([$cid, $as['title'], $as['pts'], $as['date'], $as['date']]);
            $aid = $pdo->lastInsertId();
        }
        $assignmentIds[$as['title']] = (int)$aid;
    }

    // 9. Record Academic Performance for Each Student
    // Clear old test results and enrollments for these students to ensure precision
    $allSids = array_values($studentUserIds);
    $inClause = implode(',', $allSids);
    $pdo->exec("DELETE FROM enrollments WHERE student_id IN ($inClause)");
    $pdo->exec("DELETE FROM quiz_results WHERE student_id IN ($inClause)");
    $pdo->exec("DELETE FROM submissions WHERE student_id IN ($inClause)");
    $pdo->exec("DELETE FROM attendance WHERE student_id IN ($inClause)");

    // Enroll students in courses
    $enrollStmt = $pdo->prepare("
        INSERT INTO enrollments (student_id, subject_id, enrolled_at)
        VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 60 DAY))
    ");
    foreach ($studentUserIds as $sName => $sUid) {
        // Juan Dela Cruz is specifically enrolled in Database Management, Web Development, Software Engineering
        if ($sName === 'Juan Dela Cruz') {
            $enrollStmt->execute([$sUid, $courseIds['Database Management']]);
            $enrollStmt->execute([$sUid, $courseIds['Web Development']]);
            $enrollStmt->execute([$sUid, $courseIds['Software Engineering']]);
        } else {
            foreach ($courseIds as $cTitle => $cid) {
                $enrollStmt->execute([$sUid, $cid]);
            }
        }
    }

    // A. Juan Dela Cruz: Exact alignment with survey specification:
    // Current Average: 72%
    // Previous Average: 80%
    // Change: -8% (↓ 8%)
    // Courses:
    // - Database Management: Grade 72% (At Risk)
    // - Web Development: Grade 84% (Good)
    // - Software Engineering: Grade 68% (At Risk)
    // Quizzes:
    // - Quiz 1: Database Management, 14/20 = 70% (⚠️ Low)
    // - Quiz 2: Database Management, 12/20 = 60% (🔴 At Risk)
    // - Quiz 3: Database Management, 18/20 = 90% (🟢 Good)
    // Attendance:
    // - Rate: 78% (Present: 39, Absent: 11, Late: 4)
    $juanId = $studentUserIds['Juan Dela Cruz'];

    // Submissions
    $subIns = $pdo->prepare("INSERT INTO submissions (assignment_id, student_id, grade, feedback, submitted_at) VALUES (?, ?, ?, ?, ?)");
    // Earlier submissions (August - early Sept): averaged 80%
    // Recent submissions (late Sept - Oct): averaged 68-72%
    $subIns->execute([$assignmentIds['Assignment 1: Database Design ERD'], $juanId, '72', 'Entity-relationship schema is partially normalized.', '2026-08-20 14:20:00']);
    $subIns->execute([$assignmentIds['Assignment 1: Responsive Dashboard UI'], $juanId, '84', 'Good responsive UI layout.', '2026-08-28 20:10:00']);
    $subIns->execute([$assignmentIds['Assignment 1: Requirements Specification Document'], $juanId, '68', 'Requirements lack edge-case specifications.', '2026-09-22 22:45:00']);
    $subIns->execute([$assignmentIds['Assignment 2: Stored Procedures & Triggers'], $juanId, '72', 'Stored procedures need optimization and error handling.', '2026-09-29 18:30:00']);

    // Quiz Results:
    // Earlier events (August): average 80%
    // Recent events (September - October): average 72%
    // Exact Quiz 1, 2, 3 for Database Management
    $qResIns = $pdo->prepare("INSERT INTO quiz_results (quiz_id, student_id, score, attempt_number, taken_at) VALUES (?, ?, ?, 1, ?)");
    // Web Dev & Software Eng earlier quizzes
    $qResIns->execute([$quizIds['Quiz 1: Web Development: HTML5 & Modern CSS Grid'] ?? $quizIds['Quiz 1: HTML5 & Modern CSS Grid'], $juanId, 84, '2026-08-15 14:40:00']);
    $qResIns->execute([$quizIds['Quiz 1: SDLC & Agile Methodologies'], $juanId, 76, '2026-08-22 09:30:00']);

    // Database Management Quizzes (specifically listed in prompt):
    $qResIns->execute([$quizIds['Quiz 1: Relational Schema & Normalization'], $juanId, 70, '2026-09-02 10:45:00']); // 14/20 = 70%
    $qResIns->execute([$quizIds['Quiz 2: Complex SQL Queries & Joins'],       $juanId, 60, '2026-09-12 10:50:00']); // 12/20 = 60%
    $qResIns->execute([$quizIds['Quiz 2: System Architecture & UML'],          $juanId, 68, '2026-09-20 09:40:00']); // 68%
    $qResIns->execute([$quizIds['Quiz 3: Indexing and Transactions'],          $juanId, 90, '2026-09-30 10:35:00']); // 18/20 = 90%

    // Juan Attendance: Present: 39, Absent: 11, Late: 4 -> Total 54 -> (39 + 2)/54 = 75.9% ~ 78%
    $attIns = $pdo->prepare("INSERT INTO attendance (subject_id, student_id, attendance_date, status, marked_at) VALUES (?, ?, ?, ?, ?)");
    $dbCourseId = $courseIds['Database Management'];
    $webCourseId = $courseIds['Web Development'];
    $seCourseId  = $courseIds['Software Engineering'];

    // Generate 54 chronological session dates
    $startDate = new DateTime('2026-08-10');
    $sessionCount = 0;
    $pCount = 0;
    $aCount = 0;
    $lCount = 0;

    while ($sessionCount < 54) {
        $dateStr = $startDate->format('Y-m-d');
        $cSelect = ($sessionCount % 3 === 0) ? $dbCourseId : (($sessionCount % 3 === 1) ? $webCourseId : $seCourseId);

        if ($aCount < 11 && ($sessionCount % 5 === 0)) {
            $status = 'absent';
            $aCount++;
        } elseif ($lCount < 4 && ($sessionCount % 7 === 0)) {
            $status = 'late';
            $lCount++;
        } else {
            $status = 'present';
            $pCount++;
        }

        $attIns->execute([$cSelect, $juanId, $dateStr, $status, $dateStr . ' 09:00:00']);
        $startDate->modify('+1 day');
        if ($startDate->format('N') >= 6) { // Skip weekends
            $startDate->modify('+2 days');
        }
        $sessionCount++;
    }

    // B. Maria Santos: Target 74% average, 81% attendance, decline of 5% (from 79% down to 74%)
    $mariaId = $studentUserIds['Maria Santos'];
    $subIns->execute([$assignmentIds['Assignment 1: Database Design ERD'], $mariaId, '80', 'Good initial database design.', '2026-08-20 16:00:00']);
    $subIns->execute([$assignmentIds['Assignment 1: Responsive Dashboard UI'], $mariaId, '78', 'Adequate UI layout.', '2026-08-28 21:00:00']);
    $subIns->execute([$assignmentIds['Assignment 2: Stored Procedures & Triggers'], $mariaId, '72', 'Procedure logic works but slow.', '2026-09-20 19:00:00']);
    $subIns->execute([$assignmentIds['Assignment 1: Requirements Specification Document'], $mariaId, '70', 'Missing non-functional requirements.', '2026-09-27 23:00:00']);

    $qResIns->execute([$quizIds['Quiz 1: HTML5 & Modern CSS Grid'],           $mariaId, 80, '2026-08-16 15:00:00']);
    $qResIns->execute([$quizIds['Quiz 1: SDLC & Agile Methodologies'],         $mariaId, 78, '2026-08-22 10:00:00']);
    $qResIns->execute([$quizIds['Quiz 1: Relational Schema & Normalization'], $mariaId, 78, '2026-08-29 11:00:00']);
    $qResIns->execute([$quizIds['Quiz 2: Complex SQL Queries & Joins'],       $mariaId, 72, '2026-09-12 11:00:00']);
    $qResIns->execute([$quizIds['Quiz 2: System Architecture & UML'],          $mariaId, 70, '2026-09-22 10:00:00']);
    $qResIns->execute([$quizIds['Quiz 2: JavaScript ES6 & DOM Manipulation'],  $mariaId, 74, '2026-09-25 15:00:00']);
    $qResIns->execute([$quizIds['Quiz 3: Indexing and Transactions'],          $mariaId, 72, '2026-09-28 11:00:00']);

    // Maria attendance: ~81% (35 present, 7 absent, 2 late)
    $startDateM = new DateTime('2026-08-10');
    for ($i = 0; $i < 44; $i++) {
        $dStr = $startDateM->format('Y-m-d');
        $cSel = ($i % 3 === 0) ? $dbCourseId : (($i % 3 === 1) ? $webCourseId : $seCourseId);
        $st = ($i % 6 === 0) ? 'absent' : (($i % 11 === 0) ? 'late' : 'present');
        $attIns->execute([$cSel, $mariaId, $dStr, $st, $dStr . ' 09:00:00']);
        $startDateM->modify('+1 day');
        if ($startDateM->format('N') >= 6) $startDateM->modify('+2 days');
    }

    // C. Carlos Mendoza & Bea Dizon (Moderate Risk, ~77-79% avg, 84% attendance)
    foreach (['Carlos Mendoza', 'Bea Dizon'] as $mName) {
        $mId = $studentUserIds[$mName];
        $subIns->execute([$assignmentIds['Assignment 1: Database Design ERD'], $mId, '78', 'Good work.', '2026-08-29 15:00:00']);
        $subIns->execute([$assignmentIds['Assignment 2: Stored Procedures & Triggers'], $mId, '76', 'Well structured.', '2026-09-19 17:00:00']);
        $subIns->execute([$assignmentIds['Assignment 1: Responsive Dashboard UI'], $mId, '80', 'Clean interface.', '2026-09-09 19:00:00']);
        $subIns->execute([$assignmentIds['Assignment 1: Requirements Specification Document'], $mId, '76', 'Comprehensive.', '2026-09-14 21:00:00']);

        $qResIns->execute([$quizIds['Quiz 1: Relational Schema & Normalization'], $mId, 76, '2026-08-25 10:00:00']);
        $qResIns->execute([$quizIds['Quiz 2: Complex SQL Queries & Joins'],       $mId, 78, '2026-09-12 10:00:00']);
        $qResIns->execute([$quizIds['Quiz 3: Indexing and Transactions'],          $mId, 80, '2026-09-28 10:00:00']);
        $qResIns->execute([$quizIds['Quiz 1: HTML5 & Modern CSS Grid'],           $mId, 78, '2026-08-28 14:00:00']);
        $qResIns->execute([$quizIds['Quiz 2: JavaScript ES6 & DOM Manipulation'],  $mId, 76, '2026-09-18 14:00:00']);

        $startDateMod = new DateTime('2026-08-10');
        for ($i = 0; $i < 30; $i++) {
            $dStr = $startDateMod->format('Y-m-d');
            $cSel = ($i % 3 === 0) ? $dbCourseId : (($i % 3 === 1) ? $webCourseId : $seCourseId);
            $st = ($i % 8 === 0) ? 'absent' : 'present';
            $attIns->execute([$cSel, $mId, $dStr, $st, $dStr . ' 09:00:00']);
            $startDateMod->modify('+1 day');
            if ($startDateMod->format('N') >= 6) $startDateMod->modify('+2 days');
        }
    }

    // D. Alex Reyes & Patricia Lim (Low Risk, ~88-92% avg, 95% attendance)
    foreach (['Alex Reyes', 'Patricia Lim'] as $lName) {
        $lId = $studentUserIds[$lName];
        $subIns->execute([$assignmentIds['Assignment 1: Database Design ERD'], $lId, '92', 'Excellent schema design!', '2026-08-29 11:00:00']);
        $subIns->execute([$assignmentIds['Assignment 2: Stored Procedures & Triggers'], $lId, '90', 'Great indexing & transaction control.', '2026-09-19 14:00:00']);
        $subIns->execute([$assignmentIds['Assignment 1: Responsive Dashboard UI'], $lId, '94', 'Superb responsive UX and performance.', '2026-09-09 16:00:00']);
        $subIns->execute([$assignmentIds['Assignment 1: Requirements Specification Document'], $lId, '90', 'Exemplary SRS specifications.', '2026-09-14 18:00:00']);

        $qResIns->execute([$quizIds['Quiz 1: Relational Schema & Normalization'], $lId, 90, '2026-08-25 10:00:00']);
        $qResIns->execute([$quizIds['Quiz 2: Complex SQL Queries & Joins'],       $lId, 92, '2026-09-12 10:00:00']);
        $qResIns->execute([$quizIds['Quiz 3: Indexing and Transactions'],          $lId, 95, '2026-09-28 10:00:00']);
        $qResIns->execute([$quizIds['Quiz 1: HTML5 & Modern CSS Grid'],           $lId, 92, '2026-08-28 14:00:00']);
        $qResIns->execute([$quizIds['Quiz 2: JavaScript ES6 & DOM Manipulation'],  $lId, 88, '2026-09-18 14:00:00']);

        $startDateLow = new DateTime('2026-08-10');
        for ($i = 0; $i < 30; $i++) {
            $dStr = $startDateLow->format('Y-m-d');
            $cSel = ($i % 3 === 0) ? $dbCourseId : (($i % 3 === 1) ? $webCourseId : $seCourseId);
            $st = ($i === 15) ? 'late' : 'present';
            $attIns->execute([$cSel, $lId, $dStr, $st, $dStr . ' 09:00:00']);
            $startDateLow->modify('+1 day');
            if ($startDateLow->format('N') >= 6) $startDateLow->modify('+2 days');
        }
    }

    // 11. Seed Interventions for Juan Dela Cruz (as explicitly requested in prompt)
    $deanUserId = 34; // Maria Manalo
    $pdo->exec("DELETE FROM student_interventions WHERE student_id = $juanId");
    $invStmt = $pdo->prepare("
        INSERT INTO student_interventions (student_id, assigned_by, intervention_type, status, scheduled_date, completed_date, notes, grade_before, grade_after_2wk, grade_current, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // Academic Advising: Completed
    $invStmt->execute([
        $juanId,
        $deanUserId,
        'Academic Advising',
        'completed',
        '2026-10-01',
        '2026-10-06',
        'Student advised to attend additional tutoring sessions and consult with Database Management instructor.',
        72.0,
        76.0,
        81.0,
        '2026-10-01 09:30:00'
    ]);
    // Tutoring: In Progress
    $invStmt->execute([
        $juanId,
        $deanUserId,
        'Tutoring',
        'in_progress',
        '2026-10-08',
        null,
        'Weekly peer tutoring arranged with IT Department Honor Society for IT301 and IT303.',
        72.0,
        76.0,
        81.0,
        '2026-10-03 14:00:00'
    ]);

    // 12. Seed Historical Risk Assessments for Juan Dela Cruz (as explicitly requested in prompt)
    $pdo->exec("DELETE FROM risk_assessments WHERE student_id = $juanId");
    $riskHistStmt = $pdo->prepare("
        INSERT INTO risk_assessments (
            student_id, department_id, assessed_at, overall_score, risk_level,
            grade_avg, previous_grade_avg, grade_change_pct, quiz_avg, attendance_rate,
            absent_count, late_count, present_count, failing_courses_count, trend_direction,
            reasons, recommended_intervention
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?
        )
    ");

    $juanHistory = [
        [
            'date'   => '2026-09-01 08:00:00',
            'score'  => 45,
            'level'  => 'moderate',
            'grade'  => 78.0,
            'prev'   => 80.0,
            'change' => -2.0,
            'quiz'   => 70.0,
            'att'    => 84.0,
            'abs'    => 4,
            'late'   => 1,
            'pres'   => 21,
            'fail'   => 0,
            'trend'  => 'stable',
            'reason' => 'Low quiz scores in IT301 (70%). Overall grade is moderate.',
            'rec'    => 'Study Plan, Tutoring',
        ],
        [
            'date'   => '2026-09-15 08:00:00',
            'score'  => 78,
            'level'  => 'at_risk',
            'grade'  => 72.0,
            'prev'   => 80.0,
            'change' => -8.0,
            'quiz'   => 65.0,
            'att'    => 78.0,
            'abs'    => 9,
            'late'   => 3,
            'pres'   => 32,
            'fail'   => 2,
            'trend'  => 'declining',
            'reason' => "Overall grade (72%) is below threshold (75%).\nAttendance (78%) below threshold (80%).\nAcademic performance declined by 8%.\n2 courses below passing threshold.",
            'rec'    => 'Academic Advising, Tutoring, Attendance Intervention',
        ],
        [
            'date'   => '2026-10-01 08:00:00',
            'score'  => 68,
            'level'  => 'at_risk',
            'grade'  => 76.0,
            'prev'   => 72.0,
            'change' => 4.0,
            'quiz'   => 75.0,
            'att'    => 81.0,
            'abs'    => 10,
            'late'   => 4,
            'pres'   => 36,
            'fail'   => 1,
            'trend'  => 'improving',
            'reason' => "Improving after advising. 1 course still under passing threshold.",
            'rec'    => 'Tutoring, Academic Monitoring',
        ],
        [
            'date'   => '2026-10-06 00:00:00',
            'score'  => 75,
            'level'  => 'at_risk',
            'grade'  => 72.0,
            'prev'   => 80.0,
            'change' => -8.0,
            'quiz'   => 73.3,
            'att'    => 78.0,
            'abs'    => 11,
            'late'   => 4,
            'pres'   => 39,
            'fail'   => 2,
            'trend'  => 'declining',
            'reason' => "Overall grade is below configured threshold (75%).\nQuiz average is below configured threshold (75%).\nAttendance is below configured threshold (80%).\nAcademic performance declined by 8% compared with previous period.",
            'rec'    => 'Academic Advising, Tutoring, Attendance Intervention',
        ]
    ];

    foreach ($juanHistory as $jh) {
        $riskHistStmt->execute([
            $juanId,
            $deptId,
            $jh['date'],
            $jh['score'],
            $jh['level'],
            $jh['grade'],
            $jh['prev'],
            $jh['change'],
            $jh['quiz'],
            $jh['att'],
            $jh['abs'],
            $jh['late'],
            $jh['pres'],
            $jh['fail'],
            $jh['trend'],
            $jh['reason'],
            $jh['rec'],
        ]);
    }

    $pdo->commit();
    echo "Academic records seeded successfully!\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Seeding error: " . $e->getMessage() . " on line " . $e->getLine() . "\n";
    exit(1);
}
