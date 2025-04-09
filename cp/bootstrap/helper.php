<?php
/**
 * This file contains utility functions for Namingo Registry Control Panel.
 *
 * Written and maintained by:
 * - Taras Kondratyuk (2023-2025)
 *
 * This file also incorporates functions by:
 * - Hezekiah O. <support@hezecom.com>
 *
 * @package    Namingo Panel
 * @author     Taras Kondratyuk
 * @copyright  2023-2025 Namingo
 * @license    MIT License
 * @version    1.0
 */

use Pinga\Auth\Auth;
use Pdp\Domain;
use Pdp\TopLevelDomains;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Filesystem;
use MatthiasMullie\Scrapbook\Adapters\Flysystem as ScrapbookFlysystem;
use MatthiasMullie\Scrapbook\Psr6\Pool;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Guid\Guid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\NumberParseException;
use ZxcvbnPhp\Zxcvbn;
use Money\Money;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Exchange\FixedExchange;
use Money\Converter;
use Money\CurrencyPair;

/**
 * @return mixed|string|string[]
 */
function routePath() {
    if (isset($_SERVER['REQUEST_URI'])) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $uri = (string) parse_url('http://a' . $_SERVER['REQUEST_URI'], PHP_URL_PATH);

        if (stripos($uri, $_SERVER['SCRIPT_NAME']) === 0) {
            return $_SERVER['SCRIPT_NAME'];
        }
        if ($scriptDir !== '/' && stripos($uri, $scriptDir) === 0) {
            return $scriptDir;
        }
    }
    return '';
}

/**
 * @param $key
 * @param null $default
 * @return mixed|null
 */
function config($key, $default=null){
    return \App\Lib\Config::get($key, $default);
}

/**
 * @param $var
 * @return mixed
 */
function envi($var, $default=null)
{
    if(isset($_ENV[$var])){
        return $_ENV[$var];
    }
    return $default;
}

/**
 * Start session
 */
