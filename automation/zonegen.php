<?php

// Database connection
$c = include 'config.php';
$dsn = "mysql:host={$c['mysql_host']};port={$c['mysql_port']};dbname={$c['mysql_database']}";
try {
    $dbh = new PDO($dsn, $c['mysql_username'], $c['mysql_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$timestamp = time();
$ns1 = 'ns1.namingo.org';
$ns2 = 'ns2.namingo.org';

$sth = $dbh->prepare("SELECT `id`,`tld` FROM `domain_tld`");
$sth->execute();

while (list($id, $tld) = $sth->fetch(PDO::FETCH_NUM)) {
    $tldRE = preg_quote($tld, '/');
    $outFile = fopen("/var/named/named{$tld}.zone", 'w') or print "Unable to open file '/var/named/named{$tld}.zone'.\n";
    fwrite($outFile, "\$TTL\t1H\n@\tIN\tSOA\t{$ns1}.\tpostmaster{$tld}. (\n\t$timestamp\n\t3H\n\t1H\n\t1W\n\t1D\n\t)\n\n");
    fwrite($outFile, "@\t1H\tIN\tNS\t{$ns1}.\n");
    fwrite($outFile, "@\t1H\tIN\tNS\t{$ns2}.\n");

    // Select all the hosts
    $sth2 = $dbh->prepare("SELECT DISTINCT `domain`.`id`, `domain`.`name`, `domain`.`exdate`, `rgpstatus`, `domain`.`name`
                           FROM `domain`
                           WHERE `domain`.`tldid` = :id
                           AND (
                                `domain`.`exdate` > NOW()
                                OR `rgpstatus` = 'pendingRestore'
                            )
                            ORDER BY `domain`.`name`");
    $sth2->execute([':id' => $id]);

    while (list($did, $dname, $hname) = $sth2->fetch(PDO::FETCH_NUM)) {
        $status_id = $dbh->query("SELECT `id` FROM `domain_status` WHERE `domain_id` = '$did' AND `status` LIKE '%Hold' LIMIT 1")->fetchColumn();
        if ($status_id) continue;
        $dname = trim($dname, "$tldRE.");
        $dname = ($dname == "$tld.") ? '@' : $dname;
        $hname = trim($hname, "$tldRE.");
        fwrite($outFile, "$dname\tIN\tNS\t$hname\n");
    }

    // Select the A and AAAA records
    $sth2 = $dbh->prepare("SELECT `host`.`name`,`host`.`domain_id`,`host_addr`.`ip`,`host_addr`.`addr`
                           FROM `domain`,`host`,`host_addr`
                           WHERE `domain`.`tldid` = :id
                           AND `domain`.`id` = `host`.`domain_id`
                           AND `host`.`id` = `host_addr`.`host_id`
                           AND (`domain`.`exdate` > NOW() OR `rgpstatus` = 'pendingRestore')
                           ORDER BY `host`.`name`");
    $sth2->execute([':id' => $id]);

    while (list($hname, $did, $type, $addr) = $sth2->fetch(PDO::FETCH_NUM)) {
        $status_id = $dbh->query("SELECT `id` FROM `domain_status` WHERE `domain_id` = '$did' AND `status` LIKE '%Hold' LIMIT 1")->fetchColumn();
        if ($status_id) continue;
        $hname = trim($hname, "$tldRE.");
        $hname = ($hname == "$tld.") ? '@' : $hname;
        if ($type == 'v4') {
            fwrite($outFile, "$hname\tIN\tA\t$addr\n");
        } else {
            fwrite($outFile, "$hname\tIN\tAAAA\t$addr\n");
        }
    }

    fwrite($outFile, "\n; EOF\n");
    fclose($outFile);
}

exec("systemctl reload named.service", $output, $return_var);
if ($return_var != 0) {
    print "Failed to reload named. $return_var \n";
}