<?php
require 'config/database.php';
require 'instructor/analytics_functions.php';

echo "=== Testing getTeacherNormalizedAssessments for instructor_id=47 ===\n";
try {
    $result = getTeacherNormalizedAssessments($pdo, 47);
    echo "Records returned: " . count($result) . "\n";
    if (!empty($result)) {
        echo "Sample:\n";
        print_r($result[0]);
    }
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== Testing getPerformanceStatusInfo ===\n";
$info = getPerformanceStatusInfo(74.4, 75.0);
echo "Label: " . $info['label'] . "\n";
echo "Has text_class key: " . (isset($info['text_class']) ? 'YES' : 'NO') . "\n";
echo "Keys available: " . implode(', ', array_keys($info)) . "\n";
