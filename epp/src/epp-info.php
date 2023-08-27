<?php

function processContactInfo($conn, $db, $xml, $trans) {
    $contactID = (string) $xml->command->info->children('urn:ietf:params:xml:ns:contact-1.0')->info->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    // Validation for contact ID
    $invalid_identifier = validate_identifier($contactID);
    if ($invalid_identifier) {
        sendEppError($conn, $db, 2005, 'Invalid contact ID', $clTRID, $trans);
        return;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM contact WHERE identifier = :id");
        $stmt->execute(['id' => $contactID]);

        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            sendEppError($conn, $db, 2303, 'Contact does not exist', $clTRID, $trans);
            return;
        }
        
        // Fetch authInfo
        $stmt = $db->prepare("SELECT * FROM contact_authInfo WHERE contact_id = :id");
        $stmt->execute(['id' => $contact['id']]);
        $authInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch status
        $stmt = $db->prepare("SELECT * FROM contact_status WHERE contact_id = :id");
        $stmt->execute(['id' => $contact['id']]);
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $statusArray = [];
        foreach($statuses as $status) {
            $statusArray[] = [$status['status']];
        }

        // Fetch postal_info
        $stmt = $db->prepare("SELECT * FROM contact_postalInfo WHERE contact_id = :id");
        $stmt->execute(['id' => $contact['id']]);
        $postals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $postalArray = [];
        foreach ($postals as $postal) {
            $postalType = $postal['type']; // 'int' or 'loc'
                $postalArray[$postalType] = [
                'name' => $postal['name'],
                'org' => $postal['org'],
                'street' => [$postal['street1'], $postal['street2'], $postal['street3']],
                'city' => $postal['city'],
                'sp' => $postal['sp'],
                'pc' => $postal['pc'],
                'cc' => $postal['cc']
            ];
        }
        
        $svTRID = generateSvTRID();
        $response = [
            'command' => 'info_contact',
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
            'resultCode' => 1000,
            'msg' => 'Command completed successfully',
            'id' => $contact['id'],
            'roid' => 'C_'.$contact['identifier'],
            'status' => $statusArray,
            'postal' => $postalArray,
            'voice' => $contact['voice'],
            'fax' => $contact['fax'],
            'email' => $contact['email'],
            'clID' => getRegistrarClid($db, $contact['clid']),
            'crID' => getRegistrarClid($db, $contact['crid']),
            'crDate' => $contact['crdate'],
            'upID' => getRegistrarClid($db, $contact['upid']),
            'upDate' => $contact['update'],
            'authInfo' => 'valid',
            'authInfo_type' => $authInfo['authtype'],
            'authInfo_val' => $authInfo['authinfo']
        ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'info', 'contact', 'C_'.$contact['identifier'], 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);

    } catch (PDOException $e) {
        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
    }
}

function processHostInfo($conn, $db, $xml, $trans) {
    $hostName = $xml->command->info->children('urn:ietf:params:xml:ns:host-1.0')->info->name;
    $clTRID = (string) $xml->command->clTRID;

    // Validation for host name
    if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $hostName) && strlen($hostName) > 254) {
        sendEppError($conn, $db, 2005, 'Invalid host name', $clTRID, $trans);
        return;
    }
	
    try {
        $stmt = $db->prepare("SELECT * FROM host WHERE name = :name");
        $stmt->execute(['name' => $hostName]);

        $host = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$host) {
            sendEppError($conn, $db, 2303, 'Host does not exist', $clTRID, $trans);
            return;
        }
		
        // Fetch addresses
        $stmt3 = $db->prepare("SELECT `addr`, `ip` FROM `host_addr` WHERE `host_id` = :id");
        $stmt3->execute(['id' => $host['id']]);
        $addresses = $stmt3->fetchAll(PDO::FETCH_ASSOC);

        $addrArray = [];
        foreach($addresses as $addr) {
            $addrArray[] = [$addr['ip'] === 'v4' ? 4 : 6, $addr['addr']];
        }
        
        // Fetch status
        $stmt = $db->prepare("SELECT * FROM host_status WHERE host_id = :id");
        $stmt->execute(['id' => $host['id']]);
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $statusArray = [];
        foreach($statuses as $status) {
            $statusArray[] = [$status['status']];
        }
		
        // Check for 'linked' status
        $stmt2 = $db->prepare("SELECT domain_id FROM domain_host_map WHERE host_id = :id LIMIT 1");
        $stmt2->execute(['id' => $host['id']]);
        $domainData = $stmt2->fetch(PDO::FETCH_ASSOC);

        if ($domainData) {
            $statusArray[] = ['linked'];
        }

        $svTRID = generateSvTRID();
        $response = [
            'command' => 'info_host',
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
            'resultCode' => 1000,
            'msg' => 'Command completed successfully',
            'name' => $host['name'],
            'roid' => 'H_'.$host['id'],
            'status' => $statusArray,
            'addr' => $addrArray,
            'clID' => getRegistrarClid($db, $host['clid']),
            'crID' => getRegistrarClid($db, $host['crid']),
            'crDate' => $host['crdate'],
            'upID' => getRegistrarClid($db, $host['upid']),
            'upDate' => $host['update'],
            'trDate' => $host['trdate']
        ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'info', 'host', 'H_'.$host['id'], 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
    } catch (PDOException $e) {
        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
    }
}

