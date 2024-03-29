<?php

function processPoll($conn, $db, $xml, $clid, $trans) {
    $clTRID = (string) $xml->command->clTRID;
    $node = $xml->command->poll;
    $op = (string) $node['op'];

    $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    $clid = $stmt->fetch(PDO::FETCH_ASSOC);
    $clid = $clid['id'];

    if ($op === 'ack') {
        $id = (string)$node['msgID'];
        $stmt = $db->prepare("SELECT id FROM poll WHERE registrar_id = :registrar_id AND id = :id LIMIT 1");
        $stmt->execute([':registrar_id' => $clid, ':id' => $id]);
        $ack_id = $stmt->fetchColumn();

        if (!$ack_id) {
            $response['resultCode'] = 2303; // Object does not exist
        } else {
            $stmt = $db->prepare("DELETE FROM poll WHERE registrar_id = :registrar_id AND id = :id");
            $stmt->execute([':registrar_id' => $clid, ':id' => $id]);
            $response['resultCode'] = 1300;
        }
        
        $stmt = $db->prepare("SELECT id, qdate, msg, msg_type, obj_name_or_id, obj_trStatus, obj_reID, obj_reDate, obj_acID, obj_acDate, obj_exDate, registrarName, creditLimit, creditThreshold, creditThresholdType, availableCredit FROM poll WHERE registrar_id = :registrar_id ORDER BY id DESC LIMIT 1");
        $stmt->execute([':registrar_id' => $clid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = $result['id'] ?? null;
        
        if (isset($id) && is_numeric($id) && $id >= 1) {
            $stmt = $db->prepare("SELECT id, qdate, msg, msg_type, obj_name_or_id, obj_trStatus, obj_reID, obj_reDate, obj_acID, obj_acDate, obj_exDate, registrarName, creditLimit, creditThreshold, creditThresholdType, availableCredit FROM poll WHERE registrar_id = :registrar_id ORDER BY id DESC LIMIT 1");
            $stmt->execute([':registrar_id' => $clid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $id = $result['id'] ?? null;
            $response['resultCode'] = $id ? 1301 : 1300;
        } else {
            $stmt = $db->prepare("SELECT COUNT(id) AS counter FROM poll WHERE registrar_id = :registrar_id");
            $stmt->execute([':registrar_id' => $clid]);
            $counter = $stmt->fetchColumn();
            $response['count'] = $counter;
            $response['id'] = $id;
            $response['command'] = 'poll';
            $response['clTRID'] = $clTRID;
            $response['svTRID'] = generateSvTRID();

            $epp = new EPP\EppWriter();
            $xml = $epp->epp_writer($response);
            updateTransaction($db, 'poll', null, null, $response['resultCode'], 'Command completed successfully', $response['svTRID'], $xml, $trans);
            sendEppResponse($conn, $xml);
            return;
        }    
    } else {
        $stmt = $db->prepare("SELECT id, qdate, msg, msg_type, obj_name_or_id, obj_trStatus, obj_reID, obj_reDate, obj_acID, obj_acDate, obj_exDate, registrarName, creditLimit, creditThreshold, creditThresholdType, availableCredit FROM poll WHERE registrar_id = :registrar_id ORDER BY id DESC LIMIT 1");
        $stmt->execute([':registrar_id' => $clid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $id = $result['id'] ?? null;
        $response['resultCode'] = $id ? 1301 : 1300;
    }
    
    if ((int) $response['resultCode'] === 1300) {
        $svTRID = generateSvTRID();
        $response = [
            'command' => 'poll',
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
            'resultCode' => $response['resultCode'],
            'msg' => 'Command completed successfully; no messages',
        ];
        
        $epp = new EPP\EppWriter();
        $xml = $epp->epp_writer($response);
        updateTransaction($db, 'poll', null, null, $response['resultCode'], 'Command completed successfully', $svTRID, $xml, $trans);
        sendEppResponse($conn, $xml);
        return;
    }

    $stmt = $db->prepare("SELECT COUNT(id) AS counter FROM poll WHERE registrar_id = :registrar_id");
    $stmt->execute([':registrar_id' => $clid]);
    $counter = $stmt->fetchColumn();

    $response = [];
    $response['command'] = 'poll';
    $response['count'] = $counter;
    $response['id'] = $id;
    $response['msg'] = $result['msg'] ?? null;
    $response['resultCode'] = 1301;
    $response['poll_msg_type'] = $result['msg_type'] ?? null;
    $response['lang'] = 'en-US';
    $qdate = str_replace(' ', 'T', $result['qdate'] ?? '') . 'Z';
    $response['qDate'] = $qdate;

    if ($response['poll_msg_type'] === 'lowBalance') {
        $response['registrarName'] = $result['registrarName'];
        $response['creditLimit'] = $result['creditLimit'];
        $response['creditThreshold'] = $result['creditThreshold'];
        $response['creditThresholdType'] = $result['creditThresholdType'];
        $response['availableCredit'] = $result['availableCredit'];
    } elseif ($response['poll_msg_type'] === 'domainTransfer') {
        $response['name'] = $result['obj_name_or_id'];
        $response['obj_trStatus'] = $result['obj_trStatus'];
        $response['obj_reID'] = $result['obj_reID'];
        $response['obj_reDate'] = str_replace(' ', 'T', $result['obj_reDate']) . 'Z';
        $response['obj_acID'] = $result['obj_acID'];
        $response['obj_acDate'] = str_replace(' ', 'T', $result['obj_acDate']) . 'Z';
        if ($result['obj_exDate']) {
            $response['obj_exDate'] = str_replace(' ', 'T', $result['obj_exDate']) . 'Z';
        }
        $response['obj_type'] = 'domain';
        $response['obj_id'] = $result['obj_name_or_id'];
    } elseif ($response['poll_msg_type'] === 'contactTransfer') {
        $response['identifier'] = $result['obj_name_or_id'];
        $response['obj_trStatus'] = $result['obj_trStatus'];
        $response['obj_reID'] = $result['obj_reID'];
        $response['obj_reDate'] = str_replace(' ', 'T', $result['obj_reDate']) . 'Z';
        $response['obj_acID'] = $result['obj_acID'];
        $response['obj_acDate'] = str_replace(' ', 'T', $result['obj_acDate']) . 'Z';
        $response['obj_type'] = 'contact';
        $response['obj_id'] = $result['obj_name_or_id'];
    }

    $response['clTRID'] = $clTRID;
    $response['svTRID'] = generateSvTRID();

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'poll', null, $response['id'], $response['resultCode'], 'Command completed successfully', $response['svTRID'], $xml, $trans);
    sendEppResponse($conn, $xml);
}