<?php
require_once 'config/database.php';

echo "Running database migrations...\n\n";

// 1. Add class_code column to subjects table
try {
    $pdo->exec("ALTER TABLE subjects ADD COLUMN class_code VARCHAR(10) UNIQUE DEFAULT NULL");
    echo "[OK] Added class_code column to subjects table.\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "[SKIP] class_code column already exists.\n";
    } else {
        echo "[ERROR] class_code: " . $e->getMessage() . "\n";
    }
}

// 2. Ensure subjects table has department_id column and FK
try {
    $hasDept = (bool)$pdo->query("SHOW COLUMNS FROM subjects LIKE 'department_id'")->fetch();
    if (!$hasDept) {
        $pdo->exec("ALTER TABLE subjects ADD COLUMN department_id INT DEFAULT NULL");
        echo "[OK] Added department_id column to subjects table.\n";
    } else {
        echo "[SKIP] department_id column already exists on subjects.\n";
    }

    // Add foreign key if not present
    $fk = $pdo->prepare("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'department_id' AND REFERENCED_TABLE_NAME = 'departments'");
    $fk->execute();
    if (!$fk->fetch()) {
        // add FK (use a generated name to avoid conflicts)
        try {
            $pdo->exec("ALTER TABLE subjects ADD CONSTRAINT fk_subjects_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL");
            echo "[OK] Added foreign key fk_subjects_department -> departments(id).\n";
        } catch (Exception $e) {
            echo "[ERROR] adding FK department_id: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[SKIP] Foreign key for subjects.department_id already exists.\n";
    }
} catch (Exception $e) {
    echo "[ERROR] subjects department check: " . $e->getMessage() . "\n";
}

// 3. Ensure programs table has department_id column and FK
try {
    $hasProgDept = (bool)$pdo->query("SHOW COLUMNS FROM programs LIKE 'department_id'")->fetch();
    if (!$hasProgDept) {
        $pdo->exec("ALTER TABLE programs ADD COLUMN department_id INT DEFAULT NULL");
        echo "[OK] Added department_id column to programs table.\n";
    } else {
        echo "[SKIP] department_id column already exists on programs.\n";
    }

    $fkprog = $pdo->prepare("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'department_id' AND REFERENCED_TABLE_NAME = 'departments'");
    $fkprog->execute();
    if (!$fkprog->fetch()) {
        try {
            $pdo->exec("ALTER TABLE programs ADD CONSTRAINT fk_programs_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL");
            echo "[OK] Added foreign key fk_programs_department -> departments(id).\n";
        } catch (Exception $e) {
            echo "[ERROR] adding FK programs.department_id: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[SKIP] Foreign key for programs.department_id already exists.\n";
    }
} catch (Exception $e) {
    echo "[ERROR] programs department check: " . $e->getMessage() . "\n";
}

// 4. Ensure user and student profile fields support complete student accounts
try {
    $ensureColumn = function ($table, $column, $definition) use ($pdo) {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            echo "[OK] Added {$table}.{$column}.\n";
        }
    };

    $ensureColumn('users', 'department_id', 'INT DEFAULT NULL');
    $ensureColumn('users', 'student_id_number', 'VARCHAR(50) DEFAULT NULL');

    $roleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    preg_match_all("/'((?:\\\\.|[^'])*)'/", $roleColumn['Type'] ?? '', $roleMatches);
    $roleValues = array_unique(array_merge($roleMatches[1] ?? [], ['admin', 'instructor', 'student', 'dean', 'faculty', 'staff']));
    if ($roleColumn && count($roleValues) !== count($roleMatches[1] ?? [])) {
        $roleEnum = implode(', ', array_map(function ($value) use ($pdo) {
            return $pdo->quote(stripslashes($value));
        }, $roleValues));
        $pdo->exec("ALTER TABLE users MODIFY role ENUM({$roleEnum}) DEFAULT 'student'");
        echo "[OK] Added dean to users.role.\n";
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS student_profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL UNIQUE,
        student_id_number VARCHAR(50) NULL,
        first_name VARCHAR(100) NULL,
        last_name VARCHAR(100) NULL,
        middle_initial VARCHAR(5) NULL,
        date_of_birth DATE NULL,
        gender VARCHAR(50) NULL,
        email VARCHAR(255) NULL,
        contact_number VARCHAR(20) NULL,
        program_id INT NULL,
        department_id INT NULL,
        section_id INT NULL,
        advisor_id INT NULL,
        year_level ENUM('1st Year', '2nd Year', '3rd Year', '4th Year', 'Other') NULL,
        enrollment_status ENUM('Active', 'Inactive', 'Graduated', 'Suspended', 'Dropped') DEFAULT 'Active',
        bio TEXT NULL,
        profile_picture VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_student_profile_number (student_id_number),
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
    )");

    $profileColumns = [
        'student_id_number' => 'VARCHAR(50) NULL',
        'first_name' => 'VARCHAR(100) NULL',
        'last_name' => 'VARCHAR(100) NULL',
        'middle_initial' => 'VARCHAR(5) NULL',
        'date_of_birth' => 'DATE NULL',
        'gender' => 'VARCHAR(50) NULL',
        'email' => 'VARCHAR(255) NULL',
        'contact_number' => 'VARCHAR(20) NULL',
        'program_id' => 'INT NULL',
        'department_id' => 'INT NULL',
        'section_id' => 'INT NULL',
        'advisor_id' => 'INT NULL',
        'year_level' => "ENUM('1st Year', '2nd Year', '3rd Year', '4th Year', 'Other') NULL",
        'enrollment_status' => "ENUM('Active', 'Inactive', 'Graduated', 'Suspended', 'Dropped') DEFAULT 'Active'",
        'profile_picture' => 'VARCHAR(255) NULL',
        'bio' => 'TEXT NULL'
    ];
    foreach ($profileColumns as $column => $definition) {
        $ensureColumn('student_profiles', $column, $definition);
    }

    $uniqueId = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_profiles' AND COLUMN_NAME = 'student_id_number' AND NON_UNIQUE = 0");
    $uniqueId->execute();
    if (!$uniqueId->fetch()) {
        $pdo->exec("ALTER TABLE student_profiles ADD UNIQUE KEY uq_student_profile_number (student_id_number)");
        echo "[OK] Added unique student ID constraint.\n";
    }
} catch (Exception $e) {
    echo "[ERROR] student account schema: " . $e->getMessage() . "\n";
}

// 5. Create lesson_attachments table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lesson_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lesson_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
    )");
    echo "[OK] Created lesson_attachments table.\n";
} catch (Exception $e) {
    echo "[ERROR] lesson_attachments: " . $e->getMessage() . "\n";
}

