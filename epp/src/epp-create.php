<?php

function processContactCreate($conn, $db, $xml, $clid, $database_type, $trans) {
    $contactID = (string) $xml->command->create->children('urn:ietf:params:xml:ns:contact-1.0')->create->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    if (!$contactID) {
        sendEppError($conn, $db, 2003, 'Please provide a contact ID', $clTRID, $trans);
        return;
    }

    // Validation for contact ID
    $invalid_identifier = validate_identifier($contactID);
    if ($invalid_identifier) {
        sendEppError($conn, $db, 2005, 'Invalid contact ID', $clTRID, $trans);
        return;
    }
    
    $stmt = $db->prepare("SELECT * FROM contact WHERE identifier = :id");
    $stmt->execute(['id' => $contactID]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if ($contact) {
        sendEppError($conn, $db, 2302, 'Contact ID already exists', $clTRID, $trans);
        return;
    }
    
    $clid = getClid($db, $clid);
    
    $contactCreate = $xml->command->create->children('urn:ietf:params:xml:ns:contact-1.0')->create;
    $postalInfoInt = null;
    $postalInfoLoc = null;

    foreach ($contactCreate->postalInfo as $postalInfo) {
        $type = (string) $postalInfo->attributes()->type;
        if ($type === 'int') {
            $postalInfoInt = $postalInfo;
        } elseif ($type === 'loc') {
            $postalInfoLoc = $postalInfo;
        }
    }

    if ($postalInfoInt) {
        $postalInfoIntName = (string) $postalInfoInt->name;
        $postalInfoIntOrg  = (string) $postalInfoInt->org;
    
        $streetInt = [];
        if (isset($postalInfoInt->addr->street)) {
            foreach ($postalInfoInt->addr->street as $street) {
                $streetInt[] = (string) $street;
            }
        }

        $postalInfoIntStreet1 = $streetInt[0] ?? '';
        $postalInfoIntStreet2 = $streetInt[1] ?? '';
        $postalInfoIntStreet3 = $streetInt[2] ?? '';
    
        $postalInfoIntCity = (string) $postalInfoInt->addr->city;
        $postalInfoIntSp   = (string) $postalInfoInt->addr->sp;
        $postalInfoIntPc   = (string) $postalInfoInt->addr->pc;
        $postalInfoIntCc   = (string) $postalInfoInt->addr->cc;

        if (!$postalInfoIntName) {
            sendEppError($conn, $db, 2003, 'Missing contact:name', $clTRID, $trans);
            return;
        }

        if (
            preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntName) ||
            !preg_match('/^[a-zA-Z0-9\-\&\,\.\'\/\s]{5,}$/', $postalInfoIntName) ||
            strlen($postalInfoIntName) > 255
        ) {
            sendEppError($conn, $db, 2005, 'Invalid contact:name', $clTRID, $trans);
            return;
        }

        if ($postalInfoIntOrg) {
            if (
                preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntOrg) ||
                !preg_match('/^[a-zA-Z0-9\-\'\&\,\.\/\s]{5,}$/', $postalInfoIntOrg) ||
                strlen($postalInfoIntOrg) > 255
            ) {
                sendEppError($conn, $db, 2005, 'Invalid contact:org', $clTRID, $trans);
                return;
            }
        }

        if ($postalInfoIntStreet1) {
            if (
                preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet1) ||
                !preg_match('/^[a-zA-Z0-9\'\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet1) ||
                strlen($postalInfoIntStreet1) > 255
            ) {
                sendEppError($conn, $db, 2005, 'Invalid contact:street', $clTRID, $trans);
                return;
            }
        }

        if ($postalInfoIntStreet2) {
            if (
                preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet2) ||
                !preg_match('/^[a-zA-Z0-9\'\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet2) ||
                strlen($postalInfoIntStreet2) > 255
            ) {
                sendEppError($conn, $db, 2005, 'Invalid contact:street', $clTRID, $trans);
                return;
            }
        }

        if ($postalInfoIntStreet3) {
            if (
                preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet3) ||
                !preg_match('/^[a-zA-Z0-9\'\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet3) ||
                strlen($postalInfoIntStreet3) > 255
            ) {
                sendEppError($conn, $db, 2005, 'Invalid contact:street', $clTRID, $trans);
                return;
            }
        }

        if (
            preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoIntCity) ||
            !preg_match('/^[a-z][a-z\-\.\'\s]{2,}$/i', $postalInfoIntCity) ||
            strlen($postalInfoIntCity) > 255
        ) {
            sendEppError($conn, $db, 2005, 'Invalid contact:city', $clTRID, $trans);
            return;
        }

        if ($postalInfoIntSp) {
            if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoIntSp) || !preg_match('/^[A-Z][a-zA-Z\-\.\s]{1,}$/', $postalInfoIntSp)) {
                sendEppError($conn, $db, 2005, 'Invalid contact:sp', $clTRID, $trans);
                return;
            }
        }

        if ($postalInfoIntPc) {
            if (preg_match('/(^\-)|(\-\-)|(\-$)/', $postalInfoIntPc) || !preg_match('/^[A-Z0-9\-\s]{3,}$/', $postalInfoIntPc)) {
                sendEppError($conn, $db, 2005, 'Invalid contact:pc', $clTRID, $trans);
                return;
            }
        }

        if (!preg_match('/^(AF|AX|AL|DZ|AS|AD|AO|AI|AQ|AG|AR|AM|AW|AU|AT|AZ|BS|BH|BD|BB|BY|BE|BZ|BJ|BM|BT|BO|BQ|BA|BW|BV|BR|IO|BN|BG|BF|BI|KH|CM|CA|CV|KY|CF|TD|CL|CN|CX|CC|CO|KM|CG|CD|CK|CR|CI|HR|CU|CW|CY|CZ|DK|DJ|DM|DO|EC|EG|SV|GQ|ER|EE|ET|FK|FO|FJ|FI|FR|GF|PF|TF|GA|GM|GE|DE|GH|GI|GR|GL|GD|GP|GU|GT|GG|GN|GW|GY|HT|HM|VA|HN|HK|HU|IS|IN|ID|IR|IQ|IE|IM|IL|IT|JM|JP|JE|JO|KZ|KE|KI|KP|KR|KW|KG|LA|LV|LB|LS|LR|LY|LI|LT|LU|MO|MK|MG|MW|MY|MV|ML|MT|MH|MQ|MR|MU|YT|MX|FM|MD|MC|MN|ME|MS|MA|MZ|MM|NA|NR|NP|NL|NC|NZ|NI|NE|NG|NU|NF|MP|NO|OM|PK|PW|PS|PA|PG|PY|PE|PH|PN|PL|PT|PR|QA|RE|RO|RU|RW|BL|SH|KN|LC|MF|PM|VC|WS|SM|ST|SA|SN|RS|SC|SL|SG|SX|SK|SI|SB|SO|ZA|GS|ES|LK|SD|SR|SJ|SZ|SE|CH|SY|TW|TJ|TZ|TH|TL|TG|TK|TO|TT|TN|TR|TM|TC|TV|UG|UA|AE|GB|US|UM|UY|UZ|VU|VE|VN|VG|VI|WF|EH|YE|ZM|ZW)$/', $postalInfoIntCc)) {
            sendEppError($conn, $db, 2005, 'Invalid contact:cc', $clTRID, $trans);
            return;
        }
    }
    
    if ($postalInfoLoc) {
        $postalInfoLocName = (string) $postalInfoLoc->name;
        $postalInfoLocOrg  = (string) $postalInfoLoc->org;
    
        $streetLoc = [];
        if (isset($postalInfoLoc->addr->street)) {
            foreach ($postalInfoLoc->addr->street as $street) {
                $streetLoc[] = (string) $street;
            }
        }

        $postalInfoLocStreet1 = $streetLoc[0] ?? '';
        $postalInfoLocStreet2 = $streetLoc[1] ?? '';
        $postalInfoLocStreet3 = $streetLoc[2] ?? '';
    
        $postalInfoLocCity = (string) $postalInfoLoc->addr->city;
        $postalInfoLocSp   = (string) $postalInfoLoc->addr->sp;
        $postalInfoLocPc   = (string) $postalInfoLoc->addr->pc;
        $postalInfoLocCc   = (string) $postalInfoLoc->addr->cc;

        if (!$postalInfoLocName) {
            sendEppError($conn, $db, 2003, 'Missing contact:name', $clTRID, $trans);
            return;
        }

        if (!validateLocField($postalInfoLocName, 3)) {
            sendEppError($conn, $db, 2005, 'Invalid contact:name', $clTRID, $trans);
            return;
        }

        if ($postalInfoLocOrg) {
            if (!validateLocField($postalInfoLocOrg, 3)) {
                sendEppError($conn, $db, 2005, 'Invalid contact:org', $clTRID, $trans);
                return;
            }
        }

        if ($postalInfoLocStreet1) {
            if (!validateLocField($postalInfoLocStreet1, 3)) {
                sendEppError($conn, $db, 2005, 'Invalid contact:street', $clTRID, $trans);
                return;
            }
        }

        if ($postalInfoLocStreet2) {
            if (!validateLocField($postalInfoLocStreet2, 3)) {
                sendEppError($conn, $db, 2005, 'Invalid contact:street', $clTRID, $trans);
                return;
            }
        }

        if ($postalInfoLocStreet3) {
            if (!validateLocField($postalInfoLocStreet3, 3)) {
                sendEppError($conn, $db, 2005, 'Invalid contact:street', $clTRID, $trans);
                return;
            }
        }

        if (!validateLocField($postalInfoLocCity, 3)) {
            sendEppError($conn, $db, 2005, 'Invalid contact:city', $clTRID, $trans);
            return;
        }

        if ($postalInfoLocSp) {
            if (!validateLocField($postalInfoLocSp, 2)) {
                sendEppError($conn, $db, 2005, 'Invalid contact:sp', $clTRID, $trans);
                return;
            }
        }

        if ($postalInfoLocPc) {
            if (!validateLocField($postalInfoLocPc, 3)) {
                sendEppError($conn, $db, 2005, 'Invalid contact:pc', $clTRID, $trans);
                return;
            }
        }

        if (!preg_match('/^(AF|AX|AL|DZ|AS|AD|AO|AI|AQ|AG|AR|AM|AW|AU|AT|AZ|BS|BH|BD|BB|BY|BE|BZ|BJ|BM|BT|BO|BQ|BA|BW|BV|BR|IO|BN|BG|BF|BI|KH|CM|CA|CV|KY|CF|TD|CL|CN|CX|CC|CO|KM|CG|CD|CK|CR|CI|HR|CU|CW|CY|CZ|DK|DJ|DM|DO|EC|EG|SV|GQ|ER|EE|ET|FK|FO|FJ|FI|FR|GF|PF|TF|GA|GM|GE|DE|GH|GI|GR|GL|GD|GP|GU|GT|GG|GN|GW|GY|HT|HM|VA|HN|HK|HU|IS|IN|ID|IR|IQ|IE|IM|IL|IT|JM|JP|JE|JO|KZ|KE|KI|KP|KR|KW|KG|LA|LV|LB|LS|LR|LY|LI|LT|LU|MO|MK|MG|MW|MY|MV|ML|MT|MH|MQ|MR|MU|YT|MX|FM|MD|MC|MN|ME|MS|MA|MZ|MM|NA|NR|NP|NL|NC|NZ|NI|NE|NG|NU|NF|MP|NO|OM|PK|PW|PS|PA|PG|PY|PE|PH|PN|PL|PT|PR|QA|RE|RO|RU|RW|BL|SH|KN|LC|MF|PM|VC|WS|SM|ST|SA|SN|RS|SC|SL|SG|SX|SK|SI|SB|SO|ZA|GS|ES|LK|SD|SR|SJ|SZ|SE|CH|SY|TW|TJ|TZ|TH|TL|TG|TK|TO|TT|TN|TR|TM|TC|TV|UG|UA|AE|GB|US|UM|UY|UZ|VU|VE|VN|VG|VI|WF|EH|YE|ZM|ZW)$/', $postalInfoLocCc)) {
            sendEppError($conn, $db, 2005, 'Invalid contact:cc', $clTRID, $trans);
            return;
        }
    }

    if (!$postalInfoInt && !$postalInfoLoc) {
        sendEppError($conn, $db, 2003, 'Missing contact:postalInfo', $clTRID, $trans);
        return;
    }
    
    $contactCreate = $xml->command->create->children('urn:ietf:params:xml:ns:contact-1.0')->create;

    if (isset($contactCreate->voice) && trim((string) $contactCreate->voice) === '') {
        sendEppError($conn, $db, 2003, 'Voice element must not be empty if provided', $clTRID, $trans);
        return;
    }
    $voice = (string) $contactCreate->voice;
    $voice_x = (string) $contactCreate->voice->attributes()->x;
    if ($voice && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $voice) || strlen($voice) > 17)) {
        sendEppError($conn, $db, 2005, 'Voice must be (\+[0-9]{1,3}\.[0-9]{1,14})', $clTRID, $trans);
        return;
    }

    $fax = (string) $contactCreate->fax;
    $fax_x = '';
    if ($contactCreate->fax) {
        $fax_x = (string) $contactCreate->fax->attributes()->x;
    }
    if ($fax && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $fax) || strlen($fax) > 17)) {
        sendEppError($conn, $db, 2005, 'Fax must be (\+[0-9]{1,3}\.[0-9]{1,14})', $clTRID, $trans);
        return;
    }

    $email = (string) $contactCreate->email;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendEppError($conn, $db, 2005, 'Email address failed check', $clTRID, $trans);
        return;
    }

    $authInfo_pw = (string) $contactCreate->authInfo->pw;
    if (!$authInfo_pw) {
        sendEppError($conn, $db, 2003, 'Missing contact:pw', $clTRID, $trans);
        return;
    }

    if ((strlen($authInfo_pw) < 6) || (strlen($authInfo_pw) > 64)) {
        sendEppError($conn, $db, 2005, 'Password needs to be at least 6 and up to 64 characters long', $clTRID, $trans);
        return;
    }

    if (!preg_match('/[A-Z]/', $authInfo_pw)) {
        sendEppError($conn, $db, 2005, 'Password should have both upper and lower case characters', $clTRID, $trans);
        return;
    }

    if (!preg_match('/\d/', $authInfo_pw)) {
        sendEppError($conn, $db, 2005, 'Password should contain one or more numbers', $clTRID, $trans);
        return;
    }

    $contact_disclose = $xml->xpath('//contact:disclose');
    $disclose_voice = 1;
    $disclose_fax = 1;
    $disclose_email = 1;
    $disclose_name_int = 1;
    $disclose_name_loc = 0;
    $disclose_org_int = 1;
    $disclose_org_loc = 0;
    $disclose_addr_int = 1;
    $disclose_addr_loc = 0;

    foreach ($contact_disclose as $node_disclose) {
        $flag = (string)$node_disclose['flag'];

        if ($node_disclose->xpath('contact:voice')) {
            $disclose_voice = $flag;
        }
        if ($node_disclose->xpath('contact:fax')) {
            $disclose_fax = $flag;
        }
        if ($node_disclose->xpath('contact:email')) {
            $disclose_email = $flag;
        }
        if ($node_disclose->xpath('contact:name[@type="int"]')) {
            $disclose_name_int = $flag;
        }
        if ($node_disclose->xpath('contact:name[@type="loc"]')) {
            $disclose_name_loc = $flag;
        }
        if ($node_disclose->xpath('contact:org[@type="int"]')) {
            $disclose_org_int = $flag;
        }
        if ($node_disclose->xpath('contact:org[@type="loc"]')) {
            $disclose_org_loc = $flag;
        }
        if ($node_disclose->xpath('contact:addr[@type="int"]')) {
            $disclose_addr_int = $flag;
        }
        if ($node_disclose->xpath('contact:addr[@type="loc"]')) {
            $disclose_addr_loc = $flag;
        }
    }

    $identicaCreate = $xml->xpath('//identica:create') ?? null;

    if ($identicaCreate) {
        $nin = (string) ($xml->xpath('//identica:nin[1]')[0] ?? null);
        $nin_type = (string) ($xml->xpath('//identica:nin/@type[1]')[0] ?? null);

        if (!preg_match('/\d/', $nin)) {
            sendEppError($conn, $db, 2005, 'NIN should contain one or more numbers', $clTRID, $trans);
            return;
        }
        if (!in_array($nin_type, ['personal', 'business'])) {
            sendEppError($conn, $db, 2005, 'NIN type is invalid', $clTRID, $trans);
            return;
        }
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO contact (identifier,voice,voice_x,fax,fax_x,email,nin,nin_type,clid,crid,crdate,upid,lastupdate,trdate,trstatus,reid,redate,acid,acdate,disclose_voice,disclose_fax,disclose_email) VALUES(?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP(3),NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,?,?,?)");

        $stmt->execute([
            $contactID,
            $voice ?? null,
            empty($voice_x) ? null : $voice_x,
            empty($fax) ? null : $fax,
            empty($fax_x) ? null : $fax_x,
            $email,
            $nin ?? null,
            $nin_type ?? null,
            $clid,
            $clid,
            $disclose_voice,
            $disclose_fax,
            $disclose_email
        ]);

        $contact_id = $db->lastInsertId();

        if ($postalInfoInt) {
            $stmt = $db->prepare("INSERT INTO contact_postalInfo (contact_id,type,name,org,street1,street2,street3,city,sp,pc,cc,disclose_name_int,disclose_name_loc,disclose_org_int,disclose_org_loc,disclose_addr_int,disclose_addr_loc) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$contact_id, 'int', $postalInfoIntName, $postalInfoIntOrg, $postalInfoIntStreet1, $postalInfoIntStreet2 ?? null, $postalInfoIntStreet3 ?? null, $postalInfoIntCity, $postalInfoIntSp, $postalInfoIntPc, $postalInfoIntCc, $disclose_name_int, $disclose_name_loc, $disclose_org_int, $disclose_org_loc, $disclose_addr_int, $disclose_addr_loc]);
        }

        if ($postalInfoLoc) {
            $stmt = $db->prepare("INSERT INTO contact_postalInfo (contact_id,type,name,org,street1,street2,street3,city,sp,pc,cc,disclose_name_int,disclose_name_loc,disclose_org_int,disclose_org_loc,disclose_addr_int,disclose_addr_loc) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$contact_id, 'loc', $postalInfoLocName, $postalInfoLocOrg, $postalInfoLocStreet1, $postalInfoLocStreet2 ?? null, $postalInfoLocStreet3 ?? null, $postalInfoLocCity, $postalInfoLocSp, $postalInfoLocPc, $postalInfoLocCc, $disclose_name_int, $disclose_name_loc, $disclose_org_int, $disclose_org_loc, $disclose_addr_int, $disclose_addr_loc]);
        }

        $stmt = $db->prepare("INSERT INTO contact_authInfo (contact_id,authtype,authinfo) VALUES(?,?,?)");
        $stmt->execute([$contact_id, 'pw', $authInfo_pw]);
        
        $stmt = $db->prepare("INSERT INTO contact_status (contact_id,status) VALUES(?,?)");
        $stmt->execute([$contact_id, 'ok']);

        $stmt = $db->prepare("SELECT identifier, crdate FROM contact WHERE id = ? LIMIT 1");
        $stmt->execute([$contact_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $identifier = $row['identifier'] ?? null;
        $crdate = $row['crdate'] ?? null;

    } catch (PDOException $e) {
        sendEppError($conn, $db, 2400, 'Contact could not be created due to database error', $clTRID, $trans);
        return;
    }

    $svTRID = generateSvTRID();
    $response = [
        'command' => 'create_contact',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'id' => $identifier,
        'crDate' => $crdate,
        'clTRID' => $clTRID,
        'svTRID' => $svTRID,
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'create', 'contact', $identifier, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}

function processHostCreate($conn, $db, $xml, $clid, $database_type, $trans) {
    $hostName = $xml->command->create->children('urn:ietf:params:xml:ns:host-1.0')->create->name;
    $clTRID = (string) $xml->command->clTRID;

    if (validateHostName($hostName)) {
        $host_id_already_exist = $db->query("SELECT id FROM host WHERE name = '$hostName' LIMIT 1")->fetchColumn();
        if ($host_id_already_exist) {
            sendEppError($conn, $db, 2302, 'host:name already exists', $clTRID, $trans);
            return;
        }
    } else {
        sendEppError($conn, $db, 2005, 'Invalid host:name', $clTRID, $trans);
        return;
    }

    $hostName = strtolower($hostName);

    $host_addr_list = $xml->xpath('//host:addr');
    if (count($host_addr_list) > 13) {
        sendEppError($conn, $db, 2306, 'No more than 13 host:addr are allowed', $clTRID, $trans);
        return;
    }
    
    $clid = getClid($db, $clid);

    $nsArr = [];

    foreach ($host_addr_list as $node) {
        $addr = (string) $node;
        $addr_type = (string) ($node->attributes()->ip ?? 'v4');
        if (!in_array($addr_type, ['v4', 'v6'])) {
            sendEppError($conn, $db, 2005, 'host:addr ip attribute must be "v4" or "v6"', $clTRID, $trans);
            return;
        }

        if ($addr_type === 'v6') {
            $addr = normalize_v6_address($addr);
        } else {
            $addr = normalize_v4_address($addr);
        }

        // v6 IP validation
        if ($addr_type === 'v6' && !filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE)) {
            sendEppError($conn, $db, 2005, 'Invalid host:addr v6', $clTRID, $trans);
            return;
        }

        // v4 IP validation
        if ($addr_type !== 'v6' && !filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE)) {
            sendEppError($conn, $db, 2005, 'Invalid host:addr v4', $clTRID, $trans);
            return;
        }

        // check for duplicate IPs
        if (isset($nsArr[$addr_type][$addr])) {
            sendEppError($conn, $db, 2306, 'Duplicated host:addr '.$addr, $clTRID, $trans);
            return;
        }

        $nsArr[$addr_type][$addr] = $addr;
    }

    $tlds = $db->query("SELECT tld FROM domain_tld")->fetchAll(PDO::FETCH_COLUMN);
    $internal_host = false;
    foreach ($tlds as $tld) {
        if (str_ends_with($hostName, strtolower($tld))) {
            $internal_host = true;
            break;
        }
    }

    if ($internal_host) {
        $domain_exist = false;
        $clid_domain = 0;
        $superordinate_dom = 0;
        
        $stmt = $db->prepare("SELECT id,clid,name FROM domain");
        $stmt->execute();
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (preg_match('/\.' . preg_quote($row['name'], '/') . '$/i', $hostName)) {
                $domain_exist = true;
                $clid_domain = $row['clid'];
                $superordinate_dom = $row['id'];
                break;
            }
        }
        $stmt->closeCursor();
        
        if (!$domain_exist) {
            sendEppError($conn, $db, 2303, 'A host name object can NOT be created in a repository for which no superordinate domain name object exists', $clTRID, $trans);
            return;
        }
        
        if ($clid != $clid_domain) {
            sendEppError($conn, $db, 2201, 'The domain name belongs to another registrar, you are not allowed to create hosts for it', $clTRID, $trans);
            return;
        }

        if (empty($host_addr_list)) {
            sendEppError($conn, $db, 2306, 'In-bailiwick hosts must include at least one IP address (glue record)', $clTRID, $trans);
            return;
        }

        $stmt = $db->prepare("INSERT INTO host (name,domain_id,clid,crid,crdate) VALUES(?,?,?,?,CURRENT_TIMESTAMP(3))");
        $stmt->execute([$hostName, $superordinate_dom, $clid, $clid]);
        $host_id = $db->lastInsertId();
        
        $host_addr_list = $xml->xpath('//host:addr');
        
        foreach ($host_addr_list as $node) {
            $addr = (string) $node;

            if (empty($addr)) {
                sendEppError($conn, $db, 2303, 'Error: Address is empty', $clTRID, $trans);
                return;
            }

            $addr_type = isset($node['ip']) ? (string) $node['ip'] : 'v4';
            
            if ($addr_type == 'v6') {
                $addr = normalize_v6_address($addr);
            } else {
                $addr = normalize_v4_address($addr);
            }
            
            $stmt = $db->prepare("INSERT INTO host_addr (host_id,addr,ip) VALUES(?,?,?)");
            $stmt->execute([$host_id, $addr, $addr_type]);
        }
        
        $host_status = 'ok';
        $stmt = $db->prepare("INSERT INTO host_status (host_id,status) VALUES(?,?)");
        $stmt->execute([$host_id, $host_status]);

        $stmt = $db->prepare("SELECT crdate FROM host WHERE name = ? LIMIT 1");
        $stmt->execute([$hostName]);
        $crdate = $stmt->fetchColumn();
        $stmt->closeCursor();
        
        $svTRID = generateSvTRID();
        $response = [
            'command' => 'create_host',
            'resultCode' => 1000,
            'lang' => 'en-US',
            'message' => 'Command completed successfully',
            'name' => $hostName,
            'crDate' => $crdate,
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
        ];

        $epp = new EPP\EppWriter();
        $xml = $epp->epp_writer($response);
        updateTransaction($db, 'create', 'host', $hostName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
        sendEppResponse($conn, $xml);

    } else {
        $stmt = $db->prepare("INSERT INTO host (name,clid,crid,crdate) VALUES(?,?,?,CURRENT_TIMESTAMP(3))");
        $stmt->execute([$hostName, $clid, $clid]);
        
        $host_id = $db->lastInsertId();
        
        $host_status = 'ok';
        $stmt = $db->prepare("INSERT INTO host_status (host_id,status) VALUES(?,?)");
        $stmt->execute([$host_id, $host_status]);
        
        $stmt = $db->prepare("SELECT crdate FROM host WHERE name = ? LIMIT 1");
        $stmt->execute([$hostName]);
        $crdate = $stmt->fetchColumn();
        $stmt->closeCursor();
        
        $svTRID = generateSvTRID();
        $response = [
            'command' => 'create_host',
            'resultCode' => 1000,
            'lang' => 'en-US',
            'message' => 'Command completed successfully',
            'name' => $hostName,
            'crDate' => $crdate,
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
        ];

        $epp = new EPP\EppWriter();
        $xml = $epp->epp_writer($response);
        updateTransaction($db, 'create', 'host', $hostName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
        sendEppResponse($conn, $xml);
    }

}

function processDomainCreate($conn, $db, $xml, $clid, $database_type, $trans, $minimum_data) {
    $domainName = $xml->command->create->children('urn:ietf:params:xml:ns:domain-1.0')->create->name;
    $clTRID = (string) $xml->command->clTRID;

    $domainName = strtolower($domainName);

    $extensionNode = $xml->command->extension;
    $launch_create = null;
    if (isset($extensionNode)) {
        $fee_create = $xml->xpath('//fee:create')[0] ?? null;
        $launch_create = $xml->xpath('//launch:create')[0] ?? null;
        $allocation_token = $xml->xpath('//allocationToken:allocationToken')[0] ?? null;

        // Check if launch extension is enabled in database settings
        $stmt = $db->prepare("SELECT value FROM settings WHERE name = 'launch_phases' LIMIT 1");
        $stmt->execute();
        $launch_extension_enabled = $stmt->fetchColumn();
        $stmt->closeCursor();
    }

    $invalid_domain = validate_label($domainName, $db);

    if ($invalid_domain) {
        sendEppError($conn, $db, 2306, 'Invalid domain:name', $clTRID, $trans);
        return;
    }

    $parts = extractDomainAndTLD($domainName);
    $label = $parts['domain'];
    $domain_extension = '.' . strtoupper($parts['tld']);

    $noticeid = null;
    $notafter = null;
    $accepted = null;

    if ($launch_extension_enabled && isset($launch_create)) {
        $xml->registerXPathNamespace('launch', 'urn:ietf:params:xml:ns:launch-1.0');
        $xml->registerXPathNamespace('signedMark', 'urn:ietf:params:xml:ns:signedMark-1.0');

        $launch_phase_node = $launch_create->xpath('launch:phase')[0] ?? null;
        $launch_phase = $launch_phase_node ? (string)$launch_phase_node : null;
        $launch_phase_name = $launch_phase_node ? (string)$launch_phase_node['name'] : null;
        
        $xpath = '//*[namespace-uri()="urn:ietf:params:xml:ns:signedMark-1.0" and local-name()="encodedSignedMark"]';
        $smd_encodedSignedMark = $xml->xpath($xpath)[0] ?? null;
        $smd_encodedSignedMark = $smd_encodedSignedMark ? preg_replace('/\s+/', '', (string)$smd_encodedSignedMark) : null;

        $launch_notice_exists = $xml->xpath('//launch:notice');
        if (!empty($launch_notice_exists)) {
            $launch_noticeID = (string) ($xml->xpath('//launch:noticeID')[0] ?? '');
            $launch_notAfter = (string) ($xml->xpath('//launch:notAfter')[0] ?? '');
            $launch_acceptedDate = (string) ($xml->xpath('//launch:acceptedDate')[0] ?? '');
        } else {
            $launch_noticeID = $launch_notAfter = $launch_acceptedDate = null;
        }

        // Validate and handle each specific case
        if ($launch_phase === 'sunrise' && $smd_encodedSignedMark) {
            // Parse and validate SMD encoded signed mark later
        } elseif ($launch_phase === 'claims') {
            // Check for missing notice elements and validate dates
            if (!isset($launch_noticeID) || $launch_noticeID === '' ||
                !isset($launch_notAfter) || $launch_notAfter === '' ||
                !isset($launch_acceptedDate) || $launch_acceptedDate === '') {
                sendEppError($conn, $db, 2306, "Error creating domain: 'noticeid', 'notafter', or 'accepted' cannot be empty when phaseType is 'claims'", $clTRID, $trans);
                return;
            }
            
            $noticeid = $launch_noticeID;
            $notafter = $launch_notAfter;
            $accepted = $launch_acceptedDate;

            // Validate that acceptedDate is before notAfter
            try {
                $acceptedDate = DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', $launch_acceptedDate);
                $notAfterDate = DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', $launch_notAfter);
                
                if (!$acceptedDate || !$notAfterDate) {
                    sendEppError($conn, $db, 2003, 'Invalid date format', $clTRID, $trans);
                    return;
                }

                if ($acceptedDate >= $notAfterDate) {
                    sendEppError($conn, $db, 2003, 'Invalid dates: acceptedDate must be before notAfter', $clTRID, $trans);
                    return;
                }

                $currentTime = new DateTime('now', new DateTimeZone('UTC'));
                $interval = $currentTime->getTimestamp() - $acceptedDate->getTimestamp();

                if ($interval > 172800) { // 172800 seconds = 48 hours
                    sendEppError($conn, $db, 2003, 'Invalid acceptedDate: must be within 48 hours of current time', $clTRID, $trans);
                    return;
                }
            } catch (Exception $e) {
                sendEppError($conn, $db, 2003, 'Invalid date format', $clTRID, $trans);
                return;
            }

            if (!validateTcnId($domainName, $noticeid, $launch_notAfter)) {
                sendEppError($conn, $db, 2306, 'Invalid TMCH claims noticeID format', $clTRID, $trans);
                return;
            }
        } elseif ($launch_phase === 'landrush') {
            // Continue
        } elseif ($launch_phase === 'custom') {
            if (empty($launch_phase_name)) {
                sendEppError($conn, $db, 2003, 'Missing required element: A phase name is mandatory for the custom phase.', $clTRID, $trans);
                return;
            }
            // Continue
        } else {
            // Mixed or unsupported form
            sendEppError($conn, $db, 2101, 'unsupported launch phase or mixed form', $clTRID, $trans);
            return;
        }
    }

    $stmt = $db->prepare("SELECT id FROM domain_tld WHERE UPPER(tld) = ?");
    $stmt->execute([$domain_extension]);
    $tld_id = $stmt->fetchColumn();
    $stmt->closeCursor();

    if (!$tld_id) {
        sendEppError($conn, $db, 2306, 'Invalid domain extension', $clTRID, $trans);
        return;
    }

    $stmt = $db->prepare("SELECT id FROM domain WHERE name = ? LIMIT 1");
    $stmt->execute([$domainName]);
    $domain_already_exist = $stmt->fetchColumn();
    $stmt->closeCursor();

    if ($domain_already_exist) {
        sendEppError($conn, $db, 2302, 'Domain name already exists', $clTRID, $trans);
        return;
    }

    $launch_phases = $db->query("SELECT value FROM settings WHERE name = 'launch_phases' LIMIT 1")->fetchColumn();
    if ($launch_phases) {
        $currentDateTime = new \DateTime();
        $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

        $stmt = $db->prepare("
            SELECT phase_category 
            FROM launch_phases 
            WHERE tld_id = ? 
            AND start_date <= ? 
            AND (end_date >= ? OR end_date IS NULL OR end_date = '') 
            LIMIT 1
        ");
        $stmt->execute([$tld_id, $currentDate, $currentDate]);
        $phase_details = $stmt->fetchColumn();
        $stmt->closeCursor();

        $launch_phase = $launch_phase ?? null;

        // Check if the phase requires application submission
        if (empty($launch_phase) && $launch_phase !== 'custom' && $phase_details === 'Application') {
            sendEppError($conn, $db, 2304, 'Domain registration is not allowed for this TLD. You must submit a new application instead.', $clTRID, $trans);
            return;
        }
    }

    if ($launch_extension_enabled && isset($launch_create)) {
        $currentDateTime = new \DateTime();
        $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

        $stmt = $db->prepare("
            SELECT phase_category 
            FROM launch_phases 
            WHERE tld_id = ? 
            AND start_date <= ? 
            AND (end_date >= ? OR end_date IS NULL OR end_date = '') 
            LIMIT 1
        ");
        $stmt->execute([$tld_id, $currentDate, $currentDate]);
        $phase_details = $stmt->fetchColumn();
        $stmt->closeCursor();

        // Check if the phase requires application submission
        if (empty($launch_phase) && $launch_phase !== 'custom' && $phase_details === 'Application') {
            sendEppError($conn, $db, 2304, 'Domain registration is not allowed for this TLD. You must submit a new application instead.', $clTRID, $trans);
            return;
        }

        if ($phase_details !== 'First-Come-First-Serve') {
            if ($launch_phase !== 'none') {
                if ($launch_phase == null && $launch_phase == '') {
                    sendEppError($conn, $db, 2306, 'Error creating domain: The launch phase ' . $launch_phase . ' is improperly configured. Please check the settings or contact support.', $clTRID, $trans);
                    return;
                } else if ($phase_details == null) {
                    sendEppError($conn, $db, 2306, 'Error creating domain: The launch phase ' . $launch_phase . ' is currently not active.', $clTRID, $trans);
                    return;
                }
            }
        } else if ($launch_phase !== 'none') {
            if ($launch_phase == null && $launch_phase == '') {
                sendEppError($conn, $db, 2306, 'Error creating domain: The launch phase ' . $launch_phase . ' is improperly configured. Please check the settings or contact support.', $clTRID, $trans);
                return;
            } else if ($phase_details == null) {
                sendEppError($conn, $db, 2306, 'Error creating domain: The launch phase ' . $launch_phase . ' is currently not active.', $clTRID, $trans);
                return;
            }
        }

        if ($launch_phase === 'sunrise') {
            if ($smd_encodedSignedMark !== null && $smd_encodedSignedMark !== '') {
                if (strpos($smd_encodedSignedMark, '-----BEGIN ENCODED SMD-----') !== false ||
                    strpos($smd_encodedSignedMark, '-----END ENCODED SMD-----') !== false) {
                    sendEppError($conn, $db, 2306, 'SMD must not include BEGIN/END ENCODED SMD lines', $clTRID, $trans);
                    return;
                }

                // Decode the BASE64 content
                $xmlContent = base64_decode($smd_encodedSignedMark);
                $xmlContent = str_replace('&#13;', '', $xmlContent);

                // Load the XML content using DOMDocument
                $domDocument = new \DOMDocument();
                $domDocument->preserveWhiteSpace = false;
                $domDocument->formatOutput = true;
                $domDocument->loadXML($xmlContent);

                // Parse data
                $xpath = new \DOMXPath($domDocument);
                $xpath->registerNamespace('smd', 'urn:ietf:params:xml:ns:signedMark-1.0');
                $xpath->registerNamespace('mark', 'urn:ietf:params:xml:ns:mark-1.0');
                $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
                $certNode = $xpath->evaluate('string(//ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate)');
                $certBase64 = preg_replace('/\s+/', '', $certNode);
                $certPem = "-----BEGIN CERTIFICATE-----\n" .
                           chunk_split($certBase64, 64, "\n") .
                           "-----END CERTIFICATE-----\n";
                           
                // Load the SMD certificate
                $x509 = new \phpseclib3\File\X509();
                $cert = $x509->loadX509($certPem);
                $serial = strtoupper($cert['tbsCertificate']['serialNumber']->toHex()); // serial as hex

                // Get latest CRL from DB
                $stmt = $db->query("SELECT content FROM tmch_crl ORDER BY update_timestamp DESC LIMIT 1");
                $crlDer = $stmt->fetchColumn();
                $stmt->closeCursor();

                // Load and parse the CRL
                $crl = new \phpseclib3\File\X509();
                $crlData = $crl->loadCRL($crlDer);

                // Check revoked serials
                $revoked = $crlData['tbsCertList']['revokedCertificates'] ?? [];
                foreach ($revoked as $entry) {
                    $revokedSerial = strtoupper($entry['userCertificate']->toHex());
                    if ($revokedSerial === $serial) {
                        sendEppError($conn, $db, 2306, 'Error creating domain: SMD certificate has been revoked.', $clTRID, $trans);
                        return;
                    }
                }

                $smdId = $xpath->evaluate('string(//smd:id)');
                $stmt = $db->prepare("SELECT 1 FROM tmch_revocation WHERE smd_id = ?");
                $stmt->execute([$smdId]);
                if ($stmt->fetchColumn()) {
                    sendEppError($conn, $db, 2306, 'Error creating domain: SMD certificate has been revoked.', $clTRID, $trans);
                    return;
                }

                $notBefore = new \DateTime($xpath->evaluate('string(//smd:notBefore)'));
                $notafter = new \DateTime($xpath->evaluate('string(//smd:notAfter)'));
                $markName = $xpath->evaluate('string(//mark:markName)');
                $markId = $xpath->evaluate('string(//mark:id)');
                $labels = [];
                foreach ($xpath->query('//mark:label') as $x_label) {
                    $labels[] = $x_label->nodeValue;
                }

                if (!in_array($label, $labels)) {
                    sendEppError($conn, $db, 2306, 'Error creating domain: SMD file is not valid for the domain name being registered.', $clTRID, $trans);
                    return;
                }

                // Check if current date and time is between notBefore and notAfter
                $now = new \DateTime();
                if (!($now >= $notBefore && $now <= $notafter)) {
                    sendEppError($conn, $db, 2306, 'Error creating domain: Current time is outside the valid range in the SMD.', $clTRID, $trans);
                    return;
                }

                // Verify the signature
                $dsig = new \RobRichards\XMLSecLibs\XMLSecurityDSig();
                $signatureNode = $dsig->locateSignature($domDocument);
                $dsig->canonicalizeSignedInfo();
                $dsig->idKeys = ['ID'];
                $dsig->idNS = ['smd' => 'urn:ietf:params:xml:ns:signedMark-1.0'];

                $key = new \RobRichards\XMLSecLibs\XMLSecurityKey(
                    \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256,
                    ['type' => 'public']
                );
                $key->loadKey($certPem, false, true);

                if (!$dsig->verify($key, $signatureNode)) {
                    sendEppError($conn, $db, 2306, 'Error creating domain: The XML signature of the SMD file is not valid.', $clTRID, $trans);
                    return;
                }
            } else {
                sendEppError($conn, $db, 2306, "Error creating domain: SMD upload is required in the 'sunrise' phase.", $clTRID, $trans);
                return;
            }
        }
    }

    $stmt = $db->prepare("SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1");
    $stmt->execute([$label]);
    $domain_already_reserved = $stmt->fetchColumn();
    $stmt->closeCursor();

    if ($domain_already_reserved) {
        if ($allocation_token !== null) {
            $allocationTokenValue = (string)$allocation_token;
                        
            $stmt = $db->prepare("SELECT token FROM allocation_tokens WHERE domain_name = :domainName AND token = :token LIMIT 1");
            $stmt->bindParam(':domainName', $label, PDO::PARAM_STR);
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
        } else {
            sendEppError($conn, $db, 2302, 'Domain name is reserved or restricted', $clTRID, $trans);
            return;
        }
    }
    
    $periodElements = $xml->xpath("//domain:create/domain:period");

    if (!empty($periodElements)) {
        $periodElement = $periodElements[0];
        $period_raw = trim((string) $periodElement);

        if (!ctype_digit($period_raw)) {
            sendEppError($conn, $db, 2004, 'domain:period must be a whole number', $clTRID, $trans);
            return;
        }

        $period = (int) $period_raw;
        $period_unit = (string) $periodElement['unit'];
    } else {
        $periodElement = null;
        $period = null;
        $period_unit = null;
    }

    if ($period && (($period < 1) || ($period > 99))) {
        sendEppError($conn, $db, 2004, 'domain:period minLength value=1, maxLength value=99', $clTRID, $trans);
        return;
    } elseif (!$period) {
        $period = 1;
    }

    if ($period_unit) {
        if (!preg_match('/^(m|y)$/i', $period_unit)) {
        sendEppError($conn, $db, 2004, 'domain:period unit m|y', $clTRID, $trans);
        return;
        }
    } else {
        $period_unit = 'y';
    }

    $date_add = 0;
    if ($period_unit === 'y') {
        $date_add = ($period * 12);
    } elseif ($period_unit === 'm') {
        $date_add = $period;
    }

    if (!preg_match("/^(12|24|36|48|60|72|84|96|108|120)$/", $date_add)) {
        sendEppError($conn, $db, 2306, 'A domain name can initially be registered for 1-10 years period', $clTRID, $trans);
        return;
    }
    
    $clid = getClid($db, $clid);

    $stmt = $db->prepare("SELECT accountBalance, creditLimit, currency FROM registrar WHERE id = :registrar_id LIMIT 1");
    $stmt->bindParam(':registrar_id', $clid, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $registrar_balance = $result['accountBalance'];
    $creditLimit = $result['creditLimit'];
    $currency = $result['currency'];
    
    $returnValue = getDomainPrice($db, $domainName, $tld_id, $date_add, 'create', $clid, $currency);
    $price = $returnValue['price'];

    if (!$price) {
        sendEppError($conn, $db, 2400, 'The price, period and currency for such TLD are not declared', $clTRID, $trans);
        return;
    }

    if (($registrar_balance + $creditLimit) < $price) {
        sendEppError($conn, $db, 2104, 'Low credit: minimum threshold reached', $clTRID, $trans);
        return;
    }

    $ns = $xml->xpath('//domain:ns')[0] ?? null;
    $hostObj_list = null;
    $hostAttr_list = null;

    if (isset($ns)) {
        $hostObj_list = $ns->xpath('//domain:hostObj');
        $hostAttr_list = $ns->xpath('//domain:hostAttr');
    }

    if (
        ($hostObj_list !== null && is_array($hostObj_list)) || 
        ($hostAttr_list !== null && is_array($hostAttr_list))
    ) {
        if (count($hostObj_list) > 0 && count($hostAttr_list) > 0) {
            sendEppError($conn, $db, 2001, 'It cannot be hostObj and hostAttr at the same time, either one or the other', $clTRID, $trans);
            return;
        }

        if (count($hostObj_list) > 13) {
            sendEppError($conn, $db, 2306, 'No more than 13 domain:hostObj are allowed', $clTRID, $trans);
            return;
        }

        if (count($hostAttr_list) > 13) {
            sendEppError($conn, $db, 2306, 'No more than 13 domain:hostAttr are allowed', $clTRID, $trans);
            return;
        }

        $nsArr = [];
        foreach ($hostObj_list as $hostObj) {
            if (isset($nsArr[(string)$hostObj])) {
                sendEppError($conn, $db, 2302, 'Duplicate nameserver '.(string)$hostObj, $clTRID, $trans);
                return;
            }
            $nsArr[(string)$hostObj] = 1;
        }

        $nsArr = [];
        foreach ($ns->xpath('//domain:hostAttr/domain:hostName') as $hostName) {
            if (isset($nsArr[(string)$hostName])) {
                sendEppError($conn, $db, 2302, 'Duplicate nameserver '.(string)$hostName, $clTRID, $trans);
                return;
            }
            $nsArr[(string)$hostName] = 1;
        }

        if (count($hostObj_list) > 0) {
            foreach ($hostObj_list as $node) {
                $hostObj = strtoupper((string)$node);

                if (!validateHostName($hostObj)) {
                    sendEppError($conn, $db, 2005, 'Invalid domain:hostObj', $clTRID, $trans);
                    return;
                }

                // A host object MUST be known to the server before the host object can be associated with a domain object.
                $stmt = $db->prepare("SELECT id FROM host WHERE name = :hostObj LIMIT 1");
                $stmt->bindParam(':hostObj', $hostObj);
                $stmt->execute();
                
                $host_id_already_exist = $stmt->fetch(PDO::FETCH_COLUMN);
                $stmt->closeCursor();

                if (!$host_id_already_exist) {
                    sendEppError($conn, $db, 2303, 'domain:hostObj '.$hostObj.' does not exist', $clTRID, $trans);
                    return;
                }
            }
        }

        if (count($hostAttr_list) > 0) {
            foreach ($hostAttr_list as $node) {
                $hostName = strtoupper((string)$node->xpath('//domain:hostName')[0]);

                if (!validateHostName($hostName)) {
                    sendEppError($conn, $db, 2005, 'Invalid domain:hostName', $clTRID, $trans);
                    return;
                }

                // Check if the host is internal or external
                $tlds = $db->query("SELECT tld FROM domain_tld")->fetchAll(PDO::FETCH_COLUMN);
                $internal_host = false;
                foreach ($tlds as $tld) {
                    if (str_ends_with($hostName, strtolower($tld))) {
                        $internal_host = true;
                        break;
                    }
                }

                if ($internal_host) {
                    if (preg_match('/\.' . preg_quote($domainName, '/') . '$/i', $hostName)) {
                    $hostAddrNodes = $node->xpath('//domain:hostAddr');

                    if (count($hostAddrNodes) > 13) {
                        sendEppError($conn, $db, 2306, 'No more than 13 domain:hostObj are allowed', $clTRID, $trans);
                        return;
                    }

                    $nsArr = [];
                    foreach ($hostAddrNodes as $hostAddrNode) {
                        $hostAddr = (string)$hostAddrNode;
                        if (isset($nsArr[$hostAddr])) {
                            sendEppError($conn, $db, 2302, 'Duplicate IP'.$hostAddr, $clTRID, $trans);
                            return;
                        }
                        $nsArr[$hostAddr] = true;
                    }

                    if (count($hostAddrNodes) === 0) {
                        sendEppError($conn, $db, 2003, 'Missing domain:hostAddr', $clTRID, $trans);
                        return;
                    }
                    
                    foreach ($hostAddrNodes as $node) {
                        $hostAddr = (string) $node;
                        $addr_type = (string) ($node['ip'] ?? 'v4');
        
                        if ($addr_type == 'v6') {
                            if (!filter_var($hostAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE)) {
                                sendEppError($conn, $db, 2005, 'Invalid domain:hostAddr v6', $clTRID, $trans);
                                return;
                            }
                        } else {
                            if (!filter_var($hostAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE) || $hostAddr === '127.0.0.1') {
                                sendEppError($conn, $db, 2005, 'Invalid domain:hostAddr v4', $clTRID, $trans);
                                return;
                            }
                        }
                    }
                } else {
                    // Validate the hostname using the function
                    if (validateHostName($hostName)) {
                        $domain_exist = false;
                        $clid_domain = 0;

                        // Prepare statement
                        $stmt = $db->prepare("SELECT clid, name FROM domain");
                        $stmt->execute();

                        // Fetch results
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            if (stripos($hostName, '.' . $row['name']) !== false) {
                                $domain_exist = true;
                                $clid_domain = $row['clid'];
                                break;
                            }
                        }
                        $stmt->closeCursor();

                        // Object does not exist error
                        if (!$domain_exist) {
                           sendEppError($conn, $db, 2303, 'domain:hostName '.$hostName.' . A host name object can NOT be created in a repository for which no superordinate domain name object exists', $clTRID, $trans);
                           return;
                        }

                        // Authorization error
                        if ($clid != $clid_domain) {
                           sendEppError($conn, $db, 2201, 'The domain name belongs to another registrar, you are not allowed to create hosts for it', $clTRID, $trans);
                           return;
                        }
                    } else {
                       sendEppError($conn, $db, 2005, 'Invalid domain:hostName', $clTRID, $trans);
                       return;
                    }

                    $hostAddr_list = $xml->xpath('//domain:hostAddr');

                    // Max 13 IP per host
                    if (count($hostAddr_list) > 13) {
                       sendEppError($conn, $db, 2306, 'No more than 13 IPs are allowed per host', $clTRID, $trans);
                       return;
                    }

                    // Compare for duplicates in hostAddr
                    $nsArr = array();
                    foreach ($hostAddr_list as $node) {
                        $hostAddr = (string) $node;
                        if (isset($nsArr[$hostAddr])) {
                            sendEppError($conn, $db, 2302, 'Duplicate IP'.$hostAddr, $clTRID, $trans);
                            return;
                        }
                        $nsArr[$hostAddr] = 1;
                    }

                    // Check for missing host addresses
                    if (count($hostAddr_list) === 0) {
                        sendEppError($conn, $db, 2003, 'Missing domain:hostAddr', $clTRID, $trans);
                        return;
                    }


                    foreach ($hostAddr_list as $node) {
                        $hostAddr = (string) $node;
                        $addr_type = isset($node['ip']) ? (string) $node['ip'] : 'v4';

                        if ($addr_type === 'v6') {
                            if (!filter_var($hostAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE)) {
                                sendEppError($conn, $db, 2005, 'Invalid domain:hostAddr v6', $clTRID, $trans);
                                return;
                            }
                        } else {
                            if (!filter_var($hostAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE) || $hostAddr === '127.0.0.1') {
                                sendEppError($conn, $db, 2005, 'Invalid domain:hostAddr v4', $clTRID, $trans);
                                return;
                            }
                        }
                    }
                }
                } else {
                    // External host
                    if (!validateHostName($hostName)) {
                        sendEppError($conn, $db, 2005, 'Invalid domain:hostName', $clTRID, $trans);
                        return;
                    }
                }
            }
        }
    }

    // Registrant
    $registrant = $xml->xpath('//domain:registrant[1]');
    $registrant_id = null; // Default to null

    if ($registrant && count($registrant) > 0) {
        $registrant_id = (string)$registrant[0][0];
    }

    // Check $minimum_data and handle registrant_id accordingly
    if ($minimum_data) {
        // In minimal data mode, registrant_id should always be null
        if ($registrant_id !== null) {
            // If registrant_id is submitted, give an error
            sendEppError($conn, $db, 2306, 'domain:registrant field is not supported in minimal data mode', $clTRID);
            $conn->close();
            return;
        }
    } else {
        // Non-minimal data mode: registrant_id must not be null
        if ($registrant_id === null) {
            sendEppError($conn, $db, 2303, 'domain:registrant is required and does not exist', $clTRID, $trans);
            return;
        }

        $validRegistrant = validate_identifier($registrant_id);

        $registrantStmt = $db->prepare("SELECT id FROM contact WHERE identifier = :registrant LIMIT 1");
        $registrantStmt->execute([':registrant' => $registrant_id]);
        $registrant_id = $registrantStmt->fetchColumn();
        $registrantStmt->closeCursor();

        // Set registrant_id to null if it returns false
        if ($registrant_id === false) {
            sendEppError($conn, $db, 2303, 'domain:registrant does not exist', $clTRID, $trans);
            return;
        }

        $stmt = $db->prepare("SELECT id, clid FROM contact WHERE id = :registrant LIMIT 1");
        $stmt->bindParam(':registrant', $registrant_id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$row) {
            sendEppError($conn, $db, 2303, 'domain:registrant does not exist', $clTRID, $trans);
            return;
        }

        if ($clid != $row['clid']) {
            sendEppError($conn, $db, 2201, 'The contact requested in the command does NOT belong to the current registrar', $clTRID, $trans);
            return;
        }
    }

    // Handle contacts of type 'admin', 'billing' and 'tech'
    foreach (['admin', 'billing', 'tech'] as $type) {
        $contactList = $xml->xpath("//domain:contact[@type='{$type}']");
        $size = count($contactList);

        // Max five contacts per domain name for each type
        if ($size > 5) {
            sendEppError($conn, $db, 2306, 'No more than 5 '.$type.' contacts are allowed per domain name', $clTRID, $trans);
            return;
        }

        foreach ($contactList as $node) {
            $contactValue = (string)$node;
            $validContact = validate_identifier($contactValue);

            $stmt = $db->prepare("SELECT id, clid FROM contact WHERE identifier = :contact LIMIT 1");
            $stmt->bindParam(':contact', $contactValue);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if (!$row) {
                sendEppError($conn, $db, 2303, 'domain:contact '.$type.' does not exist', $clTRID, $trans);
                return;
            }

            if ($clid != $row['clid']) {
                sendEppError($conn, $db, 2201, 'The contact type='.$type.' requested in the command does NOT belong to the current Registrar', $clTRID, $trans);
                return;
            }
        }
    }
    
    $authInfo_pw = (string) $xml->xpath('//domain:authInfo/domain:pw[1]')[0] ?? null;

    if (!$authInfo_pw) {
        sendEppError($conn, $db, 2003, 'Missing domain:pw', $clTRID, $trans);
        return;
    }

    if (strlen($authInfo_pw) < 6 || strlen($authInfo_pw) > 64) {
        sendEppError($conn, $db, 2005, 'Password needs to be at least 6 and up to 64 characters long', $clTRID, $trans);
        return;
    }

    if (!preg_match('/[A-Z]/', $authInfo_pw)) {
        sendEppError($conn, $db, 2005, 'Password should have both upper and lower case characters', $clTRID, $trans);
        return;
    }

    if (!preg_match('/\d/', $authInfo_pw)) {
        sendEppError($conn, $db, 2005, 'Password should contain one or more numbers', $clTRID, $trans);
        return;
    }

    if (isset($phase_details) && $phase_details === 'Application') {
        try {
            $db->beginTransaction();
            
            $currentDateTime = new \DateTime();
            $crdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
            
            $response = [];
            $domainSql = "INSERT INTO application (
                name, tldid, registrant, crdate, clid, crid, authtype, authinfo, tm_phase, phase_type
            ) VALUES (
                :name, :tldid, :registrant, :crdate, :clid, :crid, :authtype, :authinfo, :tm_phase, :phase_type
            )";

            $domainStmt = $db->prepare($domainSql);
            $domainStmt->execute([
                ':name' => $domainName,
                ':tldid' => $tld_id,
                ':registrant' => $registrant_id,
                ':crdate' => $crdate,
                ':clid' => $clid,
                ':crid' => $clid,
                ':authtype' => 'pw',
                ':authinfo' => $authInfo_pw ?? null,
                ':tm_phase' => $launch_phase_name ?? null,
                ':phase_type' => $launch_phase ?? null,
            ]);
            $domain_id = $db->lastInsertId();
            $uuid = createUuidFromId($domain_id);

            // Update application_id in the application table
            $updateStmt = $db->prepare("UPDATE application SET application_id = :uuid WHERE id = :domain_id");
            $updateStmt->execute([
                ':uuid' => $uuid,
                ':domain_id' => $domain_id
            ]);

            // Insert into application_status table
            $insertStmt = $db->prepare("INSERT INTO application_status (domain_id, status) VALUES (:domain_id, :status)");
            $insertStmt->execute([
                ':domain_id' => $domain_id,
                ':status' => 'pendingValidation'
            ]);

            $updateRegistrarStmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance - :price) WHERE id = :registrar_id");
            $updateRegistrarStmt->execute([
                ':price' => $price,
                ':registrar_id' => $clid
            ]);

            $paymentHistoryStmt = $db->prepare("INSERT INTO payment_history (registrar_id,date,description,amount) VALUES(:registrar_id,CURRENT_TIMESTAMP(3),:description,:amount)");
            $paymentHistoryStmt->execute([
                ':registrar_id' => $clid,
                ':description' => "create application $domainName for period $date_add MONTH",
                ':amount' => "-$price"
            ]);

            $selectDomainDatesStmt = $db->prepare("SELECT crdate,exdate FROM application WHERE name = :name LIMIT 1");
            $selectDomainDatesStmt->execute([':name' => $domainName]);
            [$from, $to] = $selectDomainDatesStmt->fetch(PDO::FETCH_NUM);
            $selectDomainDatesStmt->closeCursor();

            $statementStmt = $db->prepare("INSERT INTO statement (registrar_id,date,command,domain_name,length_in_months,fromS,toS,amount) VALUES(:registrar_id,CURRENT_TIMESTAMP(3),:cmd,:name,:date_add,:from,:to,:price)");
            $statementStmt->execute([
                ':registrar_id' => $clid,
                ':cmd' => 'create',
                ':name' => $domainName,
                ':date_add' => $date_add,
                ':from' => $from,
                ':to' => $from,
                ':price' => $price
            ]);

            if (!empty($hostObj_list) && is_array($hostObj_list)) {
                foreach ($hostObj_list as $node) {
                    $hostObj = strtoupper((string)$node);

                    $hostExistStmt = $db->prepare("SELECT id FROM host WHERE name = :hostObj LIMIT 1");
                    $hostExistStmt->execute([':hostObj' => $hostObj]);
                    $hostObj_already_exist = $hostExistStmt->fetchColumn();
                    $hostExistStmt->closeCursor();

                    if ($hostObj_already_exist) {
                        $domainHostMapStmt = $db->prepare("SELECT domain_id FROM application_host_map WHERE domain_id = :domain_id AND host_id = :host_id LIMIT 1");
                        $domainHostMapStmt->execute([':domain_id' => $domain_id, ':host_id' => $hostObj_already_exist]);
                        $domain_host_map_id = $domainHostMapStmt->fetchColumn();
                        $domainHostMapStmt->closeCursor();

                        if (!$domain_host_map_id) {
                            $insertDomainHostMapStmt = $db->prepare("INSERT INTO application_host_map (domain_id,host_id) VALUES(:domain_id,:host_id)");
                            $insertDomainHostMapStmt->execute([':domain_id' => $domain_id, ':host_id' => $hostObj_already_exist]);
                        } else {
                            $errorLogStmt = $db->prepare("INSERT INTO error_log 
                                (channel, level, level_name, message, context, extra, created_at) 
                                VALUES ('epp', 300, 'WARNING', :log, :context, '{}', CURRENT_TIMESTAMP)");
                            $errorLogStmt->execute([
                                ':log' => "Domain: $domainName; hostObj: $hostObj - is duplicated",
                                ':context' => json_encode(['registrar_id' => $clid, 'domain' => $domainName, 'host' => $hostObj])
                            ]);
                        }
                    } else {
                        sendEppError($conn, $db, 2303, "Host object $hostObj does not exist", $clTRID, $trans);
                        return;
                    }
                }
            }

            if (!empty($hostAttr_list) && is_array($hostAttr_list)) {
                foreach ($hostAttr_list as $node) {
                    // Extract the hostName
                    $hostName = strtoupper((string)$node->xpath('./domain:hostName')[0] ?? '');
                    if (empty($hostName)) {
                        continue; // Skip if no hostName found
                    }

                    // Check if the host already exists
                    $stmt = $db->prepare("SELECT id FROM host WHERE name = ? LIMIT 1");
                    $stmt->execute([$hostName]);
                    $hostName_already_exist = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    if ($hostName_already_exist) {
                        // Check if the host is already mapped to this domain
                        $stmt = $db->prepare("SELECT domain_id FROM application_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1");
                        $stmt->execute([$domain_id, $hostName_already_exist]);
                        $domain_host_map_id = $stmt->fetchColumn();
                        $stmt->closeCursor();

                        if (!$domain_host_map_id) {
                            // Map the host to the domain
                            $stmt = $db->prepare("INSERT INTO application_host_map (domain_id,host_id) VALUES (?, ?)");
                            $stmt->execute([$domain_id, $hostName_already_exist]);
                        } else {
                            // Log duplicate mapping error
                            $stmt = $db->prepare("INSERT INTO error_log 
                                (channel, level, level_name, message, context, extra, created_at) 
                                VALUES ('epp', 300, 'WARNING', ?, ?, '{}', CURRENT_TIMESTAMP)");
                            $stmt->execute([
                                "Domain: $domainName; hostName: $hostName - duplicate mapping",
                                json_encode(['registrar_id' => $clid, 'domain' => $domainName, 'host' => $hostName])
                            ]);
                        }
                    } else {
                        $tlds = $db->query("SELECT tld FROM domain_tld")->fetchAll(PDO::FETCH_COLUMN);
                        $internal_host = false;
                        foreach ($tlds as $tld) {
                            if (str_ends_with(strtolower($hostName), strtolower($tld))) {
                                $internal_host = true;
                                break;
                            }
                        }

                        if ($internal_host) {
                            $stmt = $db->prepare("INSERT INTO host (name, domain_id, clid, crid, crdate) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP(3))");
                            $stmt->execute([$hostName, $domain_id, $clid, $clid]);
                            $host_id = $db->lastInsertId();

                            $stmt = $db->prepare("INSERT INTO application_host_map (domain_id, host_id) VALUES (?, ?)");
                            $stmt->execute([$domain_id, $host_id]);

                            foreach ($node->xpath('./domain:hostAddr') as $nodeAddr) {
                                $hostAddr = (string)$nodeAddr;
                                $addr_type = (string)($nodeAddr->attributes()->ip ?? 'v4');

                                if ($addr_type === 'v6') {
                                    $hostAddr = normalize_v6_address($hostAddr);
                                } else {
                                    $hostAddr = normalize_v4_address($hostAddr);
                                }

                                $stmt = $db->prepare("INSERT INTO host_addr (host_id, addr, ip) VALUES (?, ?, ?)");
                                $stmt->execute([$host_id, $hostAddr, $addr_type]);
                            }
                        } else {
                            sendEppError($conn, $db, 2303, "Host attribute $hostName does not exist", $clTRID, $trans);
                            return;
                        }
                    }
                }
            }

            $contact_admin_list = $xml->xpath("//domain:contact[@type='admin']");
            $contact_billing_list = $xml->xpath("//domain:contact[@type='billing']");
            $contact_tech_list = $xml->xpath("//domain:contact[@type='tech']");

            $contactTypes = [
                'admin' => $contact_admin_list,
                'billing' => $contact_billing_list,
                'tech' => $contact_tech_list
            ];

            foreach ($contactTypes as $type => $contact_list) {
                foreach ($contact_list as $element) {
                    $contact = (string)$element;
                    $stmt = $db->prepare("SELECT id FROM contact WHERE identifier = ? LIMIT 1");
                    $stmt->execute([$contact]);
                    $contact_id = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    $stmt = $db->prepare("INSERT INTO application_contact_map (domain_id,contact_id,type) VALUES(?,?,?)");
                    $stmt->execute([$domain_id, $contact_id, $type]);
                }
            }

            $stmt = $db->prepare("SELECT crdate,exdate FROM domain WHERE name = ? LIMIT 1");
            $stmt->execute([$domainName]);
            [$crdate, $exdate] = $stmt->fetch(PDO::FETCH_NUM);
            $stmt->closeCursor();

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();

            sendEppError($conn, $db, 2400, "Database failure: " . $e->getMessage(), $clTRID, $trans);
        }
        $svTRID = generateSvTRID();
        $response['command'] = 'create_domain';
        $response['resultCode'] = 1000;
        $response['lang'] = 'en-US';
        $response['message'] = 'Command completed successfully';
        $response['name'] = $domainName;
        $response['crDate'] = $crdate;
        $response['exDate'] = null;
        $response['clTRID'] = $clTRID;
        $response['svTRID'] = $svTRID;
        
        $epp = new EPP\EppWriter();
        $xml = $epp->epp_writer($response);
        updateTransaction($db, 'create', 'domain', $domainName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
        sendEppResponse($conn, $xml);
    } else {

        try {
            $db->beginTransaction();
            
            $response = [];
            if (isset($fee_create)) {
                $response['fee_fee'] = (string) $fee_create->children('urn:ietf:params:xml:ns:epp:fee-1.0')->fee;
                
                if ($response['fee_fee'] >= $price) {
                    $response['fee_currency'] = (string) $fee_create->children('urn:ietf:params:xml:ns:epp:fee-1.0')->currency;
                    $response['fee_price'] = $price;
                    $response['fee_balance'] = $registrar_balance;
                    $response['fee_creditLimit'] = $creditLimit;
                    $response['fee_include'] = true;
                } else {
                    $response['fee_include'] = false;
                    $db->rollBack();
                    sendEppError($conn, $db, 2004, "Provided fee is less than the server domain fee", $clTRID, $trans);
                    return;
                }
            }
            
            $domainSql = "INSERT INTO domain (
                name, tldid, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, rgpstatus, addPeriod,
                phase_name, tm_phase, tm_smd_id, tm_notice_id, tm_notice_accepted, tm_notice_expires
            ) VALUES (
                :name, :tld_id, :registrant_id, CURRENT_TIMESTAMP(3), DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL :date_add MONTH), NULL, :registrar_id, :registrar_id, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'addPeriod', :date_add2,
                :phase_name, :tm_phase, :tm_smd_id, :tm_notice_id, :tm_notice_accepted, :tm_notice_expires
            )";

            $domainStmt = $db->prepare($domainSql);
            $domainStmt->execute([
                ':name' => $domainName,
                ':tld_id' => $tld_id,
                ':registrant_id' => $registrant_id,
                ':date_add' => $date_add,
                ':date_add2' => $date_add,
                ':registrar_id' => $clid,
                ':phase_name' => $launch_phase_name ?? null,
                ':tm_phase' => $launch_phase ?? 'none',
                ':tm_smd_id' => $markId ?? null,
                ':tm_notice_id' => $noticeid ?? null,
                ':tm_notice_accepted' => normalizeDatetime($accepted) ?? null,
                ':tm_notice_expires' => normalizeDatetime($notafter) ?? null
            ]);

            $domain_id = $db->lastInsertId();

            $authInfoStmt = $db->prepare("INSERT INTO domain_authInfo (domain_id,authtype,authinfo) VALUES(:domain_id,'pw',:authInfo_pw)");
            $authInfoStmt->execute([
                ':domain_id' => $domain_id,
                ':authInfo_pw' => $authInfo_pw
            ]);
            
            $secDNSDataSet = $xml->xpath('//secDNS:dsData');

            if ($secDNSDataSet) {
                foreach ($secDNSDataSet as $secDNSData) {
                    // Extract dsData elements
                    $keyTag = (int) $secDNSData->xpath('secDNS:keyTag')[0] ?? null;
                    $alg = (int) $secDNSData->xpath('secDNS:alg')[0] ?? null;
                    $digestType = (int) $secDNSData->xpath('secDNS:digestType')[0] ?? null;
                    $digest = (string) $secDNSData->xpath('secDNS:digest')[0] ?? null;
                    $maxSigLife = $secDNSData->xpath('secDNS:maxSigLife') ? (int) $secDNSData->xpath('secDNS:maxSigLife')[0] : null;

                    // Data sanity checks
                    // Validate keyTag
                    if (!isset($keyTag) || !is_int($keyTag)) {
                        $db->rollBack();
                        sendEppError($conn, $db, 2005, 'Incomplete keyTag provided', $clTRID, $trans);
                        return;
                    }
                    if ($keyTag < 0 || $keyTag > 65535) {
                        $db->rollBack();
                        sendEppError($conn, $db, 2004, 'Invalid keyTag provided', $clTRID, $trans);
                        return;
                    }

                    // Validate alg
                    $validAlgorithms = [8, 13, 14, 15, 16];
                    if (!isset($alg) || !in_array($alg, $validAlgorithms)) {
                        $db->rollBack();
                        sendEppError($conn, $db, 2004, 'Invalid algorithm', $clTRID, $trans);
                        return;
                    }

                    // Validate digestType and digest
                    if (!isset($digestType) || !is_int($digestType)) {
                        $db->rollBack();
                        sendEppError($conn, $db, 2005, 'Invalid digestType', $clTRID, $trans);
                        return;
                    }
                    $validDigests = [
                    2 => 64,  // SHA-256
                    4 => 96   // SHA-384
                    ];
                    if (!isset($validDigests[$digestType])) {
                        $db->rollBack();
                        sendEppError($conn, $db, 2004, 'Unsupported digestType', $clTRID, $trans);
                        return;
                    }
                    if (!isset($digest) || strlen($digest) != $validDigests[$digestType] || !ctype_xdigit($digest)) {
                        $db->rollBack();
                        sendEppError($conn, $db, 2005, 'Invalid digest length or format', $clTRID, $trans);
                        return;
                    }

                    // Extract keyData elements if available
                    $flags = null;
                    $protocol = null;
                    $algKeyData = null;
                    $pubKey = null;

                    if ($secDNSData->xpath('secDNS:keyData')) {
                        $flags = (int) $secDNSData->xpath('secDNS:keyData/secDNS:flags')[0];
                        $protocol = (int) $secDNSData->xpath('secDNS:keyData/secDNS:protocol')[0];
                        $algKeyData = (int) $secDNSData->xpath('secDNS:keyData/secDNS:alg')[0];
                        $pubKey = (string) $secDNSData->xpath('secDNS:keyData/secDNS:pubKey')[0];

                        // Data sanity checks for keyData
                        // Validate flags
                        $validFlags = [256, 257];
                        if (isset($flags) && !in_array($flags, $validFlags)) {
                            $db->rollBack();
                            sendEppError($conn, $db, 2004, 'Invalid flags', $clTRID, $trans);
                            return;
                        }

                        // Validate protocol
                        if (isset($protocol) && $protocol != 3) {
                            $db->rollBack();
                            sendEppError($conn, $db, 2004, 'Invalid protocol', $clTRID, $trans);
                            return;
                        }

                        // Validate algKeyData
                        if (isset($algKeyData)) {
                            $db->rollBack();
                            sendEppError($conn, $db, 2005, 'Invalid algKeyData encoding', $clTRID, $trans);
                            return;
                        }

                        // Validate pubKey
                        if (isset($pubKey) && base64_encode(base64_decode($pubKey, true)) !== $pubKey) {
                            $db->rollBack();
                            sendEppError($conn, $db, 2005, 'Invalid pubKey encoding', $clTRID, $trans);
                            return;
                        }
                    }

                    $stmt = $db->prepare("INSERT INTO secdns (domain_id, maxsiglife, interface, keytag, alg, digesttype, digest, flags, protocol, keydata_alg, pubkey) VALUES (:domain_id, :maxsiglife, :interface, :keytag, :alg, :digesttype, :digest, :flags, :protocol, :keydata_alg, :pubkey)");

                    $stmt->execute([
                        ':domain_id' => $domain_id,
                        ':maxsiglife' => $maxSigLife,
                        ':interface' => 'dsData',
                        ':keytag' => $keyTag,
                        ':alg' => $alg,
                        ':digesttype' => $digestType,
                        ':digest' => $digest,
                        ':flags' => $flags ?? null,
                        ':protocol' => $protocol ?? null,
                        ':keydata_alg' => $algKeyData ?? null,
                        ':pubkey' => $pubKey ?? null
                    ]);
                }
            }

            $updateRegistrarStmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance - :price) WHERE id = :registrar_id");
            $updateRegistrarStmt->execute([
                ':price' => $price,
                ':registrar_id' => $clid
            ]);

            $paymentHistoryStmt = $db->prepare("INSERT INTO payment_history (registrar_id,date,description,amount) VALUES(:registrar_id,CURRENT_TIMESTAMP(3),:description,:amount)");
            $paymentHistoryStmt->execute([
                ':registrar_id' => $clid,
                ':description' => "create domain $domainName for period $date_add MONTH",
                ':amount' => "-$price"
            ]);

            $selectDomainDatesStmt = $db->prepare("SELECT crdate,exdate FROM domain WHERE name = :name LIMIT 1");
            $selectDomainDatesStmt->execute([':name' => $domainName]);
            [$from, $to] = $selectDomainDatesStmt->fetch(PDO::FETCH_NUM);
            $selectDomainDatesStmt->closeCursor();

            $statementStmt = $db->prepare("INSERT INTO statement (registrar_id,date,command,domain_name,length_in_months,fromS,toS,amount) VALUES(:registrar_id,CURRENT_TIMESTAMP(3),:cmd,:name,:date_add,:from,:to,:price)");
            $statementStmt->execute([
                ':registrar_id' => $clid,
                ':cmd' => 'create',
                ':name' => $domainName,
                ':date_add' => $date_add,
                ':from' => $from,
                ':to' => $to,
                ':price' => $price
            ]);

            if (!empty($hostObj_list) && is_array($hostObj_list)) {
                foreach ($hostObj_list as $node) {
                    $hostObj = strtoupper((string)$node);

                    $hostExistStmt = $db->prepare("SELECT id FROM host WHERE name = :hostObj LIMIT 1");
                    $hostExistStmt->execute([':hostObj' => $hostObj]);
                    $hostObj_already_exist = $hostExistStmt->fetchColumn();
                    $hostExistStmt->closeCursor();

                    if ($hostObj_already_exist) {
                        $domainHostMapStmt = $db->prepare("SELECT domain_id FROM domain_host_map WHERE domain_id = :domain_id AND host_id = :host_id LIMIT 1");
                        $domainHostMapStmt->execute([':domain_id' => $domain_id, ':host_id' => $hostObj_already_exist]);
                        $domain_host_map_id = $domainHostMapStmt->fetchColumn();
                        $domainHostMapStmt->closeCursor();

                        if (!$domain_host_map_id) {
                            $insertDomainHostMapStmt = $db->prepare("INSERT INTO domain_host_map (domain_id,host_id) VALUES(:domain_id,:host_id)");
                            $insertDomainHostMapStmt->execute([':domain_id' => $domain_id, ':host_id' => $hostObj_already_exist]);
                        } else {
                            $errorLogStmt = $db->prepare("INSERT INTO error_log 
                                (channel, level, level_name, message, context, extra, created_at) 
                                VALUES ('epp', 300, 'WARNING', :log, :context, '{}', CURRENT_TIMESTAMP)");
                            $errorLogStmt->execute([
                                ':log' => "Domain: $domainName; hostObj: $hostObj - is duplicated",
                                ':context' => json_encode(['registrar_id' => $clid, 'domain' => $domainName, 'host' => $hostObj])
                            ]);
                        }
                    } else {
                        $internal_host = false;
                        $stmt = $db->prepare("SELECT tld FROM domain_tld");
                        $stmt->execute();

                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $tld = strtoupper($row['tld']);
                            $tld = str_replace('.', '\\.', $tld); // Escape the dot for regex pattern matching
                            if (preg_match("/$tld$/i", $hostObj)) {
                                $internal_host = true;
                                break;
                            }
                        }
                        $stmt->closeCursor();

                        if ($internal_host) {
                            if (preg_match("/\.$domainName$/i", $hostObj)) {
                                $stmt = $db->prepare("INSERT INTO host (name,domain_id,clid,crid,crdate) VALUES(?, ?, ?, ?, CURRENT_TIMESTAMP(3))");
                                $stmt->execute([$hostObj, $domain_id, $clid, $clid]);
                                $host_id = $db->lastInsertId();

                                $stmt = $db->prepare("INSERT INTO domain_host_map (domain_id,host_id) VALUES(?, ?)");
                                $stmt->execute([$domain_id, $host_id]);
                            }
                        } else {
                            $stmt = $db->prepare("INSERT INTO host (name,clid,crid,crdate) VALUES(?, ?, ?, CURRENT_TIMESTAMP(3))");
                            $stmt->execute([$hostObj, $clid, $clid]);
                            $host_id = $db->lastInsertId();

                            $stmt = $db->prepare("INSERT INTO domain_host_map (domain_id,host_id) VALUES(?, ?)");
                            $stmt->execute([$domain_id, $host_id]);
                        }

                    }
                }
            }

            if (!empty($hostAttr_list) && is_array($hostAttr_list)) {
                foreach ($hostAttr_list as $node) {
                    // Extract the hostName
                    $hostName = strtoupper((string)$node->xpath('./domain:hostName')[0] ?? '');
                    if (empty($hostName)) {
                        continue; // Skip if no hostName found
                    }

                    // Check if the host already exists
                    $stmt = $db->prepare("SELECT id FROM host WHERE name = ? LIMIT 1");
                    $stmt->execute([$hostName]);
                    $hostName_already_exist = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    if ($hostName_already_exist) {
                        // Check if the host is already mapped to this domain
                        $stmt = $db->prepare("SELECT domain_id FROM domain_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1");
                        $stmt->execute([$domain_id, $hostName_already_exist]);
                        $domain_host_map_id = $stmt->fetchColumn();
                        $stmt->closeCursor();

                        if (!$domain_host_map_id) {
                            // Map the host to the domain
                            $stmt = $db->prepare("INSERT INTO domain_host_map (domain_id,host_id) VALUES (?, ?)");
                            $stmt->execute([$domain_id, $hostName_already_exist]);
                        } else {
                            // Log duplicate mapping error
                            $stmt = $db->prepare("INSERT INTO error_log 
                                (channel, level, level_name, message, context, extra, created_at) 
                                VALUES ('epp', 300, 'WARNING', ?, ?, '{}', CURRENT_TIMESTAMP)");
                            $stmt->execute([
                                "Domain: $domainName; hostName: $hostName - duplicate mapping",
                                json_encode(['registrar_id' => $clid, 'domain' => $domainName, 'host' => $hostName])
                            ]);
                        }
                    } else {
                        // Insert a new host
                        $stmt = $db->prepare("INSERT INTO host (name, domain_id, clid, crid, crdate) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP(3))");
                        $stmt->execute([$hostName, $domain_id, $clid, $clid]);
                        $host_id = $db->lastInsertId();

                        // Map the new host to the domain
                        $stmt = $db->prepare("INSERT INTO domain_host_map (domain_id, host_id) VALUES (?, ?)");
                        $stmt->execute([$domain_id, $host_id]);

                        // Process and insert host addresses
                        foreach ($node->xpath('./domain:hostAddr') as $nodeAddr) {
                            $hostAddr = (string)$nodeAddr;
                            $addr_type = (string)($nodeAddr->attributes()->ip ?? 'v4');

                            // Normalize the address
                            if ($addr_type === 'v6') {
                                $hostAddr = normalize_v6_address($hostAddr);
                            } else {
                                $hostAddr = normalize_v4_address($hostAddr);
                            }

                            // Insert the address into host_addr table
                            $stmt = $db->prepare("INSERT INTO host_addr (host_id, addr, ip) VALUES (?, ?, ?)");
                            $stmt->execute([$host_id, $hostAddr, $addr_type]);
                        }
                    }
                }
            }

            $contact_admin_list = $xml->xpath("//domain:contact[@type='admin']");
            $contact_billing_list = $xml->xpath("//domain:contact[@type='billing']");
            $contact_tech_list = $xml->xpath("//domain:contact[@type='tech']");

            $contactTypes = [
                'admin' => $contact_admin_list,
                'billing' => $contact_billing_list,
                'tech' => $contact_tech_list
            ];

            foreach ($contactTypes as $type => $contact_list) {
                foreach ($contact_list as $element) {
                    $contact = (string)$element;
                    $stmt = $db->prepare("SELECT id FROM contact WHERE identifier = ? LIMIT 1");
                    $stmt->execute([$contact]);
                    $contact_id = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    $stmt = $db->prepare("INSERT INTO domain_contact_map (domain_id,contact_id,type) VALUES(?,?,?)");
                    $stmt->execute([$domain_id, $contact_id, $type]);
                }
            }

            $stmt = $db->prepare("SELECT crdate,exdate FROM domain WHERE name = ? LIMIT 1");
            $stmt->execute([$domainName]);
            [$crdate, $exdate] = $stmt->fetch(PDO::FETCH_NUM);
            $stmt->closeCursor();

            $stmt = $db->prepare("SELECT id FROM statistics WHERE date = CURDATE()");
            $stmt->execute();
            $curdate_id = $stmt->fetchColumn();
            $stmt->closeCursor();

            if (!$curdate_id) {
                $stmt = $db->prepare("INSERT IGNORE INTO statistics (date) VALUES(CURDATE())");
                $stmt->execute();
            }
            $db->exec("UPDATE statistics SET created_domains = created_domains + 1 WHERE date = CURDATE()");

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();

            sendEppError($conn, $db, 2400, "Database failure: " . $e->getMessage(), $clTRID, $trans);
        }
        $svTRID = generateSvTRID();
        $response['command'] = 'create_domain';
        $response['resultCode'] = 1000;
        $response['lang'] = 'en-US';
        $response['message'] = 'Command completed successfully';
        $response['name'] = $domainName;
        $response['crDate'] = $crdate;
        $response['exDate'] = $exdate;
        $response['clTRID'] = $clTRID;
        $response['svTRID'] = $svTRID;
        
        $epp = new EPP\EppWriter();
        $xml = $epp->epp_writer($response);
        updateTransaction($db, 'create', 'domain', $domainName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
        sendEppResponse($conn, $xml);
    }    
}