function processDomainInfo($conn, $db, $xml, $trans) {
    $domainName = $xml->command->info->children('urn:ietf:params:xml:ns:domain-1.0')->info->name;
    $clTRID = (string) $xml->command->clTRID;

    // Validation for domain name
    $invalid_label = validate_label($domainName, $db);
    if ($invalid_label) {
        sendEppError($conn, $db, 2005, 'Invalid domain name', $clTRID, $trans);
        return;
    }
	
    if (!filter_var($domainName, FILTER_VALIDATE_DOMAIN)) {
        sendEppError($conn, $db, 2005, 'Invalid domain name', $clTRID, $trans);
        return;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM domain WHERE name = :name");
        $stmt->execute(['name' => $domainName]);

        $domain = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$domain) {
            sendEppError($conn, $db, 2303, 'Domain does not exist', $clTRID, $trans);
            return;
        }
        
        // Fetch contacts
        $stmt = $db->prepare("SELECT * FROM domain_contact_map WHERE domain_id = :id");
        $stmt->execute(['id' => $domain['id']]);
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $transformedContacts = [];
        foreach ($contacts as $contact) {
            $transformedContacts[] = [$contact['type'], getContactIdentifier($db, $contact['contact_id'])];
        }
		
        // Fetch hosts
        $stmt = $db->prepare("SELECT * FROM domain_host_map WHERE domain_id = :id");
        $stmt->execute(['id' => $domain['id']]);
        $hosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $transformedHosts = [];
        if ($hosts) {
            foreach ($hosts as $host) {
                $transformedHosts[] = [getHost($db, $host['host_id'])];
            }
        }
        
        $stmt = $db->prepare("SELECT name FROM host WHERE domain_id = :id");
        $stmt->execute(['id' => $domain['id']]);
        $hostNames = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        // Fetch authInfo
        $stmt = $db->prepare("SELECT * FROM domain_authInfo WHERE domain_id = :id");
        $stmt->execute(['id' => $domain['id']]);
        $authInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch status
        $stmt = $db->prepare("SELECT * FROM domain_status WHERE domain_id = :id");
        $stmt->execute(['id' => $domain['id']]);
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $statusArray = [];
        foreach($statuses as $status) {
            $statusArray[] = [$status['status']];
        }
		
        // Fetch secDNS data
        $stmt = $db->prepare("SELECT * FROM secdns WHERE domain_id = :id");
        $stmt->execute(['id' => $domain['id']]);
        $secDnsRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        }

        // Fetch RGP status
        $rgpstatus = isset($domain['rgpstatus']) && $domain['rgpstatus'] ? $domain['rgpstatus'] : null;

        $svTRID = generateSvTRID();
        $response = [
            'command' => 'info_domain',
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
            'resultCode' => 1000,
            'msg' => 'Command completed successfully',
            'name' => $domain['name'],
            'roid' => 'D_'.$domain['id'],
            'status' => $statusArray,
            'registrant' => $domain['registrant'],
            'contact' => $transformedContacts,
            'clID' => getRegistrarClid($db, $domain['clid']),
            'crID' => getRegistrarClid($db, $domain['crid']),
            'crDate' => $domain['crdate'],
            'exDate' => $domain['exdate'],
            'authInfo' => 'valid',
            'authInfo_type' => $authInfo['authtype'],
            'authInfo_val' => $authInfo['authinfo']
        ];
        // Conditionally add upID, upDate, and trDate to the response
        if (isset($domain['upid']) && $domain['upid']) {
            $response['upID'] = getRegistrarClid($db, $domain['upid']);
        }
        if (isset($domain['update']) && $domain['update']) {
            $response['upDate'] = $domain['update'];
        }
        if (isset($domain['trdate']) && $domain['trdate']) {
            $response['trDate'] = $domain['trdate'];
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

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'info', 'domain', 'D_'.$domain['id'], 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
    } catch (PDOException $e) {
        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
    }
}

function processFundsInfo($conn, $db, $xml, $clid, $trans) {
    $clTRID = (string) $xml->command->clTRID;

    try {
        $stmt = $db->prepare("SELECT accountBalance, creditLimit, creditThreshold, thresholdType, currency FROM registrar WHERE clid = :id");
        $stmt->execute(['id' => $clid]);

        $funds = $stmt->fetch(PDO::FETCH_ASSOC);
		
        $creditBalance = ($funds['accountBalance'] < 0) ? -$funds['accountBalance'] : 0;
        $availableCredit = $funds['creditLimit'] - $creditBalance;
		$availableCredit = number_format($availableCredit, 2, '.', '');

        if (!$funds) {
            sendEppError($conn, $db, 2303, 'Registrar does not exist', $clTRID, $trans);
            return;
        }
        
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