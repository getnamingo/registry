<?php

function processContactDelete($conn, $db, $xml, $clid, $database_type) {
    $contactID = (string) $xml->command->delete->children('urn:ietf:params:xml:ns:contact-1.0')->delete->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    if (!$contactID) {
        sendEppError($conn, 2003, 'Required parameter missing');
        return;
    }

    $stmt = $db->prepare("SELECT id, clid FROM contact WHERE identifier = ? LIMIT 1");
    $stmt->execute([$contactID]);
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

    if ($clid !== $registrar_id_contact) {
        sendEppError($conn, 2201, 'Authorization error');
        return;
    }

    $stmt = $db->prepare("SELECT id FROM domain WHERE registrant = ? LIMIT 1");
    $stmt->execute([$contact_id]);
    $registrantExists = $stmt->fetchColumn();

    if ($registrantExists) {
        sendEppError($conn, 2305, 'Object association prohibits operation');
        return;
    }

    $stmt = $db->prepare("SELECT id FROM domain_contact_map WHERE contact_id = ? LIMIT 1");
    $stmt->execute([$contact_id]);
    $contactInUse = $stmt->fetchColumn();

    if ($contactInUse) {
        sendEppError($conn, 2305, 'Object association prohibits operation');
        return;
    }

    $stmt = $db->prepare("SELECT status FROM contact_status WHERE contact_id = ?");
    $stmt->execute([$contact_id]);

    while ($status = $stmt->fetchColumn()) {
        if (preg_match('/.*(UpdateProhibited|DeleteProhibited)$/', $status) || preg_match('/^pending/', $status)) {
        sendEppError($conn, 2304, 'Object status prohibits operation');
        return;
        }
    }

    // Delete associated records
    $db->prepare("DELETE FROM contact_postalInfo WHERE contact_id = ?")->execute([$contact_id]);
    $db->prepare("DELETE FROM contact_authInfo WHERE contact_id = ?")->execute([$contact_id]);
    $db->prepare("DELETE FROM contact_status WHERE contact_id = ?")->execute([$contact_id]);

    $stmt = $db->prepare("DELETE FROM contact WHERE id = ?");
    $stmt->execute([$contact_id]);

    if ($stmt->errorCode() != '00000') {
        sendEppError($conn, 2400, 'Command failed');
        return;
    }

    $response = [
        'command' => 'delete_contact',
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

function processHostDelete($conn, $db, $xml, $clid, $database_type) {
    $hostName = $xml->command->delete->children('urn:ietf:params:xml:ns:host-1.0')->delete->name;
    $clTRID = (string) $xml->command->clTRID;

    if (!$hostName) {
        sendEppError($conn, 2003, 'Required parameter missing');
        return;
    }

    $query = "SELECT id, clid FROM host WHERE name = :name LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([':name' => $hostName]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $host_id = $result['id'] ?? null;
    $registrar_id_host = $result['clid'] ?? null;

    if (!$host_id) {
        sendEppError($conn, 2303, 'Object does not exist');
        return;
    }
    
    $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    $clid = $stmt->fetch(PDO::FETCH_ASSOC);
    $clid = $clid['id'];

    if ($clid !== $registrar_id_host) {
        sendEppError($conn, 2201, 'Authorization error');
        return;
    }

    $query = "SELECT domain_id FROM domain_host_map WHERE host_id = :host_id LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([':host_id' => $host_id]);
    $nameserver_inuse = $stmt->fetchColumn();

    if ($nameserver_inuse) {
        sendEppError($conn, 2305, 'Object association prohibits operation');
        return;
    }

    $query = "DELETE FROM host_addr WHERE host_id = :host_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':host_id' => $host_id]);

    $query = "DELETE FROM host_status WHERE host_id = :host_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':host_id' => $host_id]);

    $query = "DELETE FROM host WHERE id = :host_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':host_id' => $host_id]);

    if ($stmt->errorCode() != '00000') {
        sendEppError($conn, 2400, 'Command failed');
        return;
    }

    $response = [
        'command' => 'delete_host',
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