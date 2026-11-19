<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SELECT id, name, role, department_id FROM users WHERE role = 'dean'");
$deans = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Deans:\n";
print_r($deans);

if (!empty($deans)) {
    $dean = $deans[0];
    $dept_id = (int)$dean['department_id'];
    echo "Department ID: $dept_id\n";

    // Test announcements query
    try {
        $ann_stmt = $pdo->prepare("
            SELECT n.message, MIN(n.created_at) AS created_at, COUNT(*) as recipients
            FROM notifications n
            WHERE n.message LIKE '[Dept Announcement]%'
              AND n.user_id IN (
                  SELECT DISTINCT student_id FROM student_profiles WHERE department_id = ?
                  UNION
                  SELECT DISTINCT instructor_id FROM subjects WHERE department_id = ?
              )
            GROUP BY n.message, DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i')
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $ann_stmt->execute([$dept_id, $dept_id]);
        $announcements = $ann_stmt->fetchAll();
        echo "Announcements query succeeded. Count: " . count($announcements) . "\n";
    } catch (Exception $e) {
        echo "Announcements query failed: " . $e->getMessage() . "\n";
    }

    // Test dept trends query
    try {
        $deptTrendsStmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(assessed_at, '%b %d') AS label_date,
                ROUND(AVG(grade_avg), 1) AS dept_grade,
                ROUND(AVG(quiz_avg), 1) AS dept_quiz,
                ROUND(AVG(attendance_rate), 1) AS dept_att,
                SUM(risk_level = 'at_risk') AS dept_at_risk
            FROM risk_assessments
            WHERE (department_id = ? OR department_id IS NULL)
            GROUP BY DATE_FORMAT(assessed_at, '%Y-%m-%d')
            ORDER BY assessed_at ASC
        ");
        $deptTrendsStmt->execute([$dept_id]);
        $trends = $deptTrendsStmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Trends query succeeded. Count: " . count($trends) . "\n";
        print_r($trends);
    } catch (Exception $e) {
        echo "Trends query failed: " . $e->getMessage() . "\n";
    }

    // Check notifications table
    $notifs = $pdo->query("SELECT * FROM notifications ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "Latest notifications in DB:\n";
    print_r($notifs);
}
