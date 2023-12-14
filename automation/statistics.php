<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$logFilePath = '/var/log/namingo/statistics.log';
$log = setupLogger($logFilePath, 'Statistics');
$log->info('job started.');

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

try {
    // Check if there's an entry for the current date
    $query = "SELECT id FROM statistics WHERE date = CURRENT_DATE";
    $curdate_id = $dbh->query($query)->fetchColumn();

    // Only insert if there is no entry for the current date
    if (!$curdate_id) {
        $insertQuery = "INSERT INTO statistics (date) VALUES (CURRENT_DATE)";
        $dbh->exec($insertQuery);
    }

    // Get the total number of domains
    $total_domains = $dbh->query("SELECT COUNT(id) AS total_domains FROM domain")->fetchColumn();

    // Update the statistics table with the total number of domains for the current date
    $updateQuery = "UPDATE statistics SET total_domains = '$total_domains' WHERE date = CURRENT_DATE";
    $dbh->exec($updateQuery);
    $log->info('job finished successfully.');
    
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
}