<?php

try {
    // Connect to the MySQL database using PDO
    $c = require 'config.php';
    $dsn = "mysql:host={$c['mysql_host']};dbname={$c['mysql_database']};port={$c['mysql_port']}";
    $dbh = new PDO($dsn, $c['mysql_username'], $c['mysql_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if there's an entry for the current date
    $query = "SELECT `id` FROM `statistics` WHERE `date` = CURDATE()";
    $curdate_id = $dbh->query($query)->fetchColumn();

    if (!$curdate_id) {
        $dbh->exec("INSERT IGNORE INTO `statistics` (`date`) VALUES(CURDATE())");
    }

    // Get the total number of domains
    $total_domains = $dbh->query("SELECT COUNT(`id`) AS `total_domains` FROM `domain`")->fetchColumn();

    // Update the statistics table with the total number of domains for the current date
    $dbh->exec("UPDATE `statistics` SET `total_domains` = '$total_domains' WHERE `date` = CURDATE()");

} catch (PDOException $e) {
    // Handle database errors
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    // Handle other types of errors
    die("Error: " . $e->getMessage());
}