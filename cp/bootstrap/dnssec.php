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

function dnssec_algorithms(): array
{
    return [8, 13, 14, 15, 16, 17, 18];
}

function dnssec_digest_lengths(): array
{
    return [2 => 64, 4 => 96, 6 => 64]; // SHA-256, SHA-384, SM3 (hex characters)
}

function dnssec_decode_key(string $publicKey, int $algorithm): string
{
    $publicKey = preg_replace('/\s+/', '', $publicKey);
    $binary = base64_decode($publicKey, true);
    if ($publicKey === '' || $binary === false || base64_encode($binary) !== $publicKey) {
        throw new InvalidArgumentException('Invalid public key encoding');
    }
    // RFC 9563 and the ML-DSA-44 DNSSEC specification define fixed key sizes.
    $lengths = [17 => 64, 18 => 1312];
    if (isset($lengths[$algorithm]) && strlen($binary) !== $lengths[$algorithm]) {
        throw new InvalidArgumentException('Invalid public key length for algorithm ' . $algorithm);
    }
    // TEXT storage, leaving ample room for existing RSA keys as well.
    if (strlen($publicKey) > 16384) {
        throw new InvalidArgumentException('Public key is too long');
    }
    return $binary;
}

function dnssec_owner_wire(string $owner): string
{
    $owner = strtolower(rtrim($owner, '.'));
    $wire = '';
    foreach (explode('.', $owner) as $label) {
        if (strlen($label) < 1 || strlen($label) > 63) {
            throw new InvalidArgumentException('Invalid DNSKEY owner name');
        }
        $wire .= chr(strlen($label)) . $label;
    }
    if (strlen($wire) + 1 > 255) {
        throw new InvalidArgumentException('DNSKEY owner name is too long');
    }
    return $wire . "\0";
}

function dnssec_keytag(string $rdata): int
{
    $sum = 0;
    for ($i = 0, $length = strlen($rdata); $i < $length; ++$i) {
        $sum += ($i & 1) ? ord($rdata[$i]) : ord($rdata[$i]) << 8;
    }
    $sum += ($sum >> 16) & 0xffff;
    return $sum & 0xffff;
}

function dnssec_digest(string $data, int $type): string
{
    // SHA-1 is retained only for the legacy dnssec_key2ds helper's API.
    $hashes = [1 => 'sha1', 2 => 'sha256', 4 => 'sha384'];
    if (isset($hashes[$type])) {
        return strtoupper(hash($hashes[$type], $data));
    }
    if ($type !== 6) {
        throw new InvalidArgumentException('Unsupported digest type');
    }
    // SM3 is provided by OpenSSL, not by PHP's hash extension on all versions.
    if (!function_exists('openssl_get_md_methods') ||
        !in_array('sm3', array_map('strtolower', openssl_get_md_methods()), true)) {
        throw new RuntimeException('SM3 digest generation requires OpenSSL SM3 support');
    }
    $digest = openssl_digest($data, 'sm3');
    if ($digest === false) {
        throw new RuntimeException('SM3 digest generation failed');
    }
    return strtoupper($digest);
}

function dnssec_derive_ds(string $owner, int $flags, int $protocol, int $algorithm, string $publicKey, int $digestType = 2): array
{
    if (!in_array($flags, [256, 257], true) || $protocol !== 3 ||
        !in_array($algorithm, dnssec_algorithms(), true)) {
        throw new InvalidArgumentException('Invalid DNSKEY flags, protocol or algorithm');
    }
    if (!isset(dnssec_digest_lengths()[$digestType])) {
        throw new InvalidArgumentException('Unsupported digest type');
    }
    $binary = dnssec_decode_key($publicKey, $algorithm);
    $rdata = pack('nCC', $flags, $protocol, $algorithm) . $binary;
    return [
        'keytag' => dnssec_keytag($rdata),
        'alg' => $algorithm,
        'digesttype' => $digestType,
        'digest' => dnssec_digest(dnssec_owner_wire($owner) . $rdata, $digestType),
        'flags' => $flags,
        'protocol' => $protocol,
        'keydata_alg' => $algorithm,
        'pubkey' => base64_encode($binary),
    ];
}
