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
$logFilePath = '/var/log/namingo/tmch.log';
$log = setupLogger($logFilePath, 'TMCH');
$log->info('job started.');

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
    exit(1);
}

$savePath = $c['tmch_path'];

// https://newgtlds.icann.org/sites/default/files/smd-test-repository-15may18-en.pdf
$files = [
    'smdrl' => 'https://test.ry.marksdb.org/smdrl/smdrl-latest.csv',
    'dnl' => 'https://test.ry.marksdb.org/dnl/dnl-latest.csv',
    'tmch' => 'https://crl.icann.org/tmch.crl'
];

// Configure the username and password for each URL.
$credentials = [
    'smdrl' => ['user' => $c['tmch_smdrl_user'], 'pass' => $c['tmch_smdrl_pass']],
    'dnl' => ['user' => $c['tmch_dnl_user'], 'pass' => $c['tmch_dnl_pass']]
];

try {
    foreach ($files as $key => $url) {
        $filePath = null;

        try {
            $ch = curl_init($url);

            if (isset($credentials[$key])) {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt(
                    $ch,
                    CURLOPT_USERPWD,
                    $credentials[$key]['user'] . ':' . $credentials[$key]['pass']
                );
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'HTTPS');
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'HTTPS');

            $data = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($data === false) {
                throw new RuntimeException(
                    "Failed to download $url: " . curl_error($ch)
                );
            }

            if ($httpCode !== 200 || $data === '') {
                throw new RuntimeException(
                    "Invalid response from $url: HTTP $httpCode"
                );
            }

            $timestamp = date('YmdHis');
            $filePath = rtrim($savePath, '/\\')
                . '/' . $timestamp . '_' . basename($url);

            if (file_put_contents($filePath, $data) === false) {
                throw new RuntimeException(
                    "Failed to save downloaded file: $filePath"
                );
            }

            $log->info("Successfully downloaded $url to $filePath");

            // Atomic replacement of this file's database records.
            $dbh->beginTransaction();

            try {
                clearTableData($dbh, $key);

                if ($key === 'smdrl') {
                    insertSmdrlData($dbh, $filePath, $log);
                } elseif ($key === 'dnl') {
                    insertDnlData($dbh, $filePath, $log);
                } elseif ($key === 'tmch') {
                    insertTmchData($dbh, $filePath);
                }

                $dbh->commit();
                $log->info("Successfully imported $key data.");

            } catch (Throwable $e) {
                if ($dbh->inTransaction()) {
                    $dbh->rollBack();
                }

                throw $e;
            }

        } finally {
            // Always clean up this download, even on failure.
            if ($filePath !== null && is_file($filePath)) {
                if (!unlink($filePath)) {
                    $log->warning(
                        "Could not delete temporary file: $filePath"
                    );
                }
            }
        }
    }

    $log->info('job finished successfully.');
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
    exit(1);
}

function clearTableData(PDO $dbh, string $key): void
{
    $tableMap = [
        'smdrl' => 'tmch_revocation',
        'dnl' => 'tmch_claims',
        'tmch' => 'tmch_crl'
    ];

    if (!isset($tableMap[$key])) {
        throw new InvalidArgumentException(
            "Unknown TMCH data type: $key"
        );
    }

    $dbh->exec('DELETE FROM ' . $tableMap[$key]);
}

function insertSmdrlData(PDO $dbh, string $filePath, $log): int
{
    $handle = fopen($filePath, 'r');

    if ($handle === false) {
        throw new RuntimeException("Cannot open SMD revocation file: $filePath");
    }

    $count = 0;

    try {
        for ($i = 0; $i < 2; $i++) {
            if (fgetcsv($handle, null, ',', '"', '') === false) {
                throw new RuntimeException('Missing SMD revocation CSV headers.');
            }
        }

        $stmt = $dbh->prepare(
            'INSERT INTO tmch_revocation (smd_id, revocation_time) VALUES (?, ?)'
        );

        while (($data = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($data === [null]) {
                continue;
            }

            if (count($data) < 2 || trim((string)$data[0]) === '' || trim((string)$data[1]) === '') {
                throw new RuntimeException('Invalid SMD revocation CSV record.');
            }

            $date = new DateTime($data[1]);
            $formattedDate = $date->format('Y-m-d H:i:s.v');

            $stmt->execute([$data[0], $formattedDate]);
            $count++;
        }

        if ($count === 0) {
            throw new RuntimeException('SMD revocation CSV contains no records.');
        }

        return $count;

    } finally {
        fclose($handle);
    }
}

function insertDnlData(PDO $dbh, string $filePath, $log): int
{
    $handle = fopen($filePath, 'r');

    if ($handle === false) {
        throw new RuntimeException("Cannot open DNL file: $filePath");
    }

    $count = 0;

    try {
        for ($i = 0; $i < 2; $i++) {
            if (fgetcsv($handle, null, ',', '"', '') === false) {
                throw new RuntimeException('Missing DNL CSV headers.');
            }
        }

        $stmt = $dbh->prepare(
            'INSERT INTO tmch_claims (domain_label, claim_key, insert_time) VALUES (?, ?, ?)'
        );

        while (($data = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($data === [null]) {
                continue;
            }

            if (
                count($data) < 3
                || trim((string)$data[0]) === ''
                || trim((string)$data[1]) === ''
                || trim((string)$data[2]) === ''
            ) {
                throw new RuntimeException('Invalid DNL CSV record.');
            }

            $date = new DateTime($data[2]);
            $formattedDate = $date->format('Y-m-d H:i:s.v');

            $stmt->execute([$data[0], $data[1], $formattedDate]);
            $count++;
        }

        if ($count === 0) {
            throw new RuntimeException('DNL CSV contains no records.');
        }

        return $count;

    } finally {
        fclose($handle);
    }
}

function insertTmchData(PDO $dbh, string $filePath): void
{
    $content = file_get_contents($filePath);

    if ($content === false || $content === '') {
        throw new RuntimeException(
            "Cannot read TMCH CRL file: $filePath"
        );
    }

    $currentDateTime = (new DateTime())->format('Y-m-d H:i:s.v');

    $stmt = $dbh->prepare(
        'INSERT INTO tmch_crl (content, url, update_timestamp) VALUES (?, ?, ?)'
    );

    $stmt->execute([
        $content,
        $filePath,
        $currentDateTime
    ]);
}