// 6. Extend subjects for department-owned course catalog records.
try {
    $courseColumns = [
        'program_type' => 'VARCHAR(50) NULL',
        'units' => 'DECIMAL(6,2) NULL',
        'year_level' => 'VARCHAR(50) NULL',
        'semester' => 'VARCHAR(50) NULL'
    ];
    foreach ($courseColumns as $column => $definition) {
        $check = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = ?");
        $check->execute([$column]);
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE subjects ADD COLUMN `{$column}` {$definition}");
            echo "[OK] Added subjects.{$column}.\n";
        }
    }

    $pdo->exec("ALTER TABLE subjects MODIFY class_code VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE subjects MODIFY instructor_id INT NULL");
    $pdo->exec("ALTER TABLE subjects MODIFY status ENUM('active', 'inactive', 'completed', 'draft', 'archived') DEFAULT 'draft'");

    $pdo->exec("UPDATE subjects s JOIN programs p ON p.id = s.program_id SET s.department_id = p.department_id WHERE s.department_id IS NULL AND p.department_id IS NOT NULL");

    $programFkQuery = $pdo->prepare("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'department_id' AND REFERENCED_TABLE_NAME = 'departments'");
    $programFkQuery->execute();
    $programForeignKey = $programFkQuery->fetchColumn();
    if ($programForeignKey) {
        $pdo->exec("ALTER TABLE programs DROP FOREIGN KEY `{$programForeignKey}`");
    }
    $unassignedPrograms = (int)$pdo->query("SELECT COUNT(*) FROM programs WHERE department_id IS NULL")->fetchColumn();
    if ($unassignedPrograms === 0) {
        $pdo->exec("ALTER TABLE programs MODIFY department_id INT NOT NULL");
        echo "[OK] Enforced required program departments at the column level.\n";
    } else {
        echo "[WARN] {$unassignedPrograms} existing program(s) need a department assignment before enforcing NOT NULL.\n";
    }
    $pdo->exec("ALTER TABLE programs ADD CONSTRAINT fk_programs_department_required FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT");

    $fkQuery = $pdo->prepare("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'department_id' AND REFERENCED_TABLE_NAME = 'departments'");
    $fkQuery->execute();
    $foreignKey = $fkQuery->fetchColumn();
    if ($foreignKey) {
        $pdo->exec("ALTER TABLE subjects DROP FOREIGN KEY `{$foreignKey}`");
    }

    $unassignedCourses = (int)$pdo->query("SELECT COUNT(*) FROM subjects WHERE department_id IS NULL")->fetchColumn();
    if ($unassignedCourses === 0) {
        $pdo->exec("ALTER TABLE subjects MODIFY department_id INT NOT NULL");
        echo "[OK] Enforced required subject departments at the column level.\n";
    } else {
        echo "[WARN] {$unassignedCourses} existing subject(s) have no department; assign them before enforcing NOT NULL.\n";
    }

    $pdo->exec("ALTER TABLE subjects ADD CONSTRAINT fk_subjects_department_required FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT");

    $globalCodeIndexes = $pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' GROUP BY INDEX_NAME HAVING COUNT(*) = 1 AND SUM(COLUMN_NAME = 'class_code') = 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($globalCodeIndexes as $indexName) {
        $pdo->exec("ALTER TABLE subjects DROP INDEX `{$indexName}`");
    }

    $uniqueIndex = $pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND INDEX_NAME = 'uq_subject_department_code'")->fetchColumn();
    if (!$uniqueIndex) {
        $pdo->exec("ALTER TABLE subjects ADD UNIQUE KEY uq_subject_department_code (department_id, class_code)");
    }

    $pdo->exec("DROP TRIGGER IF EXISTS subjects_require_department_insert");
    $pdo->exec("CREATE TRIGGER subjects_require_department_insert BEFORE INSERT ON subjects FOR EACH ROW BEGIN IF NEW.department_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A department is required for every course'; END IF; END");
    $pdo->exec("DROP TRIGGER IF EXISTS subjects_require_department_update");
    $pdo->exec("CREATE TRIGGER subjects_require_department_update BEFORE UPDATE ON subjects FOR EACH ROW BEGIN IF NEW.department_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A department is required for every course'; END IF; END");
    $pdo->exec("DROP TRIGGER IF EXISTS programs_require_department_insert");
    $pdo->exec("CREATE TRIGGER programs_require_department_insert BEFORE INSERT ON programs FOR EACH ROW BEGIN IF NEW.department_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A department is required for every program'; END IF; END");
    $pdo->exec("DROP TRIGGER IF EXISTS programs_require_department_update");
    $pdo->exec("CREATE TRIGGER programs_require_department_update BEFORE UPDATE ON programs FOR EACH ROW BEGIN IF NEW.department_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A department is required for every program'; END IF; END");

    echo "[OK] Course catalog fields, department constraint, and department-scoped course codes are ready.\n";
} catch (Exception $e) {
    echo "[ERROR] course catalog schema: " . $e->getMessage() . "\n";
}

