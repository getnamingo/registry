<?php

function processContactTransfer($conn, $db, $xml, $clid, $config, $trans) {
    // $config['db_type'] for future
    $contactID = (string) $xml->command->transfer->children('urn:ietf:params:xml:ns:contact-1.0')->transfer->{'id'};
    $clTRID = (string) $xml->command->clTRID;
    $opNode = $xml->xpath('//@op');
    $op = isset($opNode[0]) ? (string)$opNode[0] : null;

    $objList = $xml->xpath('//contact:transfer');
    $obj = isset($objList[0]) ? $objList[0] : null;

    // authInfo is OPTIONAL for most ops, REQUIRED for request
    $authInfo_pw = null;
    if ($obj) {
        $authNode = $obj->xpath('contact:authInfo/contact:pw[1]');
        if ($authNode && isset($authNode[0])) {
            $authInfo_pw = trim((string)$authNode[0]);
        }
    }

    // Validate op value early
    $validOps = ['approve', 'cancel', 'query', 'reject', 'request'];
    if (!$op || !in_array($op, $validOps, true)) {
        sendEppError($conn, $db, 2005, 'Only op: approve|cancel|query|reject|request are accepted', $clTRID, $trans);
        return;
    }

    if (!$contactID) {
        sendEppError($conn, $db, 2003, 'Contact ID was not provided', $clTRID, $trans);
        return;
    }

    $invalid_identifier = validate_identifier($contactID);
    if ($invalid_identifier) {
        sendEppError($conn, $db, 2005, 'Invalid contact ID', $clTRID, $trans);
        return;
    }

    $identifier = strtoupper($contactID);
    $stmt = $db->prepare("SELECT id, clid FROM contact WHERE identifier = :identifier LIMIT 1");
    $stmt->execute([':identifier' => $identifier]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $contact_id = $result['id'] ?? null;
    $registrar_id_contact = $result['clid'] ?? null;

    if (!$contact_id) {
        sendEppError($conn, $db, 2303, 'Contact does not exist', $clTRID, $trans);
        return;
    }

    $clid = getClid($db, $clid);

    if ($op === 'approve') {
        if ($clid !== $registrar_id_contact) {
            sendEppError($conn, $db, 2201, 'Only the losing registrar can approve', $clTRID, $trans);
            return;
        }

        if ($authInfo_pw) {
            $stmt = $db->prepare("SELECT id FROM contact_authInfo WHERE contact_id = :contact_id AND authtype = 'pw' AND authinfo = :authInfo_pw LIMIT 1");
            $stmt->execute([
                ':contact_id' => $contact_id,
                ':authInfo_pw' => $authInfo_pw
            ]);
            $contact_authinfo_id = $stmt->fetchColumn();
            $stmt->closeCursor();

            if (!$contact_authinfo_id) {
                sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                return;
            }
        }

        $stmt = $db->prepare("SELECT crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = :contact_id LIMIT 1");
        $stmt->execute([':contact_id' => $contact_id]);
        $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $trstatus = $contactInfo['trstatus'] ?? '';

        if ($trstatus === 'pending') {
            $stmt = $db->prepare("UPDATE contact SET lastupdate = CURRENT_TIMESTAMP(3), clid = :reid, upid = :upid, trdate = CURRENT_TIMESTAMP(3), trstatus = 'clientApproved', acdate = CURRENT_TIMESTAMP(3) WHERE id = :contact_id");
            $stmt->execute([
                ':reid' => $contactInfo['reid'],
                ':upid' => $clid,
                ':contact_id' => $contact_id
            ]);

            if ($stmt->errorCode() != 0) {
                sendEppError($conn, $db, 2400, 'The transfer was not approved successfully, something is wrong', $clTRID, $trans);
                return;
            } else {
                $stmt = $db->prepare("SELECT crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = :contact_id LIMIT 1");
                $stmt->execute([':contact_id' => $contact_id]);
                $updatedContactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                $reid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                $reid_identifier_stmt->execute([':reid' => $updatedContactInfo['reid']]);
                $reid_identifier = $reid_identifier_stmt->fetchColumn();
                $reid_identifier_stmt->closeCursor();

                $acid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                $acid_identifier_stmt->execute([':acid' => $updatedContactInfo['acid']]);
                $acid_identifier = $acid_identifier_stmt->fetchColumn();
                $acid_identifier_stmt->closeCursor();
                
                $svTRID = generateSvTRID();
                $response = [
                    'command' => 'transfer_contact',
                    'resultCode' => 1000,
                    'lang' => 'en-US',
                    'message' => 'Command completed successfully',
                    'id' => $identifier,
                    'trStatus' => $updatedContactInfo['trstatus'],
                    'reID' => $reid_identifier,
                    'reDate' => $updatedContactInfo['redate'],
                    'acID' => $acid_identifier,
                    'acDate' => $updatedContactInfo['acdate'],
                    'clTRID' => $clTRID,
                    'svTRID' => $svTRID,
                ];

                $epp = new EPP\EppWriter();
                $xml = $epp->epp_writer($response);
                updateTransaction($db, 'transfer', 'contact', $identifier, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
                sendEppResponse($conn, $xml);
            }
        } else {
            sendEppError($conn, $db, 2301, 'Command failed because the contact is NOT pending transfer', $clTRID, $trans);
            return;
        }
    } elseif ($op === 'cancel') {
        // Only the requesting or 'Gaining' Registrar can cancel
        if ($clid === $registrar_id_contact) {
            sendEppError($conn, $db, 2201, 'Only the applicant can cancel', $clTRID, $trans);
            return;
        }

        // A <contact:authInfo> element that contains authorization information associated with the contact object.
        if ($authInfo_pw) {
            $stmt = $db->prepare("SELECT id FROM contact_authInfo WHERE contact_id = :contact_id AND authtype = 'pw' AND authinfo = :authInfo_pw LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id, ':authInfo_pw' => $authInfo_pw]);
            $contact_authinfo_id = $stmt->fetchColumn();
            $stmt->closeCursor();
            if (!$contact_authinfo_id) {
                sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                return;
            }
        }

        $stmt = $db->prepare("SELECT crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = :contact_id LIMIT 1");
        $stmt->execute([':contact_id' => $contact_id]);
        $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $trstatus = $contactInfo['trstatus'] ?? '';

        if ($trstatus === 'pending') {
            $stmt = $db->prepare("UPDATE contact SET trstatus = 'clientCancelled' WHERE id = :contact_id");
            $stmt->execute([':contact_id' => $contact_id]);

            if ($stmt->errorCode() != 0) {
                sendEppError($conn, $db, 2400, 'The transfer was not canceled successfully, something is wrong', $clTRID, $trans);
                return;
            } else {
                $stmt = $db->prepare("SELECT crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = :contact_id LIMIT 1");
                $stmt->execute([':contact_id' => $contact_id]);
                $updatedContactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                $reid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                $reid_identifier_stmt->execute([':reid' => $updatedContactInfo['reid']]);
                $reid_identifier = $reid_identifier_stmt->fetchColumn();
                $reid_identifier_stmt->closeCursor();

                $acid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                $acid_identifier_stmt->execute([':acid' => $updatedContactInfo['acid']]);
                $acid_identifier = $acid_identifier_stmt->fetchColumn();
                $acid_identifier_stmt->closeCursor();
                
                $svTRID = generateSvTRID();
                $response = [
                    'command' => 'transfer_contact',
                    'resultCode' => 1000,
                    'lang' => 'en-US',
                    'message' => 'Command completed successfully',
                    'id' => $identifier,
                    'trStatus' => $updatedContactInfo['trstatus'],
                    'reID' => $reid_identifier,
                    'reDate' => $updatedContactInfo['redate'],
                    'acID' => $acid_identifier,
                    'acDate' => $updatedContactInfo['acdate'],
                    'clTRID' => $clTRID,
                    'svTRID' => $svTRID,
                ];

                $epp = new EPP\EppWriter();
                $xml = $epp->epp_writer($response);
                updateTransaction($db, 'transfer', 'contact', $identifier, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
                sendEppResponse($conn, $xml);
            }
        } else {
            sendEppError($conn, $db, 2301, 'Command failed because the contact is NOT pending transfer', $clTRID, $trans);
            return;
        }
    } elseif ($op === 'query') {
        $stmt = $db->prepare("SELECT crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = :contact_id LIMIT 1");
        $stmt->execute([':contact_id' => $contact_id]);
        $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $trstatus = $contactInfo['trstatus'] ?? '';

        if ($trstatus === 'pending') {
            $reid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
            $reid_identifier_stmt->execute([':reid' => $contactInfo['reid']]);
            $reid_identifier = $reid_identifier_stmt->fetchColumn();
            $reid_identifier_stmt->closeCursor();

            $acid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
            $acid_identifier_stmt->execute([':acid' => $contactInfo['acid']]);
            $acid_identifier = $acid_identifier_stmt->fetchColumn();
            $acid_identifier_stmt->closeCursor();
            
            $svTRID = generateSvTRID();
            $response = [
                'command' => 'transfer_contact',
                'resultCode' => 1000,
                'lang' => 'en-US',
                'message' => 'Command completed successfully',
                'id' => $identifier,
                'trStatus' => $trstatus,
                'reID' => $reid_identifier,
                'reDate' => $contactInfo['redate'],
                'acID' => $acid_identifier,
                'acDate' => $contactInfo['acdate'],
                'clTRID' => $clTRID,
                'svTRID' => $svTRID,
            ];

            $epp = new EPP\EppWriter();
            $xml = $epp->epp_writer($response);
            updateTransaction($db, 'transfer', 'contact', $identifier, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
            sendEppResponse($conn, $xml);
        } else {
            sendEppError($conn, $db, 2301, 'Command failed because the contact is NOT pending transfer', $clTRID, $trans);
            return;
        }
    } elseif ($op === 'reject') {
        // Only the LOSING REGISTRAR can approve or reject
        if ($clid !== $registrar_id_contact) {
            sendEppError($conn, $db, 2201, 'Only the losing registrar can reject', $clTRID, $trans);
            return;
        }

        // A <contact:authInfo> element that contains authorization information associated with the contact object.
        if ($authInfo_pw) {
            $stmt = $db->prepare("SELECT id FROM contact_authInfo WHERE contact_id = :contact_id AND authtype = 'pw' AND authinfo = :authInfo_pw LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id, ':authInfo_pw' => $authInfo_pw]);
            $contact_authinfo_id = $stmt->fetchColumn();
            $stmt->closeCursor();

            if (!$contact_authinfo_id) {
                sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                return;
            }
        }

        $stmt = $db->prepare("SELECT crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = :contact_id LIMIT 1");
        $stmt->execute([':contact_id' => $contact_id]);
        $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($contactInfo['trstatus'] === 'pending') {
            // The losing registrar has five days once the contact is pending to respond.
            $updateStmt = $db->prepare("UPDATE contact SET trstatus = 'clientRejected' WHERE id = :contact_id");
            $updateStmt->execute([':contact_id' => $contact_id]);

            if ($updateStmt->errorCode() !== '00000') {
                sendEppError($conn, $db, 2400, 'The transfer was not successfully rejected, something is wrong', $clTRID, $trans);
                return;
            } else {
                $stmt = $db->prepare("SELECT crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = :contact_id LIMIT 1");
                $stmt->execute([':contact_id' => $contact_id]);
                $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                // Fetch registrar identifiers
                $reidStmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                $reidStmt->execute([':reid' => $contactInfo['reid']]);
                $reid_identifier = $reidStmt->fetchColumn();
                $reidStmt->closeCursor();

                $acidStmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                $acidStmt->execute([':acid' => $contactInfo['acid']]);
                $acid_identifier = $acidStmt->fetchColumn();
                $acidStmt->closeCursor();
                
                $svTRID = generateSvTRID();
                $response = [
                    'command' => 'transfer_contact',
                    'resultCode' => 1000,
                    'lang' => 'en-US',
                    'message' => 'Command completed successfully',
                    'id' => $identifier,
                    'trStatus' => $contactInfo['trstatus'],
                    'reID' => $reid_identifier,
                    'reDate' => $contactInfo['redate'],
                    'acID' => $acid_identifier,
                    'acDate' => $contactInfo['acdate'],
                    'clTRID' => $clTRID,
                    'svTRID' => $svTRID,
                ];

                $epp = new EPP\EppWriter();
                $xml = $epp->epp_writer($response);
                updateTransaction($db, 'transfer', 'contact', $identifier, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
                sendEppResponse($conn, $xml);
            }
        } else {
            sendEppError($conn, $db, 2301, 'Command failed because the contact is NOT pending transfer', $clTRID, $trans);
            return;
        }
    } elseif ($op == 'request') {
        if (!$authInfo_pw) {
            sendEppError($conn, $db, 2003, 'Missing contact authInfo pw for transfer request', $clTRID, $trans);
            return;
        }

        if (!($config['disable_60days'] ?? false)) {
            // Check if contact is within 60 days of its initial registration
            $stmt = $db->prepare("SELECT DATEDIFF(CURRENT_TIMESTAMP(3),crdate) FROM contact WHERE id = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $days_from_registration = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($days_from_registration < 60) {
                sendEppError($conn, $db, 2201, 'The contact name must not be within 60 days of its initial registration', $clTRID, $trans);
                return;
            }

            // Check if contact is within 60 days of its last transfer
            $stmt = $db->prepare("SELECT trdate, DATEDIFF(CURRENT_TIMESTAMP(3),trdate) AS intval FROM contact WHERE id = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            $last_trdate = $result['trdate'];
            $days_from_last_transfer = $result['intval'];

            if ($last_trdate && $days_from_last_transfer < 60) {
                sendEppError($conn, $db, 2201, 'The contact name must not be within 60 days of its last transfer from another registrar', $clTRID, $trans);
                return;
            }
        }

        // Check the <contact:authInfo> element
        $stmt = $db->prepare("SELECT id FROM contact_authInfo WHERE contact_id = :contact_id AND authtype = 'pw' AND authinfo = :authInfo_pw LIMIT 1");
        $stmt->execute([':contact_id' => $contact_id, ':authInfo_pw' => $authInfo_pw]);
        $contact_authinfo_id = $stmt->fetchColumn();
        $stmt->closeCursor();

        if (!$contact_authinfo_id) {
            sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
            return;
        }

        $stmt = $db->prepare("SELECT status FROM contact_status WHERE contact_id = :contact_id");
        $stmt->execute([':contact_id' => $contact_id]);
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor();

        foreach ($statuses as $status) {
            if (preg_match('/TransferProhibited$/', $status) || preg_match('/^pending/', $status)) {
                sendEppError($conn, $db, 2304, 'It has a status that does not allow the transfer, first change the status', $clTRID, $trans);
                return;
            }
        }

        if ($clid == $registrar_id_contact) {
            sendEppError($conn, $db, 2106, 'Destination client of the transfer operation is the contact sponsoring client', $clTRID, $trans);
            return;
        }

        $stmt = $db->prepare("SELECT crid,crdate,upid,lastupdate,trdate,trstatus,reid,redate,acid,acdate FROM contact WHERE id = :contact_id LIMIT 1");
        $stmt->execute([':contact_id' => $contact_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $trstatus = $result['trstatus'];

        if (!$trstatus || $trstatus != 'pending') {
            try {
                $db->beginTransaction();

                $waiting_period = 5; // days
                $stmt = $db->prepare("UPDATE contact SET trstatus = 'pending', reid = :registrar_id, redate = CURRENT_TIMESTAMP(3), acid = :registrar_id_contact, acdate = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL $waiting_period DAY) WHERE id = :contact_id");
                $stmt->execute([
                    ':registrar_id' => $clid,
                    ':registrar_id_contact' => $registrar_id_contact,
                    ':contact_id' => $contact_id
                ]);

                $stmt = $db->prepare("SELECT crid,crdate,upid,lastupdate,trdate,trstatus,reid,redate,acid,acdate FROM contact WHERE id = :contact_id LIMIT 1");
                $stmt->execute([':contact_id' => $contact_id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                $reid_identifier = $db->query("SELECT clid FROM registrar WHERE id = '{$result['reid']}' LIMIT 1")->fetchColumn();
                $acid_identifier = $db->query("SELECT clid FROM registrar WHERE id = '{$result['acid']}' LIMIT 1")->fetchColumn();

                $db->prepare("INSERT INTO poll (registrar_id,qdate,msg,msg_type,obj_name_or_id,obj_trStatus,obj_reID,obj_reDate,obj_acID,obj_acDate,obj_exDate) VALUES(:registrar_id_contact, CURRENT_TIMESTAMP(3), 'Transfer requested.', 'contactTransfer', :identifier, 'pending', :reid_identifier, :redate, :acid_identifier, :acdate, NULL)")
                    ->execute([
                        ':registrar_id_contact' => $registrar_id_contact,
                        ':identifier' => $identifier,
                        ':reid_identifier' => $reid_identifier,
                        ':redate' => $result['redate'],
                        ':acid_identifier' => $acid_identifier,
                        ':acdate' => $result['acdate']
                    ]);

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                sendEppError($conn, $db, 2400, 'Database error during contact transfer request', $clTRID, $trans);
                return;
            }

            $svTRID = generateSvTRID();
            $response = [
                'command' => 'transfer_contact',
                'resultCode' => 1000,
                'lang' => 'en-US',
                'message' => 'Command completed successfully',
                'id' => $identifier,
                'trStatus' => $result['trstatus'],
                'reID' => $reid_identifier,
                'reDate' => $result['redate'],
                'acID' => $acid_identifier,
                'acDate' => $result['acdate'],
                'clTRID' => $clTRID,
                'svTRID' => $svTRID,
            ];

            $epp = new EPP\EppWriter();
            $xml = $epp->epp_writer($response);
            updateTransaction($db, 'transfer', 'contact', $identifier, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
            sendEppResponse($conn, $xml);
        } else {
            sendEppError($conn, $db, 2300, 'Command failed because the contact is pending transfer', $clTRID, $trans);
            return;
        }
    } else {
        sendEppError($conn, $db, 2005, 'Only op: approve|cancel|query|reject|request are accepted', $clTRID, $trans);
        return;
    }
}

function processDomainTransfer($conn, $db, $xml, $clid, $config, $trans) {
    // $config['db_type'] for future
    $domainName = (string) $xml->command->transfer->children('urn:ietf:params:xml:ns:domain-1.0')->transfer->name;
    $opNode = $xml->xpath('//@op');
    $op = isset($opNode[0]) ? (string)$opNode[0] : null;

    $clTRID = (string) $xml->command->clTRID;

    // Validate op early
    $validOps = ['approve', 'cancel', 'query', 'reject', 'request'];
    if (!$op || !in_array($op, $validOps, true)) {
        sendEppError($conn, $db, 2005, 'Only op: approve|cancel|query|reject|request are accepted', $clTRID, $trans);
        return;
    }

    $extensionNode = $xml->command->extension;
    if (isset($extensionNode)) {
        $allocation_token = $xml->xpath('//allocationToken:allocationToken')[0] ?? null;
    }

    // An OPTIONAL <domain:authInfo> for op="query" and mandatory for other op values "approve|cancel|reject|request"
    $result = $xml->xpath('//domain:authInfo/domain:pw[1]');
    $authInfo_pw = $result ? (string)$result[0] : null;

    if (!$domainName) {
        sendEppError($conn, $db, 2003, 'Please provide the domain name', $clTRID, $trans);
        return;
    }

    // Validate domain syntax (same rules as info/renew)
    $invalid_label = validate_label($domainName, $db);
    if ($invalid_label || !filter_var($domainName, FILTER_VALIDATE_DOMAIN)) {
        sendEppError($conn, $db, 2005, 'Invalid domain name', $clTRID, $trans);
        return;
    }

    if ($op === 'request' && (!$authInfo_pw || $authInfo_pw === '')) {
        sendEppError($conn, $db, 2003, 'Missing domain authInfo pw for this transfer op', $clTRID, $trans);
        return;
    }

    $stmt = $db->prepare("SELECT id,tldid,clid FROM domain WHERE name = :name LIMIT 1");
    $stmt->bindParam(':name', $domainName, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    $domain_id = $row['id'] ?? null;
    $tldid = $row['tldid'] ?? null;
    $registrar_id_domain = $row['clid'] ?? null;

    if (!$domain_id) {
        sendEppError($conn, $db, 2303, 'Domain does not exist in registry', $clTRID, $trans);
        return;
    }
    
    $clid = getClid($db, $clid);

    if ($op === 'approve') {
        if ($clid !== $registrar_id_domain) {
            sendEppError($conn, $db, 2201, 'Only LOSING REGISTRAR can approve', $clTRID, $trans);
            return;
        }

        if ($authInfo_pw) {
            $stmt = $db->prepare("SELECT id FROM domain_authInfo WHERE domain_id = ? AND authtype = 'pw' AND authinfo = ? LIMIT 1");
            $stmt->execute([$domain_id, $authInfo_pw]);
            $domain_authinfo_id = $stmt->fetchColumn();
            $stmt->closeCursor();

            if (!$domain_authinfo_id) {
                sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                return;
            }
        }

        $stmt = $db->prepare("SELECT id,registrant,crdate,exdate,lastupdate,clid,crid,upid,trdate,trstatus,reid,redate,acid,acdate,transfer_exdate FROM domain WHERE name = ? LIMIT 1");
        $stmt->execute([$domainName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($row && $row["trstatus"] === 'pending') {
            try {
                $db->beginTransaction();

                $date_add = 0;
                $price = 0;

                $stmt = $db->prepare("SELECT accountBalance,creditLimit FROM registrar WHERE id = ? LIMIT 1");
                $stmt->execute([$row["reid"]]);
                list($registrar_balance, $creditLimit) = $stmt->fetch(PDO::FETCH_NUM);
                $stmt->closeCursor();

                if ($row["transfer_exdate"]) {
                    $stmt = $db->prepare("SELECT PERIOD_DIFF(DATE_FORMAT(transfer_exdate, '%Y%m'), DATE_FORMAT(exdate, '%Y%m')) AS intval FROM domain WHERE name = ? LIMIT 1");
                    $stmt->execute([$domainName]);
                    $date_add = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    $stmt = $db->prepare("SELECT currency FROM registrar WHERE id = :registrar_id LIMIT 1");
                    $stmt->execute([':registrar_id' => $clid]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                    $currency = $result["currency"];

                    $returnValue = getDomainPrice($db, $domainName, $tldid, $date_add, 'transfer', $clid, $currency);
                    $price = $returnValue['price'] ?? null;

                    if (!isset($price)) {
                        sendEppError($conn, $db, 2400, 'The price, period and currency for such TLD are not declared', $clTRID, $trans);
                        return;
                    }

                    if (($registrar_balance + $creditLimit) < $price) {
                        sendEppError($conn, $db, 2104, 'The registrar who took over this domain has no money to pay the renewal period that resulted from the transfer request', $clTRID, $trans);
                        return;
                    }
                }
                
                if (!($config['minimum_data'] ?? false)) {
                    // Fetch contact map
                    $stmt = $db->prepare('SELECT contact_id, type FROM domain_contact_map WHERE domain_id = ?');
                    $stmt->execute([$domain_id]);
                    $contactMap = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();

                    // Prepare an array to hold new contact IDs to prevent duplicating contacts
                    $newContactIds = [];

                    // Copy registrant data
                    $stmt = $db->prepare('SELECT * FROM contact WHERE id = ?');
                    $stmt->execute([$row['registrant']]);
                    $registrantData = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                    unset($registrantData['id']);
                    $registrantData['identifier'] = generateAuthInfo();
                    $registrantData['clid'] = $row['reid'];

                    $stmt = $db->prepare('INSERT INTO contact (' . implode(', ', array_keys($registrantData)) . ') VALUES (:' . implode(', :', array_keys($registrantData)) . ')');
                    foreach ($registrantData as $key => $value) {
                        $stmt->bindValue(':' . $key, $value);
                    }
                    $stmt->execute();
                    $newRegistrantId = $db->lastInsertId();
                    $newContactIds[$row['registrant']] = $newRegistrantId;

                    // Copy postal info for the registrant
                    $stmt = $db->prepare('SELECT * FROM contact_postalInfo WHERE contact_id = ?');
                    $stmt->execute([$row['registrant']]);
                    $postalInfos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();

                    foreach ($postalInfos as $postalInfo) {
                        unset($postalInfo['id']);
                        $postalInfo['contact_id'] = $newRegistrantId;
                        $columns = array_keys($postalInfo);
                        $stmt = $db->prepare('INSERT INTO contact_postalInfo (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
                        foreach ($postalInfo as $key => $value) {
                            $stmt->bindValue(':' . $key, $value);
                        }
                        $stmt->execute();
                    }

                    // Insert auth info and status for the new registrant
                    $new_authinfo = generateAuthInfo();
                    $db->prepare('INSERT INTO contact_authInfo (contact_id, authtype, authinfo) VALUES (?, ?, ?)')->execute([$newRegistrantId, 'pw', $new_authinfo]);
                    $db->prepare('INSERT INTO contact_status (contact_id, status) VALUES (?, ?)')->execute([$newRegistrantId, 'ok']);

                    // Process each contact in the contact map
                    foreach ($contactMap as $contact) {
                        if (!array_key_exists($contact['contact_id'], $newContactIds)) {
                            $stmt = $db->prepare('SELECT * FROM contact WHERE id = ?');
                            $stmt->execute([$contact['contact_id']]);
                            $contactData = $stmt->fetch(PDO::FETCH_ASSOC);
                            $stmt->closeCursor();
                            unset($contactData['id']);
                            $contactData['identifier'] = generateAuthInfo();
                            $contactData['clid'] = $row["reid"];

                            $stmt = $db->prepare('INSERT INTO contact (' . implode(', ', array_keys($contactData)) . ') VALUES (:' . implode(', :', array_keys($contactData)) . ')');
                            foreach ($contactData as $key => $value) {
                                $stmt->bindValue(':' . $key, $value);
                            }
                            $stmt->execute();
                            $newContactId = $db->lastInsertId();
                            $newContactIds[$contact['contact_id']] = $newContactId;

                            // Repeat postal info and auth info/status insertion for each new contact
                            $stmt = $db->prepare('SELECT * FROM contact_postalInfo WHERE contact_id = ?');
                            $stmt->execute([$contact['contact_id']]);
                            $postalInfos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            $stmt->closeCursor();

                            foreach ($postalInfos as $postalInfo) {
                                unset($postalInfo['id']);
                                $postalInfo['contact_id'] = $newContactId;
                                $columns = array_keys($postalInfo);
                                $stmt = $db->prepare('INSERT INTO contact_postalInfo (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
                                foreach ($postalInfo as $key => $value) {
                                    $stmt->bindValue(':' . $key, $value);
                                }
                                $stmt->execute();
                            }

                            $new_authinfo = generateAuthInfo();
                            $db->prepare('INSERT INTO contact_authInfo (contact_id, authtype, authinfo) VALUES (?, ?, ?)')->execute([$newContactId, 'pw', $new_authinfo]);
                            $db->prepare('INSERT INTO contact_status (contact_id, status) VALUES (?, ?)')->execute([$newContactId, 'ok']);
                        }
                    }
                } else {
                    $newRegistrantId = null;
                }

                $stmt = $db->prepare("SELECT exdate FROM domain WHERE id = :domain_id LIMIT 1");
                $stmt->execute(['domain_id' => $domain_id]);
                $from = $stmt->fetchColumn();
                $stmt->closeCursor();

                $stmt = $db->prepare("UPDATE domain SET exdate = DATE_ADD(exdate, INTERVAL ? MONTH), lastupdate = CURRENT_TIMESTAMP(3), clid = ?, upid = ?, registrant = ?, trdate = CURRENT_TIMESTAMP(3), trstatus = 'clientApproved', acdate = CURRENT_TIMESTAMP(3), transfer_exdate = NULL, rgpstatus = 'transferPeriod', transferPeriod = ? WHERE id = ?");
                $stmt->execute([$date_add, $row["reid"], $clid, $newRegistrantId, $date_add, $domain_id]);

                $reid = $row['reid'];
                $logRegistrantText = $newRegistrantId === null ? '[NULL]' : $newRegistrantId;
                $stmt_log = $db->prepare("INSERT INTO error_log (channel, level, level_name, message, context, extra) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_log->execute([
                    'manual_transfer',
                    250,
                    'NOTICE',
                    "Domain transfer manually approved: $domainName (New registrant: $logRegistrantText, Registrar: $reid)",
                    json_encode(['domain_id' => $domain_id, 'new_registrant' => $newRegistrantId, 'registrar' => $reid]),
                    json_encode([
                        'received_on' => date('Y-m-d H:i:s'),
                        'read_on' => null,
                        'is_read' => false,
                        'message_type' => 'manual_transfer_approval',
                        'performed_by' => $clid
                    ])
                ]);

                $stmt = $db->prepare('SELECT status FROM domain_status WHERE domain_id = ? AND status = ? LIMIT 1');
                $stmt->execute([$domain_id, 'pendingTransfer']);
                $existingStatus = $stmt->fetchColumn();
                $stmt->closeCursor();

                if ($existingStatus === 'pendingTransfer') {
                    $deleteStmt = $db->prepare('DELETE FROM domain_status WHERE domain_id = ? AND status = ?');
                    $deleteStmt->execute([$domain_id, 'pendingTransfer']);
                }

                $insertStmt = $db->prepare('INSERT INTO domain_status (domain_id, status) VALUES (?, ?)');
                $insertStmt->execute([$domain_id, 'ok']);

                $new_authinfo = generateAuthInfo();
                $stmt = $db->prepare("UPDATE domain_authInfo SET authinfo = ? WHERE domain_id = ?");
                $stmt->execute([$new_authinfo, $domain_id]);
                
                if (!($config['minimum_data'] ?? false)) {
                    foreach ($contactMap as $contact) {
                        $sql = "UPDATE domain_contact_map SET contact_id = :new_contact_id WHERE domain_id = :domain_id AND type = :type AND contact_id = :contact_id";
                        $stmt = $db->prepare($sql);
                        $stmt->bindValue(':new_contact_id', $newContactIds[$contact['contact_id']]);
                        $stmt->bindValue(':domain_id', $domain_id);
                        $stmt->bindValue(':type', $contact['type']);
                        $stmt->bindValue(':contact_id', $contact['contact_id']);
                        $stmt->execute();
                    }
                }

                $stmt = $db->prepare("UPDATE host SET clid = ?, upid = ?, lastupdate = CURRENT_TIMESTAMP(3), trdate = CURRENT_TIMESTAMP(3) WHERE domain_id = ?");
                $stmt->execute([$row["reid"], $clid, $domain_id]);

                $stmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance - :price) WHERE id = :reid");
                $stmt->execute(['price' => $price, 'reid' => $row['reid']]);

                $stmt = $db->prepare("INSERT INTO payment_history (registrar_id,date,description,amount) VALUES(:reid, CURRENT_TIMESTAMP(3), :description, :amount)");
                $description = "transfer domain $domainName for period $date_add MONTH";
                $stmt->execute(['reid' => $row['reid'], 'description' => $description, 'amount' => -$price]);

                $stmt = $db->prepare("SELECT exdate FROM domain WHERE id = :domain_id LIMIT 1");
                $stmt->execute(['domain_id' => $domain_id]);
                $to = $stmt->fetchColumn();
                $stmt->closeCursor();

                $stmt = $db->prepare("INSERT INTO statement (registrar_id,date,command,domain_name,length_in_months,fromS,toS,amount) VALUES(:registrar_id, CURRENT_TIMESTAMP(3), :command, :domain_name, :length_in_months, :from, :to, :amount)");
                $stmt->execute(['registrar_id' => $row['reid'], 'command' => 'transfer', 'domain_name' => $domainName, 'length_in_months' => $date_add, 'from' => $from, 'to' => $to, 'amount' => $price]);

                $stmt = $db->prepare("SELECT id,registrant,crdate,exdate,lastupdate,clid,crid,upid,trdate,trstatus,reid,redate,acid,acdate,transfer_exdate FROM domain WHERE name = :name LIMIT 1");
                $stmt->execute(['name' => $domainName]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                extract($row);

                $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                $stmt->execute(['reid' => $reid]);
                $reid_identifier = $stmt->fetchColumn();
                $stmt->closeCursor();

                $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                $stmt->execute(['acid' => $acid]);
                $acid_identifier = $stmt->fetchColumn();
                $stmt->closeCursor();

                $stmt = $db->prepare("SELECT id FROM statistics WHERE date = CURDATE()");
                $stmt->execute();
                $curdate_id = $stmt->fetchColumn();
                $stmt->closeCursor();

                if (!$curdate_id) {
                    $stmt = $db->prepare("INSERT IGNORE INTO statistics (date) VALUES(CURDATE())");
                    $stmt->execute();
                }

                $stmt = $db->prepare("UPDATE statistics SET transfered_domains = transfered_domains + 1 WHERE date = CURDATE()");
                $stmt->execute();
    
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                sendEppError($conn, $db, 2400, 'Database error during domain transfer approve', $clTRID, $trans);
                return;
            }
            
            $svTRID = generateSvTRID();
            $response = [
                'command' => 'transfer_domain',
                'resultCode' => 1000,
                'lang' => 'en-US',
                'message' => 'Command completed successfully',
                'name' => $domainName,
                'trStatus' => $trstatus,
                'reID' => $reid_identifier,
                'reDate' => $redate,
                'acID' => $acid_identifier,
                'acDate' => $acdate,
                'clTRID' => $clTRID,
                'svTRID' => $svTRID,
            ];
                
            if ($transfer_exdate) {
                $response["exDate"] = $transfer_exdate;
            }

            $epp = new EPP\EppWriter();
            $xml = $epp->epp_writer($response);
            updateTransaction($db, 'transfer', 'domain', $domainName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
            sendEppResponse($conn, $xml);
        } else {
            sendEppError($conn, $db, 2301, 'The domain is NOT pending transfer', $clTRID, $trans);
            return;
        }
    }
    elseif ($op === 'cancel') {

        if ($clid === $registrar_id_domain) {
            sendEppError($conn, $db, 2201, 'Only the APPLICANT can cancel', $clTRID, $trans);
            return;
        }

        if ($authInfo_pw) {
            $stmt = $db->prepare("SELECT id FROM domain_authInfo WHERE domain_id = :domain_id AND authtype = 'pw' AND authinfo = :authInfo_pw LIMIT 1");
            $stmt->execute(['domain_id' => $domain_id, 'authInfo_pw' => $authInfo_pw]);
            $domain_authinfo_id = $stmt->fetchColumn();
            $stmt->closeCursor();

            if (!$domain_authinfo_id) {
                sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                return;
            }
        }

        $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $domainName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        extract($row);

        if ($trstatus === 'pending') {
            try {
                $db->beginTransaction();
        
                $stmt = $db->prepare("UPDATE domain SET trstatus = 'clientCancelled' WHERE id = :domain_id");
                $stmt->execute(['domain_id' => $domain_id]);

                $stmt_log = $db->prepare("INSERT INTO error_log (channel, level, level_name, message, context, extra) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_log->execute([
                    'manual_transfer',
                    250,
                    'NOTICE',
                    "Domain transfer manually canceled: $domainName (Registrar: $reid)",
                    json_encode(['domain_id' => $domain_id, 'registrar' => $reid]),
                    json_encode([
                        'received_on' => date('Y-m-d H:i:s'),
                        'read_on' => null,
                        'is_read' => false,
                        'message_type' => 'manual_transfer_cancellation',
                        'performed_by' => $clid
                    ])
                ]);

                $stmt = $db->prepare('SELECT status FROM domain_status WHERE domain_id = ? AND status = ? LIMIT 1');
                $stmt->execute([$domain_id, 'pendingTransfer']);
                $existingStatus = $stmt->fetchColumn();
                $stmt->closeCursor();

                if ($existingStatus === 'pendingTransfer') {
                    $deleteStmt = $db->prepare('DELETE FROM domain_status WHERE domain_id = ? AND status = ?');
                    $deleteStmt->execute([$domain_id, 'pendingTransfer']);
                }

                $insertStmt = $db->prepare('INSERT INTO domain_status (domain_id, status) VALUES (?, ?)');
                $insertStmt->execute([$domain_id, 'ok']);

                $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
                $stmt->execute(['name' => $domainName]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                extract($row);

                $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                $stmt->execute(['reid' => $reid]);
                $reid_identifier = $stmt->fetchColumn();
                $stmt->closeCursor();

                $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                $stmt->execute(['acid' => $acid]);
                $acid_identifier = $stmt->fetchColumn();
                $stmt->closeCursor();
                
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                sendEppError($conn, $db, 2400, 'Database error during domain transfer cancel', $clTRID, $trans);
                return;
            }
                
            $svTRID = generateSvTRID();
            $response = [
                'command' => 'transfer_domain',
                'resultCode' => 1000,
                'lang' => 'en-US',
                'message' => 'Command completed successfully',
                'name' => $domainName,
                'trStatus' => $trstatus,
                'reID' => $reid_identifier,
                'reDate' => $redate,
                'acID' => $acid_identifier,
                'acDate' => $acdate,
                'clTRID' => $clTRID,
                'svTRID' => $svTRID,
            ];
                
            if ($transfer_exdate) {
                $response["exDate"] = $transfer_exdate;
            }

            $epp = new EPP\EppWriter();
            $xml = $epp->epp_writer($response);
            updateTransaction($db, 'transfer', 'domain', $domainName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
            sendEppResponse($conn, $xml);

        } else {
            sendEppError($conn, $db, 2301, 'The domain is NOT pending transfer', $clTRID, $trans);
            return;
        }
    }

    elseif ($op === 'query') {
        $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $domainName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        extract($result);

        if ($trstatus === 'pending') {
            $stmtReID = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
            $stmtReID->execute(['reid' => $reid]);
            $reid_identifier = $stmtReID->fetchColumn();
            $stmtReID->closeCursor();

            $stmtAcID = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
            $stmtAcID->execute(['acid' => $acid]);
            $acid_identifier = $stmtAcID->fetchColumn();
            $stmtAcID->closeCursor();
            
            $svTRID = generateSvTRID();
            $response = [
                'command' => 'transfer_domain',
                'resultCode' => 1000,
                'lang' => 'en-US',
                'message' => 'Command completed successfully',
                'name' => $domainName,
                'trStatus' => $trstatus,
                'reID' => $reid_identifier,
                'reDate' => $redate,
                'acID' => $acid_identifier,
                'acDate' => $acdate,
                'clTRID' => $clTRID,
                'svTRID' => $svTRID,
            ];
                
            if ($transfer_exdate) {
                $response["exDate"] = $transfer_exdate;
            }

            $epp = new EPP\EppWriter();
            $xml = $epp->epp_writer($response);
            updateTransaction($db, 'transfer', 'domain', $domainName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
            sendEppResponse($conn, $xml);
        } else {
            sendEppError($conn, $db, 2301, 'The domain is NOT pending transfer', $clTRID, $trans);
            return;
        }
    }
    elseif ($op === 'reject') {
        if ($clid !== $registrar_id_domain) {
            sendEppError($conn, $db, 2201, 'Only LOSING REGISTRAR can reject', $clTRID, $trans);
            return;
        }

        if ($authInfo_pw) {
            $stmtAuthInfo = $db->prepare("SELECT id FROM domain_authInfo WHERE domain_id = :domain_id AND authtype = 'pw' AND authinfo = :authInfo_pw LIMIT 1");
            $stmtAuthInfo->execute(['domain_id' => $domain_id, 'authInfo_pw' => $authInfo_pw]);
            $domain_authinfo_id = $stmtAuthInfo->fetchColumn();
            $stmtAuthInfo->closeCursor();

            if (!$domain_authinfo_id) {
                sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                return;
            }
        }

        $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $domainName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        extract($result);

        if ($trstatus === 'pending') {
            try {
                $db->beginTransaction();

                $stmtUpdate = $db->prepare("UPDATE domain SET trstatus = 'clientRejected' WHERE id = :domain_id");
                $success = $stmtUpdate->execute(['domain_id' => $domain_id]);

                $stmt_log = $db->prepare("INSERT INTO error_log (channel, level, level_name, message, context, extra) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_log->execute([
                    'manual_transfer',
                    250,
                    'NOTICE',
                    "Domain transfer manually rejected: $domainName (Registrar: $reid)",
                    json_encode(['domain_id' => $domain_id, 'registrar' => $reid]),
                    json_encode([
                        'received_on' => date('Y-m-d H:i:s'),
                        'read_on' => null,
                        'is_read' => false,
                        'message_type' => 'manual_transfer_rejection',
                        'performed_by' => $clid
                    ])
                ]);

                $stmt = $db->prepare('SELECT status FROM domain_status WHERE domain_id = ? AND status = ? LIMIT 1');
                $stmt->execute([$domain_id, 'pendingTransfer']);
                $existingStatus = $stmt->fetchColumn();
                $stmt->closeCursor();

                if ($existingStatus === 'pendingTransfer') {
                    $deleteStmt = $db->prepare('DELETE FROM domain_status WHERE domain_id = ? AND status = ?');
                    $deleteStmt->execute([$domain_id, 'pendingTransfer']);
                }

                $insertStmt = $db->prepare('INSERT INTO domain_status (domain_id, status) VALUES (?, ?)');
                $insertStmt->execute([$domain_id, 'ok']);

                $stmtReID = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                $stmtReID->execute(['reid' => $reid]);
                $reid_identifier = $stmtReID->fetchColumn();
                $stmtReID->closeCursor();

                $stmtAcID = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                $stmtAcID->execute(['acid' => $acid]);
                $acid_identifier = $stmtAcID->fetchColumn();
                $stmtAcID->closeCursor();

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                sendEppError($conn, $db, 2400, 'Database error during domain transfer reject', $clTRID, $trans);
                return;
            }

            $svTRID = generateSvTRID();
            $response = [
                'command' => 'transfer_domain',
                'resultCode' => 1000,
                'lang' => 'en-US',
                'message' => 'Command completed successfully',
                'name' => $domainName,
                'trStatus' => $trstatus,
                'reID' => $reid_identifier,
                'reDate' => $redate,
                'acID' => $acid_identifier,
                'acDate' => $acdate,
                'clTRID' => $clTRID,
                'svTRID' => $svTRID,
            ];
                    
            if ($transfer_exdate) {
                $response["exDate"] = $transfer_exdate;
            }

            $epp = new EPP\EppWriter();
            $xml = $epp->epp_writer($response);
            updateTransaction($db, 'transfer', 'domain', $domainName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
            sendEppResponse($conn, $xml);
        } else {
            sendEppError($conn, $db, 2301, 'The domain is NOT pending transfer', $clTRID, $trans);
            return;
        }
    }

    elseif ($op === 'request') {
        if ($allocation_token !== null) {
            $allocationTokenValue = (string)$allocation_token;
                        
            $stmt = $db->prepare("SELECT token FROM allocation_tokens WHERE domain_name = :domainName AND token = :token LIMIT 1");
            $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
            $stmt->bindParam(':token', $allocationTokenValue, PDO::PARAM_STR);
            $stmt->execute();
            $token = $stmt->fetchColumn();
            $stmt->closeCursor();
                        
            if ($token) {
                // No action needed, script continues
            } else {
                sendEppError($conn, $db, 2201, 'Please double check your allocation token', $clTRID, $trans);
                return;
            }
        }
        
        if (!($config['disable_60days'] ?? false)) {
            // Check days from registration
            $stmt = $db->prepare("SELECT DATEDIFF(CURRENT_TIMESTAMP(3), crdate) FROM domain WHERE id = :domain_id LIMIT 1");
            $stmt->execute(['domain_id' => $domain_id]);
            $days_from_registration = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($days_from_registration < 60) {
                sendEppError($conn, $db, 2201, 'The domain name must not be within 60 days of its initial registration', $clTRID, $trans);
                return;
            }

            // Check days from last transfer
            $stmt = $db->prepare("SELECT trdate, DATEDIFF(CURRENT_TIMESTAMP(3),trdate) AS intval FROM domain WHERE id = :domain_id LIMIT 1");
            $stmt->execute(['domain_id' => $domain_id]);
            $result = $stmt->fetch();
            $stmt->closeCursor();
            $last_trdate = $result["trdate"];
            $days_from_last_transfer = $result["intval"];

            if ($last_trdate && $days_from_last_transfer < 60) {
                sendEppError($conn, $db, 2201, 'The domain name must not be within 60 days of its last transfer from another registrar', $clTRID, $trans);
                return;
            }
        }

        // Check days from expiry date
        $stmt = $db->prepare("SELECT DATEDIFF(CURRENT_TIMESTAMP(3),exdate) FROM domain WHERE id = :domain_id LIMIT 1");
        $stmt->execute(['domain_id' => $domain_id]);
        $days_from_expiry_date = $stmt->fetchColumn();
        $stmt->closeCursor();

        if ($days_from_expiry_date > 30) {
            sendEppError($conn, $db, 2201, 'The domain name must not be more than 30 days past its expiry date', $clTRID, $trans);
            return;
        }

        // Auth info
        $stmt = $db->prepare("SELECT id FROM domain_authInfo WHERE domain_id = :domain_id AND authtype = 'pw' AND authinfo = :authInfo_pw LIMIT 1");
        $stmt->execute(['domain_id' => $domain_id, 'authInfo_pw' => $authInfo_pw]);
        $domain_authinfo_id = $stmt->fetchColumn();
        $stmt->closeCursor();

        if (!$domain_authinfo_id) {
            sendEppError($conn, $db, 2202, 'authInfo pw is invalid', $clTRID, $trans);
            return;
        }

        // Check domain status
        $stmt = $db->prepare("SELECT status FROM domain_status WHERE domain_id = :domain_id");
        $stmt->execute(['domain_id' => $domain_id]);
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor();

        foreach ($statuses as $status) {
            if (preg_match('/TransferProhibited$/', $status) || preg_match('/^pending/', $status)) {
                sendEppError($conn, $db, 2304, 'It has a status that does not allow the transfer', $clTRID, $trans);
                return;
            }
        }

        if ($clid == $registrar_id_domain) {
            sendEppError($conn, $db, 2106, 'Destination client of the transfer operation is the domain sponsoring client', $clTRID, $trans);
            return;
        }

        $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate FROM domain WHERE name = ? LIMIT 1");
        $stmt->execute([$domainName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $domain_id = $row['id'];
        $registrant = $row['registrant'];
        $crdate = $row['crdate'];
        $exdate = $row['exdate'];
        $update = $row['lastupdate'];
        $registrar_id_domain = $row['clid'];
        $crid = $row['crid'];
        $upid = $row['upid'];
        $trdate = $row['trdate'];
        $trstatus = $row['trstatus'];
        $reid = $row['reid'];
        $redate = $row['redate'];
        $acid = $row['acid'];
        $acdate = $row['acdate'];

        if (!$trstatus || $trstatus !== 'pending') {
            $period = (int) ($xml->xpath('//domain:period[1]')[0] ?? 0);
            $period_unit = ($xml->xpath('//domain:period/@unit[1]')[0] ?? '');

            if ($period) {
                if ($period < 1 || $period > 99) {
                    sendEppError($conn, $db, 2004, "domain:period minLength value='1', maxLength value='99'", $clTRID, $trans);
                    return;
                }
            }

            if ($period_unit) {
                $period_unit = strtolower($period_unit);
                if (!in_array($period_unit, ['m', 'y'])) {
                    sendEppError($conn, $db, 2004, 'domain:period unit m|y', $clTRID, $trans);
                    return;
                }
            }

            $date_add = 0;
            if ($period_unit === 'y') {
                $date_add = $period * 12;
            } elseif ($period_unit === 'm') {
                $date_add = $period;
            }

            if ($date_add > 0) {

                if (!preg_match("/^(12|24|36|48|60|72|84|96|108|120)$/", $date_add)) {
                    sendEppError($conn, $db, 2306, 'Not less than 1 year and not more than 10', $clTRID, $trans);
                    return;
                }

                $stmt = $db->prepare("SELECT accountBalance, creditLimit, currency FROM registrar WHERE id = :registrar_id LIMIT 1");
                $stmt->execute([':registrar_id' => $clid]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                $registrar_balance = $result["accountBalance"];
                $creditLimit = $result["creditLimit"];
                $currency = $result["currency"];

                $returnValue = getDomainPrice($db, $domainName, $tldid, $date_add, 'transfer', $clid, $currency);
                $price = $returnValue['price'] ?? null;

                if (!isset($price)) {
                    sendEppError($conn, $db, 2400, 'The price, period and currency for such TLD are not declared', $clTRID, $trans);
                    return;
                }

                if (($registrar_balance + $creditLimit) < $price) {
                    sendEppError($conn, $db, 2104, 'The registrar who wants to take over this domain has no money', $clTRID, $trans);
                    return;
                }

                try {
                    $db->beginTransaction();

                    $waiting_period = 5; 
                    $stmt = $db->prepare("UPDATE domain SET trstatus = 'pending', reid = :registrar_id, redate = CURRENT_TIMESTAMP(3), acid = :registrar_id_domain, acdate = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL $waiting_period DAY), transfer_exdate = DATE_ADD(exdate, INTERVAL $date_add MONTH) WHERE id = :domain_id");
                    $stmt->execute([':registrar_id' => $clid, ':registrar_id_domain' => $registrar_id_domain, ':domain_id' => $domain_id]);

                    $stmt = $db->prepare('SELECT status FROM domain_status WHERE domain_id = ? AND status = ? LIMIT 1');
                    $stmt->execute([$domain_id, 'ok']);
                    $existingStatus = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    if ($existingStatus === 'ok') {
                        $deleteStmt = $db->prepare('DELETE FROM domain_status WHERE domain_id = ? AND status = ?');
                        $deleteStmt->execute([$domain_id, 'ok']);
                    }

                    $insertStmt = $db->prepare('INSERT INTO domain_status (domain_id, status) VALUES (?, ?)');
                    $insertStmt->execute([$domain_id, 'pendingTransfer']);

                    $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
                    $stmt->execute([':name' => $domainName]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                    
                    list($domain_id, $registrant, $crdate, $exdate, $lastupdate, $registrar_id_domain, $crid, $upid, $trdate, $trstatus, $reid, $redate, $acid, $acdate, $transfer_exdate) = array_values($result);

                    $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                    $stmt->execute([':reid' => $reid]);
                    $reid_identifier = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                    $stmt->execute([':acid' => $acid]);
                    $acid_identifier = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    // The current sponsoring registrar will receive a notification of a pending transfer
                    $stmt = $db->prepare("INSERT INTO poll (registrar_id,qdate,msg,msg_type,obj_name_or_id,obj_trStatus,obj_reID,obj_reDate,obj_acID,obj_acDate,obj_exDate) VALUES(:registrar_id_domain, CURRENT_TIMESTAMP(3), 'Transfer requested.', 'domainTransfer', :name, 'pending', :reid_identifier, :redate, :acid_identifier, :acdate, :transfer_exdate)");
                    $stmt->execute([
                        ':registrar_id_domain' => $registrar_id_domain,
                        ':name' => $domainName,
                        ':reid_identifier' => $reid_identifier,
                        ':redate' => $redate,
                        ':acid_identifier' => $acid_identifier,
                        ':acdate' => $acdate,
                        ':transfer_exdate' => $transfer_exdate
                    ]);

                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    sendEppError($conn, $db, 2400, 'Database error during domain transfer request', $clTRID, $trans);
                    return;
                }

                $svTRID = generateSvTRID();
                $response = [
                    'command' => 'transfer_domain',
                    'resultCode' => 1001,
                    'lang' => 'en-US',
                    'message' => 'Command completed successfully; action pending',
                    'name' => $domainName,
                    'trStatus' => $trstatus,
                    'reID' => $reid_identifier,
                    'reDate' => $redate,
                    'acID' => $acid_identifier,
                    'acDate' => $acdate,
                    'clTRID' => $clTRID,
                    'svTRID' => $svTRID,
                ];
                        
                if ($transfer_exdate) {
                    $response["exDate"] = $transfer_exdate;
                }

                $epp = new EPP\EppWriter();
                $xml = $epp->epp_writer($response);
                updateTransaction($db, 'transfer', 'domain', $domainName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
                sendEppResponse($conn, $xml);
            } else {
                // No expiration date is inserted after the transfer procedure
                try {
                    $db->beginTransaction();
                    $waiting_period = 5; // days

                    $stmt = $db->prepare("UPDATE domain SET trstatus = 'pending', reid = :registrar_id, redate = CURRENT_TIMESTAMP(3), acid = :registrar_id_domain, acdate = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL :waiting_period DAY), transfer_exdate = NULL WHERE id = :domain_id");
                    $stmt->execute([
                        ':registrar_id' => $clid,
                        ':registrar_id_domain' => $registrar_id_domain,
                        ':waiting_period' => $waiting_period,
                        ':domain_id' => $domain_id
                    ]);

                    $stmt = $db->prepare('SELECT status FROM domain_status WHERE domain_id = ? AND status = ? LIMIT 1');
                    $stmt->execute([$domain_id, 'ok']);
                    $existingStatus = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    if ($existingStatus === 'ok') {
                        $deleteStmt = $db->prepare('DELETE FROM domain_status WHERE domain_id = ? AND status = ?');
                        $deleteStmt->execute([$domain_id, 'ok']);
                    }

                    $insertStmt = $db->prepare('INSERT INTO domain_status (domain_id, status) VALUES (?, ?)');
                    $insertStmt->execute([$domain_id, 'pendingTransfer']);

                    // Get the domain details
                    $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
                    $stmt->execute([':name' => $domainName]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();

                    list($domain_id, $registrant, $crdate, $exdate, $lastupdate, $registrar_id_domain, $crid, $upid, $trdate, $trstatus, $reid, $redate, $acid, $acdate, $transfer_exdate) = array_values($result);

                    $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                    $stmt->execute([':reid' => $reid]);
                    $reid_identifier = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                    $stmt->execute([':acid' => $acid]);
                    $acid_identifier = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    // Notify the current sponsoring registrar of the pending transfer
                    $stmt = $db->prepare("INSERT INTO poll (registrar_id, qdate, msg, msg_type, obj_name_or_id, obj_trStatus, obj_reID, obj_reDate, obj_acID, obj_acDate, obj_exDate) VALUES(:registrar_id_domain, CURRENT_TIMESTAMP(3), 'Transfer requested.', 'domainTransfer', :name, 'pending', :reid_identifier, :redate, :acid_identifier, :acdate, NULL)");
                    $stmt->execute([
                        ':registrar_id_domain' => $registrar_id_domain,
                        ':name' => $domainName,
                        ':reid_identifier' => $reid_identifier,
                        ':redate' => $redate,
                        ':acid_identifier' => $acid_identifier,
                        ':acdate' => $acdate
                    ]);

                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }

                    sendEppError($conn, $db, 2400, 'Database error during domain transfer request', $clTRID, $trans);
                    return;
                }

                $svTRID = generateSvTRID();
                $response = [
                    'command' => 'transfer_domain',
                    'resultCode' => 1001,
                    'lang' => 'en-US',
                    'message' => 'Command completed successfully; action pending',
                    'name' => $domainName,
                    'trStatus' => $trstatus,
                    'reID' => $reid_identifier,
                    'reDate' => $redate,
                    'acID' => $acid_identifier,
                    'acDate' => $acdate,
                    'clTRID' => $clTRID,
                    'svTRID' => generateSvTRID(),
                ];
                        
                if ($transfer_exdate) {
                    $response["exDate"] = $transfer_exdate;
                }

                $epp = new EPP\EppWriter();
                $xml = $epp->epp_writer($response);
                updateTransaction($db, 'transfer', 'domain', $domainName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
                sendEppResponse($conn, $xml);
            }
        } elseif ($trstatus === 'pending') {
            sendEppError($conn, $db, 2300, 'Command failed as the domain is pending transfer', $clTRID, $trans);
            return;
        }
    }
    else {
        sendEppError($conn, $db, 2005, 'Only op: approve|cancel|query|reject|request are accepted', $clTRID, $trans);
        return;
    }
}