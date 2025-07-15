<?php
/**
 * ICANN MoSAPI Registry Monitor
 * Fetches TLD state and DAAR-like statistics
 *
 * Author: Taras Kondratyuk (https://namingo.org)
 * License: MIT
 */

// ===== CONFIGURATION =====
$config = [
    'base_url'    => 'https://mosapi.icann.org/ry/your-tld',
    'username'    => 'your-ry-user',
    'password'    => 'your-password',
    'version'     => 'v2',
    'cookie_file' => __DIR__ . '/cookies-ry.txt',
    'timeout'     => 10,
    'tld'         => 'your-tld' // e.g., 'example'
];
// ==========================

if (!function_exists('apcu_fetch')) {
    die("APCu not enabled. Enable it with `apcu.enable_cli=1` in CLI php.ini\n");
}

function is_cli() {
    return php_sapi_name() === 'cli';
}

function output($message) {
    echo is_cli() ? $message . PHP_EOL : "<pre>$message</pre>";
}

function login($config) {
    $ch = curl_init("{$config['base_url']}/login");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "{$config['username']}:{$config['password']}",
        CURLOPT_COOKIEJAR      => $config['cookie_file'],
        CURLOPT_TIMEOUT        => $config['timeout'],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        throw new Exception("Login failed (HTTP $status): " . trim($response));
    }
}

function logout($config) {
    $ch = curl_init("{$config['base_url']}/logout");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $config['cookie_file'],
        CURLOPT_TIMEOUT        => $config['timeout'],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function fetch_json($url, $config) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $config['cookie_file'],
        CURLOPT_TIMEOUT        => $config['timeout'],
        CURLOPT_ENCODING       => 'gzip',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Accept-Encoding: gzip',
        ],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new Exception("Fetch failed (HTTP $status): $response");
    }

    $json = json_decode($response, true);
    if ($json === null) {
        output("❗ JSON decode failed — raw response:\n$response");
        throw new Exception("Invalid JSON from $url");
    }

    return $json;
}

function display_registry_status($data) {
    output("Registry State for TLD: " . ($data['tld'] ?? 'N/A'));
    output("  Status   : " . ($data['status'] ?? 'Unknown'));
    output("  Updated  : " . date('Y-m-d H:i:s', $data['lastUpdateApiDatabase'] ?? time()));
    foreach ($data['testedServices'] ?? [] as $service) {
        $threshold = $service['emergencyThreshold'] ?? '-';
        output("  - {$service['status']} / Emergency: {$threshold}%");
        foreach ($service['incidents'] ?? [] as $incident) {
            $end = $incident['endTime'] ? date('Y-m-d H:i:s', $incident['endTime']) : 'Active';
            output("     Incident {$incident['incidentID']}: {$incident['state']} since " . date('Y-m-d H:i:s', $incident['startTime']) . " (end: $end)");
        }
    }
}

function display_daar_stats($data) {
    output("\nTLD Abuse Report:");
    output("  Report Date   : " . ($data['domainListDate'] ?? 'N/A'));
    output("  Total Domains : " . ($data['totalDomains'] ?? 'N/A'));
    output("  Abused Count  : " . ($data['uniqueAbuseDomains'] ?? 'N/A'));

    foreach ($data['domainListData'] ?? [] as $entry) {
        output("  Threat: {$entry['threatType']} (Count: {$entry['count']})");
        foreach ($entry['domains'] ?? [] as $domain) {
            output("    - $domain");
        }
    }
}

// MAIN EXECUTION
try {
    $cacheKeyState   = 'mosapi_registry_state';
    $cacheKeyMetrica = 'mosapi_registry_daar';

    $stateData   = apcu_fetch($cacheKeyState);
    $daarData    = apcu_fetch($cacheKeyMetrica);

    if (!$stateData || !$daarData) {
        login($config);

        $stateUrl = "{$config['base_url']}/{$config['version']}/monitoring/state";
        $daarUrl  = "{$config['base_url']}/{$config['version']}/metrica/domainList/latest";

        $stateData = fetch_json($stateUrl, $config);
        $daarData  = fetch_json($daarUrl, $config);

        apcu_store($cacheKeyState, $stateData, 290);
        apcu_store($cacheKeyMetrica, $daarData, 290);

        logout($config);
    } else {
        output("⚠️  Using cached MoSAPI registry data");
    }

    display_registry_status($stateData);
    display_daar_stats($daarData);
} catch (Exception $e) {
    output("ERROR: " . $e->getMessage());
} finally {
    logout($config);
}