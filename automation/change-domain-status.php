<?php

// Connect to the MySQL database using PDO
$c = require 'config.php';
$dsn = "mysql:host={$c['mysql_host']};dbname={$c['mysql_database']};port={$c['mysql_port']}";
$dbh = new PDO($dsn, $c['mysql_username'], $c['mysql_password']);
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Auto-Renew Grace Period
$auto_renew = 0;

if ($auto_renew) {
    $sth_autorenewperiod = $dbh->prepare("SELECT `id`, `name`, `tldid`, `exdate`, `clid` FROM `domain` WHERE CURRENT_TIMESTAMP > `exdate` AND `rgpstatus` IS NULL");
    $sth_autorenewperiod->execute();
    while ($row = $sth_autorenewperiod->fetch(PDO::FETCH_ASSOC)) {
        $domain_id = $row['id'];
        $name = $row['name'];
        $tldid = $row['tldid'];
        $exdate = $row['exdate'];
        $clid = $row['clid'];

        $sth_status = $dbh->prepare("SELECT `status` FROM `domain_status` WHERE `domain_id` = ?");
        $sth_status->execute([$domain_id]);

        $set_autorenewPeriod = 1;
        while ($status_row = $sth_status->fetch(PDO::FETCH_ASSOC)) {
            $status = $status_row['status'];
            if (preg_match("/(serverUpdateProhibited|serverDeleteProhibited)$/", $status) || preg_match("/^pending/", $status)) {
                $set_autorenewPeriod = 0;
                continue;
            }
        }

        if ($set_autorenewPeriod) {
            list($registrar_balance, $creditLimit) = $dbh->query("SELECT `accountBalance`, `creditLimit` FROM `registrar` WHERE `id` = '$clid' LIMIT 1")->fetch(PDO::FETCH_NUM);
            $price = $dbh->query("SELECT `m12` FROM `domain_price` WHERE `tldid` = '$tldid' AND `command` = 'renew' LIMIT 1")->fetchColumn();

            if (($registrar_balance + $creditLimit) > $price) {
                $dbh->exec("UPDATE `domain` SET `rgpstatus` = 'autoRenewPeriod', `exdate` = DATE_ADD(`exdate`, INTERVAL 12 MONTH), `autoRenewPeriod` = '12', `renewedDate` = `exdate` WHERE `id` = '$domain_id'");
                $dbh->exec("UPDATE `registrar` SET `accountBalance` = (`accountBalance` - $price) WHERE `id` = '$clid'");
                $dbh->exec("INSERT INTO `payment_history` (`registrar_id`, `date`, `description`, `amount`) VALUES('$clid', CURRENT_TIMESTAMP, 'autoRenew domain $name for period 12 MONTH', '-$price')");
                list($to) = $dbh->query("SELECT `exdate` FROM `domain` WHERE `id` = '$domain_id' LIMIT 1")->fetch(PDO::FETCH_NUM);
                $sth = $dbh->prepare("INSERT INTO `statement` (`registrar_id`, `date`, `command`, `domain_name`, `length_in_months`, `from`, `to`, `amount`) VALUES(?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?)");
                $sth->execute([$clid, 'autoRenew', $name, '12', $exdate, $to, $price]);
                if (!$dbh->query("SELECT `id` FROM `statistics` WHERE `date` = CURDATE()")->fetchColumn()) {
                    $dbh->exec("INSERT IGNORE INTO `statistics` (`date`) VALUES(CURDATE())");
                }
                $dbh->exec("UPDATE `statistics` SET `renewed_domains` = `renewed_domains` + 1 WHERE `date` = CURDATE()");
            } else {
                $dbh->exec("DELETE FROM `domain_status` WHERE `domain_id` = '$domain_id'");
                $dbh->exec("UPDATE `domain` SET `rgpstatus` = 'redemptionPeriod', `delTime` = `exdate` WHERE `id` = '$domain_id'");
                $dbh->exec("INSERT INTO `domain_status` (`domain_id`, `status`) VALUES('$domain_id', 'pendingDelete')");
            }
        }
        echo date("Y-m-d H:i:s") . " - $domain_id\t|\t$name\t|\trgpStatus:autoRenewPeriod exdate:$exdate\n";
    }
} else {
    $grace_period = 30;
    $sth_graceperiod = $dbh->prepare("SELECT `id`, `name`, `exdate` FROM `domain` WHERE CURRENT_TIMESTAMP > DATE_ADD(`exdate`, INTERVAL $grace_period DAY) AND `rgpstatus` IS NULL");
    $sth_graceperiod->execute();
    while ($row = $sth_graceperiod->fetch(PDO::FETCH_ASSOC)) {
        $domain_id = $row['id'];
        $name = $row['name'];
        $exdate = $row['exdate'];

        $sth_status = $dbh->prepare("SELECT `status` FROM `domain_status` WHERE `domain_id` = ?");
        $sth_status->execute([$domain_id]);

        $set_graceperiod = 1;
        while ($status_row = $sth_status->fetch(PDO::FETCH_ASSOC)) {
            $status = $status_row['status'];
            if (preg_match("/(serverUpdateProhibited|serverDeleteProhibited)$/", $status) || preg_match("/^pending/", $status)) {
                $set_graceperiod = 0;
                continue;
            }
        }

        if ($set_graceperiod) {
            $dbh->exec("DELETE FROM `domain_status` WHERE `domain_id` = '$domain_id'");
            $dbh->exec("UPDATE `domain` SET `rgpstatus` = 'redemptionPeriod', `delTime` = DATE_ADD(`exdate`, INTERVAL $grace_period DAY) WHERE `id` = '$domain_id'");
            $dbh->exec("INSERT INTO `domain_status` (`domain_id`, `status`) VALUES('$domain_id', 'pendingDelete')");
        }

        echo date("Y-m-d H:i:s") . " - $domain_id\t|\t$name\t|\trgpStatus:redemptionPeriod exdate:$exdate\n";
    }
}

