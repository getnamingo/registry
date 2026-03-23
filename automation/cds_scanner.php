<?php
/**
 * Namingo Registry DNSSEC CDS/CDNSKEY scanner
 *
 * This job implements parent-side DNSSEC DS automation for child zones,
 * following the general behavior described in:
 * - RFC 7344 (CDS/CDNSKEY child-to-parent signaling)
 * - RFC 9615 (DNSSEC bootstrapping via _dsboot signaling)
 * - current DNSOP operational recommendations for DS automation
 *
 * Install delv on Ubuntu / Debian:
 *   sudo apt-get update
 *   sudo apt-get install -y bind9-dnsutils
 *
 * Operational caution:
 * This is an automated parent-side DNSSEC process. Test carefully in a
 * staging environment before enabling it broadly in production.
 */

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;

require __DIR__ . '/vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/cds_scanner.log';
$log = setupLogger($logFilePath, 'CDS_Scanner');
$log->info('job started.');

$pool = new Swoole\Database\PDOPool(
    (new Swoole\Database\PDOConfig())
        ->withDriver($c['db_type'])
        ->withHost($c['db_host'])
        ->withPort($c['db_port'])
        ->withDbName($c['db_database'])
        ->withUsername($c['db_username'])
        ->withPassword($c['db_password'])
        ->withCharset('utf8mb4')
);

