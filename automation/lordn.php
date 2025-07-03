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
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

// Fetching TLDs
$stmt = $pdo->query("SELECT id, tld FROM domain_tld;");
$tlds = $stmt->fetchAll();

foreach ($tlds as $tld) {
    try {
        $stmt = $pdo->prepare("SELECT phase_type, phase_description FROM launch_phases WHERE tld_id = ?");
        $stmt->execute([$tld['id']]);
        $launchPhase = $stmt->fetch();

        if ($launchPhase) {
            if ($launchPhase['phase_type'] == 'sunrise') {
                // Generate Sunrise LORDN file
                generateSunriseLordn($pdo, $tld, $c['lordn_user'], $c['lordn_pass'], $log);
            } elseif ($launchPhase['phase_type'] == 'claims' || strpos($launchPhase['phase_description'], 'claims') !== false) {
                // Generate Claims LORDN file
                generateClaimsLordn($pdo, $tld, $c['lordn_user'], $c['lordn_pass'], $log);
            }
        } else {
            $log->info("No launch phase found for TLD {$tld['tld']}.");
            continue;
        }
    } catch (Throwable $e) {
        $log->error("Error processing TLD {$tld['tld']}: " . $e->getMessage());
    }
}

$log->info('job finished successfully.');

function generateSunriseLordn($pdo, $tld, $username, $password, $log) {
    $dateStamp = date('Ymd');
    $tldName = ltrim($tld['tld'], '.'); // Remove leading dot
    $fileName = "sunrise_lordn_{$tldName}_{$dateStamp}.csv";
    $file = fopen($fileName, 'w');

    if (!$file) {
        $log->error("Unable to open file {$fileName} for writing.");
        return false;
    }
    
    // Fetch data from your database
    $stmt = $pdo->prepare("SELECT id, name, smd, clid, crdate FROM application WHERE tldid = ?");
    $stmt->execute([$tld['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $log->info("Creating LORDN file {$fileName} with " . count($rows) . " entries.");

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
    $result = uploadFile($fileName, $uploadUrl, $username, $password, $log);

    if ($result['transactionId']) {
        $log->info("LORDN upload successful for {$tld['tld']} ({$fileName}). Transaction ID: {$result['transactionId']}");
    } else {
        $log->warning("LORDN upload for {$tld['tld']} ({$fileName}) did not return a transaction ID.");
    }
}

function generateClaimsLordn($pdo, $tld, $username, $password, $log) {
    $dateStamp = date('Ymd');
    $tldName = ltrim($tld['tld'], '.'); // Remove leading dot
    $fileName = "claims_lordn_{$tldName}_{$dateStamp}.csv";
    $file = fopen($fileName, 'w');

    if (!$file) {
        $log->error("Unable to open file {$fileName} for writing.");
        return false;
    }

    // Fetch data from your database
    $stmt = $pdo->prepare("SELECT id, name, notice_id, clid, crdate, ack_datetime FROM domain WHERE tldid = ?");
    $stmt->execute([$tld['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $log->info("Creating LORDN file {$fileName} with " . count($rows) . " entries.");

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
    $result = uploadFile($fileName, $uploadUrl, $username, $password, $log);

    if ($result['transactionId']) {
        $log->info("LORDN upload successful for {$tld['tld']} ({$fileName}). Transaction ID: {$result['transactionId']}");
    } else {
        $log->warning("LORDN upload for {$tld['tld']} ({$fileName}) did not return a transaction ID.");
    }
}

function uploadFile($filePath, $uploadUrl, $username, $password, $log) {
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
        $log->error("Error 400: Incorrect syntax - " . $body);
    } elseif ($httpCode === 401) {
        // Unauthorized
        $log->error("Error 401: Unauthorized");
    } elseif ($httpCode === 404) {
        // Not found, typically outside of a QLP Period
        $log->error("Error 404: Not Found");
    } elseif ($httpCode === 500) {
        // Server error
        $log->error("Error 500: Server error");
    } else {
        // Other errors
        $log->error("Error: HTTP status code " . $httpCode);
    }

    curl_close($curl);

    return ['transactionId' => $transactionId, 'locationUrl' => $locationUrl];
}