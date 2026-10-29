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

const ALLOCATION_TOKEN_NS = 'urn:ietf:params:xml:ns:allocationToken-1.0';

/**
 * Return the supplied token or null. The info marker is returned as an empty
 * string. Validate the namespace, location and structure, not XML prefixes.
 */
function allocationTokenParse(SimpleXMLElement $xml, string $command): ?string
{
    $all = $xml->xpath('//*[namespace-uri()="' . ALLOCATION_TOKEN_NS . '"]') ?: [];
    if (!$all) {
        return null;
    }

    $element = $command === 'info' ? 'info' : 'allocationToken';
    $xpath = '/e:epp/e:command/e:extension/allocationToken:' . $element;
    $nodes = $xml->xpath($xpath) ?: [];
    if (count($all) !== 1 || count($nodes) !== 1) {
        throw new InvalidArgumentException('Unexpected allocation-token extension element');
    }

    $node = $nodes[0];
    if (count($node->children()) !== 0 || count($node->attributes()) !== 0) {
        throw new InvalidArgumentException('Invalid allocation-token extension structure');
    }
    if ($command === 'info') {
        if (trim((string)$node) !== '') {
            throw new InvalidArgumentException('allocationToken:info must be empty');
        }
        return '';
    }

    // The XSD uses xs:token, which collapses XML whitespace.
    $value = preg_replace('/\s+/u', ' ', trim((string)$node));
    if (!is_string($value) || $value === '' || strlen($value) > 255) {
        throw new InvalidArgumentException('Invalid allocation-token value');
    }
    return $value;
}

function allocationTokensEnabled($db): bool
{
    $stmt = $db->query("SELECT value FROM settings WHERE name = 'allocationTokens' LIMIT 1");
    return $stmt->fetchColumn() === 'on';
}

function allocationTokenColumn($db, string $column): string
{
    // Mixed-case columns are quoted identifiers in PostgreSQL.
    return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? '"' . $column . '"' : $column;
}

/** The legacy null JSON policy means all registrars, TLDs and EPP actions. */
function allocationTokenAllows(?string $json, string $value, bool $isTld = false): bool
{
    if ($json === null) {
        return true;
    }
    $values = json_decode($json, true);
    if (!is_array($values) || !array_is_list($values)) {
        return false;
    }
    foreach ($values as $allowed) {
        if (!is_string($allowed)) {
            continue;
        }
        if ($isTld) {
            if (ltrim(strtolower($allowed), '.') === ltrim(strtolower($value), '.')) {
                return true;
            }
        } elseif ($allowed === $value) {
            return true;
        }
    }
    return false;
}

/** Legacy null action scope is create-only; transfer requires explicit opt-in. */
function allocationTokenAllowsAction(?string $json, string $command): bool
{
    return $json === null ? $command === 'create' : allocationTokenAllows($json, $command);
}

function allocationTokenRows($db, string $name): array
{
    $status = allocationTokenColumn($db, 'tokenStatus');
    $actions = allocationTokenColumn($db, 'eppActions');
    $stmt = $db->prepare("SELECT token, domain_name, $status AS token_status, registrars, tlds, " .
        "$actions AS epp_actions, expires_at FROM allocation_tokens WHERE domain_name = :domain_name");
    $stmt->execute([':domain_name' => strtolower($name)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** A configured token makes the named object token-controlled, even after expiry. */
function allocationTokenRequired($db, string $name, string $command, bool $reserved = false): bool
{
    if (!allocationTokensEnabled($db)) {
        return false;
    }
    if ($reserved && $command === 'create') {
        return true;
    }
    foreach (allocationTokenRows($db, $name) as $row) {
        if (!($command === 'transfer' && $row['token_status'] === 'used') &&
            allocationTokenAllowsAction($row['epp_actions'], $command)) {
            return true;
        }
    }
    return false;
}

/**
 * A separate, exact token comparison prevents MariaDB collation differences.
 * $clid is the EPP client identifier, not the internal registrar row ID.
 */
function allocationTokenValid($db, string $name, string $value, string $command, string $clid): bool
{
    if (!allocationTokensEnabled($db)) {
        return false;
    }
    $name = strtolower($name);
    $parts = extractDomainAndTLD($name);
    $tld = strtolower((string)$parts['tld']);
    foreach (allocationTokenRows($db, $name) as $row) {
        if (!hash_equals((string)$row['token'], $value) ||
            $row['token_status'] !== 'active' ||
            ($row['expires_at'] !== null && strtotime($row['expires_at'] . ' UTC') <= time()) ||
            !allocationTokenAllowsAction($row['epp_actions'], $command) ||
            !allocationTokenAllows($row['registrars'], $clid) ||
            !allocationTokenAllows($row['tlds'], $tld, true)) {
            continue;
        }
        return true;
    }
    return false;
}

/** Must be executed within the domain/application transaction. */
function allocationTokenConsume($db, string $name, string $value): bool
{
    if (!$db->inTransaction()) {
        throw new LogicException('Allocation token consumption requires a database transaction');
    }
    $status = allocationTokenColumn($db, 'tokenStatus');
    $sql = "UPDATE allocation_tokens SET $status = 'used', used_at = CURRENT_TIMESTAMP, " .
        "lastupdate = CURRENT_TIMESTAMP WHERE token = :token AND domain_name = :name " .
        "AND $status = 'active' AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)";
    $stmt = $db->prepare($sql);
    $stmt->execute([':token' => $value, ':name' => strtolower($name)]);
    return $stmt->rowCount() === 1;
}

/** Only an active, unexpired token should be exposed to a sponsor. */
function allocationTokenForInfo($db, string $name): ?string
{
    foreach (allocationTokenRows($db, $name) as $row) {
        if ($row['token_status'] === 'active' &&
            ($row['expires_at'] === null || strtotime($row['expires_at'] . ' UTC') > time())) {
            return (string)$row['token'];
        }
    }
    return null;
}
