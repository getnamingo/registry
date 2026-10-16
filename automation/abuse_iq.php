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

require __DIR__ . '/vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$logFilePath = '/var/log/namingo/abuse_iq.log';
$log = setupLogger($logFilePath, 'Abuse_IQ');
$log->info('job started.');

if (empty($c['abuse_iq_api'])) {
    $log->error("Error: Missing configuration key \$c['abuse_iq_api'] — you need to add your iQ Abuse Manager API key in config.php");
    exit;
}

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
    exit(1);
}

// Call API
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.abusemanager.iq.global/api/v1/abusemanager/cases?verbose=0&limit=20&offset=0',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'X-API-KEY: ' . $c['abuse_iq_api'],
    ],
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    $log->error('Abuse iQ API connection failed: ' . curl_error($ch));
    exit(1);
}

if ($httpCode !== 200) {
    $log->error("Abuse iQ API returned HTTP $httpCode");
    exit(1);
}

// Decode response
$data = json_decode($response, true);

if (!isset($data['data']) || !is_array($data['data'])) {
    $log->error('Invalid Abuse iQ API response.');
    exit(1);
}

foreach ($data['data'] as $case) {
    $attr = $case['attributes'];

    if ($attr['sub_status'] !== 'new') continue;

    $domain = $attr['domain_name'];
    $abuseType = $attr['abuse_category'];

    // Check if already inserted
    $checkStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM support_tickets
         WHERE reported_domain = ?
           AND nature_of_abuse = ?
           AND category_id = 8
           AND date_created >= ?"
    );

    $checkStmt->execute([
        $domain,
        $abuseType,
        gmdate('Y-m-d H:i:s', time() - 30 * 86400)
    ]);

    if ($checkStmt->fetchColumn() > 0) continue;

    $sql = "
        SELECT 
            COALESCE(ru.user_id, admin.id) AS user_id
        FROM domain d
        LEFT JOIN registrar_users ru ON d.clid = ru.registrar_id
        LEFT JOIN users admin ON admin.username = 'admin'
        WHERE d.name = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$domain]);
    $userData = $stmt->fetch();

    if (!$userData || empty($userData['user_id'])) {
        $log->error("No user ID could be resolved for domain: $domain");
        continue;
    }

    // Insert new support ticket
    $insertStmt = $pdo->prepare("INSERT INTO support_tickets (
        user_id, category_id, subject, message, status, priority,
        reported_domain, nature_of_abuse, evidence, relevant_urls,
        date_of_incident, date_created, last_updated
    ) VALUES (
        ?, 8, ?, ?, 'Open', 'High',
        ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )");
    $insertStmt->execute([
        $userData['user_id'],
        "Abuse Report for $domain",
        "Abuse detected for domain $domain via $abuseType.",
        $domain,
        $abuseType,
        "Link to $abuseType",
        $attr['abuse_source'],
        $attr['create_date']
    ]);

    $log->info("created ticket for $domain");
}

$log->info('job finished successfully.');