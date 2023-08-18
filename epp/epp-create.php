<?php

function processContactCreate($conn, $db, $xml, $clid, $database_type) {
    $contactID = (string) $xml->command->create->children('urn:ietf:params:xml:ns:contact-1.0')->create->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    if (!$contactID) {
        sendEppError($conn, 2003, 'Required parameter missing');
        return;
    }

    // Validation for contact ID
    $invalid_identifier = validate_identifier($contactID);
    if ($invalid_identifier) {
        sendEppError($conn, 2005, 'Invalid contact ID');
        return;
    }
    
    $stmt = $db->prepare("SELECT * FROM contact WHERE identifier = :id");
    $stmt->execute(['id' => $contactID]);

    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($contact) {
        sendEppError($conn, 2302, 'Contact ID already exists');
        return;
    }
	
    $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    $clid = $stmt->fetch(PDO::FETCH_ASSOC);
    
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
            sendEppError($conn, 2003, 'Missing contact:name');
            return;
        }

        if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntName) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntName)) {
            sendEppError($conn, 2005, 'Invalid contact:name');
            return;
        }

        if ($postalInfoIntOrg) {
            if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntOrg) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntOrg)) {
                sendEppError($conn, 2005, 'Invalid contact:org');
                return;
            }
        }

        if ($postalInfoIntStreet1) {
            if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet1) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet1)) {
                sendEppError($conn, 2005, 'Invalid contact:street');
                return;
            }
        }

        if ($postalInfoIntStreet2) {
            if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet2) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet2)) {
                sendEppError($conn, 2005, 'Invalid contact:street');
                return;
            }
        }

        if ($postalInfoIntStreet3) {
            if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet3) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet3)) {
                sendEppError($conn, 2005, 'Invalid contact:street');
                return;
            }
        }

        if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoIntCity) || !preg_match('/^[a-z][a-z\-\.\s]{3,}$/i', $postalInfoIntCity)) {
            sendEppError($conn, 2005, 'Invalid contact:city');
            return;
        }

        if ($postalInfoIntSp) {
            if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoIntSp) || !preg_match('/^[A-Z][a-zA-Z\-\.\s]{1,}$/', $postalInfoIntSp)) {
                sendEppError($conn, 2005, 'Invalid contact:sp');
                return;
            }
        }

        if ($postalInfoIntPc) {
            if (preg_match('/(^\-)|(\-\-)|(\-$)/', $postalInfoIntPc) || !preg_match('/^[A-Z0-9\-\s]{3,}$/', $postalInfoIntPc)) {
                sendEppError($conn, 2005, 'Invalid contact:pc');
                return;
            }
        }

        if (!preg_match('/^(AF|AX|AL|DZ|AS|AD|AO|AI|AQ|AG|AR|AM|AW|AU|AT|AZ|BS|BH|BD|BB|BY|BE|BZ|BJ|BM|BT|BO|BQ|BA|BW|BV|BR|IO|BN|BG|BF|BI|KH|CM|CA|CV|KY|CF|TD|CL|CN|CX|CC|CO|KM|CG|CD|CK|CR|CI|HR|CU|CW|CY|CZ|DK|DJ|DM|DO|EC|EG|SV|GQ|ER|EE|ET|FK|FO|FJ|FI|FR|GF|PF|TF|GA|GM|GE|DE|GH|GI|GR|GL|GD|GP|GU|GT|GG|GN|GW|GY|HT|HM|VA|HN|HK|HU|IS|IN|ID|IR|IQ|IE|IM|IL|IT|JM|JP|JE|JO|KZ|KE|KI|KP|KR|KW|KG|LA|LV|LB|LS|LR|LY|LI|LT|LU|MO|MK|MG|MW|MY|MV|ML|MT|MH|MQ|MR|MU|YT|MX|FM|MD|MC|MN|ME|MS|MA|MZ|MM|NA|NR|NP|NL|NC|NZ|NI|NE|NG|NU|NF|MP|NO|OM|PK|PW|PS|PA|PG|PY|PE|PH|PN|PL|PT|PR|QA|RE|RO|RU|RW|BL|SH|KN|LC|MF|PM|VC|WS|SM|ST|SA|SN|RS|SC|SL|SG|SX|SK|SI|SB|SO|ZA|GS|ES|LK|SD|SR|SJ|SZ|SE|CH|SY|TW|TJ|TZ|TH|TL|TG|TK|TO|TT|TN|TR|TM|TC|TV|UG|UA|AE|GB|US|UM|UY|UZ|VU|VE|VN|VG|VI|WF|EH|YE|ZM|ZW)$/', $postalInfoIntCc)) {
            sendEppError($conn, 2005, 'Invalid contact:cc');
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
            sendEppError($conn, 2003, 'Missing contact:name');
            return;
        }

        if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocName) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocName)) {
            sendEppError($conn, 2005, 'Invalid contact:name');
            return;
        }

        if ($postalInfoLocOrg) {
            if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocOrg) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocOrg)) {
                sendEppError($conn, 2005, 'Invalid contact:org');
                return;
            }
        }

        if ($postalInfoLocStreet1) {
            if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet1) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet1)) {
                sendEppError($conn, 2005, 'Invalid contact:street');
                return;
            }
        }

        if ($postalInfoLocStreet2) {
            if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet2) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet2)) {
                sendEppError($conn, 2005, 'Invalid contact:street');
                return;
            }
        }

        if ($postalInfoLocStreet3) {
            if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet3) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet3)) {
                sendEppError($conn, 2005, 'Invalid contact:street');
                return;
            }
        }

        if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoLocCity) || !preg_match('/^[a-z][a-z\-\.\s]{3,}$/i', $postalInfoLocCity)) {
            sendEppError($conn, 2005, 'Invalid contact:city');
            return;
        }

        if ($postalInfoLocSp) {
            if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoLocSp) || !preg_match('/^[A-Z][a-zA-Z\-\.\s]{1,}$/', $postalInfoLocSp)) {
                sendEppError($conn, 2005, 'Invalid contact:sp');
                return;
            }
        }

        if ($postalInfoLocPc) {
            if (preg_match('/(^\-)|(\-\-)|(\-$)/', $postalInfoLocPc) || !preg_match('/^[A-Z0-9\-\s]{3,}$/', $postalInfoLocPc)) {
                sendEppError($conn, 2005, 'Invalid contact:pc');
                return;
            }
        }

        if (!preg_match('/^(AF|AX|AL|DZ|AS|AD|AO|AI|AQ|AG|AR|AM|AW|AU|AT|AZ|BS|BH|BD|BB|BY|BE|BZ|BJ|BM|BT|BO|BQ|BA|BW|BV|BR|IO|BN|BG|BF|BI|KH|CM|CA|CV|KY|CF|TD|CL|CN|CX|CC|CO|KM|CG|CD|CK|CR|CI|HR|CU|CW|CY|CZ|DK|DJ|DM|DO|EC|EG|SV|GQ|ER|EE|ET|FK|FO|FJ|FI|FR|GF|PF|TF|GA|GM|GE|DE|GH|GI|GR|GL|GD|GP|GU|GT|GG|GN|GW|GY|HT|HM|VA|HN|HK|HU|IS|IN|ID|IR|IQ|IE|IM|IL|IT|JM|JP|JE|JO|KZ|KE|KI|KP|KR|KW|KG|LA|LV|LB|LS|LR|LY|LI|LT|LU|MO|MK|MG|MW|MY|MV|ML|MT|MH|MQ|MR|MU|YT|MX|FM|MD|MC|MN|ME|MS|MA|MZ|MM|NA|NR|NP|NL|NC|NZ|NI|NE|NG|NU|NF|MP|NO|OM|PK|PW|PS|PA|PG|PY|PE|PH|PN|PL|PT|PR|QA|RE|RO|RU|RW|BL|SH|KN|LC|MF|PM|VC|WS|SM|ST|SA|SN|RS|SC|SL|SG|SX|SK|SI|SB|SO|ZA|GS|ES|LK|SD|SR|SJ|SZ|SE|CH|SY|TW|TJ|TZ|TH|TL|TG|TK|TO|TT|TN|TR|TM|TC|TV|UG|UA|AE|GB|US|UM|UY|UZ|VU|VE|VN|VG|VI|WF|EH|YE|ZM|ZW)$/', $postalInfoLocCc)) {
            sendEppError($conn, 2005, 'Invalid contact:cc');
            return;
        }
    }

    if (!$postalInfoInt && !$postalInfoLoc) {
        sendEppError($conn, 2003, 'Missing contact:postalInfo');
        return;
    }
	
	$contactCreate = $xml->command->create->children('urn:ietf:params:xml:ns:contact-1.0')->create;

	$voice = (string) $contactCreate->voice;
	$voice_x = (string) $contactCreate->voice->attributes()->x;
    if ($voice && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $voice) || strlen($voice) > 17)) {
        sendEppError($conn, 2005, 'Voice must be (\+[0-9]{1,3}\.[0-9]{1,14})');
        return;
    }

	$fax = (string) $contactCreate->fax;
	$fax_x = '';
	if ($contactCreate->fax) {
	    $fax_x = (string) $contactCreate->fax->attributes()->x;
	}
    if ($fax && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $fax) || strlen($fax) > 17)) {
        sendEppError($conn, 2005, 'Fax must be (\+[0-9]{1,3}\.[0-9]{1,14})');
        return;
    }

    $email = (string) $contactCreate->email;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendEppError($conn, 2005, 'Email address failed check');
        return;
    }

    $authInfo_pw = (string) $contactCreate->authInfo->pw;
    if (!$authInfo_pw) {
        sendEppError($conn, 2003, 'Missing contact:pw');
        return;
    }

    if ((strlen($authInfo_pw) < 6) || (strlen($authInfo_pw) > 16)) {
        sendEppError($conn, 2005, 'Password needs to be at least 6 and up to 16 characters long');
        return;
    }

    if (!preg_match('/[A-Z]/', $authInfo_pw)) {
        sendEppError($conn, 2005, 'Password should have both upper and lower case characters');
        return;
    }

    if (!preg_match('/\d/', $authInfo_pw)) {
        sendEppError($conn, 2005, 'Password should contain one or more numbers');
        return;
    }

    $contact_disclose = $xml->xpath('//contact:disclose');
    $disclose_voice = 1;
    $disclose_fax = 1;
    $disclose_email = 1;
    $disclose_name_int = 1;
    $disclose_name_loc = 1;
    $disclose_org_int = 1;
    $disclose_org_loc = 1;
    $disclose_addr_int = 1;
    $disclose_addr_loc = 1;

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

    $obj_ext = $xml->xpath('//identExt:create')[0] ?? null;

    if ($obj_ext) {
        $nin = (string)$obj_ext->xpath('identExt:nin')[0] ?? '';
        $nin_type = (string)$obj_ext->xpath('identExt:nin/@type')[0] ?? '';

        if (!preg_match('/\d/', $nin)) {
            sendEppError($conn, 2005, 'NIN should contain one or more numbers');
            return;
        }
        if (!in_array($nin_type, ['personal', 'business'])) {
            sendEppError($conn, 2005, 'NIN type is invalid');
            return;
        }
    }
	
    try {
        if ($database_type === 'mysql') {
            $stmt = $db->prepare("INSERT INTO contact (identifier,voice,voice_x,fax,fax_x,email,nin,nin_type,clid,crid,crdate,upid,`update`,trdate,trstatus,reid,redate,acid,acdate,disclose_voice,disclose_fax,disclose_email) VALUES(?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,?,?,?)");
        } elseif ($database_type === 'pgsql') {
            $stmt = $db->prepare("INSERT INTO \"contact\" (\"identifier\",\"voice\",\"voice_x\",\"fax\",\"fax_x\",\"email\",\"nin\",\"nin_type\",\"clid\",\"crid\",\"crdate\",\"upid\",\"update\",\"trdate\",\"trstatus\",\"reid\",\"redate\",\"acid\",\"acdate\",\"disclose_voice\",\"disclose_fax\",\"disclose_email\") VALUES(?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,?,?,?)");
        }

        $stmt->execute([
            $contactID,
            $voice ?? null,
            empty($voice_x) ? null : $voice_x,
            empty($fax) ? null : $fax,
            empty($fax_x) ? null : $fax_x,
            $email,
            $nin ?? null,
            $nin_type ?? null,
            $clid['id'],
            $clid['id'],
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
		
        $stmt = $db->prepare("SELECT identifier FROM contact WHERE id = ? LIMIT 1");
        $stmt->execute([$contact_id]);
        $identifier = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT crdate FROM contact WHERE id = ? LIMIT 1");
        $stmt->execute([$contact_id]);
        $crdate = $stmt->fetchColumn();

    } catch (PDOException $e) {
		    echo "Database Error: " . $e->getMessage();

        sendEppError($conn, 2400, 'Database error');
    	return;
    }

    $response = [
        'command' => 'create_contact',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'id' => $identifier,
        'crDate' => $crdate,
        'clTRID' => $clTRID,
        'svTRID' => generateSvTRID(),
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    sendEppResponse($conn, $xml);
}