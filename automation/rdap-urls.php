<?php

/*
 * Namingo Registry
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$logFilePath = '/var/log/namingo/rdap-urls.log';
$log = setupLogger($logFilePath, 'RDAP_URLS');
$log->info('job started.');

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
    exit(1);
}

// URL to the IANA file
$csv_url = 'https://www.iana.org/assignments/registrar-ids/registrar-ids-1.csv';

// Download IANA file
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $csv_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
$userAgent = 'Namingo Registry/1.0 (+https://namingo.org)';
curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
$csv_data = curl_exec($ch);
if (curl_errno($ch)) {
    $error_msg = curl_error($ch);
}

if ($csv_data === false) {
    $log->error('Error downloading CSV file: ' . ($error_msg ?? 'Unknown error'));
    exit(1);
}

// Parse CSV data
$rows = array_map(
    static fn($line) => str_getcsv($line, ',', '"', ''),
    explode("\n", $csv_data)
);
$header = array_shift($rows); // Remove header

foreach ($rows as $row) {
    if (count($row) < 4 || empty($row[3])) {
        // Skip rows with missing RDAP Base URL
        continue;
    }

    // Prepare SQL statement
    $stmt = $dbh->prepare("UPDATE registrar SET rdap_server = :rdap_server WHERE iana_id = :iana_id");

    // Bind parameters
    $stmt->bindParam(':rdap_server', $row[3], PDO::PARAM_STR);
    $stmt->bindParam(':iana_id', $row[0], PDO::PARAM_INT);

    // Execute and check for errors
    try {
        $stmt->execute();
    } catch (\PDOException $e) {
        $log->error("Error updating ID {$row[0]}: " . $e->getMessage());
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
    }
}

$log->info('job finished successfully.');