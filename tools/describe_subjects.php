<?php
require_once __DIR__ . '/../config/database.php';
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM subjects");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns in subjects table:\n";
    foreach ($cols as $c) {
        echo $c['Field'] . "\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
