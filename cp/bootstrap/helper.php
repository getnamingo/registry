<?php
/**
 * Helper functions
 * @author    Hezekiah O. <support@hezecom.com>
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
        return 'Please provide a contact ID';
    }

    $length = strlen($identifier);

    if ($length < 3) {
        return 'Identifier type minLength value=3, maxLength value=16';
    }

    if ($length > 16) {
        return 'Identifier type minLength value=3, maxLength value=16';
    }

    $pattern1 = '/^[A-Z]+\-[0-9]+$/';
    $pattern2 = '/^[A-Za-z][A-Z0-9a-z]*$/';

    if (!preg_match($pattern1, $identifier) && !preg_match($pattern2, $identifier)) {
        return 'The ID of the contact must contain letters (A-Z) (ASCII), hyphen (-), and digits (0-9).';
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
        throw new \Exception("The TLDs cache file is missing or unreadable");
    }

    // Load a list of test TLDs used in your QA environment
    $testTlds = explode(',', envi('TEST_TLDS'));

    // Parse the URL to get the host
    $parts = parse_url($urlString);
    $host = $parts['host'] ?? $urlString;

    // Sort test TLDs by length (longest first) to match the longest possible TLD
    usort($testTlds, function ($a, $b) {
        return strlen($b) - strlen($a);
    });

    // Check if the TLD is a known test TLD
    foreach ($testTlds as $testTld) {
        if (str_ends_with($host, "$testTld")) {
            // Handle the test TLD case
            $tldLength = strlen($testTld); // No +1 for the dot
            $hostWithoutTld = substr($host, 0, -$tldLength);
            $hostParts = explode('.', $hostWithoutTld);
            $sld = array_pop($hostParts);
            if (strpos($testTld, '.') === 0) {
                $testTld = ltrim($testTld, '.');
            }
            return [
                'domain' => implode('.', $hostParts) ? implode('.', $hostParts) . '.' . $sld : $sld, 
                'tld' => $testTld
            ];
        }
    }
    
    // Use the PHP Domain Parser library for real TLDs
    $tlds = TopLevelDomains::fromString($fileContent);
    $domain = Domain::fromIDNA2008($host);
    $result = $tlds->resolve($domain);
    $sld = $result->secondLevelDomain()->toString();
    $tld = $result->suffix()->toString();

    return ['domain' => $sld, 'tld' => $tld];
}

function getDomainPrice($db, $domain_name, $tld_id, $date_add = 12, $command = 'create') {
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
        "SELECT $priceColumn FROM domain_price WHERE tldid = ? AND command = ? LIMIT 1",
        [$tld_id, $command]
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