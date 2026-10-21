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

declare(strict_types=1);

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\VerifySolutionOptions;

session_start();

$c = require_once __DIR__ . '/config.php';

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

if (($c['ignore_captcha'] ?? true) === false) {
    require __DIR__ . '/vendor/autoload.php';

    $payload = $_POST['altcha'] ?? '';
    $decoded = is_string($payload) ? base64_decode($payload, true) : false;
    $payloadData = $decoded !== false ? json_decode($decoded, true) : null;
    $signature = is_array($payloadData) ? ($payloadData['challenge']['signature'] ?? '') : '';
    $storedSignature = $_SESSION['altcha_signature'] ?? '';
    unset($_SESSION['altcha_signature']);

    if (
        !is_string($payload)
        || $payload === ''
        || !is_string($signature)
        || !is_string($storedSignature)
        || $storedSignature === ''
        || !hash_equals($storedSignature, $signature)
    ) {
        echo json_encode(['error' => 'Captcha verification failed.']);
        exit;
    }

    $secret = (string) ($c['altcha_hmac_secret'] ?? '');

    if ($secret === '') {
        echo json_encode(['error' => 'ALTCHA is not configured.']);
        exit;
    }

    try {
        $result = (new Altcha($secret))->verifySolution(new VerifySolutionOptions(
            payload: $payload,
            algorithm: new Pbkdf2(),
        ));
    } catch (\InvalidArgumentException) {
        echo json_encode(['error' => 'Captcha verification failed.']);
        exit;
    }

    if (!$result->verified) {
        echo json_encode(['error' => 'Captcha verification failed.']);
        exit;
    }
}

$domain = $_POST['domain'] ?? null;
$type = $_POST['type'] ?? null;

if (!is_string($domain) || !is_string($type) ||
    $domain === '' || strlen($domain) > 253 ||
    !in_array($type, ['whois', 'rdap'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input.']);
    exit;
}

$whoisServer = $c['whois_url'];
$rdapServer = 'https://' . $c['rdap_url'] . '/domain/';

$sanitized_domain = filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);

// Check if the domain is in Unicode and convert it to Punycode
if (mb_check_encoding($domain, 'UTF-8') && !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
    $punycodeDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

    if ($punycodeDomain !== false) {
        $domain = $punycodeDomain;
    } else {
        echo json_encode(['error' => 'Invalid domain.']);
        exit;
    }
}

$sanitized_domain = filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);

if ($sanitized_domain) {
    $domain = $sanitized_domain;
} else {
    echo json_encode(['error' => 'Invalid domain.']);
    exit;
}

if ($type === 'whois') {
    $output = '';
    $socket = @fsockopen($whoisServer, 43, $errno, $errstr, 5);

    if (!$socket) {
        echo json_encode(['error' => "Error fetching WHOIS data."]);
        exit;
    }

    stream_set_timeout($socket, 2);
    $deadline = microtime(true) + 10;

    if (fwrite($socket, $domain . "\r\n") === false) {
        fclose($socket);
        http_response_code(502);
        echo json_encode(['error' => 'WHOIS query failed.']);
        exit;
    }

    while (!feof($socket) && microtime(true) < $deadline) {
        $chunk = fread($socket, min(8192, 1048577 - strlen($output)));
        if ($chunk === false || stream_get_meta_data($socket)['timed_out']) {
            break;
        }
        $output .= $chunk;
        if (strlen($output) > 1048576) {
            break;
        }
    }
    $complete = feof($socket);
    fclose($socket);
    
    if (!$complete || strlen($output) > 1048576) {
        http_response_code(502);
        echo json_encode(['error' => 'WHOIS timeout or response too large.']);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
} elseif ($type === 'rdap') {
    $ch = curl_init();
    $output = '';
    $tooLarge = false;
    curl_setopt($ch, CURLOPT_URL, $rdapServer . rawurlencode($domain));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'HTTPS');
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $data) use (&$output, &$tooLarge) {
        if (strlen($output) + strlen($data) > 1048576) {
            $tooLarge = true;
            return 0;
        }
        $output .= $data;
        return strlen($data);
    });

    $result = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($result === false || ($status !== 404 && ($status < 200 || $status >= 300))) {
        http_response_code(502);
        echo json_encode(['error' => $tooLarge ? 'RDAP response too large.' : 'Error fetching RDAP data.']);
        exit;
    }

    if (!$output) {
        echo json_encode(['error' => 'Error fetching RDAP data.']);
        exit;
    }

    header('Content-Type: application/rdap+json; charset=utf-8');
}

echo $output;