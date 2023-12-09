<?php

$c = require_once 'config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/abusemonitor.log';
$log = setupLogger($logFilePath, 'Abuse_Monitor');
$log->info('job started.');

use Swoole\Coroutine;

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

// Creating first coroutine
Coroutine::create(function () use ($pool, $log) {
    try {
        $pdo = $pool->get();
        $stmt = $pdo->query('SELECT name, clid FROM domain');

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $domain = $row['name'];

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
            
            // Get URLhaus data
            $urlhausData = getUrlhausData();
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
        }
        $log->info('job finished successfully.');
    } catch (PDOException $e) {
        $log->error('Database error: ' . $e->getMessage());
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
    }
});