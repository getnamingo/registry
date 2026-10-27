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

$logFilePath = '/var/log/namingo/abusemonitor.log';
$log = setupLogger($logFilePath, 'Abuse_Monitor');
$log->info('job started.');

use Swoole\Coroutine;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Filesystem;
use MatthiasMullie\Scrapbook\Adapters\Flysystem as ScrapbookFlysystem;
use MatthiasMullie\Scrapbook\Psr6\Pool;

// Initialize the PDO connection pool
$pdoConfig = (new Swoole\Database\PDOConfig())
        ->withDriver($c['db_type'])
        ->withHost($c['db_host'])
        ->withPort($c['db_port'])
        ->withDbName($c['db_database'])
        ->withUsername($c['db_username'])
        ->withPassword($c['db_password']);
if ($c['db_type'] === 'mysql') {
    $pdoConfig->withCharset('utf8mb4');
}
$pool = new Swoole\Database\PDOPool($pdoConfig);

Swoole\Runtime::enableCoroutine();

// Filesystem Cache setup
$cachePath = __DIR__ . '/cache';

// Check if the 'cache' directory exists
if (!is_dir($cachePath)) {
    // Attempt to create the directory
    if (!mkdir($cachePath, 0755, true)) {
        $log->error("Unable to create cache directory at $cachePath. Please check permissions.");
    }
}

$adapter = new LocalFilesystemAdapter($cachePath, null, LOCK_EX);
$filesystem = new Filesystem($adapter);
$cache = new Pool(new ScrapbookFlysystem($filesystem));

// Cache key and URL for the NetAPI compromised domains list (one domain per line, refreshed daily)
$netapiCacheKey = 'netapi_compromised_domains';
$netapiUrl = 'https://netapi.com/api2/?method=compromised&dataset_type=url';

// Creating first coroutine
Coroutine::create(function () use ($pool, $log, $cache, $netapiCacheKey, $netapiUrl) {
    try {
        $pdo = $pool->get();
        $stmt = $pdo->query('SELECT name, clid FROM domain');

        // Retrieve cached NetAPI data
        $netapiData = getNetapiData($cache, $netapiCacheKey, $netapiUrl);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $domain = $row['name'];

            // Check NetAPI compromised domains
            if (checkNetapi($domain, $netapiData)) {
                processAbuseDetection($pdo, $domain, $row['clid'], 'NetAPI', 'https://netapi.com/lookup-domain/' . $domain . '/', $log);
            }

            // Check Spamhaus
            if (checkSpamhaus($domain)) {
                processAbuseDetection($pdo, $domain, $row['clid'], 'Spamhaus', 'http://www.spamhaus.org/query/domain/'.$domain, $log);
            }
        }
        $log->info('job finished successfully.');
    } catch (PDOException $e) {
        $log->error('Database error: ' . $e->getMessage());
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
    } finally {
        // Return the connection to the pool
        $pool->put($pdo);
    }
});