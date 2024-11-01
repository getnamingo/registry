<?php
require __DIR__ . '/vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/abusereport.log';
$log = setupLogger($logFilePath, 'Abuse_Report');
$log->info('Job started.');

try {
    // Database connection
    $dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}";
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
    exit;
}

// Retrieve tickets by user role
function getTicketsByUserRole($dbh, $userRoleMask, $userId = null)
{
    $query = "SELECT reported_domain, nature_of_abuse, status, priority, date_of_incident, date_created
              FROM support_tickets
              WHERE category_id = '8'";

    if ($userRoleMask === 4 && $userId) {
        $query .= " AND user_id = :userId";
    }

    $stmt = $dbh->prepare($query);
    if ($userRoleMask === 4 && $userId) {
        $stmt->execute([':userId' => $userId]);
    } else {
        $stmt->execute();
    }
    
    return $stmt->fetchAll();
}

// Generate HTML report for abuse tickets
function generateReportHTML($tickets)
{
    $html = "<!DOCTYPE html>
    <html>
    <head>
    <title>Abuse Report</title>
    </head>
    <body>
    <h1>Abuse Report</h1>
    <p>Report Date: " . date('Y-m-d H:i:s') . "</p>";

    if (empty($tickets)) {
        $html .= "<p>No abuse cases found for the period.</p>";
    } else {
        $html .= "<table border='1'>
        <tr>
            <th>Reported Domain</th>
            <th>Nature of Abuse</th>
            <th>Status</th>
            <th>Priority</th>
            <th>Date of Incident</th>
            <th>Date Reported</th>
        </tr>";

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
        $html .= "</table>";
    }
    
    $html .= "</body></html>";
    return $html;
}

// Send email via internal API
function sendEmail($toEmail, $subject, $htmlContent)
{
    global $log;
    $data = [
        'type' => 'sendmail',
        'toEmail' => $toEmail,
        'subject' => $subject,
        'body' => $htmlContent,
    ];

    $url = 'http://127.0.0.1:8250';
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($data))
        ],
    ];

    $curl = curl_init($url);
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);

    if ($response === false) {
        $log->error('Email sending failed: ' . curl_error($curl));
        curl_close($curl);
        return false;
    }

    curl_close($curl);
    return true;
}

// Process report generation and sending based on roles
try {
    $userRoles = [
        ['role' => 0, 'message' => 'Full abuse report for all domains'],
        ['role' => 4, 'message' => 'Abuse report for specific user cases']
    ];

    foreach ($userRoles as $role) {
        $users = $dbh->prepare("SELECT id, email FROM users WHERE roles_mask = :roleMask");
        $users->execute([':roleMask' => $role['role']]);
        
        while ($user = $users->fetch()) {
            $tickets = getTicketsByUserRole($dbh, $role['role'], $user['id']);
            $htmlContent = generateReportHTML($tickets);
            $subject = "Abuse Report - {$role['message']}";

            if (sendEmail($user['email'], $subject, $htmlContent)) {
                $log->info("Abuse report sent to {$user['email']} for role {$role['role']}");
            } else {
                $log->error("Failed to send abuse report to {$user['email']} for role {$role['role']}");
            }
        }
    }

    $log->info('Job finished successfully.');
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
}