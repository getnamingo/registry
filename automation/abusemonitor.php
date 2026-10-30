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

require __DIR__ . '/vendor/autoload.php';
$c = require_once 'config.php';
require_once 'helpers.php';

use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Filesystem;
use MatthiasMullie\Scrapbook\Adapters\Flysystem as ScrapbookFlysystem;
use MatthiasMullie\Scrapbook\Psr6\Pool;

$log = setupLogger('/var/log/namingo/abusemonitor.log', 'Abuse_Monitor');
$log->info('Job started.');

// Prevent two cron invocations from simultaneously opening duplicate tickets.
$lock = fopen('/tmp/namingo-abusemonitor.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log->error('Another abuse monitor is already running.');
    exit(1);
}

try {
    $dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $cachePath = __DIR__ . '/cache';
    if (!is_dir($cachePath) && !mkdir($cachePath, 0755, true) && !is_dir($cachePath)) {
        throw new RuntimeException('Cannot create abuse monitor cache directory');
    }
    $cache = new Pool(new ScrapbookFlysystem(new Filesystem(new LocalFilesystemAdapter($cachePath))));

    $tlds = $pdo->query('SELECT id, tld FROM domain_tld ORDER BY id')->fetchAll();
    $beginSql = 'INSERT INTO abuse_scan_runs
        (scan_uuid, tld_id, tld_name, source, started_at, status)
        VALUES (?, ?, ?, ?, ?, ?)';
    $isPostgres = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $begin = $pdo->prepare($beginSql . ($isPostgres ? ' RETURNING id' : ''));
    $complete = $pdo->prepare('UPDATE abuse_scan_runs SET finished_at = ?, status = ?,
        domains_checked = ?, threats_identified = ?, tickets_created = ?, error_message = ?
        WHERE id = ?');
    $select = $pdo->prepare('SELECT name, clid FROM domain WHERE tldid = ? ORDER BY id');
    $failed = 0;

    // A failed feed does not silently turn its results into "zero threats".
    $netapiData = null;
    $netapiError = null;
    try {
        $netapiData = getNetapiData($cache, 'netapi_compromised_domains',
            'https://netapi.com/api2/?method=compromised&dataset_type=url');
        if (!$netapiData instanceof \Ds\Map) {
            throw new RuntimeException('NetAPI returned no usable dataset');
        }
    } catch (Throwable $e) {
        $netapiError = $e->getMessage();
    }

    $spamhausError = null;
    try {
        // If the resolver is blocked or rate-limited, do not claim a successful scan.
        $test = dns_get_record('test.dbl.spamhaus.org', DNS_A);
        if ($test === false || !in_array('127.0.1.2', array_column($test, 'ip'), true)) {
            throw new RuntimeException('Spamhaus DBL DNS resolver preflight failed');
        }
    } catch (Throwable $e) {
        $spamhausError = $e->getMessage();
    }

    foreach ($tlds as $tld) {
        foreach (['NetAPI', 'Spamhaus DBL'] as $source) {
            $hex = bin2hex(random_bytes(16));
            $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
                . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
            // This UUID is an audit correlation identifier, not a cryptographic signature.
            $started = gmdate('Y-m-d H:i:s');
            $begin->execute([$uuid, $tld['id'], $tld['tld'], $source, $started, 'running']);
            $runId = $isPostgres ? (int)$begin->fetchColumn() : (int)$pdo->lastInsertId();
            $begin->closeCursor();
            $checked = 0;
            $found = 0;
            $created = 0;
            $status = 'success';
            $error = null;

            try {
                if ($source === 'NetAPI' && $netapiError !== null) {
                    throw new RuntimeException('NetAPI unavailable: ' . $netapiError);
                }
                if ($source === 'Spamhaus DBL' && $spamhausError !== null) {
                    throw new RuntimeException($spamhausError);
                }

                $select->execute([(int)$tld['id']]);
                // Buffered PDO result permits ticket inserts on this connection.
                while ($row = $select->fetch()) {
                    $domain = strtolower(rtrim((string)$row['name'], '.'));
                    $evidence = null;
                    $category = $source;
                    $sourceCode = null;
                    if ($source === 'NetAPI') {
                        if (checkNetapi($domain, $netapiData)) {
                            $evidence = 'https://netapi.com/lookup-domain/' . rawurlencode($domain) . '/';
                        }
                    } else {
                        $hit = checkSpamhaus($domain);
                        if ($hit !== null) {
                            $category = 'Spamhaus DBL: ' . $hit['category'];
                            $sourceCode = $hit['code'];
                            $evidence = 'https://check.spamhaus.org/listed/?searchterm=' . rawurlencode($domain);
                        }
                    }
                    $checked++;
                    if ($evidence !== null) {
                        $found++;
                        if (processAbuseDetection($pdo, $domain, (int)$row['clid'], $category,
                                $evidence, $log, (int)$tld['id'], $runId, $source, $sourceCode)) {
                            $created++;
                        }
                    }
                }
                $select->closeCursor();
            } catch (Throwable $e) {
                $select->closeCursor();
                $status = 'failed';
                $error = mb_substr($e->getMessage(), 0, 2000);
                $failed++;
                $log->error("$source / {$tld['tld']} failed: $error");
            } finally {
                $complete->execute([gmdate('Y-m-d H:i:s'), $status,
                    $checked, $found, $created, $error, $runId]);
                $log->info("{$tld['tld']} / $source: $status, checked=$checked, hits=$found, tickets=$created, audit=$runId");
            }
        }
    }
    if ($failed > 0) {
        throw new RuntimeException("$failed source/TLD scan(s) failed; see abuse_scan_runs");
    }
    $log->info('Job finished successfully.');
} catch (Throwable $e) {
    $log->error('Abuse monitor failed: ' . $e->getMessage());
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
