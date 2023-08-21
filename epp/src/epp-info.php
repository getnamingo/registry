<?php

function processContactInfo($conn, $db, $xml) {
    $contactID = (string) $xml->command->info->children('urn:ietf:params:xml:ns:contact-1.0')->info->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    // Validation for contact ID
    $invalid_identifier = validate_identifier($contactID);
    if ($invalid_identifier) {
        sendEppError($conn, 2005, 'Invalid contact ID');
        return;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM contact WHERE identifier = :id");
        $stmt->execute(['id' => $contactID]);

        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            sendEppError($conn, 2303, 'Object does not exist');
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
        
        $response = [
            'command' => 'info_contact',
            'clTRID' => $clTRID,
            'svTRID' => generateSvTRID(),
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
    sendEppResponse($conn, $xml);

    } catch (PDOException $e) {
        sendEppError($conn, 2400, 'Database error');
    }
}

function processHostInfo($conn, $db, $xml) {
    $hostName = $xml->command->info->children('urn:ietf:params:xml:ns:host-1.0')->info->name;
    $clTRID = (string) $xml->command->clTRID;

    // Validation for host name
    if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $hostName) && strlen($hostName) > 254) {
        sendEppError($conn, 2005, 'Invalid host name');
        return;
    }
	
    try {
        $stmt = $db->prepare("SELECT * FROM host WHERE name = :name");
        $stmt->execute(['name' => $hostName]);

        $host = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$host) {
            sendEppError($conn, 2303, 'Object does not exist');
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

        $response = [
            'command' => 'info_host',
            'clTRID' => $clTRID,
            'svTRID' => generateSvTRID(),
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
    sendEppResponse($conn, $xml);
    } catch (PDOException $e) {
        sendEppError($conn, 2400, 'Database error');
    }
}

function processDomainInfo($conn, $db, $xml) {
    $domainName = $xml->command->info->children('urn:ietf:params:xml:ns:domain-1.0')->info->name;
    $clTRID = (string) $xml->command->clTRID;

    // Validation for domain name
    $invalid_label = validate_label($domainName, $db);
    if ($invalid_label) {
        sendEppError($conn, 2005, 'Invalid domain name');
        return;
    }
	
    if (!filter_var($domainName, FILTER_VALIDATE_DOMAIN)) {
        sendEppError($conn, 2005, 'Invalid domain name');
        return;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM domain WHERE name = :name");
        $stmt->execute(['name' => $domainName]);

        $domain = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$domain) {
            sendEppError($conn, 2303, 'Object does not exist');
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
        foreach ($hosts as $host) {
            $transformedHosts[] = [getHost($db, $host['host_id'])];
        }

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

        $response = [
            'command' => 'info_domain',
            'clTRID' => $clTRID,
            'svTRID' => generateSvTRID(),
            'resultCode' => 1000,
            'msg' => 'Command completed successfully',
            'name' => $domain['name'],
            'roid' => 'D_'.$domain['id'],
            'status' => $statusArray,
            'registrant' => $domain['registrant'],
            'contact' => $transformedContacts,
            'hostObj' => $transformedHosts,
            'clID' => getRegistrarClid($db, $domain['clid']),
            'crID' => getRegistrarClid($db, $domain['crid']),
            'crDate' => $domain['crdate'],
            'upID' => getRegistrarClid($db, $domain['upid']),
            'upDate' => $domain['update'],
            'exDate' => $domain['exdate'],
            'trDate' => $domain['trdate'],
            'authInfo' => 'valid',
            'authInfo_type' => $authInfo['authtype'],
            'authInfo_val' => $authInfo['authinfo']
        ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    sendEppResponse($conn, $xml);
    } catch (PDOException $e) {
        sendEppError($conn, 2400, 'Database error');
    }
}

function processFundsInfo($conn, $db, $xml, $clid) {
    $clTRID = (string) $xml->command->clTRID;

    try {
        $stmt = $db->prepare("SELECT accountBalance, creditLimit, creditThreshold, thresholdType, currency FROM registrar WHERE clid = :id");
        $stmt->execute(['id' => $clid]);

        $funds = $stmt->fetch(PDO::FETCH_ASSOC);
		
        $creditBalance = ($funds['accountBalance'] < 0) ? -$funds['accountBalance'] : 0;
        $availableCredit = $funds['creditLimit'] - $creditBalance;
		$availableCredit = number_format($availableCredit, 2, '.', '');

        if (!$funds) {
            sendEppError($conn, 2303, 'Registrar does not exist');
            return;
        }
        
        $response = [
            'command' => 'info_funds',
            'clTRID' => $clTRID,
            'svTRID' => generateSvTRID(),
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
    sendEppResponse($conn, $xml);

    } catch (PDOException $e) {
        sendEppError($conn, 2400, 'Database error');
    }
}