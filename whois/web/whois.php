<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

if ($_POST['captcha'] !== $_SESSION['captcha']) {
    die('Captcha verification failed');
}

$domain = $_POST['domain'];
$whoisServer = 'ENTER_WHOIS_IP_HERE';

$output = '';
$socket = fsockopen($whoisServer, 43, $errno, $errstr, 30);

if (!$socket) {
    echo json_encode(['error' => "Error connecting to the Whois server."]);
    exit;
}
    
fwrite($socket, $domain . "\r\n");
while (!feof($socket)) {
    $output .= fgets($socket);
}
fclose($socket);

// Invalidate the current captcha
unset($_SESSION['captcha']);

echo $output;