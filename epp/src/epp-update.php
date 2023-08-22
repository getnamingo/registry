<?php

function processContactUpdate($conn, $db, $xml, $clid, $database_type) {
    $contactID = (string) $xml->command->update->children('urn:ietf:params:xml:ns:contact-1.0')->update->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    $contactRem = $xml->xpath('//contact:rem') ?? null;
    $contactAdd = $xml->xpath('//contact:add') ?? null;
    $contactChg = $xml->xpath('//contact:chg') ?? null;
    $identicaUpdate = $xml->xpath('//identica:update') ?? null;

    if (!$contactRem && !$contactAdd && !$contactChg && !$identicaUpdate) {
        sendEppError($conn, 2003, 'Required parameter missing');
        return;
    }

    if (!$contactID) {
        sendEppError($conn, 2003, 'Required parameter missing');
        return;
    }

    $stmt = $db->prepare("SELECT `id`, `clid` FROM `contact` WHERE `identifier` = :identifier LIMIT 1");
    $stmt->execute([':identifier' => $contactID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $contact_id = $row['id'] ?? null;
    $registrar_id_contact = $row['clid'] ?? null;

    if (!$contact_id) {
        sendEppError($conn, 2303, 'Object does not exist');
        return;
    }
    
    $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    $clid = $stmt->fetch(PDO::FETCH_ASSOC);
    $clid = $clid['id'];

    if ($clid != $registrar_id_contact) {
        sendEppError($conn, 2201, 'Authorization error');
        return;
    }

    $stmt = $db->prepare("SELECT `status` FROM `contact_status` WHERE `contact_id` = :contact_id");
    $stmt->execute([':contact_id' => $contact_id]);
    while ($statusRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $statusRow['status'];
        if (preg_match('/.*(serverUpdateProhibited)$/', $status) || preg_match('/^pending/', $status)) {
            sendEppError($conn, 2304, 'Object status prohibits operation');
            return;
        }
    }

    $clientUpdateProhibited = 0;
    $stmt = $db->prepare("SELECT `id` FROM `contact_status` WHERE `contact_id` = :contact_id AND `status` = 'clientUpdateProhibited' LIMIT 1");
    $stmt->execute([':contact_id' => $contact_id]);
    $clientUpdateProhibited = $stmt->fetchColumn();

    if ($contactRem) {
        $statusList = $xml->xpath('contact:status/@s', $contactRem);

        if (count($statusList) == 0) {
            sendEppError($conn, 2005, 'Parameter value syntax error');
            return;
        }

        foreach ($statusList as $node) {
            $status = (string)$node;
            if ($status === 'clientUpdateProhibited') {
                $clientUpdateProhibited = 0;
            }
            if (!preg_match('/^(clientDeleteProhibited|clientTransferProhibited|clientUpdateProhibited)$/', $status)) {
                sendEppError($conn, 2005, 'Parameter value syntax error');
                return;
            }
        }
    }

    if ($clientUpdateProhibited) {
        sendEppError($conn, 2304, 'Object status prohibits operation');
        return;
    }

    if ($contactAdd) {
        $statusList = $xml->xpath('contact:status/@s', $contactAdd);

        if (count($statusList) == 0) {
            sendEppError($conn, 2005, 'Parameter value syntax error');
            return;
        }

        foreach ($statusList as $node) {
            $status = (string)$node;
            if (!preg_match('/^(clientDeleteProhibited|clientTransferProhibited|clientUpdateProhibited)$/', $status)) {
                sendEppError($conn, 2005, 'Parameter value syntax error');
                return;
            }

            if (count($xml->xpath('contact:status[@s="' . $status . '"]', $contactRem)) == 0) {
                $stmt = $db->prepare("SELECT `id` FROM `contact_status` WHERE `contact_id` = :contact_id AND `status` = :status LIMIT 1");
                $stmt->execute([':contact_id' => $contact_id, ':status' => $status]);
                $contactStatusId = $stmt->fetchColumn();

                if ($contactStatusId) {
                    sendEppError($conn, 2306, 'Parameter policy error');
                    return;
                }
            }
        }
    }

    if ($contactChg) {
        $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
        $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
        $stmt->execute();
        $clid = $stmt->fetch(PDO::FETCH_ASSOC);
        $postalInfoInt = null;
        $postalInfoLoc = null;

        $postalInfoIntNodes = $xml->xpath("//contact:postalInfo[@type='int']");

        if (count($postalInfoIntNodes) > 0) {
            $postalInfoInt = $postalInfoIntNodes[0];
        }

        $postalInfoLocNodes = $xml->xpath("//contact:postalInfo[@type='loc']");

        if (count($postalInfoLocNodes) > 0) {
            $postalInfoLoc = $postalInfoLocNodes[0];
        }

        if ($postalInfoInt) {
            $postalInfoIntName = (string) $postalInfoInt->children('urn:ietf:params:xml:ns:contact-1.0')->name;
            $postalInfoIntOrg  = (string) $postalInfoInt->children('urn:ietf:params:xml:ns:contact-1.0')->org;
        
            $streetInt = [];
            if (isset($postalInfoInt->children('urn:ietf:params:xml:ns:contact-1.0')->addr->street)) {
                foreach ($postalInfoInt->children('urn:ietf:params:xml:ns:contact-1.0')->addr->street as $street) {
                    $streetInt[] = (string) $street;
                }
            }

            $postalInfoIntStreet1 = $streetInt[0] ?? '';
            $postalInfoIntStreet2 = $streetInt[1] ?? '';
            $postalInfoIntStreet3 = $streetInt[2] ?? '';
        
            $postalInfoIntCity = (string) $postalInfoInt->children('urn:ietf:params:xml:ns:contact-1.0')->addr->city;
            $postalInfoIntSp   = (string) $postalInfoInt->children('urn:ietf:params:xml:ns:contact-1.0')->addr->sp;
            $postalInfoIntPc   = (string) $postalInfoInt->children('urn:ietf:params:xml:ns:contact-1.0')->addr->pc;
            $postalInfoIntCc   = (string) $postalInfoInt->children('urn:ietf:params:xml:ns:contact-1.0')->addr->cc;

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
            $postalInfoLocName = (string) $postalInfoLoc->children('urn:ietf:params:xml:ns:contact-1.0')->name;
            $postalInfoLocOrg  = (string) $postalInfoLoc->children('urn:ietf:params:xml:ns:contact-1.0')->org;
        
            $streetLoc = [];
            if (isset($postalInfoLoc->children('urn:ietf:params:xml:ns:contact-1.0')->addr->street)) {
                foreach ($postalInfoLoc->children('urn:ietf:params:xml:ns:contact-1.0')->addr->street as $street) {
                    $streetLoc[] = (string) $street;
                }
            }

            $postalInfoLocStreet1 = $streetLoc[0] ?? '';
            $postalInfoLocStreet2 = $streetLoc[1] ?? '';
            $postalInfoLocStreet3 = $streetLoc[2] ?? '';
        
            $postalInfoLocCity = (string) $postalInfoLoc->children('urn:ietf:params:xml:ns:contact-1.0')->addr->city;
            $postalInfoLocSp   = (string) $postalInfoLoc->children('urn:ietf:params:xml:ns:contact-1.0')->addr->sp;
            $postalInfoLocPc   = (string) $postalInfoLoc->children('urn:ietf:params:xml:ns:contact-1.0')->addr->pc;
            $postalInfoLocCc   = (string) $postalInfoLoc->children('urn:ietf:params:xml:ns:contact-1.0')->addr->cc;

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

        $contactUpdate = $xml->command->update->children('urn:ietf:params:xml:ns:contact-1.0')->update;

        $voice = (string) $contactUpdate->chg->voice;
        $voice_x = '';
        if ($voice && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $voice) || strlen($voice) > 17)) {
            sendEppError($conn, 2005, 'Voice must be (\+[0-9]{1,3}\.[0-9]{1,14})');
            return;
        }

        $fax = (string) $contactUpdate->chg->fax;
        $fax_x = '';
        if ($contactUpdate->fax) {
            $fax_x = (string) $contactUpdate->chg->fax->attributes()->x;
        }
        if ($fax && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $fax) || strlen($fax) > 17)) {
            sendEppError($conn, 2005, 'Fax must be (\+[0-9]{1,3}\.[0-9]{1,14})');
            return;
        }

        $email = (string) $contactUpdate->chg->email;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendEppError($conn, 2005, 'Email address failed check');
            return;
        }

        $authInfo_pw = (string) $contactUpdate->chg->authInfo->pw;
        if ($authInfo_pw) {
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

    }

    $identicaUpdateResults = $xml->xpath('//identica:update');

    if (!empty($identicaUpdateResults)) {
        $identica_update = $identicaUpdateResults[0];
    } else {
        $identica_update = null;
    }

    if ($identica_update) {
        $nin = (string)$identica_update->xpath('//identica:nin[1]')[0];
        $nin_type = (string)$identica_update->xpath('//identica:nin/@type[1]')[0];

        if (!preg_match('/\d/', $nin)) {
            sendEppError($conn, 2005, 'Parameter value syntax error');
            return;
        }

        if (!in_array($nin_type, ['personal', 'business'])) {
            sendEppError($conn, 2005, 'Parameter value syntax error');
            return;
        }
    }

    if ($contactRem && $xml->xpath("./*[name()='$contactRem']")) {
        $status_list = $xml->xpath("contact:status/@s");

        foreach ($status_list as $node) {
            $status = (string)$node;
            $sth = $db->prepare("DELETE FROM `contact_status` WHERE `contact_id` = ? AND `status` = ?");
            $sth->execute([$contact_id, $status]);
        }
    }

    if ($contactAdd && $xml->xpath("./*[name()='$contactAdd']")) {
        $status_list = $xml->xpath("contact:status/@s");

        foreach ($status_list as $node) {
            $status = (string)$node;
            $sth = $db->prepare("INSERT INTO `contact_status` (`contact_id`,`status`) VALUES(?,?)");
            $sth->execute([$contact_id, $status]);
        }
    }

    if ($contactChg) {
    $stmt = $db->prepare("SELECT `voice`,`voice_x`,`fax`,`fax_x`,`email`,`clid`,`crid`,`crdate`,`upid`,`update`,`trdate`,`trstatus`,`reid`,`redate`,`acid`,`acdate`,`disclose_voice`,`disclose_fax`,`disclose_email` FROM `contact` WHERE `id` = :contact_id LIMIT 1");
    $stmt->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    extract($row);

    if ($postalInfoInt) {
        // For `contact_postalInfo` table with `type` = 'int'
        $stmt_int = $db->prepare("SELECT `name`,`org`,`street1`,`street2`,`street3`,`city`,`sp`,`pc`,`cc`,`disclose_name_int`,`disclose_org_int`,`disclose_addr_int` FROM `contact_postalInfo` WHERE `contact_id` = :contact_id AND `type` = 'int' LIMIT 1");
        $stmt_int->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
        $stmt_int->execute();
        $row_int = $stmt_int->fetch(PDO::FETCH_ASSOC);
        extract($row_int);
    }

    if ($postalInfoLoc) {
        // For `contact_postalInfo` table with `type` = 'loc'
        $stmt_loc = $db->prepare("SELECT `name`,`org`,`street1`,`street2`,`street3`,`city`,`sp`,`pc`,`cc`,`disclose_name_loc`,`disclose_org_loc`,`disclose_addr_loc` FROM `contact_postalInfo` WHERE `contact_id` = :contact_id AND `type` = 'loc' LIMIT 1");
        $stmt_loc->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
        $stmt_loc->execute();
        $row_loc = $stmt_loc->fetch(PDO::FETCH_ASSOC);
        extract($row_loc);
    }

    // For `contact_authInfo` table with `authtype` = 'pw'
    $stmt_pw = $db->prepare("SELECT `authinfo` FROM `contact_authInfo` WHERE `contact_id` = :contact_id AND `authtype` = 'pw' LIMIT 1");
    $stmt_pw->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
    $stmt_pw->execute();
    $e_authInfo_pw = $stmt_pw->fetchColumn();

    // For `contact_authInfo` table with `authtype` = 'ext'
    $stmt_ext = $db->prepare("SELECT `authinfo` FROM `contact_authInfo` WHERE `contact_id` = :contact_id AND `authtype` = 'ext' LIMIT 1");
    $stmt_ext->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
    $stmt_ext->execute();
    $e_authInfo_ext = $stmt_ext->fetchColumn();

    $postalInfo_int = $xml->xpath("//contact:postalInfo[@type='int']")[0] ?? null;
    if ($postalInfoInt) {
        $int_name = (string)($postalInfo_int->xpath("contact:name")[0] ?? "");
        $int_org = (string)($postalInfo_int->xpath("contact:org")[0] ?? "");

        $streets_int = $postalInfo_int->xpath("contact:addr/contact:street");
        $int_street1 = (string)($streets_int[0] ?? "");
        $int_street2 = (string)($streets_int[1] ?? "");
        $int_street3 = (string)($streets_int[2] ?? "");

        $int_city = (string)($postalInfo_int->xpath("contact:addr/contact:city")[0] ?? "");
        $int_sp = (string)($postalInfo_int->xpath("contact:addr/contact:sp")[0] ?? "");
        $int_pc = (string)($postalInfo_int->xpath("contact:addr/contact:pc")[0] ?? "");
        $int_cc = (string)($postalInfo_int->xpath("contact:addr/contact:cc")[0] ?? "");
    }

    $postalInfo_loc = $xml->xpath("//contact:postalInfo[@type='loc']")[0] ?? null;
    if ($postalInfoLoc) {
        $loc_name = (string)($postalInfo_loc->xpath("contact:name")[0] ?? "");
        $loc_org = (string)($postalInfo_loc->xpath("contact:org")[0] ?? "");

        $streets_loc = $postalInfo_loc->xpath("contact:addr/contact:street");
        $loc_street1 = (string)($streets_loc[0] ?? "");
        $loc_street2 = (string)($streets_loc[1] ?? "");
        $loc_street3 = (string)($streets_loc[2] ?? "");

        $loc_city = (string)($postalInfo_loc->xpath("contact:addr/contact:city")[0] ?? "");
        $loc_sp = (string)($postalInfo_loc->xpath("contact:addr/contact:sp")[0] ?? "");
        $loc_pc = (string)($postalInfo_loc->xpath("contact:addr/contact:pc")[0] ?? "");
        $loc_cc = (string)($postalInfo_loc->xpath("contact:addr/contact:cc")[0] ?? "");
    }

    $e_voice = (string)($xml->xpath("//contact:voice")[0] ?? "");
    $e_voice_x = (string)($xml->xpath("//contact:voice/@x")[0] ?? "");
    $e_fax = (string)($xml->xpath("//contact:fax")[0] ?? "");
    $e_fax_x = (string)($xml->xpath("//contact:fax/@x")[0] ?? "");
    $e_email = (string)($xml->xpath("//contact:email")[0] ?? "");
    $e_authInfo_pw = (string)($xml->xpath("//contact:authInfo/contact:pw")[0] ?? "");
    $e_authInfo_ext = (string)($xml->xpath("//contact:authInfo/contact:ext")[0] ?? "");

    // Update contact
    $query = "UPDATE `contact` SET `voice` = ?, `voice_x` = ?, `fax` = ?, `fax_x` = ?, `email` = ?, `update` = CURRENT_TIMESTAMP WHERE `id` = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([
        $e_voice ?: null,
        $e_voice_x ?: null,
        $e_fax ?: null,
        $e_fax_x ?: null,
        $e_email,
        $contact_id
    ]);

    if ($postalInfoInt) {
        // Update contact_postalInfo for 'int'
        $query = "UPDATE `contact_postalInfo` SET `name` = ?, `org` = ?, `street1` = ?, `street2` = ?, `street3` = ?, `city` = ?, `sp` = ?, `pc` = ?, `cc` = ? WHERE `contact_id` = ? AND `type` = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([
            $int_name,
            $int_org,
            $int_street1,
            $int_street2,
            $int_street3,
            $int_city,
            $int_sp,
            $int_pc,
            $int_cc,
            $contact_id,
            'int'
        ]);
    }

    if ($postalInfoLoc) {
        // Update contact_postalInfo for 'loc'
        $stmt = $db->prepare($query); // Same query as above, can reuse
        $stmt->execute([
            $loc_name,
            $loc_org,
            $loc_street1,
            $loc_street2,
            $loc_street3,
            $loc_city,
            $loc_sp,
            $loc_pc,
            $loc_cc,
            $contact_id,
            'loc'
        ]);
    }

    // Update contact_authInfo for 'pw'
    $query = "UPDATE `contact_authInfo` SET `authinfo` = ? WHERE `contact_id` = ? AND `authtype` = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([
        $e_authInfo_pw,
        $contact_id,
        'pw'
    ]);

    // Update contact_authInfo for 'ext'
    $stmt = $db->prepare($query); // Same query as above, can reuse
    $stmt->execute([
        $e_authInfo_ext,
        $contact_id,
        'ext'
    ]);

    }

    if ($identica_update) {
        $query = "UPDATE `contact` SET `nin` = ?, `nin_type` = ?, `update` = CURRENT_TIMESTAMP WHERE `id` = ?";
        $stmt = $db->prepare($query);

        if (!$stmt) {
            sendEppError($conn, 2400, 'Command failed');
            return;
        }

        $result = $stmt->execute([
            $nin ?: null,
            $nin_type ?: null,
            $contact_id
        ]);

        if (!$result) {
            sendEppError($conn, 2400, 'Command failed');
            return;
        }
    }

    $response = [
        'command' => 'update_contact',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'clTRID' => $clTRID,
        'svTRID' => generateSvTRID(),
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    sendEppResponse($conn, $xml);
}