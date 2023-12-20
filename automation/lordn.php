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
$logFilePath = '/var/log/namingo/lordn.log';
$log = setupLogger($logFilePath, 'Lordn');
$log->info('job started.');

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

// Fetching TLDs
$stmt = $dbh->query("SELECT id, tld FROM domain_tld;");
$tlds = $stmt->fetchAll();

foreach ($tlds as $tld) {
    $stmt = $dbh->prepare("SELECT phase_type, phase_description FROM launch_phases WHERE tld_id = ?");
    $stmt->execute([$tld['id']]);
    $launchPhase = $stmt->fetch();

    if ($launchPhase) {
        if ($launchPhase['phase_type'] == 'sunrise') {
            // Generate Sunrise LORDN file
            generateSunriseLordn($dbh, $tld, $c['lordn_user'], $c['lordn_pass']);
        } elseif ($launchPhase['phase_type'] == 'claims' || strpos($launchPhase['phase_description'], 'claims') !== false) {
            // Generate Claims LORDN file
            generateClaimsLordn($dbh, $tld, $c['lordn_user'], $c['lordn_pass']);
        }
    }

    $log->info('job finished successfully.');
}

function generateSunriseLordn($dbh, $tld, $username, $password) {
    $dateStamp = date('Ymd');
    $tldName = ltrim($tld['tld'], '.'); // Remove leading dot
    $fileName = "sunrise_lordn_{$tldName}_{$dateStamp}.csv";
    $file = fopen($fileName, 'w');

    // Fetch data from your database
    $stmt = $dbh->prepare("SELECT id, name, smd, clid, crdate FROM application WHERE tldid = ?");
    $stmt->execute([$tld['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // First row: version, creation datetime, number of lines
    fputcsv($file, [1, gmdate('Y-m-d\TH:i:s\Z'), count($rows)]);
    
    // Header row
    fputcsv($file, ['roid', 'domain-name', 'SMD-id', 'registrar-id', 'registration-datetime', 'application-datetime']);

    // Data rows
    foreach ($rows as $row) {
        fputcsv($file, $row);
    }

    fclose($file);
    
    // Upload the file
    $uploadUrl = "https://<tmdb-domain-name>/LORDN/{$tldName}/sunrise";
    uploadFile($fileName, $uploadUrl, $username, $password);
}

function generateClaimsLordn($dbh, $tld, $username, $password) {
    $dateStamp = date('Ymd');
    $tldName = ltrim($tld['tld'], '.'); // Remove leading dot
    $fileName = "claims_lordn_{$tldName}_{$dateStamp}.csv";
    $file = fopen($fileName, 'w');

    // Fetch data from your database
    $stmt = $dbh->prepare("SELECT id, name, notice_id, clid, crdate, ack_datetime FROM domain WHERE tldid = ?");
    $stmt->execute([$tld['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // First row: version, creation datetime, number of lines
    fputcsv($file, [1, gmdate('Y-m-d\TH:i:s\Z'), count($rows)]);

    // Header row
    fputcsv($file, ['roid', 'domain-name', 'notice-id', 'registrar-id', 'registration-datetime', 'ack-datetime', 'application-datetime']);

    // Data rows
    foreach ($rows as $row) {
        // Assuming '0' for missing values in the row
        $data = array_merge($row, array_fill(0, 7 - count($row), '0'));
        fputcsv($file, $data);
    }

    fclose($file);
    
    // Upload the file
    $uploadUrl = "https://<tmdb-domain-name>/LORDN/{$tldName}/claims";
    uploadFile($fileName, $uploadUrl, $username, $password);
}

function uploadFile($filePath, $uploadUrl, $username, $password) {
    $curl = curl_init();
    $fileData = file_get_contents($filePath);

    curl_setopt($curl, CURLOPT_URL, $uploadUrl);
    curl_setopt($curl, CURLOPT_PUT, true);
    curl_setopt($curl, CURLOPT_USERPWD, "$username:$password");
    curl_setopt($curl, CURLOPT_POSTFIELDS, $fileData);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/octet-stream'));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, true);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    $transactionId = null;
    $locationUrl = null;

    if ($httpCode === 202) {
        // Successful, LORDN file syntax correct
        // Extracting LORDN Transaction Identifier from the body
        $transactionId = trim($body);
        // Extracting the Location header
        if (preg_match('/Location: (.*?)\s/m', $header, $matches)) {
            $locationUrl = trim($matches[1]);
        }
    } elseif ($httpCode === 400) {
        // Syntax of the LORDN file is incorrect
        echo "Error 400: Incorrect syntax - " . $body;
    } elseif ($httpCode === 401) {
        // Unauthorized
        echo "Error 401: Unauthorized";
    } elseif ($httpCode === 404) {
        // Not found, typically outside of a QLP Period
        echo "Error 404: Not Found";
    } elseif ($httpCode === 500) {
        // Server error
        echo "Error 500: Server error";
    } else {
        // Other errors
        echo "Error: HTTP status code " . $httpCode;
    }

    curl_close($curl);

    return ['transactionId' => $transactionId, 'locationUrl' => $locationUrl];
}