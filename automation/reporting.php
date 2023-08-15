<?php
$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Fetch all TLDs
$query = "SELECT tld FROM domain_tld";
$tlds = $dbh->query($query)->fetchAll(PDO::FETCH_COLUMN);

foreach ($tlds as $tld) {
    // Construct activity data for each TLD
    $activityData[] = [
        'operational-registrars' => getOperationalRegistrars($dbh, $tld),
        'ramp-up-registrars' => getRampUpRegistrars($dbh, $tld),
        'pre-ramp-up-registrars' => getPreRampUpRegistrars($dbh, $tld),
        'zfa-passwords' => getZfaPasswords($dbh, $tld),
        // ... continue for all other headers
    ];

    // Loop through registrars and get transaction data
    $registrars = getRegistrars($dbh);
    foreach ($registrars as $registrar) {
        $transactionData[] = [
            'registrar-name' => $registrar['name'],
            'iana-id' => $registrar['iana_id'],
            'total-domains' => $registrar['total_domains'],
            'net-adds-1-yr' => getNetAdds1Yr($dbh, $registrar),
            // ... continue for all other headers
        ];
    }

    // Write data to CSV
    writeCSV("{$tld}-activity-" . date('Ym') . "-en.csv", $activityData);
    writeCSV("{$tld}-transactions-" . date('Ym') . "-en.csv", $transactionData);
	
    // Upload if the $c['reporting_upload'] variable is true
    if ($c['reporting_upload']) {
        // Calculate the period (previous month from now)
        $previousMonth = date('Ym', strtotime('-1 month'));
    
        // Paths to the files you created
        $activityFile = "{$tld}-activity-" . $previousMonth . "-en.csv";
        $transactionFile = "{$tld}-transactions-" . $previousMonth . "-en.csv";
    
        // URLs for upload
        $activityUploadUrl = 'https://ry-api.icann.org/report/registry-functions-activity/' . $tld . '/' . $previousMonth;
        $transactionUploadUrl = 'https://ry-api.icann.org/report/registrar-transactions/' . $tld . '/' . $previousMonth;
    
        // Perform the upload
        uploadFile($activityUploadUrl, $activityFile, $c['reporting_username'], $c['reporting_password']);
        uploadFile($transactionUploadUrl, $transactionFile, $c['reporting_username'], $c['reporting_password']);
    }
	
}

// HELPER FUNCTIONS
function getOperationalRegistrars($dbh, $tld) {
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM registrars WHERE status = 'operational' AND tld = ?");
    $stmt->execute([$tld]);
    return $stmt->fetchColumn();
}

function getRegistrars($dbh) {
    return $dbh->query("SELECT name, iana_id, total_domains FROM registrars")->fetchAll();
}

function writeCSV($filename, $data) {
    $file = fopen($filename, 'w');
    fputcsv($file, array_keys($data[0]));
    foreach ($data as $row) {
        fputcsv($file, $row);
    }
    fclose($file);
}

function getRampUpRegistrars($dbh, $tld) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getPreRampUpRegistrars($dbh, $tld) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getZfaPasswords($dbh, $tld) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

// Sample transaction data helper functions:
function getNetAdds1Yr($dbh, $registrar) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

// Upload function using cURL
function uploadFile($url, $filePath, $username, $password) {
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_PUT, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filePath));
    curl_setopt($ch, CURLOPT_INFILE, fopen($filePath, 'r'));
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    
    curl_close($ch);
}