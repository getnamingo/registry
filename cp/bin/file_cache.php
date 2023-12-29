<?php

require __DIR__ . '/../vendor/autoload.php';

use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Filesystem;
use MatthiasMullie\Scrapbook\Adapters\Flysystem as ScrapbookFlysystem;
use MatthiasMullie\Scrapbook\Psr6\Pool;
use GuzzleHttp\Client;

// Set up Filesystem Cache
$cachePath = __DIR__ . '/../cache'; // Cache directory
$adapter = new LocalFilesystemAdapter($cachePath, null, LOCK_EX);
$filesystem = new Filesystem($adapter);
$cache = new Pool(new ScrapbookFlysystem($filesystem));

// Define the cache key and the URL of the file you want to cache
$cacheKey = 'tlds_alpha_by_domain';
$fileUrl = 'https://data.iana.org/TLD/tlds-alpha-by-domain.txt';

// Check if the file is already cached
$cachedFile = $cache->getItem($cacheKey);
if (!$cachedFile->isHit()) {
    // File is not cached, download it
    $httpClient = new Client();
    $response = $httpClient->get($fileUrl);
    $fileContent = $response->getBody()->getContents();

    // Save the file content to cache
    $cachedFile->set($fileContent);
    $cachedFile->expiresAfter(86400 * 7); // Cache for 7 days
    $cache->save($cachedFile);
    echo "ICANN TLD List downloaded and cached.".PHP_EOL;
} else {
    // Retrieve the file content from the cache
    $fileContent = $cachedFile->get();
    echo "ICANN TLD List loaded from cache.".PHP_EOL;
}