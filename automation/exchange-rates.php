<?php

/*
 * Namingo Registry
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

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
$existingRates = ['rates' => []];

if (is_file($outputFile)) {
    $contents = file_get_contents($outputFile);
    $decoded = $contents !== false ? json_decode($contents, true) : null;

    if (is_array($decoded) && isset($decoded['rates']) && is_array($decoded['rates'])) {
        $existingRates = $decoded;
    }
}

// Fetch exchange rates from ExchangeRate.host with API key
$apiUrl = "https://api.exchangerate.host/live?access_key={$apiKey}&format=1";
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

    if ($httpCode === 200 && $response) {
        break; // Success
    }

    if ($i < $maxRetries - 1) {
        sleep($retryDelay);
    }
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
    $rateKey = $baseCurrency . $currency;
    if (isset($data['quotes'][$rateKey])) {
        $filteredRates[$currency] = $data['quotes'][$rateKey];
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