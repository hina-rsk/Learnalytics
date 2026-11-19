<?php
require_once __DIR__ . '/../config/database.php';
foreach (['assignments', 'submissions', 'submission_overrides'] as $tbl) {
    echo "--- TABLE: $tbl ---\n";
    $cols = $pdo->query("SHOW COLUMNS FROM $tbl")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "  {$col['Field']} ({$col['Type']}) Default: " . var_export($col['Default'], true) . " Null: {$col['Null']}\n";
    }
}

$rows = $pdo->query("SELECT id, title, allow_resubmission, max_resubmissions FROM assignments")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
