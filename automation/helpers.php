<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Ds\Map;

/**
 * Sets up and returns a Logger instance.
 * 
 * @param string $logFilePath Full path to the log file.
 * @param string $channelName Name of the log channel (optional).
 * @return Logger
 */
function setupLogger($logFilePath, $channelName = 'app') {
    // Create a log channel
    $log = new Logger($channelName);

    // Set up the console handler
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u", // Date format
        true, // Allow inline line breaks
        true  // Ignore empty context and extra
    );
    $consoleHandler->setFormatter($consoleFormatter);
    $log->pushHandler($consoleHandler);

    // Set up the file handler
    $fileHandler = new RotatingFileHandler($logFilePath, 0, Logger::DEBUG);
    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u" // Date format
    );
    $fileHandler->setFormatter($fileFormatter);
    $log->pushHandler($fileHandler);

    return $log;
}

function fetchCount($pdo, $tableName) {
    // Calculate the end of the previous day
    $endOfPreviousDay = date('Y-m-d 23:59:59', strtotime('-1 day'));

    // Prepare the SQL query
    $query = "SELECT COUNT(id) AS count FROM {$tableName} WHERE crdate <= :endOfPreviousDay";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':endOfPreviousDay', $endOfPreviousDay);
    $stmt->execute();

    // Fetch and return the count
    $result = $stmt->fetch();
    return $result['count'];
}

// Function to check domain against Spamhaus SBL
function checkSpamhaus($domain) {
    // Append '.sbl.spamhaus.org' to the domain
    $queryDomain = $domain . '.sbl.spamhaus.org';

    // Check if the domain is listed in the SBL
    return checkdnsrr($queryDomain, "A");
}

function getUrlhausData() {
    $urlhausUrl = 'https://urlhaus.abuse.ch/downloads/json_recent/';
    $json = file_get_contents($urlhausUrl);
    $data = json_decode($json, true);
    $map = new Map();

    foreach ($data as $entry) {
        foreach ($entry as $urlData) {
            $domain = parse_url($urlData['url'], PHP_URL_HOST); // Extract domain from URL
            $map->put($domain, $urlData); // Store data against domain
        }
    }

    return $map;
}

function checkUrlhaus($domain, Map $urlhausData) {
    return $urlhausData->get($domain, false);
}