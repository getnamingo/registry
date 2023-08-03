<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['domain']) || !isset($_POST['captcha'])) {
        echo json_encode(['error' => 'Invalid request.']);
        exit;
    }

    if ($_POST['captcha'] !== $_SESSION['captcha']) {
        echo json_encode(['error' => 'Incorrect Captcha.']);
        exit;
    }

    $domain = $_POST['domain'];
    $whoisServer = 'whois.example.com';

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

    echo json_encode(['result' => nl2br(htmlspecialchars($output))]);

    } else {
    echo json_encode(['error' => 'Invalid request method.']);
    }