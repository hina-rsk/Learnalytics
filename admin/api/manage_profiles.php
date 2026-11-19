<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die('Unauthorized access');
}

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'message' => ''];

try {
    if ($action === 'populate_samples') {
        // Populate student profiles with sample/random data
        $yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
        $statuses = ['Active', 'Inactive', 'Graduated', 'Suspended', 'Dropped'];
        
        // Get all students without profiles
        $stmt = $pdo->prepare("
            SELECT u.id, u.name 
            FROM users u 
            LEFT JOIN student_profiles sp ON u.id = sp.student_id 
            WHERE u.role = 'student' 
            AND sp.id IS NULL
        ");
        $stmt->execute();
        $students = $stmt->fetchAll();
        
        $count = 0;
        foreach ($students as $student) {
            $yearLevel = $yearLevels[array_rand($yearLevels)];
            $status = $statuses[array_rand($statuses)];
            
            $stmt = $pdo->prepare("
                INSERT INTO student_profiles 
                (student_id, student_id_number, year_level, enrollment_status)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                student_id_number = VALUES(student_id_number)
            ");
            $stmt->execute([
                $student['id'],
                'STU' . str_pad($student['id'], 4, '0', STR_PAD_LEFT),
                $yearLevel,
                $status
            ]);
            $count++;
        }
        
        $response['success'] = true;
        $response['message'] = "Populated $count student profiles with sample data";
        
    } elseif ($action === 'update_status') {
        // Update a specific student's status
        $studentId = (int)($_POST['student_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        $validStatuses = ['Active', 'Inactive', 'Graduated', 'Suspended', 'Dropped'];
        
        if ($studentId > 0 && in_array($status, $validStatuses)) {
            $stmt = $pdo->prepare("
                UPDATE student_profiles 
                SET enrollment_status = ? 
                WHERE student_id = ?
            ");
            $stmt->execute([$status, $studentId]);
            
            $response['success'] = true;
            $response['message'] = "Student status updated to: $status";
        } else {
            $response['message'] = 'Invalid student ID or status';
        }
        
    } elseif ($action === 'update_year_level') {
        // Update a specific student's year level
        $studentId = (int)($_POST['student_id'] ?? 0);
        $yearLevel = $_POST['year_level'] ?? '';
        
        $validYears = ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Other'];
        
        if ($studentId > 0 && in_array($yearLevel, $validYears)) {
            $stmt = $pdo->prepare("
                UPDATE student_profiles 
                SET year_level = ? 
                WHERE student_id = ?
            ");
            $stmt->execute([$yearLevel, $studentId]);
            
            $response['success'] = true;
            $response['message'] = "Student year level updated to: $yearLevel";
        } else {
            $response['message'] = 'Invalid student ID or year level';
        }
        
    } elseif ($action === 'link_program') {
        // Link a student to a program
        $studentId = (int)($_POST['student_id'] ?? 0);
        $programId = (int)($_POST['program_id'] ?? 0);
        
        if ($studentId > 0 && $programId > 0) {
            // Verify program exists
            $stmt = $pdo->prepare("SELECT id FROM programs WHERE id = ?");
            $stmt->execute([$programId]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare("
                    UPDATE student_profiles 
                    SET program_id = ? 
                    WHERE student_id = ?
                ");
                $stmt->execute([$programId, $studentId]);
                
                $response['success'] = true;
                $response['message'] = "Student linked to program";
            } else {
                $response['message'] = 'Program not found';
            }
        } else {
            $response['message'] = 'Invalid student ID or program ID';
        }
    }
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
