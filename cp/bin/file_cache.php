<?php

require '../vendor/autoload.php';

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
    $cachedFile->expiresAfter(86400); // Cache for 24 hours, for example
    $cache->save($cachedFile);
    echo "File downloaded and cached.\n";
} else {
    // Retrieve the file content from the cache
    $fileContent = $cachedFile->get();
    echo "File loaded from cache.\n";
}

// Use $fileContent as needed
// ...

// For demonstration: Writing first 200 characters of the content
echo substr($fileContent, 0, 50) . "...";