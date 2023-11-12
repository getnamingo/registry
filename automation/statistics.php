<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

try {
    // Check if there's an entry for the current date
    $query = "SELECT id FROM statistics WHERE date = CURDATE()";
    $curdate_id = $dbh->query($query)->fetchColumn();

    if (!$curdate_id) {
        $dbh->exec("INSERT IGNORE INTO statistics (date) VALUES(CURDATE())");
    }

    // Get the total number of domains
    $total_domains = $dbh->query("SELECT COUNT(id) AS total_domains FROM domain")->fetchColumn();

    // Update the statistics table with the total number of domains for the current date
    $dbh->exec("UPDATE statistics SET total_domains = '$total_domains' WHERE date = CURDATE()");

} catch (PDOException $e) {
    // Handle database errors
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    // Handle other types of errors
    die("Error: " . $e->getMessage());
}