<?php
$lines = file('dean_dashboard.php');
foreach ($lines as $i => $line) {
    if (preg_match('/<div[^>]*id=["\']tab-/', $line)) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
