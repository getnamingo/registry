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

// Reading CSV file
$inputFile = fopen('Registry_Contacts.csv', 'r');

// Skip the header row
$headers = fgetcsv($inputFile);

while (($row = fgetcsv($inputFile)) !== false) {
    $data = array_combine($headers, $row);
    
    // Preparing data for `contact` table
    $contactData = [
        $data['roid'], // identifier
        formatPhoneNumber($data['Voice']), // voice
        null, // voice_x (not provided in CSV)
        formatPhoneNumber($data['Fax']), // fax
        null, // fax_x (not provided in CSV)
        $data['E-mail'], // email
        null, // nin (not provided in CSV)
        null, // nin_type (not provided in CSV)
        $data['registrar'], // clid
        $data['registrar'], // crid
        formatTimestamp($data['createdate']) // crdate
    ];

    // Inserting into `contact` table
    $stmt = $pdo->prepare("INSERT INTO contact (identifier, voice, voice_x, fax, fax_x, email, nin, nin_type, clid, crid, crdate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute($contactData);

    // Getting the last inserted contact_id
    $contactId = $pdo->lastInsertId();

    // Preparing data for `contact_postalInfo` table
    $postalInfoData = [
        $contactId, // contact_id
        'int', // type (assuming 'int' for international)
        $data['International Name'] ?: $data['Local Name'], // name
        $data['International Organisation'] ?: $data['Local Organisation'], // org
        $data['International Street1'] ?: $data['Local Street1'], // street1
        $data['International Street2'] ?: $data['Local Street2'], // street2
        $data['International Street3'] ?: $data['Local Street3'], // street3
        $data['International City'] ?: $data['Local City'], // city
        $data['International State/Province'] ?: $data['Local State/Province'], // sp
        $data['International Postal Code'] ?: $data['Local Postal Code'], // pc
        formatCountryCode($data['International Country Code'] ?: $data['Local Country Code']) // cc
    ];

    // Inserting into `contact_postalInfo` table
    $stmt = $pdo->prepare("INSERT INTO contact_postalInfo (contact_id, type, name, org, street1, street2, street3, city, sp, pc, cc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute($postalInfoData);
    
    // Inserting into `contact_authInfo` table
    $authInfoData = [
        $contactId, // contact_id
        'pw', // authtype
        generateRandomString() // authinfo
    ];

    $stmt = $pdo->prepare("INSERT INTO contact_authInfo (contact_id, authtype, authinfo) VALUES (?, ?, ?)");
    $stmt->execute($authInfoData);

    // Inserting into `contact_status` table
    $statusData = [
        $contactId, // contact_id
        'ok' // status
    ];

    $stmt = $pdo->prepare("INSERT INTO contact_status (contact_id, status) VALUES (?, ?)");
    $stmt->execute($statusData);
}

fclose($inputFile);

