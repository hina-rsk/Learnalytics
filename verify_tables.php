<?php
require_once 'config/database.php';

$result = $pdo->query('SHOW TABLES LIKE "attendance%"')->fetchAll(PDO::FETCH_COLUMN);
echo "✓ Attendance tables created: " . count($result) . "\n";
foreach($result as $table) {
    echo "  - " . $table . "\n";
}
?>