Co\run(function () use ($pool, $log) {
    $concurrency = 20;
    $chan = new Channel($concurrency);
    $wg = new WaitGroup();
    $failedDomains = [];

    $pdo = $pool->get();
    ensureDnssecAutomationStateTable($pdo);
    ensureDelvAvailable();
    ensureDnssecAutomationSeenTable($pdo);
    $stmt = $pdo->query("SELECT id, name FROM domain");
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pool->put($pdo);

    foreach ($domains as $domain) {
        $chan->push(true);
        $wg->add();

        go(function () use ($domain, $pool, $chan, $wg, $log, &$failedDomains) {
            defer(function () use ($chan, $wg) {
                $chan->pop();
                $wg->done();
            });

            $pdo = $pool->get();

            try {
                if (!isAutomationEnabled($pdo, (int)$domain['id'])) {
                    $log->info("DS automation disabled for {$domain['name']}");
                    $pool->put($pdo);
                    return;
                }

                // Get NS hosts
                $stmt = $pdo->prepare("SELECT host_id FROM domain_host_map WHERE domain_id = ?");
                $stmt->execute([$domain['id']]);
                $host_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (empty($host_ids)) {
                    $pool->put($pdo);
                    return;
                }

                $host_stmt = $pdo->prepare("SELECT name FROM host WHERE id = ?");

                $nsList = [];
                foreach ($host_ids as $host_id) {
                    $host_stmt->execute([$host_id]);
                    $ns = $host_stmt->fetchColumn();
                    if ($ns) {
                        $nsList[] = $ns;
                    }
                }

                if (empty($nsList)) {
                    $pool->put($pdo);
                    return;
                }

                $currentDs = getCurrentSecdnsDS($pdo, (int)$domain['id']);
                $hasCurrentDs = !empty($currentDs);

                // RFC 9615 dsboot path for unsigned delegations
                if (!$hasCurrentDs) {
                    $boot = checkDsBootSignal($domain['name'], $nsList, $failedDomains);
                    if ($boot['valid'] === true) {
                        if (empty($boot['ds'])) {
                            markSignalError($pdo, (int)$domain['id'], 'dsboot', 'Validated dsboot data produced empty DS set; no bootstrapping action taken');
                            $log->warning("Validated dsboot for {$domain['name']} was empty; no action taken");
                            $pool->put($pdo);
                            return;
                        }

                        $decisionHash = hashSignalSet($boot['ds']);

                        if (!isNewerSignalSet($pdo, (int)$domain['id'], 'dsboot', $decisionHash, null, null)) {
                            markSignalError($pdo, (int)$domain['id'], 'dsboot', 'Older dsboot signal set ignored');
                            $log->warning("Older dsboot signal set ignored for {$domain['name']}");
                            $pool->put($pdo);
                            return;
                        }

                        if (shouldAcceptSignalSet($pdo, (int)$domain['id'], $decisionHash, 'dsboot')) {
                            syncSecdnsDS($pdo, (int)$domain['id'], $currentDs, $boot['ds']);
                            markSignalAccepted($pdo, (int)$domain['id'], $decisionHash, 'dsboot', null);
                            markSignalSeen($pdo, (int)$domain['id'], 'dsboot', $decisionHash, null, null);
                            $log->info("Accepted DS bootstrapping for {$domain['name']}");
                        } else {
                            markSignalPending($pdo, (int)$domain['id'], $decisionHash, 'dsboot');
                            $log->info("DS bootstrapping pending second confirmation for {$domain['name']}");
                        }

                        $pool->put($pdo);
                        return;
                    }
                }

                $observedSets = [];

                foreach ($nsList as $ns) {
                    $signal = getDsSignalSet($domain['name'], $ns, $failedDomains);

                    if (!$signal['valid']) {
                        continue;
                    }

                    $normalized = normalizeDsSet($signal['ds']);
                    $observedSets[] = [
                        'ns' => $ns,
                        'ds' => $normalized,
                        'hash' => hashSignalSet($normalized),
                        'source' => $signal['source'],
                    ];
                }

                if (empty($observedSets)) {
                    markSignalError($pdo, (int)$domain['id'], 'cds', 'No valid CDS/CDNSKEY signal set found from any NS');
                    $log->warning("No valid CDS/CDNSKEY signal for {$domain['name']}");
                    $pool->put($pdo);
                    return;
                }

                $quorum = chooseQuorumSignalSet($observedSets, count($nsList));

                if ($quorum === null) {
                    markSignalError($pdo, (int)$domain['id'], 'cds', 'NS responses differ, no quorum');
                    $log->warning("No quorum across NS for {$domain['name']}");
                    $pool->put($pdo);
                    return;
                }

                $decisionHash = $quorum['hash'];

                if (!isNewerSignalSet($pdo, (int)$domain['id'], $quorum['source'], $decisionHash, null, null)) {
                    markSignalError($pdo, (int)$domain['id'], $quorum['source'], 'Older signal set ignored');
                    $log->warning("Older signal set ignored for {$domain['name']}");
                    $pool->put($pdo);
                    return;
                }

                if (!empty($currentDs) && !empty($quorum['ds']) && !passesContinuityRule($currentDs, $quorum['ds'])) {
                    markSignalError($pdo, (int)$domain['id'], $quorum['source'], 'Continuity rule failed');
                    $log->warning("Continuity rule failed for {$domain['name']}");
                    $pool->put($pdo);
                    return;
                }

                if (shouldAcceptSignalSet($pdo, (int)$domain['id'], $decisionHash, $quorum['source'])) {
                    syncSecdnsDS($pdo, (int)$domain['id'], $currentDs, $quorum['ds']);
                    markSignalAccepted($pdo, (int)$domain['id'], $decisionHash, $quorum['source'], null);
                    markSignalSeen($pdo, (int)$domain['id'], $quorum['source'], $decisionHash, null, null);
                    $log->info("Accepted {$quorum['source']} signal for {$domain['name']}");
                } else {
                    markSignalPending($pdo, (int)$domain['id'], $decisionHash, $quorum['source']);
                    $log->info("Signal pending second confirmation for {$domain['name']}");
                }
            } catch (Throwable $e) {
                $log->error("Domain {$domain['name']} failed: " . $e->getMessage());
            }

            $pool->put($pdo);
        });
    }

    $wg->wait();
    if (!empty($failedDomains)) {
        $log->info('--- DELV ERRORS SUMMARY ---');
        foreach ($failedDomains as $line) {
            $log->info($line);
        }
    }
    $log->info('job finished successfully.');
});

function validateCDS(array $rr): bool {
    return isset($rr['keytag'], $rr['alg'], $rr['digesttype'], $rr['digest']) &&
           $rr['keytag'] >= 0 &&
           $rr['alg'] >= 1 &&
           $rr['digesttype'] >= 1 &&
           strlen($rr['digest']) > 20;
}

function validateCDNSKEY(array $rr): bool {
    return isset($rr['flags'], $rr['protocol'], $rr['alg'], $rr['pubkey']) &&
           $rr['protocol'] === 3 &&
           $rr['alg'] >= 1 &&
           in_array((int)$rr['flags'], [256, 257], true) &&
           !empty($rr['pubkey']);
}

