<?php

require_once 'vendor/autoload.php';

use Badcow\DNS\Zone;
use Badcow\DNS\Rdata\Factory;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\Classes;
use Badcow\DNS\ZoneBuilder;

$c = require_once 'config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/write_zone.log';
$log = setupLogger($logFilePath, 'Zone_Generator');
$log->info('job started.');

use Swoole\Coroutine;

// Initialize the PDO connection pool
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

// Creating first coroutine
Coroutine::create(function () use ($pool, $log, $c) {
    try {
        $pdo = $pool->get();
        $sth = $pdo->prepare('SELECT id, tld FROM domain_tld');
        $sth->execute();
        $timestamp = time();

        while (list($id, $tld) = $sth->fetch(PDO::FETCH_NUM)) {
            $tldRE = preg_quote($tld, '/');
            $cleanedTld = ltrim(strtolower($tld), '.');
            $zone = new Zone('.');
            $zone->setDefaultTtl(3600);
            
            $soa = new ResourceRecord;
            $soa->setName($cleanedTld . '.');
            $soa->setClass(Classes::INTERNET);
            $soa->setRdata(Factory::Soa(
                $c['ns']['ns1'] . '.',
                $c['dns_soa'] . '.',
                $timestamp, 
                3600, 
                14400, 
                604800, 
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
            
            // Fetch domains for this TLD
            $sthDomains = $pdo->prepare('SELECT DISTINCT domain.id, domain.name FROM domain WHERE tldid = :id AND (exdate > CURRENT_TIMESTAMP OR rgpstatus = \'pendingRestore\') ORDER BY domain.name');
            
            $domainIds = [];
            $sthDomains->execute([':id' => $id]);
            while ($row = $sthDomains->fetch(PDO::FETCH_ASSOC)) {
                $domainIds[] = $row['id'];
            }

            $statuses = [];
            if (count($domainIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($domainIds), '?'));
                $sthStatus = $pdo->prepare("SELECT domain_id, id FROM domain_status WHERE domain_id IN ($placeholders) AND status LIKE '%Hold'");
                $sthStatus->execute($domainIds);
                while ($row = $sthStatus->fetch(PDO::FETCH_ASSOC)) {
                    $statuses[$row['domain_id']] = $row['id'];
                }
            }
            
            $sthDomains->execute([':id' => $id]);

            while (list($did, $dname) = $sthDomains->fetch(PDO::FETCH_NUM)) {
                if (isset($statuses[$did])) continue;

                $dname_clean = trim($dname, "$tldRE.");
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
                    $hname_clean = trim($hname, "$tldRE.");
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
                    $dsRecord->setRdata(Factory::Ds($keytag, $alg, $digest, $digesttype));
                    $zone->addResourceRecord($dsRecord);
                }
            }
            
            $builder = new ZoneBuilder();
            $completed_zone = $builder->build($zone);

            if ($c['dns_server'] == 'bind') {
                $basePath = '/etc/bind/zones';
            } elseif ($c['dns_server'] == 'nsd') {
                $basePath = '/etc/nsd';
            } elseif ($c['dns_server'] == 'knot') {
                $basePath = '/etc/knot';
            } else {
                // Default path
                $basePath = '/etc/bind/zones';
            }

            file_put_contents("{$basePath}/{$cleanedTld}.zone", $completed_zone);
        }

        if ($c['dns_server'] == 'bind') {
            exec("rndc reload .{$cleanedTld}", $output, $return_var);
            if ($return_var != 0) {
                $log->error('Failed to reload BIND. ' . $return_var);
            }

            exec("rndc notify .{$cleanedTld}", $output, $return_var);
            if ($return_var != 0) {
                $log->error('Failed to notify secondary servers. ' . $return_var);
            }
        } elseif ($c['dns_server'] == 'nsd') {
            exec("nsd-control reload", $output, $return_var);
            if ($return_var != 0) {
                $log->error('Failed to reload NSD. ' . $return_var);
            }
        } elseif ($c['dns_server'] == 'knot') {
            exec("knotc reload", $output, $return_var);
            if ($return_var != 0) {
                $log->error('Failed to reload Knot DNS. ' . $return_var);
            }

            exec("knotc zone-notify .{$cleanedTld}", $output, $return_var);
            if ($return_var != 0) {
                $log->error('Failed to notify secondary servers. ' . $return_var);
            }
        } else {
            // Default
            exec("rndc reload .{$cleanedTld}", $output, $return_var);
            if ($return_var != 0) {
                $log->error('Failed to reload BIND. ' . $return_var);
            }

            exec("rndc notify .{$cleanedTld}", $output, $return_var);
            if ($return_var != 0) {
                $log->error('Failed to notify secondary servers. ' . $return_var);
            }
        }
    
        $log->info('job finished successfully.');
    } catch (PDOException $e) {
        $log->error('Database error: ' . $e->getMessage());
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
    } finally {
        // Return the connection to the pool
        $pool->put($pdo);
    }
});