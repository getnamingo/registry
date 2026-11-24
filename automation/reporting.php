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
 *
 * ICANN Registry Agreement Specification 3 monthly reporting.
 * See docs/reporting.md for required database migrations and source metrics.
 */


declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

if (!defined('RY_REPORTING_TEST')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/** The exact column order of ICANN Registry Agreement Specification 3. */
const RY_ACTIVITY = [
    'operational-registrars', 'zfa-passwords',
    'whois-43-queries', 'web-whois-queries', 'searchable-whois-queries',
    'dns-udp-queries-received', 'dns-udp-queries-responded',
    'dns-tcp-queries-received', 'dns-tcp-queries-responded',
    'srs-dom-check', 'srs-dom-create', 'srs-dom-delete', 'srs-dom-info',
    'srs-dom-renew', 'srs-dom-rgp-restore-report', 'srs-dom-rgp-restore-request',
    'srs-dom-transfer-approve', 'srs-dom-transfer-cancel', 'srs-dom-transfer-query',
    'srs-dom-transfer-reject', 'srs-dom-transfer-request', 'srs-dom-update',
    'srs-host-check', 'srs-host-create', 'srs-host-delete', 'srs-host-info',
    'srs-host-update', 'srs-cont-check', 'srs-cont-create', 'srs-cont-delete',
    'srs-cont-info', 'srs-cont-transfer-approve', 'srs-cont-transfer-cancel',
    'srs-cont-transfer-query', 'srs-cont-transfer-reject',
    'srs-cont-transfer-request', 'srs-cont-update',
];
const RY_TRANSACTION = [
    'registrar-name', 'iana-id', 'total-domains', 'total-nameservers',
    'net-adds-1-yr', 'net-adds-2-yr', 'net-adds-3-yr', 'net-adds-4-yr',
    'net-adds-5-yr', 'net-adds-6-yr', 'net-adds-7-yr', 'net-adds-8-yr',
    'net-adds-9-yr', 'net-adds-10-yr',
    'net-renews-1-yr', 'net-renews-2-yr', 'net-renews-3-yr',
    'net-renews-4-yr', 'net-renews-5-yr', 'net-renews-6-yr',
    'net-renews-7-yr', 'net-renews-8-yr', 'net-renews-9-yr',
    'net-renews-10-yr', 'transfer-gaining-successful',
    'transfer-gaining-nacked', 'transfer-losing-successful',
    'transfer-losing-nacked', 'transfer-disputed-won', 'transfer-disputed-lost',
    'transfer-disputed-nodecision', 'deleted-domains-grace',
    'deleted-domains-nograce', 'restored-domains', 'restored-noreport',
    'agp-exemption-requests', 'agp-exemptions-granted',
    'agp-exempted-domains', 'attempted-adds',
];

function ryPdo(array $c, ?string $name = null): PDO
{
    $dsn = sprintf('%s:host=%s;port=%s;dbname=%s',
        $c['db_type'], $c['db_host'], $c['db_port'], $name ?? $c['db_database']);
    return new PDO($dsn, $c['db_username'], $c['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** Avoid claiming compliance when the history starts part-way through the month. */
function ryVerifyBaseline(PDO $db, DateTimeImmutable $start): void
{
    $started = $db->query('SELECT installed_at FROM reporting_baseline WHERE id = 1')->fetchColumn();
    if (!$started || $started > $start->format('Y-m-d H:i:s')) {
        throw new RuntimeException('Reporting history does not cover this entire month. ' .
            'Install the reporting database migration before the start of a reporting month.');
    }
}

function ryTlds(PDO $db): array
{
    $rows = $db->query('SELECT id, tld FROM domain_tld ORDER BY tld')->fetchAll();
    $result = [];
    foreach ($rows as $r) {
        $tld = strtolower(trim((string)$r['tld'], '.'));
        // No synthetic second-level zones: this interface submits per delegated TLD.
        if ($tld === '' || str_contains($tld, '.') || !preg_match('/^[a-z0-9-]+$/D', $tld)) {
            continue;
        }
        $result[$tld] = (int)$r['id'];
    }
    return $result;
}

function ryMetrics(string $base, string $tld, string $month): array
{
    $file = rtrim($base, '/') . '/metrics/' . $tld . '-' . str_replace('-', '', $month) . '.json';
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException("Missing per-TLD monthly service metrics: $file");
    }
    $m = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($m)) {
        throw new RuntimeException("Expected a JSON object in $file");
    }
    $required = array_slice(RY_ACTIVITY, 1, 8);
    foreach ($required as $key) {
        if (!array_key_exists($key, $m)) {
            throw new RuntimeException("Missing service metric '$key' in $file");
        }
        $v = $m[$key];
        // Empty is allowed only for unavailable WHOIS services.
        if ($v === '' && in_array($key, array_slice(RY_ACTIVITY, 2, 3), true)) {
            continue;
        }
        if ($key === 'zfa-passwords' && $v === 'CZDS') {
            continue;
        }
        if (!is_int($v) || $v < 0) {
            throw new RuntimeException("Metric '$key' must be a non-negative integer" .
                " (or the explicitly allowed empty/CZDS value): $file");
        }
    }
    // The monthly provider file is a source for external service counters only.
    // In multi-TLD installations, host/contact command counts cannot be inferred
    // from the EPP database because those objects are not assigned to a zone.
    $allowed = array_merge(['operational-registrars'], $required,
        array_filter(RY_ACTIVITY, fn (string $key): bool =>
            str_starts_with($key, 'srs-host-') || str_starts_with($key, 'srs-cont-')));
    foreach ($m as $key => $v) {
        if (!in_array($key, $allowed, true)) {
            throw new RuntimeException("Unsupported provider metric '$key' in $file");
        }
        if (!in_array($key, $required, true) && (!is_int($v) || $v < 0)) {
            throw new RuntimeException("Metric '$key' must be a non-negative integer in $file");
        }
    }
    return $m;
}

function ryBlankActivity(): array
{
    return array_fill_keys(RY_ACTIVITY, 0);
}
function ryBlankTransaction(string $name, $iana): array
{
    $row = array_fill_keys(RY_TRANSACTION, 0);
    $row['registrar-name'] = $name;
    $row['iana-id'] = $iana;
    return $row;
}

function ryRegistrars(PDO $db): array
{
    $rows = [];
    foreach ($db->query('SELECT id, name, iana_id FROM registrar ORDER BY iana_id, name') as $r) {
        // ICANN requires a valid sponsoring IANA ID, including reserved IDs.
        if ($r['iana_id'] === null || (int)$r['iana_id'] < 1) {
            throw new RuntimeException('Registrar ' . $r['name'] . ' has no IANA ID; cannot submit an ICANN report.');
        }
        $rows[(int)$r['id']] = ryBlankTransaction((string)$r['name'], (int)$r['iana_id']);
    }
    return $rows;
}

function ryCellIncrement(array &$rows, int $registrarId, string $field, int $by = 1): void
{
    if (!isset($rows[$registrarId])) {
        throw new RuntimeException("Missing registrar ID $registrarId in the reporting database");
    }
    $rows[$registrarId][$field] += $by;
}

/** States and associations at exactly 00:00 UTC on the first day of next month. */
/** Return all as-of domain rows for one TLD, indexed by domain ID. */
function ryDomainState(PDO $db, string $end, int $tldId): array
{
    $sql = <<<'SQL'
SELECT x.* FROM (
    SELECT e.*, ROW_NUMBER() OVER (PARTITION BY domain_id ORDER BY id DESC) AS rn
    FROM reporting_domain_event e WHERE event_time < :end
) x WHERE x.rn = 1 AND x.action <> 'delete' AND x.tldid = :tld
 AND NOT EXISTS (
    SELECT 1 FROM (
        SELECT s.*, ROW_NUMBER() OVER (PARTITION BY domain_id ORDER BY id DESC) AS srn
        FROM reporting_pending_create_event s WHERE event_time < :end2
    ) p WHERE p.srn = 1 AND p.domain_id = x.domain_id AND p.action = 'insert'
 )
SQL;
    $q = $db->prepare($sql);
    $q->execute(['end' => $end, 'end2' => $end, 'tld' => $tldId]);
    $result = [];
    foreach ($q as $row) $result[(int)$row['domain_id']] = $row;
    return $result;
}

function ryHostInventory(PDO $db, string $end, array $domains, array &$rows): void
{
    if (!$domains) return;
    $sql = <<<'SQL'
WITH hs AS (
    SELECT e.*, ROW_NUMBER() OVER (PARTITION BY host_id ORDER BY id DESC) AS rn
    FROM reporting_host_event e WHERE event_time < :end1
), ms AS (
    SELECT e.*, ROW_NUMBER() OVER (PARTITION BY domain_id, host_id ORDER BY id DESC) AS rn
    FROM reporting_map_event e WHERE event_time < :end2
)
SELECT m.domain_id, h.host_id FROM ms m JOIN hs h ON h.host_id = m.host_id
WHERE m.rn = 1 AND m.action = 'insert' AND h.rn = 1 AND h.action <> 'delete'
SQL;
    $q = $db->prepare($sql);
    $q->execute(['end1' => $end, 'end2' => $end]);
    $seen = [];
    foreach ($q as $m) {
        $domainId = (int)$m['domain_id'];
        if (!isset($domains[$domainId])) continue;
        $reg = (int)$domains[$domainId]['clid'];
        $key = $reg . ':' . (int)$m['host_id'];
        $seen[$key] = $reg;
    }
    foreach ($seen as $reg) ryCellIncrement($rows, $reg, 'total-nameservers');
}

/** Treat redemption as cancellation even when the domain is purged later. */
function ryWasPurged(PDO $db, string $name, string $crdate, string $until): bool
{
    static $q = null;
    $q ??= $db->prepare("SELECT COUNT(*) FROM reporting_domain_event
        WHERE name = :name AND crdate = :created AND event_time < :until
        AND (action = 'delete' OR (action = 'update' AND rgpstatus = 'redemptionPeriod'
            AND (prev_rgpstatus IS NULL OR prev_rgpstatus <> 'redemptionPeriod')))");
    $q->execute(['name' => $name, 'created' => $crdate, 'until' => $until]);
    return (int)$q->fetchColumn() > 0;
}

function ryLedger(PDO $db, string $start, string $end, int $tldId, string $tld,
    array &$rows, array $c): void
{
    // Registered/renewed names may be reported only after the relevant grace period.
    // Use the immutable statement ledger, NOT the current domain's addPeriod / renewPeriod
    // fields, which store the last operation and would lose multiple renewals.
    $maxDays = max((int)($c['addPeriodDays'] ?? 5), (int)($c['renewPeriodDays'] ?? 5),
        (int)($c['autoRenewPeriodDays'] ?? 45));
    $begin = (new DateTimeImmutable($start, new DateTimeZone('UTC')))
        ->modify("-$maxDays days")->format('Y-m-d H:i:s');
    $q = $db->prepare("SELECT id, registrar_id, date, command, domain_name, length_in_months
        FROM statement WHERE date >= :begin AND date < :end
        AND command IN ('create','renew','autoRenew') ORDER BY id");
    $q->execute(['begin' => $begin, 'end' => $end]);
    foreach ($q as $s) {
        $name = strtolower(trim((string)$s['domain_name'], '.'));
        if (!str_ends_with($name, '.' . $tld)) continue;
        $years = (int)$s['length_in_months'] / 12;
        if ($years < 1 || $years > 10 || (int)$s['length_in_months'] % 12 !== 0) {
            throw new RuntimeException('Invalid initial/renewal term in statement #' . $s['id']);
        }
        $isAdd = $s['command'] === 'create';
        $grace = $isAdd ? (int)($c['addPeriodDays'] ?? 5) :
            (int)($c[$s['command'] === 'autoRenew' ? 'autoRenewPeriodDays' : 'renewPeriodDays'] ?? 5);
        $date = new DateTimeImmutable($s['date'], new DateTimeZone('UTC'));
        $cutoff = $date->modify("+$grace days")->format('Y-m-d H:i:s');
        if ($cutoff < $start || $cutoff >= $end) continue;
        // Validate that the domain existed at the end of its grace period.
        if (ryWasPurged($db, $name, ryCreationDate($db, $name, $s['date']), $cutoff)) {
            continue;
        }
        ryCellIncrement($rows, (int)$s['registrar_id'],
            ($isAdd ? 'net-adds-' : 'net-renews-') . (int)$years . '-yr');
    }
}

function ryCreationDate(PDO $db, string $name, string $at): string
{
    static $q = null;
    // On PostgreSQL, statement.date and domain.crdate share transaction time,
    // but the AFTER trigger's clock_timestamp() is later than both.
    $q ??= $db->prepare("SELECT crdate FROM reporting_domain_event
        WHERE name = :name AND action <> 'delete' AND crdate <= :at
        ORDER BY crdate DESC, id DESC LIMIT 1");
    $q->execute(['name' => $name, 'at' => $at]);
    $v = $q->fetchColumn();
    if ($v === false) throw new RuntimeException("Missing domain history for $name");
    return (string)$v;
}

/** Durable changes emitted by database triggers, including lifecycle cron and panel operations. */
function ryEvents(PDO $db, string $start, string $end, string $tld, int $tldId,
    array &$rows, array $c): void
{
    $grace = max((int)($c['transferPeriodDays'] ?? 5), (int)($c['addPeriodDays'] ?? 5));
    $begin = (new DateTimeImmutable($start, new DateTimeZone('UTC')))
        ->modify("-$grace days")->format('Y-m-d H:i:s');
    $q = $db->prepare('SELECT * FROM reporting_domain_event WHERE event_time >= :begin
        AND event_time < :end AND tldid = :tld ORDER BY id');
    $q->execute(['begin' => $begin, 'end' => $end, 'tld' => $tldId]);
    $restorations = [];
    foreach ($q as $e) {
        $at = (string)$e['event_time'];
        $reg = (int)$e['clid'];
        if ($e['action'] === 'delete' && $at >= $start) {
            $created = new DateTimeImmutable($e['crdate'], new DateTimeZone('UTC'));
            $agpEnd = $created->modify('+' . (int)($c['addPeriodDays'] ?? 5) . ' days');
            $kind = (new DateTimeImmutable($at, new DateTimeZone('UTC'))) < $agpEnd
                ? 'deleted-domains-grace' : 'deleted-domains-nograce';
            ryCellIncrement($rows, $reg, $kind);
        }
        if ($e['action'] !== 'update') continue;
        if ($e['prev_trstatus'] === 'pending' && $e['trstatus'] === 'clientRejected'
            && $at >= $start) {
            if ($e['reid'] !== null) ryCellIncrement($rows, (int)$e['reid'], 'transfer-gaining-nacked');
            ryCellIncrement($rows, (int)$e['clid'], 'transfer-losing-nacked');
        }
        if ($e['prev_clid'] !== null && $e['prev_clid'] !== $e['clid']
            && in_array($e['trstatus'], ['clientApproved','serverApproved'], true)) {
            $cutoff = (new DateTimeImmutable($at, new DateTimeZone('UTC')))
                ->modify('+' . (int)($c['transferPeriodDays'] ?? 5) . ' days')
                ->format('Y-m-d H:i:s');
            // ICANN defers the gaining registrar's count until its transfer
            // grace period ends. The losing registrar counts on completion.
            if ($at >= $start && $at < $end) {
                ryCellIncrement($rows, (int)$e['prev_clid'], 'transfer-losing-successful');
            }
            if ($cutoff >= $start && $cutoff < $end
                && !ryWasPurged($db, (string)$e['name'], (string)$e['crdate'], $cutoff)) {
                ryCellIncrement($rows, (int)$e['clid'], 'transfer-gaining-successful');
            }
        }
        if ($at < $start) continue;
        if ($e['rgpres_time'] !== null && $e['rgpres_time'] !== $e['prev_rgpres_time']) {
            ryCellIncrement($rows, $reg, 'restored-domains');
            $restorations[] = [(int)$e['domain_id'], $reg];
        }
        if ($e['agp_request'] !== null && $e['agp_request'] !== $e['prev_agp_request']) {
            ryCellIncrement($rows, $reg, 'agp-exemption-requests');
        }
        if ($e['agp_grant'] !== null && $e['agp_grant'] !== $e['prev_agp_grant']) {
            ryCellIncrement($rows, $reg, 'agp-exemptions-granted');
            if (in_array($e['agp_exempted'], [true, 1, '1', 't', 'true'], true)) {
                ryCellIncrement($rows, $reg, 'agp-exempted-domains');
            }
        }
    }
    // A restore report may be filed after the restore itself. Evaluate whether
    // it is still missing at the report's month-end snapshot.
    $last = $db->prepare('SELECT rgpres_reason FROM reporting_domain_event
        WHERE domain_id = :id AND event_time < :end ORDER BY id DESC LIMIT 1');
    foreach ($restorations as [$id, $reg]) {
        $last->execute(['id' => $id, 'end' => $end]);
        $reason = $last->fetchColumn();
        if ($reason === false || $reason === null || trim((string)$reason) === '') {
            ryCellIncrement($rows, $reg, 'restored-noreport');
        }
    }
}

function ryParseFrame(?string $xml): array
{
    if ($xml === null || $xml === '') return [null, null, []];
    $dom = new DOMDocument('1.0', 'UTF-8');
    $old = libxml_use_internal_errors(true);
    try {
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return [null, null, []];
        }
        $xp = new DOMXPath($dom);
        $commands = $xp->query('/*[local-name()="epp"]/*[local-name()="command"]/*');
        if (!$commands) return [null, null, []];
        $cmd = null;
        $op = null;
        $names = [];
        foreach ($commands as $node) {
            if (in_array($node->localName, ['clTRID','extension'], true)) continue;
            $cmd = $node->localName;
            $op = $node->attributes?->getNamedItem('op')?->nodeValue;
            break;
        }
        if ($cmd === 'update') {
            $restore = $xp->query('//*[local-name()="restore" and namespace-uri()="urn:ietf:params:xml:ns:rgp-1.0"]')->item(0);
            if ($restore !== null) {
                $op = 'restore-' . ($restore->attributes?->getNamedItem('op')?->nodeValue ?? '');
            }
        }
        foreach ($xp->query('//*[local-name()="name" and (namespace-uri()="urn:ietf:params:xml:ns:domain-1.0" or namespace-uri()="urn:ietf:params:xml:ns:host-1.0")]') as $n) {
            $names[] = strtolower(trim($n->textContent, '.'));
        }
        return [$cmd, $op, array_unique($names)];
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($old);
    }
}

function rySrs(PDO $tx, string $table, string $start, string $end, array $tlds,
    array &$activity, array &$transactions): void
{
    $q = $tx->prepare("SELECT registrar_id, cmd, obj_type, obj_id, clTRIDframe AS frame_xml, code
        FROM $table WHERE svdate >= :start AND svdate < :end AND code IS NOT NULL");
    $q->execute(['start' => $start, 'end' => $end]);
    foreach ($q as $v) {
        [$cmd, $op, $names] = ryParseFrame($v['frame_xml']);
        $cmd ??= $v['cmd'];
        $object = $v['obj_type'];
        // Failed requests may not have an obj_type in the log. Determine it from the XML.
        if (!in_array($object, ['domain','host','contact'], true)) {
            if (str_contains((string)$v['frame_xml'], 'urn:ietf:params:xml:ns:domain-1.0')) $object = 'domain';
            elseif (str_contains((string)$v['frame_xml'], 'urn:ietf:params:xml:ns:host-1.0')) $object = 'host';
            elseif (str_contains((string)$v['frame_xml'], 'urn:ietf:params:xml:ns:contact-1.0')) $object = 'contact';
        }
        $prefix = match ($object) {'domain' => 'srs-dom-', 'host' => 'srs-host-',
            'contact' => 'srs-cont-', default => null};
        if ($prefix === null || !in_array($cmd, ['check','info','create','delete','renew','update','transfer'], true)) continue;
        $suffix = $cmd;
        if ($cmd === 'transfer') $suffix = 'transfer-' . $op;
        if ($cmd === 'update' && str_starts_with((string)$op, 'restore-')) {
            $suffix = 'rgp-' . $op;
        }
        $key = $prefix . $suffix;
        if (!in_array($key, RY_ACTIVITY, true)) continue;
        $assign = [];
        if ($object === 'domain') {
            foreach ($names ?: [(string)$v['obj_id']] as $name) {
                foreach ($tlds as $tld => $id) {
                    if (str_ends_with($name, '.' . $tld)) $assign[$tld] = true;
                }
            }
        } else {
            // Host and contact objects are registry-wide in Namingo: not tied to a zone.
            foreach ($tlds as $tld => $_id) $assign[$tld] = true;
        }
        foreach ($assign as $tld => $_) {
            $activity[$tld][$key]++;
            if ($object === 'domain' && $cmd === 'create') {
                // Spec 3: attempts include both successful and failed EPP create requests.
                ryCellIncrement($transactions[$tld], (int)$v['registrar_id'], 'attempted-adds');
            }
        }
    }
}

function ryCSV(string $file, array $columns, array $data): void
{
    $tmp = tempnam(dirname($file), '.report-');
    if ($tmp === false) throw new RuntimeException("Cannot create temporary report near $file");
    try {
        $h = fopen($tmp, 'wb');
        if ($h === false) throw new RuntimeException("Cannot open temporary report $tmp");
        try {
            if (fputcsv($h, $columns, ',', '"', '', "\r\n") === false) throw new RuntimeException('Header write failed');
            foreach ($data as $row) {
                if (array_keys($row) !== $columns) throw new LogicException('CSV field order mismatch');
                if (fputcsv($h, array_values($row), ',', '"', '', "\r\n") === false) throw new RuntimeException('CSV write failed');
            }
            if (!fflush($h)) throw new RuntimeException('CSV flush failed');
        } finally { fclose($h); }
        if (!rename($tmp, $file)) throw new RuntimeException("Cannot publish report $file");
    } finally { if (is_file($tmp)) unlink($tmp); }
}

function ryUpload(string $url, string $path, string $username, string $password): void
{
    $client = new Client(['auth' => [$username, $password], 'timeout' => 90,
        'connect_timeout' => 15, 'http_errors' => false]);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $h = fopen($path, 'rb');
            if ($h === false) throw new RuntimeException("Cannot read $path");
            try {
                $r = $client->put($url, ['body' => $h,
                    'headers' => ['Content-Type' => 'text/csv']]);
            } finally { fclose($h); }
            $status = $r->getStatusCode();
            if ($status >= 500 || $status === 429) throw new RuntimeException("ICANN HTTP $status");
            if ($status !== 200) throw new RuntimeException("ICANN rejected report (HTTP $status)");
            $body = (string)$r->getBody();
            $dom = new DOMDocument();
            if (!@$dom->loadXML($body, LIBXML_NONET)) {
                throw new RuntimeException('ICANN returned a non-XML acknowledgement');
            }
            $xp = new DOMXPath($dom);
            $code = $xp->query('//*[local-name()="result"]/@code')->item(0)?->nodeValue;
            if ($code !== '1000') {
                throw new RuntimeException('ICANN did not accept report: result=' . ($code ?? 'missing'));
            }
            return;
        } catch (GuzzleException | RuntimeException $e) {
            if ($attempt === 4 || !str_contains($e->getMessage(), 'HTTP 5') &&
                !str_contains($e->getMessage(), 'HTTP 429') && $e instanceof RuntimeException) {
                throw new RuntimeException('ICANN upload failed: ' . $e->getMessage(), 0, $e);
            }
            sleep(2 ** $attempt);
        }
    }
}

function ryMain(): int
{
    $c = require __DIR__ . '/config.php';
    $opt = getopt('', ['month:', 'upload', 'no-upload']);
    if (isset($opt['upload'], $opt['no-upload'])) throw new InvalidArgumentException('Use either --upload or --no-upload');
    $utc = new DateTimeZone('UTC');
    $now = new DateTimeImmutable('now', $utc);
    $month = $opt['month'] ?? $now->modify('first day of previous month')->format('Y-m');
    if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
        throw new InvalidArgumentException('--month must be YYYY-MM');
    }
    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $month . '-01 00:00:00', $utc);
    if (!$start || $start->format('Y-m') !== $month) throw new InvalidArgumentException('Invalid reporting month');
    $end = $start->modify('+1 month');
    if ($end > $now) throw new InvalidArgumentException('Cannot report an incomplete month');
    $startText = $start->format('Y-m-d H:i:s');
    $endText = $end->format('Y-m-d H:i:s');
    $period = $start->format('Ym');
    $base = rtrim((string)$c['reporting_path'], '/');
    if (!is_dir($base) && !mkdir($base, 0750, true) && !is_dir($base)) {
        throw new RuntimeException("Cannot create $base");
    }
    $db = ryPdo($c);
    ryVerifyBaseline($db, $start);
    $tlds = ryTlds($db);
    if (!$tlds) throw new RuntimeException('No delegated top-level zones configured');
    $tx = $c['db_type'] === 'pgsql' ? ryPdo($c, 'registryTransaction') : $db;
    $transactionTable = $c['db_type'] === 'pgsql' ? 'transaction_identifier' : 'registryTransaction.transaction_identifier';
    $registrars = ryRegistrars($db);
    $service = [];
    foreach ($tlds as $tld => $_) $service[$tld] = ryMetrics($base, $tld, $month);
    if (count($tlds) > 1) {
        $global = array_values(array_filter(RY_ACTIVITY, fn (string $k): bool =>
            str_starts_with($k, 'srs-host-') || str_starts_with($k, 'srs-cont-')));
        foreach ($tlds as $tld => $_) foreach ($global as $key) {
            if (!array_key_exists($key, $service[$tld])) {
                throw new RuntimeException("Multi-TLD install requires zone-specific '$key' in $tld service metrics");
            }
        }
    }
    $activity = [];
    $transaction = [];
    foreach ($tlds as $tld => $tldId) {
        $activity[$tld] = ryBlankActivity();
        $activity[$tld]['operational-registrars'] = count($registrars);
        foreach ($service[$tld] as $key => $value) {
            if (array_key_exists($key, $activity[$tld])) $activity[$tld][$key] = $value;
        }
        $transaction[$tld] = $registrars;
        $inventory = ryDomainState($db, $endText, $tldId);
        foreach ($inventory as $v) ryCellIncrement($transaction[$tld], (int)$v['clid'], 'total-domains');
        ryHostInventory($db, $endText, $inventory, $transaction[$tld]);
        ryLedger($db, $startText, $endText, $tldId, $tld, $transaction[$tld], $c);
        ryEvents($db, $startText, $endText, $tld, $tldId, $transaction[$tld], $c);
    }
    rySrs($tx, $transactionTable, $startText, $endText, $tlds, $activity, $transaction);
    if (count($tlds) > 1) {
        foreach ($tlds as $tld => $_) foreach ($service[$tld] as $key => $value) {
            if (str_starts_with($key, 'srs-host-') || str_starts_with($key, 'srs-cont-')) {
                $activity[$tld][$key] = $value;
            }
        }
    }
    foreach ($tlds as $tld => $tldId) {
        $totals = ryBlankTransaction('Totals', '');
        foreach ($transaction[$tld] as $row) {
            foreach (array_slice(RY_TRANSACTION, 2) as $field) $totals[$field] += (int)$row[$field];
        }
        $activityFile = "$base/$tld-activity-$period.csv";
        $transactionFile = "$base/$tld-transactions-$period.csv";
        ryCSV($activityFile, RY_ACTIVITY, [$activity[$tld]]);
        ryCSV($transactionFile, RY_TRANSACTION, [...array_values($transaction[$tld]), $totals]);
        echo "Written $activityFile and $transactionFile\n";
        if (isset($opt['upload']) || (!isset($opt['no-upload']) && !empty($c['reporting_upload']))) {
            if (!$c['reporting_username'] || !$c['reporting_password']) {
                throw new RuntimeException('Configure reporting_username and reporting_password');
            }
            foreach (['registry-functions-activity' => $activityFile, 'registrar-transactions' => $transactionFile] as $kind => $file) {
                ryUpload("https://ry-api.icann.org/report/$kind/$tld/$month", $file,
                    $c['reporting_username'], $c['reporting_password']);
                echo "ICANN accepted $kind for $tld/$month\n";
            }
        }
    }
    return 0;
}

if (defined('RY_REPORTING_TEST')) return;

try {
    exit(ryMain());
} catch (Throwable $e) {
    fwrite(STDERR, 'ICANN reporting failed: ' . $e->getMessage() . "\n");
    exit(1);
}
