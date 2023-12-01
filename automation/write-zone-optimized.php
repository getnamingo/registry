<?php

require_once 'vendor/autoload.php';

use Badcow\DNS\Zone;
use Badcow\DNS\Rdata\Factory;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\Classes;
use Badcow\DNS\ZoneBuilder;

$c = require_once 'config.php';
require_once 'helpers.php';

$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$logFilePath = '/var/log/namingo/write_zone_optimized.log';
$log = setupLogger($logFilePath, 'Zone_Generator_Optimized');
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

    // Fetch domains for this TLD
    $sthDomains = $db->prepare('SELECT DISTINCT domain.id, domain.name FROM domain WHERE tldid = :id AND (exdate > CURRENT_TIMESTAMP OR rgpstatus = \'pendingRestore\') ORDER BY domain.name');
    $sthDomains->execute([':id' => $id]);

    while (list($did, $dname) = $sthDomains->fetch(PDO::FETCH_NUM)) {
        $sthStatus = $db->prepare("SELECT id FROM domain_status WHERE domain_id = :did AND status LIKE '%Hold' LIMIT 1");
        $sthStatus->bindParam(':did', $did, PDO::PARAM_INT);
        $sthStatus->execute();
        $status_id = $sthStatus->fetchColumn();

        if ($status_id) continue;

        $dname_clean = trim($dname, "$tldRE.");
        $dname_clean = ($dname_clean == "$tld.") ? '@' : $dname_clean;

        // NS records for the domain
        $sthNsRecords = $db->prepare('SELECT DISTINCT host.name FROM domain_host_map INNER JOIN host ON domain_host_map.host_id = host.id WHERE domain_host_map.domain_id = :did');
        $sthNsRecords->execute([':did' => $did]);
        while (list($hname) = $sthNsRecords->fetch(PDO::FETCH_NUM)) {
            $nsRecord = new ResourceRecord;
            $nsRecord->setName($dname_clean . '.');
            $nsRecord->setClass(Classes::INTERNET);
            $nsRecord->setRdata(Factory::Ns($hname . '.'));
            $zone->addResourceRecord($nsRecord);
        }

        // A/AAAA records for the domain
        $sthHostRecords = $db->prepare("SELECT host.name, host_addr.ip, host_addr.addr FROM host INNER JOIN host_addr ON host.id = host_addr.host_id WHERE host.domain_id = :did ORDER BY host.name");
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
        $sthDS = $db->prepare("SELECT keytag, alg, digesttype, digest FROM secdns WHERE domain_id = :did");
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