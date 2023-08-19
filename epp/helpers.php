<?php

function checkLogin($db, $clID, $pw) {
    $stmt = $db->prepare("SELECT pw FROM registrar WHERE clid = :username");
    $stmt->execute(['username' => $clID]);
    $hashedPassword = $stmt->fetchColumn();

    return password_verify($pw, $hashedPassword);
}

function sendGreeting($conn) {
    global $c;
    $currentDate = gmdate('Y-m-d\TH:i:s\Z');

    $response = [
        'command' => 'greeting',
        'svID' => $c['epp_greeting'],
        'svDate' => $currentDate,
        'version' => '1.0',
        'lang' => 'en',
        'services' => [
            'urn:ietf:params:xml:ns:domain-1.0',
            'urn:ietf:params:xml:ns:contact-1.0',
            'urn:ietf:params:xml:ns:host-1.0'
        ],
        'extensions' => [
            'http://www.namingo.org/epp/nBalance-1.0',
            'http://www.namingo.org/epp/nIdent-1.0',
            'urn:ietf:params:xml:ns:secDNS-1.1',
            'urn:ietf:params:xml:ns:rgp-1.0',
            'urn:ietf:params:xml:ns:launch-1.0',
            'urn:ietf:params:xml:ns:idn-1.0',
            'urn:ietf:params:xml:ns:epp:fee-1.0',
            'urn:ar:params:xml:ns:price-1.1'
        ],
        'dcp' => [ // Data Collection Policy (optional)
            'access' => ['all'],
            'statement' => [
                'purpose' => ['admin', 'prov'],
                'recipient' => ['ours'],
                'retention' => ['stated']
            ]
        ]
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    sendEppResponse($conn, $xml);
}

function sendEppError($conn, $code, $msg) {
    $errorResponse = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <response>
    <result code="$code">
      <msg>$msg</msg>
    </result>
  </response>
</epp>
XML;
    sendEppResponse($conn, $errorResponse);
}

function sendEppResponse($conn, $response) {
    $length = strlen($response) + 4; // Total length including the 4-byte header
    $lengthData = pack('N', $length); // Pack the length into 4 bytes

    $conn->send($lengthData . $response);
}

function generateSvTRID($prefix = "Namingo") {
    // Get current timestamp
    $timestamp = time();

    // Generate a random 5-character alphanumeric string
    $randomString = bin2hex(random_bytes(5));

    // Combine the prefix, timestamp, and random string to form the svTRID
    $svTRID = "{$prefix}-{$timestamp}-{$randomString}";

    return $svTRID;
}

function getRegistrarClid(PDO $db, $id) {
    $stmt = $db->prepare("SELECT clid FROM registrar WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['clid'] ?? null;  // Return the clid if found, otherwise return null
}

function getContactIdentifier(PDO $db, $id) {
    $stmt = $db->prepare("SELECT identifier FROM contact WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['identifier'] ?? null;  // Return the identifier if found, otherwise return null
}

function getHost(PDO $db, $id) {
    $stmt = $db->prepare("SELECT name FROM host WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['name'] ?? null;  // Return the name if found, otherwise return null
}

function validate_identifier($identifier) {
    if (!$identifier) {
        return 'Abstract client and object identifier type minLength value=3';
    }

    if (strlen($identifier) < 3) {
        return 'Abstract client and object identifier type minLength value=3';
    }

    if (strlen($identifier) > 16) {
        return 'Abstract client and object identifier type maxLength value=16';
    }

    if (preg_match('/[^A-Z0-9\-]/', $identifier)) {
        return 'The ID of the contact must contain letters (A-Z) (ASCII) hyphen (-), and digits (0-9). Registry assigns each registrar a unique prefix with which that registrar must create contact IDs.';
    }
}

function validate_label($label, $pdo) {
    if (!$label) {
        return 'You must enter a domain name';
    }
    if (strlen($label) > 63) {
        return 'Total lenght of your domain must be less then 63 characters';
    }
    if (strlen($label) < 2) {
        return 'Total lenght of your domain must be greater then 2 characters';
    }
    if (preg_match("/(^-|^\.|-\.|\.-|--|\.\.|-$|\.$)/", $label)) {
        return 'Invalid domain name format, cannot begin or end with a hyphen (-)';
    }
    
    // Extract TLD from the domain and prepend a dot
    $parts = explode('.', $label);
    $tld = "." . end($parts);

    // Check if the TLD exists in the domain_tld table
    $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM domain_tld WHERE tld = :tld");
    $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtTLD->execute();
    $tldExists = $stmtTLD->fetchColumn();

    if (!$tldExists) {
        return 'Zone is not supported';
    }

    // Fetch the IDN regex for the given TLD
    $stmtRegex = $pdo->prepare("SELECT idn_table FROM domain_tld WHERE tld = :tld");
    $stmtRegex->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtRegex->execute();
    $idnRegex = $stmtRegex->fetchColumn();

    if (!$idnRegex) {
        return 'Failed to fetch domain IDN table';
    }

    // Check for invalid characters using fetched regex
    if (!preg_match($idnRegex, $label)) {
        $server->send($fd, "Domain name invalid format");
        return 'Invalid domain name format, please review registry policy about accepted labels';
    }
}

function normalize_v4_address($v4) {
    // Remove leading zeros from the first octet
    $v4 = preg_replace('/^0+(\d)/', '$1', $v4);
    
    // Remove leading zeros from successive octets
    $v4 = preg_replace('/\.0+(\d)/', '.$1', $v4);

    return $v4;
}

function normalize_v6_address($v6) {
    // Upper case any alphabetics
    $v6 = strtoupper($v6);
    
    // Remove leading zeros from the first word
    $v6 = preg_replace('/^0+([\dA-F])/', '$1', $v6);
    
    // Remove leading zeros from successive words
    $v6 = preg_replace('/:0+([\dA-F])/', ':$1', $v6);
    
    // Introduce a :: if there isn't one already
    if (strpos($v6, '::') === false) {
        $v6 = preg_replace('/:0:0:/', '::', $v6);
    }

    // Remove initial zero word before a ::
    $v6 = preg_replace('/^0+::/', '::', $v6);
    
    // Remove other zero words before a ::
    $v6 = preg_replace('/(:0)+::/', '::', $v6);

    // Remove zero words following a ::
    $v6 = preg_replace('/:(:0)+/', ':', $v6);

    return $v6;
}