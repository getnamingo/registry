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

namespace App\Lib;

require_once __DIR__ . '/../../bootstrap/dnssec.php';

final class Dnssec
{
    /** Validate before opening a transaction; accept DS, DNSKEY, or matching DS+DNSKEY. */
    public static function fromForm(array $data, string $owner): ?array
    {
        $hasDs = self::hasAny($data, ['dsKeyTag', 'dsAlg', 'dsDigest']);
        $hasKey = self::hasAny($data, ['dnskeyFlags', 'dnskeyAlg', 'dnskeyPubKey']);
        if (!$hasDs && !$hasKey) {
            return null;
        }
        $record = [
            'interface' => $hasDs ? 'dsData' : 'keyData',
            'flags' => null, 'protocol' => null, 'keydata_alg' => null, 'pubkey' => null,
        ];
        if ($hasDs) {
            $record['keytag'] = self::integer($data['dsKeyTag'] ?? null, 0, 65535, 'key tag');
            $record['alg'] = self::integer($data['dsAlg'] ?? null, 0, 255, 'algorithm');
            $record['digesttype'] = self::integer($data['dsDigestType'] ?? null, 0, 255, 'digest type');
            if (!in_array($record['alg'], dnssec_algorithms(), true)) {
                throw new \InvalidArgumentException('Unsupported DNSSEC algorithm');
            }
            $lengths = dnssec_digest_lengths();
            $record['digest'] = strtoupper(self::text($data['dsDigest'] ?? null, 'digest'));
            if (!isset($lengths[$record['digesttype']]) ||
                strlen($record['digest']) !== $lengths[$record['digesttype']] || !ctype_xdigit($record['digest'])) {
                throw new \InvalidArgumentException('Unsupported digest type or invalid digest length/format');
            }
        }
        if ($hasKey) {
            $flags = self::integer($data['dnskeyFlags'] ?? null, 0, 65535, 'flags');
            if (!$hasDs && $flags !== 257) {
                throw new \InvalidArgumentException('DNSKEY-only submissions require KSK flags 257');
            }
            $protocol = self::integer($data['dnskeyProtocol'] ?? null, 0, 255, 'protocol');
            $algorithm = self::integer($data['dnskeyAlg'] ?? null, 0, 255, 'algorithm');
            $type = $hasDs ? $record['digesttype'] : self::integer($data['dnskeyDigestType'] ?? 2, 0, 255, 'digest type');
            $derived = dnssec_derive_ds($owner, $flags, $protocol, $algorithm,
                self::text($data['dnskeyPubKey'] ?? null, 'public key'), $type);
            if ($hasDs && ($record['keytag'] !== $derived['keytag'] ||
                $record['alg'] !== $derived['alg'] || $record['digest'] !== $derived['digest'])) {
                throw new \InvalidArgumentException('DS record does not match the supplied DNSKEY');
            }
            $record = array_replace($record, $derived);
        }
        return $record;
    }

    private static function hasAny(array $data, array $fields): bool
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && $data[$field] !== '') return true;
        }
        return false;
    }

    private static function text($value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Missing or invalid DNSSEC ' . $field);
        }
        return trim($value);
    }

    private static function integer($value, int $min, int $max, string $field): int
    {
        if ((!is_string($value) && !is_int($value)) || !ctype_digit((string)$value) ||
            (float)$value < $min || (float)$value > $max) {
            throw new \InvalidArgumentException('Missing or invalid DNSSEC ' . $field);
        }
        return (int)$value;
    }
}