// 7. Create course-grade records for instructor-managed grading.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_grades (
        grade_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        instructor_id INT NOT NULL,
        subject_id INT NOT NULL,
        prelim_grade DECIMAL(5,2) DEFAULT NULL,
        midterm_grade DECIMAL(5,2) DEFAULT NULL,
        final_grade DECIMAL(5,2) DEFAULT NULL,
        final_average DECIMAL(5,2) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_student_grades_subject_student (subject_id, student_id),
        KEY idx_student_grades_student (student_id),
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Course grade records are ready.\n";
} catch (Exception $e) {
    echo "[ERROR] student_grades: " . $e->getMessage() . "\n";
}

// 8. Persist instructor annotations and assignment review feedback.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS assignment_annotations (
        annotation_id INT AUTO_INCREMENT PRIMARY KEY,
        submission_id INT NOT NULL,
        instructor_id INT NOT NULL,
        page_number INT NOT NULL DEFAULT 1,
        annotation_type VARCHAR(32) NOT NULL,
        annotation_content TEXT,
        annotation_data LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_assignment_annotations_submission (submission_id),
        FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
        FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS assignment_feedback (
        feedback_id INT AUTO_INCREMENT PRIMARY KEY,
        submission_id INT NOT NULL,
        instructor_id INT NOT NULL,
        comments TEXT,
        score DECIMAL(8,2) DEFAULT NULL,
        maximum_score DECIMAL(8,2) NOT NULL DEFAULT 100,
        status ENUM('under_review', 'checked', 'returned_for_revision') NOT NULL DEFAULT 'under_review',
        allow_resubmission BOOLEAN NOT NULL DEFAULT FALSE,
        reviewed_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_assignment_feedback_submission (submission_id),
        FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
        FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Assignment review and annotation tables are ready.\n";
} catch (Exception $e) {
    echo "[ERROR] assignment review schema: " . $e->getMessage() . "\n";
}

