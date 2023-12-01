<?php

require_once 'vendor/autoload.php';

use Badcow\DNS\Zone;
use Badcow\DNS\Rdata\Factory;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\Classes;
use Badcow\DNS\AlignedBuilder;

$c = require_once 'config.php';
require_once 'helpers.php';

$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$logFilePath = '/var/log/namingo/write_zone.log';
$log = setupLogger($logFilePath, 'Zone_Generator');
$log->info('job started.');

try {
    $db = new PDO($dsn, $c['db_username'], $c['db_password']);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

$timestamp = time();
$ns1 = 'ns1.namingo.org';
$ns2 = 'ns2.namingo.org';

$sth = $db->prepare('SELECT id, tld FROM domain_tld');
$sth->execute();

while (list($id, $tld) = $sth->fetch(PDO::FETCH_NUM)) {
    $tldRE = preg_quote($tld, '/');
    $cleanedTld = ltrim(strtolower($tld), '.');
    $zone = new Zone('.');
    $zone->setDefaultTtl(3600);
    
    $soa = new ResourceRecord;
    $soa->setName($cleanedTld . '.');
    $soa->setClass(Classes::INTERNET);
    $soa->setRdata(Factory::Soa(
        $ns1 . '.',
        'postmaster.' . $cleanedTld . '.',
        $timestamp, 
        3600, 
        14400, 
        604800, 
        3600
    ));
    $zone->addResourceRecord($soa);

    $nsRecord1 = new ResourceRecord;
    $nsRecord1->setName($cleanedTld . '.');
    $nsRecord1->setClass(Classes::INTERNET);
    $nsRecord1->setRdata(Factory::Ns($ns1 . '.'));
    $zone->addResourceRecord($nsRecord1);

    $nsRecord2 = new ResourceRecord;
    $nsRecord2->setName($cleanedTld . '.');
    $nsRecord2->setClass(Classes::INTERNET);
    $nsRecord2->setRdata(Factory::Ns($ns2 . '.'));
    $zone->addResourceRecord($nsRecord2);

    $sth2 = $db->prepare('SELECT DISTINCT domain.id, domain.name, domain.rgpstatus, host.name
                           FROM domain
                           INNER JOIN domain_host_map ON domain.id = domain_host_map.domain_id
                           INNER JOIN host ON domain_host_map.host_id = host.id
                           LEFT JOIN host_addr ON host_addr.host_id = host.id
                           WHERE domain.tldid = :id
                           AND (
                               host.domain_id IS NULL
                               OR host_addr.addr IS NOT NULL
                           )
                           AND (
                               domain.exdate > CURRENT_TIMESTAMP
                               OR rgpstatus = \'pendingRestore\'
                           )
                           ORDER BY domain.name');
    $sth2->execute([':id' => $id]);

    while (list($did, $dname, $rgp, $hname) = $sth2->fetch(PDO::FETCH_NUM)) {
        $sthStatus = $db->prepare("SELECT id FROM domain_status WHERE domain_id = :did AND status LIKE '%Hold' LIMIT 1");
        $sthStatus->bindParam(':did', $did, PDO::PARAM_INT);
        $sthStatus->execute();
        $status_id = $sthStatus->fetchColumn();

        if ($status_id) continue;
    
        $dname = trim($dname, "$tldRE.");
        $dname = ($dname == "$tld.") ? '@' : $dname;
    
        $nsRecord = new ResourceRecord;
        $nsRecord->setName($dname . '.');
        $nsRecord->setClass(Classes::INTERNET);
        $nsRecord->setRdata(Factory::Ns($hname . '.'));
        $zone->addResourceRecord($nsRecord);
    }

    $sth2 = $db->prepare("SELECT host.name, host.domain_id, host_addr.ip, host_addr.addr
                           FROM domain
                           INNER JOIN host ON domain.id = host.domain_id
                           INNER JOIN host_addr ON host.id = host_addr.host_id
                           WHERE domain.tldid = :id
                           AND (domain.exdate > CURRENT_TIMESTAMP OR rgpstatus = 'pendingRestore')
                           ORDER BY host.name");
    $sth2->execute([':id' => $id]);

    while (list($hname, $did, $type, $addr) = $sth2->fetch(PDO::FETCH_NUM)) {
        $sthStatus = $db->prepare("SELECT id FROM domain_status WHERE domain_id = :did AND status LIKE '%Hold' LIMIT 1");
        $sthStatus->bindParam(':did', $did, PDO::PARAM_INT);
        $sthStatus->execute();
        $status_id = $sthStatus->fetchColumn();
        
        if ($status_id) continue;

        $hname = trim($hname, "$tldRE.");
        $hname = ($hname == "$tld.") ? '@' : $hname;

        $record = new ResourceRecord;
        $record->setName($hname . '.');
        $record->setClass(Classes::INTERNET);

        if ($type == 'v4') {
            $record->setRdata(Factory::A($addr));
        } else {
            $record->setRdata(Factory::AAAA($addr));
        }
    
        $zone->addResourceRecord($record);
    }
    
    // Fetch DS records for domains from the secdns table
    $sthDS = $db->prepare("SELECT domain_id, keytag, alg, digesttype, digest 
                            FROM secdns 
                            WHERE domain_id IN (
                                SELECT id FROM domain 
                                WHERE tldid = :id 
                                AND (exdate > CURRENT_TIMESTAMP OR rgpstatus = 'pendingRestore')
                            )");
    $sthDS->execute([':id' => $id]);

    while (list($did, $keytag, $alg, $digesttype, $digest) = $sthDS->fetch(PDO::FETCH_NUM)) {
        $sthStatus = $db->prepare("SELECT id FROM domain_status WHERE domain_id = :did AND status LIKE '%Hold' LIMIT 1");
        $sthStatus->bindParam(':did', $did, PDO::PARAM_INT);
        $sthStatus->execute();
        $status_id = $sthStatus->fetchColumn();

        if ($status_id) continue;

        // Fetch domain name based on domain_id for the DS record
        $sthDomainName = $db->prepare("SELECT name FROM domain WHERE id = :did LIMIT 1");
        $sthDomainName->bindParam(':did', $did, PDO::PARAM_INT);
        $sthDomainName->execute();
        $dname = $sthDomainName->fetchColumn();
        
        $dname = trim($dname, "$tldRE.");
        $dname = ($dname == "$tld.") ? '@' : $dname;

        $dsRecord = new ResourceRecord;
        $dsRecord->setName($dname . '.');
        $dsRecord->setClass(Classes::INTERNET);
        $dsRecord->setRdata(Factory::Ds($keytag, $alg, $digest, $digesttype));

        $zone->addResourceRecord($dsRecord);
    }
    
    $builder = new AlignedBuilder();
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
    $log->info('job finished successfully.');
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