<?php

function processContactUpdate($conn, $db, $xml, $clid, $database_type, $trans) {
    $contactID = (string) $xml->command->update->children('urn:ietf:params:xml:ns:contact-1.0')->update->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    $contactRem = $xml->xpath('//contact:rem') ?? null;
    $contactAdd = $xml->xpath('//contact:add') ?? null;
    $contactChg = $xml->xpath('//contact:chg') ?? null;
    $identicaUpdate = $xml->xpath('//identica:update') ?? null;

    if (!$contactRem && !$contactAdd && !$contactChg && !$identicaUpdate) {
        sendEppError($conn, $db, 2003, 'At least one contact:rem || contact:add || contact:chg', $clTRID, $trans);
        return;
    }

    if (!$contactID) {
        sendEppError($conn, $db, 2003, 'Contact identifier missing', $clTRID, $trans);
        return;
    }

    $stmt = $db->prepare("SELECT id, clid FROM contact WHERE identifier = :identifier LIMIT 1");
    $stmt->execute([':identifier' => $contactID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $contact_id = $row['id'] ?? null;
    $registrar_id_contact = $row['clid'] ?? null;

    if (!$contact_id) {
        sendEppError($conn, $db, 2303, 'Contact does not exist', $clTRID, $trans);
        return;
    }

    $clid = getClid($db, $clid);
    if ($clid != $registrar_id_contact) {
        sendEppError($conn, $db, 2201, 'It belongs to another registrar', $clTRID, $trans);
        return;
    }

    $stmt = $db->prepare("SELECT status FROM contact_status WHERE contact_id = :contact_id");
    $stmt->execute([':contact_id' => $contact_id]);
    while ($statusRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $statusRow['status'];
        if (preg_match('/.*(serverUpdateProhibited)$/', $status) || preg_match('/^pending/', $status)) {
            sendEppError($conn, $db, 2304, 'It has a serverUpdateProhibited or pendingUpdate status that does not allow modification', $clTRID, $trans);
            return;
        }
    }
    $stmt->closeCursor();

    $hasClientUpdateProhibited = false;
    $clientWantsToRemoveUpdateLock = false;

    $stmt = $db->prepare("SELECT id FROM contact_status WHERE contact_id = :contact_id AND status = 'clientUpdateProhibited' LIMIT 1");
    $stmt->execute([':contact_id' => $contact_id]);
    $hasClientUpdateProhibited = $stmt->fetchColumn() ? true : false;
    $stmt->closeCursor();

    if ($contactRem) {
        $statusList = $contactRem[0]->xpath('contact:status/@s');

        if (count($statusList) == 0) {
            sendEppError($conn, $db, 2005, 'At least one status element MUST be present', $clTRID, $trans);
            return;
        }

        foreach ($statusList as $node) {
            $status = (string)$node;

            if (!preg_match('/^(clientDeleteProhibited|clientTransferProhibited|clientUpdateProhibited)$/', $status)) {
                sendEppError($conn, $db, 2005, 'Only these clientDeleteProhibited|clientTransferProhibited|clientUpdateProhibited statuses are accepted', $clTRID, $trans);
                return;
            }

            if ($status === 'clientUpdateProhibited') {
                $clientWantsToRemoveUpdateLock = true;
            }
        }
    }

    if ($hasClientUpdateProhibited && !$clientWantsToRemoveUpdateLock) {
        sendEppError($conn, $db, 2304, 'Object status prohibits operation: It has clientUpdateProhibited status, but you did not indicate this status when deleting', $clTRID, $trans);
        return;
    }

    if ($contactAdd) {
        $statusList = $contactAdd[0]->xpath('contact:status/@s');

        if (count($statusList) == 0) {
            sendEppError($conn, $db, 2005, 'At least one status element MUST be present', $clTRID, $trans);
            return;
        }

        foreach ($statusList as $node) {
            $status = (string)$node;
            if (!preg_match('/^(clientDeleteProhibited|clientTransferProhibited|clientUpdateProhibited)$/', $status)) {
                sendEppError($conn, $db, 2005, 'Only these clientDeleteProhibited|clientTransferProhibited|clientUpdateProhibited statuses are accepted', $clTRID, $trans);
                return;
            }

            if (!$contactRem || count($contactRem[0]->xpath('contact:status[@s="' . $status . '"]')) == 0) {
                $stmt = $db->prepare("SELECT id FROM contact_status WHERE contact_id = :contact_id AND status = :status LIMIT 1");
                $stmt->execute([':contact_id' => $contact_id, ':status' => $status]);
                $contactStatusId = $stmt->fetchColumn();
                $stmt->closeCursor();

                if ($contactStatusId) {
                    sendEppError($conn, $db, 2306, 'This status '.$status.' already exists for this contact', $clTRID, $trans);
                    return;
                }
            }
        }
    }

    if ($contactChg) {
        $clid = getClid($db, $clid);
        $postalInfoInt = null;
        $postalInfoLoc = null;

        $postalInfoAllNodes = $xml->xpath('//contact:postalInfo');

        foreach ($postalInfoAllNodes as $node) {
            $typeAttr = (string) $node['type'];

            if ($typeAttr !== 'int' && $typeAttr !== 'loc') {
                sendEppError($conn, $db, 2003, 'Invalid postalInfo type attribute: must be "int" or "loc"', $clTRID, $trans);
                return;
            }
        }

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
                sendEppError($conn, $db, 2003, 'Missing contact:name', $clTRID, $trans);
                return;
            }

            if (
                preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntName) ||
                !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntName) ||
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
                    !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet1) ||
                    strlen($postalInfoIntStreet1) > 255
                ) {
                    sendEppError($conn, $db, 2005, 'Invalid contact:street', $clTRID, $trans);
                    return;
                }
            }

            if ($postalInfoIntStreet2) {
                if (
                    preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet2) ||
                    !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet2) ||
                    strlen($postalInfoIntStreet2) > 255
                ) {
                    sendEppError($conn, $db, 2005, 'Invalid contact:street', $clTRID, $trans);
                    return;
                }
            }

            if ($postalInfoIntStreet3) {
                if (
                    preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet3) ||
                    !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet3) ||
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

        $contactUpdate = $xml->command->update->children('urn:ietf:params:xml:ns:contact-1.0')->update;

        $voice = (string) $contactUpdate->chg->voice;
        $voice_x = '';
        if ($voice && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $voice) || strlen($voice) > 17)) {
            sendEppError($conn, $db, 2005, 'Voice must be (\+[0-9]{1,3}\.[0-9]{1,14})', $clTRID, $trans);
            return;
        }

        $fax = (string) $contactUpdate->chg->fax;
        $fax_x = '';
        if ($contactUpdate->fax) {
            $fax_x = (string) $contactUpdate->chg->fax->attributes()->x;
        }
        if ($fax && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $fax) || strlen($fax) > 17)) {
            sendEppError($conn, $db, 2005, 'Fax must be (\+[0-9]{1,3}\.[0-9]{1,14})', $clTRID, $trans);
            return;
        }

        $email = (string) $contactUpdate->chg->email;
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendEppError($conn, $db, 2005, 'Email address failed check', $clTRID, $trans);
            return;
        }

        $authInfo_pw = (string) $contactUpdate->chg->authInfo->pw;
        if ($authInfo_pw) {
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
        }

        $contact_disclose = $xml->xpath('//contact:disclose');
        $disclose = [
            'voice' => 1,
            'fax' => 1,
            'email' => 1,
            'name_int' => 1,
            'name_loc' => 1,
            'org_int' => 1,
            'org_loc' => 1,
            'addr_int' => 1,
            'addr_loc' => 1,
        ];

        foreach ($xml->xpath('//contact:disclose') as $node_disclose) {
            $flag = (string)$node_disclose['flag'];

            if ($node_disclose->xpath('//contact:voice')) {
                $disclose['voice'] = $flag;
            }
            if ($node_disclose->xpath('//contact:fax')) {
                $disclose['fax'] = $flag;
            }
            if ($node_disclose->xpath('//contact:email')) {
                $disclose['email'] = $flag;
            }
            if ($node_disclose->xpath('//contact:name[@type="int"]')) {
                $disclose['name_int'] = $flag;
            }
            if ($node_disclose->xpath('//contact:name[@type="loc"]')) {
                $disclose['name_loc'] = $flag;
            }
            if ($node_disclose->xpath('//contact:org[@type="int"]')) {
                $disclose['org_int'] = $flag;
            }
            if ($node_disclose->xpath('//contact:org[@type="loc"]')) {
                $disclose['org_loc'] = $flag;
            }
            if ($node_disclose->xpath('//contact:addr[@type="int"]')) {
                $disclose['addr_int'] = $flag;
            }
            if ($node_disclose->xpath('//contact:addr[@type="loc"]')) {
                $disclose['addr_loc'] = $flag;
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
            sendEppError($conn, $db, 2005, 'NIN should contain one or more numbers', $clTRID, $trans);
            return;
        }

        if (!in_array($nin_type, ['personal', 'business'])) {
            sendEppError($conn, $db, 2005, 'NIN Type should contain personal or business', $clTRID, $trans);
            return;
        }
    }

    if (!empty($contactRem)) {
        $status_list = $xml->xpath('//contact:rem/contact:status/@s');

        foreach ($status_list as $node) {
            $status = (string)$node;

            $sth = $db->prepare("SELECT id FROM contact_status WHERE contact_id = ? AND status = ?");
            $sth->execute([$contact_id, $status]);
            $exists = $sth->fetchColumn();
            $sth->closeCursor();

            if (!$exists) {
                sendEppError($conn, $db, 2303, 'Cannot remove status "' . $status . '" because it does not exist on the contact', $clTRID, $trans);
                return;
            }

            $sth = $db->prepare("DELETE FROM contact_status WHERE contact_id = ? AND status = ?");
            $sth->execute([$contact_id, $status]);
        }

        $sth = $db->prepare("SELECT COUNT(*) FROM contact_status WHERE contact_id = ?");
        $sth->execute([$contact_id]);
        $remaining = $sth->fetchColumn();

        if ((int)$remaining === 0) {
            $sth = $db->prepare("INSERT INTO contact_status (contact_id, status) VALUES (?, 'ok')");
            $sth->execute([$contact_id]);
        }
    }

    if (!empty($contactAdd)) {
        $status_list = $xml->xpath('//contact:add/contact:status/@s');

        foreach ($status_list as $node) {
            $status = (string)$node;
            $sth = $db->prepare("INSERT INTO contact_status (contact_id, status) VALUES (?, ?)");
            $sth->execute([$contact_id, $status]);
        }

        $sth = $db->prepare("DELETE FROM contact_status WHERE contact_id = ? AND status = 'ok'");
        $sth->execute([$contact_id]);
    }

    if ($contactChg) {
        $stmt = $db->prepare("SELECT voice, voice_x, fax, fax_x, email, clid, crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate, disclose_voice, disclose_fax, disclose_email FROM contact WHERE id = :contact_id LIMIT 1");
        $stmt->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        extract($row);

        if ($postalInfoInt) {
            // For contact_postalInfo table with type = 'int'
            $stmt_int = $db->prepare("SELECT name,org,street1,street2,street3,city,sp,pc,cc,disclose_name_int,disclose_org_int,disclose_addr_int FROM contact_postalInfo WHERE contact_id = :contact_id AND type = 'int' LIMIT 1");
            $stmt_int->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
            $stmt_int->execute();
            $row_int = $stmt_int->fetch(PDO::FETCH_ASSOC);
            $stmt_int->closeCursor();
            extract($row_int);
        }

        if ($postalInfoLoc) {
            // For contact_postalInfo table with type = 'loc'
            $stmt_loc = $db->prepare("SELECT name,org,street1,street2,street3,city,sp,pc,cc,disclose_name_loc,disclose_org_loc,disclose_addr_loc FROM contact_postalInfo WHERE contact_id = :contact_id AND type = 'loc' LIMIT 1");
            $stmt_loc->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
            $stmt_loc->execute();
            $row_loc = $stmt_loc->fetch(PDO::FETCH_ASSOC);
            $stmt_loc->closeCursor();
            extract($row_loc);
        }

        // For contact_authInfo table with authtype = 'pw'
        $stmt_pw = $db->prepare("SELECT authinfo FROM contact_authInfo WHERE contact_id = :contact_id AND authtype = 'pw' LIMIT 1");
        $stmt_pw->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
        $stmt_pw->execute();
        $e_authInfo_pw = $stmt_pw->fetchColumn();
        $stmt_pw->closeCursor();

        // For contact_authInfo table with authtype = 'ext'
        $stmt_ext = $db->prepare("SELECT authinfo FROM contact_authInfo WHERE contact_id = :contact_id AND authtype = 'ext' LIMIT 1");
        $stmt_ext->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
        $stmt_ext->execute();
        $e_authInfo_ext = $stmt_ext->fetchColumn();
        $stmt_ext->closeCursor();

        $postalInfoAllNodes = $xml->xpath('//contact:postalInfo');

        foreach ($postalInfoAllNodes as $node) {
            $typeAttr = (string) $node['type'];

            if ($typeAttr !== 'int' && $typeAttr !== 'loc') {
                sendEppError($conn, $db, 2003, 'Invalid postalInfo type attribute: must be "int" or "loc"', $clTRID, $trans);
                return;
            }
        }

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

        if (!empty($e_voice) || !empty($e_voice_x) || !empty($e_fax) || !empty($e_fax_x) || !empty($e_email)) {
            // Update contact
            $query = "UPDATE contact SET voice = ?, voice_x = ?, fax = ?, fax_x = ?, email = ?, upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?";
            $stmt = $db->prepare($query);

            $stmt->execute([
                $e_voice ?: null,
                $e_voice_x ?: null,
                $e_fax ?: null,
                $e_fax_x ?: null,
                $e_email,
                $clid,
                $contact_id
            ]);
        }
        
        if (isset($disclose['voice']) || isset($disclose['fax']) || isset($disclose['email'])) {
            // Update contact
            $query = "UPDATE contact SET disclose_voice = ?, disclose_fax = ?, disclose_email = ?, upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?";
            $stmt = $db->prepare($query);

            $stmt->execute([
                isset($disclose['voice']) ? $disclose['voice'] : $disclose_voice,  // Check if $disclose['voice'] is set, otherwise use $disclose_voice
                isset($disclose['fax']) ? $disclose['fax'] : $disclose_fax,        // Same logic for fax
                isset($disclose['email']) ? $disclose['email'] : $disclose_email,  // Same logic for email
                $clid,
                $contact_id
            ]);
        }

        if ($postalInfoInt) {
            // Update contact_postalInfo for 'int'
            $query = "UPDATE contact_postalInfo SET name = ?, org = ?, street1 = ?, street2 = ?, street3 = ?, city = ?, sp = ?, pc = ?, cc = ? WHERE contact_id = ? AND type = ?";
            $stmt = $db->prepare($query);
            
            // Use the disclose array if set, otherwise fall back to the extracted values
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
            $query = "UPDATE contact_postalInfo SET name = ?, org = ?, street1 = ?, street2 = ?, street3 = ?, city = ?, sp = ?, pc = ?, cc = ? WHERE contact_id = ? AND type = ?";
            $stmt = $db->prepare($query);

            // Use the disclose array if set, otherwise fall back to the extracted values
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

        if (isset($disclose['name_int']) || isset($disclose['org_int']) || isset($disclose['addr_int'])) {
            $query = "UPDATE contact_postalInfo SET disclose_name_int = ?, disclose_org_int = ?, disclose_addr_int = ? WHERE contact_id = ? AND type = ?";
            $stmt = $db->prepare($query);
                
            // Use the disclose array if set, otherwise fall back to the extracted values
            $stmt->execute([
                isset($disclose['name_int']) ? $disclose['name_int'] : $disclose_name_int,  // Use disclose array if set, otherwise use the extracted value
                isset($disclose['org_int']) ? $disclose['org_int'] : $disclose_org_int,     // Same logic for org
                isset($disclose['addr_int']) ? $disclose['addr_int'] : $disclose_addr_int,  // Same logic for address
                $contact_id,
                'int'
            ]);
        }

        if (isset($disclose['name_loc']) || isset($disclose['org_loc']) || isset($disclose['addr_loc'])) {
            $query = "UPDATE contact_postalInfo SET disclose_name_loc = ?, disclose_org_loc = ?, disclose_addr_loc = ? WHERE contact_id = ? AND type = ?";
            $stmt = $db->prepare($query);
               
            // Use the disclose array if set, otherwise fall back to the extracted values
            $stmt->execute([
                isset($disclose['name_loc']) ? $disclose['name_loc'] : $disclose_name_loc,  // Use disclose array if set, otherwise use the extracted value
                isset($disclose['org_loc']) ? $disclose['org_loc'] : $disclose_org_loc,     // Same logic for org
                isset($disclose['addr_loc']) ? $disclose['addr_loc'] : $disclose_addr_loc,  // Same logic for address
                $contact_id,
                'int'
            ]);
        }

        // Update contact_authInfo for 'pw'
        if (!empty($e_authInfo_pw)) {
            $query = "UPDATE contact_authInfo SET authinfo = ? WHERE contact_id = ? AND authtype = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([
                $e_authInfo_pw,
                $contact_id,
                'pw'
            ]);
        }

        // Update contact_authInfo for 'ext'
        if (!empty($e_authInfo_ext)) {
            $stmt = $db->prepare($query); // Same query as above, can reuse
            $stmt->execute([
                $e_authInfo_ext,
                $contact_id,
                'ext'
            ]);
        }

    }

    if ($identica_update) {
        $query = "UPDATE contact SET nin = ?, nin_type = ?, upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?";
        $stmt = $db->prepare($query);

        if (!$stmt) {
            sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
            return;
        }

        $result = $stmt->execute([
            $nin ?: null,
            $nin_type ?: null,
            $clid,
            $contact_id
        ]);

        if (!$result) {
            sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
            return;
        }
    }

    $svTRID = generateSvTRID();
    $response = [
        'command' => 'update_contact',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'clTRID' => $clTRID,
        'svTRID' => $svTRID,
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'update', 'contact', $contact_id, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}

