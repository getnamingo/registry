<?php

function processContactDelete($conn, $db, $xml, $clid, $database_type) {
    $contactID = (string) $xml->command->delete->children('urn:ietf:params:xml:ns:contact-1.0')->delete->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    if (!$contactID) {
        sendEppError($conn, 2003, 'Missing contact:id', $clTRID);
        return;
    }

    $stmt = $db->prepare("SELECT id, clid FROM contact WHERE identifier = ? LIMIT 1");
    $stmt->execute([$contactID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $contact_id = $row['id'] ?? null;
    $registrar_id_contact = $row['clid'] ?? null;

    if (!$contact_id) {
        sendEppError($conn, 2303, 'contact:id does not exist', $clTRID);
        return;
    }
    
    $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    $clid = $stmt->fetch(PDO::FETCH_ASSOC);
    $clid = $clid['id'];

    if ($clid !== $registrar_id_contact) {
        sendEppError($conn, 2201, 'Contact belongs to another registrar', $clTRID);
        return;
    }

    $stmt = $db->prepare("SELECT id FROM domain WHERE registrant = ? LIMIT 1");
    $stmt->execute([$contact_id]);
    $registrantExists = $stmt->fetchColumn();

    if ($registrantExists) {
        sendEppError($conn, 2305, 'This contact is associated with a domain as a registrant', $clTRID);
        return;
    }

    $stmt = $db->prepare("SELECT id FROM domain_contact_map WHERE contact_id = ? LIMIT 1");
    $stmt->execute([$contact_id]);
    $contactInUse = $stmt->fetchColumn();

    if ($contactInUse) {
        sendEppError($conn, 2305, 'This contact is associated with a domain', $clTRID);
        return;
    }

    $stmt = $db->prepare("SELECT status FROM contact_status WHERE contact_id = ?");
    $stmt->execute([$contact_id]);

    while ($status = $stmt->fetchColumn()) {
        if (preg_match('/.*(UpdateProhibited|DeleteProhibited)$/', $status) || preg_match('/^pending/', $status)) {
        sendEppError($conn, 2304, 'It has a status that does not allow deletion', $clTRID);
        return;
        }
    }

    // Delete associated records
    $db->prepare("DELETE FROM contact_postalInfo WHERE contact_id = ?")->execute([$contact_id]);
    $db->prepare("DELETE FROM contact_authInfo WHERE contact_id = ?")->execute([$contact_id]);
    $db->prepare("DELETE FROM contact_status WHERE contact_id = ?")->execute([$contact_id]);

    $stmt = $db->prepare("DELETE FROM contact WHERE id = ?");
    $stmt->execute([$contact_id]);

    if ($stmt->errorCode() != '00000') {
        sendEppError($conn, 2400, 'Contact was not deleted, it probably has links to other objects', $clTRID);
        return;
    }

    $response = [
        'command' => 'delete_contact',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'clTRID' => $clTRID,
        'svTRID' => generateSvTRID(),
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    sendEppResponse($conn, $xml);
}

function processHostDelete($conn, $db, $xml, $clid, $database_type) {
    $hostName = $xml->command->delete->children('urn:ietf:params:xml:ns:host-1.0')->delete->name;
    $clTRID = (string) $xml->command->clTRID;

    if (!$hostName) {
        sendEppError($conn, 2003, 'Specify your host name', $clTRID);
        return;
    }

    $query = "SELECT id, clid FROM host WHERE name = :name LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([':name' => $hostName]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $host_id = $result['id'] ?? null;
    $registrar_id_host = $result['clid'] ?? null;

    if (!$host_id) {
        sendEppError($conn, 2303, 'host:name does not exist', $clTRID);
        return;
    }
    
    $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    $clid = $stmt->fetch(PDO::FETCH_ASSOC);
    $clid = $clid['id'];

    if ($clid !== $registrar_id_host) {
        sendEppError($conn, 2201, 'Host belongs to another registrar', $clTRID);
        return;
    }

    $query = "SELECT domain_id FROM domain_host_map WHERE host_id = :host_id LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([':host_id' => $host_id]);
    $nameserver_inuse = $stmt->fetchColumn();

    if ($nameserver_inuse) {
        sendEppError($conn, 2305, 'It is not possible to delete because it is a dependency, it is used by some domain', $clTRID);
        return;
    }

    $query = "DELETE FROM host_addr WHERE host_id = :host_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':host_id' => $host_id]);

    $query = "DELETE FROM host_status WHERE host_id = :host_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':host_id' => $host_id]);

    $query = "DELETE FROM host WHERE id = :host_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':host_id' => $host_id]);

    if ($stmt->errorCode() != '00000') {
        sendEppError($conn, 2400, 'The host was not deleted, it depends on other objects', $clTRID);
        return;
    }

    $response = [
        'command' => 'delete_host',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'clTRID' => $clTRID,
        'svTRID' => generateSvTRID(),
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    sendEppResponse($conn, $xml);
}

function processDomainDelete($conn, $db, $xml, $clid, $database_type) {
    $domainName = $xml->command->delete->children('urn:ietf:params:xml:ns:domain-1.0')->delete->name;
    $clTRID = (string) $xml->command->clTRID;
    
    if (!$domainName) {
        sendEppError($conn, 2003, 'Please specify the domain name that will be deleted', $clTRID);
        return;
    }
	
    if ($database_type === 'mysql') {
        $stmt = $db->prepare("SELECT id, tldid, registrant, crdate, exdate, `update`, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, rgpstatus, addPeriod, autoRenewPeriod, renewPeriod, renewedDate, transferPeriod FROM domain WHERE name = :name LIMIT 1");
    } elseif ($database_type === 'pgsql') {
        $stmt = $db->prepare("SELECT id, tldid, registrant, crdate, exdate, \"update\", clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, rgpstatus, addPeriod, autoRenewPeriod, renewPeriod, renewedDate, transferPeriod FROM domain WHERE name = :name LIMIT 1");
    }
    
    $stmt->execute([':name' => $domainName]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        sendEppError($conn, 2303, 'domain:name does not exist', $clTRID);
        return;
    }

    $domain_id = $result['id'];
    $tldid = $result['tldid'];
    $registrant = $result['registrant'];
    $crdate = $result['crdate'];
    $exdate = $result['exdate'];
    $update = $result['update'];
    $registrar_id_domain = $result['clid'];
    $crid = $result['crid'];
    $upid = $result['upid'];
    $trdate = $result['trdate'];
    $trstatus = $result['trstatus'];
    $reid = $result['reid'];
    $redate = $result['redate'];
    $acid = $result['acid'];
    $acdate = $result['acdate'];
    $rgpstatus = $result['rgpstatus'];
    $addPeriod = $result['addPeriod'];
    $autoRenewPeriod = $result['autoRenewPeriod'];
    $renewPeriod = $result['renewPeriod'];
    $renewedDate = $result['renewedDate'];
    $transferPeriod = $result['transferPeriod'];
    
    $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    $clid = $stmt->fetch(PDO::FETCH_ASSOC);
    $clid = $clid['id'];

    if ($clid != $registrar_id_domain) {
        sendEppError($conn, 2201, 'Domain belongs to another registrar', $clTRID);
        return;
    }

    $stmt = $db->prepare("SELECT status FROM domain_status WHERE domain_id = :domain_id");
    $stmt->execute([':domain_id' => $domain_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        if (preg_match('/.*(UpdateProhibited|DeleteProhibited)$/', $status) || preg_match('/^pending/', $status)) {
            sendEppError($conn, 2304, 'The domain name has a status that does not allow deletion', $clTRID);
            return;
        }
    }

    $grace_period = 30;

    // DELETE FROM `domain_status`
    $stmt = $db->prepare("DELETE FROM domain_status WHERE domain_id = ?");
    $stmt->execute([$domain_id]);

    // UPDATE domain
    $stmt = $db->prepare("UPDATE domain SET rgpstatus = 'redemptionPeriod', delTime = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? DAY) WHERE id = ?");
    $stmt->execute([$grace_period, $domain_id]);

    // INSERT INTO domain_status
    $stmt = $db->prepare("INSERT INTO domain_status (domain_id, status) VALUES(?, 'pendingDelete')");
    $stmt->execute([$domain_id]);

    if ($rgpstatus) {
        if ($rgpstatus === 'addPeriod') {
            $stmt = $db->prepare("SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP < DATE_ADD(crdate, INTERVAL 5 DAY)) LIMIT 1");
            $stmt->execute([$domain_id]);
            $addPeriod_id = $stmt->fetchColumn();

            if ($addPeriod_id) {
                $stmt = $db->prepare("SELECT m$addPeriod FROM domain_price WHERE tldid = ? AND command = 'create' LIMIT 1");
                $stmt->execute([$tldid]);
                $price = $stmt->fetchColumn();
        
                if (!isset($price)) {
                    sendEppError($conn, 2400, 'The price, period and currency for such TLD are not declared', $clTRID);
                    return;
                }

                // Update registrar
                $stmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?");
                $stmt->execute([$price, $clid]);

                // Insert into payment_history
                $description = "domain name is deleted by the registrar during grace addPeriod, the registry provides a credit for the cost of the registration domain $domainName for period $addPeriod MONTH";
                $stmt = $db->prepare("INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP, ?, ?)");
                $stmt->execute([$clid, $description, $price]);

                // Fetch host ids
                $stmt = $db->prepare("SELECT id FROM host WHERE domain_id = ?");
                $stmt->execute([$domain_id]);

                while ($host_id = $stmt->fetchColumn()) {
                    $db->exec("DELETE FROM host_addr WHERE host_id = $host_id");
                    $db->exec("DELETE FROM host_status WHERE host_id = $host_id");
                    $db->exec("DELETE FROM domain_host_map WHERE host_id = $host_id");
                }

                // Delete domain related records
                $db->exec("DELETE FROM domain_contact_map WHERE domain_id = $domain_id");
                $db->exec("DELETE FROM domain_host_map WHERE domain_id = $domain_id");
                $db->exec("DELETE FROM domain_authInfo WHERE domain_id = $domain_id");
                $db->exec("DELETE FROM domain_status WHERE domain_id = $domain_id");
                $db->exec("DELETE FROM host WHERE domain_id = $domain_id");

                $stmt = $db->prepare("DELETE FROM domain WHERE id = ?");
                $stmt->execute([$domain_id]);

                if ($stmt->errorCode() != "00000") {
                    sendEppError($conn, 2400, 'The domain name has not been deleted, it has something to do with other objects', $clTRID);
                    return;
                }

                // Handle statistics
                $curdate_id = $db->query("SELECT id FROM statistics WHERE date = CURDATE()")->fetchColumn();

                if (!$curdate_id) {
                    $db->exec("INSERT IGNORE INTO statistics (date) VALUES(CURDATE())");
                }
        
                $db->exec("UPDATE statistics SET deleted_domains = deleted_domains + 1 WHERE date = CURDATE()");
            }
        } elseif ($rgpstatus === 'autoRenewPeriod') {
            $stmt = $db->prepare("SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP < DATE_ADD(renewedDate, INTERVAL 45 DAY)) LIMIT 1");
            $stmt->execute([$domain_id]);
            $autoRenewPeriod_id = $stmt->fetchColumn();

            if ($autoRenewPeriod_id) {
                $stmt = $db->prepare("SELECT m$autoRenewPeriod FROM domain_price WHERE tldid = ? AND command = 'renew' LIMIT 1");
                $stmt->execute([$tldid]);
                $price = $stmt->fetchColumn();

                if (!isset($price)) {
                    sendEppError($conn, 2400, 'The price, period and currency for such TLD are not declared', $clTRID);
                    return;
                }

                // Update registrar
                $stmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?");
                $stmt->execute([$price, $clid]);

                // Insert into payment_history
                $description = "domain name is deleted by the registrar during grace autoRenewPeriod, the registry provides a credit for the cost of the renewal domain $domainName for period $autoRenewPeriod MONTH";
                $stmt = $db->prepare("INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP, ?, ?)");
                $stmt->execute([$clid, $description, $price]);
            }
        } elseif ($rgpstatus === 'renewPeriod') {
            $stmt = $db->prepare("SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP < DATE_ADD(renewedDate, INTERVAL 5 DAY)) LIMIT 1");
            $stmt->execute([$domain_id]);
            $renewPeriod_id = $stmt->fetchColumn();

            if ($renewPeriod_id) {
                $stmt = $db->prepare("SELECT m$renewPeriod FROM domain_price WHERE tldid = ? AND command = 'renew' LIMIT 1");
                $stmt->execute([$tldid]);
                $price = $stmt->fetchColumn();

                if (!isset($price)) {
                    sendEppError($conn, 2400, 'The price, period and currency for such TLD are not declared', $clTRID);
                    return;
                }

                // Update registrar
                $stmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?");
                $stmt->execute([$price, $clid]);

                // Insert into payment_history
                $description = "domain name is deleted by the registrar during grace renewPeriod, the registry provides a credit for the cost of the renewal domain $domainName for period $renewPeriod MONTH";
                $stmt = $db->prepare("INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP, ?, ?)");
                $stmt->execute([$clid, $description, $price]);
            }
        } elseif ($rgpstatus === 'transferPeriod') {
            $stmt = $db->prepare("SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP < DATE_ADD(trdate, INTERVAL 5 DAY)) LIMIT 1");
            $stmt->execute([$domain_id]);
            $transferPeriod_id = $stmt->fetchColumn();

            if ($transferPeriod_id) {
                // Return money if a transfer was also a renew
                if ($transferPeriod > 0) {
                    $stmt = $db->prepare("SELECT m$transferPeriod FROM domain_price WHERE tldid = ? AND command = 'renew' LIMIT 1");
                    $stmt->execute([$tldid]);
                    $price = $stmt->fetchColumn();

                    if (!isset($price)) {
                        sendEppError($conn, 2400, 'The price, period and currency for such TLD are not declared', $clTRID);
                        return;
                    }

                    // Update registrar
                    $stmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?");
                    $stmt->execute([$price, $clid]);

                    // Insert into payment_history
                    $description = "domain name is deleted by the registrar during grace transferPeriod, the registry provides a credit for the cost of the transfer domain $domainName for period $transferPeriod MONTH";
                    $stmt = $db->prepare("INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP, ?, ?)");
                    $stmt->execute([$clid, $description, $price]);
                }
            }
        } 
    }

    $response = [
        'command' => 'delete_domain',
        'resultCode' => 1001,
        'lang' => 'en-US',
        'message' => 'Command completed successfully; action pending',
        'clTRID' => $clTRID,
        'svTRID' => generateSvTRID(),
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    sendEppResponse($conn, $xml);
}