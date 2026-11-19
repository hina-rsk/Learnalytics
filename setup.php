<?php
// This script creates the database and imports the schema
// Uses centralized database configuration

require_once __DIR__ . '/config/database.php';

try {
    // Read and execute the schema.sql file
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    
    // Split the schema into individual statements and execute them
    $statements = array_filter(array_map('trim', explode(';', $schema)));
    
    $count = 0;
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
            $count++;
        }
    }
    
    echo "<p style='color: green;'>✓ Successfully imported " . $count . " tables!</p>";
    echo "<p style='color: blue;'><strong>Database setup complete!</strong></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
