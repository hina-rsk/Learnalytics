<?php
require_once 'config/database.php';

$migrationPath = __DIR__ . '/migrations/quiz_builder.sql';
if (!is_file($migrationPath)) {
    fwrite(STDERR, "Quiz builder migration file was not found.\n");
    exit(1);
}

$migration = file_get_contents($migrationPath);
if ($migration === false) {
    fwrite(STDERR, "Quiz builder migration file could not be read.\n");
    exit(1);
}

$statements = preg_split('/;\s*(?:\r?\n|$)/', $migration);
try {
    foreach ($statements as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    echo "Quiz builder migration applied successfully.\n";
} catch (PDOException $exception) {
    error_log('Quiz builder migration failed: ' . $exception->getMessage());
    fwrite(STDERR, "Quiz builder migration failed. Check the database schema and retry.\n");
    exit(1);
}
