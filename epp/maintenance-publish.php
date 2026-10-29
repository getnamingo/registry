<?php
/*
 * RFC 9167 operator CLI. Usage:
 *   php maintenance-publish.php create  event.json
 *   php maintenance-publish.php update  event.json
 *   php maintenance-publish.php delete  EVENT-ID
 *   php maintenance-publish.php courtesy EVENT-ID
 *   php maintenance-publish.php end     EVENT-ID
 * event.json uses the naming conventions of EppWriter::_maintenanceItem().
 * Publication and one poll snapshot per registrar are committed atomically.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 3 || !in_array($argv[1],
        ['create', 'update', 'delete', 'courtesy', 'end'], true)) {
    fwrite(STDERR, "Usage: php maintenance-publish.php {create|update|delete|courtesy|end} {event.json|id}\n");
    exit(2);
}

function validTime(string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
}
function validAlabel(string $value): bool {
    return strlen($value) <= 253 && (bool) preg_match(
        '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/D', $value);
}
function validateItem(array $item): void {
    if (empty($item['id']) || !is_string($item['id']) ||
        !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $item['id'])) {
        throw new InvalidArgumentException('id must be an ASCII token, max 64 bytes');
    }
    if (empty($item['systems']) || !is_array($item['systems'])) {
        throw new InvalidArgumentException('At least one system is required');
    }
    foreach ($item['systems'] as $system) {
        if (!is_string($system['name'] ?? null) || trim($system['name']) === '' ||
            !in_array($system['impact'] ?? null, ['full', 'partial', 'none'], true)) {
            throw new InvalidArgumentException('Invalid affected system');
        }
        if (isset($system['host']) && !validAlabel($system['host'])) {
            throw new InvalidArgumentException('Host must be an A-label');
        }
    }
    if (!in_array($item['environment']['type'] ?? null,
        ['production', 'ote', 'staging', 'dev', 'custom'], true) ||
        !in_array($item['reason'] ?? null, ['planned', 'emergency'], true)) {
        throw new InvalidArgumentException('Invalid environment or reason');
    }
    if (($item['environment']['type'] ?? null) === 'custom' &&
        empty($item['environment']['name'])) {
        throw new InvalidArgumentException('Custom environment needs a name');
    }
    if (!is_string($item['start'] ?? null) || !validTime($item['start']) ||
        !is_string($item['end'] ?? null) || !validTime($item['end']) ||
        $item['end'] <= $item['start']) {
        throw new InvalidArgumentException('Invalid UTC start/end timestamps');
    }
    if (isset($item['detail']) && !filter_var($item['detail'], FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('detail must be a URL');
    }
    foreach (($item['tlds'] ?? []) as $tld) {
        if (!validAlabel($tld)) throw new InvalidArgumentException('Invalid A-label TLD');
    }
    foreach (($item['descriptions'] ?? []) as $desc) {
        if (!is_string($desc['text'] ?? null) ||
            !in_array($desc['type'] ?? 'plain', ['plain', 'html'], true)) {
            throw new InvalidArgumentException('Invalid description');
        }
    }
    foreach (($item['types'] ?? []) as $type) {
        if (!is_string($type['text'] ?? null)) {
            throw new InvalidArgumentException('Invalid type');
        }
    }
    if (isset($item['intervention']) &&
        (!is_bool($item['intervention']['connection'] ?? null) ||
         !is_bool($item['intervention']['implementation'] ?? null))) {
        throw new InvalidArgumentException('Invalid intervention');
    }
}

try {
    $action = $argv[1];
    $event = in_array($action, ['create', 'update'], true)
        ? json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR) : null;
    if (in_array($action, ['create', 'update'], true)) {
        if (!is_array($event)) throw new InvalidArgumentException('Event file must be a JSON object');
        validateItem($event);
    }
    $id = $event['id'] ?? $argv[2];
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $id)) {
        throw new InvalidArgumentException('Invalid event id');
    }
    $cfg = require __DIR__ . '/config.php';
    $driver = $cfg['db_type'] === 'pgsql' ? 'pgsql' : 'mysql';
    $dsn = sprintf('%s:host=%s;port=%d;dbname=%s', $driver,
        $cfg['db_host'], $cfg['db_port'], $cfg['db_database']);
    if ($driver === 'mysql') $dsn .= ';charset=utf8mb4';
    $db = new PDO($dsn, $cfg['db_username'], $cfg['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT item_json, deleted FROM maintenance WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $now = gmdate('Y-m-d\TH:i:s\Z');

    if ($action === 'create') {
        if ($old) throw new RuntimeException('Maintenance id already exists');
        $event['crDate'] = $now;
        unset($event['upDate'], $event['pollType']);
        $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $db->prepare('INSERT INTO maintenance (id, item_json, deleted) VALUES (?, ?, FALSE)')
            ->execute([$id, $json]);
    } else {
        if (!$old || in_array($old['deleted'], [true, 1, '1', 't', 'true'], true)) {
            throw new RuntimeException('Maintenance id not found or deleted');
        }
        $previous = json_decode($old['item_json'], true, 512, JSON_THROW_ON_ERROR);
        if ($action === 'update') {
            $event['crDate'] = $previous['crDate'];
            $event['upDate'] = $now;
            unset($event['pollType']);
            $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $db->prepare('UPDATE maintenance SET item_json = ? WHERE id = ?')
                ->execute([$json, $id]);
        } else {
            $event = $previous;
            if ($action === 'delete') {
                $db->prepare('UPDATE maintenance SET deleted = TRUE WHERE id = ?')->execute([$id]);
            }
        }
    }
    $event['pollType'] = $action;
    $snapshot = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (strlen($snapshot) > 65000) {
        throw new RuntimeException('Maintenance event too large for poll.msg TEXT');
    }
    $qdate = gmdate('Y-m-d H:i:s');
    $db->prepare("INSERT INTO poll (registrar_id, qdate, msg, msg_type)
        SELECT id, ?, ?, 'maintenance' FROM registrar")
        ->execute([$qdate, $snapshot]);
    $db->commit();
    echo "Published {$action} notification for {$id}\n";
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
