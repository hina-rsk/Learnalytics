<?php
$lines = file('dean_dashboard.php');
$tabRanges = [
    'overview' => [825, 1005],
    'students' => [1006, 1105],
    'faculty' => [1106, 1148],
    'programs' => [1149, 1262],
    'analytics' => [1263, 1300],
    'announcements' => [1301, 1370],
    'reports' => [1371, 1412]
];

foreach ($tabRanges as $name => $range) {
    $slice = array_slice($lines, $range[0] - 1, $range[1] - $range[0] + 1);
    $text = implode("", $slice);
    $opens = substr_count($text, '<div');
    $closes = substr_count($text, '</div');
    echo "Tab $name (lines {$range[0]}-{$range[1]}): Opens=$opens, Closes=$closes, Diff=" . ($opens - $closes) . "\n";
}
