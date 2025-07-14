<?php

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

            // Get NS hosts
            $stmt = $pdo->prepare("SELECT host_id FROM domain_host_map WHERE domain_id = ?");
            $stmt->execute([$domain['id']]);
            $host_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($host_ids)) {
                $pool->put($pdo);
                return;
            }

            $host_stmt = $pdo->prepare("SELECT name FROM host WHERE id = ?");
            foreach ($host_ids as $host_id) {
                $host_stmt->execute([$host_id]);
                $ns = $host_stmt->fetchColumn();
                if (!$ns) continue;

                // Prefer CDS
                $cds = digRecords($domain['name'], $ns, 'CDS', $failedDomains);
                if (!empty($cds) && validateCDS($cds)) {
                    try {
                        $log->info("Valid CDS for {$domain['name']} via $ns: " . json_encode($cds, JSON_THROW_ON_ERROR));
                    } catch (Throwable $e) {
                        $log->warning("CDS found for {$domain['name']} via $ns but failed to log JSON: " . $e->getMessage());
                    }
                    foreach ($cds as $rr) {
                        insertSecdnsDS($pdo, $domain['id'], $rr);
                    }
                    break;
                }

                // Fallback to CDNSKEY + generate DS
                $cdnskey = digRecords($domain['name'], $ns, 'CDNSKEY', $failedDomains);
                if (!empty($cdnskey)) {
                    $valid = array_filter($cdnskey, fn($k) => validateCDNSKEY($k));
                    foreach ($valid as $k) {
                        $ds = generateDSFromDNSKEY($domain['name'], $k);
                        if ($ds) insertSecdnsDS($pdo, $domain['id'], $ds);
                    }
                    break;
                }
            }

            $pool->put($pdo);
        });
    }

    $wg->wait();
    if (!empty($failedDomains)) {
        $log->info('--- DIG ERRORS SUMMARY ---');
        foreach ($failedDomains as $line) {
            $log->info($line);
        }
    }
    $log->info('job finished successfully.');
});

// Run dig and parse
function digRecords(string $domain, string $ns, string $type, &$failedDomains): array {
    static $logged = [];

    $cmd = "dig @$ns +short $domain $type 2>&1";
    $output = shell_exec($cmd);

    if (!is_string($output)) return [];

    $output = trim($output);
    if ($output === '') return [];

    $key = "$domain|$type";
    $err = null;

    if (stripos($output, "couldn't get address") !== false) {
        $err = "NS unreachable";
    } elseif (preg_match('/(connection timed out|SERVFAIL|refused|not found)/i', $output)) {
        $err = "DNS error";
    }

    if ($err !== null && !isset($logged[$key])) {
        $logged[$key] = true;
        $failedDomains[] = "$domain ($type) via $ns - $err";
    }

    if ($err !== null) return [];

    $results = [];

    foreach (explode("\n", $output) as $line) {
        if (empty($line)) continue;
        $parts = preg_split('/\s+/', trim($line));

        if ($type === 'CDS' && count($parts) >= 4) {
            $results[] = [
                'keytag' => (int)($parts[0] ?? 0),
                'alg' => (int)($parts[1] ?? 0),
                'digesttype' => (int)($parts[2] ?? 0),
                'digest' => $parts[3] ?? '',
            ];
        } elseif ($type === 'CDNSKEY' && count($parts) >= 4) {
            $results[] = [
                'flags' => (int)($parts[0] ?? 0),
                'protocol' => (int)($parts[1] ?? 0),
                'alg' => (int)($parts[2] ?? 0),
                'pubkey' => implode('', array_slice($parts, 3)),
            ];
        }
    }

    return $results;
}

function validateCDS(array $rr): bool {
    return isset($rr['alg'], $rr['digesttype'], $rr['digest']) &&
           $rr['alg'] >= 1 &&
           $rr['digesttype'] >= 1 &&
           strlen($rr['digest']) > 20;
}

function validateCDNSKEY(array $rr): bool {
    return isset($rr['protocol'], $rr['alg'], $rr['pubkey']) &&
           $rr['protocol'] === 3 &&
           $rr['alg'] >= 1 &&
           !empty($rr['pubkey']);
}

function generateDSFromDNSKEY(string $domain, array $rr): ?array {
    if (!isset($rr['flags'], $rr['protocol'], $rr['alg'], $rr['pubkey'])) {
        return null;
    }

    try {
        $rdata = pack('nCC', $rr['flags'], $rr['protocol'], $rr['alg']) . base64_decode($rr['pubkey']);
        $keytag = keyTag($rdata);

        $digest_sha256 = strtolower(hash('sha256', canonicalDNSName($domain) . $rdata));

        return [
            'keytag' => $keytag,
            'alg' => $rr['alg'],
            'digesttype' => 2,
            'digest' => $digest_sha256
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

function insertSecdnsDS(Swoole\Database\PDOProxy $pdo, int $domain_id, array $r) {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO secdns
        (domain_id, interface, keytag, alg, digesttype, digest)
        VALUES (?, 'dsData', ?, ?, ?, ?)
    ");
    $stmt->execute([
        $domain_id,
        $r['keytag'],
        $r['alg'],
        $r['digesttype'],
        $r['digest'],
    ]);
}