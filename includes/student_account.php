<?php

function validateStudentAccountInput(PDO $pdo, array $data, string $status = 'active'): array
{
    $fields = [
        'student_id' => trim($data['student_id'] ?? ''),
        'first_name' => trim($data['first_name'] ?? ''),
        'last_name' => trim($data['last_name'] ?? ''),
        'middle_initial' => trim($data['middle_initial'] ?? ''),
        'email' => trim($data['email'] ?? ''),
        'contact_number' => trim($data['contact_number'] ?? ''),
        'date_of_birth' => trim($data['date_of_birth'] ?? ''),
        'gender' => trim($data['gender'] ?? ''),
        'program_id' => (int)($data['program'] ?? $data['program_id'] ?? 0),
        'department_id' => (int)($data['department'] ?? $data['department_id'] ?? 0),
        'year_level' => trim($data['year_level'] ?? ''),
        'password' => $data['password'] ?? '',
        'confirm_password' => $data['confirm_password'] ?? ''
    ];

    foreach (['student_id', 'first_name', 'last_name', 'email', 'contact_number', 'date_of_birth', 'gender', 'year_level', 'password', 'confirm_password'] as $required) {
        if ($fields[$required] === '') {
            throw new InvalidArgumentException('Please complete all required student information.');
        }
    }

    if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid email address.');
    }
    if (strlen($fields['student_id']) > 50 || strlen($fields['first_name']) > 100 || strlen($fields['last_name']) > 100 || strlen($fields['middle_initial']) > 5 || strlen($fields['email']) > 255 || strlen($fields['contact_number']) > 20) {
        throw new InvalidArgumentException('One or more student information fields exceed the allowed length.');
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('Please select a valid account status.');
    }

    $password = $fields['password'];
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters.');
    }
    if ($password !== $fields['confirm_password']) {
        throw new InvalidArgumentException('Passwords do not match.');
    }

    $date = DateTime::createFromFormat('!Y-m-d', $fields['date_of_birth']);
    $dateErrors = DateTime::getLastErrors();
    if (!$date || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $date->format('Y-m-d') !== $fields['date_of_birth']) {
        throw new InvalidArgumentException('Invalid date of birth.');
    }
    $today = new DateTime('today');
    if ($date > $today || $today->diff($date)->y < 13) {
        throw new InvalidArgumentException('You must be at least 13 years old to register.');
    }
    if (!in_array($fields['gender'], ['Male', 'Female', 'Other', 'Prefer not to say'], true)) {
        throw new InvalidArgumentException('Please select a valid gender.');
    }
    if (!in_array($fields['year_level'], ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Other'], true)) {
        throw new InvalidArgumentException('Please select a valid year level.');
    }
    if ($fields['program_id'] <= 0 || $fields['department_id'] <= 0) {
        throw new InvalidArgumentException('Please select a valid program for the selected department.');
    }

    $stmt = $pdo->prepare("SELECT p.id FROM programs p JOIN departments d ON d.id = p.department_id WHERE p.id = ? AND p.department_id = ? AND p.status = 'active' AND d.status = 'active'");
    $stmt->execute([$fields['program_id'], $fields['department_id']]);
    if (!$stmt->fetch()) {
        throw new InvalidArgumentException('Please select a valid program for the selected department.');
    }

    $stmt = $pdo->prepare('SELECT id FROM student_profiles WHERE student_id_number = ?');
    $stmt->execute([$fields['student_id']]);
    if ($stmt->fetch()) {
        throw new InvalidArgumentException('Student ID already exists.');
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE student_id_number = ?');
    $stmt->execute([$fields['student_id']]);
    if ($stmt->fetch()) {
        throw new InvalidArgumentException('Student ID already exists.');
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$fields['email']]);
    if ($stmt->fetch()) {
        throw new InvalidArgumentException('Email address is already registered.');
    }

    return $fields;
}

function createStudentAccount(PDO $pdo, array $data, string $status = 'active', ?string $profilePicture = null): array
{
    $student = validateStudentAccountInput($pdo, $data, $status);
    $fullName = $student['first_name'];
    if ($student['middle_initial'] !== '') $fullName .= ' ' . $student['middle_initial'];
    $fullName .= ' ' . $student['last_name'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, department_id, student_id_number, status, created_at, updated_at) VALUES (?, ?, ?, 'student', ?, ?, ?, NOW(), NOW())");
        $stmt->execute([
            $fullName,
            $student['email'],
            password_hash($student['password'], PASSWORD_DEFAULT),
            $student['department_id'],
            $student['student_id'],
            $status
        ]);
        $userId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO student_profiles (student_id, student_id_number, first_name, last_name, middle_initial, date_of_birth, gender, email, contact_number, program_id, department_id, year_level, enrollment_status, profile_picture, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([
            $userId,
            $student['student_id'],
            $student['first_name'],
            $student['last_name'],
            $student['middle_initial'] ?: null,
            $student['date_of_birth'],
            $student['gender'],
            $student['email'],
            $student['contact_number'],
            $student['program_id'],
            $student['department_id'],
            $student['year_level'],
            $status === 'active' ? 'Active' : 'Inactive',
            $profilePicture
        ]);

        $stmt = $pdo->prepare('SELECT name FROM programs WHERE id = ?');
        $stmt->execute([$student['program_id']]);
        $programName = $stmt->fetchColumn();
        $pdo->commit();

        return [
            'user_id' => $userId,
            'student_id' => $student['student_id'],
            'full_name' => $fullName,
            'email' => $student['email'],
            'program' => $programName,
            'year_level' => $student['year_level']
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && $error->getCode() === '23000') {
            $message = $error->getMessage();
            if (strpos($message, 'student_id_number') !== false) {
                throw new InvalidArgumentException('Student ID already exists.');
            }
            if (strpos($message, 'email') !== false) {
                throw new InvalidArgumentException('Email address is already registered.');
            }
        }
        throw $error;
    }
}