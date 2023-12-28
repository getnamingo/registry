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
$type = $_POST['type'];
$whoisServer = 'whois.example.com';
$rdapServer = 'https://rdap.example.com/domain/';

$sanitized_type = filter_var($type, FILTER_SANITIZE_STRING);

if ($sanitized_type === 'whois' || $sanitized_type === 'rdap') {
    $type = $sanitized_type;
} else {
    $type = null; // or throw new Exception("Invalid input");
}

if ($type === 'whois') {
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
} elseif ($type === 'rdap') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $rdapServer . $domain);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    // Execute cURL session and close it
    $output = curl_exec($ch);
    curl_close($ch);

    // Check for errors
    if (!$output) {
        echo json_encode(['error' => 'Error fetching RDAP data.']);
        exit;
    }
}

echo $output;