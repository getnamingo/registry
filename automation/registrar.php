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
$sql = 'SELECT id, clid, name, accountBalance AS "accountBalance",
        creditThreshold AS "creditThreshold", creditLimit AS "creditLimit", email, currency
        FROM registrar';

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
    global $c;

    switch ($case) {
        case 'low_balance':
            $subject = "Low balance alert for registrar: " . $data['clid'];
            $alertTitle = 'Low Balance Alert';
            $alertMessage = "We are writing to inform you that your account with us currently has a low balance. As of now, your account balance is {$data['currency']} {$data['accountBalance']}, which is below the minimum credit threshold of {$data['currency']} {$data['creditThreshold']}.";
            break;

        case 'zero_balance':
            $subject = "Zero balance alert for registrar: " . $data['clid'];
            $alertTitle = 'Zero Balance Alert';
            $alertMessage = 'We have noticed that your account balance with us is currently zero. This means you are unable to use our services until the balance is topped up.';
            break;

        case 'over_limit':
            $subject = "Over limit alert for registrar: " . $data['clid'];
            $alertTitle = 'Credit Limit Alert';
            $alertMessage = 'Your account is currently past the credit limit. Immediate action is required to bring your account back into good standing and avoid service disruption.';
            break;

        default:
            $subject = "Alert for registrar: " . $data['clid'];
            $alertTitle = 'Account Alert';
            $alertMessage = "This is a generic warning for registrar: {$data['clid']}.";
    }

    $message = renderEmailTemplate(
        'registrar.html',
        [
            'registry_name' => $registryName,
            'registrar_name' => $data['name'],
            'registrar_id' => $data['clid'],
            'alert_title' => $alertTitle,
            'alert_message' => $alertMessage,
            'support_email' => $supportEmail,
            'support_phone' => $supportPhoneNumber,
        ]
    );

    $toSend = [
        'type'    => 'sendmail',
        'subject' => $subject,
        'body'    => $message,
        'toEmail' => $data['email'],
    ];

    try {
        $payload = json_encode(
            $toSend,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (JsonException $e) {
        $log->error('Unable to encode email payload', [
            'clid' => $data['clid'],
            'error' => $e->getMessage(),
        ]);
        return;
    }

    $url = 'http://127.0.0.1:8250';

    $headers = [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
    ];

    $apiToken = (string)($c['msg_api_token'] ?? '');

    if ($apiToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiToken;
    }

    $curl = curl_init($url);

    if ($curl === false) {
        $log->error('Unable to initialize cURL', [
            'clid' => $data['clid'],
        ]);
        return;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $curlErrno = curl_errno($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    curl_close($curl);

    if ($response === false) {
        $log->error('Message producer connection failed', [
            'clid' => $data['clid'],
            'error' => $curlError,
            'errno' => $curlErrno,
        ]);
        return;
    }

    if ($httpCode !== 202) {
        $log->error('Message producer rejected email', [
            'clid' => $data['clid'],
            'http_code' => $httpCode,
            'response' => substr((string)$response, 0, 500),
        ]);
        return;
    }

    $result = json_decode($response, true);

    $log->info('Email queued successfully', [
        'clid' => $data['clid'],
        'message_id' => $result['id'] ?? null,
    ]);
}
