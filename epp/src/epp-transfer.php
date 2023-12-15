<?php

function processContactTranfer($conn, $db, $xml, $clid, $database_type, $trans) {
    $contactID = (string) $xml->command->transfer->children('urn:ietf:params:xml:ns:contact-1.0')->transfer->{'id'};
    $clTRID = (string) $xml->command->clTRID;
    $op = (string) $xml->xpath('//@op')[0] ?? null;

    $op = (string)$xml['op'][0];
    $obj = $xml->xpath('//contact:transfer')[0] ?? null;

    if ($obj) {
        $authInfo_pw = (string)$obj->xpath('//contact:authInfo/contact:pw[1]')[0];

        if (!$contactID) {
            sendEppError($conn, $db, 2003, 'Contact ID was not provided', $clTRID, $trans);
            return;
        }

        $identifier = strtoupper($contactID);
        $stmt = $db->prepare("SELECT id, clid FROM contact WHERE identifier = :identifier LIMIT 1");
        $stmt->execute([':identifier' => $identifier]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $contact_id = $result['id'] ?? null;
        $registrar_id_contact = $result['clid'] ?? null;

        if (!$contact_id) {
            sendEppError($conn, $db, 2303, 'Contact does not exist', $clTRID, $trans);
            return;
        }
    
        $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
        $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
        $stmt->execute();
        $clid = $stmt->fetch(PDO::FETCH_ASSOC);
        $clid = $clid['id'];

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

                if (!$contact_authinfo_id) {
                    sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                    return;
                }
            }

            $stmt = $db->prepare("SELECT crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
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
                    $stmt->execute([':contact_id' => $contact_id]);
                    $updatedContactInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                    $reid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                    $reid_identifier_stmt->execute([':reid' => $updatedContactInfo['reid']]);
                    $reid_identifier = $reid_identifier_stmt->fetchColumn();

                    $acid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                    $acid_identifier_stmt->execute([':acid' => $updatedContactInfo['acid']]);
                    $acid_identifier = $acid_identifier_stmt->fetchColumn();
                    
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
                if (!$contact_authinfo_id) {
                    sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                    return;
                }
            }

            $stmt = $db->prepare("SELECT crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $trstatus = $contactInfo['trstatus'] ?? '';

            if ($trstatus === 'pending') {
                $stmt = $db->prepare("UPDATE contact SET trstatus = 'clientCancelled' WHERE id = :contact_id");
                $stmt->execute([':contact_id' => $contact_id]);

                if ($stmt->errorCode() != 0) {
                    sendEppError($conn, $db, 2400, 'The transfer was not canceled successfully, something is wrong', $clTRID, $trans);
                    return;
                } else {
                    $stmt->execute([':contact_id' => $contact_id]);
                    $updatedContactInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                    $reid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                    $reid_identifier_stmt->execute([':reid' => $updatedContactInfo['reid']]);
                    $reid_identifier = $reid_identifier_stmt->fetchColumn();

                    $acid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                    $acid_identifier_stmt->execute([':acid' => $updatedContactInfo['acid']]);
                    $acid_identifier = $acid_identifier_stmt->fetchColumn();
                    
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
            $trstatus = $contactInfo['trstatus'] ?? '';

            if ($trstatus === 'pending') {
                $reid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                $reid_identifier_stmt->execute([':reid' => $contactInfo['reid']]);
                $reid_identifier = $reid_identifier_stmt->fetchColumn();

                $acid_identifier_stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                $acid_identifier_stmt->execute([':acid' => $contactInfo['acid']]);
                $acid_identifier = $acid_identifier_stmt->fetchColumn();
                
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

                if (!$contact_authinfo_id) {
                    sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                    return;
                }
            }

            $stmt = $db->prepare("SELECT crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);

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

                    // Fetch registrar identifiers
                    $reidStmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                    $reidStmt->execute([':reid' => $contactInfo['reid']]);
                    $reid_identifier = $reidStmt->fetchColumn();

                    $acidStmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                    $acidStmt->execute([':acid' => $contactInfo['acid']]);
                    $acid_identifier = $acidStmt->fetchColumn();
                    
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
            // Check if contact is within 60 days of its initial registration
            $stmt = $db->prepare("SELECT DATEDIFF(CURRENT_TIMESTAMP(3),crdate) FROM contact WHERE id = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $days_from_registration = $stmt->fetchColumn();

            if ($days_from_registration < 60) {
                sendEppError($conn, $db, 2201, 'The contact name must not be within 60 days of its initial registration', $clTRID, $trans);
                return;
            }

            // Check if contact is within 60 days of its last transfer
            $stmt = $db->prepare("SELECT trdate, DATEDIFF(CURRENT_TIMESTAMP(3),trdate) AS intval FROM contact WHERE id = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $last_trdate = $result['trdate'];
            $days_from_last_transfer = $result['intval'];

            if ($last_trdate && $days_from_last_transfer < 60) {
                sendEppError($conn, $db, 2201, 'The contact name must not be within 60 days of its last transfer from another registrar', $clTRID, $trans);
                return;
            }

            // Check the <contact:authInfo> element
            $stmt = $db->prepare("SELECT id FROM contact_authInfo WHERE contact_id = :contact_id AND authtype = 'pw' AND authinfo = :authInfo_pw LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id, ':authInfo_pw' => $authInfo_pw]);
            $contact_authinfo_id = $stmt->fetchColumn();

            if (!$contact_authinfo_id) {
                sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                return;
            }

            // Check if the contact name is subject to any special locks or holds
            $stmt = $db->prepare("SELECT status FROM contact_status WHERE contact_id = :contact_id");
            $stmt->execute([':contact_id' => $contact_id]);

            while ($status = $stmt->fetchColumn()) {
                if (preg_match("/.*(TransferProhibited)$/", $status) || preg_match("/^pending/", $status)) {
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
            $trstatus = $result['trstatus'];

            if (!$trstatus || $trstatus != 'pending') {
                $waiting_period = 5; // days
                $stmt = $db->prepare("UPDATE contact SET trstatus = 'pending', reid = :registrar_id, redate = CURRENT_TIMESTAMP(3), acid = :registrar_id_contact, acdate = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL $waiting_period DAY) WHERE id = :contact_id");
                $stmt->execute([
                    ':registrar_id' => $clid,
                    ':registrar_id_contact' => $registrar_id_contact,
                    ':contact_id' => $contact_id
                ]);

                if ($stmt->errorCode() != '00000') {
                    sendEppError($conn, $db, 2400, 'The transfer was not initiated successfully, something is wrong', $clTRID, $trans);
                    return;
                } else {
                    $stmt = $db->prepare("SELECT crid,crdate,upid,lastupdate,trdate,trstatus,reid,redate,acid,acdate FROM contact WHERE id = :contact_id LIMIT 1");
                    $stmt->execute([':contact_id' => $contact_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);

                    $reid_identifier = $db->query("SELECT clid FROM registrar WHERE id = '{$result['reid']}' LIMIT 1")->fetchColumn();
                    $acid_identifier = $db->query("SELECT clid FROM registrar WHERE id = '{$result['acid']}' LIMIT 1")->fetchColumn();

                    $db->prepare("INSERT INTO poll (registrar_id,qdate,msg,msg_type,obj_name_or_id,obj_trStatus,obj_reID,obj_reDate,obj_acID,obj_acDate,obj_exDate) VALUES(:registrar_id_contact, CURRENT_TIMESTAMP(3), 'Transfer requested.', 'contactTransfer', :identifier, 'pending', :reid_identifier, :redate, :acid_identifier, :acdate, NULL)")
                        ->execute([
                            ':registrar_id_contact' => $registrar_id_contact,
                            ':identifier' => $identifier,
                            ':reid_identifier' => $reid_identifier,
                            ':redate' => str_replace(" ", "T", $result['redate']) . '.0Z',
                            ':acid_identifier' => $acid_identifier,
                            ':acdate' => str_replace(" ", "T", $result['acdate']) . '.0Z'
                        ]);
                        
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
                }
            } elseif ($op == 'pending') {
                sendEppError($conn, $db, 2300, 'Command failed because the contact is pending transfer', $clTRID, $trans);
                return;
            }
        } else {
            sendEppError($conn, $db, 2005, 'Only op: approve|cancel|query|reject|request are accepted', $clTRID, $trans);
            return;
        }
    }
}

function processDomainTransfer($conn, $db, $xml, $clid, $database_type, $trans) {
    $domainName = (string) $xml->command->transfer->children('urn:ietf:params:xml:ns:domain-1.0')->transfer->name;
    $clTRID = (string) $xml->command->clTRID;
    $op = (string) $xml->xpath('//@op')[0] ?? null;

    // -  An OPTIONAL <domain:authInfo> for op="query" and mandatory for other op values "approve|cancel|reject|request"
    $authInfo_pw = (string)$xml->xpath('//domain:authInfo/domain:pw[1]')[0];

    if (!$domainName) {
        sendEppError($conn, $db, 2003, 'Please provide the domain name', $clTRID, $trans);
        return;
    }

    $stmt = $db->prepare("SELECT id,tldid,clid FROM domain WHERE name = :name LIMIT 1");
    $stmt->bindParam(':name', $domainName, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $domain_id = $row['id'] ?? null;
    $tldid = $row['tldid'] ?? null;
    $registrar_id_domain = $row['clid'] ?? null;

    if (!$domain_id) {
        sendEppError($conn, $db, 2303, 'Domain does not exist in registry', $clTRID, $trans);
        return;
    }
    
    $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    $clid = $stmt->fetch(PDO::FETCH_ASSOC);
    $clid = $clid['id'];

    if ($op === 'approve') {
        if ($clid !== $registrar_id_domain) {
            sendEppError($conn, $db, 2201, 'Only LOSING REGISTRAR can approve', $clTRID, $trans);
            return;
        }

        if ($authInfo_pw) {
            $stmt = $db->prepare("SELECT id FROM domain_authInfo WHERE domain_id = ? AND authtype = 'pw' AND authinfo = ? LIMIT 1");
            $stmt->execute([$domain_id, $authInfo_pw]);
            $domain_authinfo_id = $stmt->fetchColumn();

            if (!$domain_authinfo_id) {
                sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                return;
            }
        }

        $stmt = $db->prepare("SELECT id,registrant,crdate,exdate,lastupdate,clid,crid,upid,trdate,trstatus,reid,redate,acid,acdate,transfer_exdate FROM domain WHERE name = ? LIMIT 1");
        $stmt->execute([$domainName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row["trstatus"] === 'pending') {
            $date_add = 0;
            $price = 0;

            $stmt = $db->prepare("SELECT accountBalance,creditLimit FROM registrar WHERE id = ? LIMIT 1");
            $stmt->execute([$row["reid"]]);
            list($registrar_balance, $creditLimit) = $stmt->fetch(PDO::FETCH_NUM);

            if ($row["transfer_exdate"]) {
                $stmt = $db->prepare("SELECT PERIOD_DIFF(DATE_FORMAT(transfer_exdate, '%Y%m'), DATE_FORMAT(exdate, '%Y%m')) AS intval FROM domain WHERE name = ? LIMIT 1");
                $stmt->execute([$domainName]);
                $date_add = $stmt->fetchColumn();

                $returnValue = getDomainPrice($db, $domainName, $tldid, $date_add, 'transfer');
                $price = $returnValue['price'];
                
                if (($registrar_balance + $creditLimit) < $price) {
                    sendEppError($conn, $db, 2104, 'The registrar who took over this domain has no money to pay the renewal period that resulted from the transfer request', $clTRID, $trans);
                    return;
                }
            }
            
            $stmt = $db->prepare("SELECT exdate FROM domain WHERE id = :domain_id LIMIT 1");
            $stmt->execute(['domain_id' => $domain_id]);
            $from = $stmt->fetchColumn();

            $stmt = $db->prepare("UPDATE domain SET exdate = DATE_ADD(exdate, INTERVAL ? MONTH), lastupdate = CURRENT_TIMESTAMP(3), clid = ?, upid = ?, trdate = CURRENT_TIMESTAMP(3), trstatus = 'clientApproved', acdate = CURRENT_TIMESTAMP(3), transfer_exdate = NULL, rgpstatus = 'transferPeriod', transferPeriod = ? WHERE id = ?");
            $stmt->execute([$date_add, $row["reid"], $clid, $date_add, $domain_id]);

            $stmt = $db->prepare("UPDATE host SET clid = ?, upid = ?, lastupdate = CURRENT_TIMESTAMP(3), trdate = CURRENT_TIMESTAMP(3) WHERE domain_id = ?");
            $stmt->execute([$row["reid"], $clid, $domain_id]);

            if ($stmt->errorCode() !== PDO::ERR_NONE) {
                sendEppError($conn, $db, 2400, 'The transfer was not successful, something is wrong', $clTRID, $trans);
                return;
            } else {
                $stmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance - :price) WHERE id = :reid");
                $stmt->execute(['price' => $price, 'reid' => $reid]);

                $stmt = $db->prepare("INSERT INTO payment_history (registrar_id,date,description,amount) VALUES(:reid, CURRENT_TIMESTAMP(3), :description, :amount)");
                $description = "transfer domain $domainName for period $date_add MONTH";
                $stmt->execute(['reid' => $reid, 'description' => $description, 'amount' => -$price]);

                $stmt = $db->prepare("SELECT exdate FROM domain WHERE id = :domain_id LIMIT 1");
                $stmt->execute(['domain_id' => $domain_id]);
                $to = $stmt->fetchColumn();

                $stmt = $db->prepare("INSERT INTO statement (registrar_id,date,command,domain_name,length_in_months,fromS,toS,amount) VALUES(:registrar_id, CURRENT_TIMESTAMP(3), :command, :domain_name, :length_in_months, :from, :to, :amount)");
                $stmt->execute(['registrar_id' => $reid, 'command' => 'transfer', 'domain_name' => $domainName, 'length_in_months' => $date_add, 'from' => $from, 'to' => $to, 'amount' => $price]);

                $stmt = $db->prepare("SELECT id,registrant,crdate,exdate,lastupdate,clid,crid,upid,trdate,trstatus,reid,redate,acid,acdate,transfer_exdate FROM domain WHERE name = :name LIMIT 1");
                $stmt->execute(['name' => $domainName]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                extract($row);

                $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                $stmt->execute(['reid' => $reid]);
                $reid_identifier = $stmt->fetchColumn();

                $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                $stmt->execute(['acid' => $acid]);
                $acid_identifier = $stmt->fetchColumn();

                $stmt = $db->prepare("SELECT id FROM statistics WHERE date = CURDATE()");
                $stmt->execute();
                $curdate_id = $stmt->fetchColumn();

                if (!$curdate_id) {
                    $stmt = $db->prepare("INSERT IGNORE INTO statistics (date) VALUES(CURDATE())");
                    $stmt->execute();
                }

                $stmt = $db->prepare("UPDATE statistics SET transfered_domains = transfered_domains + 1 WHERE date = CURDATE()");
                $stmt->execute();
                
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
            }
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

            if (!$domain_authinfo_id) {
                sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                return;
            }
        }

        $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $domainName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        extract($row);

        if ($trstatus === 'pending') {
            $stmt = $db->prepare("UPDATE domain SET trstatus = 'clientCancelled' WHERE id = :domain_id");
            $stmt->execute(['domain_id' => $domain_id]);
            
            if ($stmt->errorCode() !== '00000') {
                sendEppError($conn, $db, 2400, 'The transfer was not canceled successfully, something is wrong', $clTRID, $trans);
                return;
            } else {
                $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
                $stmt->execute(['name' => $domainName]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                extract($row);

                $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                $stmt->execute(['reid' => $reid]);
                $reid_identifier = $stmt->fetchColumn();

                $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                $stmt->execute(['acid' => $acid]);
                $acid_identifier = $stmt->fetchColumn();
                
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
            }
        } else {
            sendEppError($conn, $db, 2301, 'The domain is NOT pending transfer', $clTRID, $trans);
            return;
        }
    }

    elseif ($op === 'query') {

        $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $domainName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        extract($result);

        if ($trstatus === 'pending') {

            $stmtReID = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
            $stmtReID->execute(['reid' => $reid]);
            $reid_identifier = $stmtReID->fetchColumn();

            $stmtAcID = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
            $stmtAcID->execute(['acid' => $acid]);
            $acid_identifier = $stmtAcID->fetchColumn();
            
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

            if (!$domain_authinfo_id) {
                sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                return;
            }
        }

        $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $domainName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        extract($result);

        if ($trstatus === 'pending') {
            $stmtUpdate = $db->prepare("UPDATE domain SET trstatus = 'clientRejected' WHERE id = :domain_id");
            $success = $stmtUpdate->execute(['domain_id' => $domain_id]);

            if (!$success || $stmtUpdate->errorCode() !== '00000') {
                sendEppError($conn, $db, 2400, 'The transfer was not successfully rejected, something is wrong', $clTRID, $trans);
                return;
            } else {
                $stmtReID = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                $stmtReID->execute(['reid' => $reid]);
                $reid_identifier = $stmtReID->fetchColumn();

                $stmtAcID = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                $stmtAcID->execute(['acid' => $acid]);
                $acid_identifier = $stmtAcID->fetchColumn();
                
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
            }
        } else {
            sendEppError($conn, $db, 2301, 'The domain is NOT pending transfer', $clTRID, $trans);
            return;
        }
    }

    elseif ($op === 'request') {
        // Check days from registration
        $stmt = $db->prepare("SELECT DATEDIFF(CURRENT_TIMESTAMP(3), crdate) FROM domain WHERE id = :domain_id LIMIT 1");
        $stmt->execute(['domain_id' => $domain_id]);
        $days_from_registration = $stmt->fetchColumn();

        if ($days_from_registration < 60) {
            sendEppError($conn, $db, 2201, 'The domain name must not be within 60 days of its initial registration', $clTRID, $trans);
            return;
        }

        // Check days from last transfer
        $stmt = $db->prepare("SELECT trdate, DATEDIFF(CURRENT_TIMESTAMP(3),trdate) AS intval FROM domain WHERE id = :domain_id LIMIT 1");
        $stmt->execute(['domain_id' => $domain_id]);
        $result = $stmt->fetch();
        $last_trdate = $result["trdate"];
        $days_from_last_transfer = $result["intval"];

        if ($last_trdate && $days_from_last_transfer < 60) {
            sendEppError($conn, $db, 2201, 'The domain name must not be within 60 days of its last transfer from another registrar', $clTRID, $trans);
            return;
        }

        // Check days from expiry date
        $stmt = $db->prepare("SELECT DATEDIFF(CURRENT_TIMESTAMP(3),exdate) FROM domain WHERE id = :domain_id LIMIT 1");
        $stmt->execute(['domain_id' => $domain_id]);
        $days_from_expiry_date = $stmt->fetchColumn();

        if ($days_from_expiry_date > 30) {
            sendEppError($conn, $db, 2201, 'The domain name must not be more than 30 days past its expiry date', $clTRID, $trans);
            return;
        }

        // Auth info
        $stmt = $db->prepare("SELECT id FROM domain_authInfo WHERE domain_id = :domain_id AND authtype = 'pw' AND authinfo = :authInfo_pw LIMIT 1");
        $stmt->execute(['domain_id' => $domain_id, 'authInfo_pw' => $authInfo_pw]);
        $domain_authinfo_id = $stmt->fetchColumn();

        if (!$domain_authinfo_id) {
            sendEppError($conn, $db, 2202, 'authInfo pw is invalid', $clTRID, $trans);
            return;
        }

        // Check domain status
        $stmt = $db->prepare("SELECT status FROM domain_status WHERE domain_id = :domain_id");
        $stmt->execute(['domain_id' => $domain_id]);
        while ($status = $stmt->fetchColumn()) {
            if (preg_match('/.*(TransferProhibited)$/', $status) || preg_match('/^pending/', $status)) {
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
            $period = (int) ($xml->xpath('domain:period[1]')[0] ?? 0);
            $period_unit = ($xml->xpath('domain:period/@unit[1]')[0] ?? '');

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

                $stmt = $db->prepare("SELECT accountBalance, creditLimit FROM registrar WHERE id = :registrar_id LIMIT 1");
                $stmt->execute([':registrar_id' => $clid]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $registrar_balance = $result["accountBalance"];
                $creditLimit = $result["creditLimit"];
                
                $returnValue = getDomainPrice($db, $domainName, $tldid, $date_add, 'transfer');
                $price = $returnValue['price'];

                if (($registrar_balance + $creditLimit) < $price) {
                    sendEppError($conn, $db, 2104, 'The registrar who wants to take over this domain has no money', $clTRID, $trans);
                    return;
                }

                $waiting_period = 5; 
                $stmt = $db->prepare("UPDATE domain SET trstatus = 'pending', reid = :registrar_id, redate = CURRENT_TIMESTAMP(3), acid = :registrar_id_domain, acdate = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL $waiting_period DAY), transfer_exdate = DATE_ADD(exdate, INTERVAL $date_add MONTH) WHERE id = :domain_id");
                $stmt->execute([':registrar_id' => $clid, ':registrar_id_domain' => $registrar_id_domain, ':domain_id' => $domain_id]);
                
                if ($stmt->errorCode() !== '00000') {
                    sendEppError($conn, $db, 2400, 'The transfer was not initiated successfully, something is wrong', $clTRID, $trans);
                    return;
                } else {
                    // Get the domain details
                    $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
                    $stmt->execute([':name' => $domainName]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    list($domain_id, $registrant, $crdate, $exdate, $lastupdate, $registrar_id_domain, $crid, $upid, $trdate, $trstatus, $reid, $redate, $acid, $acdate, $transfer_exdate) = array_values($result);

                    $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                    $stmt->execute([':reid' => $reid]);
                    $reid_identifier = $stmt->fetchColumn();

                    $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                    $stmt->execute([':acid' => $acid]);
                    $acid_identifier = $stmt->fetchColumn();

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
                }
            } else {
                // No expiration date is inserted after the transfer procedure
                $waiting_period = 5; // days

                $stmt = $db->prepare("UPDATE domain SET trstatus = 'pending', reid = :registrar_id, redate = CURRENT_TIMESTAMP(3), acid = :registrar_id_domain, acdate = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL :waiting_period DAY), transfer_exdate = NULL WHERE id = :domain_id");
                $stmt->execute([
                    ':registrar_id' => $clid,
                    ':registrar_id_domain' => $registrar_id_domain,
                    ':waiting_period' => $waiting_period,
                    ':domain_id' => $domain_id
                ]);

                if ($stmt->errorCode() !== '00000') {
                    sendEppError($conn, $db, 2400, 'The transfer was not initiated successfully, something is wrong', $clTRID, $trans);
                    return;
                } else {
                    // Get the domain details
                    $stmt = $db->prepare("SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = :name LIMIT 1");
                    $stmt->execute([':name' => $domainName]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);

                    list($domain_id, $registrant, $crdate, $exdate, $lastupdate, $registrar_id_domain, $crid, $upid, $trdate, $trstatus, $reid, $redate, $acid, $acdate, $transfer_exdate) = array_values($result);

                    $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :reid LIMIT 1");
                    $stmt->execute([':reid' => $reid]);
                    $reid_identifier = $stmt->fetchColumn();

                    $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :acid LIMIT 1");
                    $stmt->execute([':acid' => $acid]);
                    $acid_identifier = $stmt->fetchColumn();

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