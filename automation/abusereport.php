<?php
require __DIR__ . '/vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/abusereport.log';
$log = setupLogger($logFilePath, 'Abuse_Report');
$log->info('Job started.');

try {
    // Database connection
    $dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $settingsStmt = $dbh->query("
        SELECT name, value FROM settings
        WHERE name IN ('email', 'phone', 'company_name')
    ");
    $settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $supportEmail = $settings['email'] ?? 'default-support@example.com';
    $supportPhoneNumber = $settings['phone'] ?? '+1.23456789';
    $registryName = $settings['company_name'] ?? 'Example Registry LLC';
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
function generateReportHTML(
    $tickets,
    $reportScope,
    $registryName,
    $supportEmail,
    $supportPhoneNumber
)
{
    $escape = static function ($value): string {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    };

    if (empty($tickets)) {
        $reportContent = '<div class="notice" style="margin:24px 0; padding:16px 18px; background-color:#f3f5f2; border-left:3px solid #70867d; border-radius:8px; color:#3f4743;">'
            . '<p>No abuse cases found for the period.</p>'
            . '</div>';
    } else {
        $reportContent = '<h2>Cases</h2>';

        foreach ($tickets as $ticket) {
            $reportContent .= sprintf(
                '<table role="presentation" class="details" width="100%%" cellspacing="0" cellpadding="0" border="0" style="width:100%%; margin:0 0 16px; background-color:#fafaf8; border:1px solid #e5e5df; border-radius:10px;">'
                . '<tr><td style="padding:12px 14px; vertical-align:top; border-bottom:1px solid #e5e5df;">Reported Domain</td><td style="padding:12px 14px; vertical-align:top; word-break:break-word; border-bottom:1px solid #e5e5df;"><strong>%s</strong></td></tr>'
                . '<tr><td style="padding:12px 14px; vertical-align:top; border-bottom:1px solid #e5e5df;">Nature of Abuse</td><td style="padding:12px 14px; vertical-align:top; word-break:break-word; border-bottom:1px solid #e5e5df;">%s</td></tr>'
                . '<tr><td style="padding:12px 14px; vertical-align:top; border-bottom:1px solid #e5e5df;">Status</td><td style="padding:12px 14px; vertical-align:top; word-break:break-word; border-bottom:1px solid #e5e5df;">%s</td></tr>'
                . '<tr><td style="padding:12px 14px; vertical-align:top; border-bottom:1px solid #e5e5df;">Priority</td><td style="padding:12px 14px; vertical-align:top; word-break:break-word; border-bottom:1px solid #e5e5df;">%s</td></tr>'
                . '<tr><td style="padding:12px 14px; vertical-align:top; border-bottom:1px solid #e5e5df;">Date of Incident</td><td style="padding:12px 14px; vertical-align:top; word-break:break-word; border-bottom:1px solid #e5e5df;">%s</td></tr>'
                . '<tr><td style="padding:12px 14px; vertical-align:top;">Date Reported</td><td style="padding:12px 14px; vertical-align:top; word-break:break-word;">%s</td></tr>'
                . '</table>',
                $escape($ticket['reported_domain']),
                $escape($ticket['nature_of_abuse']),
                $escape($ticket['status']),
                $escape($ticket['priority']),
                $escape($ticket['date_of_incident']),
                $escape($ticket['date_created'])
            );
        }
    }

    return renderEmailTemplate(
        'abusereport.html',
        [
            'registry_name' => $registryName,
            'report_scope' => $reportScope,
            'report_date' => date('Y-m-d H:i:s'),
            'report_content' => $reportContent,
            'support_email' => $supportEmail,
            'support_phone' => $supportPhoneNumber,
        ],
        ['report_content']
    );
}

// Send email via internal API
function sendEmail($toEmail, $subject, $htmlContent)
{
    global $log, $c;
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
            'Content-Length: ' . strlen(json_encode($data)),
            'Authorization: Bearer ' . $c['msg_api_token'],
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
            $htmlContent = generateReportHTML(
                $tickets,
                $role['message'],
                $registryName,
                $supportEmail,
                $supportPhoneNumber
            );
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
