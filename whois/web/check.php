<?php
session_start();
$c = require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

if ($_POST['captcha'] !== $_SESSION['captcha']) {
    echo json_encode(['error' => 'Captcha verification failed.']);
    exit;
}

$domain = $_POST['domain'];
$type = $_POST['type'];
$whoisServer = $c['whois_url'];
$rdapServer = 'https://' . $c['rdap_url'] . '/domain/';

$sanitized_domain = filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);

if ($sanitized_domain) {
    $domain = $sanitized_domain;
} else {
    echo json_encode(['error' => 'Invalid domain.']);
    exit;
}

$sanitized_type = filter_var($type, FILTER_SANITIZE_STRING);

if ($sanitized_type === 'whois' || $sanitized_type === 'rdap') {
    $type = $sanitized_type;
} else {
    echo json_encode(['error' => 'Invalid input.']);
    exit;
}

if ($type === 'whois') {
    $output = '';
    $socket = fsockopen($whoisServer, 43, $errno, $errstr, 30);

    if (!$socket) {
        echo json_encode(['error' => "Error fetching WHOIS data."]);
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

    $output = curl_exec($ch);
    curl_close($ch);

    if (!$output) {
        echo json_encode(['error' => 'Error fetching RDAP data.']);
        exit;
    }
}

echo $output;