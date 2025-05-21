<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$logFilePath = '/var/log/namingo/tmch.log';
$log = setupLogger($logFilePath, 'TMCH');
$log->info('job started.');

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

$savePath = $c['tmch_path'];

// https://newgtlds.icann.org/sites/default/files/smd-test-repository-15may18-en.pdf
$files = [
    'smdrl' => 'https://test.ry.marksdb.org/smdrl/smdrl-latest.csv',
    'dnl' => 'https://test.ry.marksdb.org/dnl/dnl-latest.csv',
    'tmch' => 'http://crl.icann.org/tmch.crl'
];

// Configure the username and password for each URL.
$credentials = [
    'smdrl' => ['user' => $c['tmch_smdrl_user'], 'pass' => $c['tmch_smdrl_pass']],
    'dnl' => ['user' => $c['tmch_dnl_user'], 'pass' => $c['tmch_dnl_pass']]
];

try {
    foreach ($files as $key => $url) {
        $ch = curl_init($url);

        // Check if credentials exist for this URL and set them.
        if (isset($credentials[$key])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $credentials[$key]['user'] . ":" . $credentials[$key]['pass']);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $data = curl_exec($ch);

        if ($data === false) {
            $log->error("Failed to download $url. Error: " . curl_error($ch));
        } else {
            $timestamp = date('YmdHis');
            $filePath = $savePath . $timestamp . '_' . basename($url);
            if (file_put_contents($filePath, $data)) {
                $log->info("Successfully downloaded $url to $filePath");
            } else {
                $log->info("Failed to save the downloaded file to $filePath");
            }
        }

        curl_close($ch);
        
        // Clear existing table data
        clearTableData($dbh, $key);

        // Depending on the file type, call different function to handle and insert data
        if ($key === 'smdrl') {
            insertSmdrlData($dbh, $filePath, $log);
        } elseif ($key === 'dnl') {
            insertDnlData($dbh, $filePath);
        } elseif ($key === 'tmch') {
            insertTmchData($dbh, $filePath);
        }
    }
    unlink($filePath);
    $log->info('job finished successfully.');
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
}

function clearTableData($dbh, $key) {
    $tableMap = [
        'smdrl' => 'tmch_revocation',
        'dnl' => 'tmch_claims',
        'tmch' => 'tmch_crl'
    ];

    if (isset($tableMap[$key])) {
        $dbh->exec("TRUNCATE TABLE " . $tableMap[$key]);
    }
}

function insertSmdrlData($dbh, $filePath, $log) {
    if (($handle = fopen($filePath, "r")) !== FALSE) {
        // Skip the first two header rows
        for ($i = 0; $i < 2; $i++) {
            fgetcsv($handle);
        }
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) >= 2) {
                // Convert ISO 8601 format to MySQL DATETIME(3) format
                try {
                    $date = new DateTime($data[1]);
                    $formattedDate = $date->format('Y-m-d H:i:s.v'); // Format for MySQL DATETIME(3)
                } catch (Exception $e) {
                    $log->info('Date conversion error:' . $e->getMessage());
                    continue; // Skip this record or handle it as needed
                }

                // Assume data format: smd_id,insertion_datetime
                $stmt = $dbh->prepare("INSERT INTO tmch_revocation (smd_id, revocation_time) VALUES (?, ?)");
                $stmt->execute([$data[0], $formattedDate]);
            }
        }
        fclose($handle);
    }
}

function insertDnlData($dbh, $filePath) {
    if (($handle = fopen($filePath, "r")) !== FALSE) {
        // Skip the first two header rows
        for ($i = 0; $i < 2; $i++) {
            fgetcsv($handle);
        }
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) >= 3) {
                // Convert ISO 8601 format to MySQL DATETIME(3) format
                try {
                    $date = new DateTime($data[2]);
                    $formattedDate = $date->format('Y-m-d H:i:s.v'); // Format for MySQL DATETIME(3)
                } catch (Exception $e) {
                    $log->info('Date conversion error:' . $e->getMessage());
                    continue; // Skip this record or handle it as needed
                }
                
                // Assuming data format
                $stmt = $dbh->prepare("INSERT INTO tmch_claims (domain_label, claim_key, insert_time) VALUES (?, ?, ?)");
                $stmt->execute([$data[0], $data[1], $formattedDate]);
            }
        }
        fclose($handle);
    }
}

function insertTmchData($dbh, $filePath) {
    $content = file_get_contents($filePath);
    if ($content !== false) {
        // Get the current date-time in the format accepted by both MySQL and PostgreSQL
        $currentDateTime = (new DateTime())->format('Y-m-d H:i:s');

        // Insert the whole content into the database
        $stmt = $dbh->prepare("INSERT INTO tmch_crl (content, url, update_timestamp) VALUES (?, ?, ?)");
        $stmt->execute([$content, $filePath, $currentDateTime]);
    }
}