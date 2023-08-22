<?php

function processContactTranfer($conn, $db, $xml, $clid, $database_type) {
    $contactID = (string) $xml->command->transfer->children('urn:ietf:params:xml:ns:contact-1.0')->transfer->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    $op = (string)$xml['op'][0];
    $obj = $xml->xpath('//contact:transfer')[0] ?? null;

    if ($obj) {
        $authInfo_pw = (string)$obj->xpath('//contact:authInfo/contact:pw[1]')[0];

        if (!$contactID) {
            sendEppError($conn, 2003, 'Required parameter missing');
            return;
        }

        $identifier = strtoupper($contactID);
        $stmt = $db->prepare("SELECT `id`, `clid` FROM `contact` WHERE `identifier` = :identifier LIMIT 1");
        $stmt->execute([':identifier' => $identifier]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $contact_id = $result['id'] ?? null;
        $registrar_id_contact = $result['clid'] ?? null;

        if (!$contact_id) {
            sendEppError($conn, 2303, 'Object does not exist');
            return;
        }
    
        $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
        $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
        $stmt->execute();
        $clid = $stmt->fetch(PDO::FETCH_ASSOC);
        $clid = $clid['id'];

        if ($op === 'approve') {
            if ($clid !== $registrar_id_contact) {
                sendEppError($conn, 2201, 'Authorization error');
                return;
            }

            if ($authInfo_pw) {
                $stmt = $db->prepare("SELECT `id` FROM `contact_authInfo` WHERE `contact_id` = :contact_id AND `authtype` = 'pw' AND `authinfo` = :authInfo_pw LIMIT 1");
                $stmt->execute([
                    ':contact_id' => $contact_id,
                    ':authInfo_pw' => $authInfo_pw
                ]);
                $contact_authinfo_id = $stmt->fetchColumn();

                if (!$contact_authinfo_id) {
                    sendEppError($conn, 2202, 'Invalid authorization information');
                    return;
                }
            }

            $stmt = $db->prepare("SELECT `crid`, `crdate`, `upid`, `update`, `trdate`, `trstatus`, `reid`, `redate`, `acid`, `acdate` FROM `contact` WHERE `id` = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $trstatus = $contactInfo['trstatus'] ?? '';

            if ($trstatus === 'pending') {
                $stmt = $db->prepare("UPDATE `contact` SET `update` = CURRENT_TIMESTAMP, `clid` = :reid, `upid` = :upid, `trdate` = CURRENT_TIMESTAMP, `trstatus` = 'clientApproved', `acdate` = CURRENT_TIMESTAMP WHERE `id` = :contact_id");
                $stmt->execute([
                    ':reid' => $contactInfo['reid'],
                    ':upid' => $clid,
                    ':contact_id' => $contact_id
                ]);

                if ($stmt->errorCode() != 0) {
                    sendEppError($conn, 2400, 'Command failed');
                    return;
                } else {
                    $stmt->execute([':contact_id' => $contact_id]);
                    $updatedContactInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                    $reid_identifier_stmt = $db->prepare("SELECT `clid` FROM `registrar` WHERE `id` = :reid LIMIT 1");
                    $reid_identifier_stmt->execute([':reid' => $updatedContactInfo['reid']]);
                    $reid_identifier = $reid_identifier_stmt->fetchColumn();

                    $acid_identifier_stmt = $db->prepare("SELECT `clid` FROM `registrar` WHERE `id` = :acid LIMIT 1");
                    $acid_identifier_stmt->execute([':acid' => $updatedContactInfo['acid']]);
                    $acid_identifier = $acid_identifier_stmt->fetchColumn();
                    
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
                        'svTRID' => generateSvTRID(),
                    ];

                    $epp = new EPP\EppWriter();
                    $xml = $epp->epp_writer($response);
                    sendEppResponse($conn, $xml);
                }
            } else {
                sendEppError($conn, 2301, 'Object not pending transfer');
                return;
            }
        } elseif ($op === 'cancel') {
            // Only the requesting or 'Gaining' Registrar can cancel
            if ($clid === $registrar_id_contact) {
                sendEppError($conn, 2201, 'Authorization error');
                return;
            }

            // A <contact:authInfo> element that contains authorization information associated with the contact object.
            if ($authInfo_pw) {
                $stmt = $db->prepare("SELECT `id` FROM `contact_authInfo` WHERE `contact_id` = :contact_id AND `authtype` = 'pw' AND `authinfo` = :authInfo_pw LIMIT 1");
                $stmt->execute([':contact_id' => $contact_id, ':authInfo_pw' => $authInfo_pw]);
                 $contact_authinfo_id = $stmt->fetchColumn();
                if (!$contact_authinfo_id) {
                    sendEppError($conn, 2202, 'Invalid authorization information');
                    return;
                }
            }

            $stmt = $db->prepare("SELECT `crid`, `crdate`, `upid`, `update`, `trdate`, `trstatus`, `reid`, `redate`, `acid`, `acdate` FROM `contact` WHERE `id` = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $trstatus = $contactInfo['trstatus'] ?? '';

            if ($trstatus === 'pending') {
                $stmt = $db->prepare("UPDATE `contact` SET `trstatus` = 'clientCancelled' WHERE `id` = :contact_id");
                $stmt->execute([':contact_id' => $contact_id]);

                if ($stmt->errorCode() != 0) {
                    sendEppError($conn, 2400, 'Command failed');
                    return;
                } else {
                    $stmt->execute([':contact_id' => $contact_id]);
                    $updatedContactInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                    $reid_identifier_stmt = $db->prepare("SELECT `clid` FROM `registrar` WHERE `id` = :reid LIMIT 1");
                    $reid_identifier_stmt->execute([':reid' => $updatedContactInfo['reid']]);
                    $reid_identifier = $reid_identifier_stmt->fetchColumn();

                    $acid_identifier_stmt = $db->prepare("SELECT `clid` FROM `registrar` WHERE `id` = :acid LIMIT 1");
                    $acid_identifier_stmt->execute([':acid' => $updatedContactInfo['acid']]);
                    $acid_identifier = $acid_identifier_stmt->fetchColumn();
                    
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
                        'svTRID' => generateSvTRID(),
                    ];

                    $epp = new EPP\EppWriter();
                    $xml = $epp->epp_writer($response);
                    sendEppResponse($conn, $xml);
                }
            } else {
                sendEppError($conn, 2301, 'Object not pending transfer');
                return;
            }
        } elseif ($op === 'query') {
            $stmt = $db->prepare("SELECT `crid`, `crdate`, `upid`, `update`, `trdate`, `trstatus`, `reid`, `redate`, `acid`, `acdate` FROM `contact` WHERE `id` = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $trstatus = $contactInfo['trstatus'] ?? '';

            if ($trstatus === 'pending') {
                $reid_identifier_stmt = $db->prepare("SELECT `clid` FROM `registrar` WHERE `id` = :reid LIMIT 1");
                $reid_identifier_stmt->execute([':reid' => $contactInfo['reid']]);
                $reid_identifier = $reid_identifier_stmt->fetchColumn();

                $acid_identifier_stmt = $db->prepare("SELECT `clid` FROM `registrar` WHERE `id` = :acid LIMIT 1");
                $acid_identifier_stmt->execute([':acid' => $contactInfo['acid']]);
                $acid_identifier = $acid_identifier_stmt->fetchColumn();
                
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
                    'svTRID' => generateSvTRID(),
                ];

                $epp = new EPP\EppWriter();
                $xml = $epp->epp_writer($response);
                sendEppResponse($conn, $xml);
            } else {
                sendEppError($conn, 2301, 'Object not pending transfer');
                return;
            }
        } elseif ($op === 'reject') {
            // Only the LOSING REGISTRAR can approve or reject
            if ($clid !== $registrar_id_contact) {
                sendEppError($conn, 2201, 'Authorization error');
                return;
            }

            // A <contact:authInfo> element that contains authorization information associated with the contact object.
            if ($authInfo_pw) {
                $stmt = $db->prepare("SELECT `id` FROM `contact_authInfo` WHERE `contact_id` = :contact_id AND `authtype` = 'pw' AND `authinfo` = :authInfo_pw LIMIT 1");
                $stmt->execute([':contact_id' => $contact_id, ':authInfo_pw' => $authInfo_pw]);
                $contact_authinfo_id = $stmt->fetchColumn();

                if (!$contact_authinfo_id) {
                    sendEppError($conn, 2202, 'Invalid authorization information');
                    return;
                }
            }

            $stmt = $db->prepare("SELECT `crid`, `crdate`, `upid`, `update`, `trdate`, `trstatus`, `reid`, `redate`, `acid`, `acdate` FROM `contact` WHERE `id` = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($contactInfo['trstatus'] === 'pending') {
                // The losing registrar has five days once the contact is pending to respond.
                $updateStmt = $db->prepare("UPDATE `contact` SET `trstatus` = 'clientRejected' WHERE `id` = :contact_id");
                $updateStmt->execute([':contact_id' => $contact_id]);

                if ($updateStmt->errorCode() !== '00000') {
                    sendEppError($conn, 2400, 'Command failed');
                    return;
                } else {
                    $stmt = $db->prepare("SELECT `crid`, `crdate`, `upid`, `update`, `trdate`, `trstatus`, `reid`, `redate`, `acid`, `acdate` FROM `contact` WHERE `id` = :contact_id LIMIT 1");
                    $stmt->execute([':contact_id' => $contact_id]);
                    $contactInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                    // Fetch registrar identifiers
                    $reidStmt = $db->prepare("SELECT `clid` FROM `registrar` WHERE `id` = :reid LIMIT 1");
                    $reidStmt->execute([':reid' => $contactInfo['reid']]);
                    $reid_identifier = $reidStmt->fetchColumn();

                    $acidStmt = $db->prepare("SELECT `clid` FROM `registrar` WHERE `id` = :acid LIMIT 1");
                    $acidStmt->execute([':acid' => $contactInfo['acid']]);
                    $acid_identifier = $acidStmt->fetchColumn();
					
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
                        'svTRID' => generateSvTRID(),
                    ];

                    $epp = new EPP\EppWriter();
                    $xml = $epp->epp_writer($response);
                    sendEppResponse($conn, $xml);
                }
            } else {
                sendEppError($conn, 2301, 'Object not pending transfer');
                return;
            }
        } elseif ($op == 'request') {
            // Check if contact is within 60 days of its initial registration
            $stmt = $db->prepare("SELECT DATEDIFF(CURRENT_TIMESTAMP,`crdate`) FROM `contact` WHERE `id` = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $days_from_registration = $stmt->fetchColumn();

            if ($days_from_registration < 60) {
                sendEppError($conn, 2201, 'Authorization error');
                return;
            }

            // Check if contact is within 60 days of its last transfer
            $stmt = $db->prepare("SELECT `trdate`, DATEDIFF(CURRENT_TIMESTAMP,`trdate`) AS `intval` FROM `contact` WHERE `id` = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $last_trdate = $result['trdate'];
            $days_from_last_transfer = $result['intval'];

            if ($last_trdate && $days_from_last_transfer < 60) {
                sendEppError($conn, 2201, 'Authorization error');
                return;
            }

            // Check the <contact:authInfo> element
            $stmt = $db->prepare("SELECT `id` FROM `contact_authInfo` WHERE `contact_id` = :contact_id AND `authtype` = 'pw' AND `authinfo` = :authInfo_pw LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id, ':authInfo_pw' => $authInfo_pw]);
            $contact_authinfo_id = $stmt->fetchColumn();

            if (!$contact_authinfo_id) {
                sendEppError($conn, 2202, 'Invalid authorization information');
                return;
            }

            // Check if the contact name is subject to any special locks or holds
            $stmt = $db->prepare("SELECT `status` FROM `contact_status` WHERE `contact_id` = :contact_id");
            $stmt->execute([':contact_id' => $contact_id]);

            while ($status = $stmt->fetchColumn()) {
                if (preg_match("/.*(TransferProhibited)$/", $status) || preg_match("/^pending/", $status)) {
                    sendEppError($conn, 2304, 'Object status prohibits operation');
                    return;
                }
            }

            if ($clid == $registrar_id_contact) {
                sendEppError($conn, 2106, 'Object is not eligible for transfer');
                return;
            }

            $stmt = $db->prepare("SELECT `crid`,`crdate`,`upid`,`update`,`trdate`,`trstatus`,`reid`,`redate`,`acid`,`acdate` FROM `contact` WHERE `id` = :contact_id LIMIT 1");
            $stmt->execute([':contact_id' => $contact_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $trstatus = $result['trstatus'];

            if (!$trstatus || $trstatus != 'pending') {
                $waiting_period = 5; // days
                $stmt = $db->prepare("UPDATE `contact` SET `trstatus` = 'pending', `reid` = :registrar_id, `redate` = CURRENT_TIMESTAMP, `acid` = :registrar_id_contact, `acdate` = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL $waiting_period DAY) WHERE `id` = :contact_id");
                $stmt->execute([
                    ':registrar_id' => $clid,
                    ':registrar_id_contact' => $registrar_id_contact,
                    ':contact_id' => $contact_id
                ]);

                if ($stmt->errorCode() != '00000') {
                    sendEppError($conn, 2400, 'Command failed');
                    return;
                } else {
                    $stmt = $db->prepare("SELECT `crid`,`crdate`,`upid`,`update`,`trdate`,`trstatus`,`reid`,`redate`,`acid`,`acdate` FROM `contact` WHERE `id` = :contact_id LIMIT 1");
                    $stmt->execute([':contact_id' => $contact_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);

                    $reid_identifier = $db->query("SELECT `clid` FROM `registrar` WHERE `id` = '{$result['reid']}' LIMIT 1")->fetchColumn();
                    $acid_identifier = $db->query("SELECT `clid` FROM `registrar` WHERE `id` = '{$result['acid']}' LIMIT 1")->fetchColumn();

                    $db->prepare("INSERT INTO `poll` (`registrar_id`,`qdate`,`msg`,`msg_type`,`obj_name_or_id`,`obj_trStatus`,`obj_reID`,`obj_reDate`,`obj_acID`,`obj_acDate`,`obj_exDate`) VALUES(:registrar_id_contact, CURRENT_TIMESTAMP, 'Transfer requested.', 'contactTransfer', :identifier, 'pending', :reid_identifier, :redate, :acid_identifier, :acdate, NULL)")
                        ->execute([
                            ':registrar_id_contact' => $registrar_id_contact,
                            ':identifier' => $identifier,
                            ':reid_identifier' => $reid_identifier,
                            ':redate' => str_replace(" ", "T", $result['redate']) . '.0Z',
                            ':acid_identifier' => $acid_identifier,
                            ':acdate' => str_replace(" ", "T", $result['acdate']) . '.0Z'
                        ]);
						
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
                        'svTRID' => generateSvTRID(),
                    ];

                    $epp = new EPP\EppWriter();
                    $xml = $epp->epp_writer($response);
                    sendEppResponse($conn, $xml);
                }
            } elseif ($op == 'pending') {
                sendEppError($conn, 2300, 'Object pending transfer');
                return;
            }
        } else {
            sendEppError($conn, 2005, 'Parameter value syntax error');
            return;
        }
    }
}