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

namespace App\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

class MaintenanceController extends Controller
{
    private function isAdmin(): bool
    {
        // Namingo uses role 0 for registry administrators (not user ID 0).
        return in_array($_SESSION['auth_roles'] ?? null, [0, '0'], true);
    }

    private function forbidden(Response $response): Response
    {
        $response->getBody()->write('Forbidden');
        return $response->withStatus(403);
    }

    public function index(Request $request, Response $response): Response
    {
        $pdo = $this->container->get('pdo');
        $sql = 'SELECT id, item_json, deleted FROM maintenance';
        if (!$this->isAdmin()) {
            // Deleted events are tombstones for EPP and not shown to registrars.
            $sql .= ' WHERE deleted = FALSE';
        }
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $notices = [];
        foreach ($rows as $row) {
            $item = json_decode($row['item_json'], true);
            if (!is_array($item)) {
                continue;
            }
            $item['id'] = $row['id'];
            $item['deleted'] = in_array($row['deleted'], [true, 1, '1', 't', 'true'], true);
            $item['endSentAt'] = $item['_cp']['endSentAt'] ?? null;
            $notices[] = $item;
        }
        // RFC timestamps are canonical UTC strings, so lexical sort is chronological.
        usort($notices, static fn($a, $b) => strcmp($b['start'] ?? '', $a['start'] ?? ''));

        return view($response, 'admin/maintenance/index.twig', [
            'notices' => $notices,
            'isAdmin' => $this->isAdmin(),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (string) ($args['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $id)) {
            return $response->withStatus(404);
        }
        $stmt = $this->container->get('pdo')->prepare(
            'SELECT item_json, deleted FROM maintenance WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $response->withStatus(404);
        }
        $deleted = in_array($row['deleted'], [true, 1, '1', 't', 'true'], true);
        if ($deleted && !$this->isAdmin()) {
            return $response->withStatus(404);
        }
        $item = json_decode($row['item_json'], true);
        if (!is_array($item)) {
            return $response->withStatus(404);
        }
        $item['id'] = $id;
        return view($response, 'admin/maintenance/show.twig', [
            'item' => $item,
            'deleted' => $deleted,
            'isAdmin' => $this->isAdmin(),
            'ended' => !empty($item['_cp']['endSentAt']),
            'revision' => hash('sha256', $row['item_json']),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->forbidden($response);
        }
        return view($response, 'admin/maintenance/create.twig', ['old' => []]);
    }

    public function store(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->forbidden($response);
        }
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            $data = [];
        }
        $pdo = $this->container->get('pdo');
        try {
            $event = self::makeEvent($data);
            $event['crDate'] = gmdate('Y-m-d\TH:i:s\Z');
            $stored = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO maintenance (id, item_json, deleted) VALUES (?, ?, FALSE)');
            $insert->execute([$event['id'], $stored]);
            self::queueNotification($pdo, $event, 'create');
            $pdo->commit();
            $this->container->get('flash')->addMessage('success', 'Maintenance notice created and queued for registrars.');
            return $response->withHeader('Location', '/registry/maintenance')->withStatus(303);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof InvalidArgumentException) {
                $message = $e->getMessage(); // Only constant, server-defined validation messages.
                $status = 422;
            } elseif ($e instanceof PDOException && in_array($e->getCode(), ['23000', '23505'], true)) {
                $message = 'A maintenance notice with that ID already exists.';
                $status = 409;
            } else {
                error_log('Maintenance creation failed: ' . $e->getMessage());
                $message = 'Unable to publish maintenance notice. Please try again.';
                $status = 500;
            }
            return view($response->withStatus($status), 'admin/maintenance/create.twig', [
                'error' => $message,
                'old' => $data,
            ]);
        }
    }

    /** RFC 9167 notification snapshots all use the existing poll.msg column. */
    private static function queueNotification(PDO $pdo, array $event, string $pollType): void
    {
        // The _cp key is control-panel bookkeeping, never an RFC 9167 object field.
        unset($event['_cp'], $event['pollType']);
        $event['pollType'] = $pollType;
        $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        // PostgreSQL TEXT is unbounded, but MariaDB poll.msg is TEXT (64 KiB).
        if (strlen($json) > 65000) {
            throw new InvalidArgumentException('Maintenance notice is too large for the EPP poll queue.');
        }
        $stmt = $pdo->prepare(
            "INSERT INTO poll (registrar_id, qdate, msg, msg_type, obj_name_or_id)
             SELECT id, ?, ?, 'maintenance', ? FROM registrar"
        );
        $stmt->execute([gmdate('Y-m-d H:i:s'), $json, $event['id']]);
    }

    private static function validId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $id);
    }

    private static function toForm(array $event): array
    {
        $systems = [];
        foreach ($event['systems'] ?? [] as $system) {
            $systems[] = ($system['name'] ?? '') . '|' . ($system['host'] ?? '') . '|' . ($system['impact'] ?? '');
        }
        return [
            'id' => $event['id'],
            'name' => $event['name'] ?? '',
            'start' => substr($event['start'] ?? '', 0, 16),
            'end' => substr($event['end'] ?? '', 0, 16),
            'environment' => $event['environment']['type'] ?? 'production',
            'environment_name' => $event['environment']['name'] ?? '',
            'reason' => $event['reason'] ?? 'planned',
            'type' => $event['types'][0]['text'] ?? '',
            'systems' => implode("\n", $systems),
            'tlds' => implode(', ', $event['tlds'] ?? []),
            'description' => $event['descriptions'][0]['text'] ?? '',
            'detail' => $event['detail'] ?? '',
            'connection' => !empty($event['intervention']['connection']),
            'implementation' => !empty($event['intervention']['implementation']),
        ];
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->forbidden($response);
        }
        $id = (string) ($args['id'] ?? '');
        if (!self::validId($id)) {
            return $response->withStatus(404);
        }
        $stmt = $this->container->get('pdo')->prepare(
            'SELECT item_json, deleted FROM maintenance WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || self::isDeleted($row['deleted'])) {
            return $response->withStatus(404);
        }
        $item = json_decode($row['item_json'], true);
        if (!is_array($item) || !empty($item['_cp']['endSentAt'])) {
            return $response->withStatus(409);
        }
        return view($response, 'admin/maintenance/create.twig', [
            'isEdit' => true,
            'eventId' => $id,
            'revision' => hash('sha256', $row['item_json']),
            'old' => self::toForm($item),
        ]);
    }

    private static function isDeleted($value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    /** POST /edit: post-update snapshot, or /notify|end|delete with the prior state. */
    public function change(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->forbidden($response);
        }
        $id = (string) ($args['id'] ?? '');
        $action = (string) ($args['action'] ?? '');
        if (!self::validId($id) || !in_array($action, ['edit', 'notify', 'end', 'delete'], true)) {
            return $response->withStatus(404);
        }
        $data = $request->getParsedBody();
        $data = is_array($data) ? $data : [];
        $pdo = $this->container->get('pdo');
        try {
            if (!is_string($data['revision'] ?? null) ||
                !preg_match('/^[a-f0-9]{64}$/D', $data['revision'])) {
                throw new InvalidArgumentException('Missing or invalid revision; refresh the page.');
            }
            if ($action === 'edit' && isset($data['id']) && $data['id'] !== $id) {
                throw new InvalidArgumentException('Maintenance event IDs cannot be changed.');
            }
            // Validate BEFORE opening the transaction; the locked row is checked below.
            $candidate = null;
            if ($action === 'edit') {
                $data['id'] = $id;
                $candidate = self::makeEvent($data);
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT item_json, deleted FROM maintenance WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if (!$row || self::isDeleted($row['deleted'])) {
                throw new InvalidArgumentException('Maintenance event was deleted or cannot be found.');
            }
            if (!hash_equals(hash('sha256', $row['item_json']), $data['revision'])) {
                throw new InvalidArgumentException('Maintenance event changed since you opened it. Refresh and retry.');
            }
            $current = json_decode($row['item_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($current)) {
                throw new InvalidArgumentException('Invalid stored maintenance notice.');
            }
            $ended = !empty($current['_cp']['endSentAt']);
            if ($ended && $action !== 'delete') {
                throw new InvalidArgumentException('An end notification was already sent; only deletion is permitted.');
            }

            if ($action === 'edit') {
                // Preserve identity, creation time, and optional extensions not edited by CP.
                $next = $current;
                foreach (['name', 'systems', 'environment', 'start', 'end', 'reason', 'intervention'] as $key) {
                    $next[$key] = $candidate[$key];
                }
                $next['types'][0] = $candidate['types'][0];
                $next['descriptions'][0] = $candidate['descriptions'][0];
                unset($next['tlds'], $next['detail']);
                foreach (['tlds', 'detail'] as $key) {
                    if (array_key_exists($key, $candidate)) {
                        $next[$key] = $candidate[$key];
                    }
                }
                $next['upDate'] = gmdate('Y-m-d\TH:i:s\Z');
                unset($next['pollType']);
                $json = json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $pdo->prepare('UPDATE maintenance SET item_json = ? WHERE id = ?')->execute([$json, $id]);
                self::queueNotification($pdo, $next, 'update');
                $message = 'Maintenance updated and registrars notified.';
            } elseif ($action === 'notify') {
                self::queueNotification($pdo, $current, 'courtesy');
                $message = 'Courtesy reminder queued for all registrars.';
            } elseif ($action === 'end') {
                // RFC 9167: an end poll includes the latest state without modifying
                // the advertised maintenance object. _cp is private UI-only metadata.
                self::queueNotification($pdo, $current, 'end');
                $current['_cp']['endSentAt'] = gmdate('Y-m-d\TH:i:s\Z');
                $json = json_encode($current, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $pdo->prepare('UPDATE maintenance SET item_json = ? WHERE id = ?')->execute([$json, $id]);
                $message = 'End notification queued for all registrars.';
            } else {
                // The delete notification MUST snapshot the state BEFORE deletion.
                self::queueNotification($pdo, $current, 'delete');
                $pdo->prepare('UPDATE maintenance SET deleted = TRUE WHERE id = ?')->execute([$id]);
                $message = 'Maintenance withdrawn and deletion queued for all registrars.';
            }
            $pdo->commit();
            $this->container->get('flash')->addMessage('success', $message);
            return $response->withHeader('Location', '/registry/maintenance/' . rawurlencode($id))->withStatus(303);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof InvalidArgumentException) {
                $message = $e->getMessage();
                $status = 409;
            } else {
                error_log('Maintenance management failed: ' . $e->getMessage());
                $message = 'Unable to publish maintenance change. No notifications were committed.';
                $status = 500;
            }
            if ($action === 'edit' && $status === 409) {
                // Do not regenerate a revision on error: stale edits must reload.
                return view($response->withStatus($status), 'admin/maintenance/create.twig', [
                    'isEdit' => true,
                    'eventId' => $id,
                    'revision' => (string) ($data['revision'] ?? ''),
                    'error' => $message,
                    'old' => $data,
                ]);
            }
            $this->container->get('flash')->addMessage('error', $message);
            return $response->withHeader('Location', '/registry/maintenance/' . rawurlencode($id))->withStatus(303);
        }
    }

    private static function utcFromLocal($value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Start and end timestamps are required (UTC).');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException('Invalid UTC start or end timestamp.');
        }
        return $date->format('Y-m-d\TH:i:s\Z');
    }

    private static function aLabel(string $value): bool
    {
        return strlen($value) <= 253 && (bool) preg_match(
            '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/D',
            $value
        );
    }

    private static function makeEvent(array $data): array
    {
        $id = trim((string) ($data['id'] ?? ''));
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $id)) {
            throw new InvalidArgumentException('Invalid maintenance ID (1–64 ASCII letters, numbers, dot, hyphen, underscore).');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || strlen($name) > 255) {
            throw new InvalidArgumentException('A maintenance name of at most 255 bytes is required.');
        }
        $start = self::utcFromLocal($data['start'] ?? null);
        $end = self::utcFromLocal($data['end'] ?? null);
        if ($end <= $start) {
            throw new InvalidArgumentException('End time must be after start time.');
        }
        $environment = (string) ($data['environment'] ?? '');
        $reason = (string) ($data['reason'] ?? '');
        if (!in_array($environment, ['production', 'ote', 'staging', 'dev', 'custom'], true)
            || !in_array($reason, ['planned', 'emergency'], true)) {
            throw new InvalidArgumentException('Invalid maintenance environment or reason.');
        }
        $env = ['type' => $environment];
        if ($environment === 'custom') {
            $envName = trim((string) ($data['environment_name'] ?? ''));
            if ($envName === '' || strlen($envName) > 255) {
                throw new InvalidArgumentException('Custom environment requires a name.');
            }
            $env['name'] = $envName;
        }

        // One affected system per line: name|hostname|full/partial/none.
        $systems = [];
        foreach (preg_split('/\r\n|\n|\r/', (string) ($data['systems'] ?? '')) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 3 || $parts[0] === '' || strlen($parts[0]) > 255
                || !in_array($parts[2], ['full', 'partial', 'none'], true)
                || ($parts[1] !== '' && !self::aLabel($parts[1]))) {
                throw new InvalidArgumentException('Systems must be one per line: name|hostname|full (or partial/none).');
            }
            $system = ['name' => $parts[0], 'impact' => $parts[2]];
            if ($parts[1] !== '') {
                $system['host'] = $parts[1];
            }
            $systems[] = $system;
        }
        if (count($systems) < 1 || count($systems) > 25) {
            throw new InvalidArgumentException('Specify between 1 and 25 affected systems.');
        }

        $type = trim((string) ($data['type'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        if ($type === '' || strlen($type) > 255 || $description === '' || strlen($description) > 5000) {
            throw new InvalidArgumentException('An event type (up to 255 bytes) and description (up to 5000 bytes) are required.');
        }
        $tlds = [];
        foreach (preg_split('/[,\s]+/', strtolower(trim((string) ($data['tlds'] ?? '')))) as $tld) {
            if ($tld === '') {
                continue;
            }
            $tld = ltrim($tld, '.');
            if (!self::aLabel($tld)) {
                throw new InvalidArgumentException('TLDs must use DNS A-labels, separated by commas.');
            }
            $tlds[] = $tld;
        }
        $tlds = array_values(array_unique($tlds));

        $event = [
            'id' => $id,
            'name' => $name,
            'nameLang' => 'en',
            'types' => [['text' => $type, 'lang' => 'en']],
            'systems' => $systems,
            'environment' => $env,
            'start' => $start,
            'end' => $end,
            'reason' => $reason,
            'descriptions' => [['lang' => 'en', 'type' => 'plain', 'text' => $description]],
            'intervention' => [
                'connection' => isset($data['connection']),
                'implementation' => isset($data['implementation']),
            ],
        ];
        if ($tlds !== []) {
            $event['tlds'] = $tlds;
        }
        $detail = trim((string) ($data['detail'] ?? ''));
        if ($detail !== '') {
            $scheme = strtolower((string) parse_url($detail, PHP_URL_SCHEME));
            if (!filter_var($detail, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Details URL must be HTTP or HTTPS.');
            }
            $event['detail'] = $detail;
        }
        return $event;
    }
}
