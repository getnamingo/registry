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

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

use GuzzleHttp\Client;

$config = require __DIR__ . '/config.php';
$log = setupLogger('/var/log/namingo/ntfy_error_notifier.log', 'Ntfy_Error_Notifier');

/** Replace the checkpoint atomically; the separate .lock file is never replaced. */
function saveNtfyCursor(string $path, int $baseline, array $delivered): void
{
    $temp = tempnam(dirname($path), '.ntfy-');
    if ($temp === false) {
        throw new RuntimeException('Unable to create temporary ntfy cursor');
    }
    try {
        $stream = fopen($temp, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open temporary ntfy cursor');
        }
        try {
            chmod($temp, 0600);
            $contents = json_encode(['baseline' => $baseline,
                'delivered' => array_map('intval', array_keys($delivered))], JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)
                || (function_exists('fsync') && !fsync($stream))) {
                throw new RuntimeException('Unable to flush ntfy cursor');
            }
        } finally {
            fclose($stream);
        }
        if (!rename($temp, $path)) {
            throw new RuntimeException('Unable to publish ntfy cursor');
        }
    } finally {
        if (is_file($temp)) {
            unlink($temp);
        }
    }
}

try {
    $topic = trim((string)($config['ntfy_topic'] ?? ''));
    if ($topic === '') {
        $log->info('ntfy topic not configured; notifier disabled.');
        exit(0);
    }
    $parts = parse_url($topic);
    if ($parts === false || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
        || empty($parts['host']) || empty($parts['path'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new RuntimeException('ntfy_topic must be a full HTTP(S) topic URL without credentials or query parameters');
    }
    $minLevel = (int)($config['ntfy_min_level'] ?? 400);
    if ($minLevel < 0 || $minLevel > 600) {
        throw new RuntimeException('ntfy_min_level must be between 0 and 600');
    }
    $stateFile = (string)($config['ntfy_last_id_file'] ?? '');
    if ($stateFile === '' || !is_dir(dirname($stateFile))) {
        throw new RuntimeException('ntfy_last_id_file directory does not exist');
    }
    if (!empty($config['ntfy_token']) && ($parts['scheme'] ?? '') !== 'https') {
        throw new RuntimeException('Authenticated ntfy publishing requires HTTPS');
    }

    $lock = fopen($stateFile . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Unable to open ntfy notification lock');
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $log->info('Another notifier process is already running.');
            exit(0);
        }
        $dsn = sprintf('%s:host=%s;port=%s;dbname=%s',
            $config['db_type'], $config['db_host'], $config['db_port'], $config['db_database']);
        if ($config['db_type'] === 'mysql') {
            $dsn .= ';charset=utf8mb4';
        }
        $db = new PDO($dsn, $config['db_username'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if (!is_file($stateFile)) {
            // Never replay months of historical incidents on first enablement.
            $initial = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM error_log')->fetchColumn();
            saveNtfyCursor($stateFile, $initial, []);
            $log->info('Initialized ntfy cursor at current event', ['id' => $initial]);
            exit(0);
        }
        $content = trim((string)file_get_contents($stateFile));
        if (ctype_digit($content)) {
            // Accept checkpoints from the standalone notifier and first integration.
            $baseline = (int)$content;
            $delivered = [];
        } else {
            $checkpoint = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($checkpoint) || !isset($checkpoint['baseline'], $checkpoint['delivered'])
                || !is_int($checkpoint['baseline']) || $checkpoint['baseline'] < 0
                || !is_array($checkpoint['delivered'])) {
                throw new RuntimeException('Invalid ntfy cursor; fix the file before rerunning');
            }
            $baseline = $checkpoint['baseline'];
            $delivered = [];
            foreach ($checkpoint['delivered'] as $id) {
                if (!is_int($id) || $id <= $baseline) {
                    throw new RuntimeException('Invalid delivered ntfy event ID in cursor');
                }
                $delivered[$id] = true;
            }
        }
        // IDs are allocated before commit, so a lower ID can become visible
        // after a higher one. Revisit all IDs since the baseline, deduplicating
        // with the durable per-event delivery set rather than a high-water ID.
        $query = $db->prepare('SELECT id, level, level_name, message, created_at
            FROM error_log WHERE id > :scan_id AND level >= :min_level
            ORDER BY id ASC LIMIT 500');
        $query->bindValue(':min_level', $minLevel, PDO::PARAM_INT);

        $headers = ['Content-Type' => 'text/plain; charset=utf-8'];
        $token = trim((string)($config['ntfy_token'] ?? ''));
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $client = new Client(['timeout' => 10, 'connect_timeout' => 5,
            'http_errors' => false, 'allow_redirects' => false]);
        $sent = 0;
        $scanId = $baseline;
        while ($sent < 500) {
            $query->bindValue(':scan_id', $scanId, PDO::PARAM_INT);
            $query->execute();
            $page = $query->fetchAll();
            $query->closeCursor();
            if (!$page) {
                break;
            }
            foreach ($page as $event) {
                $id = (int)$event['id'];
                $scanId = $id;
                if (isset($delivered[$id])) {
                    continue;
                }
                $level = (int)$event['level'];
                $body = sprintf('[%s] [%s] %s',
                    $event['created_at'], $event['level_name'], $event['message']);
                $response = $client->post($topic, [
                    'headers' => $headers + [
                        'Title' => $level >= 500 ? 'Namingo critical error' : 'Namingo registry error',
                        'Priority' => $level >= 500 ? '5' : ($level >= 400 ? '4' : '3'),
                    ],
                    'body' => $body,
                ]);
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    throw new RuntimeException(sprintf(
                        'ntfy delivery failed with HTTP %d for event %d',
                        $response->getStatusCode(), $event['id']
                    ));
                }
                $delivered[$id] = true;
                saveNtfyCursor($stateFile, $baseline, $delivered);
                $sent++;
                if ($sent >= 500) {
                    break;
                }
            }
            if (count($page) < 500) {
                break;
            }
        }
        $log->info('ntfy notification check complete.', ['delivered' => $sent,
            'baseline' => $baseline, 'tracked_events' => count($delivered)]);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (Throwable $e) {
    $log->error('ntfy notification job failed: ' . $e->getMessage());
    exit(1);
}
