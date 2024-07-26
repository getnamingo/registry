<?php
// Clean test detaila from registry
// DO NOT use once you start adding data
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
echo "Warning: This will delete all test TLDs and registrar data. Are you sure you want to continue? (y/n): ";

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

    $dbh->prepare("DELETE FROM registrar_whitelist")->execute();
    $dbh->prepare("DELETE FROM registrar_users")->execute();
    $dbh->prepare("DELETE FROM registrar_ote")->execute();
    $dbh->prepare("DELETE FROM registrar_contact")->execute();
    $dbh->prepare("DELETE FROM users WHERE id = ?")->execute([1]);
    $dbh->prepare("DELETE FROM users WHERE id = ?")->execute([2]);
    $dbh->prepare("DELETE FROM registrar")->execute();

    $dbh->prepare("DELETE FROM domain_restore_price")->execute();
    $dbh->prepare("DELETE FROM domain_price")->execute();
    $dbh->prepare("DELETE FROM domain_price")->execute();
    $dbh->prepare("DELETE FROM domain_tld")->execute();
    
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