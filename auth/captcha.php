<?php
session_start();

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$purpose = ($_GET['purpose'] ?? 'registration') === 'login' ? 'login' : 'registration';
$sessionKey = $purpose === 'login' ? 'login_captcha' : 'student_registration_captcha';

$characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
$answer = '';
for ($i = 0; $i < 6; $i++) {
    $answer .= $characters[random_int(0, strlen($characters) - 1)];
}

$_SESSION[$sessionKey] = [
    'answer' => $answer,
    'created_at' => time()
];

$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="210" height="58" viewBox="0 0 210 58" role="img" aria-label="CAPTCHA challenge">';
$svg .= '<rect width="210" height="58" rx="8" fill="#eff6ff"/>';

for ($i = 0; $i < 5; $i++) {
    $x1 = random_int(0, 210);
    $y1 = random_int(0, 58);
    $x2 = random_int(0, 210);
    $y2 = random_int(0, 58);
    $svg .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="#93c5fd" stroke-width="1"/>';
}

for ($i = 0; $i < strlen($answer); $i++) {
    $x = 23 + ($i * 31);
    $y = random_int(34, 43);
    $rotation = random_int(-12, 12);
    $character = htmlspecialchars($answer[$i], ENT_QUOTES, 'UTF-8');
    $svg .= '<text x="' . $x . '" y="' . $y . '" transform="rotate(' . $rotation . ' ' . $x . ' ' . $y . ')" fill="#1e3a8a" font-family="Arial,sans-serif" font-size="27" font-weight="700">' . $character . '</text>';
}

$svg .= '</svg>';
echo $svg;
