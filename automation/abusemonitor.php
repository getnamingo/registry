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

// Filesystem Cache setup for URLAbuse
$cachePath = __DIR__ . '/../cache';
$adapter = new LocalFilesystemAdapter($cachePath, null, LOCK_EX);
$filesystem = new Filesystem($adapter);
$cache = new Pool(new ScrapbookFlysystem($filesystem));

// Cache key and file URL for URLAbuse data
$cacheKey = 'urlabuse_blacklist';
$fileUrl = 'https://urlabuse.com/public/data/data.txt';

// Creating first coroutine
Coroutine::create(function () use ($pool, $log, $cache, $cacheKey, $fileUrl) {
    try {
        $pdo = $pool->get();
        $stmt = $pdo->query('SELECT name, clid FROM domain');

        // Retrieve URLAbuse data with caching
        $urlAbuseData = getUrlAbuseData($cache, $cacheKey, $fileUrl);

        // Get URLhaus data
        $urlhausData = getUrlhausData();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $domain = $row['name'];

            // Check URLAbuse
            if (checkUrlAbuse($domain, $urlAbuseData)) {
                $userStmt = $pdo->prepare('SELECT user_id FROM registrar_users WHERE registrar_id = ?');
                $userStmt->execute([$row['clid']]);
                $userData = $userStmt->fetch(PDO::FETCH_ASSOC);

                if ($userData) {
                    // Prepare INSERT statement to add a ticket
                    $insertStmt = $pdo->prepare('INSERT INTO support_tickets (id, user_id, category_id, subject, message, status, priority, reported_domain, nature_of_abuse, evidence, relevant_urls, date_of_incident, date_created, last_updated) VALUES (NULL, ?, 8, ?, ?, "Open", "High", ?, "Abuse", ?, ?, ?, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))');

                    // Execute the prepared statement with appropriate values
                    $insertStmt->execute([
                        $userData['user_id'], // user_id
                        "Abuse Report for $domain", // subject
                        "Abuse detected for domain $domain.", // message
                        $domain, // reported_domain
                        "Link to URLAbuse", // evidence
                        "https://urlabuse.com/public/data/data.txt", // relevant_urls
                        date('Y-m-d H:i:s') // date_of_incident
                    ]);
                }
            }

            // Check URLhaus
            $urlhausResult = checkUrlhaus($domain, $urlhausData);

            if ($urlhausResult) {
                $userStmt = $pdo->prepare('SELECT user_id FROM registrar_users WHERE registrar_id = ?');
                $userStmt->execute([$row['clid']]);
                $userData = $userStmt->fetch(PDO::FETCH_ASSOC);

                if ($userData) {
                    // Prepare INSERT statement to add a ticket
                    $insertStmt = $pdo->prepare('INSERT INTO support_tickets (id, user_id, category_id, subject, message, status, priority, reported_domain, nature_of_abuse, evidence, relevant_urls, date_of_incident, date_created, last_updated) VALUES (NULL, ?, 8, ?, ?, "Open", "High", ?, "Abuse", ?, ?, ?, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))');

                    // Execute the prepared statement with appropriate values
                    $insertStmt->execute([
                        $userData['user_id'], // user_id
                        "Abuse Report for $domain", // subject
                        "Abuse detected for domain $domain.", // message
                        $domain, // reported_domain
                        "Link to URLhaus", // evidence
                        "https://urlhaus.abuse.ch/downloads/json_recent/", // relevant_urls
                        date('Y-m-d H:i:s') // date_of_incident
                    ]);
                }
            }

            // Check Spamhaus
            if (checkSpamhaus($domain)) {
                $userStmt = $pdo->prepare('SELECT user_id FROM registrar_users WHERE registrar_id = ?');
                $userStmt->execute([$row['clid']]);
                $userData = $userStmt->fetch(PDO::FETCH_ASSOC);

                if ($userData) {
                    // Prepare INSERT statement to add a ticket
                    $insertStmt = $pdo->prepare('INSERT INTO support_tickets (id, user_id, category_id, subject, message, status, priority, reported_domain, nature_of_abuse, evidence, relevant_urls, date_of_incident, date_created, last_updated) VALUES (NULL, ?, 8, ?, ?, "Open", "High", ?, "Abuse", ?, ?, ?, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))');

                    // Execute the prepared statement with appropriate values
                    $insertStmt->execute([
                        $userData['user_id'], // user_id
                        "Abuse Report for $domain", // subject
                        "Abuse detected for domain $domain.", // message
                        $domain, // reported_domain
                        "Link to Spamhaus", // evidence
                        "http://www.spamhaus.org/query/domain/$domain", // relevant_urls
                        date('Y-m-d H:i:s') // date_of_incident
                    ]);
                }
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