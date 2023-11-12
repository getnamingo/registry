<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$inputFile = fopen('Domains.csv', 'r');

// Skip the header row of the input file
$headers = fgetcsv($inputFile);

while (($row = fgetcsv($inputFile)) !== false) {
    $data = array_combine($headers, $row);
    
    // Inserting into the 'domain' table
    $tldId = getTldId($pdo, $data['name']);
    $createdOn = formatTimestamp($data['create_date']);
    $expiryDate = formatTimestamp($data['expiry_date']);
    $registrarId = $data['registrar_id'];

    $sql = "INSERT INTO domain (name, tldid, crdate, exdate, clid, crid) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['name'], $tldId, $createdOn, $expiryDate, $registrarId, $registrarId]);

    $domainId = $pdo->lastInsertId();

    // Inserting into the 'secdns' table
    $sql = "INSERT INTO secdns (domain_id, maxsiglife, interface, keytag, alg, digesttype, digest, flags, protocol, keydata_alg, pubkey) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    // Assuming default values for some fields as they are not provided in the CSV
    $maxSigLife = 604800;
    $interface = 'dsData';
    $alg = 5; // Default algorithm
    $digestType = 1; // Default digest type
    $flags = null;
    $protocol = null;
    $keydataAlg = null;
    $pubkey = null;

    // Extract DNSSEC data from the current row
    $keyTag = $data['key_tag'] ?? '';  // Using null coalescing operator for default value
    $digestValue = $data['digest_value'] ?? '';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$domainId, $maxSigLife, $interface, $keyTag, $alg, $digestType, $digestValue, $flags, $protocol, $keydataAlg, $pubkey]);
    
    // Inserting domain status into 'domain_status' table
    $status = $data['status'] ?? 'ok';
    $sql = "INSERT INTO domain_status (domain_id, status) VALUES (?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$domainId, $status]);
    
    // Inserting hosts into 'host' table
    for ($i = 1; $i <= 4; $i++) {
        if (isset($data["NameServer_$i"]) && !empty($data["NameServer_$i"])) {
            $hostName = $data["NameServer_$i"];
            $registrarId = $data['registrar_id'];
            $createdOn = formatTimestamp($data['create_date']);
            
            $sql = "INSERT INTO host (name, domain_id, clid, crid, crdate) VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$hostName, null, $registrarId, $registrarId, $createdOn]);
            
            $hostId = $pdo->lastInsertId();

            // Inserting 'ok' status into 'host_status' table
            $statusSql = "INSERT INTO host_status (host_id, status) VALUES (?, ?)";
            $statusStmt = $pdo->prepare($statusSql);
            $statusStmt->execute([$hostId, 'ok']);
        }
    }
    
    // Insert into domain_contact_map
    $contactTypes = ['admin' => 'admin_contact_id', 'billing' => 'billing_contact_id', 'tech' => 'tech_contact_id'];
    foreach ($contactTypes as $type => $field) {
        $contactIds = explode(',', $data[$field]);
        foreach ($contactIds as $roid) {
            $contactId = getContactIdByROID($pdo, trim($roid));
            if ($contactId !== null) {
                $contactSql = "INSERT INTO domain_contact_map (domain_id, contact_id, type) VALUES (?, ?, ?)";
                $contactStmt = $pdo->prepare($contactSql);
                $contactStmt->execute([$domainId, $contactId, $type]);
            }
        }
    }

    // Insert into domain_host_map
    for ($i = 1; $i <= 5; $i++) {
        if (!empty($data["NameServer_$i"])) {
            $hostId = getHostIdByName($pdo, $data["NameServer_$i"]);
            if ($hostId !== null) {
                $hostSql = "INSERT INTO domain_host_map (domain_id, host_id) VALUES (?, ?)";
                $hostStmt = $pdo->prepare($hostSql);
                $hostStmt->execute([$domainId, $hostId]);
            }
        }
    }
}

fclose($inputFile);

// Updating the 'host' table with domain_id
$hostsStmt = $pdo->query("SELECT id, name FROM host WHERE domain_id IS NULL");
while ($host = $hostsStmt->fetch()) {
    $domainName = strstr($host['name'], '.', true);
    $domainId = getDomainIdByName($pdo, $domainName);
    
    if ($domainId !== null) {
        $updateStmt = $pdo->prepare("UPDATE host SET domain_id = ? WHERE id = ?");
        $updateStmt->execute([$domainId, $host['id']]);
    }
}