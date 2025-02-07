#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Domain Expiration Report
 *
 * Sends reports for domains expiring in 30 days, 7 days, and 1 day.
 *
 * The report is sent via an HTTP POST to a local mail service.
 *
 * The mail service expects a JSON payload similar to:
 *
 * {
 *     "type": "sendmail",
 *     "to": "admin@example.com",
 *     "subject": "Domain Expiration Report - Domains Expiring in 30 Days (YYYY-MM-DD)",
 *     "body": "..."
 * }
 *
 * Add in crontab: 0 0 * * * /usr/bin/php /opt/registry/tests/expiring-domains.php
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

    // Get registry operator email
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = :name LIMIT 1");
    $stmt->execute(['name' => 'email']);
    $to = $stmt->fetchColumn();

    // Timeframes to check: 30 days, 7 days, 1 day
    $timeframes = [
        30 => "Domains Expiring in 30 Days",
        7  => "Domains Expiring in 7 Days",
        1  => "Domains Expiring Tomorrow",
    ];

    foreach ($timeframes as $days => $subjectSuffix) {
        // Calculate the target expiration date range
        $targetDateStart = (new DateTimeImmutable("+{$days} days"))->setTime(0, 0, 0);
        $targetDateEnd   = (new DateTimeImmutable("+{$days} days"))->setTime(23, 59, 59);

        // Fetch domains expiring on that day
        $sql = "
            SELECT d.name AS domain,
                   d.exdate,
                   r.name AS registrar_name
            FROM domain d
            LEFT JOIN registrar r ON d.clid = r.id
            WHERE d.exdate BETWEEN :target_start AND :target_end
            ORDER BY d.exdate ASC
        ";

        $stmt2 = $pdo->prepare($sql);
        $stmt2->execute([
            ':target_start' => $targetDateStart->format('Y-m-d H:i:s'),
            ':target_end'   => $targetDateEnd->format('Y-m-d H:i:s'),
        ]);
        $domains = $stmt2->fetchAll();

        // Build email body
        $emailBody  = "Domain Expiration Report\n";
        $emailBody .= "Domains expiring on: " . $targetDateStart->format('Y-m-d') . "\n\n";

        if (empty($domains)) {
            $emailBody .= "No domains are scheduled to expire on this date.\n";
        } else {
            foreach ($domains as $row) {
                $registrar = $row['registrar_name'] ?? 'Unknown';
                $emailBody .= sprintf(
                    "%s - Registered by: %s (Expiry Date: %s)\n",
                    $row['domain'],
                    $registrar,
                    $row['exdate']
                );
            }
        }

        // Prepare email data
        $emailData = [
            'type'    => 'sendmail',
            'toEmail' => $to,
            'subject' => "Domain Expiration Report - {$subjectSuffix} (" . $targetDateStart->format('Y-m-d') . ")",
            'body'    => $emailBody,
        ];

        $jsonPayload = json_encode($emailData);
        if ($jsonPayload === false) {
            throw new Exception("JSON encoding error: " . json_last_error_msg());
        }

        // Send email via cURL
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

        echo "Email sent for {$subjectSuffix}. Response: " . $response . PHP_EOL;
    }

} catch (Exception $ex) {
    error_log("Error in domain expiration report script: " . $ex->getMessage());
    echo "An error occurred: " . $ex->getMessage() . PHP_EOL;
    exit(1);
}
