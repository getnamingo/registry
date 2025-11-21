<?php

function processContactInfo($conn, $db, $xml, $clid, $trans) {
    $contactID = (string) $xml->command->info->children('urn:ietf:params:xml:ns:contact-1.0')->info->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    if (!$contactID) {
        sendEppError($conn, $db, 2003, 'Missing contact:id', $clTRID, $trans);
        return;
    }

    $invalid_identifier = validate_identifier($contactID);
    if ($invalid_identifier) {
        sendEppError($conn, $db, 2005, 'Invalid contact ID', $clTRID, $trans);
        return;
    }

    try {
        $stmt = $db->prepare("
            SELECT c.id, c.identifier, c.voice, c.fax, c.email, c.clid, c.crid, c.crdate, c.upid, c.lastupdate,
                c.disclose_voice, c.disclose_fax, c.disclose_email, c.nin, c.nin_type, c.validation, c.validation_stamp, c.validation_log,
                p.type AS postal_type, p.name, p.org, p.street1, p.street2, p.street3, p.city, p.sp, p.pc, p.cc,
                p.disclose_name_int, p.disclose_name_loc, p.disclose_org_int, p.disclose_org_loc, 
                p.disclose_addr_int, p.disclose_addr_loc,
                a.authtype, a.authinfo
            FROM contact c
            LEFT JOIN contact_postalInfo p ON c.id = p.contact_id
            LEFT JOIN contact_authInfo a ON c.id = a.contact_id
            WHERE c.identifier = :id
        ");
        $stmt->execute(['id' => $contactID]);
        $contact = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$contact) {
            sendEppError($conn, $db, 2303, 'Contact does not exist', $clTRID, $trans);
            return;
        }

        $clidNumeric = getClid($db, $clid);
        if ($clidNumeric !== (int)$contact[0]['clid']) {
            sendEppError($conn, $db, 2201, 'Client is not the sponsor of the contact object', $clTRID, $trans);
            return;
        }

        $contactRow = $contact[0];

        // Extract Postal Info
        $postalArray = [];
        foreach ($contact as $row) {
            $postalType = $row['postal_type']; // 'int' or 'loc'
            if ($postalType) {
                $postalArray[$postalType] = [
                    'name' => $row['name'],
                    'org' => $row['org'],
                    'street' => array_filter([$row['street1'], $row['street2'], $row['street3']]),
                    'city' => $row['city'],
                    'sp' => $row['sp'],
                    'pc' => $row['pc'],
                    'cc' => $row['cc']
                ];
            }
        }

        $stmt = $db->prepare("SELECT status FROM contact_status WHERE contact_id = :id");
        $stmt->execute(['id' => $contactRow['id']]);
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor();
        $statusArray = array_map(fn($status) => [$status], $statuses);

        // Handle Disclose Fields (Only Show When Set to `1`)
        $disclose_fields = [];

        if ($contactRow['disclose_name_int'] === '1') {
            $disclose_fields[] = ['name' => 'name', 'type' => 'int'];
        }
        if ($contactRow['disclose_name_loc'] === '1') {
            $disclose_fields[] = ['name' => 'name', 'type' => 'loc'];
        }
        if ($contactRow['disclose_org_int'] === '1') {
            $disclose_fields[] = ['name' => 'org', 'type' => 'int'];
        }
        if ($contactRow['disclose_org_loc'] === '1') {
            $disclose_fields[] = ['name' => 'org', 'type' => 'loc'];
        }
        if ($contactRow['disclose_addr_int'] === '1') {
            $disclose_fields[] = ['name' => 'addr', 'type' => 'int'];
        }
        if ($contactRow['disclose_addr_loc'] === '1') {
            $disclose_fields[] = ['name' => 'addr', 'type' => 'loc'];
        }
        if ($contactRow['disclose_voice'] === '1') {
            $disclose_fields[] = ['name' => 'voice'];
        }
        if ($contactRow['disclose_fax'] === '1') {
            $disclose_fields[] = ['name' => 'fax'];
        }
        if ($contactRow['disclose_email'] === '1') {
            $disclose_fields[] = ['name' => 'email'];
        }

        $stmt = $db->query("SELECT value FROM settings WHERE name = 'handle'");
        $roid = $stmt->fetchColumn();
        $stmt->closeCursor();

        $svTRID = generateSvTRID();
        $response = [
            'command' => 'info_contact',
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
            'resultCode' => 1000,
            'msg' => 'Command completed successfully',
            'id' => $contactRow['identifier'],
            'roid' => 'C' . $contactRow['id'] . '-' . $roid,
            'status' => $statusArray,
            'postal' => $postalArray,
            'voice' => $contactRow['voice'],
            'fax' => $contactRow['fax'],
            'email' => $contactRow['email'],
            'clID' => getRegistrarClid($db, $contactRow['clid']),
            'crID' => getRegistrarClid($db, $contactRow['crid']),
            'crDate' => $contactRow['crdate'],
            'upID' => getRegistrarClid($db, $contactRow['upid']),
            'upDate' => $contactRow['lastupdate'],
            'authInfo' => 'valid',
            'authInfo_type' => $contactRow['authtype'],
            'authInfo_val' => $contactRow['authinfo']
        ];

        if (!empty($disclose_fields)) {
            $response['disclose'] = [
                'flag' => '1',
                'fields' => $disclose_fields
            ];
        }

        if (!empty($contactRow['nin']) && !empty($contactRow['nin_type'])) {
            $response['nin'] = $contactRow['nin'];
            $response['nin_type'] = $contactRow['nin_type'];

            if (!is_null($contactRow['validation'])) {
                $response['validation'] = $contactRow['validation'];
            }

            if (!is_null($contactRow['validation_stamp'])) {
                $response['validation_stamp'] = $contactRow['validation_stamp'];
            }

            if (!empty($contactRow['validation_log'])) {
                $response['validation_log'] = $contactRow['validation_log'];
            }
        }

        $epp = new EPP\EppWriter();
        $xml = $epp->epp_writer($response);
        updateTransaction($db, 'info', 'contact', $contactID, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
        sendEppResponse($conn, $xml);

    } catch (PDOException $e) {
        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
    }
}

function processHostInfo($conn, $db, $xml, $trans) {
    $hostName = $xml->command->info->children('urn:ietf:params:xml:ns:host-1.0')->info->name;
    $clTRID = (string) $xml->command->clTRID;

    if (!$hostName) {
        sendEppError($conn, $db, 2003, 'Specify your host name', $clTRID, $trans);
        return;
    }

    if (!validateHostName($hostName)) {
        sendEppError($conn, $db, 2005, 'Invalid host name', $clTRID, $trans);
        return;
    }

    try {
        $stmt = $db->prepare("
            SELECT id, name, clid, crid, upid, crdate, lastupdate, trdate
            FROM host
            WHERE name = :name
            LIMIT 1
        ");
        $stmt->execute(['name' => $hostName]);
        $host = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$host) {
            sendEppError($conn, $db, 2303, 'Host does not exist', $clTRID, $trans);
            return;
        }
        
        $stmt3 = $db->prepare("SELECT addr, ip FROM host_addr WHERE host_id = :id");
        $stmt3->execute(['id' => $host['id']]);
        $addresses = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        $stmt3->closeCursor();

        $addrArray = [];
        foreach($addresses as $addr) {
            $addrArray[] = [$addr['ip'] === 'v4' ? 4 : 6, $addr['addr']];
        }
        
        $stmt = $db->prepare("SELECT status FROM host_status WHERE host_id = :id");
        $stmt->execute(['id' => $host['id']]);
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor();

        $statusArray = [];
        foreach ($statuses as $status) {
            $statusArray[] = [$status];
        }

        $stmt2 = $db->prepare("SELECT domain_id FROM domain_host_map WHERE host_id = :id LIMIT 1");
        $stmt2->execute(['id' => $host['id']]);
        $domainData = $stmt2->fetch(PDO::FETCH_ASSOC);
        $stmt2->closeCursor();

        if ($domainData) {
            $statusArray[] = ['linked'];
        }
        
        $stmt = $db->query("SELECT value FROM settings WHERE name = 'handle'");
        $roid = $stmt->fetchColumn();
        $stmt->closeCursor();

        $svTRID = generateSvTRID();
        $response = [
            'command' => 'info_host',
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
            'resultCode' => 1000,
            'msg' => 'Command completed successfully',
            'name' => $host['name'],
            'roid' => 'H' . $host['id'] . '-' . $roid,
            'status' => $statusArray,
            'addr' => $addrArray,
            'clID' => getRegistrarClid($db, $host['clid']),
            'crID' => getRegistrarClid($db, $host['crid']),
            'crDate' => $host['crdate'],
            'upID' => getRegistrarClid($db, $host['upid']),
            'upDate' => $host['lastupdate'],
            'trDate' => $host['trdate']
        ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'info', 'host', $host['name'], 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
    } catch (PDOException $e) {
        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
    }
}

function processDomainInfo($conn, $db, $xml, $clid, $trans) {
    $domainName = $xml->command->info->children('urn:ietf:params:xml:ns:domain-1.0')->info->name;
    $clTRID = (string) $xml->command->clTRID;

    if (!$domainName) {
        sendEppError($conn, $db, 2003, 'Please specify a domain name', $clTRID, $trans);
        return;
    }

    $extensionNode   = $xml->command->extension;
    $launch_info     = null;
    $allocation_token = null;

    if (isset($extensionNode)) {
        $launch_nodes = $xml->xpath('//launch:info');
        if (!empty($launch_nodes)) {
            $launch_info = $launch_nodes[0];
        }

        $alloc_nodes = $xml->xpath('//allocationToken:info');
        if (!empty($alloc_nodes)) {
            $allocation_token = $alloc_nodes[0];
        }
    }

    $result = $xml->xpath('//domain:authInfo/domain:pw[1]');
    $authInfo_pw = !empty($result) ? (string)$result[0] : null;

    $invalid_label = validate_label($domainName, $db);
    if ($invalid_label) {
        sendEppError($conn, $db, 2005, 'Invalid domain name', $clTRID, $trans);
        return;
    }

    if (!filter_var($domainName, FILTER_VALIDATE_DOMAIN)) {
        sendEppError($conn, $db, 2005, 'Invalid domain name', $clTRID, $trans);
        return;
    }

    if (isset($launch_info)) {
        $phaseType = (string) $launch_info->children('urn:ietf:params:xml:ns:launch-1.0')->phase;
            
        // Get the <launch:applicationID> element if it exists
        $applicationIDElement = $launch_info->children('urn:ietf:params:xml:ns:launch-1.0')->applicationID;

        // Check if the <launch:applicationID> element is present and set the variable accordingly
        $applicationID = isset($applicationIDElement) ? (string) $applicationIDElement : null;
            
        // Get the attributes of the <launch:info> node
        $attributes = $launch_info->attributes('launch', true);
        $includeMark = (string) ($attributes->includeMark ?? 'false');

        // Check if includeMark is 'true'
        $includeMarkBool = strtolower($includeMark) === 'true';
        
        try {
            $query = "SELECT * FROM application WHERE name = :name AND phase_type = :phase";

            if (!empty($applicationID)) {
                $query .= " AND application_id = :applicationID";
            }

            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $domainName);
            $stmt->bindParam(':phase', $phaseType);

            if (!empty($applicationID)) {
                $stmt->bindParam(':applicationID', $applicationID);
            }
            
            $stmt->execute();
            
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!$domain) {
                sendEppError($conn, $db, 2303, 'Application does not exist', $clTRID, $trans);
                return;
            }

            // Fetch contacts
            $stmt = $db->prepare("SELECT * FROM application_contact_map WHERE domain_id = :id");
            $stmt->execute(['id' => $domain['id']]);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            $transformedContacts = [];
            foreach ($contacts as $contact) {
                $transformedContacts[] = [$contact['type'], getContactIdentifier($db, $contact['contact_id'])];
            }
            
            // Fetch hosts
            $stmt = $db->prepare("SELECT * FROM application_host_map WHERE domain_id = :id");
            $stmt->execute(['id' => $domain['id']]);
            $hosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            $transformedHosts = [];
            if ($hosts) {
                foreach ($hosts as $host) {
                    $transformedHosts[] = [getHost($db, $host['host_id'])];
                }
            }
            
            // Fetch status
            $stmt = $db->prepare("SELECT * FROM application_status WHERE domain_id = :id LIMIT 1");
            $stmt->execute(['id' => $domain['id']]);
            $status = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            // Fetch registrant identifier
            $stmt = $db->prepare("SELECT identifier FROM contact WHERE id = :id");
            $stmt->execute(['id' => $domain['registrant']]);
            $registrant_id = $stmt->fetch(PDO::FETCH_COLUMN);
            $stmt->closeCursor();
            
            $stmt = $db->query("SELECT value FROM settings WHERE name = 'handle'");
            $roid = $stmt->fetchColumn();
            $stmt->closeCursor();

            $svTRID = generateSvTRID();
            $response = [
                'command' => 'info_domain',
                'clTRID' => $clTRID,
                'svTRID' => $svTRID,
                'resultCode' => 1000,
                'msg' => 'Command completed successfully',
                'name' => $domain['name'],
                'roid' => 'A' . $domain['id'] . '-' . $roid,
                'status' => $status['status'],
                'contact' => $transformedContacts,
                'clID' => getRegistrarClid($db, $domain['clid']),
                'crID' => getRegistrarClid($db, $domain['crid']),
                'crDate' => $domain['crdate'],
                'exDate' => $domain['exdate']
            ];
            if ($registrant_id !== null && $registrant_id !== false) {
                $response['registrant'] = $registrant_id;
            }
            if (isset($domain['authinfo'])) {
                $response['authInfo'] = 'valid';
                $response['authInfo_type'] = $domain['authtype'];
                $response['authInfo_val'] = $domain['authinfo'];
            } else {
                $response['authInfo'] = 'invalid';
            }
            
            // Conditionally add hostObj if hosts are available from domain_host_map
            if (!empty($transformedHosts)) {
                $response['hostObj'] = $transformedHosts;
            }
            
            $response['launch_phase'] = $phaseType;
            $response['launch_application_id'] = $applicationID;
            $response['launch_status'] = $status['status'];
            if ($includeMarkBool) {
                $response['launch_mark'] = $domain['smd'];
            }
            
            $epp = new EPP\EppWriter();
            $xml = $epp->epp_writer($response);
            updateTransaction($db, 'info', 'domain', $domain['name'], 1000, 'Command completed successfully', $svTRID, $xml, $trans);
            sendEppResponse($conn, $xml);
        } catch (PDOException $e) {
            sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
        }
    } else {
        try {
            $stmt = $db->prepare("SELECT * FROM domain WHERE name = :name");
            $stmt->bindParam(':name', $domainName);
            $stmt->execute();
            
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!$domain) {
                sendEppError($conn, $db, 2303, 'Domain does not exist', $clTRID, $trans);
                return;
            }
            
            $domain_authinfo_id = null;
            if ($authInfo_pw) {
                $stmt = $db->prepare("SELECT id FROM domain_authInfo WHERE domain_id = ? AND authtype = 'pw' AND authinfo = ? LIMIT 1");
                $stmt->execute([$domain['id'], $authInfo_pw]);
                $domain_authinfo_id = $stmt->fetchColumn();
                $stmt->closeCursor();

                if (!$domain_authinfo_id) {
                    sendEppError($conn, $db, 2202, 'authInfo pw is not correct', $clTRID, $trans);
                    return;
                }
            }
            
            // Fetch contacts
            $stmt = $db->prepare("SELECT * FROM domain_contact_map WHERE domain_id = :id");
            $stmt->execute(['id' => $domain['id']]);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            $transformedContacts = [];
            foreach ($contacts as $contact) {
                $transformedContacts[] = [$contact['type'], getContactIdentifier($db, $contact['contact_id'])];
            }
            
            // Fetch hosts
            $stmt = $db->prepare("SELECT * FROM domain_host_map WHERE domain_id = :id");
            $stmt->execute(['id' => $domain['id']]);
            $hosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            $transformedHosts = [];
            if ($hosts) {
                foreach ($hosts as $host) {
                    $transformedHosts[] = [getHost($db, $host['host_id'])];
                }
            }
            
            $stmt = $db->prepare("SELECT name FROM host WHERE domain_id = :id");
            $stmt->execute(['id' => $domain['id']]);
            $hostNames = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            $stmt->closeCursor();

            // Fetch authInfo
            $stmt = $db->prepare("SELECT * FROM domain_authInfo WHERE domain_id = :id");
            $stmt->execute(['id' => $domain['id']]);
            $authInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            // Fetch status
            $stmt = $db->prepare("SELECT * FROM domain_status WHERE domain_id = :id");
            $stmt->execute(['id' => $domain['id']]);
            $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            $statusArray = [];
            if (!empty($statuses)) {
                foreach ($statuses as $status) {
                    $statusArray[] = [$status['status']];
                }
            } else {
                $statusArray[] = ['ok'];
            }

            // Fetch secDNS data
            $stmt = $db->prepare("SELECT * FROM secdns WHERE domain_id = :id");
            $stmt->execute(['id' => $domain['id']]);
            $secDnsRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            // Fetch registrant identifier
            $stmt = $db->prepare("SELECT identifier FROM contact WHERE id = :id");
            $stmt->execute(['id' => $domain['registrant']]);
            $registrant_id = $stmt->fetch(PDO::FETCH_COLUMN);
            $stmt->closeCursor();

            $transformedSecDnsRecords = [];
            if ($secDnsRecords) {
                foreach ($secDnsRecords as $record) {
                    $tmpRecord = [
                        'keyTag' => $record['keytag'],
                        'alg' => $record['alg'],
                        'digestType' => $record['digesttype'],
                        'digest' => $record['digest']
                    ];

                    // Add optional fields if they are not null
                    if (!is_null($record['maxsiglife'])) {
                        $tmpRecord['maxSigLife'] = $record['maxsiglife'];
                    }
                    if (!is_null($record['flags'])) {
                        $tmpRecord['keyData']['flags'] = $record['flags'];
                    }
                    if (!is_null($record['protocol'])) {
                        $tmpRecord['keyData']['protocol'] = $record['protocol'];
                    }
                    if (!is_null($record['keydata_alg'])) {
                        $tmpRecord['keyData']['alg'] = $record['keydata_alg'];
                    }
                    if (!is_null($record['pubkey'])) {
                        $tmpRecord['keyData']['pubKey'] = $record['pubkey'];
                    }
            
                    $transformedSecDnsRecords[] = $tmpRecord;
                }

                usort($transformedSecDnsRecords, fn($a, $b) => $a['keyTag'] <=> $b['keyTag']);
            }

            // Fetch RGP status
            $rgpstatus = isset($domain['rgpstatus']) && $domain['rgpstatus'] ? $domain['rgpstatus'] : null;
            
            $stmt = $db->query("SELECT value FROM settings WHERE name = 'handle'");
            $roid = $stmt->fetchColumn();
            $stmt->closeCursor();

            $svTRID = generateSvTRID();
            $response = [
                'command' => 'info_domain',
                'clTRID' => $clTRID,
                'svTRID' => $svTRID,
                'resultCode' => 1000,
                'msg' => 'Command completed successfully',
                'name' => $domain['name'],
                'roid' => 'D' . $domain['id'] . '-' . $roid,
                'status' => $statusArray,
                'contact' => $transformedContacts,
                'clID' => getRegistrarClid($db, $domain['clid']),
                'crID' => getRegistrarClid($db, $domain['crid']),
                'crDate' => $domain['crdate'],
                'exDate' => $domain['exdate']
            ];
            if ($registrant_id !== null && $registrant_id !== false) {
                $response['registrant'] = $registrant_id;
            }
            // Conditionally add upID, upDate, and trDate to the response
            if (isset($domain['upid']) && $domain['upid']) {
                $response['upID'] = getRegistrarClid($db, $domain['upid']);
            }
            if (isset($domain['lastupdate']) && $domain['lastupdate']) {
                $response['upDate'] = $domain['lastupdate'];
            }
            if (isset($domain['trdate']) && $domain['trdate']) {
                $response['trDate'] = $domain['trdate'];
            }
            if ($clid == $domain['clid']) {
                $response['authInfo'] = 'valid';
                $response['authInfo_type'] = $authInfo['authtype'];
                $response['authInfo_val'] = $authInfo['authinfo'];
            } else if (isset($domain_authinfo_id) && $domain_authinfo_id) {
                $response['authInfo'] = 'valid';
                $response['authInfo_type'] = $authInfo['authtype'];
                $response['authInfo_val'] = $authInfo['authinfo'];
            } else {
                $response['authInfo'] = 'invalid';
            }

            // Conditionally add hostObj if hosts are available from domain_host_map
            if (!empty($transformedHosts)) {
                $response['hostObj'] = $transformedHosts;
            }

            // Conditionally add hostName if hosts are available from host
            if (!empty($hostNames)) {
                $response['host'] = $hostNames;
            }

            // Add secDNS records to response if they exist
            if ($transformedSecDnsRecords) {
                $response['secDNS'] = $transformedSecDnsRecords;
            }

            // Add RGP status to response if it exists
            if ($rgpstatus) {
                $response['rgpstatus'] = $rgpstatus;
            }
            
            if ($allocation_token !== null) {
                $stmt = $db->prepare("SELECT token FROM allocation_tokens WHERE domain_name = :domainName LIMIT 1");
                $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
                $stmt->execute();
                $token = $stmt->fetchColumn();
                $stmt->closeCursor();
                        
                if ($token) {
                    $response['allocation'] = $token;
                } else {
                    $response['allocation'] = 'ERROR';
                }
            }
            
            $epp = new EPP\EppWriter();
            $xml = $epp->epp_writer($response);
            updateTransaction($db, 'info', 'domain', $domain['name'], 1000, 'Command completed successfully', $svTRID, $xml, $trans);
            sendEppResponse($conn, $xml);
        } catch (PDOException $e) {
            sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
        }
    }

}

