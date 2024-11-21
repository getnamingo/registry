<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Ds\Map;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

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

function getUrlhausData($cache, $cacheKey, $urlhausUrl) {
    // Check if data is cached
    $cachedFile = $cache->getItem($cacheKey);

    if (!$cachedFile->isHit()) {
        // Data is not cached, download it
        $httpClient = new Client();
        $response = $httpClient->get($urlhausUrl);
        $fileContent = $response->getBody()->getContents();

        // Cache the file content
        $cachedFile->set($fileContent);
        $cachedFile->expiresAfter(86400 * 7); // Cache for 7 days
        $cache->save($cachedFile);

        return processUrlhausData($fileContent);
    } else {
        // Retrieve data from cache
        $fileContent = $cachedFile->get();
        return processUrlhausData($fileContent);
    }
}

function processUrlhausData($data) {
    $map = new \Ds\Map();

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

function processAbuseDetection($pdo, $domain, $clid, $abuseType, $evidenceLink, $log) {
    $userStmt = $pdo->prepare('SELECT user_id FROM registrar_users WHERE registrar_id = ?');
    $userStmt->execute([$clid]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);

    if ($userData) {
        // Prepare INSERT statement to add a ticket
        $insertStmt = $pdo->prepare('INSERT INTO support_tickets (id, user_id, category_id, subject, message, status, priority, reported_domain, nature_of_abuse, evidence, relevant_urls, date_of_incident, date_created, last_updated) VALUES (NULL, ?, 8, ?, ?, "Open", "High", ?, "Abuse", ?, ?, ?, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))');

        // Execute the prepared statement with appropriate values
        $insertStmt->execute([
            $userData['user_id'], // user_id
            "Abuse Report for $domain ($abuseType)", // subject
            "Abuse detected for domain $domain via $abuseType.", // message
            $domain, // reported_domain
            "Link to $abuseType", // evidence
            $evidenceLink, // relevant_urls
            date('Y-m-d H:i:s') // date_of_incident
        ]);

        $log->info("Abuse detected for domain $domain using $abuseType.");
    }
}

function getDomainPrice($pdo, $domain_name, $tld_id, $date_add = 12, $command = 'create', $registrar_id = null) {
    // Check if the domain is a premium domain
    $stmt = $pdo->prepare("
        SELECT c.category_price 
        FROM premium_domain_pricing p
        JOIN premium_domain_categories c ON p.category_id = c.category_id
        WHERE p.domain_name = ? AND p.tld_id = ?
    ");
    $stmt->execute([$domain_name, $tld_id]);
    if ($stmt->rowCount() > 0) {
        return ['type' => 'premium', 'price' => $stmt->fetch()['category_price']];
    }

    // Check if there is a promotion for the domain
    $currentDate = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT discount_percentage, discount_amount 
        FROM promotion_pricing 
        WHERE tld_id = ? 
        AND promo_type = 'full' 
        AND status = 'active' 
        AND start_date <= ? 
        AND end_date >= ?
    ");
    $stmt->execute([$tld_id, $currentDate, $currentDate]);
    if ($stmt->rowCount() > 0) {
        $promo = $stmt->fetch();
        $discount = null;
        
        // Determine discount based on percentage or amount
        if (!empty($promo['discount_percentage'])) {
            $discount = $promo['discount_percentage']; // Percentage discount
        } elseif (!empty($promo['discount_amount'])) {
            $discount = $promo['discount_amount']; // Fixed amount discount
        }
    } else {
        $discount = null;
    }

    // Get regular price for the specified period
    $priceColumn = "m" . $date_add;
    $sql = "
        SELECT $priceColumn 
        FROM domain_price 
        WHERE tldid = ? 
        AND command = ? 
        AND (registrar_id = ? OR registrar_id IS NULL)
        ORDER BY registrar_id DESC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tld_id, $command, $registrar_id]);
    
    if ($stmt->rowCount() > 0) {
        $regularPrice = $stmt->fetch()[$priceColumn];

        if ($discount !== null) {
            if (isset($promo['discount_percentage'])) {
                $discountAmount = $regularPrice * ($promo['discount_percentage'] / 100);
            } else {
                $discountAmount = $discount;
            }
            $price = $regularPrice - $discountAmount;
            return ['type' => 'promotion', 'price' => $price];
        }
        
        return ['type' => 'regular', 'price' => $regularPrice];
    }

    return ['type' => 'not_found', 'price' => 0];
}

function generateAuthInfo(): string {
    $length = 16;
    $charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $retVal = "";
    $digitCount = 0;

    // Generate initial random string
    for ($i = 0; $i < $length; $i++) {
        $randomIndex = random_int(0, strlen($charset) - 1);
        $char = $charset[$randomIndex];
        $retVal .= $char;
        if ($char >= '0' && $char <= '9') {
            $digitCount++;
        }
    }

    // Ensure there are at least two digits in the string
    while ($digitCount < 2) {
        // Replace a non-digit character at a random position with a digit
        $replacePosition = random_int(0, $length - 1);
        if (!($retVal[$replacePosition] >= '0' && $retVal[$replacePosition] <= '9')) {
            $randomDigit = random_int(0, 9); // Generate a digit from 0 to 9
            $retVal = substr_replace($retVal, (string)$randomDigit, $replacePosition, 1);
            $digitCount++;
        }
    }

    return $retVal;
}

// Function to fetch and cache URLAbuse data
function getUrlAbuseData($cache, $cacheKey, $fileUrl) {
    // Check if data is cached
    $cachedFile = $cache->getItem($cacheKey);

    if (!$cachedFile->isHit()) {
        // Data is not cached, download it
        $httpClient = new Client();
        $response = $httpClient->get($fileUrl);
        $fileContent = $response->getBody()->getContents();

        // Cache the file content
        $cachedFile->set($fileContent);
        $cachedFile->expiresAfter(300); // Cache for 5 minutes
        $cache->save($cachedFile);

        return processUrlAbuseData($fileContent);
    } else {
        // Retrieve data from cache
        $fileContent = $cachedFile->get();
        return processUrlAbuseData($fileContent);
    }
}

// Function to process URLAbuse data
function processUrlAbuseData($fileContent) {
    $lines = explode("\n", $fileContent);
    $map = new \Ds\Map();

    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse JSON data from each line
        $entry = json_decode($line, true);
        if ($entry && isset($entry['url'])) {
            $domain = parse_url($entry['url'], PHP_URL_HOST); // Extract domain from URL
            $map->put($domain, $entry); // Store data against domain
        }
    }

    return $map;
}

// Function to check if a domain is listed in URLAbuse
function checkUrlAbuse($domain, Map $urlAbuseData) {
    return $urlAbuseData->get($domain, false);
}

function generateSerial($soa_type = null) {
    // Default to Type 1 if $soa_type is not set, null, or invalid
    $soa_type = $soa_type ?? 1;

    switch ($soa_type) {
        case 2: // Date-based, updates every 15 minutes
            $hour = (int) date('H');
            $segment = (int)(date('i') / 15); // 0 through 3
            $offset = $hour * 4 + $segment;   // Converts hour + quarter into a unique number
            return date('Ymd') . str_pad($offset, 2, '0', STR_PAD_LEFT);

        case 3: // Cloudflare-like serial
            $referenceTimestamp = strtotime("2020-11-01 00:00:00"); // Reference point
            $timeDifference = time() - $referenceTimestamp; // Difference in seconds
            $serial = $timeDifference + 2350000000; // Offset to ensure longer serials
            return $serial;

        case 1: // Fixed-length, second-based serial (default)
        default:
            return time();
    }
}