// 9. Create semesters and attach sections/courses without rewriting old records.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS semesters (
        semester_id INT AUTO_INCREMENT PRIMARY KEY,
        academic_year_id INT NOT NULL,
        semester_name VARCHAR(100) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        description TEXT,
        status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_semester_year_name (academic_year_id, semester_name),
        KEY idx_semester_dates (start_date, end_date),
        FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ensureSemesterColumn = function ($table) use ($pdo) {
        $columnCheck = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'semester_id'");
        $columnCheck->execute([$table]);
        if (!$columnCheck->fetch()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN semester_id INT DEFAULT NULL");
            echo "[OK] Added {$table}.semester_id.\n";
        }

        $foreignKeyCheck = $pdo->prepare("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'semester_id' AND REFERENCED_TABLE_NAME = 'semesters'");
        $foreignKeyCheck->execute([$table]);
        if (!$foreignKeyCheck->fetch()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `fk_{$table}_semester` FOREIGN KEY (semester_id) REFERENCES semesters(semester_id) ON DELETE SET NULL");
        }
    };

    $ensureSemesterColumn('sections');
    $ensureSemesterColumn('subjects');
    $pdo->exec('UPDATE subjects s INNER JOIN sections sec ON sec.id = s.section_id SET s.semester_id = sec.semester_id WHERE s.semester_id IS NULL AND sec.semester_id IS NOT NULL');
    echo "[OK] Semester relationships are ready; existing unassigned records were preserved.\n";
} catch (Exception $e) {
    echo "[ERROR] semester schema: " . $e->getMessage() . "\n";
}

// 10. Extend academic years without replacing historical records or IDs.
try {
    $ensureAcademicYearColumn = function ($column, $definition) use ($pdo) {
        $check = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_years' AND COLUMN_NAME = ?");
        $check->execute([$column]);
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE academic_years ADD COLUMN `{$column}` {$definition}");
            echo "[OK] Added academic_years.{$column}.\n";
        }
    };
    $ensureAcademicYearColumn('description', 'TEXT NULL');
    $ensureAcademicYearColumn('status', "ENUM('active', 'inactive') NOT NULL DEFAULT 'active'");
    $ensureAcademicYearColumn('updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

    $subjectYearColumn = $pdo->query("SHOW COLUMNS FROM subjects LIKE 'academic_year_id'");
    if (!$subjectYearColumn->fetch()) {
        $pdo->exec('ALTER TABLE subjects ADD COLUMN academic_year_id INT DEFAULT NULL');
        echo "[OK] Added subjects.academic_year_id.\n";
    }

    $subjectYearIndex = $pdo->query("SHOW INDEX FROM subjects WHERE Key_name = 'idx_subjects_academic_year'");
    if (!$subjectYearIndex->fetch()) {
        $pdo->exec('ALTER TABLE subjects ADD INDEX idx_subjects_academic_year (academic_year_id)');
    }

    $subjectYearForeignKey = $pdo->prepare("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'academic_year_id' AND REFERENCED_TABLE_NAME = 'academic_years'");
    $subjectYearForeignKey->execute();
    if (!$subjectYearForeignKey->fetch()) {
        $pdo->exec('ALTER TABLE subjects ADD CONSTRAINT fk_subjects_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL');
    }

    $pdo->exec('UPDATE subjects sub LEFT JOIN semesters sem ON sem.semester_id = sub.semester_id LEFT JOIN sections sec ON sec.id = sub.section_id SET sub.academic_year_id = COALESCE(sem.academic_year_id, sec.academic_year_id) WHERE sub.academic_year_id IS NULL AND (sem.academic_year_id IS NOT NULL OR sec.academic_year_id IS NOT NULL)');
    echo "[OK] Academic year management fields are ready.\n";
} catch (Exception $e) {
    echo "[ERROR] academic year schema: " . $e->getMessage() . "\n";
}

echo "\nMigration complete!\n";
?>
