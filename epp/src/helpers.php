<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use MonologPHPMailer\PHPMailerHandler;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Pdp\Domain;
use Pdp\TopLevelDomains;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Filesystem;
use MatthiasMullie\Scrapbook\Adapters\Flysystem as ScrapbookFlysystem;
use MatthiasMullie\Scrapbook\Psr6\Pool;
use Money\Money;
use Money\Currency;
use Money\Converter;
use Money\Currencies\ISOCurrencies;
use Money\Exchange\FixedExchange;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Guid\Guid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;

/**
 * Sets up and returns a Logger instance.
 * 
 * @param string $logFilePath Full path to the log file.
 * @param string $channelName Name of the log channel (optional).
 * @return Logger
 */
function setupLogger($logFilePath, $channelName = 'app') {
    $log = new Logger($channelName);
    
    // Load email & pushover configuration
    $config = include('/opt/registry/automation/config.php');

    // Console handler (for real-time debugging)
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u",
        true,
        true
    );
    $consoleHandler->setFormatter($consoleFormatter);
    $log->pushHandler($consoleHandler);

    // File handler - Rotates daily, keeps logs for 14 days
    $fileHandler = new RotatingFileHandler($logFilePath, 14, Logger::DEBUG);
    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u"
    );
    $fileHandler->setFormatter($fileFormatter);
    $log->pushHandler($fileHandler);

    // Email Handler (For CRITICAL, ALERT, EMERGENCY)
    if (!empty($config['mailer_smtp_host'])) {
        // Create a PHPMailer instance
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $config['mailer_smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['mailer_smtp_username'];
            $mail->Password   = $config['mailer_smtp_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $config['mailer_smtp_port'];
            $mail->setFrom($config['mailer_from'], 'Registry System');
            $mail->addAddress($config['admin_email']);

            // Attach PHPMailer to Monolog
            $mailerHandler = new PHPMailerHandler($mail);
            $mailerHandler->setFormatter(new HtmlFormatter);

            $filteredMailHandler = new FilterHandler($mailerHandler, Logger::ALERT, Logger::EMERGENCY);
            $safeMailHandler = new WhatFailureGroupHandler([$filteredMailHandler]);
            $log->pushHandler($safeMailHandler);
        } catch (Exception $e) {
            error_log("Failed to initialize PHPMailer: " . $e->getMessage());
        }
    }

    return $log;
}

function checkLogin($db, $clID, $pw) {
    $stmt = $db->prepare("SELECT pw FROM registrar WHERE clid = :username");
    $stmt->execute(['username' => $clID]);
    $hashedPassword = $stmt->fetchColumn();

    return password_verify($pw, $hashedPassword);
}

