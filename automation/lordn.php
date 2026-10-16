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
date_default_timezone_set('UTC');

$log = setupLogger('/var/log/namingo/lordn.log', 'Lordn');
$log->info('job started.');

// Keep this script from submitting the same reporting window concurrently.
$lock = fopen(sys_get_temp_dir() . '/namingo-lordn.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log->error('LORDN job already running or lock unavailable.');
    exit(1);
}

$failures = 0;
try {
    $dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $handle = (string)$pdo->query("SELECT value FROM settings WHERE name = 'handle'")->fetchColumn();
    if ($handle === '' || $handle === '1') {
        throw new RuntimeException('Missing repository handle in settings.');
    }

    // Configure the actual TMDB base URL; never upload to a placeholder hostname.
    $baseUrl = rtrim((string)($c['lordn_url'] ?? ''), '/');
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('Set lordn_url to the HTTPS TMDB base URL in config.php.');
    }

    $tlds = $pdo->query('SELECT id, tld FROM domain_tld')->fetchAll();
    foreach ($tlds as $tld) {
        try {
            // Report only TLDs with a currently active launch phase.
            $stmt = $pdo->prepare(
                "SELECT phase_type, phase_description FROM launch_phases
                 WHERE tld_id = ? AND start_date <= UTC_TIMESTAMP(3)
                   AND (end_date IS NULL OR end_date > UTC_TIMESTAMP(3))
                 ORDER BY start_date DESC"
            );
            $stmt->execute([$tld['id']]);
            $phases = $stmt->fetchAll();
            $types = [];
            foreach ($phases as $phase) {
                $type = strtolower((string)$phase['phase_type']);
                if ($type === 'sunrise') {
                    $types['sunrise'] = true;
                } elseif ($type === 'claims' || stripos((string)($phase['phase_description'] ?? ''), 'claims') !== false) {
                    $types['claims'] = true;
                }
            }

            foreach (array_keys($types) as $type) {
                generateLordn($pdo, $tld, $type, $handle, $baseUrl, $c['lordn_user'], $c['lordn_pass'], $log);
            }
        } catch (Throwable $e) {
            $failures++;
            $log->error("Error processing TLD {$tld['tld']}: " . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    $failures++;
    $log->error('LORDN job failed: ' . $e->getMessage());
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($failures > 0) {
    $log->error("LORDN job finished with $failures failure(s).");
    exit(1);
}
$log->info('job finished successfully.');

function generateLordn(PDO $pdo, array $tld, string $type, string $handle, string $baseUrl, string $username, string $password, $log): void
{
    $tldName = ltrim((string)$tld['tld'], '.');
    // Registered domains only. A creation timestamp within the last 24h bounds daily reporting.
    $sql = "SELECT d.id, d.name, d.crdate, d.tm_smd_id, d.tm_notice_id,
                   d.tm_notice_accepted, r.iana_id,
                   (SELECT MIN(a.crdate) FROM application a
                    WHERE a.tldid = d.tldid AND a.name = d.name AND a.phase_type = 'sunrise') AS app_date
            FROM domain d
            JOIN registrar r ON r.id = d.clid
            WHERE d.tldid = ? AND d.crdate >= UTC_TIMESTAMP(3) - INTERVAL 1 DAY
              AND d.crdate <= UTC_TIMESTAMP(3) AND d.delTime IS NULL";
    if ($type === 'sunrise') {
        $sql .= " AND LOWER(d.tm_phase) = 'sunrise' AND d.tm_smd_id IS NOT NULL AND d.tm_smd_id <> ''";
    } else {
        $sql .= " AND LOWER(d.tm_phase) = 'claims' AND d.tm_notice_id IS NOT NULL AND d.tm_notice_id <> ''";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tld['id']]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        $log->info("No new $type LORDN records for {$tld['tld']}.");
        return;
    }

    $records = [];
    foreach ($rows as $row) {
        if (!is_numeric($row['iana_id']) || (int)$row['iana_id'] <= 0) {
            throw new RuntimeException("Missing IANA registrar ID for domain {$row['name']}.");
        }
        $base = ['D' . $row['id'] . '-' . $handle, $row['name']];
        if ($type === 'sunrise') {
            $records[] = [...$base, $row['tm_smd_id'], $row['iana_id'], lordnDate($row['crdate']),
                $row['app_date'] !== null ? lordnDate($row['app_date']) : ''];
        } else {
            $ack = (string)($row['tm_notice_accepted'] ?? '');
            if ($ack === '') {
                throw new RuntimeException("Missing Claims acknowledgement timestamp for {$row['name']}.");
            }
            $records[] = [...$base, $row['tm_notice_id'], $row['iana_id'], lordnDate($row['crdate']), lordnDate($ack), ''];
        }
    }

    $fileName = tempnam(sys_get_temp_dir(), 'lordn_');
    if ($fileName === false) {
        throw new RuntimeException('Could not create LORDN temporary file.');
    }
    try {
        $file = fopen($fileName, 'wb');
        if ($file === false) {
            throw new RuntimeException('Could not open LORDN temporary file.');
        }
        try {
            $header = $type === 'sunrise'
                ? ['roid', 'domain-name', 'SMD-id', 'registrar-id', 'registration-datetime', 'application-datetime']
                : ['roid', 'domain-name', 'notice-id', 'registrar-id', 'registration-datetime', 'ack-datetime', 'application-datetime'];
            $write = static function (array $data) use ($file): void {
                if (fputcsv($file, $data, ',', '"', '', "\r\n") === false) {
                    throw new RuntimeException('Could not write LORDN CSV.');
                }
            };
            $write([1, gmdate('Y-m-d\TH:i:s\Z'), count($records)]);
            $write($header);
            foreach ($records as $record) {
                $write($record);
            }
        } finally {
            fclose($file);
        }
        $url = $baseUrl . '/LORDN/' . rawurlencode($tldName) . '/' . $type;
        $result = uploadFile($fileName, $url, $username, $password);
        $log->info("LORDN $type accepted for {$tld['tld']}; transaction ID: {$result['transactionId']}; result URL: " . ($result['locationUrl'] ?? 'none'));
    } finally {
        if (is_file($fileName)) {
            unlink($fileName);
        }
    }
}

function lordnDate(string $value): string
{
    // Registry DATETIME columns are assumed to be stored in UTC.
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
}

function uploadFile(string $filePath, string $url, string $username, string $password): array
{
    $data = file_get_contents($filePath);
    if ($data === false || $data === '') {
        throw new RuntimeException('Cannot read LORDN file.');
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => "$username:$password",
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($curl);
    if ($response === false) {
        throw new RuntimeException('LORDN upload failed: ' . curl_error($curl));
    }
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $body = substr($response, $headerSize);
    $headers = substr($response, 0, $headerSize);
    if ($httpCode !== 202) {
        throw new RuntimeException("LORDN upload rejected: HTTP $httpCode: " . substr($body, 0, 1000));
    }
    $transactionId = trim($body);
    if ($transactionId === '') {
        throw new RuntimeException('TMDB returned 202 without a transaction ID.');
    }
    preg_match('/^Location:\s*(.+)$/im', $headers, $match);
    return ['transactionId' => $transactionId, 'locationUrl' => isset($match[1]) ? trim($match[1]) : null];
}