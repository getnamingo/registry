#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Weekly Domain Registration Report
 *
 * This script connects to the "registry" database, retrieves all domain records
 * registered since last Monday (through Sunday), formats a text report with the domain
 * name, registrar name, and registration date, and then sends the report via an HTTP
 * POST to a local mail service.
 *
 * The local mail service expects a JSON payload similar to:
 *
 * {
 *     "type": "sendmail",
 *     "to": "admin@example.com",
 *     "subject": "Weekly Domain Registration Report (...)",
 *     "body": "..."
 * }
 *
 * Add in crontab: 59 23 * * 0 /usr/bin/php /opt/registry/tests/recent-domains.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$c = require_once '/opt/registry/automation/config.php';

// Ensure the script is run from the command line.
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

// Set the default timezone (adjust as needed)
date_default_timezone_set('UTC');

// --- Database Configuration ---
$dsn    = "mysql:host={$c['db_host']};dbname={$c['db_database']};charset=utf8mb4";

try {
    // Create a new PDO instance with error handling.
    $pdo = new PDO(
        $dsn,
        $c['db_username'],
        $c['db_password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Get email of registry operator
    $name = 'email';
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = :name LIMIT 1");
    $stmt->execute(['name' => $name]);
    $to = $stmt->fetchColumn();

    // --- Determine the Date Range ---
    //
    // Since this script is run every Sunday at 23:59, we define the week as:
    //   - Start: Monday (00:00:00) of the current week.
    //   - End:   Sunday (23:59:59) of the current week.
    //
    // Note: "monday this week" and "sunday this week" work well when run on Sunday.
    $startDate = (new DateTimeImmutable('monday this week'))->setTime(0, 0, 0);
    $endDate   = (new DateTimeImmutable('sunday this week'))->setTime(23, 59, 59);
    
    // --- Fetch Domain Records ---
    $sql = "
        SELECT d.name AS domain,
               d.crdate,
               r.name AS registrar_name
        FROM domain d
        LEFT JOIN registrar r ON d.clid = r.id
        WHERE d.crdate BETWEEN :start_date AND :end_date
        ORDER BY d.crdate ASC
    ";
    
    $stmt2 = $pdo->prepare($sql);
    $stmt2->execute([
        ':start_date' => $startDate->format('Y-m-d H:i:s'),
        ':end_date'   => $endDate->format('Y-m-d H:i:s'),
    ]);
    $domains = $stmt2->fetchAll();

    // --- Build the Email Body ---
    //
    // The email includes a header with the period and then a list of domains and
    // the name of the registrar that registered each domain.
    $emailBody  = "Weekly Domain Registration Report\n";
    $emailBody .= "Period: " . $startDate->format('Y-m-d') . " to " . $endDate->format('Y-m-d') . "\n\n";
    
    if (empty($domains)) {
        $emailBody .= "No domain registrations found during this period.\n";
    } else {
        foreach ($domains as $row) {
            $registrar = $row['registrar_name'] ?? 'Unknown';
            $emailBody .= sprintf(
                "%s - Registered by: %s (Date: %s)\n",
                $row['domain'],
                $registrar,
                $row['crdate']
            );
        }
    }
    
    // --- Prepare Data for the Mail Service ---
    //
    // Using the provided system example, we send a JSON payload to the mail service.
    // Adjust the "to" email address as necessary.
    $emailData = [
        'type'    => 'sendmail',
        'toEmail'      => $to,
        'subject' => 'Weekly Domain Registration Report (' 
                     . $startDate->format('Y-m-d') . ' to ' 
                     . $endDate->format('Y-m-d') . ')',
        'body'    => $emailBody,
    ];

    $jsonPayload = json_encode($emailData);
    if ($jsonPayload === false) {
        throw new Exception("JSON encoding error: " . json_last_error_msg());
    }
    
    // --- Send the Email via cURL ---
    //
    // The mail service is available at the local URL.
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
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error ({$errNo}): {$errMsg}");
    }
    
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpStatus >= 400) {
        throw new Exception("HTTP error code {$httpStatus} received from email service. Response: {$response}");
    }
    
    // Success output (optional logging)
    echo "Email sent successfully. Response: " . $response . PHP_EOL;
    
} catch (Exception $ex) {
    // Log the error message and exit with a non-zero status code.
    error_log("Error in weekly domain registration report script: " . $ex->getMessage());
    echo "An error occurred: " . $ex->getMessage() . PHP_EOL;
    exit(1);
}
