<?php
require_once 'config/database.php';

$schema = file_get_contents('schema.sql');
$queries = array_filter(array_map('trim', explode(';', $schema)));

try {
    foreach ($queries as $query) {
        if (!empty($query)) {
            $pdo->exec($query);
        }
    }
    echo "✓ Database schema updated successfully!\n";
    echo "✓ Attendance tables created!\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
