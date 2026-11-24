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

/**
 * Persist progress after every successful delivery; a failed delivery must
 * leave the failed event eligible for the next run.
 */
function saveNtfyCursor($state, int $id): void
{
    if (!rewind($state) || !ftruncate($state, 0)
        || fwrite($state, (string)$id . "\n") === false || !fflush($state)) {
        throw new RuntimeException('Unable to save ntfy notification cursor');
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

    $state = fopen($stateFile, 'c+');
    if ($state === false) {
        throw new RuntimeException('Unable to open ntfy cursor file');
    }
    try {
        if (!flock($state, LOCK_EX | LOCK_NB)) {
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

        $content = trim((string)stream_get_contents($state));
        if ($content === '') {
            // Never replay months of historical incidents on first enablement.
            $initial = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM error_log')->fetchColumn();
            saveNtfyCursor($state, $initial);
            $log->info('Initialized ntfy cursor at current event', ['id' => $initial]);
            exit(0);
        }
        if (!ctype_digit($content)) {
            throw new RuntimeException('Invalid ntfy cursor; fix the file before rerunning');
        }
        $lastId = (int)$content;
        $query = $db->prepare('SELECT id, level, level_name, message, created_at
            FROM error_log WHERE id > :last_id AND level >= :min_level
            ORDER BY id ASC LIMIT 500');
        $query->bindValue(':last_id', $lastId, PDO::PARAM_INT);
        $query->bindValue(':min_level', $minLevel, PDO::PARAM_INT);
        $query->execute();

        $headers = ['Content-Type' => 'text/plain; charset=utf-8'];
        $token = trim((string)($config['ntfy_token'] ?? ''));
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $client = new Client(['timeout' => 10, 'connect_timeout' => 5,
            'http_errors' => false, 'allow_redirects' => false]);
        $sent = 0;
        while ($event = $query->fetch()) {
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
            $lastId = (int)$event['id'];
            saveNtfyCursor($state, $lastId);
            $sent++;
        }
        $log->info('ntfy notification check complete.', ['delivered' => $sent, 'cursor' => $lastId]);
    } finally {
        flock($state, LOCK_UN);
        fclose($state);
    }
} catch (Throwable $e) {
    $log->error('ntfy notification job failed: ' . $e->getMessage());
    exit(1);
}
