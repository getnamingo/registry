<?php

require_once 'vendor/autoload.php';

use Badcow\DNS\Zone;
use Badcow\DNS\Rdata\Factory;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\Classes;
use Badcow\DNS\ZoneBuilder;
use Badcow\DNS\AlignedBuilder;
use Swoole\Coroutine;

$c = require_once 'config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/write_zone.log';
$log = setupLogger($logFilePath, 'Zone_Generator');
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

Swoole\Runtime::enableCoroutine();
Coroutine::create(function () use ($pool, $log, $c) {
    try {
        $pdo = $pool->get();
        $sth = $pdo->prepare('SELECT id, tld FROM domain_tld');
        $sth->execute();

        $serial = generateSerial($c['dns_serial'] ?? 1);
        $dnsReload = filter_var($c['dns_reload'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $dnsServer = strtolower(trim((string) ($c['dns_server'] ?? '')));

        $tlds = [];
        while (list($id, $tld) = $sth->fetch(PDO::FETCH_NUM)) {
            $cleanedTld = ltrim(strtolower($tld), '.');
            $zone = new Zone($cleanedTld.'.');
            $zone->setDefaultTtl(3600);

            $soa = new ResourceRecord;
            $soa->setName('@');
            $soa->setClass(Classes::INTERNET);
            $soa->setRdata(Factory::Soa(
                $c['ns']['ns1'] . '.',
                $c['dns_soa'] . '.',
                $serial, 
                900, 
                1800, 
                3600000, 
                3600
            ));
            $zone->addResourceRecord($soa);

            foreach ($c['ns'] as $ns) {
                $nsRecord = new ResourceRecord;
                $nsRecord->setName($cleanedTld . '.');
                $nsRecord->setClass(Classes::INTERNET);
                $nsRecord->setRdata(Factory::Ns($ns . '.'));
                $zone->addResourceRecord($nsRecord);
            }

            // Include custom records if the file exists
            $customRecordsFile = "/opt/registry/automation/{$cleanedTld}.php";

            if (file_exists($customRecordsFile)) {
                $log->info("Loading custom records for {$cleanedTld} from {$customRecordsFile}.");
                $customRecords = require $customRecordsFile;

                foreach ($customRecords as $record) {
                    try {
                        $customRecord = new ResourceRecord;
                        $customRecord->setName($record['name']);
                        $customRecord->setClass(Classes::INTERNET);
                        $customRecord->setRdata(Factory::{$record['type']}(...$record['parameters']));
                        $zone->addResourceRecord($customRecord);
                    } catch (Throwable $e) {
                        $log->error("Failed to add custom record for {$cleanedTld}: " . $e->getMessage());
                    }
                }
            }

            // Fetch publishable domains for this TLD
            $sthDomains = $pdo->prepare(
                "SELECT domain.id, domain.name
                 FROM domain
                 WHERE domain.tldid = :id
                   AND (
                       domain.exdate > CURRENT_TIMESTAMP
                       OR domain.rgpstatus = 'pendingRestore'
                   )
                   AND NOT EXISTS (
                       SELECT 1
                       FROM domain_status
                       WHERE domain_status.domain_id = domain.id
                         AND domain_status.status IN ('clientHold', 'serverHold')
                   )
                 ORDER BY domain.name"
            );

            $sthDomains->execute([':id' => $id]);

            while (list($did, $dname) = $sthDomains->fetch(PDO::FETCH_NUM)) {
                $dname_clean = $dname;
                $dname_clean = ($dname_clean == "$tld.") ? '@' : $dname_clean;

                // NS records for the domain
                $sthNsRecords = $pdo->prepare('SELECT DISTINCT host.name FROM domain_host_map INNER JOIN host ON domain_host_map.host_id = host.id WHERE domain_host_map.domain_id = :did');
                $sthNsRecords->execute([':did' => $did]);
                while (list($hname) = $sthNsRecords->fetch(PDO::FETCH_NUM)) {
                    $nsRecord = new ResourceRecord;
                    $nsRecord->setName($dname_clean . '.');
                    $nsRecord->setClass(Classes::INTERNET);
                    $nsRecord->setRdata(Factory::Ns($hname . '.'));
                    $zone->addResourceRecord($nsRecord);
                }

                // A/AAAA records for the domain
                $sthHostRecords = $pdo->prepare("SELECT host.name, host_addr.ip, host_addr.addr FROM host INNER JOIN host_addr ON host.id = host_addr.host_id WHERE host.domain_id = :did ORDER BY host.name");
                $sthHostRecords->execute([':did' => $did]);
                while (list($hname, $type, $addr) = $sthHostRecords->fetch(PDO::FETCH_NUM)) {
                    $hname_clean = $hname;
                    $hname_clean = ($hname_clean == "$tld.") ? '@' : $hname_clean;
                    $record = new ResourceRecord;
                    $record->setName($hname_clean . '.');
                    $record->setClass(Classes::INTERNET);

                    if ($type == 'v4') {
                        $record->setRdata(Factory::A($addr));
                    } else {
                        $record->setRdata(Factory::AAAA($addr));
                    }

                    $zone->addResourceRecord($record);
                }

                // DS records for the domain
                $sthDS = $pdo->prepare("SELECT keytag, alg, digesttype, digest FROM secdns WHERE domain_id = :did");
                $sthDS->execute([':did' => $did]);
                while (list($keytag, $alg, $digesttype, $digest) = $sthDS->fetch(PDO::FETCH_NUM)) {
                    $dsRecord = new ResourceRecord;
                    $dsRecord->setName($dname_clean . '.');
                    $dsRecord->setClass(Classes::INTERNET);
                    $dsRecord->setRdata(Factory::Ds($keytag, $alg, hex2bin($digest), $digesttype));
                    $zone->addResourceRecord($dsRecord);
                }
            }

            if (isset($c['zone_mode']) && $c['zone_mode'] === 'nice') {
                $builder = new AlignedBuilder();
            } else {
                $builder = new ZoneBuilder();
            }
            $completed_zone = $builder->build($zone);

            $basePath = match ($dnsServer) {
                'bind'    => '/var/lib/bind',
                'knot'    => '/var/lib/knot',
                'cascade' => '/var/lib/cascade/zones',
                default   => null,
            };

            if ($basePath === null) {
                $configuredServer = $dnsServer !== '' ? $dnsServer : '(not set)';

                $log->error("Unsupported DNS server '{$configuredServer}'. Supported values: bind, knot, cascade.");
                continue;
            }

            $tmpPath = "{$basePath}/.{$cleanedTld}.zone.new";
            $finalPath = "{$basePath}/{$cleanedTld}.zone";

            if (file_put_contents($tmpPath, $completed_zone, LOCK_EX) === false) {
                $log->error("Failed to write temporary zone file for {$cleanedTld}.");
                continue;
            }

            if (!chmod($tmpPath, 0644)) {
                unlink($tmpPath);
                $log->error("Failed to set permissions on zone file for {$cleanedTld}.");
                continue;
            }

            // Validate generated zone before publishing it
            if (file_exists($finalPath)    && filesize($tmpPath) === filesize($finalPath) && md5_file($tmpPath) === md5_file($finalPath)) {
                unlink($tmpPath);
                $log->info("Zone file unchanged for {$cleanedTld}, skipping validation and reload.");
                continue;
            }

            if (!validateZoneFile($dnsServer, $cleanedTld, $tmpPath, $log)) {
                unlink($tmpPath);
                $log->error("Skipping publication for {$cleanedTld} because validation failed.");
                continue;
            }

            if (!rename($tmpPath, $finalPath)) {
                unlink($tmpPath);
                $log->error("Failed to publish zone file for {$cleanedTld}.");
                continue;
            }

            $tlds[] = $cleanedTld;
            $log->info("Zone file updated for {$cleanedTld}.");
        }

        foreach ($tlds as $cleanedTld) {
            if (!$dnsReload) {
                $log->info("DNS reload disabled by configuration; skipping reload for {$cleanedTld}.");
                continue;
            }

            $reload = match ($dnsServer) {
                'bind'    => ['BIND', 'rndc reload ' . escapeshellarg($cleanedTld . '.')],
                'knot'    => ['Knot DNS', 'knotc zone-reload ' . escapeshellarg($cleanedTld . '.')],
                'cascade' => ['Cascade', 'cascade zone reload ' . escapeshellarg($cleanedTld)],
                default   => null,
            };

            if ($reload === null) {
                $configuredServer = $dnsServer !== '' ? $dnsServer : '(not set)';
                $log->error("Unsupported DNS server '{$configuredServer}'. Supported values: bind, knot, cascade.");
                continue;
            }

            [$serverName, $command] = $reload;

            $output = [];
            $returnVar = 0;

            exec($command . ' 2>&1', $output, $returnVar);

            if ($returnVar !== 0) {
                $log->error("Failed to reload {$serverName} for {$cleanedTld}. Output: " . implode(' ', $output) . " Code: {$returnVar}");
                continue;
            }

            $log->info("{$serverName} successfully reloaded zone {$cleanedTld}.");
        }

        $log->info('job finished successfully.');
    } catch (PDOException $e) {
        $log->error('Database error: ' . $e->getMessage());
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
    } finally {
        $pool->put($pdo);
    }
});