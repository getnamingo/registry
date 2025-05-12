<?php

function processContactDelete($conn, $db, $xml, $clid, $database_type, $trans) {
    $contactID = (string) $xml->command->delete->children('urn:ietf:params:xml:ns:contact-1.0')->delete->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    if (!$contactID) {
        sendEppError($conn, $db, 2003, 'Missing contact:id', $clTRID, $trans);
        return;
    }

    $stmt = $db->prepare("SELECT id, clid FROM contact WHERE identifier = ? LIMIT 1");
    $stmt->execute([$contactID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    $contact_id = $row['id'] ?? null;
    $registrar_id_contact = $row['clid'] ?? null;

    if (!$contact_id) {
        sendEppError($conn, $db, 2303, 'contact:id does not exist', $clTRID, $trans);
        return;
    }
    
    $clid = getClid($db, $clid);
    if ($clid !== $registrar_id_contact) {
        sendEppError($conn, $db, 2201, 'Contact belongs to another registrar', $clTRID, $trans);
        return;
    }

    $stmt = $db->prepare("SELECT id FROM domain WHERE registrant = ? LIMIT 1");
    $stmt->execute([$contact_id]);
    $registrantExists = $stmt->fetchColumn();
    $stmt->closeCursor();

    if ($registrantExists) {
        sendEppError($conn, $db, 2305, 'This contact is associated with a domain as a registrant', $clTRID, $trans);
        return;
    }

    $stmt = $db->prepare("SELECT id FROM domain_contact_map WHERE contact_id = ? LIMIT 1");
    $stmt->execute([$contact_id]);
    $contactInUse = $stmt->fetchColumn();
    $stmt->closeCursor();

    if ($contactInUse) {
        sendEppError($conn, $db, 2305, 'This contact is associated with a domain', $clTRID, $trans);
        return;
    }

    $stmt = $db->prepare("SELECT status FROM contact_status WHERE contact_id = ?");
    $stmt->execute([$contact_id]);

    while ($status = $stmt->fetchColumn()) {
        if (preg_match('/.*(UpdateProhibited|DeleteProhibited)$/', $status) || preg_match('/^pending/', $status)) {
        sendEppError($conn, $db, 2304, 'It has a status that does not allow deletion', $clTRID, $trans);
        return;
        }
    }
    $stmt->closeCursor();

    // Delete associated records
    $db->prepare("DELETE FROM contact_postalInfo WHERE contact_id = ?")->execute([$contact_id]);
    $db->prepare("DELETE FROM contact_authInfo WHERE contact_id = ?")->execute([$contact_id]);
    $db->prepare("DELETE FROM contact_status WHERE contact_id = ?")->execute([$contact_id]);

    $stmt = $db->prepare("DELETE FROM contact WHERE id = ?");
    $stmt->execute([$contact_id]);

    if ($stmt->errorCode() != '00000') {
        sendEppError($conn, $db, 2400, 'Contact was not deleted, it probably has links to other objects', $clTRID, $trans);
        return;
    }

    $svTRID = generateSvTRID();
    $response = [
        'command' => 'delete_contact',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'clTRID' => $clTRID,
        'svTRID' => $svTRID,
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'delete', 'contact', $contactID, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}

function processHostDelete($conn, $db, $xml, $clid, $database_type, $trans) {
    $hostName = $xml->command->delete->children('urn:ietf:params:xml:ns:host-1.0')->delete->name;
    $clTRID = (string) $xml->command->clTRID;

    if (!$hostName) {
        sendEppError($conn, $db, 2003, 'Specify your host name', $clTRID, $trans);
        return;
    }

    // Validation for host name
    if (!validateHostName($hostName)) {
        sendEppError($conn, $db, 2005, 'Invalid host name', $clTRID, $trans);
        return;
    }

    $query = "SELECT id, clid FROM host WHERE name = :name LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([':name' => $hostName]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    $host_id = $result['id'] ?? null;
    $registrar_id_host = $result['clid'] ?? null;

    if (!$host_id) {
        sendEppError($conn, $db, 2303, 'host:name does not exist', $clTRID, $trans);
        return;
    }
    
    $clid = getClid($db, $clid);
    if ($clid !== $registrar_id_host) {
        sendEppError($conn, $db, 2201, 'Host belongs to another registrar', $clTRID, $trans);
        return;
    }

    $query = "SELECT domain_id FROM domain_host_map WHERE host_id = :host_id LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([':host_id' => $host_id]);
    $nameserver_inuse = $stmt->fetchColumn();
    $stmt->closeCursor();

    if ($nameserver_inuse) {
        sendEppError($conn, $db, 2305, 'It is not possible to delete because it is a dependency, it is used by some domain', $clTRID, $trans);
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
        sendEppError($conn, $db, 2400, 'The host was not deleted, it depends on other objects', $clTRID, $trans);
        return;
    }

    $svTRID = generateSvTRID();
    $response = [
        'command' => 'delete_host',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'clTRID' => $clTRID,
        'svTRID' => $svTRID,
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'delete', 'host', $hostName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}

function processDomainDelete($conn, $db, $xml, $clid, $database_type, $trans) {
    $domainName = $xml->command->delete->children('urn:ietf:params:xml:ns:domain-1.0')->delete->name;
    $clTRID = (string) $xml->command->clTRID;
    
    $extensionNode = $xml->command->extension;
    if (isset($extensionNode)) {
        $launch_delete = $xml->xpath('//launch:delete')[0] ?? null;
    }
    
    if (!$domainName) {
        sendEppError($conn, $db, 2003, 'Please specify the domain name that will be deleted', $clTRID, $trans);
        return;
    }
    
    $invalid_domain = validate_label($domainName, $db);

    if ($invalid_domain) {
        sendEppError($conn, $db, 2306, 'Invalid domain:name', $clTRID, $trans);
        return;
    }
    
    if (isset($launch_delete)) {
        $stmt = $db->prepare("SELECT id, tldid, registrant, crdate, exdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, rgpstatus, addPeriod, autoRenewPeriod, renewPeriod, renewedDate, transferPeriod FROM application WHERE name = :name LIMIT 1");
    } else {
        $stmt = $db->prepare("SELECT id, tldid, registrant, crdate, exdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, rgpstatus, addPeriod, autoRenewPeriod, renewPeriod, renewedDate, transferPeriod FROM domain WHERE name = :name LIMIT 1");
    }
    $stmt->execute([':name' => $domainName]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    if (!$result) {
        sendEppError($conn, $db, 2303, 'domain:name does not exist', $clTRID, $trans);
        return;
    }

    $domain_id = $result['id'];
    $tldid = $result['tldid'];
    $registrant = $result['registrant'];
    $crdate = $result['crdate'];
    $exdate = $result['exdate'];
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

    $stmt = $db->prepare("SELECT id, currency FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    $result2 = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $clid = $result2['id'];
    $currency = $result2['currency'];

    if ($clid != $registrar_id_domain) {
        sendEppError($conn, $db, 2201, 'Domain belongs to another registrar', $clTRID, $trans);
        return;
    }

    if (!isset($launch_delete)) {
        $stmt = $db->prepare("SELECT status FROM domain_status WHERE domain_id = :domain_id");
        $stmt->execute([':domain_id' => $domain_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $status = $row['status'];
            if (preg_match('/.*(UpdateProhibited|DeleteProhibited)$/', $status) || preg_match('/^pending/', $status)) {
                sendEppError($conn, $db, 2304, 'The domain name has a status that does not allow deletion', $clTRID, $trans);
                return;
            }
        }
        $stmt->closeCursor();
    }
    
    if (isset($launch_delete)) {
        $phaseType = (string) $launch_delete->children('urn:ietf:params:xml:ns:launch-1.0')->phase;
        $applicationID = (string) $launch_delete->children('urn:ietf:params:xml:ns:launch-1.0')->applicationID;
        
        $stmt = $db->prepare("SELECT id FROM application WHERE name = ? AND phase_type = ? AND application_id = ? LIMIT 1");
        $stmt->execute([$domainName, $phaseType, $applicationID]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$result) {
            sendEppError($conn, $db, 2306, "Please verify the launch phase and/or the application ID", $clTRID, $trans);
            return;
        }

        // Delete application related records
        $db->exec("DELETE FROM application_contact_map WHERE domain_id = $domain_id");
        $db->exec("DELETE FROM application_host_map WHERE domain_id = $domain_id");
        $db->exec("DELETE FROM application_status WHERE domain_id = $domain_id");

        $stmt = $db->prepare("DELETE FROM application WHERE id = ?");
        $stmt->execute([$domain_id]);

        if ($stmt->errorCode() != "00000") {
            sendEppError($conn, $db, 2400, 'The application has not been deleted, it has something to do with other objects', $clTRID, $trans);
            return;
        }
        
        $svTRID = generateSvTRID();
        $response = [
            'command' => 'delete_domain',
            'resultCode' => 1000,
            'lang' => 'en-US',
            'message' => 'Command completed successfully',
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
        ];

    } else {
        $grace_period = 30;

        // DELETE FROM domain_status
        $stmt = $db->prepare("DELETE FROM domain_status WHERE domain_id = ?");
        $stmt->execute([$domain_id]);

        // UPDATE domain
        $stmt = $db->prepare("UPDATE domain SET rgpstatus = 'redemptionPeriod', delTime = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL ? DAY) WHERE id = ?");
        $stmt->execute([$grace_period, $domain_id]);

        // INSERT INTO domain_status
        $stmt = $db->prepare("INSERT INTO domain_status (domain_id, status) VALUES(?, 'pendingDelete')");
        $stmt->execute([$domain_id]);

        if ($rgpstatus) {
            if ($rgpstatus === 'addPeriod') {
                $stmt = $db->prepare("SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(crdate, INTERVAL 5 DAY)) LIMIT 1");
                $stmt->execute([$domain_id]);
                $addPeriod_id = $stmt->fetchColumn();
                $stmt->closeCursor();

                if ($addPeriod_id) {
                    $returnValue = getDomainPrice($db, $domainName, $tldid, $addPeriod, 'create', $clid, $currency);
                    $price = $returnValue['price'];
            
                    if (!isset($price)) {
                        sendEppError($conn, $db, 2400, 'The price, period and currency for such TLD are not declared', $clTRID, $trans);
                        return;
                    }

                    // Update registrar
                    $stmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?");
                    $stmt->execute([$price, $clid]);

                    // Insert into payment_history
                    $description = "domain name is deleted by the registrar during grace addPeriod, the registry provides a credit for the cost of the registration domain $domainName for period $addPeriod MONTH";
                    $stmt = $db->prepare("INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)");
                    $stmt->execute([$clid, $description, $price]);

                    // Fetch host ids
                    $stmt = $db->prepare("SELECT id FROM host WHERE domain_id = ?");
                    $stmt->execute([$domain_id]);

                    while ($host_id = $stmt->fetchColumn()) {
                        $db->exec("DELETE FROM host_addr WHERE host_id = $host_id");
                        $db->exec("DELETE FROM host_status WHERE host_id = $host_id");
                        $db->exec("DELETE FROM domain_host_map WHERE host_id = $host_id");
                    }
                    $stmt->closeCursor();

                    // Delete domain related records
                    $db->exec("DELETE FROM domain_contact_map WHERE domain_id = $domain_id");
                    $db->exec("DELETE FROM domain_host_map WHERE domain_id = $domain_id");
                    $db->exec("DELETE FROM domain_authInfo WHERE domain_id = $domain_id");
                    $db->exec("DELETE FROM domain_status WHERE domain_id = $domain_id");
                    $db->exec("DELETE FROM secdns WHERE domain_id = $domain_id");
                    $db->exec("DELETE FROM host WHERE domain_id = $domain_id");

                    $stmt = $db->prepare("DELETE FROM domain WHERE id = ?");
                    $stmt->execute([$domain_id]);

                    if ($stmt->errorCode() != "00000") {
                        sendEppError($conn, $db, 2400, 'The domain name has not been deleted, it has something to do with other objects', $clTRID, $trans);
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
                $stmt = $db->prepare("SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(renewedDate, INTERVAL 45 DAY)) LIMIT 1");
                $stmt->execute([$domain_id]);
                $autoRenewPeriod_id = $stmt->fetchColumn();
                $stmt->closeCursor();

                if ($autoRenewPeriod_id) {
                    $returnValue = getDomainPrice($db, $domainName, $tldid, $autoRenewPeriod, 'renew', $clid, $currency);
                    $price = $returnValue['price'];

                    if (!isset($price)) {
                        sendEppError($conn, $db, 2400, 'The price, period and currency for such TLD are not declared', $clTRID, $trans);
                        return;
                    }

                    // Update registrar
                    $stmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?");
                    $stmt->execute([$price, $clid]);

                    // Insert into payment_history
                    $description = "domain name is deleted by the registrar during grace autoRenewPeriod, the registry provides a credit for the cost of the renewal domain $domainName for period $autoRenewPeriod MONTH";
                    $stmt = $db->prepare("INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)");
                    $stmt->execute([$clid, $description, $price]);
                }
            } elseif ($rgpstatus === 'renewPeriod') {
                $stmt = $db->prepare("SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(renewedDate, INTERVAL 5 DAY)) LIMIT 1");
                $stmt->execute([$domain_id]);
                $renewPeriod_id = $stmt->fetchColumn();
                $stmt->closeCursor();

                if ($renewPeriod_id) {
                    $returnValue = getDomainPrice($db, $domainName, $tldid, $renewPeriod, 'renew', $clid, $currency);
                    $price = $returnValue['price'];

                    if (!isset($price)) {
                        sendEppError($conn, $db, 2400, 'The price, period and currency for such TLD are not declared', $clTRID, $trans);
                        return;
                    }

                    // Update registrar
                    $stmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?");
                    $stmt->execute([$price, $clid]);

                    // Insert into payment_history
                    $description = "domain name is deleted by the registrar during grace renewPeriod, the registry provides a credit for the cost of the renewal domain $domainName for period $renewPeriod MONTH";
                    $stmt = $db->prepare("INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)");
                    $stmt->execute([$clid, $description, $price]);
                }
            } elseif ($rgpstatus === 'transferPeriod') {
                $stmt = $db->prepare("SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(trdate, INTERVAL 5 DAY)) LIMIT 1");
                $stmt->execute([$domain_id]);
                $transferPeriod_id = $stmt->fetchColumn();
                $stmt->closeCursor();

                if ($transferPeriod_id) {
                    // Return money if a transfer was also a renew
                    if ($transferPeriod > 0) {
                        $returnValue = getDomainPrice($db, $domainName, $tldid, $transferPeriod, 'renew', $clid, $currency);
                        $price = $returnValue['price'];

                        if (!isset($price)) {
                            sendEppError($conn, $db, 2400, 'The price, period and currency for such TLD are not declared', $clTRID, $trans);
                            return;
                        }

                        // Update registrar
                        $stmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?");
                        $stmt->execute([$price, $clid]);

                        // Insert into payment_history
                        $description = "domain name is deleted by the registrar during grace transferPeriod, the registry provides a credit for the cost of the transfer domain $domainName for period $transferPeriod MONTH";
                        $stmt = $db->prepare("INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)");
                        $stmt->execute([$clid, $description, $price]);
                    }
                }
            } 
        }
        
        $svTRID = generateSvTRID();
        $response = [
            'command' => 'delete_domain',
            'resultCode' => 1001,
            'lang' => 'en-US',
            'message' => 'Command completed successfully; action pending',
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
        ];
    }

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'delete', 'domain', $domainName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}