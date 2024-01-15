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

$stmt = $pdo->prepare("SELECT value FROM settings WHERE name = :name");
$stmt->execute(['name' => 'email']);
$row = $stmt->fetch();
if ($row) {
    $supportEmail = $row['value'];
} else {
    $supportEmail = 'default-support@example.com';
}

$stmt = $pdo->prepare("SELECT value FROM settings WHERE name = :name");
$stmt->execute(['name' => 'phone']);
$row = $stmt->fetch();
if ($row) {
    $supportPhoneNumber = $row['value'];
} else {
    $supportPhoneNumber = '+1.23456789';
}

$stmt = $pdo->prepare("SELECT value FROM settings WHERE name = :name");
$stmt->execute(['name' => 'company_name']);
$row = $stmt->fetch();
if ($row) {
    $registryName = $row['value'];
} else {
    $registryName = 'Example Registry LLC';
}

// Define the query
$sql = "SELECT id, clid, name, accountBalance, creditThreshold, creditLimit, email FROM registrar";

try {
    $stmt = $pdo->query($sql);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['accountBalance'] < $row['creditThreshold']) {
            // Case 1: accountBalance is less than creditThreshold
            sendEmail($row, 'low_balance', $log, $supportEmail, $supportPhoneNumber, $registryName);
        } elseif ($row['accountBalance'] == 0) {
            // Case 2: accountBalance is 0
            sendEmail($row, 'zero_balance', $log, $supportEmail, $supportPhoneNumber, $registryName);
        } elseif (($row['accountBalance'] + $row['creditLimit']) < 0) {
            // Case 3: accountBalance + creditLimit is less than 0
            sendEmail($row, 'over_limit', $log, $supportEmail, $supportPhoneNumber, $registryName);
        }
    }
    
    $log->info('job finished successfully.');
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
}

// Function to send email
function sendEmail($data, $case, $log, $supportEmail, $supportPhoneNumber, $registryName) {
    $message = "Dear ".$data['name'].",\n\n";
    
    switch ($case) {
        case 'low_balance':
            $subject = "Low balance alert for registrar: " . $data['clid'];
            $message .= "We are writing to inform you that your account with us currently has a low balance. As of now, your account balance is {$data['accountBalance']}, which is below the minimum credit threshold of {$data['creditThreshold']}.\n\n";
            break;
        case 'zero_balance':
            $subject = "Zero balance alert for registrar: " . $data['clid'];
            $message .= "We have noticed that your account balance with us is currently zero. This means you are unable to use our services until the balance is topped up.\n\n";
            break;
        case 'over_limit':
            $subject = "Over limit alert for registrar: " . $data['clid'];
            $message .= "Your account is currently past the credit limit. Immediate action is required to bring your account back into good standing and avoid service disruption.\n\n";
            break;
        default:
            $subject = "Alert for registrar: " . $data['clid'];
            $message .= "This is a generic warning for registrar: " . $data['clid'];
    }
    
    $message .= "Important: To avoid any interruption in services, we recommend that you top up your account balance as soon as possible.\n\n";
    $message .= "How to Top Up:\n";
    $message .= "1. Log in to your account.\n";
    $message .= "2. Navigate to the 'Financials' -> 'Add Deposit' section.\n";
    $message .= "3. Follow the instructions to add funds.\n\n";
    $message .= "If you have any questions or require assistance, please do not hesitate to contact us at $supportEmail or $supportPhoneNumber.\n\n";
    $message .= "Thank you for your prompt attention to this matter.\n\n";
    $message .= "Best regards,\n";
    $message .= "$registryName's Billing Team";
    
    // Prepare the data array for the cURL request
    $to_send = [
        'type' => 'sendmail', 
        'subject' => $subject,
        'body' => $message,
        'toEmail' => $data['email'],
    ];
            
    $url = 'http://127.0.0.1:8250';

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($to_send),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($to_send))
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