function processFundsInfo($conn, $db, $xml, $clid, $trans) {
    $clTRID = (string) $xml->command->clTRID;

    try {
        $stmt = $db->prepare("
            SELECT accountBalance, creditLimit, creditThreshold, thresholdType, currency
            FROM registrar
            WHERE clid = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $clid]);
        $funds = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$funds) {
            sendEppError($conn, $db, 2303, 'Registrar does not exist', $clTRID, $trans);
            return;
        }

        $creditBalance = ($funds['accountBalance'] < 0) ? -$funds['accountBalance'] : 0;
        $availableCredit = $funds['creditLimit'] - $creditBalance;
        $availableCredit = number_format($availableCredit, 2, '.', '');

        $svTRID = generateSvTRID();
        $response = [
            'command' => 'info_funds',
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
            'resultCode' => 1000,
            'msg' => 'Command completed successfully',
            'funds' => $funds['accountBalance'],
            'currency' => $funds['currency'],
            'availableCredit' => $availableCredit,
            'creditLimit' => $funds['creditLimit'],
            'creditThreshold' => $funds['creditThreshold'],
            'thresholdType' => $funds['thresholdType']
        ];

        $epp = new EPP\EppWriter();
        $xml = $epp->epp_writer($response);
        updateTransaction($db, 'info', null, $funds['accountBalance'], 1000, 'Command completed successfully', $svTRID, $xml, $trans);
        sendEppResponse($conn, $xml);
    } catch (PDOException $e) {
        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
    }
}