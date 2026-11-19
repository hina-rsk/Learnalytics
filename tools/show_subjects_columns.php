<?php
require_once __DIR__ . '/../config/database.php';

try {
    $cols = $pdo->query('SHOW COLUMNS FROM subjects')->fetchAll();
    foreach ($cols as $c) {
        echo $c['Field'] . "\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>