function sendGreeting($conn) {
    global $c;
    $currentDateTime = new DateTime("now", new DateTimeZone("UTC"));
    $currentDate = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");

    $response = [
        'command' => 'greeting',
        'svID' => $c['epp_greeting'],
        'svDate' => $currentDate,
        'version' => '1.0',
        'lang' => 'en',
        'services' => [
            'urn:ietf:params:xml:ns:domain-1.0',
            'urn:ietf:params:xml:ns:contact-1.0',
            'urn:ietf:params:xml:ns:host-1.0'
        ],
        'extensions' => [
            'https://namingo.org/epp/funds-1.0',
            'https://namingo.org/epp/identica-1.0',
            'urn:ietf:params:xml:ns:secDNS-1.1',
            'urn:ietf:params:xml:ns:rgp-1.0',
            'urn:ietf:params:xml:ns:launch-1.0',
            'urn:ietf:params:xml:ns:idn-1.0',
            'urn:ietf:params:xml:ns:epp:fee-1.0',
            'urn:ietf:params:xml:ns:mark-1.0',
            'urn:ietf:params:xml:ns:allocationToken-1.0'
        ],
        'dcp' => [ // Data Collection Policy (optional)
            'access' => ['all'],
            'statement' => [
                'purpose' => ['admin', 'prov'],
                'recipient' => ['ours'],
                'retention' => ['stated']
            ]
        ]
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    sendEppResponse($conn, $xml);
}

function sendEppError($conn, $db, $code, $msg, $clTRID = "000", $trans = "0") {
    if ($clTRID === "000") {
        $clTRID = 'client-not-provided-' . bin2hex(random_bytes(8));
    }
    if (!isset($trans)) {
        $trans = "0";
    }
    $svTRID = generateSvTRID();
    $response = [
        'command' => 'error',
        'resultCode' => $code,
        'human_readable_message' => $msg,
        'clTRID' => $clTRID,
        'svTRID' => $svTRID,
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, null, null, 'error', $code, $msg, $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}

function sendEppResponse($conn, $response) {
    $length = strlen($response) + 4; // Total length including the 4-byte header
    $lengthData = pack('N', $length); // Pack the length into 4 bytes

    $conn->send($lengthData . $response);
}

function generateSvTRID() {
    global $c;
    $prefix = $c['epp_prefix'];

    // Get current timestamp
    $timestamp = time();

    // Generate a random 5-character alphanumeric string
    $randomString = bin2hex(random_bytes(5));

    // Combine the prefix, timestamp, and random string to form the svTRID
    $svTRID = "{$prefix}-{$timestamp}-{$randomString}";

    return $svTRID;
}

function getRegistrarClid(Swoole\Database\PDOProxy $db, $id) {
    $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['clid'] ?? null;  // Return the clid if found, otherwise return null
}

function getContactIdentifier(Swoole\Database\PDOProxy $db, $id) {
    $stmt = $db->prepare("SELECT identifier FROM contact WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['identifier'] ?? null;  // Return the identifier if found, otherwise return null
}

function getHost(Swoole\Database\PDOProxy $db, $id) {
    $stmt = $db->prepare("SELECT name FROM host WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['name'] ?? null;  // Return the name if found, otherwise return null
}

function validate_identifier($identifier) {
    if (!$identifier) {
        return 'Please provide a contact ID';
    }

    $length = strlen($identifier);

    if ($length < 3 || $length > 16) {
        return 'Identifier must be between 3 and 16 characters long';
    }

    // Updated pattern: allows letters and digits at start and end, hyphens in the middle only
    $pattern = '/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/';

    if (!preg_match($pattern, $identifier)) {
        return 'The ID must start and end with a letter or digit and can contain hyphens (-) in the middle.';
    }
}

function validate_label($domain, $pdo) {
    if (!$domain) {
        return 'You must enter a domain name';
    }

    // Ensure domain has at least one dot (.) separating labels
    if (strpos($domain, '.') === false) {
        return 'Invalid domain name format: must contain at least one dot (.)';
    }

    // Split domain into labels (subdomains, SLD, TLD)
    $labels = explode('.', $domain);

    foreach ($labels as $index => $label) {
        $len = strlen($label);

        // Stricter validation for the first label
        if ($index === 0) {
            if ($len < 2 || $len > 63) {
                return 'The domain must be between 2 and 63 characters';
            }
            
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]$/', $label)) {
                return 'The domain must start and end with a letter or number and contain only letters, numbers, or hyphens';
            }
        } 
        // Basic validation for other labels
        else {
            if (!preg_match('/^[a-zA-Z0-9-]+$/', $label)) {
                return 'Each domain label must contain only letters, numbers, or hyphens';
            }
        }

        // Check if it's a Punycode label (IDN)
        if (strpos($label, 'xn--') === 0) {
            // Ensure valid Punycode structure
            if (!preg_match('/^xn--[a-zA-Z0-9-]+$/', $label)) {
                return 'Invalid Punycode format';
            }

            // Convert Punycode to UTF-8
            $decoded = idn_to_utf8($label, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($decoded === false || $decoded === '') {
                return 'Invalid Punycode conversion';
            }

            // Ensure decoded label follows normal domain rules
            if (!preg_match('/^[\p{L}0-9][\p{L}0-9-]*[\p{L}0-9]$/u', $decoded)) {
                return 'IDN must start and end with a letter or number';
            }
        } else {
            // Prevent consecutive or invalid hyphen usage
            if (preg_match('/--|\.\./', $label)) {
                return 'Domain labels cannot contain consecutive dashes (--) or dots (..)';
            }
        }
    }

    // Extract domain and TLD
    $parts = extractDomainAndTLD($domain);
    if (!$parts || empty($parts['domain']) || empty($parts['tld'])) {
        return 'Invalid domain structure, unable to parse domain name';
    }

    $tld = "." . $parts['tld'];

    // Validate domain length
    $domainLength = strlen($parts['domain']);
    if ($domainLength < 2 || $domainLength > 63) {
        return 'Domain length must be between 2 and 63 characters';
    }

    // Check if the TLD exists in the domain_tld table
    $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM domain_tld WHERE tld = :tld");
    $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtTLD->execute();
    $tldExists = $stmtTLD->fetchColumn();

    if (!$tldExists) {
        return 'Zone is not supported';
    }

    // Prevent mixed IDN & ASCII domains
    if ((strpos($parts['domain'], 'xn--') === 0) !== (strpos($parts['tld'], 'xn--') === 0)) {
        return 'Invalid domain name: IDN (xn--) domains must have both an IDN domain and TLD.';
    }

    // IDN-specific validation (only if the domain contains Punycode)
    if (strpos($parts['domain'], 'xn--') === 0 && strpos($parts['tld'], 'xn--') === 0) {
        $label = idn_to_utf8($parts['domain'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        // Fetch the IDN regex for the given TLD
        $stmtRegex = $pdo->prepare("SELECT idn_table FROM domain_tld WHERE tld = :tld");
        $stmtRegex->bindParam(':tld', $tld, PDO::PARAM_STR);
        $stmtRegex->execute();
        $idnRegex = $stmtRegex->fetchColumn();

        if (!$idnRegex) {
            return 'Failed to fetch domain IDN table';
        }

        // Check against IDN regex
        if (!preg_match($idnRegex, $label)) {
            return 'Invalid domain name format, please review registry policy about accepted labels';
        }
    }

    return null; // No errors
}

function extractDomainAndTLD($urlString) {
    global $c;
    
    $cachePath = '/var/www/cp/cache'; // Cache directory
    $adapter = new LocalFilesystemAdapter($cachePath, null, LOCK_EX);
    $filesystem = new Filesystem($adapter);
    $cache = new Pool(new ScrapbookFlysystem($filesystem));
    $cacheKey = 'tlds_alpha_by_domain';
    $cachedFile = $cache->getItem($cacheKey);
    $fileContent = $cachedFile->get();

    // Check if fileContent is not null
    if (null === $fileContent) {
        // Handle the error gracefully
        throw new \Exception("The TLDs cache file is missing or unreadable");
    }

    // Load a list of test TLDs used in your QA environment
    $testTlds = explode(',', $c['test_tlds']);

    // Parse the URL to get the host
    $parts = parse_url($urlString);
    $host = $parts['host'] ?? $urlString;

    if (!preg_match('/\./', $urlString)) {
        throw new \Exception("Invalid domain format");
    }

    // Function to handle TLD extraction
    $extractSLDandTLD = function($host, $tlds) {
        foreach ($tlds as $tld) {
            if (str_ends_with($host, ".$tld")) {
                $tldLength = strlen($tld) + 1; // +1 for the dot
                $hostWithoutTld = substr($host, 0, -$tldLength);
                $hostParts = explode('.', $hostWithoutTld);
                $sld = array_pop($hostParts);
                return [
                    'domain' => $sld,
                    'tld' => $tld
                ];
            }
        }
        return null;
    };

    // First, check against test TLDs
    $result = $extractSLDandTLD($host, $testTlds);
    if ($result !== null) {
        return $result;
    }

    try {
        // Use the PHP Domain Parser library for real TLDs
        $tlds = TopLevelDomains::fromString($fileContent);
        $domain = Domain::fromIDNA2008($host);
        $resolvedTLD = $tlds->resolve($domain)->suffix()->toString();
    } catch (\Pdp\Exception $e) { // Catch domain parser exceptions
        throw new \Exception('Domain parsing error: ' . $e->getMessage());
    } catch (\Exception $e) { // Catch any other unexpected exceptions
        throw new \Exception('Unexpected error: ' . $e->getMessage());
    }

    // Handle cases with multi-level TLDs
    $possibleTLDs = [];
    $hostParts = explode('.', $host);
    $tld = '';
    for ($i = count($hostParts) - 1; $i >= 0; $i--) {
        $tld = $hostParts[$i] . ($tld ? '.' . $tld : '');
        $possibleTLDs[] = $tld;
    }

    // Sort by length to match longest TLD first
    usort($possibleTLDs, function ($a, $b) {
        return strlen($b) - strlen($a);
    });

    // Check against real TLDs
    $result = $extractSLDandTLD($host, $possibleTLDs);
    if ($result !== null) {
        return $result;
    }

    // Fallback if nothing matches
    $sld = $domain->secondLevelDomain()->toString();
    $tld = $resolvedTLD;

    return ['domain' => $sld, 'tld' => $tld];
}

function normalize_v4_address($v4) {
    // Remove leading zeros from the first octet
    $v4 = preg_replace('/^0+(\d)/', '$1', $v4);
    
    // Remove leading zeros from successive octets
    $v4 = preg_replace('/\.0+(\d)/', '.$1', $v4);

    return $v4;
}

function normalize_v6_address($v6) {
    // Upper case any alphabetics
    $v6 = strtoupper($v6);
    
    // Remove leading zeros from the first word
    $v6 = preg_replace('/^0+([\dA-F])/', '$1', $v6);
    
    // Remove leading zeros from successive words
    $v6 = preg_replace('/:0+([\dA-F])/', ':$1', $v6);
    
    // Introduce a :: if there isn't one already
    if (strpos($v6, '::') === false) {
        $v6 = preg_replace('/:0:0:/', '::', $v6);
    }

    // Remove initial zero word before a ::
    $v6 = preg_replace('/^0+::/', '::', $v6);
    
    // Remove other zero words before a ::
    $v6 = preg_replace('/(:0)+::/', '::', $v6);

    // Remove zero words following a ::
    $v6 = preg_replace('/:(:0)+/', ':', $v6);

    return $v6;
}

function createTransaction($db, $clid, $clTRID, $clTRIDframe) {
    // Prepare the statement for insertion
    $stmt = $db->prepare("INSERT INTO registryTransaction.transaction_identifier (registrar_id,clTRID,clTRIDframe,cldate,clmicrosecond) VALUES(?,?,?,?,?)");

    // Get date and microsecond for cl transaction
    $currentDateTime = new DateTime("now", new DateTimeZone("UTC"));
    $cldate = $currentDateTime->format("Y-m-d H:i:s.v");
    $dateForClTransaction = microtime(true);
    $clmicrosecond = sprintf("%06d", ($dateForClTransaction - floor($dateForClTransaction)) * 1000000);

    if (empty($clTRID)) {
        // If $clTRID is empty, generate a random string prefixed with "client-not-provided-"
        $clTRID = 'client-not-provided-' . bin2hex(random_bytes(8));  // Generates a 16 character hexadecimal string
    }

    if (empty($clid)) {
        // If $clid is empty, throw an exception
        throw new Exception("Malformed command received.");
    }

    // Execute the statement
    if (!$stmt->execute([
        $clid,
        $clTRID,
        $clTRIDframe,
        $cldate,
        $clmicrosecond
    ])) {
        throw new Exception("Failed to execute createTransaction: " . implode(", ", $stmt->errorInfo()));
    }

    // Return the ID of the newly created transaction
    return $db->lastInsertId();
}

function updateTransaction($db, $cmd, $obj_type, $obj_id, $code, $msg, $svTRID, $svTRIDframe, $transaction_id) {
    // Prepare the statement
    $stmt = $db->prepare("UPDATE registryTransaction.transaction_identifier SET cmd = ?, obj_type = ?, obj_id = ?, code = ?, msg = ?, svTRID = ?, svTRIDframe = ?, svdate = ?, svmicrosecond = ? WHERE id = ?");

    // Get date and microsecond for sv transaction
    $currentDateTime = new DateTime("now", new DateTimeZone("UTC"));
    $svdate = $currentDateTime->format("Y-m-d H:i:s.v");
    $dateForSvTransaction = microtime(true);
    $svmicrosecond = sprintf("%06d", ($dateForSvTransaction - floor($dateForSvTransaction)) * 1000000);

    // Execute the statement
    if (!$stmt->execute([
        $cmd,
        $obj_type,
        $obj_id,
        $code,
        $msg,
        $svTRID,
        $svTRIDframe,
        $svdate,
        $svmicrosecond,
        $transaction_id
    ])) {
        throw new Exception("Failed to execute updateTransaction: " . implode(", ", $stmt->errorInfo()));
    }

    return true;
}

function getClid(Swoole\Database\PDOProxy $db, string $clid): ?int {
    $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? (int)$result['id'] : null;
}

/**
 * Calculate ds-rdata from dnskey-rdata
 * For additional information please refer to RFC 5910: http://www.ietf.org/rfc/rfc5910.txt
 * 
 * @param string owner, the coanonical name of the owner (e.g. example.com.)
 * @param int flags, the flags of the dnskey (only 256 or 257)
 * @param int protocol, the protocol of the dnskey (only 3)
 * @param int algoritm, the algorithm of the dnskey (only 3, 5, 6, 7, 8, 10, 12, 13 or 14)
 * @param string publickey, the full publickey base64 encoded (care, no spaces allowed)
 * 
 * @return array, on success
 *   Array (
 *     [owner] => $owner
 *     [keytag] => $keytag
 *     [algorithm] => $algorithm
 *     [digest] => Array (
 *       [] => Array (
 *         [type] => 1
 *         [hash] => $digest_sha1
 *       ),
 *       [] => Array (
 *         [type] => 2
 *         [hash] => $digest_sha256
 *       )
 *     )
 *   )
 * @return int < 0, on failure
 *   -1, unsupported owner
 *   -2, unsupported flags
 *   -3, unsupported protocol
 *   -4, unsupported algorithm
 *   -5, unsupported publickey
 */
function dnssec_key2ds($owner, $flags, $protocol, $algorithm, $publickey) {
    // define paramenter check variants
    $regex_owner = '/^[a-z0-9\-]+\.[a-z]+\.$/';
    $allowed_flags = array(256, 257);
    $allowed_protocol = array(3);
    $allowed_algorithm = array(2, 3, 5, 6, 7, 8, 10, 13, 14, 15, 16);
    $regex_publickey = '/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=|[A-Za-z0-9+\/]{4})$/';
    
    // do parameter checks and break if failed
    if(!preg_match($regex_owner, $owner)) return -1;
    if(!in_array($flags, $allowed_flags)) return -2;
    if(!in_array($protocol, $allowed_protocol)) return -3;
    if(!in_array($algorithm, $allowed_algorithm)) return -4;
    if(!preg_match($regex_publickey, $publickey)) return -5;
    
    // calculate hex of parameters
    $owner_hex = '';
    $parts = explode(".", substr($owner, 0, -1));
    foreach ($parts as $part) {
        $len = dechex(strlen($part));
        $owner_hex .= str_repeat('0', 2 - strlen($len)).$len;
        $part = str_split($part);
        for ($i = 0; $i < count($part); $i++) {
            $byte = strtoupper(dechex(ord($part[$i])));
            $byte = str_repeat('0', 2 - strlen($byte)).$byte;
            $owner_hex .= $byte;
        }
    }
    $owner_hex .= '00';
    $flags_hex = sprintf("%04d", dechex($flags));
    $protocol_hex = sprintf("%02d", dechex($protocol));
    $algorithm_hex = sprintf("%02d", dechex($algorithm));
    $publickey_hex = bin2hex(base64_decode($publickey));
    
    // calculate keytag using algorithm defined in rfc
    $string = hex2bin($flags_hex.$protocol_hex.$algorithm_hex.$publickey_hex);
    $sum = 0;
    for($i = 0; $i < strlen($string); $i++) {
        $b = ord($string[$i]);
        $sum += ($i & 1) ? $b : $b << 8;
    }
    $keytag = 0xffff & ($sum + ($sum >> 16));
    
    // calculate digest using rfc specified hashing algorithms
    $string = hex2bin($owner_hex.$flags_hex.$protocol_hex.$algorithm_hex.$publickey_hex);
    $digest_sha1 = strtoupper(sha1($string));
    $digest_sha256 = strtoupper(hash('sha256', $string));
    
    // return results and also copied parameters
    return array(
        //'debug' => array($owner_hex, $flags_hex, $protocol_hex, $algorithm_hex, $publickey_hex),
        'owner' => $owner,
        'keytag' => $keytag,
        'algorithm' => $algorithm,
        'digest' => array(
            array(
                'type' => 1,
                'hash' => $digest_sha1
            ),
            array(
                'type' => 2,
                'hash' => $digest_sha256
            )
        )
    );
}

// Function to update the permitted IPs from the database
function updatePermittedIPs($pool, $permittedIPsTable) {
    $pdo = $pool->get();
    $query = "SELECT addr FROM registrar_whitelist";
    $stmt = $pdo->query($query);
    $permittedIPs = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $pool->put($pdo);

    // Manually clear the table by removing each entry
    foreach ($permittedIPsTable as $key => $value) {
        $permittedIPsTable->del($key);
    }

    // Insert new values
    foreach ($permittedIPs as $ip) {
        $permittedIPsTable->set($ip, ['addr' => $ip]);
    }
}

function getDomainPrice($pdo, $domain_name, $tld_id, $date_add = 12, $command = 'create', $registrar_id = null, $currency = 'USD') {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);

    $cacheKey = "domain_price_{$domain_name}_{$tld_id}_{$date_add}_{$command}_{$registrar_id}_{$currency}";

    // Try fetching from cache
    $cached = $redis->get($cacheKey);
    if ($cached !== false) {
        return json_decode($cached, true); // Redis stores as string, so decode
    }

    $exchangeRates = getExchangeRates();
    $baseCurrency = $exchangeRates['base_currency'] ?? 'USD';
    $exchangeRate = $exchangeRates['rates'][$currency] ?? 1.0;

    // Check for premium pricing
    $premiumCacheKey = "premium_price_{$domain_name}_{$tld_id}";
    $premiumPrice = json_decode($redis->get($premiumCacheKey), true);

    if ($premiumPrice === null || $premiumPrice == 0) {
        $premiumPrice = fetchSingleValue(
            $pdo,
            'SELECT c.category_price 
             FROM premium_domain_pricing p
             JOIN premium_domain_categories c ON p.category_id = c.category_id
             WHERE p.domain_name = ? AND p.tld_id = ?',
            [$domain_name, $tld_id]
        );

        if (!is_null($premiumPrice) && $premiumPrice !== false) {
            $redis->setex($premiumCacheKey, 1800, json_encode($premiumPrice));
        }
    }

    if (!is_null($premiumPrice) && $premiumPrice !== false) {
        $money = convertMoney(new Money((int) ($premiumPrice * 100), new Currency($baseCurrency)), $exchangeRate, $currency);
        $result = ['type' => 'premium', 'price' => formatMoney($money)];

        $redis->setex($cacheKey, 1800, json_encode($result));
        return $result;
    }

    // Check for active promotions
    $currentDate = date('Y-m-d');
    $promoCacheKey = "promo_{$tld_id}";
    $promo = json_decode($redis->get($promoCacheKey), true);

    if ($promo === null) {
        $promo = fetchSingleRow(
            $pdo,
            "SELECT discount_percentage, discount_amount 
             FROM promotion_pricing 
             WHERE tld_id = ? 
             AND promo_type = 'full' 
             AND status = 'active' 
             AND start_date <= ? 
             AND end_date >= ?",
            [$tld_id, $currentDate, $currentDate]
        );

        if ($promo) {
            $redis->setex($promoCacheKey, 3600, json_encode($promo));
        }
    }

    // Get regular price from DB
    $priceColumn = "m" . (int) $date_add;
    $regularPriceCacheKey = "regular_price_{$tld_id}_{$command}_{$date_add}_{$registrar_id}";
    $regularPrice = json_decode($redis->get($regularPriceCacheKey), true);

    if ($regularPrice === null || $regularPrice == 0) {
        $regularPrice = fetchSingleValue(
            $pdo,
            "SELECT $priceColumn 
             FROM domain_price 
             WHERE tldid = ? AND command = ? 
             AND (registrar_id = ? OR registrar_id IS NULL) 
             ORDER BY registrar_id DESC LIMIT 1",
            [$tld_id, $command, $registrar_id]
        );

        if (!is_null($regularPrice) && $regularPrice !== false) {
            $redis->setex($regularPriceCacheKey, 1800, json_encode($regularPrice));
        }
    }

    if (!is_null($regularPrice) && $regularPrice !== false) {
        $redis->setex("regular_price_{$tld_id}_{$command}_{$registrar_id}", 1800, json_encode($regularPrice));

        $finalPrice = $regularPrice * 100; // Convert DB float to cents
        if ($promo) {
            if ($finalPrice > 0) {
                if (!empty($promo['discount_percentage'])) {
                    $discountAmount = (int) ($finalPrice * ($promo['discount_percentage'] / 100));
                } else {
                    $discountAmount = (int) ($promo['discount_amount'] * 100);
                }
                $finalPrice = max(0, $finalPrice - $discountAmount);
                $type = 'promotion';
            } else {
                $finalPrice = 0;
                $type = 'promotion';
            }
        } else {
            $type = 'regular';
        }

        $money = convertMoney(new Money($finalPrice, new Currency($baseCurrency)), $exchangeRate, $currency);
        $result = ['type' => $type, 'price' => formatMoney($money)];

        $redis->setex($cacheKey, 1800, json_encode($result));
        return $result;
    }

    return ['type' => 'not_found', 'price' => formatMoney(new Money(0, new Currency($currency)))];
}

function getDomainRestorePrice($pdo, $tld_id, $registrar_id = null, $currency = 'USD') {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);

    $cacheKey = "domain_restore_price_{$tld_id}_{$registrar_id}_{$currency}";

    // Try fetching from cache
    $cached = $redis->get($cacheKey);
    if ($cached !== false) {
        return json_decode($cached, true);
    }

    // Fetch exchange rates
    $exchangeRates = getExchangeRates();
    $baseCurrency = $exchangeRates['base_currency'] ?? 'USD';
    $exchangeRate = $exchangeRates['rates'][$currency] ?? 1.0;

    // Fetch restore price from DB
    $restorePrice = fetchSingleValue(
        $pdo,
        "SELECT price 
         FROM domain_restore_price 
         WHERE tldid = ? 
         AND (registrar_id = ? OR registrar_id IS NULL) 
         ORDER BY registrar_id DESC 
         LIMIT 1",
        [$tld_id, $registrar_id]
    );

    // If no restore price is found, return 0.00
    if (is_null($restorePrice) || $restorePrice === false) {
        return '0.00';
    }

    // Convert to Money object for precision
    $money = convertMoney(new Money((int) ($restorePrice * 100), new Currency($baseCurrency)), $exchangeRate, $currency);

    // Format and cache the result
    $formattedPrice = formatMoney($money);
    $redis->setex($cacheKey, 1800, json_encode($formattedPrice));

    return $formattedPrice;
}

/**
 * Load exchange rates from JSON file with APCu caching.
 */
function getExchangeRates() {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);

    $cacheKey = 'exchange_rates';

    $cached = $redis->get($cacheKey);
    if ($cached !== false) {
        return json_decode($cached, true);
    }

    $filePath = "/var/www/cp/resources/exchange_rates.json";
    $defaultRates = [
        'base_currency' => 'USD',
        'rates' => [
            'USD' => 1.0  // Ensure USD always exists
        ],
        'last_updated' => date('c') // ISO 8601 timestamp
    ];

    if (!file_exists($filePath) || !is_readable($filePath)) {
        $redis->setex($cacheKey, 3600, json_encode($defaultRates)); // Cache for 1 hour
        return $defaultRates;
    }

    $json = file_get_contents($filePath);
    $data = json_decode($json, true);

    if (!isset($data['base_currency'], $data['rates']) || !is_array($data['rates'])) {
        $redis->setex($cacheKey, 3600, json_encode($defaultRates)); // Cache for 1 hour
        return $defaultRates;
    }

    // Ensure base currency exists
    if (!isset($data['rates'][$data['base_currency']])) {
        $data['rates'][$data['base_currency']] = 1.0;
    }

    // Ensure every currency defaults to 1.0 if missing
    foreach ($data['rates'] as $currency => $rate) {
        if (!is_numeric($rate)) {
            $data['rates'][$currency] = 1.0;
        }
    }

    $redis->setex($cacheKey, 3600, json_encode($data)); // Cache for 1 hour

    return $data;
}

/**
 * Convert MoneyPHP object to the target currency.
 */
function convertMoney(Money $amount, float $exchangeRate, string $currency) {
    $currencies = new ISOCurrencies();
    $exchange = new FixedExchange([
        $amount->getCurrency()->getCode() => [
            $currency => (string) $exchangeRate  // Convert float to string
        ]
    ]);
    $converter = new Converter($currencies, $exchange);

    return $converter->convert($amount, new Currency($currency));
}

/**
 * Format Money object back to a string (e.g., "10.00").
 */
function formatMoney(Money $money) {
    return number_format($money->getAmount() / 100, 2, '.', '');
}

function createUuidFromId($id) {
    // Define a namespace UUID; this should be a UUID that is unique to your application
    $namespace = '123e4567-e89b-12d3-a456-426614174000';

    // Generate a UUIDv5 based on the namespace and a name (in this case, the $id)
    try {
        $uuid5 = Uuid::uuid5($namespace, (string)$id);
        return $uuid5->toString();
    } catch (UnsatisfiedDependencyException $e) {
        // Handle exception
        return null;
    }
}

/**
 * Fetch a single value from the database using PDO.
 */
function fetchSingleValue($pdo, string $query, array $params) {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * Fetch a single row from the database using PDO.
 */
function fetchSingleRow($pdo, string $query, array $params) {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
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

function isIPv6($ip) {
    // Validate if the IP is in IPv6 format
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

function expandIPv6($ip) {
    if (strpos($ip, '::') !== false) {
        // Split the IP by '::'
        $parts = explode('::', $ip);
        $left = explode(':', $parts[0]);
        $right = isset($parts[1]) ? explode(':', $parts[1]) : [];

        // Calculate the number of missing groups and fill them with '0000'
        $fill = array_fill(0, 8 - (count($left) + count($right)), '0000');
        $expanded = array_merge($left, $fill, $right);
    } else {
        $expanded = explode(':', $ip);
    }

    // Ensure each block is four characters
    foreach ($expanded as &$block) {
        $block = str_pad($block, 4, '0', STR_PAD_LEFT);
    }

    return implode(':', $expanded);
}

function validateLocField($input, $minLength = 5, $maxLength = 255) {
    // Normalize input to NFC form
    $input = normalizer_normalize($input, Normalizer::FORM_C);

    // Remove control characters to prevent hidden injections
    $input = preg_replace('/[\p{C}]/u', '', $input);

    // Define a general regex pattern to match Unicode letters, numbers, punctuation, and spaces
    $locRegex = '/^[\p{L}\p{N}\p{P}\p{Zs}\-\/&.,]+$/u';

    // Check length constraints and regex pattern
    return mb_strlen($input) >= $minLength &&
           mb_strlen($input) <= $maxLength &&
           preg_match($locRegex, $input);
}

/**
 * Validates a hostname or domain name.
 *
 * @param string $hostName
 * @return bool
 */
function validateHostName(string $hostName): bool
{
    // Convert IDN (Unicode) to ASCII (Punycode)
    $asciiHostName = idn_to_ascii($hostName, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
    if ($asciiHostName === false) {
        return false; // Invalid IDN format
    }

    // Ensure length is under 254 characters
    if (strlen($asciiHostName) >= 254) {
        return false;
    }

    // Validate using filter_var for Punycode
    if (!filter_var($asciiHostName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        return false;
    }

    // Optional: regex for stricter validation (on Punycode format)
    return preg_match(
        '/^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
        $asciiHostName
    );
}