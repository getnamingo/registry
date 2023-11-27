<?php
session_start();

// Check for a valid POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

// Captcha validation (if used)
if ($_POST['captcha'] !== $_SESSION['captcha']) {
    echo json_encode(['error' => 'Captcha verification failed']);
    exit;
}

// Invalidate the current captcha
unset($_SESSION['captcha']);

// Domain name from the POST request
$domain = $_POST['domain'];

// RDAP server URL
$rdapServer = 'https://rdap.EXAMPLE.COM/domain/';

// Initialize cURL session
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $rdapServer . $domain);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// Execute cURL session and close it
$response = curl_exec($ch);
curl_close($ch);

// Check for errors
if (!$response) {
    echo json_encode(['error' => 'Error fetching RDAP data.']);
    exit;
}

// Output the RDAP data
echo $response;