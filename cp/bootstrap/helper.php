<?php
/**
 * This file contains utility functions for Namingo Registry Control Panel.
 *
 * Written and maintained by:
 * - Taras Kondratyuk (2023-2025)
 *
 * This file also incorporates functions:
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

    // Updated pattern: allows letters and digits at start and end, hyphens in the middle only
    $pattern = '/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/';

    if (!preg_match($pattern, $identifier)) {
        return 'Your contact ID must contain letters (A-Z, a-z), digits (0-9), and optionally a hyphen (-). Please adjust and try again.';
    }
}

function validate_label($label, $db) {
    if (!$label) {
        return 'You must enter a domain name';
    }
    if (strlen($label) > 63) {
        return 'Total lenght of your domain must be less then 63 characters';
    }
    if (strlen($label) < 2) {
        return 'Total lenght of your domain must be greater then 2 characters';
    }
    if (strpos($label, '.') === false) {
        return 'Invalid domain name format, must contain at least one dot (.)';
    }
    if (strpos($label, 'xn--') === false && preg_match("/(^-|^\.|-\.|\.-|--|\.\.|-$|\.$)/", $label)) {
        return 'Invalid domain name format, cannot begin or end with a hyphen (-)';
    }
    
    // Extract TLD from the domain and prepend a dot
    $parts = extractDomainAndTLD($label);
    $tld = "." . $parts['tld'];

    // Check if the TLD exists in the domain_tld table
    $tldExists = $db->select('SELECT COUNT(*) FROM domain_tld WHERE tld = ?', [$tld]);

    if ($tldExists[0]["COUNT(*)"] == 0) {
        return 'Zone is not supported';
    }

    // Fetch the IDN regex for the given TLD
    $idnRegex = $db->selectRow('SELECT idn_table FROM domain_tld WHERE tld = ?', [$tld]);

    if (!$idnRegex) {
        return 'Failed to fetch domain IDN table';
    }

    if (strpos($parts['domain'], 'xn--') === 0) {
        $label = idn_to_utf8($parts['domain'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    }

    // Check for invalid characters using fetched regex
    if (!preg_match($idnRegex['idn_table'], $label)) {
        return 'Invalid domain name format, please review registry policy about accepted labels';
    }
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
    $tlds = TopLevelDomains::fromString($fileContent);
    $domain = Domain::fromIDNA2008($host);
    $resolvedTLD = $tlds->resolve($domain)->suffix()->toString();

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

function getDomainPrice($db, $domain_name, $tld_id, $date_add = 12, $command = 'create', $registrar_id = null) {
    // Check if the domain is a premium domain
    $premiumDomain = $db->selectRow(
        'SELECT c.category_price 
         FROM premium_domain_pricing p
         JOIN premium_domain_categories c ON p.category_id = c.category_id
         WHERE p.domain_name = ? AND p.tld_id = ?',
        [$domain_name, $tld_id]
    );

    if ($premiumDomain) {
        return ['type' => 'premium', 'price' => $premiumDomain['category_price']];
    }

    // Check if there is a promotion for the domain
    $currentDate = date('Y-m-d');
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

    $discount = null;
    if ($promo) {
        if (!empty($promo['discount_percentage'])) {
            $discount = $promo['discount_percentage']; // Percentage discount
        } elseif (!empty($promo['discount_amount'])) {
            $discount = $promo['discount_amount']; // Fixed amount discount
        }
    }

    // Get regular price for the specified period
    $priceColumn = "m" . $date_add;
    $regularPrice = $db->selectValue(
        "SELECT $priceColumn FROM domain_price WHERE tldid = ? AND command = ? AND (registrar_id = ? OR registrar_id IS NULL) ORDER BY registrar_id DESC LIMIT 1",
        [$tld_id, $command, $registrar_id]
    );

    if ($regularPrice !== false) {
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
    $requiredScore = getenv('PASSWORD_STRENGTH') ?: 3; // Default to score 3 if ENV is not set

    $score = $zxcvbn->passwordStrength($password)['score'];

    if ($score < $requiredScore) { // Score ranges from 0 (weak) to 4 (strong)
        throw new Exception('Password too weak. Use a stronger password.');
    }
}

function checkPasswordRenewal($lastPasswordUpdateTimestamp) {
    // Use configured or default password expiration days
    $passwordExpiryDays = getenv('PASSWORD_EXPIRATION_DAYS') ?: 90; // Default to 90 days

    if (time() - $lastPasswordUpdateTimestamp > $passwordExpiryDays * 86400) {
        return 'Your password is expired. Please change it.';
    }
    return null;
}