function processHostUpdate($conn, $db, $xml, $clid, $database_type, $trans) {
    $name = (string) $xml->command->update->children('urn:ietf:params:xml:ns:host-1.0')->update->name;
    $clTRID = (string) $xml->command->clTRID;
    
    $hostRem = $xml->xpath('//host:rem')[0] ?? null;
    $hostAdd = $xml->xpath('//host:add')[0] ?? null;
    $hostChg = $xml->xpath('//host:chg')[0] ?? null;

    $extension = 0;
    
    if ($hostRem === null && $hostAdd === null && $hostChg === null) {
        sendEppError($conn, $db, 2003, 'At least one host:rem || host:add || host:chg MUST be provided', $clTRID, $trans);
        return;
    }

    if (!$name) {
        sendEppError($conn, $db, 2003, 'The host being updated is not indicated', $clTRID, $trans);
        return;
    }

    $name = strtoupper($name);

    $stmt = $db->prepare("SELECT id, clid FROM host WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $hostId = $row['id'] ?? null;
    $registrarIdHost = $row['clid'] ?? null;

    if (!$hostId) {
        sendEppError($conn, $db, 2303, 'Host does not exist in registry', $clTRID, $trans);
        return;
    }
    
    $clid = getClid($db, $clid);
    if ($clid !== $registrarIdHost) {
        sendEppError($conn, $db, 2201, 'Not registrar for host', $clTRID, $trans);
        return;
    }

    $stmtStatus = $db->prepare("SELECT status FROM host_status WHERE host_id = ?");
    $stmtStatus->execute([$hostId]);

    while ($rowStatus = $stmtStatus->fetch(PDO::FETCH_ASSOC)) {
        $status = $rowStatus['status'];
        if (preg_match('/(serverUpdateProhibited)$/', $status) || preg_match('/^pending/', $status)) {
            sendEppError($conn, $db, 2304, 'It has a serverUpdateProhibited or pendingUpdate status that does not allow modification', $clTRID, $trans);
            return;
        }
    }
    $stmtStatus->closeCursor();

    $clientUpdateProhibited = 0;
    $stmtClientUpdateProhibited = $db->prepare("SELECT id FROM host_status WHERE host_id = ? AND status = 'clientUpdateProhibited' LIMIT 1");
    $stmtClientUpdateProhibited->execute([$hostId]);

    $clientUpdateProhibited = $stmtClientUpdateProhibited->fetchColumn();
    $stmtClientUpdateProhibited->closeCursor();

    if (isset($hostRem)) {
        $addrList = $xml->xpath('//host:rem/host:addr');
        $statusList = $xml->xpath('//host:rem/host:status/@s');

        if (count($addrList) == 0 && count($statusList) == 0) {
            sendEppError($conn, $db, 2005, 'At least one element MUST be present', $clTRID, $trans);
            return;
        }

        foreach ($statusList as $node) {
            $status = (string)$node;
            if ($status === 'clientUpdateProhibited') {
                $clientUpdateProhibited = 0;
            }
            if (!in_array($status, ['clientDeleteProhibited', 'clientUpdateProhibited'])) {
                sendEppError($conn, $db, 2005, 'Only these statuses clientDeleteProhibited|clientUpdateProhibited are accepted', $clTRID, $trans);
                return;
            }
        }
    }

    if ($clientUpdateProhibited) {
        sendEppError($conn, $db, 2304, 'It has clientUpdateProhibited status, but you did not indicate this status when deleting', $clTRID, $trans);
        return;
    }

    if (isset($hostAdd)) {
        $addr_list = $xml->xpath('//host:add/host:addr');
        $status_list = $xml->xpath('//host:add/host:status/@s');

        if (count($addr_list) == 0 && count($status_list) == 0) {
            sendEppError($conn, $db, 2005, 'At least one element MUST be present', $clTRID, $trans);
            return;
        }

        foreach ($status_list as $node) {
            $status = (string) $node;
            if (!preg_match('/^(clientDeleteProhibited|clientUpdateProhibited)$/', $status)) {
                sendEppError($conn, $db, 2005, 'Only these statuses clientDeleteProhibited|clientUpdateProhibited are accepted', $clTRID, $trans);
                return;
            }

            if (count($xml->xpath('//host:add/host:status[@s="' . $status . '"]')) == 0) {
                $stmt = $db->prepare("SELECT id FROM host_status WHERE host_id = ? AND status = ? LIMIT 1");
                $stmt->execute([$hostId, $status]);
                $contact_status_id = $stmt->fetchColumn();
                $stmt->closeCursor();
                if ($contact_status_id) {
                    sendEppError($conn, $db, 2306, 'This status '.$status.' already exists for this host', $clTRID, $trans);
                    return;
                }
            }
        }

        foreach ($addr_list as $node) {
            $addr = (string) $node;
            $addr_type = (string) ($node->attributes()->ip ?? 'v4');
            if (!in_array($addr_type, ['v4', 'v6'])) {
                sendEppError($conn, $db, 2005, 'host:addr ip attribute must be "v4" or "v6"', $clTRID, $trans);
                return;
            }

            if ($addr_type == 'v6') {
                // IPv6 validation and normalization
                if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE)) {
                    $addr = normalize_v6_address($addr);
                    $stmt = $db->prepare("SELECT id FROM host_addr WHERE host_id = ? AND addr = ? AND ip = '6' LIMIT 1");
                    $stmt->execute([$hostId, $addr]);
                    $ipv6_addr_already_exists = $stmt->fetchColumn();
                    $stmt->closeCursor();
                    if ($ipv6_addr_already_exists) {
                        sendEppError($conn, $db, 2306, 'This addr '.$addr.' already exists for this host', $clTRID, $trans);
                        return;
                    }
                } else {
                    // Invalid IPv6
                    sendEppError($conn, $db, 2005, 'Invalid host:addr v6', $clTRID, $trans);
                    return;
                }
            } else {
                // IPv4 validation and normalization
                if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE)) {
                    $addr = normalize_v4_address($addr);
                    $stmt = $db->prepare("SELECT id FROM host_addr WHERE host_id = ? AND addr = ? AND ip = '4' LIMIT 1");
                    $stmt->execute([$hostId, $addr]);
                    $ipv4_addr_already_exists = $stmt->fetchColumn();
                    $stmt->closeCursor();
                    if ($ipv4_addr_already_exists) {
                        sendEppError($conn, $db, 2306, 'This addr '.$addr.' already exists for this host', $clTRID, $trans);
                        return;
                    }
                    if ($addr == '127.0.0.1') {
                        sendEppError($conn, $db, 2005, 'Invalid host:addr v4', $clTRID, $trans);
                        return;
                    }
                } else {
                    // Invalid IPv4
                    sendEppError($conn, $db, 2005, 'Invalid host:addr v4', $clTRID, $trans);
                    return;
                }
            }
        }
    }

    if (isset($hostChg)) {
        $chg_name = $xml->xpath('//host:name[1]')[0];

        if (!validateHostName($chg_name)) {
            sendEppError($conn, $db, 2005, 'Invalid host:name', $clTRID, $trans);
            return;
        }

        $stmt = $db->prepare("SELECT domain_id FROM host WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $domain_id = $stmt->fetchColumn();
        $stmt->closeCursor();

        if ($domain_id) {
            $stmt = $db->prepare("SELECT name FROM domain WHERE id = ? LIMIT 1");
            $stmt->execute([$domain_id]);
            $domain_name = $stmt->fetchColumn();
            $stmt->closeCursor();

            if (!preg_match('/\.' . preg_quote($domain_name, '/') . '$/i', $chg_name)) {
                sendEppError($conn, $db, 2005, 'Out-of-bailiwick change not allowed: host name must be a subdomain of '.$domain_name, $clTRID, $trans);
                return;
            }
        } else {
            $tlds = $db->query("SELECT tld FROM domain_tld")->fetchAll(PDO::FETCH_COLUMN);
            $internal_host = false;
            foreach ($tlds as $tld) {
                if (str_ends_with(strtolower($chg_name), strtolower($tld))) {
                    $internal_host = true;
                    break;
                }
            }

            if ($internal_host) {
                sendEppError($conn, $db, 2005, 'Out-of-bailiwick change not allowed: host must be external to registry-managed domains', $clTRID, $trans);
                return;
            }
        }

        // Check if new host name already exists
        $stmt = $db->prepare("SELECT id FROM host WHERE name = ? LIMIT 1");
        $stmt->execute([$chg_name]);
        $chg_name_id = $stmt->fetchColumn();
        $stmt->closeCursor();

        if ($chg_name_id) {
            sendEppError($conn, $db, 2306, 'If it already exists, then we can\'t change it', $clTRID, $trans);
            return;
        }

        // Check if used as NS by other domains
        $stmt = $db->prepare("SELECT h.id FROM host AS h
            INNER JOIN domain_host_map AS dhm ON (dhm.host_id = h.id)
            INNER JOIN domain AS d ON (d.id = dhm.domain_id AND d.clid != h.clid)
            WHERE h.id = ? AND h.domain_id IS NULL
            LIMIT 1");
        $stmt->execute([$hostId]);
        $domain_host_map_id = $stmt->fetchColumn();
        $stmt->closeCursor();

        if ($domain_host_map_id) {
            sendEppError($conn, $db, 2305, 'It is not possible to modify because it is a dependency, it is used by some domain as NS', $clTRID, $trans);
            return;
        }
    }

    if (isset($hostRem)) {
        $rem_name = $xml->xpath('//host:name[1]')[0];

        if (!validateHostName($rem_name)) {
            sendEppError($conn, $db, 2005, 'Invalid host:name', $clTRID, $trans);
            return;
        }

        $addr_list = $xml->xpath('//host:rem/host:addr');
        $status_list = $xml->xpath('//host:rem/host:status/@s');

        foreach ($addr_list as $node) {
            $addr = (string) $node;
            $addr_type = $node->attributes()['ip'] ? (string) $node->attributes()['ip'] : 'v4';

            $normalized_addr = $addr_type === 'v6' ? normalize_v6_address($addr) : normalize_v4_address($addr);

            // Check if this addr exists
            $stmt = $db->prepare("SELECT id FROM host_addr WHERE host_id = ? AND addr = ? AND ip = ?");
            $stmt->execute([$hostId, $normalized_addr, $addr_type]);
            $exists = $stmt->fetchColumn();
            $stmt->closeCursor();

            if (!$exists) {
                sendEppError($conn, $db, 2306, "host:addr $addr not found for host, cannot remove", $clTRID, $trans);
                return;
            }

            $stmt = $db->prepare("DELETE FROM host_addr WHERE host_id = ? AND addr = ? AND ip = ?");
            $stmt->execute([$hostId, $normalized_addr, $addr_type]);
        }

        foreach ($status_list as $node) {
            $status = (string) $node;
            
            $stmt = $db->prepare("DELETE FROM host_status WHERE host_id = ? AND status = ?");
            $stmt->execute([$hostId, $status]);
        }
    }

    if (isset($hostAdd)) {
        $add_name = $xml->xpath('//host:name[1]')[0];

        if (!validateHostName($add_name)) {
            sendEppError($conn, $db, 2005, 'Invalid host:name', $clTRID, $trans);
            return;
        }

        $addr_list = $xml->xpath('//host:add/host:addr');
        $status_list = $xml->xpath('//host:add/host:status/@s');

        foreach ($addr_list as $node) {
            $addr = (string) $node;
            $addr_type = $node->attributes()['ip'] ? (string) $node->attributes()['ip'] : 'v4';

            // Normalize
            if ($addr_type == 'v6') {
                $addr = normalize_v6_address($addr);
            } else {
                $addr = normalize_v4_address($addr);
            }

            try {
                $stmt = $db->prepare("INSERT INTO host_addr (host_id,addr,ip) VALUES(?,?,?)");
                $stmt->execute([$hostId, $addr, $addr_type]);
            } catch (PDOException $e) {
                if ($database_type === 'mysql' && $e->errorInfo[1] == 1062) {
                    // Duplicate entry error for MySQL. Silently ignore.
                } elseif ($database_type === 'pgsql' && $e->errorInfo[1] == 23505) {
                    // Duplicate entry error for PostgreSQL. Silently ignore.
                } else {
                    sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                    return;
                }
            }

        }

        foreach ($status_list as $node) {
            $status = (string) $node;

            $stmt = $db->prepare("INSERT INTO host_status (host_id,status) VALUES(?,?)");
            $stmt->execute([$hostId, $status]);
        }
    }

    if (isset($hostChg)) {
        $chg_name = strtoupper($xml->xpath('//host:name[1]')[0]);

        $query = "UPDATE host SET name = ?, upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE name = ?";

        $stmt = $db->prepare($query);
        $stmt->execute([$chg_name, $clid, $name]);
    }

    $svTRID = generateSvTRID();
    $response = [
        'command' => 'update_host',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'clTRID' => $clTRID,
        'svTRID' => $svTRID,
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'update', 'host', $hostId, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}

function processDomainUpdate($conn, $db, $xml, $clid, $database_type, $trans) {
    $domainName = (string) $xml->command->update->children('urn:ietf:params:xml:ns:domain-1.0')->update->name;
    $clTRID = (string) $xml->command->clTRID;

    $domainRem = $xml->xpath('//domain:rem')[0] ?? null;
    $domainAdd = $xml->xpath('//domain:add')[0] ?? null;
    $domainChg = $xml->xpath('//domain:chg')[0] ?? null;
    $extensionNode = $xml->command->extension;
    $launch_update = null;

    if (isset($extensionNode)) {
        $rgp_update = $xml->xpath('//rgp:update')[0] ?? null;
        $secdns_update = $xml->xpath('//secDNS:update')[0] ?? null;
        $launch_update = $xml->xpath('//launch:update')[0] ?? null;
        
        // Check if launch extension is enabled in database settings
        $stmt = $db->prepare("SELECT value FROM settings WHERE name = 'launch_phases' LIMIT 1");
        $stmt->execute();
        $launch_extension_enabled = $stmt->fetchColumn();
        $stmt->closeCursor();
    }

    if ($domainRem === null && $domainAdd === null && $domainChg === null && $extensionNode === null) {
        sendEppError($conn, $db, 2003, 'At least one domain:rem || domain:add || domain:chg', $clTRID, $trans);
        return;
    }

    if (!$domainName) {
        sendEppError($conn, $db, 2003, 'Domain name is not provided', $clTRID, $trans);
        return;
    }

    $stmt = $db->prepare("SELECT id,tldid,exdate,clid FROM domain WHERE name = ? LIMIT 1");
    $stmt->execute([$domainName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    if (!$row) {
        sendEppError($conn, $db, 2303, 'Domain name does not exist', $clTRID, $trans);
        return;
    }
    
    $clid = getClid($db, $clid);
    if ($clid !== $row['clid']) {
        sendEppError($conn, $db, 2201, 'You do not have privileges to modify a domain name that belongs to another registrar', $clTRID, $trans);
        return;
    }
    
    $domain_id = $row['id'];
    
    if ($launch_extension_enabled && isset($launch_update)) {
        $phase = (string) $launch_update->xpath('launch:phase')[0];
        $applicationID = (string) $launch_update->xpath('launch:applicationID')[0];

        if (!$phase || !$applicationID) {
            sendEppError($conn, $db, 2003, 'Launch phase or applicationID is missing', $clTRID, $trans);
            return;
        }

        // Validate the phase and application ID in the appropriate table
        if ($phase === 'sunrise') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM application WHERE id = ? AND phase_type = ? AND application_id = ?");
            $stmt->execute([$domain_id, $phase, $applicationID]);
        } elseif (in_array($phase, ['landrush', 'custom', 'claims'])) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM domain WHERE id = ? AND phase_name = ?");
            $stmt->execute([$domain_id, $phase]);
        } else {
            sendEppError($conn, $db, 2003, 'Unsupported phase name', $clTRID, $trans);
            return;
        }

        $launch_valid = $stmt->fetchColumn();
        $stmt->closeCursor();

        if (!$launch_valid) {
            sendEppError($conn, $db, 2304, 'Invalid launch phase or applicationID for this domain', $clTRID, $trans);
            return;
        }
    }

    $stmt = $db->prepare("SELECT status FROM domain_status WHERE domain_id = ?");
    $stmt->execute([$row['id']]);
    while ($status = $stmt->fetchColumn()) {
        if (strpos($status, 'serverUpdateProhibited') !== false || strpos($status, 'pendingTransfer') !== false) {
            sendEppError($conn, $db, 2304, 'It has a serverUpdateProhibited or pendingUpdate status that does not allow modification, first change the status and then update', $clTRID, $trans);
            return;
        }
    }
    $stmt->closeCursor();

    $clientUpdateProhibited = 0;
    $stmt = $db->prepare("SELECT id FROM domain_status WHERE domain_id = ? AND status = 'clientUpdateProhibited' LIMIT 1");
    $stmt->execute([$row['id']]);
    $clientUpdateProhibited = $stmt->fetchColumn();
    $stmt->closeCursor();

    if (isset($domainRem)) {
        $ns = $xml->xpath('//domain:rem/domain:ns') ?? [];
        $contact_list = $xml->xpath('//domain:rem/domain:contact') ?? [];
        $statusList = $xml->xpath('//domain:rem/domain:status/@s') ?? [];

        if (!$ns && count($contact_list) == 0 && count($statusList) == 0 && !$extensionNode) {
            sendEppError($conn, $db, 2005, 'At least one element MUST be present', $clTRID, $trans);
            return;
        }

        foreach ($statusList as $status) {
            $status = (string)$status;
            if ($status === 'clientUpdateProhibited') {
                $clientUpdateProhibited = 0;
            }
            if (!in_array($status, ['clientDeleteProhibited', 'clientHold', 'clientRenewProhibited', 'clientTransferProhibited', 'clientUpdateProhibited'])) {
                sendEppError($conn, $db, 2005, 'Only these clientDeleteProhibited|clientHold|clientRenewProhibited|clientTransferProhibited|clientUpdateProhibited statuses are accepted', $clTRID, $trans);
                return;
            }
        }
    }

    if ($clientUpdateProhibited) {
        sendEppError($conn, $db, 2304, 'It has clientUpdateProhibited status, but you did not indicate this status when deleting', $clTRID, $trans);
        return;
    }

    $domainAddNodes = $xml->xpath('//domain:add');

    if (!empty($domainAddNodes)) {
        $domainAddNode = $domainAddNodes[0];
    } else {
        $domainAddNode = null;
    }

    if ($domainAddNode !== null) {
        $ns = $xml->xpath('//domain:add/domain:ns');
        $hostObjList = $xml->xpath('//domain:add/domain:hostObj');
        $hostAttrList = $xml->xpath('//domain:add/domain:hostAttr');
        $contact_list = $xml->xpath('//domain:add/domain:contact');
        $statusList = $xml->xpath('//domain:add/domain:status/@s');

        if (!$ns && !count($contact_list) && !count($statusList) && !count($hostObjList) && !count($hostAttrList) && !$extensionNode) {
            sendEppError($conn, $db, 2005, 'At least one element MUST be present', $clTRID, $trans);
            return;
        }

        foreach ($statusList as $node) {
            $status = (string) $node;

            if (!preg_match('/^(clientDeleteProhibited|clientHold|clientRenewProhibited|clientTransferProhibited|clientUpdateProhibited)$/', $status)) {
                sendEppError($conn, $db, 2005, 'Only these clientDeleteProhibited|clientHold|clientRenewProhibited|clientTransferProhibited|clientUpdateProhibited statuses are accepted', $clTRID, $trans);
                return;
            }

            $matchingNodes = $xml->xpath('domain:status[@s="' . $status . '"]');
            
            if (!$matchingNodes || count($matchingNodes) == 0) {
                $stmt = $db->prepare("SELECT id FROM domain_status WHERE domain_id = ? AND status = ? LIMIT 1");
                $stmt->execute([$row['id'], $status]);
                $domainStatusId = $stmt->fetchColumn();
                $stmt->closeCursor();

                if ($domainStatusId) {
                    sendEppError($conn, $db, 2306, 'This status '.$status.' already exists for this domain', $clTRID, $trans);
                    return;
                }
            }
        }

        if (count($hostObjList) > 0 && count($hostAttrList) > 0) {
            sendEppError($conn, $db, 2001, 'It cannot be hostObj and hostAttr at the same time, either one or the other', $clTRID, $trans);
            return;
        }

        if (count($hostObjList) > 13) {
            sendEppError($conn, $db, 2306, 'No more than 13 domain:hostObj are allowed', $clTRID, $trans);
            return;
        }

        if (count($hostAttrList) > 13) {
            sendEppError($conn, $db, 2306, 'No more than 13 domain:hostObj are allowed', $clTRID, $trans);
            return;
        }
    }

    $hostObjList = $xml->xpath('domain:ns/domain:hostObj');
    $hostAttrList = $xml->xpath('domain:ns/domain:hostAttr/domain:hostName');

    // Compare for duplicates in hostObj list
    if (count($hostObjList) > 0) {
        $nsArr = [];
        foreach ($hostObjList as $node) {
            $hostObj = (string)$node;
            if (isset($nsArr[$hostObj])) {
                sendEppError($conn, $db, 2306, "Duplicate NAMESERVER ($hostObj)", $clTRID, $trans);
                return;
            }
            $nsArr[$hostObj] = 1;
        }
    }

    // Compare for duplicates in hostAttr list
    if (count($hostAttrList) > 0) {
        $nsArr = [];
        foreach ($hostAttrList as $node) {
            $hostName = (string)$node;
            if (isset($nsArr[$hostName])) {
                sendEppError($conn, $db, 2306, "Duplicate NAMESERVER ($hostName)", $clTRID, $trans);
                return;
            }
            $nsArr[$hostName] = 1;
        }
    }

    // More validation for hostObj
    if (count($hostObjList) > 0) {
        foreach ($hostObjList as $node) {
            $hostObj = strtoupper((string)$node);
            $stmt = $db->prepare("SELECT id FROM host WHERE name = ? LIMIT 1");
            $stmt->execute([$hostObj]);
            $hostExists = $stmt->fetchColumn();
            $stmt->closeCursor();

            if (!$hostExists) {
                sendEppError($conn, $db, 2303, "domain:hostObj $hostObj does not exist", $clTRID, $trans);
                return;
            }

            if (preg_match('/[^A-Z0-9\.\-]/', $hostObj) || preg_match('/^-|^\.-|-\.-|^-|-$/', $hostObj)) {
                sendEppError($conn, $db, 2005, 'Invalid domain:hostObj', $clTRID, $trans);
                return;
            }

            // Additional checks related to domain TLDs and existing records
            if (validateHostName($hostObj)) {
                $tlds = $db->query("SELECT tld FROM domain_tld")->fetchAll(PDO::FETCH_COLUMN);
                $internal_host = false;
                foreach ($tlds as $tld) {
                    if (str_ends_with(strtolower($hostObj), strtolower($tld))) {
                        $internal_host = true;
                        break;
                    }
                }

                if ($internal_host) {
                    if (preg_match("/\.$domainName$/i", $hostObj)) {
                        $superordinate_domain = 1;
                    } else {
                        $stmt = $db->prepare("SELECT id FROM host WHERE name = :hostObj LIMIT 1");
                        $stmt->bindParam(':hostObj', $hostObj);
                        $stmt->execute();
                        $host_id_already_exist = $stmt->fetchColumn();
                        $stmt->closeCursor();
                        if (!$host_id_already_exist) {
                            sendEppError($conn, $db, 2303, 'Invalid domain:hostObj '.$hostObj, $clTRID, $trans);
                            return;
                        }
                    }
                }
            } else {
                sendEppError($conn, $db, 2005, 'Invalid domain:hostObj', $clTRID, $trans);
                return;
            }
        }
    }

    if (count($hostAttrList) > 0) {
        foreach ($hostAttrList as $node) {
            $hostName = (string)$node->xpath('domain:hostName[1]')[0];
            $hostName = strtoupper($hostName);
            if (preg_match('/[^A-Z0-9\.\-]/', $hostName) || preg_match('/^-|^\.-|-\.-|\.\.-|$-|\.$/', $hostName)) {
                sendEppError($conn, $db, 2005, 'Invalid domain:hostName', $clTRID, $trans);
                return;
            }

            if (strpos($hostName, $domainName) !== false) {
                $hostAddrList = $node->xpath('domain:hostAddr');
                if (count($hostAddrList) > 13) {
                    sendEppError($conn, $db, 2306, 'No more than 13 domain:hostObj are allowed', $clTRID, $trans);
                    return;
                }

                $nsArr = [];
                foreach ($hostAddrList as $addrNode) {
                    $hostAddr = (string)$addrNode;
                    if (isset($nsArr[$hostAddr])) {
                        sendEppError($conn, $db, 2306, "Duplicate IP ($hostAddr)", $clTRID, $trans);
                        return;
                    }
                    $nsArr[$hostAddr] = 1;
                }

                foreach ($hostAddrList as $addrNode) {
                    $hostAddr = (string)$addrNode;
                    $addrType = $addrNode->attributes()['ip'] ?? 'v4';
                    $addrType = (string)$addrType;

                    if ($addrType === 'v6') {
                        if (!filter_var($hostAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE)) {
                            sendEppError($conn, $db, 2005, 'Invalid domain:hostAddr v6', $clTRID, $trans);
                            return;
                        }
                    } else {
                        if (!filter_var($hostAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE) || $hostAddr == '127.0.0.1') {
                            sendEppError($conn, $db, 2005, 'Invalid domain:hostAddr v4', $clTRID, $trans);
                            return;
                        }
                    }
                }
            } else {
                sendEppError($conn, $db, 2005, "Invalid domain:hostName $hostName", $clTRID, $trans);
                return;
            }
        }
    }

    if (isset($contact_list)) {
        foreach ($contact_list as $node) {
            $contact = (string)$node;
            $contactAttributes = $node->attributes();
            $contact_type = (string)$contactAttributes['type'];
            $contact = strtoupper($contact);
            
            $stmt = $db->prepare("SELECT id FROM contact WHERE identifier = ? LIMIT 1");
            $stmt->execute([$contact]);
            $contact_id = $stmt->fetchColumn();
            $stmt->closeCursor();
            
            if (!$contact_id) {
                sendEppError($conn, $db, 2303, 'This contact '.$contact.' does not exist', $clTRID, $trans);
                return;
            }
            
            $stmt2 = $db->prepare("SELECT id FROM domain_contact_map WHERE domain_id = ? AND contact_id = ? AND type = ? LIMIT 1");
            $stmt2->execute([$row['id'], $contact_id, $contact_type]);
            $domain_contact_map_id = $stmt2->fetchColumn();
            $stmt2->closeCursor();
            
            if ($domain_contact_map_id) {
                sendEppError($conn, $db, 2306, 'This contact '.$contact.' already exists for type '.$contact_type, $clTRID, $trans);
                return;
            }
        }
    }

    if (isset($domainChg)) {
        $registrantNodes = $domainChg->xpath('domain:registrant[1]');
        if (!empty($registrantNodes)) {
            $registrant = strtoupper((string)$domainChg->xpath('domain:registrant[1]')[0]);
        }
        
        if (isset($registrant)) {
            $stmt3 = $db->prepare("SELECT id FROM contact WHERE identifier = ? LIMIT 1");
            $stmt3->execute([$registrant]);
            $registrant_id = $stmt3->fetchColumn();
            $stmt3->closeCursor();
            
            if (!$registrant_id) {
                sendEppError($conn, $db, 2303, 'Registrant does not exist', $clTRID, $trans);
                return;
            }
        }

        $stmt4 = $db->prepare("SELECT status FROM domain_status WHERE domain_id = ?");
        $stmt4->execute([$row['id']]);
        while ($status = $stmt4->fetchColumn()) {
            if (preg_match('/.*(serverUpdateProhibited)$/', $status) || preg_match('/^pendingTransfer/', $status)) {
                sendEppError($conn, $db, 2304, 'It has a status that does not allow modification, first change the status then update', $clTRID, $trans);
                return;
            }
        }
        $stmt4->closeCursor();

        $authInfo_pw_elements = $domainChg->xpath('//domain:authInfo/domain:pw[1]');
        if (!empty($authInfo_pw_elements)) {
            $authInfo_pw = (string)$authInfo_pw_elements[0];

            if ($authInfo_pw) {
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
            }
        }
    }

    if (isset($rgp_update)) {
        $op_attribute = (string) $xml->xpath('//rgp:restore/@op[1]')[0];

        if ($op_attribute === 'request') {
            $stmt = $db->prepare("SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = 'redemptionPeriod' AND id = :domain_id LIMIT 1");
            $stmt->bindParam(':domain_id', $domain_id, PDO::PARAM_INT);
            $stmt->execute();
            $temp_id_rgpstatus = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($temp_id_rgpstatus == 0) {
                sendEppError($conn, $db, 2304, 'pendingRestore can only be done if the domain is now in redemptionPeriod rgpStatus', $clTRID, $trans);
                return;
            }

            $stmt = $db->prepare("SELECT COUNT(id) AS ids FROM domain_status WHERE status = 'pendingDelete' AND domain_id = :domain_id LIMIT 1");
            $stmt->bindParam(':domain_id', $domain_id, PDO::PARAM_INT);
            $stmt->execute();
            $temp_id_status = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($temp_id_status == 0) {
                sendEppError($conn, $db, 2304, 'pendingRestore can only be done if the domain is now in pendingDelete status', $clTRID, $trans);
                return;
            }
        } elseif ($op_attribute === 'report') {
            $stmt = $db->prepare("SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = 'pendingRestore' AND id = :domain_id LIMIT 1");
            $stmt->bindParam(':domain_id', $domain_id, PDO::PARAM_INT);
            $stmt->execute();
            $temp_id = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($temp_id == 0) {
                sendEppError($conn, $db, 2304, 'report can only be sent if the domain is in pendingRestore status', $clTRID, $trans);
                return;
            }
        }
    }

    if (isset($domainRem)) {
        $nsNodes = $xml->xpath('//domain:rem/domain:ns');
        $ns = !empty($nsNodes) ? $nsNodes[0] : null;
        $contact_list = $xml->xpath('//domain:rem/domain:contact'); 
        $status_list = $xml->xpath('//domain:rem/domain:status/@s');

        $hostObj_list = $xml->xpath('//domain:rem//domain:hostObj');
        $hostAttr_list = $xml->xpath('//domain:rem//domain:hostAttr');

        foreach ($hostObj_list as $node) {
            $hostObj = (string) $node;

            $stmt = $db->prepare("SELECT id FROM host WHERE name = :hostObj LIMIT 1");
            $stmt->bindParam(':hostObj', $hostObj, PDO::PARAM_STR);
            $stmt->execute();
            $host_id = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($host_id) {
                $stmt = $db->prepare("DELETE FROM domain_host_map WHERE domain_id = :domain_id AND host_id = :host_id");
                $stmt->bindParam(':domain_id', $domain_id, PDO::PARAM_INT);
                $stmt->bindParam(':host_id', $host_id, PDO::PARAM_INT);
                $stmt->execute();
                
                $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                if (!$sth->execute([$clid, $domain_id])) {
                    sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                    return;
                }
            } else {
                sendEppError($conn, $db, 2303, "hostObj $hostObj does not exist", $clTRID, $trans);
                return;
            }
        }

        foreach ($hostAttr_list as $node) {
            $hostNameNodes = $node->xpath('domain:hostName[1]');
    
            if ($hostNameNodes && isset($hostNameNodes[0])) {
                $hostName = (string) $hostNameNodes[0];

                $stmt = $db->prepare("SELECT id FROM host WHERE name = :hostName LIMIT 1");
                $stmt->bindParam(':hostName', $hostName, PDO::PARAM_STR);
                $stmt->execute();
                $host_id = $stmt->fetchColumn();
                $stmt->closeCursor();

                if ($host_id) {
                    $stmt = $db->prepare("DELETE FROM domain_host_map WHERE domain_id = :domain_id AND host_id = :host_id");
                    $stmt->bindParam(':domain_id', $domain_id, PDO::PARAM_INT);
                    $stmt->bindParam(':host_id', $host_id, PDO::PARAM_INT);
                    $stmt->execute();

                    $stmt = $db->prepare("DELETE FROM host_addr WHERE host_id = :host_id");
                    $stmt->bindParam(':host_id', $host_id, PDO::PARAM_INT);
                    $stmt->execute();

                    $stmt = $db->prepare("DELETE FROM host WHERE id = :host_id");
                    $stmt->bindParam(':host_id', $host_id, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                    if (!$sth->execute([$clid, $domain_id])) {
                        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                        return;
                    }
                }
            }
        }

        foreach ($contact_list as $node) {
            $contact = (string) $node;
            $contact_type = (string) $node->attributes()->type;

            $stmt = $db->prepare("SELECT id FROM contact WHERE identifier = :contact LIMIT 1");
            $stmt->bindParam(':contact', $contact, PDO::PARAM_STR);
            $stmt->execute();
            $contact_id = $stmt->fetchColumn();
            $stmt->closeCursor();

            if ($contact_id) {
                $stmt = $db->prepare("DELETE FROM domain_contact_map WHERE domain_id = :domain_id AND contact_id = :contact_id AND type = :contact_type");
                $stmt->bindParam(':domain_id', $domain_id, PDO::PARAM_INT);
                $stmt->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
                $stmt->bindParam(':contact_type', $contact_type, PDO::PARAM_STR);
                $stmt->execute();
                
                $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                if (!$sth->execute([$clid, $domain_id])) {
                    sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                    return;
                }
            }
        }

        foreach ($status_list as $node) {
            $status = (string) $node;

            $stmt = $db->prepare("SELECT 1 FROM domain_status WHERE domain_id = :domain_id AND status = :status LIMIT 1");
            $stmt->execute([
                ':domain_id' => $domain_id,
                ':status' => $status
            ]);
            $exists = $stmt->fetchColumn();
            $stmt->closeCursor();

            if (!$exists) {
                sendEppError($conn, $db, 2303, "Cannot remove status '$status': not present on domain", $clTRID, $trans);
                return;
            }

            $stmt = $db->prepare("DELETE FROM domain_status WHERE domain_id = :domain_id AND status = :status");
            $stmt->bindParam(':domain_id', $domain_id, PDO::PARAM_INT);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->execute();
            
            $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
            if (!$sth->execute([$clid, $domain_id])) {
                sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                return;
            }
        }
    }
    
    if (isset($domainAdd)) {
        $ns = $xml->xpath('//domain:add/domain:ns')[0] ?? null;
        $hostObj_list = $xml->xpath('//domain:add//domain:hostObj');
        $hostAttr_list = $xml->xpath('//domain:add//domain:hostAttr');
        $contact_list = $xml->xpath('//domain:add//domain:contact');
        $status_list = $xml->xpath('//domain:add//domain:status/@s');

        foreach ($hostObj_list as $node) {
            $hostObj = (string) $node;
            
            // Check if hostObj exists in the database
            $stmt = $db->prepare("SELECT id FROM host WHERE name = :hostObj LIMIT 1");
            $stmt->bindParam(':hostObj', $hostObj, PDO::PARAM_STR);
            $stmt->execute();
            $hostObj_already_exist = $stmt->fetchColumn();
            $stmt->closeCursor();
            
            if ($hostObj_already_exist) {
                $stmt = $db->prepare("SELECT domain_id FROM domain_host_map WHERE domain_id = :domain_id AND host_id = :hostObj_already_exist LIMIT 1");
                $stmt->bindParam(':domain_id', $domain_id, PDO::PARAM_INT);
                $stmt->bindParam(':hostObj_already_exist', $hostObj_already_exist, PDO::PARAM_INT);
                $stmt->execute();
                $domain_host_map_id = $stmt->fetchColumn();
                $stmt->closeCursor();

                if (!$domain_host_map_id) {
                    $stmt = $db->prepare("INSERT INTO domain_host_map (domain_id,host_id) VALUES(:domain_id, :hostObj_already_exist)");
                    $stmt->bindParam(':domain_id', $domain_id, PDO::PARAM_INT);
                    $stmt->bindParam(':hostObj_already_exist', $hostObj_already_exist, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                    if (!$sth->execute([$clid, $domain_id])) {
                        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                        return;
                    }
                } else {
                    $logMessage = "Domain: $domainName; hostObj: $hostObj - is duplicated";
                    $contextData = json_encode([
                        'registrar_id' => $clid,
                        'domain' => $domainName,
                        'host' => $hostObj
                    ]);
                    $stmt = $db->prepare("INSERT INTO error_log 
                        (channel, level, level_name, message, context, extra, created_at) 
                        VALUES ('epp', 300, 'WARNING', ?, ?, '{}', CURRENT_TIMESTAMP)");
                    $stmt->execute([$logMessage, $contextData]);
                }
            } else {
                $tlds = $db->query("SELECT tld FROM domain_tld")->fetchAll(PDO::FETCH_COLUMN);
                $internal_host = false;
                foreach ($tlds as $tld) {
                    if (str_ends_with(strtolower($hostObj), strtolower($tld))) {
                        $internal_host = true;
                        break;
                    }
                }

                if ($internal_host) {
                    if (preg_match("/\.$domainName$/i", $hostObj)) {
                        $sth = $db->prepare("INSERT INTO host (name,domain_id,clid,crid,crdate) VALUES(?, ?, ?, ?, CURRENT_TIMESTAMP(3))");
                        if (!$sth->execute([$hostObj, $domain_id, $clid, $clid])) {
                            sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                            return;
                        }
                        $host_id = $db->lastInsertId();
                        
                        $sth = $db->prepare("INSERT INTO domain_host_map (domain_id,host_id) VALUES(?, ?)");
                        if (!$sth->execute([$domain_id, $host_id])) {
                            sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                            return;
                        }
                        
                        $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                        if (!$sth->execute([$clid, $domain_id])) {
                            sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                            return;
                        }
                    }
                } else {
                    $sth = $db->prepare("INSERT INTO host (name,clid,crid,crdate) VALUES(?, ?, ?, CURRENT_TIMESTAMP(3))");
                    if (!$sth->execute([$hostObj, $clid, $clid])) {
                        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                        return;
                    }
                    $host_id = $db->lastInsertId();
                    
                    $sth = $db->prepare("INSERT INTO domain_host_map (domain_id,host_id) VALUES(?, ?)");
                    if (!$sth->execute([$domain_id, $host_id])) {
                        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                        return;
                    }
                    
                    $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                    if (!$sth->execute([$clid, $domain_id])) {
                        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                        return;
                    }
                }
            }
    }

        foreach ($hostAttr_list as $node) {
            $hostNames = $node->xpath('domain:hostName[1]');
            $hostName = isset($hostNames[0]) ? (string)$hostNames[0] : null;
    
            if ($hostName) {
                $stmt = $db->prepare("SELECT id FROM host WHERE name = :hostName LIMIT 1");
                $stmt->bindParam(':hostName', $hostName, PDO::PARAM_STR);
                $stmt->execute();
                $hostName_already_exist = $stmt->fetchColumn();
                $stmt->closeCursor();
                
                if ($hostName_already_exist) {
                    $sth = $db->prepare("SELECT domain_id FROM domain_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1");
                    $sth->execute([$domain_id, $hostName_already_exist]);
                    $domain_host_map_id = $sth->fetchColumn();
                    $sth->closeCursor();

                    if (!$domain_host_map_id) {
                        $sth = $db->prepare("INSERT INTO domain_host_map (domain_id,host_id) VALUES(?, ?)");
                        if (!$sth->execute([$domain_id, $hostName_already_exist])) {
                            sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                            return;
                        }
                        
                        $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                        if (!$sth->execute([$clid, $domain_id])) {
                            sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                            return;
                        }
                    } else {
                        $logMessage = "Domain: $domainName; hostName: $hostName - is duplicated";
                        $contextData = json_encode([
                            'registrar_id' => $clid,
                            'domain' => $domainName,
                            'host' => $hostName
                        ]);
                        $sth = $db->prepare("INSERT INTO error_log 
                            (channel, level, level_name, message, context, extra, created_at) 
                            VALUES ('epp', 3, 'warning', ?, ?, '{}', CURRENT_TIMESTAMP)");
                        if (!$sth->execute([$logMessage, $contextData])) {
                            sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                            return;
                        }
                    }
                } else {
                    // Insert into the host table
                    $sth = $db->prepare("INSERT INTO host (name,domain_id,clid,crid,crdate) VALUES(?, ?, ?, ?, CURRENT_TIMESTAMP(3))");
                    $sth->execute([$hostName, $domain_id, $clid, $clid]) or die($sth->errorInfo()[2]);
                    
                    $host_id = $db->lastInsertId();

                    // Insert into the domain_host_map table
                    $sth = $db->prepare("INSERT INTO domain_host_map (domain_id,host_id) VALUES(?, ?)");
                    $sth->execute([$domain_id, $host_id]) or die($sth->errorInfo()[2]);

                    // Iterate over the hostAddr_list
                    $hostAddr_list = $node->xpath('domain:hostAddr');
                    foreach ($hostAddr_list as $node) {
                        $hostAddr = (string)$node;
                        $addr_type = isset($node['ip']) ? (string)$node['ip'] : 'v4';

                        // Normalize
                        if ($addr_type == 'v6') {
                            $hostAddr = _normalise_v6_address($hostAddr); // PHP function to normalize IPv6
                        } else {
                            $hostAddr = _normalise_v4_address($hostAddr); // PHP function to normalize IPv4
                        }

                        // Insert into the host_addr table
                        $sth = $db->prepare("INSERT INTO host_addr (host_id,addr,ip) VALUES(?, ?, ?)");
                        $sth->execute([$host_id, $hostAddr, $addr_type]) or die($sth->errorInfo()[2]);
                    }
                   
                    $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                    if (!$sth->execute([$clid, $domain_id])) {
                        sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                        return;
                    }
                }
            }
        }

        foreach ($contact_list as $node) {
            $contact = (string) $node;
            $contact_type = (string) $node->attributes()->type;
    
            $stmt = $db->prepare("SELECT id FROM contact WHERE identifier = :contact LIMIT 1");
            $stmt->bindParam(':contact', $contact, PDO::PARAM_STR);
            $stmt->execute();
            $contact_id = $stmt->fetchColumn();
            $stmt->closeCursor();

            try {
                $stmt = $db->prepare("INSERT INTO domain_contact_map (domain_id,contact_id,type) VALUES(:domain_id, :contact_id, :contact_type)");
                $stmt->bindParam(':domain_id', $domain_id, PDO::PARAM_INT);
                $stmt->bindParam(':contact_id', $contact_id, PDO::PARAM_INT);
                $stmt->bindParam(':contact_type', $contact_type, PDO::PARAM_STR);
                $stmt->execute();
            } catch (PDOException $e) {
            $codesToIgnore = ['23000', '23505'];
                if (!in_array($e->getCode(), $codesToIgnore)) {
                    sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                    return;
                }
            }
            
            $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
            if (!$sth->execute([$clid, $domain_id])) {
                sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                return;
            }
        }

        foreach ($status_list as $node) {
            $status = (string) $node;

            try {
                $stmt = $db->prepare("INSERT INTO domain_status (domain_id,status) VALUES(:domain_id, :status)");
                $stmt->bindParam(':domain_id', $domain_id, PDO::PARAM_INT);
                $stmt->bindParam(':status', $status, PDO::PARAM_STR);
                $stmt->execute();
            } catch (PDOException $e) {
                $codesToIgnore = ['23000', '23505'];
                if (!in_array($e->getCode(), $codesToIgnore)) {
                    sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                    return;
                }
            }
            
            $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
            if (!$sth->execute([$clid, $domain_id])) {
                sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                return;
            }
        }
    }

    if (isset($domainChg)) {
        $registrant_nodes = $xml->xpath('//domain:registrant');
        
        if (count($registrant_nodes) > 0) {
            $registrant = strtoupper((string)$registrant_nodes[0]);
            
            if ($registrant) {
                $sth = $db->prepare("SELECT id FROM contact WHERE identifier = ? LIMIT 1");
                $sth->execute([$registrant]);
                $registrant_id = $sth->fetchColumn();
                $sth->closeCursor();
                
                $sth = $db->prepare("UPDATE domain SET registrant = ?, upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                if (!$sth->execute([$registrant_id, $clid, $domain_id])) {
                    sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                    return;
                }
            } else {
                $sth = $db->prepare("UPDATE domain SET registrant = NULL, upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                if (!$sth->execute([$clid, $domain_id])) {
                    sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                    return;
                }
            }
        }
        
        $authInfoNodes = $xml->xpath('//domain:authInfo');
        $authInfo_pw = (!empty($authInfoNodes)) ? (string)$xml->xpath('//domain:pw[1]')[0] : null;

        if ($authInfo_pw) {
            $sth = $db->prepare("UPDATE domain_authInfo SET authinfo = ? WHERE domain_id = ? AND authtype = ?");
            if (!$sth->execute([$authInfo_pw, $domain_id, 'pw'])) {
                sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                return;
            }
            
            $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
            if (!$sth->execute([$clid, $domain_id])) {
                sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                return;
            }
        }

        $authInfoExtNodes = $xml->xpath('//domain:ext[1]');
        $authInfo_ext = (!empty($authInfoExtNodes)) ? (string)$authInfoExtNodes[0] : null;

        if (isset($authInfo_ext)) {
            $sth = $db->prepare("UPDATE domain_authInfo SET authinfo = ? WHERE domain_id = ? AND authtype = ?");
            if (!$sth->execute([$authInfo_ext, $domain_id, 'ext'])) {
                sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                return;
            }
            
            $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
            if (!$sth->execute([$clid, $domain_id])) {
                sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                return;
            }
        }

        $authInfoNullNodes = $xml->xpath('//domain:null[1]');
        $authInfo_null = (!empty($authInfoNullNodes)) ? (string)$authInfoNullNodes[0] : null;

        if (isset($authInfo_null)) {
            $sth = $db->prepare("DELETE FROM domain_authInfo WHERE domain_id = ?");
            if (!$sth->execute([$domain_id])) {
                sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                return;
            }
            
            $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
            if (!$sth->execute([$clid, $domain_id])) {
                sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                return;
            }
        }
    }

    if (isset($rgp_update)) {
        $op_attribute = (string) $xml->xpath('//rgp:restore/@op[1]')[0];

        if ($op_attribute == 'request') {
            $sth = $db->prepare("SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = 'redemptionPeriod' AND id = ?");
            $sth->execute([$domain_id]);
            $temp_id = $sth->fetchColumn();
            $sth->closeCursor();

            if ($temp_id == 1) {
                $sth = $db->prepare("UPDATE domain SET rgpstatus = 'pendingRestore', resTime = CURRENT_TIMESTAMP(3), upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                if (!$sth->execute([$clid, $domain_id])) {
                    sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                    return;
                }
            } else {
                sendEppError($conn, $db, 2304, 'pendingRestore can only be done if the domain is now in redemptionPeriod', $clTRID, $trans);
                return;
            }
        } elseif ($op_attribute == 'report') {
            $preData = (string) ($xml->xpath('//rgp:preData')[0] ?? null);
            $postData = (string) ($xml->xpath('//rgp:postData')[0] ?? null);
            $delTime = (string) ($xml->xpath('//rgp:delTime')[0] ?? null);
            $resTime = (string) ($xml->xpath('//rgp:resTime')[0] ?? null);
            $resReason = (string) ($xml->xpath('//rgp:resReason')[0] ?? null);
            $other = (string) ($xml->xpath('//rgp:other')[0] ?? null);

            $statements = $xml->xpath('//rgp:statement');
            if ($statements) {
                // If there are <rgp:statement> elements, process them
                $statementTexts = [];
                foreach ($statements as $statement) {
                    $statementTexts[] = (string) $statement;
                }
            } else {
                // If there are no <rgp:statement> elements, set default values
                $statementTexts = [null, null]; // Assuming you expect two statements
            }
            
            $sth = $db->prepare("SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = 'pendingRestore' AND id = ?");
            $sth->execute([$domain_id]);
            $temp_id = $sth->fetchColumn();
            $sth->closeCursor();

            if ($temp_id == 1) {
                $sth = $db->prepare("SELECT accountBalance,creditLimit,currency FROM registrar WHERE id = ?");
                $sth->execute([$clid]);
                list($registrar_balance, $creditLimit, $currency) = $sth->fetch();
                $sth->closeCursor();

                $returnValue = getDomainPrice($db, $domainName, $row['tldid'], 12, 'renew', $clid, $currency);
                $renew_price = $returnValue['price'];

                $restore_price = getDomainRestorePrice($db, $row['tldid'], $clid, $currency);

                if (($registrar_balance + $creditLimit) < ($renew_price + $restore_price)) {
                    sendEppError($conn, $db, 2104, 'There is no money on the account for restore and renew', $clTRID, $trans);
                    return;
                }

                $sth = $db->prepare("SELECT exdate FROM domain WHERE id = ?");
                $sth->execute([$domain_id]);
                $from = $sth->fetchColumn();
                $sth->closeCursor();

                $sth = $db->prepare("UPDATE domain SET exdate = DATE_ADD(exdate, INTERVAL 12 MONTH), rgpstatus = NULL, rgpresTime = CURRENT_TIMESTAMP(3), rgppostData = ?, rgpresReason = ?, rgpstatement1 = ?, rgpstatement2 = ?, upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                
                if (!$sth->execute([$postData, $resReason, $statementTexts[0], $statementTexts[1], $clid, $domain_id])) {
                    sendEppError($conn, $db, 2400, 'It was not renewed successfully, something is wrong', $clTRID, $trans);
                    return;
                } else {
                    $sth = $db->prepare("DELETE FROM domain_status WHERE domain_id = ? AND status = ?");
                    $sth->execute([$domain_id, 'pendingDelete']);

                    $db->prepare("UPDATE registrar SET accountBalance = (accountBalance - ? - ?) WHERE id = ?")
                    ->execute([$renew_price, $restore_price, $clid]);

                    $db->prepare("INSERT INTO payment_history (registrar_id,date,description,amount) VALUES(?,CURRENT_TIMESTAMP(3),'restore domain $domainName',?)")
                    ->execute([$clid, -$restore_price]);
            
                    $db->prepare("INSERT INTO payment_history (registrar_id,date,description,amount) VALUES(?,CURRENT_TIMESTAMP(3),'renew domain $domainName for period 12 MONTH',?)")
                    ->execute([$clid, -$renew_price]);

                    $stmt = $db->prepare("SELECT exdate FROM domain WHERE id = ?");
                    $stmt->execute([$domain_id]);
                    $to = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    $sth = $db->prepare("INSERT INTO statement (registrar_id,date,command,domain_name,length_in_months,fromS,toS,amount) VALUES(?,CURRENT_TIMESTAMP(3),?,?,?,?,?,?)");
                    $sth->execute([$clid, 'restore', $domainName, 0, $from, $from, $restore_price]);
        
                    $sth->execute([$clid, 'renew', $domainName, 12, $from, $to, $renew_price]);

                    $stmt = $db->prepare("SELECT id FROM statistics WHERE date = CURDATE()");
                    $stmt->execute();
                    $curdate_id = $stmt->fetchColumn();
                    $stmt->closeCursor();

                    if (!$curdate_id) {
                        $db->prepare("INSERT IGNORE INTO statistics (date) VALUES(CURDATE())")
                        ->execute();
                    }
                    
                    $db->prepare("UPDATE statistics SET restored_domains = restored_domains + 1 WHERE date = CURDATE()")
                    ->execute();

                    $db->prepare("UPDATE statistics SET renewed_domains = renewed_domains + 1 WHERE date = CURDATE()")
                    ->execute();
                }
            } else {
                sendEppError($conn, $db, 2304, 'report can only be sent if the domain is in pendingRestore status', $clTRID, $trans);
                return;
            }
        }
    }

    if (isset($secdns_update)) {
        $secdnsRems = $xml->xpath('//secDNS:rem') ?? [];
        $secdnsAdds = $xml->xpath('//secDNS:add') ?? [];
        $secdnsChg = $xml->xpath('//secDNS:chg')[0] ?? null;
        
        if (isset($secdnsRems)) {
            foreach ($secdnsRems as $secdnsRem) {
                $dsDataToRemove = $secdnsRem->xpath('./secDNS:dsData');
                $keyDataToRemove = $secdnsRem->xpath('./secDNS:keyData');

                if ($dsDataToRemove) {
                    foreach ($dsDataToRemove as $ds) {
                        $keyTag = (int)$ds->xpath('secDNS:keyTag')[0];
                        $alg = (int)$ds->xpath('secDNS:alg')[0];
                        $digestType = (int)$ds->xpath('secDNS:digestType')[0];
                        $digest = (string)$ds->xpath('secDNS:digest')[0];

                        // Data sanity checks
                        // Validate keyTag
                        if (!isset($keyTag) || !is_int($keyTag)) {
                            sendEppError($conn, $db, 2005, 'Incomplete keyTag provided', $clTRID, $trans);
                            return;
                        }
                        if ($keyTag < 0 || $keyTag > 65535) {
                            sendEppError($conn, $db, 2006, 'Invalid keyTag provided', $clTRID, $trans);
                            return;
                        }

                        // Validate alg
                        $validAlgorithms = [8, 13, 14, 15, 16];
                        if (!isset($alg) || !in_array($alg, $validAlgorithms)) {
                            sendEppError($conn, $db, 2006, 'Invalid algorithm', $clTRID, $trans);
                            return;
                        }

                        // Validate digestType and digest
                        if (!isset($digestType) || !is_int($digestType)) {
                            sendEppError($conn, $db, 2005, 'Invalid digestType', $clTRID, $trans);
                            return;
                        }
                        $validDigests = [
                        2 => 64,  // SHA-256
                        4 => 96   // SHA-384
                        ];
                        if (!isset($validDigests[$digestType])) {
                            sendEppError($conn, $db, 2006, 'Unsupported digestType', $clTRID, $trans);
                            return;
                        }
                        if (!isset($digest) || strlen($digest) != $validDigests[$digestType] || !ctype_xdigit($digest)) {
                            sendEppError($conn, $db, 2006, 'Invalid digest length or format', $clTRID, $trans);
                            return;
                        }

                        try {
                            // Check if DS record exists before attempting to delete
                            $stmt = $db->prepare("SELECT COUNT(*) FROM secdns WHERE domain_id = :domain_id AND keytag = :keyTag AND alg = :alg AND digesttype = :digestType AND digest = :digest");
                            $stmt->execute([
                                ':domain_id' => $domain_id,
                                ':keyTag' => $keyTag,
                                ':alg' => $alg,
                                ':digestType' => $digestType,
                                ':digest' => $digest
                            ]);
                            if ($stmt->fetchColumn() == 0) {
                                sendEppError($conn, $db, 2306, 'DS record not found for removal', $clTRID, $trans);
                                return;
                            }

                            $stmt = $db->prepare("DELETE FROM secdns WHERE domain_id = :domain_id AND keytag = :keyTag AND alg = :alg AND digesttype = :digestType AND digest = :digest");
                            $stmt->execute([
                                ':domain_id' => $domain_id,
                                ':keyTag' => $keyTag,
                                ':alg' => $alg,
                                ':digestType' => $digestType,
                                ':digest' => $digest
                            ]);
                            
                            $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                            $sth->execute([$clid, $domain_id]);
                        } catch (PDOException $e) {
                            sendEppError($conn, $db, 2400, 'Database error during dsData removal', $clTRID, $trans);
                            return;
                        }
                    }
                }
                if ($keyDataToRemove) {
                    foreach ($keyDataToRemove as $keyData) {
                        $flags = (int) $keyData->xpath('secDNS:flags')[0];
                        $protocol = (int) $keyData->xpath('secDNS:protocol')[0];
                        $algKeyData = (int) $keyData->xpath('secDNS:alg')[0];
                        $pubKey = (string) $keyData->xpath('secDNS:pubKey')[0];

                        // Data sanity checks for keyData
                        // Validate flags
                        $validFlags = [256, 257];
                        if (!isset($flags) && !in_array($flags, $validFlags)) {
                            sendEppError($conn, $db, 2005, 'Invalid flags', $clTRID, $trans);
                            return;
                        }

                        // Validate protocol
                        if (!isset($protocol) && $protocol != 3) {
                            sendEppError($conn, $db, 2006, 'Invalid protocol', $clTRID, $trans);
                            return;
                        }

                        // Validate algKeyData
                        if (!isset($algKeyData)) {
                            sendEppError($conn, $db, 2005, 'Invalid algKeyData encoding', $clTRID, $trans);
                            return;
                        }

                        // Validate pubKey
                        if (!isset($pubKey) && base64_encode(base64_decode($pubKey, true)) !== $pubKey) {
                            sendEppError($conn, $db, 2005, 'Invalid pubKey encoding', $clTRID, $trans);
                            return;
                        }

                        // Check if keyData exists before attempting to delete
                        $stmt = $db->prepare("SELECT COUNT(*) FROM secdns WHERE domain_id = :domain_id AND flags = :flags AND protocol = :protocol AND keydata_alg = :algKeyData AND pubkey = :pubKey");
                        $stmt->execute([
                            ':domain_id' => $domain_id,
                            ':flags' => $flags,
                            ':protocol' => $protocol,
                            ':algKeyData' => $algKeyData,
                            ':pubKey' => $pubKey
                        ]);
                        if ($stmt->fetchColumn() == 0) {
                            sendEppError($conn, $db, 2306, 'KeyData not found for removal', $clTRID, $trans);
                            return;
                        }

                        try {
                            $stmt = $db->prepare("DELETE FROM secdns WHERE domain_id = :domain_id AND flags = :flags AND protocol = :protocol AND algKeyData = :algKeyData AND pubKey = :pubKey");
                            $stmt->execute([
                                ':domain_id' => $domain_id,
                                ':flags' => $flags,
                                ':protocol' => $protocol,
                                ':algKeyData' => $algKeyData,
                                ':pubKey' => $pubKey
                            ]);
                            
                            $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                            $sth->execute([$clid, $domain_id]);
                        } catch (PDOException $e) {
                            sendEppError($conn, $db, 2400, 'Database error during keyData removal', $clTRID, $trans);
                            return;
                        }
                    }
                }
            }
        }

        if (isset($secdnsAdds)) {
            foreach ($secdnsAdds as $secdnsAdd) {
                $secDNSDataSet = $secdnsAdd->xpath('./secDNS:dsData');
                $keyDataSet = $secdnsAdd->xpath('./secDNS:keyData');

                if ($secDNSDataSet) {
                    foreach ($secDNSDataSet as $secDNSData) {
                        // Extract dsData elements
                        $keyTagNode = $secDNSData->xpath('secDNS:keyTag')[0] ?? null;
                        if (!isset($keyTagNode) || trim((string)$keyTagNode) === '') {
                            sendEppError($conn, $db, 2005, 'Missing or empty keyTag', $clTRID, $trans);
                            return;
                        }
                        $keyTag = (int) $keyTagNode;

                        $algNode = $secDNSData->xpath('secDNS:alg')[0] ?? null;
                        if (!isset($algNode) || trim((string)$algNode) === '') {
                            sendEppError($conn, $db, 2005, 'Missing or empty algorithm', $clTRID, $trans);
                            return;
                        }
                        $alg = (int) $algNode;
                        $validAlgorithms = [8, 13, 14, 15, 16];
                        if (!in_array($alg, $validAlgorithms)) {
                            sendEppError($conn, $db, 2006, 'Invalid algorithm', $clTRID, $trans);
                            return;
                        }

                        $digestTypeNode = $secDNSData->xpath('secDNS:digestType')[0] ?? null;
                        if (!isset($digestTypeNode) || trim((string)$digestTypeNode) === '') {
                            sendEppError($conn, $db, 2005, 'Missing or empty digestType', $clTRID, $trans);
                            return;
                        }
                        $digestType = (int) $digestTypeNode;
                        $validDigests = [2 => 64, 4 => 96]; // SHA-256 and SHA-384
                        if (!array_key_exists($digestType, $validDigests)) {
                            sendEppError($conn, $db, 2006, 'Unsupported digestType', $clTRID, $trans);
                            return;
                        }

                        $digestNode = $secDNSData->xpath('secDNS:digest')[0] ?? null;
                        if (!isset($digestNode) || trim((string)$digestNode) === '') {
                            sendEppError($conn, $db, 2005, 'Missing or empty digest', $clTRID, $trans);
                            return;
                        }
                        $digest = (string) $digestNode;
                        if (strlen($digest) !== $validDigests[$digestType] || !ctype_xdigit($digest)) {
                            sendEppError($conn, $db, 2006, 'Invalid digest length or format', $clTRID, $trans);
                            return;
                        }

                        $maxSigLife = $secDNSData->xpath('secDNS:maxSigLife') ? (int) $secDNSData->xpath('secDNS:maxSigLife')[0] : null;

                        // Data sanity checks
                        // Validate keyTag
                        if (!isset($keyTag) || !is_int($keyTag)) {
                            sendEppError($conn, $db, 2005, 'Incomplete keyTag provided', $clTRID, $trans);
                            return;
                        }
                        if ($keyTag < 0 || $keyTag > 65535) {
                            sendEppError($conn, $db, 2006, 'Invalid keyTag provided', $clTRID, $trans);
                            return;
                        }

                        // Validate alg
                        $validAlgorithms = [8, 13, 14, 15, 16];
                        if (!isset($alg) || !in_array($alg, $validAlgorithms)) {
                            sendEppError($conn, $db, 2006, 'Invalid algorithm', $clTRID, $trans);
                            return;
                        }

                        // Validate digestType and digest
                        if (!isset($digestType) || !is_int($digestType)) {
                            sendEppError($conn, $db, 2005, 'Invalid digestType', $clTRID, $trans);
                            return;
                        }
                        $validDigests = [
                        2 => 64,  // SHA-256
                        4 => 96   // SHA-384
                        ];
                        if (!isset($validDigests[$digestType])) {
                            sendEppError($conn, $db, 2006, 'Unsupported digestType', $clTRID, $trans);
                            return;
                        }
                        if (!isset($digest) || strlen($digest) != $validDigests[$digestType] || !ctype_xdigit($digest)) {
                            sendEppError($conn, $db, 2006, 'Invalid digest length or format', $clTRID, $trans);
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
                            if (!isset($flags) && !in_array($flags, $validFlags)) {
                                sendEppError($conn, $db, 2005, 'Invalid flags', $clTRID, $trans);
                                return;
                            }

                            // Validate protocol
                            if (!isset($protocol) && $protocol != 3) {
                                sendEppError($conn, $db, 2006, 'Invalid protocol', $clTRID, $trans);
                                return;
                            }

                            // Validate algKeyData
                            if (!isset($algKeyData)) {
                                sendEppError($conn, $db, 2005, 'Invalid algKeyData encoding', $clTRID, $trans);
                                return;
                            }

                            // Validate pubKey
                            if (!isset($pubKey) && base64_encode(base64_decode($pubKey, true)) !== $pubKey) {
                                sendEppError($conn, $db, 2005, 'Invalid pubKey encoding', $clTRID, $trans);
                                return;
                            }
                        }

                        try {
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
                            
                            $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                            $sth->execute([$clid, $domain_id]);
                        } catch (PDOException $e) {
                            $isMySQLUniqueViolation = $e->getCode() === '23000' && strpos($e->getMessage(), '1062 Duplicate entry') !== false;
                            $isPostgreSQLUniqueViolation = $e->getCode() === '23505';
                            if ($isMySQLUniqueViolation || $isPostgreSQLUniqueViolation) {
                            // Do nothing
                            } else {
                                sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                                return;
                            }
                        }
                    }
                }
                if ($keyDataSet) {
                    foreach ($keyDataSet as $keyDataData) {
                        $flags = (int) $keyDataData->xpath('secDNS:flags')[0];
                        $protocol = (int) $keyDataData->xpath('secDNS:protocol')[0];
                        $algKeyData = (int) $keyDataData->xpath('secDNS:alg')[0];
                        $pubKey = (string) $keyDataData->xpath('secDNS:pubKey')[0];
                        $maxSigLife = $xml->xpath('//secDNS:maxSigLife') ? (int) $secDNSData->xpath('secDNS:maxSigLife')[0] : null;

                        // Data sanity checks for keyData
                        // Validate flags
                        $validFlags = [256, 257];
                        if (!isset($flags) && !in_array($flags, $validFlags)) {
                            sendEppError($conn, $db, 2005, 'Invalid flags', $clTRID, $trans);
                            return;
                        }

                        // Validate protocol
                        if (!isset($protocol) && $protocol != 3) {
                            sendEppError($conn, $db, 2006, 'Invalid protocol', $clTRID, $trans);
                            return;
                        }

                        // Validate algKeyData
                        if (!isset($algKeyData)) {
                            sendEppError($conn, $db, 2005, 'Invalid algKeyData encoding', $clTRID, $trans);
                            return;
                        }

                        // Validate pubKey
                        if (!isset($pubKey) && base64_encode(base64_decode($pubKey, true)) !== $pubKey) {
                            sendEppError($conn, $db, 2005, 'Invalid pubKey encoding', $clTRID, $trans);
                            return;
                        }
                        
                        $dsres = dnssec_key2ds($domainName.'.', $flags, $protocol, $algKeyData, $pubKey);

                        try {
                            $stmt = $db->prepare("INSERT INTO secdns (domain_id, maxsiglife, interface, keytag, alg, digesttype, digest, flags, protocol, keydata_alg, pubkey) VALUES (:domain_id, :maxsiglife, :interface, :keytag, :alg, :digesttype, :digest, :flags, :protocol, :keydata_alg, :pubkey)");

                            $stmt->execute([
                                ':domain_id' => $domain_id,
                                ':maxsiglife' => $maxSigLife,
                                ':interface' => 'dsData',
                                ':keytag' => $dsres['keytag'],
                                ':alg' => $dsres['algorithm'],
                                ':digesttype' => $dsres['digest'][1]['type'],
                                ':digest' => $dsres['digest'][1]['hash'],
                                ':flags' => $flags ?? null,
                                ':protocol' => $protocol ?? null,
                                ':keydata_alg' => $algKeyData ?? null,
                                ':pubkey' => $pubKey ?? null
                            ]);
                            
                            $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                            $sth->execute([$clid, $domain_id]);
                        } catch (PDOException $e) {
                            $isMySQLUniqueViolation = $e->getCode() === '23000' && strpos($e->getMessage(), '1062 Duplicate entry') !== false;
                            $isPostgreSQLUniqueViolation = $e->getCode() === '23505';
                            if ($isMySQLUniqueViolation || $isPostgreSQLUniqueViolation) {
                            // Do nothing
                            } else {
                                sendEppError($conn, $db, 2400, 'Database error', $clTRID, $trans);
                                return;
                            }
                        }
                    }
                }
            }
        }
        
        if (isset($secdnsChg)) {
            $maxSigLifeElement = $secdnsChg->xpath('secDNS:maxSigLife');
            
            if ($maxSigLifeElement && isset($maxSigLifeElement[0])) {
                $maxSigLife = (int)$maxSigLifeElement[0];

                try {
                    $stmt = $db->prepare("UPDATE secdns SET maxSigLife = :maxSigLife WHERE domain_id = :domain_id");
                    $stmt->execute([
                        ':maxSigLife' => $maxSigLife,
                        ':domain_id' => $domain_id
                    ]);
                    
                    $sth = $db->prepare("UPDATE domain SET upid = ?, lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?");
                    $sth->execute([$clid, $domain_id]);
                } catch (PDOException $e) {
                    sendEppError($conn, $db, 2400, 'Database error during maxSigLife update', $clTRID, $trans);
                    return;
                }
            } else {
                sendEppError($conn, $db, 2005, 'Invalid or missing maxSigLife', $clTRID, $trans);
                return;
            }
        }
    }

    $svTRID = generateSvTRID();
    $response = [
        'command' => 'update_domain',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'clTRID' => $clTRID,
        'svTRID' => $svTRID,
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'update', 'domain', $domainName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);

}