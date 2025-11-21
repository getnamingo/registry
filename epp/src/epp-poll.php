<?php

function processPoll($conn, $db, $xml, $clid, $trans) {
    $clTRID = (string) $xml->command->clTRID;
    $node = $xml->command->poll;

    $response = [];
    $result = null;
    $id = null;
    $next_msg_id = null;

    $opAttr = $node->attributes()->op ?? null;
    $op = $opAttr !== null ? (string) $opAttr : '';

    $clid = getClid($db, $clid);

    if ($op === '') {
        sendEppError($conn, $db, 2003, 'Missing poll op attribute', $clTRID, $trans);
        return;
    }
    if ($op !== 'ack' && $op !== 'req') {
        sendEppError($conn, $db, 2005, 'Invalid poll op attribute', $clTRID, $trans);
        return;
    }

    if ($op === 'ack') {
        $msgIdAttr = $node->attributes()->msgID ?? null;
        $id = $msgIdAttr !== null ? (string) $msgIdAttr : '';

        if ($id === '') {
            sendEppError($conn, $db, 2003, 'Missing poll msgID for ack', $clTRID, $trans);
            return;
        }

        $stmt = $db->prepare("SELECT id FROM poll WHERE registrar_id = :registrar_id AND id = :id LIMIT 1");
        $stmt->execute([':registrar_id' => $clid, ':id' => $id]);
        $ack_id = $stmt->fetchColumn();
        $stmt->closeCursor();

        if (!$ack_id) {
            sendEppError($conn, $db, 2303, 'Poll message does not exist', $clTRID, $trans);
            return;
        }

        $stmt = $db->prepare("DELETE FROM poll WHERE registrar_id = :registrar_id AND id = :id");
        $stmt->execute([':registrar_id' => $clid, ':id' => $id]);

        $stmt = $db->prepare("SELECT id FROM poll WHERE registrar_id = :registrar_id ORDER BY id ASC LIMIT 1");
        $stmt->execute([':registrar_id' => $clid]);
        $next_msg_id = $stmt->fetchColumn();
        $stmt->closeCursor();

        $response['resultCode'] = $next_msg_id ? 1000 : 1300;
    } else {
        // $op === 'req'
        $stmt = $db->prepare("SELECT id, qdate, msg, msg_type, obj_name_or_id, obj_trStatus, obj_reID, obj_reDate, obj_acID, obj_acDate, obj_exDate, registrarName, creditLimit, creditThreshold, creditThresholdType, availableCredit FROM poll WHERE registrar_id = :registrar_id ORDER BY id ASC LIMIT 1");
        $stmt->execute([':registrar_id' => $clid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $id = $result['id'] ?? null;
        $response['resultCode'] = $id ? 1301 : 1300;
    }

    $stmt = $db->prepare("SELECT COUNT(id) AS counter FROM poll WHERE registrar_id = :registrar_id");
    $stmt->execute([':registrar_id' => $clid]);
    $counter = $stmt->fetchColumn();
    $stmt->closeCursor();

    $response['command'] = 'poll';
    $response['count'] = $counter;
    if ($next_msg_id) {
        $response['id'] = $next_msg_id;
    } else {
        $response['id'] = $id;
    }
    $response['msg'] = $result['msg'] ?? null;
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