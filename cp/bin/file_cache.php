<?php

require __DIR__ . '/../vendor/autoload.php';
require_once '/opt/registry/automation/helpers.php';

use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Filesystem;
use MatthiasMullie\Scrapbook\Adapters\Flysystem as ScrapbookFlysystem;
use MatthiasMullie\Scrapbook\Psr6\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$logFilePath = '/var/log/namingo/system-cache.log';
$log = setupLogger($logFilePath, 'SYSTEM_CACHE');
$log->info('job started.');

// Configuration
$config = [
    'cache_path' => __DIR__ . '/../cache', // Cache directory
    'cache_key' => 'tlds_alpha_by_domain',
    'file_url' => 'https://data.iana.org/TLD/tlds-alpha-by-domain.txt',
    'cache_duration' => 86400 * 7, // Cache for 7 days
    'max_retries' => 3, // Retry up to 3 times
];
$config['timestamp_file'] = $config['cache_path'] . '/tlds_alpha_by_domain.timestamp';

// Set up Filesystem Cache
$adapter = new LocalFilesystemAdapter($config['cache_path'], null, LOCK_EX);
$filesystem = new Filesystem($adapter);
$cache = new Pool(new ScrapbookFlysystem($filesystem));

// Check if the file is already cached
$cachedFile = $cache->getItem($config['cache_key']);
$timestampFile = $config['timestamp_file'];
$cache_refresh_threshold = 86400 * 1; // Refresh if 1 day before expiration

if ($cachedFile->isHit()) {
    // Check the timestamp from the separate file
    if (file_exists($timestampFile)) {
        $cacheAge = time() - filemtime($timestampFile);
        
        if ($cacheAge < ($config['cache_duration'] - $cache_refresh_threshold)) {
            $log->info('ICANN TLD List loaded from cache.');
            exit(0);
        }

        $log->info('Cache is nearing expiration. Refreshing.');
    }
}

// Download and cache the file
$httpClient = new Client();
$retryCount = 0;
$success = false;

while ($retryCount < $config['max_retries'] && !$success) {
    try {
        $response = $httpClient->get($config['file_url']);
        $fileContent = $response->getBody()->getContents();

        // Save the file content to cache
        $cachedFile->set($fileContent);
        $cachedFile->expiresAfter($config['cache_duration']);
        $cache->save($cachedFile);
        touch($timestampFile);

        $log->info('ICANN TLD list downloaded and cached successfully.');
        $success = true;
    } catch (RequestException $e) {
        $retryCount++;
        $log->error("Error downloading file (attempt $retryCount): " . $e->getMessage());
        if ($retryCount >= $config['max_retries']) {
            $log->error('Max retries reached. File download failed.');
            exit(1);
        }
    }
}

$log->info('job finished successfully.');