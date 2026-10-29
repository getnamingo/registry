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

function allocationTokenCpRows($db, string $domain): array
{
    $status = envi('DB_DRIVER') === 'pgsql' ? '"tokenStatus"' : 'tokenStatus';
    $actions = envi('DB_DRIVER') === 'pgsql' ? '"eppActions"' : 'eppActions';
    return $db->select("SELECT token, domain_name, $status AS token_status, registrars, tlds, " .
        "$actions AS epp_actions, expires_at FROM allocation_tokens WHERE domain_name = ?", [strtolower($domain)]) ?: [];
}

function allocationTokenCpAllowed($json, string $value, bool $tld = false): bool
{
    if ($json === null) {
        return true;
    }
    $items = json_decode((string)$json, true);
    if (!is_array($items) || !array_is_list($items)) {
        return false;
    }
    foreach ($items as $item) {
        if (!is_string($item)) {
            continue;
        }
        if ($tld ? ltrim(strtolower($item), '.') === ltrim(strtolower($value), '.') : $item === $value) {
            return true;
        }
    }
    return false;
}

function allocationTokenCpActionAllowed($json, string $action): bool
{
    return $json === null ? $action === 'create' : allocationTokenCpAllowed($json, $action);
}

function allocationTokenCpRequired($db, string $domain, string $action, bool $reserved = false): bool
{
    if ($db->selectValue("SELECT value FROM settings WHERE name = 'allocationTokens'") !== 'on') {
        return false;
    }
    if ($reserved && $action === 'create') {
        return true;
    }
    foreach (allocationTokenCpRows($db, $domain) as $row) {
        if (!($action === 'transfer' && $row['token_status'] === 'used') &&
            allocationTokenCpActionAllowed($row['epp_actions'], $action)) {
            return true;
        }
    }
    return false;
}

function allocationTokenCpValid($db, string $domain, string $value, string $action, ?int $registrarId = null): bool
{
    if ($value === '' || $db->selectValue("SELECT value FROM settings WHERE name = 'allocationTokens'") !== 'on') {
        return false;
    }
    $parts = extractDomainAndTLD(strtolower($domain), $db);
    $tld = strtolower((string)$parts['tld']);
    $clid = $registrarId === null ? null : $db->selectValue('SELECT clid FROM registrar WHERE id = ?', [$registrarId]);
    foreach (allocationTokenCpRows($db, $domain) as $row) {
        if (!hash_equals((string)$row['token'], $value) ||
            $row['token_status'] !== 'active' ||
            ($row['expires_at'] !== null && strtotime($row['expires_at'] . ' UTC') <= time()) ||
            !allocationTokenCpActionAllowed($row['epp_actions'], $action) ||
            !allocationTokenCpAllowed($row['tlds'], $tld, true) ||
            ($registrarId !== null && (!$clid || !allocationTokenCpAllowed($row['registrars'], (string)$clid)))) {
            continue;
        }
        return true;
    }
    return false;
}

/** Caller must already be in a transaction. */
function allocationTokenCpConsume($db, string $domain, string $value): void
{
    $status = envi('DB_DRIVER') === 'pgsql' ? '"tokenStatus"' : 'tokenStatus';
    $affected = $db->exec("UPDATE allocation_tokens SET $status = 'used', used_at = CURRENT_TIMESTAMP, " .
        "lastupdate = CURRENT_TIMESTAMP WHERE token = ? AND domain_name = ? AND $status = 'active' " .
        'AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)', [$value, strtolower($domain)]);
    if ((int)$affected !== 1) {
        throw new \RuntimeException('Allocation Token no longer available');
    }
}