function startSession(){
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * @param $var
 * @return mixed
 */
function session($var){
    if (isset($_SESSION[$var])) {
        return $_SESSION[$var];
    }
}

/**
 * Global PDO connection
 * @return \DI\|mixed|PDO
 * @throws \DI\DependencyException
 * @throws \DI\NotFoundException
 */
function pdo(){
    global $container;
    return $container->get('pdo');
}

/**
 * @return Auth
 */
function auth(){
    $db = pdo();
    $auth = new Auth($db);
    return $auth;
}

/**
 * @param $name
 * @param array $params1
 * @param array $params2
 * @return mixed
 * @throws \DI\DependencyException
 * @throws \DI\NotFoundException
 */
function route($name, $params1 =[], $params2=[]){
    global $container;
    return $container->get('router')->urlFor($name,$params1,$params2);
}

/**
 * @param string $dir
 * @return string
 */
function baseUrl(){
    $root = "";
    $root .= !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $root .= '://' . $_SERVER['HTTP_HOST'];
    return $root;
}

/**
 * @param string|null $name
 * @return string
 */
function url($url=null, $params1 =[], $params2=[]){
    if($url){
        return baseUrl().route($url,$params1,$params2);
    }
    return baseUrl();
}

/**
 * @param $resp
 * @param $page
 * @param array $arr
 * @return mixed
 * @throws \DI\DependencyException
 * @throws \DI\NotFoundException
 */
function view($resp, $page, $arr=[]){
    global $container;
    return $container->get('view')->render($resp, $page, $arr);
}

/**
 * @param $type
 * @param $message
 * @return mixed
 * @throws \DI\DependencyException
 * @throws \DI\NotFoundException
 */
function flash($type, $message){
    global $container;
    return $container->get('flash')->addMessage($type, $message);
}

/**
 * @return \App\Lib\Redirect
 */
function redirect()
{
    return new \App\Lib\Redirect();
}

/**
 * @param $location
 * @return string
 */
function assets($location){
    return url().dirname($_SERVER["REQUEST_URI"]).'/'.$location;
}

/**
 * @param $data
 * @return mixed
 */
function toArray($data){
    return json_decode(json_encode($data), true);
}

function validate_identifier($identifier) {
    if (!$identifier) {
        return 'Oops! It looks like you forgot to provide a contact ID. Please make sure to include one.';
    }

    $length = strlen($identifier);

    if ($length < 3 || $length > 16) {
        return 'Identifier must be between 3 and 16 characters long. Please try again.';
    }

    $pattern = '/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/';

    if (!preg_match($pattern, $identifier)) {
        return 'Your contact ID must contain letters (A-Z, a-z), digits (0-9), and optionally a hyphen (-). Please adjust and try again.';
    }
}

function validate_label($domain, $db) {
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
    $tldExists = $db->selectValue('SELECT COUNT(*) FROM domain_tld WHERE tld = ?', [$tld]);

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
        $idnRegex = $db->selectValue('SELECT idn_table FROM domain_tld WHERE tld = ?', [$tld]);

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

function extractDomainAndTLD($urlString) {
    $cachePath = __DIR__ . '/../cache'; // Cache directory
    $adapter = new LocalFilesystemAdapter($cachePath, null, LOCK_EX);
    $filesystem = new Filesystem($adapter);
    $cache = new Pool(new ScrapbookFlysystem($filesystem));
    $cacheKey = 'tlds_alpha_by_domain';
    $cachedFile = $cache->getItem($cacheKey);
    $fileContent = $cachedFile->get();

    // Check if fileContent is not null
    if (null === $fileContent) {
        // Handle the error gracefully
        $_SESSION['slimFlash']['error'][] = 'The TLDs cache file is missing or unreadable';
        return null;
    }

    // Load a list of test TLDs used in your QA environment
    $testTlds = explode(',', envi('TEST_TLDS'));

    // Parse the URL to get the host
    $parts = parse_url($urlString);
    $host = $parts['host'] ?? $urlString;

    if (!preg_match('/\./', $urlString)) {
        $_SESSION['slimFlash']['error'][] = 'Invalid domain format';
        return ['error' => 'Invalid domain format'];
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

    // Use the PHP Domain Parser library for real TLDs
    try {
        // Use the PHP Domain Parser library for real TLDs
        $tlds = TopLevelDomains::fromString($fileContent);
        $domain = Domain::fromIDNA2008($host);
        $resolvedTLD = $tlds->resolve($domain)->suffix()->toString();
    } catch (\Pdp\Exception $e) { // Catch domain parser exceptions
        $_SESSION['slimFlash']['error'][] = 'Domain parsing error: ' . $e->getMessage();
        return ['error' => 'Domain parsing error: ' . $e->getMessage()];
    } catch (\Exception $e) { // Catch any other unexpected exceptions
        $_SESSION['slimFlash']['error'][] = 'Unexpected error: ' . $e->getMessage();
        return ['error' => 'Unexpected error: ' . $e->getMessage()];
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

function getDomainPrice($db, $domain_name, $tld_id, $date_add = 12, $command = 'create', $registrar_id = null, $currency = 'USD') {
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
    $premiumPrice = $redis->get("premium_price_{$domain_name}_{$tld_id}");
    if ($premiumPrice === null || $premiumPrice == "0") {
        $premiumPrice = $db->selectValue(
            'SELECT c.category_price 
             FROM premium_domain_pricing p
             JOIN premium_domain_categories c ON p.category_id = c.category_id
             WHERE p.domain_name = ? AND p.tld_id = ?',
            [$domain_name, $tld_id]
        );

        if (!is_null($premiumPrice) && $premiumPrice !== false) {
            $redis->setex("premium_price_{$domain_name}_{$tld_id}", 1800, json_encode($premiumPrice));
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
    $promo = json_decode($redis->get("promo_{$tld_id}"), true);
    if ($promo === null) {
        $promo = $db->selectRow(
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
            $redis->setex("promo_{$tld_id}", 3600, json_encode($promo));
        }
    }

    // Get regular price from DB
    $priceColumn = "m" . (int) $date_add;
    $regularPrice = json_decode($redis->get("regular_price_{$tld_id}_{$command}_{$date_add}_{$registrar_id}"), true);
    if ($regularPrice === null || $regularPrice == 0) {
        $regularPrice = $db->selectValue(
            "SELECT $priceColumn 
             FROM domain_price 
             WHERE tldid = ? AND command = ? 
             AND (registrar_id = ? OR registrar_id IS NULL) 
             ORDER BY registrar_id DESC LIMIT 1",
            [$tld_id, $command, $registrar_id]
        );

        if (!is_null($regularPrice) && $regularPrice !== false) {
            $redis->setex("regular_price_{$tld_id}_{$command}_{$registrar_id}", 1800, json_encode($regularPrice));
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

function getDomainRestorePrice($db, $tld_id, $registrar_id = null, $currency = 'USD') {
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
    $restorePrice = $db->selectValue(
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

// Function to get the client IP address
function get_client_ip() {
    $ipaddress = '';
    if (getenv('HTTP_CLIENT_IP'))
        $ipaddress = getenv('HTTP_CLIENT_IP');
    else if(getenv('HTTP_X_FORWARDED_FOR'))
        $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
    else if(getenv('HTTP_X_FORWARDED'))
        $ipaddress = getenv('HTTP_X_FORWARDED');
    else if(getenv('HTTP_FORWARDED_FOR'))
        $ipaddress = getenv('HTTP_FORWARDED_FOR');
    else if(getenv('HTTP_FORWARDED'))
       $ipaddress = getenv('HTTP_FORWARDED');
    else if(getenv('REMOTE_ADDR'))
        $ipaddress = getenv('REMOTE_ADDR');
    else
        $ipaddress = 'UNKNOWN';
    return $ipaddress;
}

function get_client_location() {
    $PublicIP = get_client_ip();
    $json     = file_get_contents("http://ipinfo.io/$PublicIP/geo");
    $json     = json_decode($json, true);
    $country  = $json['country'];

    return $country;
}

function normalizePhoneNumber($number, $defaultRegion = 'US') {
    $phoneUtil = PhoneNumberUtil::getInstance();
    
    // Strip only empty spaces and dashes from the number.
    $number = str_replace([' ', '-'], '', $number);
    
    // Prepend '00' if the number does not start with '+' or '0'.
    if (strpos($number, '+') !== 0 && strpos($number, '0') !== 0) {
        $number = '00' . $number;
    }

    // Convert a leading '+' to '00' for international format compatibility.
    if (strpos($number, '+') === 0) {
        $number = '00' . substr($number, 1);
    }

    // Now, clean the number to ensure it consists only of digits.
    $cleanNumber = preg_replace('/\D/', '', $number);

    try {
        // Parse the clean, digit-only string, which may start with '00' for international format.
        $numberProto = $phoneUtil->parse($cleanNumber, $defaultRegion);

        // Format the number to E.164 to ensure it includes the correct country code.
        $formattedNumberE164 = $phoneUtil->format($numberProto, PhoneNumberFormat::E164);

        // Extract the country code and national number.
        $countryCode = $numberProto->getCountryCode();
        $nationalNumber = $numberProto->getNationalNumber();

        // Reconstruct the number in the desired EPP format: +CountryCode.NationalNumber
        $formattedNumber = '+' . $countryCode . '.' . $nationalNumber;
        return ['success' => $formattedNumber];
        
    } catch (NumberParseException $e) {
        return ['error' => 'Failed to parse and normalize phone number: ' . $e->getMessage()];
    }
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

function validateUniversalEmail($email) {
    // Normalize the email to NFC form to ensure consistency
    $email = \Normalizer::normalize($email, \Normalizer::FORM_C);

    // Remove any control characters
    $email = preg_replace('/[\p{C}]/u', '', $email);

    // Split email into local and domain parts
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return false; // Invalid email format
    }

    list($localPart, $domainPart) = $parts;

    // Convert the domain part to Punycode if it contains non-ASCII characters
    if (preg_match('/[^\x00-\x7F]/', $domainPart)) {
        $punycodeDomain = idn_to_ascii($domainPart, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($punycodeDomain === false) {
            return false; // Invalid domain part, failed conversion
        }
    } else {
        $punycodeDomain = $domainPart;
    }

    // Reconstruct the email with the Punycode domain part (if converted)
    $emailToValidate = $localPart . '@' . $punycodeDomain;

    // Updated regex for both ASCII and IDN email validation
    $emailPattern = '/^[\p{L}\p{N}\p{M}._%+-]+@([a-zA-Z0-9-]+|\bxn--[a-zA-Z0-9-]+)(\.([a-zA-Z0-9-]+|\bxn--[a-zA-Z0-9-]+))+$/u';

    // Validate using regex
    return preg_match($emailPattern, $emailToValidate);
}

function toPunycode($value) {
    // Convert to Punycode if it contains non-ASCII characters
    return preg_match('/[^\x00-\x7F]/', $value) ? idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : $value;
}

function toUnicode($value) {
    // Convert from Punycode to UTF-8 if it's a valid IDN format
    return (strpos($value, 'xn--') === 0) ? idn_to_utf8($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : $value;
}

function extractHostTLD(string $hostname): array
{
    $parts = explode('.', $hostname);

    if (count($parts) < 2) {
        // Invalid hostname; return empty values
        return ['host' => '', 'tld' => ''];
    }

    // Extract host and TLD
    $tld = array_pop($parts); // Get the last part as TLD
    $host = array_pop($parts); // Get the second last part as host

    return ['host' => $host, 'tld' => $tld];
}

function checkPasswordComplexity($password) {
    $zxcvbn = new Zxcvbn();

    // Use configured or default password strength requirement
    $requiredScore = envi('PASSWORD_STRENGTH') ?: 3; // Default to score 3 if ENV is not set

    $score = $zxcvbn->passwordStrength($password)['score'];

    if ($score < $requiredScore) { // Score ranges from 0 (weak) to 4 (strong)
        return false;
    }
    
    return true;
}

function checkPasswordRenewal($lastPasswordUpdateTimestamp) {
    // Use configured or default password expiration days
    $passwordExpiryDays = envi('PASSWORD_EXPIRATION_DAYS') ?: 90; // Default to 90 days

    if (!$lastPasswordUpdateTimestamp) {
        return 'Your password is expired. Please change it.';
    }

    // Convert the timestamp string to a Unix timestamp
    $lastUpdatedUnix = strtotime($lastPasswordUpdateTimestamp);

    if (time() - $lastUpdatedUnix > $passwordExpiryDays * 86400) {
        return 'Your password is expired. Please change it.';
    }

    return null;
}

function hasRequiredRole(int $userRoles, int $requiredRole): bool {
    return ($userRoles & $requiredRole) !== 0;
}

function lacksRoles(int $userRoles, int ...$excludedRoles): bool {
    foreach ($excludedRoles as $role) {
        if (($userRoles & $role) !== 0) {
            return false; // User has at least one of the excluded roles
        }
    }
    return true; // User lacks all specified roles
}

function hasOnlyRole(int $userRoles, int $specificRole): bool {
    return $userRoles === $specificRole;
}

// Returns an array of ranges: each item is ['start' => int, 'end' => int]
function parseCharacterClass(string $class): array {
    $ranges = [];
    $len = mb_strlen($class, 'UTF-8');
    $i = 0;
    while ($i < $len) {
        $currentCode = null;
        // Look for an escape sequence like \x{0621}
        if (mb_substr($class, $i, 1, 'UTF-8') === '\\') {
            if (mb_substr($class, $i, 3, 'UTF-8') === '\\x{') {
                $closePos = mb_strpos($class, '}', $i);
                if ($closePos === false) {
                    throw new \RuntimeException("Unterminated escape sequence in character class.");
                }
                $hex = mb_substr($class, $i + 3, $closePos - ($i + 3), 'UTF-8');
                $currentCode = hexdec($hex);
                $i = $closePos + 1;
            } else {
                // For a simple escaped char (for example, \-)
                $i++;
                if ($i < $len) {
                    $char = mb_substr($class, $i, 1, 'UTF-8');
                    $currentCode = IntlChar::ord($char);
                    $i++;
                } else {
                    break;
                }
            }
        } else {
            $char = mb_substr($class, $i, 1, 'UTF-8');
            $currentCode = IntlChar::ord($char);
            $i++;
        }
        // Check if a dash follows and there is a token after it (forming a range)
        if ($i < $len && mb_substr($class, $i, 1, 'UTF-8') === '-' && ($i + 1) < $len) {
            // skip the dash
            $i++;
            $nextCode = null;
            if (mb_substr($class, $i, 1, 'UTF-8') === '\\') {
                if (mb_substr($class, $i, 3, 'UTF-8') === '\\x{') {
                    $closePos = mb_strpos($class, '}', $i);
                    if ($closePos === false) {
                        throw new \RuntimeException("Unterminated escape sequence in character class.");
                    }
                    $hex = mb_substr($class, $i + 3, $closePos - ($i + 3), 'UTF-8');
                    $nextCode = hexdec($hex);
                    $i = $closePos + 1;
                } else {
                    $i++;
                    if ($i < $len) {
                        $char = mb_substr($class, $i, 1, 'UTF-8');
                        $nextCode = IntlChar::ord($char);
                        $i++;
                    } else {
                        break;
                    }
                }
            } else {
                $char = mb_substr($class, $i, 1, 'UTF-8');
                $nextCode = IntlChar::ord($char);
                $i++;
            }
            $ranges[] = [
                'start' => min($currentCode, $nextCode),
                'end'   => max($currentCode, $nextCode)
            ];
        } else {
            // Not a range; add the single codepoint.
            $ranges[] = ['start' => $currentCode, 'end' => $currentCode];
        }
    }
    return $ranges;
}

// --- Helper: merge overlapping ranges (optional) ---
function mergeRanges(array $ranges): array {
    if (empty($ranges)) {
        return [];
    }
    // sort ranges by start value
    usort($ranges, fn($a, $b) => $a['start'] <=> $b['start']);
    $merged = [];
    $current = $ranges[0];
    foreach ($ranges as $r) {
        if ($r['start'] <= $current['end'] + 1) {
            // Extend the current range if overlapping or adjacent.
            $current['end'] = max($current['end'], $r['end']);
        } else {
            $merged[] = $current;
            $current = $r;
        }
    }
    $merged[] = $current;
    return $merged;
}

// --- Helper: get Unicode name (or fallback) ---
function getUnicodeName(int $codepoint): string {
    $name = IntlChar::charName($codepoint);
    return $name !== '' ? $name : 'UNKNOWN';
}

// --- Main function: generate IANA IDN table from regex and metadata ---
function generateIanaIdnTable(string $regex, array $metadata): string {
    $output = '';

    // Extract modifier flags (e.g. 'i', 'u') from the regex delimiter.
    if (!preg_match('/^.(.*).([a-zA-Z]*)$/', $regex, $parts)) {
        throw new \RuntimeException("Regex does not have expected delimiter format.");
    }
    $patternBody   = $parts[1];
    $modifiers     = $parts[2];
    $caseInsensitive = strpos($modifiers, 'i') !== false;

    // Find all bracketed character classes.
    if (!preg_match_all('/\[([^]]+)\]/u', $patternBody, $matches)) {
        throw new \RuntimeException("No character classes found in regex.");
    }
    // Combine all character class contents.
    $combinedClass = implode('', $matches[1]);

    // Parse the combined character class into ranges.
    $ranges = parseCharacterClass($combinedClass);

    // If the regex is case‐insensitive, then for any range that covers A–Z add the lowercase equivalent.
    if ($caseInsensitive) {
        $additional = [];
        foreach ($ranges as $range) {
            // Check for Latin uppercase letters: U+0041 ('A') to U+005A ('Z')
            if ($range['start'] >= 0x41 && $range['end'] <= 0x5A) {
                $additional[] = [
                    'start' => $range['start'] + 0x20,
                    'end'   => $range['end'] + 0x20
                ];
            }
        }
        $ranges = array_merge($ranges, $additional);
    }
    // Optionally merge overlapping ranges.
    $ranges = mergeRanges($ranges);

    // Build full list of allowed script-specific codepoints.
    $scriptCodepoints = [];
    foreach ($ranges as $range) {
        for ($cp = $range['start']; $cp <= $range['end']; $cp++) {
            $scriptCodepoints[$cp] = $cp;
        }
    }
    ksort($scriptCodepoints);

    // Define the “common” codepoints (always allowed in all scripts)
    $commonCodepoints = array_merge([0x002D], range(0x0030, 0x0039));
    // Remove common codepoints from script-specific set.
    foreach ($commonCodepoints as $common) {
        if (isset($scriptCodepoints[$common])) {
            unset($scriptCodepoints[$common]);
        }
    }

    // Force all script-specific codepoints to lowercase and remove duplicates.
    $lowerScriptCodepoints = [];
    foreach ($scriptCodepoints as $cp) {
        // Convert the codepoint to lowercase.
        // For non-alphabetic characters, tolower() returns the original.
        $lowerCp = IntlChar::tolower($cp);
        $lowerScriptCodepoints[$lowerCp] = $lowerCp;
    }
    $scriptCodepoints = $lowerScriptCodepoints;
    ksort($scriptCodepoints);

    // Build header block from metadata.
    foreach ($metadata as $field => $value) {
        $output .= "# {$field}: {$value}\n";
    }
    $output .= "\n";

    // Output the common codepoints.
    $output .= "# Common (allowed in all scripts)\n";
    foreach ($commonCodepoints as $cp) {
        $hex = sprintf("U+%04X", $cp);
        $name = getUnicodeName($cp);
        $output .= "{$hex}  # {$name}\n";
    }
    $output .= "\n";

    // Output the script‐specific codepoints.
    foreach ($scriptCodepoints as $cp) {
        $hex = sprintf("U+%04X", $cp);
        $name = getUnicodeName($cp);
        $output .= "{$hex}  # {$name}\n";
    }
    return $output;
}

function isValidHostname($hostname) {
    $hostname = trim($hostname);

    // Convert IDN (Unicode) to ASCII if necessary
    if (mb_detect_encoding($hostname, 'ASCII', true) === false) {
        $hostname = idn_to_ascii($hostname, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($hostname === false) {
            return false; // Invalid IDN conversion
        }
    }

    // Ensure there is at least **one dot** (to prevent single-segment hostnames)
    if (substr_count($hostname, '.') < 1) {
        return false;
    }

    // Regular expression for validating a hostname
    $pattern = '/^((xn--[a-zA-Z0-9-]{1,63}|[a-zA-Z0-9-]{1,63})\.)*([a-zA-Z0-9-]{1,63}|xn--[a-zA-Z0-9-]{2,63})$/';

    // Ensure it matches the hostname pattern
    if (!preg_match($pattern, $hostname)) {
        return false;
    }

    // Ensure no label exceeds 63 characters
    $labels = explode('.', $hostname);
    foreach ($labels as $label) {
        if (strlen($label) > 63) {
            return false;
        }
    }

    // Ensure full hostname is not longer than 255 characters
    if (strlen($hostname) > 255) {
        return false;
    }

    return true;
}

// HMAC Signature generator
function sign($ts, $method, $path, $body, $secret_key) {
    $stringToSign = $ts . strtoupper($method) . $path . $body;
    return hash_hmac('sha256', $stringToSign, $secret_key);
}

function getClid($db, string $clid): ?int {
    $result = $db->selectValue('SELECT id FROM registrar WHERE clid = ? LIMIT 1', [$clid]);
    return $result !== false ? (int)$result : null;
}