<?php
/**
 * Quiz System Enhancement Migration Script
 * This script applies the enhanced quiz system database changes
 */

require_once 'config/database.php';

echo "Running Quiz System Enhancement Migration...\n\n";

try {
    // Read the migration file
    $migrationFile = 'migrations/enhance_quiz_system.sql';
    if (!file_exists($migrationFile)) {
        die("Error: Migration file not found: $migrationFile\n");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Remove comments
    $sql = preg_replace('/--.*$/m', '', $sql);
    
    // Split by semicolons but respect content within parentheses
    $statements = [];
    $current = '';
    $parenDepth = 0;
    $inString = false;
    $stringChar = '';
    
    for ($i = 0; $i < strlen($sql); $i++) {
        $char = $sql[$i];
        
        if (!$inString) {
            if ($char === '(') {
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth--;
            } elseif ($char === ';' && $parenDepth === 0) {
                $statements[] = trim($current);
                $current = '';
                continue;
            } elseif ($char === '"' || $char === "'") {
                $inString = true;
                $stringChar = $char;
            }
        } else {
            if ($char === $stringChar) {
                $inString = false;
            }
        }
        
        $current .= $char;
    }
    
    // Add any remaining statement
    if (trim($current)) {
        $statements[] = trim($current);
    }
    
    // Filter out empty statements
    $statements = array_filter($statements, function($stmt) {
        $clean = trim($stmt);
        return !empty($clean) && 
               !preg_match('/^\/\*/', $clean) &&
               strlen($clean) > 5;
    });
    
    $successCount = 0;
    $skipCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;
        
        try {
            $pdo->exec($statement);
            
            $upperStmt = strtoupper(trim($statement));
            if (strpos($upperStmt, 'ALTER TABLE') === 0) {
                echo "[OK] Table altered\n";
            } elseif (strpos($upperStmt, 'CREATE TABLE') === 0) {
                echo "[OK] Table created\n";
            } elseif (strpos($upperStmt, 'CREATE OR REPLACE VIEW') === 0 || strpos($upperStmt, 'CREATE VIEW') === 0) {
                echo "[OK] View created\n";
            } elseif (strpos($upperStmt, 'INSERT INTO') === 0) {
                echo "[OK] Data inserted\n";
            } elseif (strpos($upperStmt, 'CREATE INDEX') === 0) {
                echo "[OK] Index created\n";
            } else {
                echo "[OK] Statement executed\n";
            }
            $successCount++;
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();
            
            if (strpos($errorMsg, 'Duplicate column name') !== false ||
                strpos($errorMsg, 'already exists') !== false ||
                strpos($errorMsg, 'Duplicate key name') !== false ||
                strpos($errorMsg, "Table '") !== false && strpos($errorMsg, "already exists") !== false) {
                echo "[SKIP] Already exists\n";
                $skipCount++;
            } else {
                echo "[ERROR] " . substr($errorMsg, 0, 100) . "...\n";
                $errorCount++;
            }
        }
    }
    
    echo "\n";
    echo "========================================\n";
    echo "Migration Summary:\n";
    echo "  Successful: $successCount\n";
    echo "  Skipped:    $skipCount\n";
    echo "  Errors:     $errorCount\n";
    echo "========================================\n\n";
    
    if ($errorCount > 0) {
        echo "Some errors occurred. Please review the output above.\n";
        echo "The system may still work with fallback mode.\n";
    } else {
        echo "Quiz System Enhancement migration completed successfully!\n";
        echo "The enhanced quiz features are now available.\n";
    }
    
} catch (Exception $e) {
    die("Fatal error: " . $e->getMessage() . "\n");
}
?>