try {
    // clean autoRenewPeriod after 45 days
    $sql1 = "UPDATE `domain` SET `rgpstatus` = NULL WHERE CURRENT_TIMESTAMP > DATE_ADD(`exdate`, INTERVAL 45 DAY) AND `rgpstatus` = 'autoRenewPeriod'";
    $dbh->exec($sql1);

    // clean addPeriod after 5 days
    $sql2 = "UPDATE `domain` SET `rgpstatus` = NULL WHERE CURRENT_TIMESTAMP > DATE_ADD(`crdate`, INTERVAL 5 DAY) AND `rgpstatus` = 'addPeriod'";
    $dbh->exec($sql2);

    // clean renewPeriod after 5 days
    $sql3 = "UPDATE `domain` SET `rgpstatus` = NULL WHERE CURRENT_TIMESTAMP > DATE_ADD(`renewedDate`, INTERVAL 5 DAY) AND `rgpstatus` = 'renewPeriod'";
    $dbh->exec($sql3);

    // clean transferPeriod after 5 days
    $sql4 = "UPDATE `domain` SET `rgpstatus` = NULL WHERE CURRENT_TIMESTAMP > DATE_ADD(`trdate`, INTERVAL 5 DAY) AND `rgpstatus` = 'transferPeriod'";
    $dbh->exec($sql4);

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Pending Delete
// The current value of the redemptionPeriod is 30 calendar days.
$sth_pendingdelete = $dbh->prepare("SELECT `id`, `name`, `exdate` FROM `domain` WHERE CURRENT_TIMESTAMP > DATE_ADD(`delTime`, INTERVAL 30 DAY) AND `rgpstatus` = 'redemptionPeriod'");
$sth_pendingdelete->execute();

while ($row = $sth_pendingdelete->fetch(PDO::FETCH_ASSOC)) {
    $domain_id = $row['id'];
    $name = $row['name'];
    $exdate = $row['exdate'];

    $sth_status = $dbh->prepare("SELECT `status` FROM `domain_status` WHERE `domain_id` = ?");
    $sth_status->execute([$domain_id]);

    $set_pendingDelete = 1;
    while ($status_row = $sth_status->fetch(PDO::FETCH_ASSOC)) {
        $status = $status_row['status'];
        if (preg_match("/(serverUpdateProhibited|serverDeleteProhibited)$/", $status)) {
            $set_pendingDelete = 0;
            continue;
        }
    }

    if ($set_pendingDelete) {
        $dbh->exec("UPDATE `domain` SET `rgpstatus` = 'pendingDelete' WHERE `id` = '$domain_id'");
    }

    echo date("Y-m-d H:i:s") . " - $domain_id\t|\t$name\t|\trgpStatus:pendingDelete exdate:$exdate\n";
}

// Pending Restore
$sth_pendingRestore = $dbh->prepare("SELECT `id`, `name`, `exdate` FROM `domain` WHERE `rgpstatus` = 'pendingRestore' AND (CURRENT_TIMESTAMP > DATE_ADD(`resTime`, INTERVAL 7 DAY))");
$sth_pendingRestore->execute();

while ($row = $sth_pendingRestore->fetch(PDO::FETCH_ASSOC)) {
    $domain_id = $row['id'];
    $name = $row['name'];
    $exdate = $row['exdate'];

    $dbh->exec("UPDATE `domain` SET `rgpstatus` = 'redemptionPeriod' WHERE `id` = '$domain_id'");

    echo date("Y-m-d H:i:s") . " - $domain_id\t|\t$name\t|\tback to redemptionPeriod from pendingRestore exdate:$exdate\n";
}

// Domain Deletion
$sth_delete = $dbh->prepare("SELECT `id`, `name`, `exdate` FROM `domain` WHERE CURRENT_TIMESTAMP > DATE_ADD(`delTime`, INTERVAL 35 DAY) AND `rgpstatus` = 'pendingDelete'");
$sth_delete->execute();

while ($row = $sth_delete->fetch(PDO::FETCH_ASSOC)) {
    $domain_id = $row['id'];
    $name = $row['name'];
    $exdate = $row['exdate'];

    $sth_status = $dbh->prepare("SELECT `status` FROM `domain_status` WHERE `domain_id` = ?");
    $sth_status->execute([$domain_id]);

    $delete_domain = 0;
    while ($status_row = $sth_status->fetch(PDO::FETCH_ASSOC)) {
        $status = $status_row['status'];
        if ($status == 'pendingDelete') {
            $delete_domain = 1;
        }
        if (preg_match("/(serverUpdateProhibited|serverDeleteProhibited)$/", $status)) {
            $delete_domain = 0;
            continue;
        }
    }

    if ($delete_domain) {
        // Actual deletion process
        $sth = $dbh->prepare("SELECT `id` FROM `host` WHERE `domain_id` = ?");
        $sth->execute([$domain_id]);
        while ($host_row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $host_id = $host_row['id'];
            $dbh->exec("DELETE FROM `host_addr` WHERE `host_id` = '$host_id'");
            $dbh->exec("DELETE FROM `host_status` WHERE `host_id` = '$host_id'");
            $dbh->exec("DELETE FROM `domain_host_map` WHERE `host_id` = '$host_id'");
        }

        $dbh->exec("DELETE FROM `domain_contact_map` WHERE `domain_id` = '$domain_id'");
        $dbh->exec("DELETE FROM `domain_host_map` WHERE `domain_id` = '$domain_id'");
        $dbh->exec("DELETE FROM `domain_authInfo` WHERE `domain_id` = '$domain_id'");
        $dbh->exec("DELETE FROM `domain_status` WHERE `domain_id` = '$domain_id'");
        $dbh->exec("DELETE FROM `host` WHERE `domain_id` = '$domain_id'");

        $sth = $dbh->prepare("DELETE FROM `domain` WHERE `id` = ?");
        $sth->execute([$domain_id]);
        if ($sth->errorCode() !== '00000') {
            $errorInfo = $sth->errorInfo();
            echo 'Numele de domeniu nu a fost sters cred ca este vre-o legatura cu alte obiecte: ' . $errorInfo[2];
        } else {
            if (!$dbh->query("SELECT `id` FROM `statistics` WHERE `date` = CURDATE()")->fetchColumn()) {
                $dbh->exec("INSERT IGNORE INTO `statistics` (`date`) VALUES(CURDATE())");
            }
            $dbh->exec("UPDATE `statistics` SET `deleted_domains` = `deleted_domains` + 1 WHERE `date` = CURDATE()");
        }
    }

    echo date("Y-m-d H:i:s") . " - $domain_id\t|\t$name\t|\tdomain:Deleted exdate:$exdate\n";
}