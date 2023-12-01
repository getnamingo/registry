<?php
use phpseclib\Net\SFTP;

require __DIR__ . '/vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$logFilePath = '/var/log/namingo/escrow.log';
$log = setupLogger($logFilePath, 'Escrow');
$log->info('job started.');

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

try {
    $domainCount = fetchCount($dbh, 'domain');
    $hostCount = fetchCount($dbh, 'host');
    $contactCount = fetchCount($dbh, 'contact');
    $registrarCount = fetchCount($dbh, 'registrar');

    // Fetching TLDs
    $stmt = $dbh->query("SELECT id,tld FROM domain_tld;");
    $tlds = $stmt->fetchAll();

    // Prepare the SQL query with a condition for the previous day
    $stmt = $dbh->prepare("SELECT revision FROM rde_escrow_deposits WHERE deposit_date = :previousDay ORDER BY revision DESC LIMIT 1");
    $date = date('Y-m-d');
    $stmt->bindParam(':previousDay', $date);
    $stmt->execute();

    // Fetch the result
    $deposit_id = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($deposit_id) {
        $finalDepositId = (int) $deposit_id['revision'] + 1;
    } else {
        $finalDepositId = 0;
    }

    foreach ($tlds as $tld) {
        $tldname = strtoupper(ltrim($tld['tld'], '.'));
        $endOfPreviousDay = date('Y-m-d 23:59:59', strtotime('-1 day'));
        
        // Skip subdomains
        if (strpos($tldname, '.') !== false) {
            continue;
        }
        
        // Starting the escrow deposit for this TLD
        // Initializing XMLWriter
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');

        // Start the rde:deposit element with the necessary attributes
        $xml->startElementNS('rde', 'deposit', 'urn:ietf:params:xml:ns:rde-1.0');
        $xml->writeAttribute('type', 'FULL');
        $paddedFinalDepositId = str_pad($finalDepositId, 3, '0', STR_PAD_LEFT);
        $depositId = date('Ymd') . $paddedFinalDepositId;
        $xml->writeAttribute('id', $depositId);

        // Add the necessary XML namespaces
        $xml->writeAttributeNS('xmlns', 'domain', null, 'urn:ietf:params:xml:ns:domain-1.0');
        $xml->writeAttributeNS('xmlns', 'contact', null, 'urn:ietf:params:xml:ns:contact-1.0');
        $xml->writeAttributeNS('xmlns', 'secDNS', null, 'urn:ietf:params:xml:ns:secDNS-1.1');
        $xml->writeAttributeNS('xmlns', 'rdeHeader', null, 'urn:ietf:params:xml:ns:rdeHeader-1.0');
        $xml->writeAttributeNS('xmlns', 'rdeDomain', null, 'urn:ietf:params:xml:ns:rdeDomain-1.0');
        $xml->writeAttributeNS('xmlns', 'rdeHost', null, 'urn:ietf:params:xml:ns:rdeHost-1.0');
        $xml->writeAttributeNS('xmlns', 'rdeContact', null, 'urn:ietf:params:xml:ns:rdeContact-1.0');
        $xml->writeAttributeNS('xmlns', 'rdeRegistrar', null, 'urn:ietf:params:xml:ns:rdeRegistrar-1.0');
        $xml->writeAttributeNS('xmlns', 'rdeIDN', null, 'urn:ietf:params:xml:ns:rdeIDN-1.0');
        $xml->writeAttributeNS('xmlns', 'rdeNNDN', null, 'urn:ietf:params:xml:ns:rdeNNDN-1.0');
        $xml->writeAttributeNS('xmlns', 'rdeEppParams', null, 'urn:ietf:params:xml:ns:rdeEppParams-1.0');
        $xml->writeAttributeNS('xmlns', 'rdePolicy', null, 'urn:ietf:params:xml:ns:rdePolicy-1.0');
        $xml->writeAttributeNS('xmlns', 'epp', null, 'urn:ietf:params:xml:ns:epp-1.0');

        $xml->startElementNS('rde', 'watermark', null);
        $previousDayWatermark = date('Y-m-d', strtotime('-1 day')) . 'T23:59:59Z';
        $xml->text($previousDayWatermark);
        $xml->endElement(); // End rde:watermark

        // Start the rde:rdeMenu element
        $xml->startElementNS('rde', 'rdeMenu', null);

        // Write the rde:version element
        $xml->startElementNS('rde', 'version', null);
        $xml->text('1.0');
        $xml->endElement(); // End rde:version

        // Array of objURI values
        $objURIs = [
            'urn:ietf:params:xml:ns:rdeHeader-1.0',
            'urn:ietf:params:xml:ns:rdeContact-1.0',
            'urn:ietf:params:xml:ns:rdeHost-1.0',
            'urn:ietf:params:xml:ns:rdeDomain-1.0',
            'urn:ietf:params:xml:ns:rdeRegistrar-1.0',
            'urn:ietf:params:xml:ns:rdeIDN-1.0',
            'urn:ietf:params:xml:ns:rdeNNDN-1.0',
            'urn:ietf:params:xml:ns:rdeEppParams-1.0'
        ];

        // Write each rde:objURI element
        foreach ($objURIs as $objURI) {
            $xml->startElementNS('rde', 'objURI', null);
            $xml->text($objURI);
            $xml->endElement(); // End rde:objURI
        }

        // End the rde:rdeMenu element
        $xml->endElement(); // End rde:rdeMenu
        
        $xml->startElementNS('rde', 'contents', null);
        
        $xml->startElement('rdeHeader:header');
        $xml->writeElement('rdeHeader:tld', $tld['tld']);
        
        $xml->startElement('rdeHeader:count');
        $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeDomain-1.0');
        $xml->text($domainCount);
        $xml->endElement();

        $xml->startElement('rdeHeader:count');
        $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeHost-1.0');
        $xml->text($hostCount);
        $xml->endElement();
        
        $xml->startElement('rdeHeader:count');
        $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeContact-1.0');
        $xml->text($contactCount);
        $xml->endElement();

        $xml->startElement('rdeHeader:count');
        $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeRegistrar-1.0');
        $xml->text($registrarCount);
        $xml->endElement();
        
        $xml->startElement('rdeHeader:count');
        $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeIDN-1.0');
        $xml->text('0');
        $xml->endElement();

        $xml->startElement('rdeHeader:count');
        $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeNNDN-1.0');
        $xml->text('0');
        $xml->endElement();
        
        $xml->startElement('rdeHeader:count');
        $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeEppParams-1.0');
        $xml->text('0');
        $xml->endElement();

        $xml->endElement();  // Closing rdeHeader:header

        // Fetch domain details for this TLD
        $stmt = $dbh->prepare("SELECT * FROM domain WHERE tldid = :tldid AND crdate <= :endOfPreviousDay");
        $stmt->bindParam(':tldid', $tld['id']);
        $stmt->bindParam(':endOfPreviousDay', $endOfPreviousDay);
        $stmt->execute();
        $domains = $stmt->fetchAll();

        foreach ($domains as $domain) {
            $xml->startElement('rdeDom:domain');
            $xml->writeElement('rdeDom:name', $domain['name']);
            $xml->writeElement('rdeDom:roid', 'D' . $domain['id']);
            $xml->writeElement('rdeDom:uName', $domain['name']);
            $xml->writeElement('rdeDom:idnTableId', 'Latn');

            // Fetch domain status
            $stmt = $dbh->prepare("SELECT * FROM domain_status WHERE domain_id = :domain_id;");
            $stmt->bindParam(':domain_id', $domain['id']);
            $stmt->execute();
            $status = $stmt->fetch();
            $xml->writeElement('rdeDom:status', $status['status'] ?? 'okk');

            $xml->writeElement('rdeDom:registrant', $domain['registrant']);

            // Fetch domain contacts
            $stmt = $dbh->prepare("SELECT * FROM domain_contact_map WHERE domain_id = :domain_id;");
            $stmt->bindParam(':domain_id', $domain['id']);
            $stmt->execute();
            $domain_contacts = $stmt->fetchAll();
            foreach ($domain_contacts as $contact) {
                $xml->startElement('rdeDom:contact');
                $xml->writeAttribute('type', $contact['type']);
                $xml->text($contact['contact_id']);
                $xml->endElement();  // Closing rdeDom:contact
            }

            // Fetch domain hosts and incorporate into XML
            $stmt = $dbh->prepare("SELECT host.name FROM domain_host_map JOIN host ON domain_host_map.host_id = host.id WHERE domain_host_map.domain_id = :domain_id;");
            $stmt->bindParam(':domain_id', $domain['id']);
            $stmt->execute();
            $domain_hosts = $stmt->fetchAll();
            $xml->startElement('rdeDom:ns');
            foreach ($domain_hosts as $host) {
                $xml->writeElement('domain:hostObj', $host['name']);
            }
            $xml->endElement();  // Closing rdeDom:ns

            $xml->writeElement('rdeDom:clID', $domain['clid']);
            $xml->writeElement('rdeDom:crRr', $domain['crid']);
            $crDate = DateTime::createFromFormat('Y-m-d H:i:s.v', $domain['crdate']);
            $xml->writeElement('rdeDom:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
            $exDate = DateTime::createFromFormat('Y-m-d H:i:s.v', $domain['exdate']);
            $xml->writeElement('rdeDom:exDate', $exDate->format("Y-m-d\\TH:i:s.v\\Z"));
            
            $xml->endElement();  // Closing rdeDom:domain
        }

        // Fetch and incorporate host details
        $stmt = $dbh->prepare("SELECT * FROM host WHERE crdate <= :endOfPreviousDay");
        $stmt->bindParam(':endOfPreviousDay', $endOfPreviousDay);
        $stmt->execute();
        $hosts = $stmt->fetchAll();

        foreach ($hosts as $host) {
            $xml->startElement('rdeHost:host');
            $xml->writeElement('rdeHost:name', $host['name']);
            $xml->writeElement('rdeHost:roid', 'H' . $host['id']);
            
            $xml->startElement('rdeHost:status');
            $xml->writeAttribute('s', 'ok');
            $xml->text('ok');
            $xml->endElement();  // Closing rdeHost:status
            
            $xml->writeElement('rdeHost:clID', $host['clid']);
            $xml->writeElement('rdeHost:crRr', $host['crid']);
            $crDate = DateTime::createFromFormat('Y-m-d H:i:s.v', $host['crdate']);
            $xml->writeElement('rdeHost:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
            $xml->endElement();  // Closing rdeHost:host
        }

        // Fetch and incorporate contact details
        $stmt = $dbh->prepare("SELECT * FROM contact WHERE crdate <= :endOfPreviousDay");
        $stmt->bindParam(':endOfPreviousDay', $endOfPreviousDay);
        $stmt->execute();
        $contacts = $stmt->fetchAll();

        foreach ($contacts as $contact) {
            $xml->startElement('rdeContact:contact');
            $xml->writeElement('rdeContact:id', $contact['identifier']);
            $xml->writeElement('rdeContact:roid', 'C' . $contact['id']);
            $xml->startElement('rdeContact:status');
            $xml->writeAttribute('s', 'ok');
            $xml->text('ok');
            $xml->endElement();  // Closing rdeContact:status

            // Fetch postalInfo for the current contact
            $stmtPostal = $dbh->prepare("SELECT * FROM contact_postalInfo WHERE contact_id = :contact_id;");
            $stmtPostal->bindParam(':contact_id', $contact['id']);
            $stmtPostal->execute();
            $postalInfo = $stmtPostal->fetch();

            if ($postalInfo) {
                $xml->startElement('rdeContact:postalInfo');
                $xml->writeAttribute('type', 'int');
                $xml->writeElement('contact:name', $postalInfo['name']);
                $xml->writeElement('contact:org', $postalInfo['org']);
                $xml->startElement('contact:addr');
                $xml->writeElement('contact:street', $postalInfo['street1']);
                $xml->writeElement('contact:city', $postalInfo['city']);
                $xml->writeElement('contact:pc', $postalInfo['pc']);
                $xml->writeElement('contact:cc', $postalInfo['cc']);
                $xml->endElement();  // Closing contact:addr
                $xml->endElement();  // Closing rdeContact:postalInfo
            }

            $xml->writeElement('rdeContact:voice', $contact['voice']);
            $xml->writeElement('rdeContact:fax', $contact['fax']);
            $xml->writeElement('rdeContact:email', $contact['email']);
            $xml->writeElement('rdeContact:clID', $contact['clid']);
            $xml->writeElement('rdeContact:crRr', $contact['crid']);
            $crDate = DateTime::createFromFormat('Y-m-d H:i:s.v', $contact['crdate']);
            $xml->writeElement('rdeContact:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
            if (!empty($contact['upid'])) {
                $xml->writeElement('rdeContact:upRr', $contact['upid']);
                $upDate = DateTime::createFromFormat('Y-m-d H:i:s.v', $contact['update']);
                $xml->writeElement('rdeContact:upDate', $upDate->format("Y-m-d\\TH:i:s.v\\Z"));
            }
            $xml->endElement();  // Closing rdeContact:contact
        }
        
        // Fetch and incorporate registrar details
        $stmt = $dbh->prepare("SELECT * FROM registrar WHERE crdate <= :endOfPreviousDay");
        $stmt->bindParam(':endOfPreviousDay', $endOfPreviousDay);
        $stmt->execute();
        $registrars = $stmt->fetchAll();

        $xml->startElement('rdeRegistrar:registrar');
        foreach ($registrars as $registrar) {
            $xml->writeElement('rdeRegistrar:id', $registrar['clid']);
            $xml->writeElement('rdeRegistrar:name', $registrar['name']);
            $xml->writeElement('rdeRegistrar:gurid', $registrar['iana_id']);
            $xml->writeElement('rdeRegistrar:status', 'ok');

            // Fetch and incorporate registrar contact details
            $stmt = $dbh->prepare("SELECT * FROM registrar_contact WHERE registrar_id = :registrar_id;");
            $stmt->bindParam(':registrar_id', $registrar['id']);
            $stmt->execute();
            $registrar_contacts = $stmt->fetchAll();

            foreach ($registrar_contacts as $contact) {
                $xml->startElement('rdeRegistrar:postalInfo');
                $xml->writeAttribute('type', 'int');
                $xml->startElement('rdeRegistrar:addr');
                $xml->writeElement('rdeRegistrar:street', $contact['street1']);
                $xml->writeElement('rdeRegistrar:city', $contact['city']);
                $xml->writeElement('rdeRegistrar:pc', $contact['pc']);
                $xml->writeElement('rdeRegistrar:cc', $contact['cc']);
                $xml->endElement();  // Closing rdeRegistrar:addr
                $xml->endElement();  // Closing rdeRegistrar:postalInfo
                
                $xml->writeElement('rdeRegistrar:voice', $contact['voice']);
                $xml->writeElement('rdeRegistrar:fax', $contact['fax']);
                $xml->writeElement('rdeRegistrar:email', $contact['email']);
            }

            $xml->writeElement('rdeRegistrar:url', $registrar['url']);
            $xml->startElement('rdeRegistrar:whoisInfo');
            $xml->writeElement('rdeRegistrar:name', $registrar['whois_server']);
            $xml->writeElement('rdeRegistrar:url', $registrar['whois_server']);
            $xml->endElement();  // Closing rdeRegistrar:whoisInfo

            $crDate = DateTime::createFromFormat('Y-m-d H:i:s.v', $registrar['crdate']);
            $xml->writeElement('rdeRegistrar:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
        }
        $xml->endElement();  // Closing rdeRegistrar:registrar
        
        // Writing the idnTableRef section
        $xml->startElement('rdeIDN:idnTableRef');
        $xml->writeAttribute('id', 'Latn');
        $xml->writeElement('rdeIDN:url', 'https://namingo.org');
        $xml->writeElement('rdeIDN:urlPolicy', 'https://namingo.org');
        $xml->endElement();  // Closing rdeIDN:idnTableRef
        
        // Start of rdeEppParams:eppParams
        $xml->startElementNS('rdeEppParams', 'eppParams', null);

        // Add version and lang elements
        $xml->writeElementNS('rdeEppParams', 'version', null, '1.0');
        $xml->writeElementNS('rdeEppParams', 'lang', null, 'en');

        // Add objURI elements
        $uriArray = [
            'urn:ietf:params:xml:ns:domain-1.0',
            'urn:ietf:params:xml:ns:contact-1.0',
            'urn:ietf:params:xml:ns:host-1.0'
        ];

        foreach ($uriArray as $uri) {
            $xml->writeElementNS('rdeEppParams', 'objURI', null, $uri);
        }

        // Start of svcExtension
        $xml->startElementNS('rdeEppParams', 'svcExtension', null);

        // Add extURI elements
        $extUriArray = [
            'urn:ietf:params:xml:ns:rgp-1.0',
            'urn:ietf:params:xml:ns:secDNS-1.1'
        ];

        foreach ($extUriArray as $extUri) {
            $xml->writeElementNS('epp', 'extURI', null, $extUri);
        }

        // End of svcExtension
        $xml->endElement();

        // Start of dcp
        $xml->startElementNS('rdeEppParams', 'dcp', null);

        // Add access
        $xml->startElementNS('epp', 'access', null);
        $xml->writeElementNS('epp', 'all', null);
        $xml->endElement();

        // Start of statement
        $xml->startElementNS('epp', 'statement', null);

        // Add purpose
        $xml->startElementNS('epp', 'purpose', null);
        $xml->writeElementNS('epp', 'admin', null);
        $xml->writeElementNS('epp', 'prov', null);
        $xml->endElement();

        // Add recipient
        $xml->startElementNS('epp', 'recipient', null);
        $xml->writeElementNS('epp', 'ours', null);
        $xml->writeElementNS('epp', 'public', null);
        $xml->endElement();

        // Add retention
        $xml->startElementNS('epp', 'retention', null);
        $xml->writeElementNS('epp', 'stated', null);
        $xml->endElement();

        // End of statement
        $xml->endElement();

        // End of dcp
        $xml->endElement();

        // End of rdeEppParams:eppParams
        $xml->endElement();

        // rdePolicy:policy element
        $xml->startElementNS('rdePolicy', 'policy', null);
        $xml->writeAttribute('scope', '//rde:deposit/rde:contents/rdeDomain:domain');
        $xml->writeAttribute('element', 'rdeDomain:registrant');
        $xml->endElement();
        
        // End the rde:contents element
        $xml->endElement(); // End rde:contents
    
        $xml->endElement();  // Closing the 'rde:deposit' element
        $deposit = $xml->outputMemory();

        // Define the base name without the extension
        $baseFileName = "{$tldname}_".date('Y-m-d')."_full_S1_R{$finalDepositId}";

        // XML, tar, and gzip filenames
        $xmlFileName = $baseFileName . ".xml";
        $tarFileName = $baseFileName . ".tar";
        $gzipFileName = $baseFileName . ".tar.gz";

        // Save the main XML file
        file_put_contents($c['escrow_deposit_path']."/".$xmlFileName, $deposit, LOCK_EX);

        // Compress the XML file using tar
        $phar = new PharData($c['escrow_deposit_path']."/".$tarFileName);
        $phar->addFile($c['escrow_deposit_path']."/".$xmlFileName, $xmlFileName);

        // Compress the tar archive using gzip
        $phar->compress(Phar::GZ);

        // Delete the original tar file
        unlink($c['escrow_deposit_path']."/".$tarFileName);

        // Check if the $c['escrow_deleteXML'] variable is set to true and delete the original XML file
        if ($c['escrow_deleteXML']) {
            unlink($c['escrow_deposit_path']."/".$xmlFileName);
        }
        
        // Initialize a GnuPG instance
        $res = gnupg_init();

        // Get information about the public key from its content
        $publicKeyInfo = gnupg_import($res, file_get_contents($c['escrow_keyPath']));
        $fingerprint = $publicKeyInfo['fingerprint'];

        // Check if the key is already in the keyring
        $existingKeys = gnupg_keyinfo($res, $fingerprint);

        if (!$existingKeys) {
            // If not, import the public key
            gnupg_import($res, file_get_contents($c['escrow_keyPath']));
        }

        // Read the .tar.gz file contents
        $fileData = file_get_contents($c['escrow_deposit_path'] . "/" . $gzipFileName);
        
        // Add the encryption key
        gnupg_addencryptkey($res, $fingerprint);

        // Encrypt the file data using the public key
        $encryptedData = gnupg_encrypt($res, $fileData);

        if (!$encryptedData) {
            $log->error('Error encrypting data: ' . gnupg_geterror($res));
        }

        // Save the encrypted data to a new file
        file_put_contents($c['escrow_deposit_path'] . "/" . $baseFileName . ".ryde", $encryptedData);

        // Delete the original .tar.gz file
        unlink($c['escrow_deposit_path'] . "/" . $gzipFileName);
        
        $encryptedFilePath = $c['escrow_deposit_path'] . "/" . $baseFileName . ".ryde";
        
        // Initialize the GnuPG extension
        $gpg = new gnupg();
        $gpg->seterrormode(gnupg::ERROR_EXCEPTION); // throw exceptions on errors

        // Import your private key (if it's not already in the keyring)
        $privateKeyData = file_get_contents($c['escrow_privateKey']);
        $importResult = $gpg->import($privateKeyData);

        // Set the key to be used for signing
        $privateKeyId = $importResult['fingerprint'];
        $gpg->addsignkey($privateKeyId);
        
        // Specify the detached signature mode
        $gpg->setsignmode(GNUPG_SIG_MODE_DETACH);

        // Sign the encrypted data
        $encryptedData = file_get_contents($encryptedFilePath);
        $signature = $gpg->sign($encryptedData);

        // Save the signature to a .sig file
        $signatureFilePath = $c['escrow_deposit_path'] . '/' . pathinfo($encryptedFilePath, PATHINFO_FILENAME) . '.sig';
        file_put_contents($signatureFilePath, $signature);

        // Optionally, delete the encrypted file if you don't need it anymore
        // unlink($encryptedFilePath);
        
        // Start XMLWriter for the report
        $reportXML = new XMLWriter();
        $reportXML->openMemory();
        $reportXML->startDocument('1.0', 'UTF-8');

        $reportXML->startElement('rdeReport:report');
        $reportXML->writeAttribute('xmlns:rdeReport', 'urn:ietf:params:xml:ns:rdeReport-1.0');
        $reportXML->writeAttribute('xmlns:rdeHeader', 'urn:ietf:params:xml:ns:rdeHeader-1.0');

        $paddedFinalDepositId = str_pad($finalDepositId, 3, '0', STR_PAD_LEFT);
        $depositId = date('Ymd') . $paddedFinalDepositId;
        $reportXML->writeElement('rdeReport:id', $depositId);
        $reportXML->writeElement('rdeReport:version', '1');
        $reportXML->writeElement('rdeReport:rydeSpecEscrow', 'RFC8909');
        $reportXML->writeElement('rdeReport:rydeSpecMapping', 'RFC9022');
        $reportXML->writeElement('rdeReport:resend', $finalDepositId);
        $currentDateTime = new DateTime();
        $crDateWithMilliseconds = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
        $reportXML->writeElement('rdeReport:crDate', $crDateWithMilliseconds);
        $reportXML->writeElement('rdeReport:kind', 'FULL');
        $previousDayWatermark = date('Y-m-d', strtotime('-1 day')) . 'T23:59:59Z';
        $reportXML->writeElement('rdeReport:watermark', $previousDayWatermark);

        $reportXML->startElement('rdeHeader:header');
        $reportXML->writeElement('rdeHeader:tld', $tld['tld']);
        $reportXML->startElement('rdeHeader:count');
        $reportXML->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeDomain-1.0');
        $reportXML->text($domainCount);
        $reportXML->endElement();

        $reportXML->startElement('rdeHeader:count');
        $reportXML->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeHost-1.0');
        $reportXML->text($hostCount);
        $reportXML->endElement();

        $reportXML->startElement('rdeHeader:count');
        $reportXML->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeContact-1.0');
        $reportXML->text($contactCount);
        $reportXML->endElement();

        $reportXML->startElement('rdeHeader:count');
        $reportXML->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeRegistrar-1.0');
        $reportXML->text($registrarCount);
        $reportXML->endElement();

        $reportXML->startElement('rdeHeader:count');
        $reportXML->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeIDN-1.0');
        $reportXML->text('0');
        $reportXML->endElement();

        $reportXML->startElement('rdeHeader:count');
        $reportXML->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeNNDN-1.0');
        $reportXML->text('0');
        $reportXML->endElement();

        $reportXML->startElement('rdeHeader:count');
        $reportXML->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeEppParams-1.0');
        $reportXML->text('0');
        $reportXML->endElement();
        
        $reportXML->endElement(); // Closing rdeHeader:header
        $reportXML->endElement(); // Closing rdeReport:report

        $reps = $reportXML->outputMemory();

        // Save the report file
        $reportFilePath = $c['escrow_deposit_path']."/{$tldname}_".date('Y-m-d')."_full_R{$finalDepositId}.rep";
        file_put_contents($reportFilePath, $reps, LOCK_EX);
        
        $dayOfWeekToRunBRDA = $c['escrow_BRDAday'];
        $currentDayOfWeek = date('l');

        if ($currentDayOfWeek === $dayOfWeekToRunBRDA) {
            // Start BRDA generation
            $xml = new XMLWriter();
            $xml->openMemory();
            $xml->startDocument('1.0', 'UTF-8');

            // Start the rde:deposit element with the necessary attributes
            $xml->startElementNS('rde', 'deposit', 'urn:ietf:params:xml:ns:rde-1.0');
            $xml->writeAttribute('type', 'FULL');
            $paddedFinalDepositId = str_pad($finalDepositId, 3, '0', STR_PAD_LEFT);
            $depositId = date('Ymd') . $paddedFinalDepositId;
            $xml->writeAttribute('id', $depositId);

            // Add the necessary XML namespaces
            $xml->writeAttributeNS('xmlns', 'domain', null, 'urn:ietf:params:xml:ns:domain-1.0');
            $xml->writeAttributeNS('xmlns', 'contact', null, 'urn:ietf:params:xml:ns:contact-1.0');
            $xml->writeAttributeNS('xmlns', 'secDNS', null, 'urn:ietf:params:xml:ns:secDNS-1.1');
            $xml->writeAttributeNS('xmlns', 'rdeHeader', null, 'urn:ietf:params:xml:ns:rdeHeader-1.0');
            $xml->writeAttributeNS('xmlns', 'rdeDomain', null, 'urn:ietf:params:xml:ns:rdeDomain-1.0');
            $xml->writeAttributeNS('xmlns', 'rdeHost', null, 'urn:ietf:params:xml:ns:rdeHost-1.0');
            $xml->writeAttributeNS('xmlns', 'rdeContact', null, 'urn:ietf:params:xml:ns:rdeContact-1.0');
            $xml->writeAttributeNS('xmlns', 'rdeRegistrar', null, 'urn:ietf:params:xml:ns:rdeRegistrar-1.0');
            $xml->writeAttributeNS('xmlns', 'rdeIDN', null, 'urn:ietf:params:xml:ns:rdeIDN-1.0');
            $xml->writeAttributeNS('xmlns', 'rdeNNDN', null, 'urn:ietf:params:xml:ns:rdeNNDN-1.0');
            $xml->writeAttributeNS('xmlns', 'rdeEppParams', null, 'urn:ietf:params:xml:ns:rdeEppParams-1.0');
            $xml->writeAttributeNS('xmlns', 'rdePolicy', null, 'urn:ietf:params:xml:ns:rdePolicy-1.0');
            $xml->writeAttributeNS('xmlns', 'epp', null, 'urn:ietf:params:xml:ns:epp-1.0');

            $xml->startElementNS('rde', 'watermark', null);
            $previousDayWatermark = date('Y-m-d', strtotime('-1 day')) . 'T23:59:59Z';
            $xml->text($previousDayWatermark);
            $xml->endElement(); // End rde:watermark

            // Start the rde:rdeMenu element
            $xml->startElementNS('rde', 'rdeMenu', null);

            // Write the rde:version element
            $xml->startElementNS('rde', 'version', null);
            $xml->text('1.0');
            $xml->endElement(); // End rde:version

            // Array of objURI values
            $objURIs = [
                'urn:ietf:params:xml:ns:rdeHeader-1.0',
                'urn:ietf:params:xml:ns:rdeContact-1.0',
                'urn:ietf:params:xml:ns:rdeHost-1.0',
                'urn:ietf:params:xml:ns:rdeDomain-1.0',
                'urn:ietf:params:xml:ns:rdeRegistrar-1.0',
                'urn:ietf:params:xml:ns:rdeIDN-1.0',
                'urn:ietf:params:xml:ns:rdeNNDN-1.0',
                'urn:ietf:params:xml:ns:rdeEppParams-1.0'
            ];

            // Write each rde:objURI element
            foreach ($objURIs as $objURI) {
                $xml->startElementNS('rde', 'objURI', null);
                $xml->text($objURI);
                $xml->endElement(); // End rde:objURI
            }

            // End the rde:rdeMenu element
            $xml->endElement(); // End rde:rdeMenu
            
            $xml->startElementNS('rde', 'contents', null);
            
            $xml->startElement('rdeHeader:header');
            $xml->writeElement('rdeHeader:tld', $tld['tld']);
            
            $xml->startElement('rdeHeader:count');
            $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeDomain-1.0');
            $xml->text($domainCount);
            $xml->endElement();

            $xml->startElement('rdeHeader:count');
            $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeHost-1.0');
            $xml->text($hostCount);
            $xml->endElement();
            
            $xml->startElement('rdeHeader:count');
            $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeContact-1.0');
            $xml->text($contactCount);
            $xml->endElement();

            $xml->startElement('rdeHeader:count');
            $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeRegistrar-1.0');
            $xml->text($registrarCount);
            $xml->endElement();
            
            $xml->startElement('rdeHeader:count');
            $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeIDN-1.0');
            $xml->text('0');
            $xml->endElement();

            $xml->startElement('rdeHeader:count');
            $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeNNDN-1.0');
            $xml->text('0');
            $xml->endElement();
            
            $xml->startElement('rdeHeader:count');
            $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeEppParams-1.0');
            $xml->text('0');
            $xml->endElement();

            $xml->endElement();  // Closing rdeHeader:header

            // Fetch domain details for this TLD
            $stmt = $dbh->prepare("SELECT * FROM domain WHERE tldid = :tldid AND crdate <= :endOfPreviousDay");
            $stmt->bindParam(':tldid', $tld['id']);
            $stmt->bindParam(':endOfPreviousDay', $endOfPreviousDay);
            $stmt->execute();
            $domains = $stmt->fetchAll();

            foreach ($domains as $domain) {
                $xml->startElement('rdeDom:domain');
                $xml->writeElement('rdeDom:name', $domain['name']);
                $xml->writeElement('rdeDom:roid', 'D' . $domain['id']);
                $xml->writeElement('rdeDom:uName', $domain['name']);
                $xml->writeElement('rdeDom:idnTableId', 'Latn');

                // Fetch domain status
                $stmt = $dbh->prepare("SELECT * FROM domain_status WHERE domain_id = :domain_id;");
                $stmt->bindParam(':domain_id', $domain['id']);
                $stmt->execute();
                $status = $stmt->fetch();
                $xml->writeElement('rdeDom:status', $status['status'] ?? 'okk');

                $xml->writeElement('rdeDom:registrant', $domain['registrant']);

                // Fetch domain contacts
                $stmt = $dbh->prepare("SELECT * FROM domain_contact_map WHERE domain_id = :domain_id;");
                $stmt->bindParam(':domain_id', $domain['id']);
                $stmt->execute();
                $domain_contacts = $stmt->fetchAll();
                foreach ($domain_contacts as $contact) {
                    $xml->startElement('rdeDom:contact');
                    $xml->writeAttribute('type', $contact['type']);
                    $xml->text($contact['contact_id']);
                    $xml->endElement();  // Closing rdeDom:contact
                }

                // Fetch domain hosts and incorporate into XML
                $stmt = $dbh->prepare("SELECT host.name FROM domain_host_map JOIN host ON domain_host_map.host_id = host.id WHERE domain_host_map.domain_id = :domain_id;");
                $stmt->bindParam(':domain_id', $domain['id']);
                $stmt->execute();
                $domain_hosts = $stmt->fetchAll();
                $xml->startElement('rdeDom:ns');
                foreach ($domain_hosts as $host) {
                    $xml->writeElement('domain:hostObj', $host['name']);
                }
                $xml->endElement();  // Closing rdeDom:ns

                $xml->writeElement('rdeDom:clID', $domain['clid']);
                $xml->writeElement('rdeDom:crRr', $domain['crid']);
                $crDate = DateTime::createFromFormat('Y-m-d H:i:s.v', $domain['crdate']);
                $xml->writeElement('rdeDom:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
                $exDate = DateTime::createFromFormat('Y-m-d H:i:s.v', $domain['exdate']);
                $xml->writeElement('rdeDom:exDate', $exDate->format("Y-m-d\\TH:i:s.v\\Z"));
                
                $xml->endElement();  // Closing rdeDom:domain
            }

            // Fetch and incorporate registrar details
            $stmt = $dbh->prepare("SELECT * FROM registrar WHERE crdate <= :endOfPreviousDay");
            $stmt->bindParam(':endOfPreviousDay', $endOfPreviousDay);
            $stmt->execute();
            $registrars = $stmt->fetchAll();

            $xml->startElement('rdeRegistrar:registrar');
            foreach ($registrars as $registrar) {
                $xml->writeElement('rdeRegistrar:id', $registrar['clid']);
                $xml->writeElement('rdeRegistrar:name', $registrar['name']);
                $xml->writeElement('rdeRegistrar:gurid', $registrar['iana_id']);
                $xml->writeElement('rdeRegistrar:status', 'ok');

                // Fetch and incorporate registrar contact details
                $stmt = $dbh->prepare("SELECT * FROM registrar_contact WHERE registrar_id = :registrar_id;");
                $stmt->bindParam(':registrar_id', $registrar['id']);
                $stmt->execute();
                $registrar_contacts = $stmt->fetchAll();

                foreach ($registrar_contacts as $contact) {
                    $xml->startElement('rdeRegistrar:postalInfo');
                    $xml->writeAttribute('type', 'int');
                    $xml->startElement('rdeRegistrar:addr');
                    $xml->writeElement('rdeRegistrar:street', $contact['street1']);
                    $xml->writeElement('rdeRegistrar:city', $contact['city']);
                    $xml->writeElement('rdeRegistrar:pc', $contact['pc']);
                    $xml->writeElement('rdeRegistrar:cc', $contact['cc']);
                    $xml->endElement();  // Closing rdeRegistrar:addr
                    $xml->endElement();  // Closing rdeRegistrar:postalInfo
                    
                    $xml->writeElement('rdeRegistrar:voice', $contact['voice']);
                    $xml->writeElement('rdeRegistrar:fax', $contact['fax']);
                    $xml->writeElement('rdeRegistrar:email', $contact['email']);
                }

                $xml->writeElement('rdeRegistrar:url', $registrar['url']);
                $xml->startElement('rdeRegistrar:whoisInfo');
                $xml->writeElement('rdeRegistrar:name', $registrar['whois_server']);
                $xml->writeElement('rdeRegistrar:url', $registrar['whois_server']);
                $xml->endElement();  // Closing rdeRegistrar:whoisInfo

                $crDate = DateTime::createFromFormat('Y-m-d H:i:s.v', $registrar['crdate']);
                $xml->writeElement('rdeRegistrar:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
            }
            $xml->endElement();  // Closing rdeRegistrar:registrar
            
            // End the rde:contents element
            $xml->endElement(); // End rde:contents
        
            $xml->endElement();  // Closing the 'rde:deposit' element
            $deposit = $xml->outputMemory();

            // Define the base name without the extension
            $baseFileNameBrda = "{$tldname}_".date('Y-m-d')."_brda_S1_R{$finalDepositId}";

            // XML, tar, and gzip filenames
            $xmlFileName = $baseFileNameBrda . ".xml";
            $tarFileName = $baseFileNameBrda . ".tar";
            $gzipFileName = $baseFileNameBrda . ".tar.gz";

            // Save the main XML file
            file_put_contents($c['escrow_deposit_path']."/".$xmlFileName, $deposit, LOCK_EX);

            // Compress the XML file using tar
            $phar = new PharData($c['escrow_deposit_path']."/".$tarFileName);
            $phar->addFile($c['escrow_deposit_path']."/".$xmlFileName, $xmlFileName);

            // Compress the tar archive using gzip
            $phar->compress(Phar::GZ);

            // Delete the original tar file
            unlink($c['escrow_deposit_path']."/".$tarFileName);

            // Check if the $c['escrow_deleteXML'] variable is set to true and delete the original XML file
            if ($c['escrow_deleteXML']) {
                unlink($c['escrow_deposit_path']."/".$xmlFileName);
            }
            
            // Initialize a GnuPG instance
            $res = gnupg_init();

            // Get information about the public key from its content
            $publicKeyInfo = gnupg_import($res, file_get_contents($c['escrow_keyPath_brda']));
            $fingerprint = $publicKeyInfo['fingerprint'];

            // Check if the key is already in the keyring
            $existingKeys = gnupg_keyinfo($res, $fingerprint);

            if (!$existingKeys) {
                // If not, import the public key
                gnupg_import($res, file_get_contents($c['escrow_keyPath_brda']));
            }

            // Read the .tar.gz file contents
            $fileData = file_get_contents($c['escrow_deposit_path'] . "/" . $gzipFileName);
            
            // Add the encryption key
            gnupg_addencryptkey($res, $fingerprint);

            // Encrypt the file data using the public key
            $encryptedData = gnupg_encrypt($res, $fileData);

            if (!$encryptedData) {
                $log->error('Error encrypting data: ' . gnupg_geterror($res));
            }

            // Save the encrypted data to a new file
            file_put_contents($c['escrow_deposit_path'] . "/" . $baseFileNameBrda . ".ryde", $encryptedData);

            // Delete the original .tar.gz file
            unlink($c['escrow_deposit_path'] . "/" . $gzipFileName);
            
            $encryptedFilePathBrda = $c['escrow_deposit_path'] . "/" . $baseFileNameBrda . ".ryde";
            
            // Initialize the GnuPG extension
            $gpg = new gnupg();
            $gpg->seterrormode(gnupg::ERROR_EXCEPTION); // throw exceptions on errors

            // Import your private key (if it's not already in the keyring)
            $privateKeyData = file_get_contents($c['escrow_privateKey']);
            $importResult = $gpg->import($privateKeyData);

            // Set the key to be used for signing
            $privateKeyId = $importResult['fingerprint'];
            $gpg->addsignkey($privateKeyId);
            
            // Specify the detached signature mode
            $gpg->setsignmode(GNUPG_SIG_MODE_DETACH);

            // Sign the encrypted data
            $encryptedData = file_get_contents($encryptedFilePathBrda);
            $signature = $gpg->sign($encryptedData);

            // Save the signature to a .sig file
            $signatureFilePathBrda = $c['escrow_deposit_path'] . '/' . pathinfo($encryptedFilePathBrda, PATHINFO_FILENAME) . '.sig';
            file_put_contents($signatureFilePathBrda, $signature);

            // Optionally, delete the encrypted file if you don't need it anymore
            // unlink($encryptedFilePathBrda);
        }

        if ($c['escrow_RDEupload']) {
            // Connect to the SFTP server
            $sftp = new SFTP($c['escrow_sftp_host']);

            // Login with username and password
            if (!$sftp->login($c['escrow_sftp_username'], $c['escrow_sftp_password'])) {
                $log->error('SFTP Login failed');
            }

            // Define the remote directory where you want to upload the files
            $remoteDir = $c['escrow_sftp_remotepath'];

            // Upload the files
            $filesToUpload = [
                $encryptedFilePath,
                $signatureFilePath,
                $reportFilePath
            ];

            foreach ($filesToUpload as $filePath) {
                $remoteFile = $remoteDir . basename($filePath);
                if (!$sftp->put($remoteFile, $filePath, SFTP::SOURCE_LOCAL_FILE)) {
                    $log->error('Failed to upload ' . basename($filePath));
                } else {
                    $log->info('Successfully uploaded ' . basename($filePath));
                }
            }
            
            $reportFileData = file_get_contents($reportFilePath);

            $ch = curl_init();

            // Set cURL options
            curl_setopt($ch, CURLOPT_URL, $c['escrow_report_url']);
            curl_setopt($ch, CURLOPT_USERPWD, $c['escrow_report_username'].':'.$c['escrow_report_password']);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $reportFileData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($reportFileData)
            ));

            $response = curl_exec($ch);

            if ($response === false) {
                $log->error('Upload error occurred: ' . curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode >= 200 && $httpCode < 300) {
                $log->info('Escrow deposit uploaded successfully');
            } else {
                $log->error('Failed to upload escrow deposit. HTTP Status Code: ' . $httpCode);
            }

            curl_close($ch);
            
        }
        
        if ($currentDayOfWeek === $dayOfWeekToRunBRDA) {
            if ($c['escrow_BRDAupload']) {
                // Connect to the SFTP server
                $sftp = new SFTP($c['brda_sftp_host']);

                // Login with username and password
                if (!$sftp->login($c['brda_sftp_username'], $c['brda_sftp_password'])) {
                    $log->error('SFTP Login failed');
                }

                // Define the remote directory where you want to upload the files
                $remoteDir = $c['brda_sftp_remotepath'];

                // Upload the files
                $filesToUpload = [
                    $encryptedFilePathBrda,
                    $signatureFilePathBrda
                ];

                foreach ($filesToUpload as $filePath) {
                    $remoteFile = $remoteDir . basename($filePath);
                    if (!$sftp->put($remoteFile, $filePath, SFTP::SOURCE_LOCAL_FILE)) {
                        $log->error('Failed to upload ' . basename($filePath));
                    } else {
                        $log->info('Successfully uploaded ' . basename($filePath));
                    }
                }
            }
        }
        
        $depositDate = date('Y-m-d');
        $fileName = $c['escrow_deposit_path'] . "/" . $baseFileName . ".ryde";
        $fileFormat = 'XML';
        $encryptionMethod = 'GnuPG';
        $depositType = 'Full';
        $status = 'Deposited';
        $verificationStatus = 'Pending';

        // Prepare the INSERT statement
        $stmt = $dbh->prepare("INSERT INTO rde_escrow_deposits (deposit_id, deposit_date, file_name, file_format, encryption_method, deposit_type, status, verification_status, revision) VALUES (:deposit_id, :deposit_date, :file_name, :file_format, :encryption_method, :deposit_type, :status, :verification_status, :revision)");

        $previousDayFormatted = date('Ymd');
        $paddedFinalDepositId = str_pad($finalDepositId, 3, '0', STR_PAD_LEFT);
        $depositId = $previousDayFormatted . $paddedFinalDepositId;

        // Bind the parameters
        $stmt->bindParam(':deposit_id', $depositId);
        $stmt->bindParam(':deposit_date', $depositDate);
        $stmt->bindParam(':file_name', $fileName);
        $stmt->bindParam(':file_format', $fileFormat);
        $stmt->bindParam(':encryption_method', $encryptionMethod);
        $stmt->bindParam(':deposit_type', $depositType);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':verification_status', $verificationStatus);
        $stmt->bindParam(':revision', $finalDepositId, PDO::PARAM_INT);

        // Execute the statement
        if (!$stmt->execute()) {
            // Handle error here
            $errorInfo = $stmt->errorInfo();
            $log->error('Database error: ' . $errorInfo[2]);
        }

    }
    $log->info('job finished successfully.');
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
}