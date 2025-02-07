#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Low Balance Notification Script
 *
 * This script checks all registrars for three conditions:
 *  - Low balance (accountBalance < creditThreshold)
 *  - Zero balance (accountBalance == 0)
 *  - Over limit (accountBalance + creditLimit < 0)
 *
 * If any of these conditions are met, the registry admin is notified.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$c = require_once '/opt/registry/automation/config.php';

// Ensure the script is run from the command line.
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

date_default_timezone_set('UTC');

$dsn = "mysql:host={$c['db_host']};dbname={$c['db_database']};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Get registry admin email
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = :name LIMIT 1");
    $stmt->execute(['name' => 'email']);
    $adminEmail = $stmt->fetchColumn();

    // Fetch registrar balances
    $sql = "SELECT id, clid, name, accountBalance, creditThreshold, creditLimit, email, currency FROM registrar";
    $stmt2 = $pdo->query($sql);
    
    $notifications = [];

    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $message = null;
        $balance = $row['accountBalance'];
        $threshold = $row['creditThreshold'];
        $creditLimit = $row['creditLimit'];
        $totalCredit = $balance + $creditLimit;

        if ($balance < $threshold) {
            $message = sprintf(
                "Registrar %s (ID: %s) has a low balance.\nCurrent Balance: %.2f %s\nCredit Threshold: %.2f %s\n",
                $row['name'], $row['clid'], $balance, $row['currency'], $threshold, $row['currency']
            );
        } elseif ($balance == 0) {
            $message = sprintf(
                "Registrar %s (ID: %s) has a zero balance.\nCurrent Balance: 0.00 %s\n",
                $row['name'], $row['clid'], $row['currency']
            );
        } elseif ($totalCredit < 0) {
            $message = sprintf(
                "Registrar %s (ID: %s) has exceeded the credit limit.\nCurrent Balance: %.2f %s\nCredit Limit: %.2f %s\nTotal Credit: %.2f %s\n",
                $row['name'], $row['clid'], $balance, $row['currency'], $creditLimit, $row['currency'], $totalCredit, $row['currency']
            );
        }

        if ($message) {
            $notifications[] = $message;
        }
    }

    // If no notifications, exit
    if (empty($notifications)) {
        echo "No registrars have low balances.\n";
        exit(0);
    }

    // Build the email body
    $emailBody  = "Low Balance Notification\n";
    $emailBody .= "The following registrars have low balance issues:\n\n";
    $emailBody .= implode("\n", $notifications);

    // Prepare email data
    $emailData = [
        'type'    => 'sendmail',
        'toEmail' => $adminEmail,
        'subject' => 'Low Balance Notification - Registrars Requiring Attention',
        'body'    => $emailBody,
    ];

    $jsonPayload = json_encode($emailData);
    if ($jsonPayload === false) {
        throw new Exception("JSON encoding error: " . json_last_error_msg());
    }

    // Send the Email via cURL
    $mailServiceUrl = 'http://127.0.0.1:8250';
    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload),
        ],
    ];

    $ch = curl_init($mailServiceUrl);
    if ($ch === false) {
        throw new Exception("Failed to initialize cURL.");
    }

    curl_setopt_array($ch, $curlOptions);
    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception("cURL error (" . curl_errno($ch) . "): " . curl_error($ch));
    }

    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpStatus >= 400) {
        throw new Exception("HTTP error code {$httpStatus} received. Response: {$response}");
    }

    echo "Low balance notification sent successfully. Response: " . $response . PHP_EOL;

} catch (Exception $ex) {
    error_log("Error in low balance notification script: " . $ex->getMessage());
    echo "An error occurred: " . $ex->getMessage() . PHP_EOL;
    exit(1);
}
