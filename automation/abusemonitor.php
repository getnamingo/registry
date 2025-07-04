<?php

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
use GuzzleHttp\Client;

// Initialize the PDO connection pool
$pool = new Swoole\Database\PDOPool(
    (new Swoole\Database\PDOConfig())
        ->withDriver($c['db_type'])
        ->withHost($c['db_host'])
        ->withPort($c['db_port'])
        ->withDbName($c['db_database'])
        ->withUsername($c['db_username'])
        ->withPassword($c['db_password'])
        ->withCharset('utf8mb4')
);

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

// Cache key and file URL for URLAbuse data
$urlAbuseCacheKey = 'urlabuse_blacklist';
$urlAbuseUrl = 'https://urlabuse.com/public/data/data.txt';

// Cache key and URL for URLHaus data
$urlhausCacheKey = 'urlhaus_blacklist';
$urlhausUrl = 'https://urlhaus.abuse.ch/downloads/json_recent/';

// Creating first coroutine
Coroutine::create(function () use ($pool, $log, $cache, $urlAbuseCacheKey, $urlAbuseUrl, $urlhausCacheKey, $urlhausUrl) {
    try {
        $pdo = $pool->get();
        $stmt = $pdo->query('SELECT name, clid FROM domain');

        // Retrieve cached URLAbuse data
        $urlAbuseData = getUrlAbuseData($cache, $urlAbuseCacheKey, $urlAbuseUrl);
        
        // Retrieve cached URLHaus data
        $urlhausData = getUrlhausData($cache, $urlhausCacheKey, $urlhausUrl);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $domain = $row['name'];

            // Check URLAbuse
            if (checkUrlAbuse($domain, $urlAbuseData)) {
                processAbuseDetection($pdo, $domain, $row['clid'], 'URLAbuse', 'https://urlabuse.com/public/data/data.txt', $log);
            }

            // Check URLhaus
            if (checkUrlhaus($domain, $urlhausData)) {
                processAbuseDetection($pdo, $domain, $row['clid'], 'URLHaus', 'https://urlhaus.abuse.ch/downloads/json_recent/', $log);
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