<?php
// Purge contact detaila from registry
// USE ONLY AFTER switch to minimal data mode completed
// Enter your database details below

$db_type = 'mysql';
$db_host = 'localhost';
$db_port = 3306;
$db_database = 'registry';
$db_username = 'your_username';
$db_password = 'your_password';

// Connect to the database
$dsn = "{$db_type}:host={$db_host};dbname={$db_database};port={$db_port}";
echo 'job started'.PHP_EOL; 

try {
    $dbh = new PDO($dsn, $db_username, $db_password);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo 'DB Connection failed: ' . $e->getMessage();
}

// Warning message
echo "Warning: This will delete all contacts in the registry. USE ONLY AFTER switch to minimal data mode completed. Are you sure you want to continue? (y/n): ";

// Read user input from the terminal
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
if(trim($line) != 'y'){
    echo "Aborted.\n";
    exit;
}

// Close the handle
fclose($handle);

try {
    $dbh->beginTransaction();
    
    $dbh->prepare("UPDATE domain SET registrant = NULL")->execute();
    $dbh->prepare("UPDATE application SET registrant = NULL")->execute();
    $dbh->prepare("UPDATE domain_auto_approve_transfer SET registrant = NULL")->execute();
    
    $dbh->prepare("DELETE FROM domain_contact_map")->execute();
    $dbh->prepare("DELETE FROM application_contact_map")->execute();
    $dbh->prepare("DELETE FROM contact_status")->execute();
    $dbh->prepare("DELETE FROM contact_postalInfo")->execute();
    $dbh->prepare("DELETE FROM contact_auto_approve_transfer")->execute();
    $dbh->prepare("DELETE FROM contact_authInfo")->execute();
    $dbh->prepare("DELETE FROM contact")->execute();
    
    $dbh->commit();
    echo 'job finished successfully'.PHP_EOL;

} catch (Exception $e) {
    $dbh->rollBack();
    echo 'Database error: ' . $e->getMessage();
} catch (PDOException $e) {
    $dbh->rollBack();
    echo 'Database error: ' . $e->getMessage();
} catch (Throwable $e) {
    $dbh->rollBack();
    echo 'Error: ' . $e->getMessage();
}