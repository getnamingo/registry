<?php
require __DIR__ . '/vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$logFilePath = '/var/log/namingo/abusereport.log';
$log = setupLogger($logFilePath, 'Abuse_Report');
$log->info('job started.');

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

try {
    // Prepare and execute the query
    $query = "SELECT reported_domain, nature_of_abuse, status, priority, date_of_incident, date_created FROM support_tickets WHERE category_id = '8'";
    $stmt = $dbh->query($query);

    // Fetch all rows
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Start HTML output
    $html = "<!DOCTYPE html>
    <html>
    <head>
    <title>Abuse Report</title>
    </head>
    <body>
    <h1>Abuse Report</h1>
    <p>Report Date: " . date('Y-m-d H:i:s') . "</p>"; // Display report generation date

    if (empty($tickets)) {
        $html .= "<p>No abuse cases found for the period.</p>"; // Message if no tickets
    } else {
        // Continue with the table if tickets are found
        $html .= "<table border='1'>
        <tr>
            <th>Reported Domain</th>
            <th>Nature of Abuse</th>
            <th>Status</th>
            <th>Priority</th>
            <th>Date of Incident</th>
            <th>Date Reported</th>
        </tr>";

        // Loop through tickets and add rows to the table
        foreach ($tickets as $ticket) {
            $html .= "<tr>
                <td>" . htmlspecialchars($ticket['reported_domain']) . "</td>
                <td>" . htmlspecialchars($ticket['nature_of_abuse']) . "</td>
                <td>" . htmlspecialchars($ticket['status']) . "</td>
                <td>" . htmlspecialchars($ticket['priority']) . "</td>
                <td>" . htmlspecialchars($ticket['date_of_incident']) . "</td>
                <td>" . htmlspecialchars($ticket['date_created']) . "</td>
            </tr>";
        }

        $html .= "</table>"; // Close the table
    }

    // End HTML
    $html .= "</body>
    </html>";

    // Prepare the data array
    $data = [
        'type' => 'sendmail',
        'toEmail' => $toEmail,
        'subject' => 'Abuse Report',
        'body' => $html,
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

    $log->info('job finished successfully.');
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
}