<?php
require_once __DIR__ . '/../config/database.php';
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM programs");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns in programs table:\n";
    foreach ($cols as $c) {
        echo $c['Field'] . "\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
