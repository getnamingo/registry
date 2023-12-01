<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$logFilePath = '/var/log/namingo/urs.log';
$log = setupLogger($logFilePath, 'URS_Robot');
$log->info('job started.');

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

// Connect to mailbox
try {
    $inbox = imap_open($c['urs_imap_host'], $c['urs_imap_username'], $c['urs_imap_password']);
    if (!$inbox) {
        throw new Exception('Cannot connect to mailbox: ' . imap_last_error());
    }
    // Search for emails from the two URS providers
    $emailsFromProviderA = imap_search($inbox, 'FROM "urs@adrforum.com" UNSEEN');
    $emailsFromProviderB = imap_search($inbox, 'FROM "urs@adndrc.org" UNSEEN');
    $emailsFromProviderC = imap_search($inbox, 'FROM "urs@mfsd.it" UNSEEN');

    // Combine the arrays of email IDs
    $allEmails = array_merge($emailsFromProviderA, $emailsFromProviderB, $emailsFromProviderC);

    foreach ($allEmails as $emailId) {
        $header = imap_headerinfo($inbox, $emailId);
        $from = $header->from[0]->mailbox . "@" . $header->from[0]->host;
        $subject = $header->subject;
        $date = date('Y-m-d H:i:s', strtotime($header->date)) . '.000';

        // Determine the URS provider based on the email sender
        $providerAEmail = 'urs@adrforum.com';
        $providerBEmail = 'urs@adndrc.org';
        $providerCEmail = 'urs@mfsd.it';

        // Determine the URS provider based on the email sender
        if ($from == $providerAEmail) {
            $ursProvider = 'FORUM';
        } elseif ($from == $providerBEmail) {
            $ursProvider = 'ADNDRC';
        } elseif ($from == $providerCEmail) {
            $ursProvider = 'MFSD';
        } else {
            $ursProvider = 'Unknown';
        }

        // Extract domain name or relevant info from the email (you'd need more specific code here based on the email content)
        $body = imap_fetchbody($inbox, $emailId, 1);
        $domainName = extractDomainNameFromEmail($body);

        // Insert into the database
        $stmt = $dbh->prepare("SELECT name, clid FROM domain WHERE name = ?");
        $stmt->execute([$domainName]);
        $domain = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($domain) {
            $domainName = $domain['name'];
            $registrarId = $domain['clid'];
            
            $stmt = $dbh->prepare("INSERT INTO support_tickets (user_id, category_id, subject, message, status, priority, evidence) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$registrarId, 12, 'New URS case for '.$domainName, 'New URS case for '.$domainName.' submitted by '.$ursProvider.' on '.$date.' Please act accordingly', 'Open', 'High', $body]);
        } else {
            $log->warning('Domain ' . $domainName . ' does not exists in registry');
        }
    }

    imap_close($inbox);
    $log->info('job finished successfully.');
} catch (Exception $e) {
    $log->error('IMAP connection error: ' . $e->getMessage());
    return;
}

function extractDomainNameFromEmail($emailBody) {
    // This is just a basic example
    preg_match("/domain: (.*?) /i", $emailBody, $matches);
    return $matches[1] ?? '';
}