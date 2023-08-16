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

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$timestamp = time();
$ns1 = 'ns1.namingo.org';
$ns2 = 'ns2.namingo.org';

$sth = $dbh->prepare('SELECT id, tld FROM domain_tld');
$sth->execute();

while (list($id, $tld) = $sth->fetch(PDO::FETCH_NUM)) {
    $tldRE = preg_quote($tld, '/');
    $cleanedTld = ltrim(strtolower($tld), '.');
    $zone = new Zone($cleanedTld . '.');
    $zone->setDefaultTtl(3600);
	
    $soa = new ResourceRecord;
    $soa->setName('@');
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
    $nsRecord1->setName('@');
    $nsRecord1->setClass(Classes::INTERNET);
    $nsRecord1->setRdata(Factory::Ns($ns1 . '.'));
    $zone->addResourceRecord($nsRecord1);

    $nsRecord2 = new ResourceRecord;
    $nsRecord2->setName('@');
    $nsRecord2->setClass(Classes::INTERNET);
    $nsRecord2->setRdata(Factory::Ns($ns2 . '.'));
    $zone->addResourceRecord($nsRecord2);

    $sth2 = $dbh->prepare('SELECT DISTINCT domain.id, domain.name, domain.rgpstatus, host.name
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
        $sthStatus = $dbh->prepare("SELECT id FROM domain_status WHERE domain_id = :did AND status LIKE '%Hold' LIMIT 1");
        $sthStatus->bindParam(':did', $did, PDO::PARAM_INT);
        $sthStatus->execute();
        $status_id = $sthStatus->fetchColumn();

        if ($status_id) continue;
    
        $dname = trim($dname, "$tldRE.");
        $dname = ($dname == "$tld.") ? '@' : $dname;
    
        $nsRecord = new ResourceRecord;
        $nsRecord->setName($dname);
        $nsRecord->setClass(Classes::INTERNET);
        $nsRecord->setRdata(Factory::Ns($hname . '.'));
        $zone->addResourceRecord($nsRecord);
    }

    $sth2 = $dbh->prepare("SELECT host.name, host.domain_id, host_addr.ip, host_addr.addr
                           FROM domain
                           INNER JOIN host ON domain.id = host.domain_id
                           INNER JOIN host_addr ON host.id = host_addr.host_id
                           WHERE domain.tldid = :id
                           AND (domain.exdate > CURRENT_TIMESTAMP OR rgpstatus = 'pendingRestore')
                           ORDER BY host.name");
    $sth2->execute([':id' => $id]);

	while (list($hname, $did, $type, $addr) = $sth2->fetch(PDO::FETCH_NUM)) {
        $sthStatus = $dbh->prepare("SELECT id FROM domain_status WHERE domain_id = :did AND status LIKE '%Hold' LIMIT 1");
        $sthStatus->bindParam(':did', $did, PDO::PARAM_INT);
        $sthStatus->execute();
        $status_id = $sthStatus->fetchColumn();
		
	    if ($status_id) continue;

	    $hname = trim($hname, "$tldRE.");
	    $hname = ($hname == "$tld.") ? '@' : $hname;

	    $record = new ResourceRecord;
	    $record->setName($hname);
	    $record->setClass(Classes::INTERNET);

	    if ($type == 'v4') {
	        $record->setRdata(Factory::A($addr));
	    } else {
	        $record->setRdata(Factory::AAAA($addr));
	    }
    
	    $zone->addResourceRecord($record);
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
}

if ($c['dns_server'] == 'bind') {
    exec("rndc reload .{$cleanedTld}", $output, $return_var);
    if ($return_var != 0) {
        print "Failed to reload BIND. $return_var \n";
    }

    exec("rndc notify .{$cleanedTld}", $output, $return_var);
    if ($return_var != 0) {
        print "Failed to notify secondary servers. $return_var \n";
    }
} elseif ($c['dns_server'] == 'nsd') {
    exec("nsd-control reload", $output, $return_var);
    if ($return_var != 0) {
        print "Failed to reload NSD. $return_var \n";
    }
} elseif ($c['dns_server'] == 'knot') {
    exec("knotc reload", $output, $return_var);
    if ($return_var != 0) {
        print "Failed to reload Knot DNS. $return_var \n";
    }

    exec("knotc zone-notify .{$cleanedTld}", $output, $return_var);
    if ($return_var != 0) {
        print "Failed to notify secondary servers. $return_var \n";
    }
} else {
    // Default
    exec("rndc reload .{$cleanedTld}", $output, $return_var);
    if ($return_var != 0) {
        print "Failed to reload BIND. $return_var \n";
    }

    exec("rndc notify .{$cleanedTld}", $output, $return_var);
    if ($return_var != 0) {
        print "Failed to notify secondary servers. $return_var \n";
    }
}