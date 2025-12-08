<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$logFilePath = '/var/log/namingo/auto_clean_unused_contact_and_host.log';
$log = setupLogger($logFilePath, 'Auto_Clean_Unused_Contact_And_Host');
$log->info('job started.');

$dbh = null;

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
    exit(1);
}

try {
    $minimum_data = $c['minimum_data'];
    $dbh->beginTransaction();
    
    // Prepare and execute the SQL statement to select unused hosts
    $stmt = $dbh->prepare("
        SELECT h.id
        FROM host AS h
        LEFT JOIN domain_host_map AS m ON h.id = m.host_id
        WHERE m.host_id IS NULL
          AND h.domain_id IS NULL
          AND h.crdate < (NOW() - INTERVAL 1 MONTH)
    ");
    $stmt->execute();
    $hostIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Delete associated records from various tables for hosts
    if (!empty($hostIds)) {
        $placeholders = implode(',', array_fill(0, count($hostIds), '?'));
        $dbh->prepare("DELETE FROM host_status WHERE host_id IN ($placeholders)")->execute($hostIds);
        $dbh->prepare("DELETE FROM host_addr WHERE host_id IN ($placeholders)")->execute($hostIds);
        $dbh->prepare("DELETE FROM host WHERE id IN ($placeholders)")->execute($hostIds);
    } else {
        $log->info('No unused hosts to delete.');
    }

    if (!$minimum_data) {
        // Prepare and execute the SQL statement to select unused contacts
        $stmt = $dbh->prepare("
            SELECT c.id
            FROM contact AS c
            LEFT JOIN domain_contact_map AS m ON c.id = m.contact_id
            LEFT JOIN domain AS d ON c.id = d.registrant
            WHERE m.contact_id IS NULL
              AND d.registrant IS NULL
              AND c.crdate < (NOW() - INTERVAL 1 MONTH)
        ");
        $stmt->execute();
        $contactIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Delete associated records from various tables for contacts
        if (!empty($contactIds)) {
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            $dbh->prepare("DELETE FROM contact_status WHERE contact_id IN ($placeholders)")->execute($contactIds);
            $dbh->prepare("DELETE FROM contact_postalInfo WHERE contact_id IN ($placeholders)")->execute($contactIds);
            $dbh->prepare("DELETE FROM contact_authInfo WHERE contact_id IN ($placeholders)")->execute($contactIds);
            $dbh->prepare("DELETE FROM contact WHERE id IN ($placeholders)")->execute($contactIds);
        } else {
            $log->info('No unused contacts to delete.');
        }
    }

    $dbh->commit();
    $log->info('job finished successfully.');

} catch (Throwable $e) {
    if ($dbh && $dbh->inTransaction()) {
        $dbh->rollBack();
    }
    $log->error('Fatal error in auto_clean_unused_contact_and_host: ' . $e->getMessage());
    exit(1);
}