#!/usr/bin/env php
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

/*
 * Example:
 * php abuse-action.php --ticket=123 --action=registrar_notified \
 *     --actor=operator@example.org --notes="Evidence sent to registrar" \
 *     --evidence=https://example.org/incident/123
 * Actions are append-only. A "detected" entry is created by the scanner.
 */

require __DIR__ . '/vendor/autoload.php';
$c = require __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options = getopt('', ['ticket:', 'action:', 'actor:', 'notes:', 'evidence::']);
$ticketId = filter_var($options['ticket'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = $options['action'] ?? '';
$actor = trim((string)($options['actor'] ?? ''));
$notes = trim((string)($options['notes'] ?? ''));
$evidence = trim((string)($options['evidence'] ?? ''));
$allowed = ['investigation_started', 'confirmed_abuse', 'false_positive',
    'registrar_notified', 'registry_hold', 'registrar_hold', 'domain_deleted',
    'no_action', 'resolved', 'other'];
if (!$ticketId || !in_array($action, $allowed, true)
    || $actor === '' || strlen($actor) > 255 || $notes === '' || strlen($notes) > 10000
    || strlen($evidence) > 2048 || ($evidence !== '' && !filter_var($evidence, FILTER_VALIDATE_URL))) {
    fwrite(STDERR, "Required: --ticket=N --action=" . implode('|', $allowed)
        . " --actor=NAME --notes=TEXT [--evidence=URL]\n");
    exit(2);
}

try {
    $dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare("SELECT a.tld_id, a.registrar_id, a.domain_name
        FROM abuse_actions a WHERE a.ticket_id = ? AND a.action_type = 'detected'
        ORDER BY a.id LIMIT 1");
    $stmt->execute([$ticketId]);
    $scope = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$scope) {
        // Legacy tickets may not have a detection snapshot.
        $stmt = $pdo->prepare('SELECT d.tldid AS tld_id, d.clid AS registrar_id, d.name AS domain_name
            FROM support_tickets s JOIN domain d ON d.name = s.reported_domain
            WHERE s.id = ? AND s.category_id = 8 LIMIT 1');
        $stmt->execute([$ticketId]);
        $scope = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$scope) {
        throw new RuntimeException('Ticket not found or no TLD/registrar scope available');
    }
    $isPostgres = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $sql = 'INSERT INTO abuse_actions
        (ticket_id, tld_id, registrar_id, domain_name, action_type, actor, notes, evidence_url, action_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = $pdo->prepare($sql . ($isPostgres ? ' RETURNING id' : ''));
    $stmt->execute([$ticketId, $scope['tld_id'], $scope['registrar_id'], $scope['domain_name'],
        $action, $actor, $notes, $evidence ?: null, gmdate('Y-m-d H:i:s')]);
    $id = $isPostgres ? $stmt->fetchColumn() : $pdo->lastInsertId();
    echo 'Recorded Spec 11 action #' . $id . " for ticket #$ticketId\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
