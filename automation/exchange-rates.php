<?php

$c = require_once 'config.php';
require_once 'helpers.php';

$apiKey = $c['exchange_rate_api_key'];
$baseCurrency = $c['exchange_rate_base_currency'];
$currencies = $c['exchange_rate_currencies'];
$outputFile = "/var/www/cp/resources/exchange_rates.json";
$lockFile = "/tmp/update_exchange_rates.lock";
$logFilePath = '/var/log/namingo/exchange-rates.log';
$log = setupLogger($logFilePath, 'EXCHANGE_RATES');
$log->info('job started.');

// Prevent concurrent execution using flock()
$lock = fopen($lockFile, "w+");
if (!$lock) {
    $log->error('Failed to open lock file.');
    exit;
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    $log->error('Script is already running. Exiting.');
    exit;
}

// Load current rates if the file exists
$existingRates = [];
if (file_exists($outputFile)) {
    $existingRates = json_decode(file_get_contents($outputFile), true);
    if (!isset($existingRates['rates'])) {
        $existingRates['rates'] = [];
    }
}

// Fetch exchange rates from ExchangeRate.host with API key
$apiUrl = "http://api.exchangerate.host/live?access_key={$apiKey}&format=1";
$maxRetries = 3;
$retryDelay = 5; // seconds
$response = false;

for ($i = 0; $i < $maxRetries; $i++) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        break; // Success
    }

    sleep($retryDelay);
}

if ($httpCode !== 200 || !$response) {
    flock($lock, LOCK_UN);
    fclose($lock);
    $log->error('Failed to fetch exchange rates.');
    exit;
}

// Decode and validate API response
$data = json_decode($response, true);
if (!isset($data['quotes'])) {
    flock($lock, LOCK_UN);
    fclose($lock);
    $log->error('Invalid API response.');
    exit;
}

// Filter only configured currencies, keeping old values for missing ones
$filteredRates = $existingRates['rates']; // Start with existing rates
foreach ($currencies as $currency) {
    $usdKey = "USD" . $currency;
    if (isset($data['quotes'][$usdKey])) {
        $filteredRates[$currency] = $data['quotes'][$usdKey]; // Update with new rate
    }
}

// Prepare updated exchange rate data
$exchangeRates = [
    "base_currency" => $baseCurrency,
    "rates" => $filteredRates,
    "last_updated" => gmdate("Y-m-d\TH:i:s\Z")
];

// Save to file safely
if (file_put_contents($outputFile, json_encode($exchangeRates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    flock($lock, LOCK_UN);
    fclose($lock);
    $log->error('Failed to save exchange rates.');
    exit;
}

// Log success
$log->info('Exchange rates updated successfully.');

// Release lock and close
flock($lock, LOCK_UN);
fclose($lock);

$log->info('job finished successfully.');