function generateDSFromDNSKEY(string $domain, array $rr): ?array {
    if (!isset($rr['flags'], $rr['protocol'], $rr['alg'], $rr['pubkey'])) {
        return null;
    }

    try {
        $pubkey = base64_decode($rr['pubkey'], true);
        if ($pubkey === false) {
            return null;
        }

        $rdata = pack('nCC', (int)$rr['flags'], (int)$rr['protocol'], (int)$rr['alg']) . $pubkey;
        $keytag = keyTag($rdata);
        $digest = strtolower(hash('sha256', canonicalDNSName($domain) . $rdata));

        return [
            'keytag' => $keytag,
            'alg' => (int)$rr['alg'],
            'digesttype' => 2,
            'digest' => $digest,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function canonicalDNSName(string $name): string {
    $labels = explode('.', strtolower($name));
    $out = '';
    foreach ($labels as $label) {
        $len = strlen($label);
        $out .= chr($len) . $label;
    }
    return $out . chr(0);
}

function keyTag(string $rdata): int {
    $ac = 0;
    $len = strlen($rdata);
    for ($i = 0; $i < $len; $i++) {
        $ac += ($i & 1) ? ord($rdata[$i]) : ord($rdata[$i]) << 8;
    }
    $ac += ($ac >> 16) & 0xFFFF;
    return $ac & 0xFFFF;
}

function insertSecdnsDS(Swoole\Database\PDOProxy $pdo, int $domain_id, array $r): void {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO secdns
        (domain_id, interface, keytag, alg, digesttype, digest)
        VALUES (?, 'dsData', ?, ?, ?, ?)
    ");
    $stmt->execute([
        $domain_id,
        (int)$r['keytag'],
        (int)$r['alg'],
        (int)$r['digesttype'],
        strtolower(trim((string)$r['digest'])),
    ]);
}

function getDsSignalSet(string $domain, string $ns, array &$failedDomains): array
{
    $defaultMode = 'cds'; // or 'cdnskey'

    $cdsSet = queryAuthoritativeBootstrapRrset($domain, $ns, 'CDS', $failedDomains);
    $cdnskeySet = queryAuthoritativeBootstrapRrset($domain, $ns, 'CDNSKEY', $failedDomains);

    $cdsValid = false;
    $cdsDs = [];
    $cdnskeyValid = false;
    $cdnskeyDs = [];

    if ($cdsSet['ok']) {
        $deleteSignals = array_values(array_filter($cdsSet['records'], 'isCdsDeleteSignal'));
        if (!empty($deleteSignals)) {
            $cdsValid = true;
            $cdsDs = [];
        } else {
            $validCds = array_values(array_filter($cdsSet['records'], 'validateCDS'));
            if (!empty($validCds)) {
                $cdsValid = true;
                $cdsDs = normalizeDsSet($validCds);
            }
        }
    }

    if ($cdnskeySet['ok']) {
        $validKeys = array_values(array_filter($cdnskeySet['records'], 'validateCDNSKEY'));

        $tmpDs = [];
        foreach ($validKeys as $k) {
            $generated = generateDSFromDNSKEY($domain, $k);
            if ($generated !== null) {
                $tmpDs[] = $generated;
            }
        }

        $tmpDs = normalizeDsSet($tmpDs);

        if (!empty($validKeys)) {
            $cdnskeyValid = true;
            $cdnskeyDs = $tmpDs;
        }
    }

    if ($defaultMode === 'cds') {
        if ($cdsValid) {
            return [
                'valid' => true,
                'source' => 'cds',
                'ds' => $cdsDs,
            ];
        }

        if ($cdnskeyValid) {
            return [
                'valid' => true,
                'source' => 'cdnskey',
                'ds' => $cdnskeyDs,
            ];
        }
    } else {
        if ($cdnskeyValid) {
            return [
                'valid' => true,
                'source' => 'cdnskey',
                'ds' => $cdnskeyDs,
            ];
        }

        if ($cdsValid) {
            return [
                'valid' => true,
                'source' => 'cds',
                'ds' => $cdsDs,
            ];
        }
    }

    return [
        'valid' => false,
        'source' => null,
        'ds' => [],
    ];
}

function normalizeDsSet(array $records): array {
    $out = [];

    foreach ($records as $r) {
        if (
            !isset($r['keytag'], $r['alg'], $r['digesttype'], $r['digest'])
        ) {
            continue;
        }

        $key = implode(':', [
            (int)$r['keytag'],
            (int)$r['alg'],
            (int)$r['digesttype'],
            strtolower(trim((string)$r['digest']))
        ]);

        $out[$key] = [
            'keytag' => (int)$r['keytag'],
            'alg' => (int)$r['alg'],
            'digesttype' => (int)$r['digesttype'],
            'digest' => strtolower(trim((string)$r['digest'])),
        ];
    }

    ksort($out);

    return array_values($out);
}

function hashSignalSet(array $records): string {
    return hash('sha256', json_encode(normalizeDsSet($records), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function chooseQuorumSignalSet(array $observedSets, int $totalNsCount): ?array {
    $counts = [];

    foreach ($observedSets as $set) {
        $hash = $set['hash'];
        if (!isset($counts[$hash])) {
            $counts[$hash] = [
                'count' => 0,
                'sample' => $set,
            ];
        }
        $counts[$hash]['count']++;
    }

    uasort($counts, fn($a, $b) => $b['count'] <=> $a['count']);

    $top = reset($counts);
    if (!$top) {
        return null;
    }

    if ($totalNsCount === 1) {
        return $top['sample'];
    }

    return $top['count'] >= 2 ? $top['sample'] : null;
}

function getCurrentSecdnsDS(Swoole\Database\PDOProxy $pdo, int $domain_id): array {
    $stmt = $pdo->prepare("
        SELECT keytag, alg, digesttype, digest
        FROM secdns
        WHERE domain_id = ?
          AND interface = 'dsData'
    ");
    $stmt->execute([$domain_id]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return normalizeDsSet($rows);
}

function syncSecdnsDS(Swoole\Database\PDOProxy $pdo, int $domain_id, array $current, array $desired): void {
    $currentMap = [];
    foreach (normalizeDsSet($current) as $r) {
        $key = implode(':', [$r['keytag'], $r['alg'], $r['digesttype'], $r['digest']]);
        $currentMap[$key] = $r;
    }

    $desiredMap = [];
    foreach (normalizeDsSet($desired) as $r) {
        $key = implode(':', [$r['keytag'], $r['alg'], $r['digesttype'], $r['digest']]);
        $desiredMap[$key] = $r;
    }

    foreach ($desiredMap as $key => $r) {
        if (!isset($currentMap[$key])) {
            insertSecdnsDS($pdo, $domain_id, $r);
        }
    }

    foreach ($currentMap as $key => $r) {
        if (!isset($desiredMap[$key])) {
            deleteSecdnsDS($pdo, $domain_id, $r);
        }
    }
}

function deleteSecdnsDS(Swoole\Database\PDOProxy $pdo, int $domain_id, array $r): void {
    $stmt = $pdo->prepare("
        DELETE FROM secdns
        WHERE domain_id = ?
          AND interface = 'dsData'
          AND keytag = ?
          AND alg = ?
          AND digesttype = ?
          AND digest = ?
    ");
    $stmt->execute([
        $domain_id,
        $r['keytag'],
        $r['alg'],
        $r['digesttype'],
        $r['digest'],
    ]);
}

function ensureDnssecAutomationStateTable(Swoole\Database\PDOProxy $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dnssec_automation_state (
            domain_id BIGINT PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            mode VARCHAR(20) NOT NULL DEFAULT 'cds',
            last_signal_hash VARCHAR(64) DEFAULT NULL,
            first_seen_at DATETIME DEFAULT NULL,
            last_seen_at DATETIME DEFAULT NULL,
            accepted_at DATETIME DEFAULT NULL,
            last_status VARCHAR(50) DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
}

function ensureDnssecAutomationStateRow(Swoole\Database\PDOProxy $pdo, int $domain_id): void {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO dnssec_automation_state (domain_id)
        VALUES (?)
    ");
    $stmt->execute([$domain_id]);
}

function isAutomationEnabled(Swoole\Database\PDOProxy $pdo, int $domain_id): bool {
    ensureDnssecAutomationStateRow($pdo, $domain_id);

    $stmt = $pdo->prepare("
        SELECT enabled
        FROM dnssec_automation_state
        WHERE domain_id = ?
        LIMIT 1
    ");
    $stmt->execute([$domain_id]);
    $value = $stmt->fetchColumn();

    return (int)$value === 1;
}

function shouldAcceptSignalSet(Swoole\Database\PDOProxy $pdo, int $domain_id, string $signalHash, string $mode): bool {
    ensureDnssecAutomationStateRow($pdo, $domain_id);

    $stmt = $pdo->prepare("
        SELECT last_signal_hash, last_status
        FROM dnssec_automation_state
        WHERE domain_id = ?
        LIMIT 1
    ");
    $stmt->execute([$domain_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    return $row['last_signal_hash'] === $signalHash && $row['last_status'] === 'pending';
}

function markSignalPending(Swoole\Database\PDOProxy $pdo, int $domain_id, string $signalHash, string $mode): void {
    ensureDnssecAutomationStateRow($pdo, $domain_id);

    $stmt = $pdo->prepare("
        UPDATE dnssec_automation_state
        SET mode = ?,
            first_seen_at = CASE
                WHEN last_signal_hash IS NULL OR last_signal_hash <> ? THEN NOW()
                ELSE first_seen_at
            END,
            last_signal_hash = ?,
            last_seen_at = NOW(),
            accepted_at = NULL,
            last_status = 'pending',
            last_error = NULL
        WHERE domain_id = ?
    ");
    $stmt->execute([$mode, $signalHash, $signalHash, $domain_id]);
}

function markSignalAccepted(Swoole\Database\PDOProxy $pdo, int $domain_id, string $signalHash, string $mode, ?string $error): void {
    ensureDnssecAutomationStateRow($pdo, $domain_id);

    $stmt = $pdo->prepare("
        UPDATE dnssec_automation_state
        SET mode = ?,
            last_signal_hash = ?,
            first_seen_at = COALESCE(first_seen_at, NOW()),
            last_seen_at = NOW(),
            accepted_at = NOW(),
            last_status = 'accepted',
            last_error = ?
        WHERE domain_id = ?
    ");
    $stmt->execute([$mode, $signalHash, $error, $domain_id]);
}

function markSignalError(Swoole\Database\PDOProxy $pdo, int $domain_id, string $mode, string $error): void {
    ensureDnssecAutomationStateRow($pdo, $domain_id);

    $stmt = $pdo->prepare("
        UPDATE dnssec_automation_state
        SET mode = ?,
            last_seen_at = NOW(),
            last_status = 'error',
            last_error = ?
        WHERE domain_id = ?
    ");
    $stmt->execute([$mode, $error, $domain_id]);
}

function checkDsBootSignal(string $domain, array $nsList, array &$failedDomains): array
{
    $externalNs = array_values(array_filter(
        array_unique($nsList),
        fn(string $ns): bool => !isSubdomainOrEqual($ns, $domain)
    ));

    // RFC 9615 requires at least one out-of-domain NS
    if (empty($externalNs)) {
        return [
            'valid' => false,
            'ds' => [],
        ];
    }

    $apexCdsSets = [];
    $apexCdnskeySets = [];

    // Step 2: query child apex directly from each authoritative NS, without caching
    foreach ($nsList as $ns) {
        $apexCds = queryAuthoritativeBootstrapRrset($domain, $ns, 'CDS', $failedDomains);
        if (!$apexCds['ok']) {
            return [
                'valid' => false,
                'ds' => [],
            ];
        }

        $normalizedCds = normalizeDsSet(array_values(array_filter($apexCds['records'], function (array $rr): bool {
            return isCdsDeleteSignal($rr) || validateCDS($rr);
        })));
        $apexCdsSets[] = [
            'hash' => hashSignalSet($normalizedCds),
            'records' => $normalizedCds,
        ];

        $apexCdnskey = queryAuthoritativeBootstrapRrset($domain, $ns, 'CDNSKEY', $failedDomains);
        if (!$apexCdnskey['ok']) {
            return [
                'valid' => false,
                'ds' => [],
            ];
        }

        $normalizedCdnskey = normalizeDnskeySet(array_values(array_filter($apexCdnskey['records'], function (array $rr): bool {
            return validateCDNSKEY($rr) && (int)$rr['flags'] === 257;
        })));
        $apexCdnskeySets[] = [
            'hash' => hashDnskeySet($normalizedCdnskey),
            'records' => $normalizedCdnskey,
        ];
    }

    if (!allRecordSetHashesEqual($apexCdsSets) || !allRecordSetHashesEqual($apexCdnskeySets)) {
        return [
            'valid' => false,
            'ds' => [],
        ];
    }

    $apexCds = $apexCdsSets[0]['records'] ?? [];
    $apexCdnskey = $apexCdnskeySets[0]['records'] ?? [];

    // Step 3: query signaling names via validating resolver, enforce validation
    $signalCdsSets = [];
    $signalCdnskeySets = [];

    foreach ($externalNs as $ns) {
        $signalName = buildDsBootSignalName($domain, $ns);

        $sigCds = delvValidatedRrset($signalName, null, 'CDS', $failedDomains);
        if (!$sigCds['ok']) {
            return [
                'valid' => false,
                'ds' => [],
            ];
        }

        $normalizedSigCds = normalizeDsSet(array_values(array_filter($sigCds['records'], function (array $rr): bool {
            return isCdsDeleteSignal($rr) || validateCDS($rr);
        })));
        $signalCdsSets[] = [
            'hash' => hashSignalSet($normalizedSigCds),
            'records' => $normalizedSigCds,
        ];

        $sigCdnskey = delvValidatedRrset($signalName, null, 'CDNSKEY', $failedDomains);
        if (!$sigCdnskey['ok']) {
            return [
                'valid' => false,
                'ds' => [],
            ];
        }

        $normalizedSigCdnskey = normalizeDnskeySet(array_values(array_filter($sigCdnskey['records'], function (array $rr): bool {
            return validateCDNSKEY($rr) && (int)$rr['flags'] === 257;
        })));
        $signalCdnskeySets[] = [
            'hash' => hashDnskeySet($normalizedSigCdnskey),
            'records' => $normalizedSigCdnskey,
        ];
    }

    if (!allRecordSetHashesEqual($signalCdsSets) || !allRecordSetHashesEqual($signalCdnskeySets)) {
        return [
            'valid' => false,
            'ds' => [],
        ];
    }

    $signalCds = $signalCdsSets[0]['records'] ?? [];
    $signalCdnskey = $signalCdnskeySets[0]['records'] ?? [];

    // Step 4: apex and signaling RRsets must match, separately by record type
    if (hashSignalSet($apexCds) !== hashSignalSet($signalCds)) {
        return [
            'valid' => false,
            'ds' => [],
        ];
    }

    if (hashDnskeySet($apexCdnskey) !== hashDnskeySet($signalCdnskey)) {
        return [
            'valid' => false,
            'ds' => [],
        ];
    }

    // Prefer CDS if present, including delete signaling
    if (!empty($apexCds)) {
        $deleteSignals = array_values(array_filter($apexCds, 'isCdsDeleteSignal'));
        if (!empty($deleteSignals)) {
            return [
                'valid' => true,
                'ds' => [],
            ];
        }

        return [
            'valid' => true,
            'ds' => $apexCds,
        ];
    }

    // Fallback to CDNSKEY-derived DS
    if (!empty($apexCdnskey)) {
        $ds = [];

        foreach ($apexCdnskey as $k) {
            $generated = generateDSFromDNSKEY($domain, $k);
            if ($generated !== null) {
                $ds[] = $generated;
            }
        }

        $ds = normalizeDsSet($ds);

        if (!empty($ds)) {
            return [
                'valid' => true,
                'ds' => $ds,
            ];
        }
    }

    return [
        'valid' => false,
        'ds' => [],
    ];
}

function parseDigAnswerLine(string $line): ?array {
    $parts = preg_split('/\s+/', $line);
    if (!$parts || count($parts) < 5) {
        return null;
    }

    $classIndex = null;
    foreach ($parts as $i => $part) {
        if (in_array(strtoupper($part), ['IN', 'CH', 'HS'], true)) {
            $classIndex = $i;
            break;
        }
    }

    if ($classIndex === null || !isset($parts[$classIndex + 1])) {
        return null;
    }

    $type = strtoupper($parts[$classIndex + 1]);
    $rdata = array_slice($parts, $classIndex + 2);

    if ($type === 'CDS' && count($rdata) >= 4) {
        return [
            'type' => 'CDS',
            'data' => [
                'keytag' => (int)$rdata[0],
                'alg' => (int)$rdata[1],
                'digesttype' => (int)$rdata[2],
                'digest' => strtolower(implode('', array_slice($rdata, 3))),
            ],
        ];
    }

    if (($type === 'DNSKEY' || $type === 'CDNSKEY') && count($rdata) >= 4) {
        return [
            'type' => $type,
            'data' => [
                'flags' => (int)$rdata[0],
                'protocol' => (int)$rdata[1],
                'alg' => (int)$rdata[2],
                'pubkey' => implode('', array_slice($rdata, 3)),
            ],
        ];
    }

    if ($type === 'RRSIG' && count($rdata) >= 1) {
        return [
            'type' => 'RRSIG',
            'data' => [
                'type_covered' => strtoupper($rdata[0]),
            ],
        ];
    }

    return null;
}

function delvValidatedRrset(string $domain, ?string $ns, string $type, array &$failedDomains): array
{
    static $logged = [];

    $cmd = sprintf(
        'delv -4 %s %s 2>&1',
        escapeshellarg($domain),
        escapeshellarg($type)
    );

    $output = shell_exec($cmd);

    if (!is_string($output) || trim($output) === '') {
        $key = "$domain|$type|resolver";
        if (!isset($logged[$key])) {
            $logged[$key] = true;
            $failedDomains[] = "$domain ($type) via resolver - empty delv response";
        }

        return [
            'ok' => false,
            'validated' => false,
            'records' => [],
            'raw' => '',
        ];
    }

    $raw = trim($output);

    // Be conservative: reject on common failure words
    if (
        preg_match('/\b(fail|failed|broken trust chain|no valid signature|resolution failed|network unreachable|timed out|servfail|refused|no valid\s+rrsig|insecurity proof failed)\b/i', $raw)
    ) {
        $key = "$domain|$type|resolver";
        if (!isset($logged[$key])) {
            $logged[$key] = true;
            $failedDomains[] = "$domain ($type) via resolver - delv validation/query failure";
        }

        return [
            'ok' => false,
            'validated' => false,
            'records' => [],
            'raw' => $raw,
        ];
    }

    $records = [];
    $validated = false;

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if (str_starts_with($line, ';')) {
            // delv includes validation status in comment/output sections
            if (preg_match('/\bfully validated\b/i', $line) || preg_match('/\bsecure\b/i', $line)) {
                $validated = true;
            }
            continue;
        }

        $parsed = parseDigAnswerLine($line);
        if ($parsed !== null && ($parsed['type'] ?? '') === strtoupper($type)) {
            $records[] = $parsed['data'];
        }
    }

    // Fallback: if data exists and no explicit failure appeared, accept only if validation marker was seen
    return [
        'ok' => $validated,
        'validated' => $validated,
        'records' => $records,
        'raw' => $raw,
    ];
}

function ensureDelvAvailable(): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $path = trim((string)shell_exec('command -v delv 2>/dev/null'));
    if ($path === '') {
        throw new RuntimeException('delv not found on system');
    }

    $checked = true;
}

function isCdsDeleteSignal(array $rr): bool {
    return isset($rr['keytag'], $rr['alg'], $rr['digesttype'], $rr['digest']) &&
           (int)$rr['keytag'] === 0 &&
           (int)$rr['alg'] === 0 &&
           (int)$rr['digesttype'] === 0 &&
           strtolower(trim((string)$rr['digest'])) === '00';
}

function buildDsBootSignalName(string $domain, string $nsHost): string
{
    return '_dsboot.' . rtrim(strtolower($domain), '.') . '._signal.' . rtrim(strtolower($nsHost), '.');
}

function isSubdomainOrEqual(string $name, string $parent): bool
{
    $name = rtrim(strtolower($name), '.');
    $parent = rtrim(strtolower($parent), '.');

    return $name === $parent || str_ends_with($name, '.' . $parent);
}

function allRecordSetHashesEqual(array $sets): bool
{
    if (empty($sets)) {
        return false;
    }

    $first = $sets[0]['hash'] ?? null;
    if ($first === null) {
        return false;
    }

    foreach ($sets as $set) {
        if (($set['hash'] ?? null) !== $first) {
            return false;
        }
    }

    return true;
}

function normalizeDnskeySet(array $records): array
{
    $out = [];

    foreach ($records as $r) {
        if (!validateCDNSKEY($r)) {
            continue;
        }

        $key = dnskeyIdentityKey($r);
        $out[$key] = [
            'flags' => (int)$r['flags'],
            'protocol' => (int)$r['protocol'],
            'alg' => (int)$r['alg'],
            'pubkey' => trim((string)$r['pubkey']),
        ];
    }

    ksort($out);

    return array_values($out);
}

function dnskeyIdentityKey(array $r): string
{
    return implode(':', [
        (int)$r['flags'],
        (int)$r['protocol'],
        (int)$r['alg'],
        trim((string)$r['pubkey']),
    ]);
}

function hashDnskeySet(array $records): string
{
    return hash(
        'sha256',
        json_encode(normalizeDnskeySet($records), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

function queryAuthoritativeBootstrapRrset(string $domain, string $ns, string $type, array &$failedDomains): array
{
    static $logged = [];

    $cmd = sprintf(
        'dig -4 @%s %s %s +norecurse +noall +answer 2>&1',
        escapeshellarg($ns),
        escapeshellarg($domain),
        escapeshellarg($type)
    );

    $output = shell_exec($cmd);

    if (!is_string($output) || trim($output) === '') {
        $key = "boot:$domain|$type|$ns";
        if (!isset($logged[$key])) {
            $logged[$key] = true;
            $failedDomains[] = "$domain ($type) via $ns - empty apex response";
        }

        return [
            'ok' => false,
            'records' => [],
            'raw' => '',
        ];
    }

    $raw = trim($output);

    if (
        stripos($raw, "connection timed out") !== false ||
        stripos($raw, "couldn't get address") !== false ||
        stripos($raw, "SERVFAIL") !== false ||
        stripos($raw, "REFUSED") !== false
    ) {
        $key = "boot:$domain|$type|$ns";
        if (!isset($logged[$key])) {
            $logged[$key] = true;
            $failedDomains[] = "$domain ($type) via $ns - apex DNS error";
        }

        return [
            'ok' => false,
            'records' => [],
            'raw' => $raw,
        ];
    }

    $records = [];

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, ';')) {
            continue;
        }

        $parsed = parseDigAnswerLine($line);
        if ($parsed !== null && ($parsed['type'] ?? '') === strtoupper($type)) {
            $records[] = $parsed['data'];
        }
    }

    return [
        'ok' => true,
        'records' => $records,
        'raw' => $raw,
    ];
}

function ensureDnssecAutomationSeenTable(Swoole\Database\PDOProxy $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dnssec_automation_seen (
            domain_id BIGINT NOT NULL,
            source VARCHAR(20) NOT NULL,
            rrset_hash VARCHAR(64) NOT NULL,
            soa_serial BIGINT DEFAULT NULL,
            rrsig_inception BIGINT DEFAULT NULL,
            seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (domain_id, source)
        )
    ");
}

function isNewerSignalSet(Swoole\Database\PDOProxy $pdo, int $domainId, string $source, string $hash, ?int $soaSerial, ?int $rrsigInception): bool
{
    $stmt = $pdo->prepare("
        SELECT rrset_hash, soa_serial, rrsig_inception
        FROM dnssec_automation_seen
        WHERE domain_id = ? AND source = ?
        LIMIT 1
    ");
    $stmt->execute([$domainId, $source]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return true;
    }

    if ($row['rrset_hash'] === $hash) {
        return true;
    }

    if ($rrsigInception !== null && $row['rrsig_inception'] !== null) {
        return $rrsigInception >= (int)$row['rrsig_inception'];
    }

    if ($soaSerial !== null && $row['soa_serial'] !== null) {
        return $soaSerial >= (int)$row['soa_serial'];
    }

    return true;
}

function markSignalSeen(
    Swoole\Database\PDOProxy $pdo,
    int $domainId,
    string $source,
    string $hash,
    ?int $soaSerial,
    ?int $rrsigInception
): void {
    $stmt = $pdo->prepare("
        INSERT INTO dnssec_automation_seen (domain_id, source, rrset_hash, soa_serial, rrsig_inception, seen_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            rrset_hash = VALUES(rrset_hash),
            soa_serial = VALUES(soa_serial),
            rrsig_inception = VALUES(rrsig_inception),
            seen_at = NOW()
    ");
    $stmt->execute([$domainId, $source, $hash, $soaSerial, $rrsigInception]);
}

function passesContinuityRule(array $currentDs, array $desiredDs): bool
{
    if (empty($currentDs)) {
        return true;
    }

    if (empty($desiredDs)) {
        return false;
    }

    $currentMap = [];
    foreach (normalizeDsSet($currentDs) as $r) {
        $currentMap[implode(':', [$r['keytag'], $r['alg'], $r['digesttype'], $r['digest']])] = true;
    }

    foreach (normalizeDsSet($desiredDs) as $r) {
        $key = implode(':', [$r['keytag'], $r['alg'], $r['digesttype'], $r['digest']]);
        if (isset($currentMap[$key])) {
            return true;
        }
    }

    return false;
}