<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$logFilePath = '/var/log/namingo/registrar.log';
$log = setupLogger($logFilePath, 'Registrar_Maintenance');
$log->info('job started.');

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

// Define the query
$sql = "SELECT id, clid, accountBalance, creditThreshold, creditLimit FROM registrar";

try {
    $stmt = $pdo->query($sql);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['accountBalance'] < $row['creditThreshold']) {
            // Case 1: accountBalance is less than creditThreshold
            sendEmail($row, 'low_balance');
        } elseif ($row['accountBalance'] == 0) {
            // Case 2: accountBalance is 0
            sendEmail($row, 'zero_balance');
        } elseif (($row['accountBalance'] + $row['creditLimit']) < 0) {
            // Case 3: accountBalance + creditLimit is less than 0
            sendEmail($row, 'over_limit');
        }
    }
    
    $log->info('job finished successfully.');
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
}

// Function to send email
function sendEmail($data, $case) {
    // Implement the actual email sending logic here
    switch ($case) {
        case 'low_balance':
            $message = "Low balance alert for registrar: " . $data['clid'];
            break;
        case 'zero_balance':
            $message = "Zero balance alert for registrar: " . $data['clid'];
            break;
        case 'over_limit':
            $message = "Over limit alert for registrar: " . $data['clid'];
            break;
        default:
            $message = "Alert for registrar: " . $data['clid'];
    }
    
    // Prepare the data array for the cURL request
    $data = [
        'type' => 'sendmail', 
        'subject' => $subject,
        'body' => $body,
        'toEmail' => $row['billing_email'],
    ];
            
    $url = 'http://127.0.0.1:8250';

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($data))
        ],
    ];

    $curl = curl_init($url);
    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);

    if ($response === false) {
        throw new Exception(curl_error($curl), curl_errno($curl));
    }

    curl_close($curl);

    $log->info('sending email to ' . $data['clid']);
}