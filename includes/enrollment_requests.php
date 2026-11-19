<?php

function ensureEnrollmentRequestsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS enrollment_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            subject_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY unique_student_subject_request (student_id, subject_id),
            INDEX idx_subject_status (subject_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function requestEnrollment(PDO $pdo, int $studentId, int $subjectId): string
{
    $enrollment = $pdo->prepare('SELECT id FROM enrollments WHERE student_id = ? AND subject_id = ?');
    $enrollment->execute([$studentId, $subjectId]);
    if ($enrollment->fetch()) {
        return 'already_enrolled';
    }

    $existingRequest = $pdo->prepare('SELECT status FROM enrollment_requests WHERE student_id = ? AND subject_id = ?');
    $existingRequest->execute([$studentId, $subjectId]);
    if ($existingRequest->fetchColumn() === 'pending') {
        return 'pending';
    }

    $request = $pdo->prepare("
        INSERT INTO enrollment_requests (student_id, subject_id, status, requested_at, reviewed_at)
        VALUES (?, ?, 'pending', NOW(), NULL)
        ON DUPLICATE KEY UPDATE status = 'pending', requested_at = NOW(), reviewed_at = NULL
    ");
    $request->execute([$studentId, $subjectId]);
    return 'requested';
}
