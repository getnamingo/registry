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

function getUrlhausData() {
    $urlhausUrl = 'https://urlhaus.abuse.ch/downloads/json_recent/';
    $data = [];

    Coroutine::create(function () use ($urlhausUrl, &$data) {
        $client = new Client('urlhaus.abuse.ch', 443, true); // SSL
        $client->set(['timeout' => 5]); // 5 seconds timeout
        $client->get('/downloads/json_recent/');

        if ($client->statusCode == 200) {
            $data = json_decode($client->body, true);
        }

        $client->close();
    });

    return processUrlhausData($data);
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

function getDomainPrice($pdo, $domain_name, $tld_id, $date_add = 12, $command = 'create') {
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
    $stmt = $pdo->prepare("SELECT $priceColumn FROM domain_price WHERE tldid = ? AND command = '$command' LIMIT 1");
    $stmt->execute([$tld_id]);
    
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