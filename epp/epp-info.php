<?php

function processContactInfo($conn, $db, $xml) {
    $contactID = (string) $xml->command->info->children('urn:ietf:params:xml:ns:contact-1.0')->info->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    // Validation for contact ID
    if (!ctype_alnum($contactID) || strlen($contactID) > 255) {
        sendEppError($conn, 2005, 'Invalid contact ID');
        return;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM contact WHERE id = :id");
        $stmt->execute(['id' => $contactID]);

        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            sendEppError($conn, 2303, 'Object does not exist');
            return;
        }
		
        // Fetch authInfo
        $stmt = $db->prepare("SELECT * FROM contact_authInfo WHERE contact_id = :id");
        $stmt->execute(['id' => $contactID]);
        $authInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch status
        $stmt = $db->prepare("SELECT * FROM contact_status WHERE contact_id = :id");
        $stmt->execute(['id' => $contactID]);
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $statusArray = [];
        foreach($statuses as $status) {
            $statusArray[] = [$status['status']];
        }

        // Fetch postal_info
        $stmt = $db->prepare("SELECT * FROM contact_postalInfo WHERE contact_id = :id");
        $stmt->execute(['id' => $contactID]);
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
        	'roid' => $contact['identifier'],
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

function processDomainInfo($conn, $db, $xml) {
    $domainName = $xml->command->info->children('urn:ietf:params:xml:ns:domain-1.0')->info->name;
    $clTRID = (string) $xml->command->clTRID;

    // Validation for domain name
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
        	'roid' => $domain['id'],
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