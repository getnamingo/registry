<?php

/*
 * Namingo Registry
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

date_default_timezone_set('UTC');

use phpseclib3\Net\SFTP;

require __DIR__ . '/vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$logFilePath = '/var/log/namingo/escrow.log';
$log = setupLogger($logFilePath, 'Escrow');
$log->info('job started.');

$lock = fopen('/tmp/namingo-escrow.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log->error('Escrow job already running or lock unavailable.');
    exit(1);
}

// Check if the directory exists
if (!file_exists($c['escrow_deposit_path'])) {
    if (!mkdir($c['escrow_deposit_path'], 0755, true)) {
        $log->error("Failed to create directory: " . $c['escrow_deposit_path']);
        exit(1);
    } else {
        $log->info("Directory created successfully: " . $c['escrow_deposit_path']);
    }
}

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
    exit(1);
}

try {
    // Separate read connection keeps a consistent live-database view while
    // operational deposit records are written on the original connection.
    $readDbh = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
    if ($readDbh->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $readDbh->exec("SET time_zone = '+00:00'");
        $readDbh->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $readDbh->exec('SET TRANSACTION READ ONLY');
        $readDbh->beginTransaction();
    } else {
        $readDbh->beginTransaction();
        $readDbh->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $readDbh->exec("SET LOCAL TIME ZONE 'UTC'");
    }
    // A live MVCC view represents the moment it was captured, not midnight
    // unless the job actually starts then. Never claim a historical cutoff.
    $capturedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $snapshotDate = $capturedAt->format('Y-m-d');
    $watermark = $capturedAt->format('Y-m-d\TH:i:s.v\Z');

    // Fetching TLDs
    $stmt = $readDbh->query("SELECT id,tld FROM domain_tld;");
    $tlds = $stmt->fetchAll();

    // Allocate the revision using live operational records.
    $stmt = $dbh->prepare("SELECT revision FROM rde_escrow_deposits WHERE deposit_date = :previousDay ORDER BY revision DESC LIMIT 1");
    $date = $snapshotDate;
    $stmt->bindParam(':previousDay', $date);
    $stmt->execute();

    // Fetch the result
    $deposit_id = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($deposit_id) {
        $finalDepositId = (int) $deposit_id['revision'] + 1;
    } else {
        $finalDepositId = 0;
    }

    // Get ROID from database
    $stmt = $readDbh->query("SELECT value FROM settings WHERE name = 'handle'");
    $roid = escrowRequired($stmt->fetchColumn(), 'registry handle');

    foreach ($tlds as $tld) {
        $tldname = ltrim($tld['tld'], '.');

        $count = $readDbh->prepare('SELECT COUNT(*) FROM domain WHERE tldid = ?');
        $count->execute([$tld['id']]);
        $domainCount = $count->fetchColumn();
        $nndns = escrowNndns($readDbh, $tldname);
        $NNDNCount = count($nndns);
        $hostCount = $readDbh->query('SELECT COUNT(*) FROM host')->fetchColumn();
        $contactCount = $readDbh->query('SELECT COUNT(*) FROM contact')->fetchColumn();
        $registrarCount = $readDbh->query('SELECT COUNT(*) FROM registrar')->fetchColumn();
        $count = $readDbh->prepare('SELECT COUNT(*) FROM domain WHERE tldid = ? AND registrant IS NULL');
        $count->execute([$tld['id']]);
        $requiresRegistrant = (int)$count->fetchColumn() === 0;

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
        $depositId = str_replace('-', '', $snapshotDate) . $paddedFinalDepositId;
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
        $previousDayWatermark = $watermark;
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
            'urn:ietf:params:xml:ns:rdeContact-1.0',
            'urn:ietf:params:xml:ns:rdeHost-1.0',
            'urn:ietf:params:xml:ns:rdeDomain-1.0',
            'urn:ietf:params:xml:ns:rdeRegistrar-1.0',
            'urn:ietf:params:xml:ns:rdeNNDN-1.0',
            'urn:ietf:params:xml:ns:rdeEppParams-1.0',
            'urn:ietf:params:xml:ns:rdePolicy-1.0'
        ];

        if (isIDN($readDbh, $tld['id'])) {
            $objURIs[] = 'urn:ietf:params:xml:ns:rdeIDN-1.0';
        }

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

        if (isIDN($readDbh, $tld['id'])) {
            $xml->startElement('rdeHeader:count');
            $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeIDN-1.0');
            $xml->text('1');
            $xml->endElement();
        }

        $xml->startElement('rdeHeader:count');
        $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeNNDN-1.0');
        $xml->text($NNDNCount);
        $xml->endElement();

        $xml->startElement('rdeHeader:count');
        $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdePolicy-1.0');
        $xml->text($requiresRegistrant ? '1' : '0');
        $xml->endElement();

        $xml->startElement('rdeHeader:count');
        $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeEppParams-1.0');
        $xml->text('1');
        $xml->endElement();

        $xml->endElement();  // Closing rdeHeader:header

        // Fetch domain details for this TLD
        $stmt = $readDbh->prepare("SELECT * FROM domain WHERE tldid = :tldid");
        $stmt->bindParam(':tldid', $tld['id']);
        $stmt->execute();
        $domains = $stmt->fetchAll();

        foreach ($domains as $domain) {
            $xml->startElement('rdeDomain:domain');
            $xml->writeElement('rdeDomain:name', $domain['name']);
            $xml->writeElement('rdeDomain:roid', 'D' . $domain['id'] . '-' . $roid);
            $xml->writeElement('rdeDomain:uName', $domain['name']);
            if (isIDN($readDbh, $tld['id'])) {
                $script = guessIdnScriptName($tld['tld']);
                $xml->writeElement('rdeDomain:idnTableId', $script);
            }

            // Fetch domain status
            escrowStatuses($xml, $readDbh, 'domain', $domain['id']);
            if (!empty($domain['rgpstatus'])) {
                $xml->startElement('rdeDomain:rgpStatus');
                $xml->writeAttribute('s', $domain['rgpstatus']);
                $xml->endElement();
            }

            if ($domain['registrant'] !== null) {
                $xml->writeElement('rdeDomain:registrant', escrowIdentifier($readDbh, $domain['registrant']));
            }

            // Fetch domain contacts
            $stmt = $readDbh->prepare("SELECT * FROM domain_contact_map WHERE domain_id = :domain_id;");
            $stmt->bindParam(':domain_id', $domain['id']);
            $stmt->execute();
            $domain_contacts = $stmt->fetchAll();
            foreach ($domain_contacts as $contact) {
                $xml->startElement('rdeDomain:contact');
                $xml->writeAttribute('type', $contact['type']);
                $xml->text(escrowIdentifier($readDbh, $contact['contact_id']));
                $xml->endElement();  // Closing rdeDomain:contact
            }

            // Fetch domain hosts and incorporate into XML
            $stmt = $readDbh->prepare("SELECT host.name FROM domain_host_map JOIN host ON domain_host_map.host_id = host.id WHERE domain_host_map.domain_id = :domain_id;");
            $stmt->bindParam(':domain_id', $domain['id']);
            $stmt->execute();
            $domain_hosts = $stmt->fetchAll();
            if (!empty($domain_hosts)) {
                $xml->startElement('rdeDomain:ns');
                foreach ($domain_hosts as $host) {
                    $xml->writeElement('domain:hostObj', $host['name']);
                }
                $xml->endElement();  // Closing rdeDomain:ns
            }

            $xml->writeElement('rdeDomain:clID', escrowClid($readDbh, $domain['clid']));
            $xml->writeElement('rdeDomain:crRr', escrowClid($readDbh, $domain['crid']));
            $crDate = escrowParseDate($domain['crdate']);
            $xml->writeElement('rdeDomain:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
            $exDate = escrowParseDate($domain['exdate']);
            $xml->writeElement('rdeDomain:exDate', $exDate->format("Y-m-d\\TH:i:s.v\\Z"));
            escrowUpdate($xml, $readDbh, 'rdeDomain', $domain);
            escrowDnssec($xml, $readDbh, $domain['id']);
            escrowTransfer($xml, $readDbh, 'rdeDomain', $domain);
            
            $xml->endElement();  // Closing rdeDomain:domain
        }

        // Fetch and incorporate host details
        $stmt = $readDbh->prepare("SELECT * FROM host");
        $stmt->execute();
        $hosts = $stmt->fetchAll();

        foreach ($hosts as $host) {
            $xml->startElement('rdeHost:host');
            $xml->writeElement('rdeHost:name', $host['name']);
            $xml->writeElement('rdeHost:roid', 'H' . $host['id'] . '-' . $roid);
            
            escrowStatuses($xml, $readDbh, 'host', $host['id']);

            if (implode('.', array_slice(explode('.', $host['name']), -count(explode('.', ltrim($tld['tld'], '.'))))) === ltrim($tld['tld'], '.')) {
                // Fetch and add addresses
                $stmtAddr = $readDbh->prepare("SELECT addr, ip FROM host_addr WHERE host_id = :host_id");
                $stmtAddr->execute([':host_id' => $host['id']]);
                $addresses = $stmtAddr->fetchAll();

                foreach ($addresses as $address) {
                    $xml->startElement('rdeHost:addr');
                    $xml->writeAttribute('ip', $address['ip']); // v4 or v6
                    $xml->text($address['addr']);
                    $xml->endElement();  // Closing rdeHost:addr
                }
            }

            $xml->writeElement('rdeHost:clID', escrowClid($readDbh, $host['clid']));
            $xml->writeElement('rdeHost:crRr', escrowClid($readDbh, $host['crid']));
            $crDate = escrowParseDate($host['crdate']);
            $xml->writeElement('rdeHost:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
            escrowUpdate($xml, $readDbh, 'rdeHost', $host);
            escrowDate($xml, 'rdeHost:trDate', $host['trdate']);
            $xml->endElement();  // Closing rdeHost:host
        }

        // Fetch and incorporate contact details
        $stmt = $readDbh->prepare("SELECT * FROM contact");
        $stmt->execute();
        $contacts = $stmt->fetchAll();

        foreach ($contacts as $contact) {
            $xml->startElement('rdeContact:contact');
            $xml->writeElement('rdeContact:id', escrowRequired($contact['identifier'], 'contact identifier'));
            $xml->writeElement('rdeContact:roid', 'C' . $contact['id'] . '-' . $roid);
            escrowStatuses($xml, $readDbh, 'contact', $contact['id']);

            // Fetch postalInfo for the current contact
            $stmtPostal = $readDbh->prepare("SELECT * FROM contact_postalInfo WHERE contact_id = :contact_id;");
            $stmtPostal->bindParam(':contact_id', $contact['id']);
            $stmtPostal->execute();
            $postalRows = $stmtPostal->fetchAll();
            if (!$postalRows) {
                throw new RuntimeException('Escrow contact has no postal information (row ' . $contact['id'] . ').');
            }
            foreach ($postalRows as $postalInfo) {
                $xml->startElement('rdeContact:postalInfo');
                $xml->writeAttribute('type', $postalInfo['type']);
                $xml->writeElement('contact:name', escrowRequired($postalInfo['name'], 'contact name'));
                escrowOptional($xml, 'contact:org', $postalInfo['org']);
                escrowAddress($xml, 'contact', $postalInfo);
                $xml->endElement();
            }

            escrowPhone($xml, 'rdeContact:voice', $contact['voice'], $contact['voice_x']);
            escrowPhone($xml, 'rdeContact:fax', $contact['fax'], $contact['fax_x']);
            $xml->writeElement('rdeContact:email', escrowRequired($contact['email'], 'contact email'));
            $xml->writeElement('rdeContact:clID', escrowClid($readDbh, $contact['clid']));
            $xml->writeElement('rdeContact:crRr', escrowClid($readDbh, $contact['crid']));
            $crDate = escrowParseDate($contact['crdate']);
            $xml->writeElement('rdeContact:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
            escrowUpdate($xml, $readDbh, 'rdeContact', $contact);
            escrowTransfer($xml, $readDbh, 'rdeContact', $contact);
            escrowDisclosure($xml, $contact, $postalRows);
            $xml->endElement();  // Closing rdeContact:contact
        }
        
        // Fetch and incorporate registrar details
        $stmt = $readDbh->prepare("SELECT * FROM registrar");
        $stmt->execute();
        $registrars = $stmt->fetchAll();

        foreach ($registrars as $registrar) {
            $xml->startElement('rdeRegistrar:registrar');
            $xml->writeElement('rdeRegistrar:id', escrowClid($readDbh, $registrar['id']));
            $xml->writeElement('rdeRegistrar:name', escrowRequired($registrar['name'], 'registrar name'));
            if ($registrar['iana_id'] !== null && $registrar['iana_id'] !== '') {
                if ((int)$registrar['iana_id'] <= 0) {
                    throw new RuntimeException('Escrow registrar IANA ID must be positive when present.');
                }
                $xml->writeElement('rdeRegistrar:gurid', $registrar['iana_id']);
            }

            // Fetch and incorporate registrar contact details
            $stmt = $readDbh->prepare("SELECT * FROM registrar_contact WHERE registrar_id = :registrar_id AND type = 'owner';");
            $stmt->bindParam(':registrar_id', $registrar['id']);
            $stmt->execute();
            $registrar_contacts = $stmt->fetch();

            if ($registrar_contacts) {
                $xml->startElement('rdeRegistrar:postalInfo');
                $xml->writeAttribute('type', 'int');
                escrowAddress($xml, 'rdeRegistrar', $registrar_contacts);
                $xml->endElement(); // Closing rdeRegistrar:postalInfo
            }

            escrowPhone($xml, 'rdeRegistrar:voice', $registrar_contacts['voice'] ?? null);
            escrowPhone($xml, 'rdeRegistrar:fax', $registrar_contacts['fax'] ?? null);
            escrowOptional($xml, 'rdeRegistrar:email', $registrar['email']);
            
            escrowOptional($xml, 'rdeRegistrar:url', $registrar['url']);

            if (!empty($registrar['whois_server'])) {
                $xml->startElement('rdeRegistrar:whoisInfo');
                $xml->writeElement('rdeRegistrar:name', $registrar['whois_server']);
                $xml->writeElement('rdeRegistrar:url', $registrar['whois_server']);
                $xml->endElement();  // Closing rdeRegistrar:whoisInfo
            }

            $crDate = escrowParseDate($registrar['crdate']);
            $xml->writeElement('rdeRegistrar:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
            if (!empty($registrar['lastupdate'])) {
                $upDate = escrowParseDate($registrar['lastupdate']);
                $xml->writeElement('rdeRegistrar:upDate', $upDate->format("Y-m-d\\TH:i:s.v\\Z"));
            }

            $xml->endElement();  // Closing rdeRegistrar:registrar
        }

        if (isIDN($readDbh, $tld['id'])) {
            $script = guessIdnScriptName($tld['tld']);

            // Writing the idnTableRef section
            $xml->startElement('rdeIDN:idnTableRef');
            $xml->writeAttribute('id', $script);
            $xml->writeElement('rdeIDN:url', 'https://namingo.org');
            $xml->writeElement('rdeIDN:urlPolicy', 'https://namingo.org');
            $xml->endElement();  // Closing rdeIDN:idnTableRef
        }

        foreach ($nndns as $name) {
            $xml->startElement('rdeNNDN:NNDN');
            $xml->writeElement('rdeNNDN:aName', $name);
            // Reserved names are blocked, not withheld IDN variants (RFC 9022, 5.6).
            $xml->writeElement('rdeNNDN:nameState', 'blocked');
            $xml->endElement();
        }

        // Start of rdeEppParams:eppParams
        $xml->startElementNS('rdeEppParams', 'eppParams', null);

        // Add version and lang elements
        $xml->writeElementNS('rdeEppParams', 'version', null, '1.0');
        $xml->writeElementNS('rdeEppParams', 'lang', null, 'en');

        // Balance is an EPP object mapping, not an svcExtension.
        $config = json_decode(@file_get_contents('/opt/registry/epp/extensions.json'), true);
        $balanceNs = 'urn:ietf:params:xml:ns:epp:balance-0.4';
        $uriArray = [
            'urn:ietf:params:xml:ns:domain-1.0',
            'urn:ietf:params:xml:ns:contact-1.0',
            'urn:ietf:params:xml:ns:host-1.0'
        ];
        if (!is_array($config) || !empty($config[$balanceNs]['enabled'])) {
            $uriArray[] = $balanceNs;
        }

        foreach ($uriArray as $uri) {
            $xml->writeElementNS('rdeEppParams', 'objURI', null, $uri);
        }

        // Start of svcExtension
        $xml->startElementNS('rdeEppParams', 'svcExtension', null);
        $extUriArray = [];
        if (is_array($config)) {
            foreach ($config as $urn => $ext) {
                if ($urn !== $balanceNs && !empty($ext['enabled'])) {
                    $extUriArray[] = $urn;
                }
            }
        }
        // Fallback to hardcoded list if needed
        if (empty($extUriArray)) {
            $extUriArray = [
                'https://namingo.org/epp/identica-1.0',
                'urn:ietf:params:xml:ns:secDNS-1.1',
                'urn:ietf:params:xml:ns:rgp-1.0',
                'urn:ietf:params:xml:ns:launch-1.0',
                'urn:ietf:params:xml:ns:idn-1.0',
                'urn:ietf:params:xml:ns:epp:fee-1.0',
                'urn:ietf:params:xml:ns:mark-1.0',
                'urn:ietf:params:xml:ns:allocationToken-1.0'
            ];
        }
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

        if ($requiresRegistrant) {
            // rdePolicy:policy element
            $xml->startElementNS('rdePolicy', 'policy', null);
            $xml->writeAttribute('scope', '//rde:deposit/rde:contents/rdeDomain:domain');
            $xml->writeAttribute('element', 'rdeDomain:registrant');
            $xml->endElement();
        }
        
        // End the rde:contents element
        $xml->endElement(); // End rde:contents
    
        $xml->endElement();  // Closing the 'rde:deposit' element
        $deposit = $xml->outputMemory();

        // Define the base name without the extension
        $baseFileName = "{$tldname}_" . $snapshotDate . "_full_S1_R{$finalDepositId}";

        // XML and tar filenames
        $xmlFileName = $baseFileName . ".xml";
        $tarFileName = $baseFileName . ".tar";

        // Save the main XML file
        if (file_put_contents($c['escrow_deposit_path']."/".$xmlFileName, $deposit, LOCK_EX) === false) {
            throw new RuntimeException('Escrow XML write failed.');
        }

        // Compress the XML file using tar
        $phar = new PharData($c['escrow_deposit_path']."/".$tarFileName);
        $phar->addFile($c['escrow_deposit_path']."/".$xmlFileName, $xmlFileName);

        // Check if the $c['escrow_deleteXML'] variable is set to true and delete the original XML file
        if ($c['escrow_deleteXML']) {
            unlink($c['escrow_deposit_path']."/".$xmlFileName);
        }

        // Initialize the GnuPG object
        $gpg = new gnupg();
        $gpg->seterrormode(gnupg::ERROR_EXCEPTION);

        // Import public key (ICANN escrow key)
        $publicKeyData = file_get_contents($c['escrow_keyPath']);
        if ($publicKeyData === false || $publicKeyData === '') {
            throw new RuntimeException('Cannot read RDE encryption public key.');
        }
        $importResult = $gpg->import($publicKeyData);
        if (!$importResult || empty($importResult['fingerprint'])) {
            $log->error("Failed to import escrow public key: " . $c['escrow_keyPath']);
            exit(1);
        }
        $escrowFingerprint = $importResult['fingerprint'];

        // Add public key for encryption
        $gpg->addencryptkey($escrowFingerprint);

        // Read .tar data and encrypt it
        $fileData = file_get_contents($c['escrow_deposit_path'] . "/" . $tarFileName);
        if ($fileData === false) {
            throw new RuntimeException('Cannot read RDE tar file.');
        }
        $gpg->setarmor(false);
        $encryptedData = $gpg->encrypt($fileData);

        if (!$encryptedData) {
            $log->error("Encryption failed");
            exit(1);
        }

        // Write encrypted .ryde file
        $encryptedFilePath = $c['escrow_deposit_path'] . "/" . $baseFileName . ".ryde";
        if (file_put_contents($encryptedFilePath, $encryptedData) === false) {
            throw new RuntimeException('RDE encrypted file write failed.');
        }

        // Delete the original .tar file
        //unlink($c['escrow_deposit_path'] . "/" . $tarFileName);

        $gpg->clearencryptkeys();
        $gpg->clearsignkeys();
        $gpg->setsignmode(GNUPG_SIG_MODE_DETACH);
        $gpg->addsignkey($c['escrow_signing_fingerprint']);
        $gpg->setarmor(false);

        // Read encrypted file
        $encryptedFileContents = file_get_contents($encryptedFilePath);
        if ($encryptedFileContents === false) {
            throw new RuntimeException('Cannot read RDE encrypted file.');
        }
        $signature = $gpg->sign($encryptedFileContents);
        if (!is_string($signature) || $signature === '') {
            throw new RuntimeException('RDE signing failed.');
        }

        // Save .sig file
        $signaturePath = $c['escrow_deposit_path'] . '/' . $baseFileName . '.sig';
        if (file_put_contents($signaturePath, $signature) === false) {
            throw new RuntimeException('RDE signature write failed.');
        }

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
        $depositId = str_replace('-', '', $snapshotDate) . $paddedFinalDepositId;
        $reportXML->writeElement('rdeReport:id', $depositId);
        $reportXML->writeElement('rdeReport:version', '1');
        $reportXML->writeElement('rdeReport:rydeSpecEscrow', 'RFC8909');
        $reportXML->writeElement('rdeReport:rydeSpecMapping', 'RFC9022');
        $reportXML->writeElement('rdeReport:resend', $finalDepositId);
        $currentDateTime = new DateTime();
        $crDateWithMilliseconds = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
        $reportXML->writeElement('rdeReport:crDate', $crDateWithMilliseconds);
        $reportXML->writeElement('rdeReport:kind', 'FULL');
        $previousDayWatermark = $watermark;
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

        if (isIDN($readDbh, $tld['id'])) {
            $reportXML->startElement('rdeHeader:count');
            $reportXML->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeIDN-1.0');
            $reportXML->text('0');
            $reportXML->endElement();
        }

        $reportXML->startElement('rdeHeader:count');
        $reportXML->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeNNDN-1.0');
        $reportXML->text($NNDNCount);
        $reportXML->endElement();

        $reportXML->startElement('rdeHeader:count');
        $reportXML->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeEppParams-1.0');
        $reportXML->text('0');
        $reportXML->endElement();
        
        $reportXML->endElement(); // Closing rdeHeader:header
        $reportXML->endElement(); // Closing rdeReport:report

        $reps = $reportXML->outputMemory();

        // Save the report file
        $reportFilePath = $c['escrow_deposit_path']."/{$tldname}_" . $snapshotDate . "_full_R{$finalDepositId}.rep";
        if (file_put_contents($reportFilePath, $reps, LOCK_EX) === false) {
            throw new RuntimeException('RDE report write failed.');
        }
        
        $dayOfWeekToRunBRDA = $c['escrow_BRDAday'];
        $currentDayOfWeek = (new DateTime($snapshotDate, new DateTimeZone('UTC')))->format('l');

        if ($currentDayOfWeek === $dayOfWeekToRunBRDA) {
            // Start BRDA generation
            $xml = new XMLWriter();
            $xml->openMemory();
            $xml->startDocument('1.0', 'UTF-8');

            // Start the rde:deposit element with the necessary attributes
            $xml->startElementNS('rde', 'deposit', 'urn:ietf:params:xml:ns:rde-1.0');
            $xml->writeAttribute('type', 'FULL');
            $paddedFinalDepositId = str_pad($finalDepositId, 3, '0', STR_PAD_LEFT);
            $depositId = str_replace('-', '', $snapshotDate) . $paddedFinalDepositId;
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
            $previousDayWatermark = $watermark;
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
                'urn:ietf:params:xml:ns:rdeContact-1.0',
                'urn:ietf:params:xml:ns:rdeHost-1.0',
                'urn:ietf:params:xml:ns:rdeDomain-1.0',
                'urn:ietf:params:xml:ns:rdeRegistrar-1.0',
                'urn:ietf:params:xml:ns:rdeNNDN-1.0',
                'urn:ietf:params:xml:ns:rdeEppParams-1.0',
                'urn:ietf:params:xml:ns:rdePolicy-1.0'
            ];

            if (isIDN($readDbh, $tld['id'])) {
                $objURIs[] = 'urn:ietf:params:xml:ns:rdeIDN-1.0';
            }

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

            if (isIDN($readDbh, $tld['id'])) {
                $xml->startElement('rdeHeader:count');
                $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeIDN-1.0');
                $xml->text('1');
                $xml->endElement();
            }

            $xml->startElement('rdeHeader:count');
            $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeNNDN-1.0');
            $xml->text($NNDNCount);
            $xml->endElement();

            $xml->startElement('rdeHeader:count');
            $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdePolicy-1.0');
            $xml->text('1');
            $xml->endElement();

            $xml->startElement('rdeHeader:count');
            $xml->writeAttribute('uri', 'urn:ietf:params:xml:ns:rdeEppParams-1.0');
            $xml->text('1');
            $xml->endElement();

            $xml->endElement();  // Closing rdeHeader:header

            // Fetch domain details for this TLD
            $stmt = $readDbh->prepare("SELECT * FROM domain WHERE tldid = :tldid");
            $stmt->bindParam(':tldid', $tld['id']);
            $stmt->execute();
            $domains = $stmt->fetchAll();

            foreach ($domains as $domain) {
                $xml->startElement('rdeDomain:domain');
                $xml->writeElement('rdeDomain:name', $domain['name']);
                $xml->writeElement('rdeDomain:roid', 'D' . $domain['id'] . '-' . $roid);
                $xml->writeElement('rdeDomain:uName', $domain['name']);
                if (isIDN($readDbh, $tld['id'])) {
                    $script = guessIdnScriptName($tld['tld']);
                    $xml->writeElement('rdeDomain:idnTableId', $script);
                }

                // Fetch domain status
                escrowStatuses($xml, $readDbh, 'domain', $domain['id']);

                if ($domain['registrant'] !== null) {
                    $xml->writeElement('rdeDomain:registrant', escrowIdentifier($readDbh, $domain['registrant']));
                }

                // Fetch domain contacts
                $stmt = $readDbh->prepare("SELECT * FROM domain_contact_map WHERE domain_id = :domain_id;");
                $stmt->bindParam(':domain_id', $domain['id']);
                $stmt->execute();
                $domain_contacts = $stmt->fetchAll();
                foreach ($domain_contacts as $contact) {
                    $xml->startElement('rdeDomain:contact');
                    $xml->writeAttribute('type', $contact['type']);
                    $xml->text(escrowIdentifier($readDbh, $contact['contact_id']));
                    $xml->endElement();  // Closing rdeDomain:contact
                }

                // Fetch domain hosts and incorporate into XML
                $stmt = $readDbh->prepare("SELECT host.name FROM domain_host_map JOIN host ON domain_host_map.host_id = host.id WHERE domain_host_map.domain_id = :domain_id;");
                $stmt->bindParam(':domain_id', $domain['id']);
                $stmt->execute();
                $domain_hosts = $stmt->fetchAll();
                if (!empty($domain_hosts)) {
                    $xml->startElement('rdeDomain:ns');
                    foreach ($domain_hosts as $host) {
                        $xml->writeElement('domain:hostObj', $host['name']);
                    }
                    $xml->endElement();  // Closing rdeDomain:ns
                }

                $xml->writeElement('rdeDomain:clID', escrowClid($readDbh, $domain['clid']));
                $xml->writeElement('rdeDomain:crRr', escrowClid($readDbh, $domain['crid']));
                $crDate = escrowParseDate($domain['crdate']);
                $xml->writeElement('rdeDomain:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
                $exDate = escrowParseDate($domain['exdate']);
                $xml->writeElement('rdeDomain:exDate', $exDate->format("Y-m-d\\TH:i:s.v\\Z"));
                
                $xml->endElement();  // Closing rdeDomain:domain
            }

            // Fetch and incorporate registrar details
            $stmt = $readDbh->prepare("SELECT * FROM registrar");
            $stmt->execute();
            $registrars = $stmt->fetchAll();

            foreach ($registrars as $registrar) {
                $xml->startElement('rdeRegistrar:registrar');
                $xml->writeElement('rdeRegistrar:id', escrowClid($readDbh, $registrar['id']));
                $xml->writeElement('rdeRegistrar:name', escrowRequired($registrar['name'], 'registrar name'));
                if ($registrar['iana_id'] !== null && $registrar['iana_id'] !== '') {
                    if ((int)$registrar['iana_id'] <= 0) {
                        throw new RuntimeException('Escrow registrar IANA ID must be positive when present.');
                    }
                    $xml->writeElement('rdeRegistrar:gurid', $registrar['iana_id']);
                }

                // Fetch and incorporate registrar contact details
                $stmt = $readDbh->prepare("SELECT * FROM registrar_contact WHERE registrar_id = :registrar_id AND type = 'owner';");
                $stmt->bindParam(':registrar_id', $registrar['id']);
                $stmt->execute();
                $registrar_contacts = $stmt->fetch();

                if ($registrar_contacts) {
                    $xml->startElement('rdeRegistrar:postalInfo');
                    $xml->writeAttribute('type', 'int');
                    escrowAddress($xml, 'rdeRegistrar', $registrar_contacts);
                    $xml->endElement();  // Closing rdeRegistrar:postalInfo
                }

                escrowPhone($xml, 'rdeRegistrar:voice', $registrar_contacts['voice'] ?? null);
                escrowPhone($xml, 'rdeRegistrar:fax', $registrar_contacts['fax'] ?? null);
                escrowOptional($xml, 'rdeRegistrar:email', $registrar['email']);

                escrowOptional($xml, 'rdeRegistrar:url', $registrar['url']);

                if (!empty($registrar['whois_server'])) {
                    $xml->startElement('rdeRegistrar:whoisInfo');
                    $xml->writeElement('rdeRegistrar:name', $registrar['whois_server']);
                    $xml->writeElement('rdeRegistrar:url', $registrar['whois_server']);
                    $xml->endElement();  // Closing rdeRegistrar:whoisInfo
                }
                
                $crDate = escrowParseDate($registrar['crdate']);
                $xml->writeElement('rdeRegistrar:crDate', $crDate->format("Y-m-d\\TH:i:s.v\\Z"));
                if (!empty($registrar['lastupdate'])) {
                    $upDate = escrowParseDate($registrar['lastupdate']);
                    $xml->writeElement('rdeRegistrar:upDate', $upDate->format("Y-m-d\\TH:i:s.v\\Z"));
                }
                $xml->endElement();  // Closing rdeRegistrar:registrar
            }
            
            // End the rde:contents element
            $xml->endElement(); // End rde:contents
        
            $xml->endElement();  // Closing the 'rde:deposit' element
            $deposit = $xml->outputMemory();

            // Define the base name without the extension
            $baseFileNameBrda = "{$tldname}_" . $snapshotDate . "_brda_S1_R{$finalDepositId}";

            // XML and tar filenames
            $xmlFileName = $baseFileNameBrda . ".xml";
            $tarFileName = $baseFileNameBrda . ".tar";

            // Save the main XML file
            if (file_put_contents($c['escrow_deposit_path']."/".$xmlFileName, $deposit, LOCK_EX) === false) {
            throw new RuntimeException('Escrow XML write failed.');
        }

            // Compress the XML file using tar
            $phar = new PharData($c['escrow_deposit_path']."/".$tarFileName);
            $phar->addFile($c['escrow_deposit_path']."/".$xmlFileName, $xmlFileName);

            // Check if the $c['escrow_deleteXML'] variable is set to true and delete the original XML file
            if ($c['escrow_deleteXML']) {
                unlink($c['escrow_deposit_path']."/".$xmlFileName);
            }
            
            // Initialize the GnuPG object
            $gpg = new gnupg();
            $gpg->seterrormode(GNUPG::ERROR_EXCEPTION);
            $gpg->setarmor(false);

            // Import BRDA escrow public key
            $brdaPublicKeyData = file_get_contents($c['escrow_keyPath_brda']);
            if ($brdaPublicKeyData === false || $brdaPublicKeyData === '') {
                throw new RuntimeException('Cannot read BRDA encryption public key.');
            }
            $importResult = $gpg->import($brdaPublicKeyData);
            if (!$importResult || empty($importResult['fingerprint'])) {
                $log->error("Failed to import BRDA public key: " . $c['escrow_keyPath_brda']);
                exit(1);
            }
            $brdaFingerprint = $importResult['fingerprint'];

            // Add BRDA public key for encryption
            $gpg->addencryptkey($brdaFingerprint);

            // Encrypt the .tar contents
            $tarFilePath = $c['escrow_deposit_path'] . "/" . $tarFileName;
            $tarData = file_get_contents($tarFilePath);
            if ($tarData === false) {
                throw new RuntimeException('Cannot read BRDA tar file.');
            }
            $encryptedData = $gpg->encrypt($tarData);

            if (!$encryptedData) {
                $log->error("Encryption failed for BRDA file");
                exit(1);
            }

            // Save encrypted BRDA file (.ryde)
            $encryptedFilePathBrda = $c['escrow_deposit_path'] . "/" . $baseFileNameBrda . ".ryde";
            if (file_put_contents($encryptedFilePathBrda, $encryptedData) === false) {
            throw new RuntimeException('BRDA encrypted file write failed.');
        }

            // Delete the original .tar file
            //unlink($c['escrow_deposit_path'] . "/" . $tarFileName);

            $gpg->clearencryptkeys(); // ensure clean context
            $gpg->clearsignkeys();
            $gpg->setsignmode(GNUPG_SIG_MODE_DETACH);
            $gpg->addsignkey($c['escrow_signing_fingerprint']);
            $gpg->setarmor(false);

            // Sign the BRDA .ryde file
            $brdaFileData = file_get_contents($encryptedFilePathBrda);
            if ($brdaFileData === false) {
                throw new RuntimeException('Cannot read BRDA encrypted file.');
            }
            $signature = $gpg->sign($brdaFileData);
            if (!is_string($signature) || $signature === '') {
                throw new RuntimeException('BRDA signing failed.');
            }

            // Save .sig file
            $signatureFilePathBrda = $c['escrow_deposit_path'] . '/' . pathinfo($encryptedFilePathBrda, PATHINFO_FILENAME) . '.sig';
            if (file_put_contents($signatureFilePathBrda, $signature) === false) {
            throw new RuntimeException('BRDA signature write failed.');
        }

            // Optionally, delete the encrypted file if you don't need it anymore
            // unlink($encryptedFilePathBrda);
        }

        if ($c['escrow_RDEupload']) {
            // Connect to the SFTP server
            $sftp = new SFTP($c['escrow_sftp_host']);

            // Login with username and password
            if (!$sftp->login($c['escrow_sftp_username'], $c['escrow_sftp_password'])) {
                $log->error('RDE SFTP login failed.');
                throw new RuntimeException('RDE SFTP login failed.');
            }

            // Define the remote directory where you want to upload the files
            $remoteDir = $c['escrow_sftp_remotepath'];

            // Upload the files
            $filesToUpload = [
                $encryptedFilePath,
                $signaturePath
            ];

            foreach ($filesToUpload as $filePath) {
                $remoteFile = $remoteDir . basename($filePath);
                if (!$sftp->put($remoteFile, $filePath, SFTP::SOURCE_LOCAL_FILE)) {
                    $log->error('Failed to upload ' . basename($filePath));
                    throw new RuntimeException('Failed to upload ' . basename($filePath));
                } else {
                    $log->info('Successfully uploaded ' . basename($filePath));
                }
            }
            
            $reportFileData = file_get_contents($reportFilePath);
            if ($reportFileData === false) {
                throw new RuntimeException('Cannot read escrow report file.');
            }

            $ch = curl_init();

            // Set cURL options
            curl_setopt($ch, CURLOPT_URL, $c['escrow_report_url']);
            curl_setopt($ch, CURLOPT_USERPWD, $c['escrow_report_username'].':'.$c['escrow_report_password']);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $reportFileData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($reportFileData)
            ));

            $response = curl_exec($ch);

            if ($response === false) {
                throw new RuntimeException('RDE report upload failed: ' . curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode >= 200 && $httpCode < 300) {
                $log->info('RDE report uploaded successfully');
            } else {
                throw new RuntimeException('RDE report upload rejected. HTTP status: ' . $httpCode);
            }

        }
        
        if ($currentDayOfWeek === $dayOfWeekToRunBRDA) {
            if ($c['escrow_BRDAupload']) {
                // Connect to the SFTP server
                $sftp = new SFTP($c['brda_sftp_host']);

                // Login with username and password
                if (!$sftp->login($c['brda_sftp_username'], $c['brda_sftp_password'])) {
                    $log->error('BRDA SFTP login failed.');
                    throw new RuntimeException('BRDA SFTP login failed.');
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
                    throw new RuntimeException('Failed to upload ' . basename($filePath));
                    } else {
                        $log->info('Successfully uploaded ' . basename($filePath));
                    }
                }
            }
            
            $depositDate = $snapshotDate;
            $fileName = $c['escrow_deposit_path'] . "/" . $baseFileNameBrda . ".ryde";
            $fileFormat = 'XML';
            $encryptionMethod = 'GnuPG';
            $depositType = 'BRDA';
            $status = 'Deposited';
            $verificationStatus = 'Pending';

            // Prepare the INSERT statement
            $stmt = $dbh->prepare("INSERT INTO rde_escrow_deposits (deposit_id, deposit_date, file_name, file_format, encryption_method, deposit_type, status, verification_status, revision) VALUES (:deposit_id, :deposit_date, :file_name, :file_format, :encryption_method, :deposit_type, :status, :verification_status, :revision)");

            $previousDayFormatted = str_replace('-', '', $snapshotDate);
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
        
        $depositDate = $snapshotDate;
        $fileName = $c['escrow_deposit_path'] . "/" . $baseFileName . ".ryde";
        $fileFormat = 'XML';
        $encryptionMethod = 'GnuPG';
        $depositType = 'Full';
        $status = 'Deposited';
        $verificationStatus = 'Pending';

        // Prepare the INSERT statement
        $stmt = $dbh->prepare("INSERT INTO rde_escrow_deposits (deposit_id, deposit_date, file_name, file_format, encryption_method, deposit_type, status, verification_status, revision) VALUES (:deposit_id, :deposit_date, :file_name, :file_format, :encryption_method, :deposit_type, :status, :verification_status, :revision)");

        $previousDayFormatted = str_replace('-', '', $snapshotDate);
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
    $readDbh->commit();
    $log->info('job finished successfully.');
} catch (PDOException $e) {
    if (isset($readDbh) && $readDbh->inTransaction()) {
        $readDbh->rollBack();
    }
    $log->error('Database error: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    if (isset($readDbh) && $readDbh->inTransaction()) {
        $readDbh->rollBack();
    }
    $log->error('Error: ' . $e->getMessage());
    exit(1);
}

// Escrow XML helpers: preserve stored values and the RFC 9022 element order.
function escrowRequired($value, string $field): string
{
    if ($value === null || trim((string)$value) === '') {
        throw new RuntimeException('Escrow snapshot is missing required ' . $field . '.');
    }
    return (string)$value;
}

function escrowOptional(XMLWriter $xml, string $element, $value): void
{
    if ($value !== null && (string)$value !== '') {
        $xml->writeElement($element, (string)$value);
    }
}

function escrowClid(PDO $db, $id): string
{
    return escrowRequired($id === null ? null : getClid($db, (int)$id), 'registrar reference');
}

function escrowIdentifier(PDO $db, $id): string
{
    return escrowRequired($id === null ? null : getIdentifier($db, (int)$id), 'contact reference');
}

function escrowParseDate($value): DateTime
{
    $value = escrowRequired($value, 'date');
    $format = str_contains($value, '.') ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s';
    $date = DateTime::createFromFormat($format, $value, new DateTimeZone('UTC'));
    $errors = DateTime::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
        throw new RuntimeException('Invalid date in escrow snapshot.');
    }
    return $date;
}

function escrowDate(XMLWriter $xml, string $element, $value): void
{
    if ($value !== null && $value !== '') {
        $xml->writeElement($element, escrowParseDate($value)->format('Y-m-d\TH:i:s.v\Z'));
    }
}

function escrowStatuses(XMLWriter $xml, PDO $db, string $object, $id): void
{
    $limit = match ($object) { 'domain' => 11, 'host', 'contact' => 7 };
    $stmt = $db->prepare("SELECT status FROM {$object}_status WHERE {$object}_id = ? ORDER BY status");
    $stmt->execute([$id]);
    $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($statuses) > $limit) {
        throw new RuntimeException('Too many ' . $object . ' statuses in escrow snapshot.');
    }
    foreach ($statuses ?: ['ok'] as $status) {
        $xml->startElement('rde' . ucfirst($object) . ':status');
        $xml->writeAttribute('s', $status);
        $xml->endElement();
    }
}

function escrowAddress(XMLWriter $xml, string $prefix, array $address): void
{
    $xml->startElement($prefix . ':addr');
    foreach (['street1', 'street2', 'street3'] as $field) {
        escrowOptional($xml, $prefix . ':street', $address[$field]);
    }
    $xml->writeElement($prefix . ':city', escrowRequired($address['city'], 'postal city'));
    escrowOptional($xml, $prefix . ':sp', $address['sp']);
    escrowOptional($xml, $prefix . ':pc', $address['pc']);
    $xml->writeElement($prefix . ':cc', escrowRequired($address['cc'], 'postal country'));
    $xml->endElement();
}

function escrowPhone(XMLWriter $xml, string $element, $value, $extension = null): void
{
    if ($value === null || $value === '') {
        if ($extension !== null && $extension !== '') {
            throw new RuntimeException('Escrow telephone extension has no telephone number.');
        }
        return;
    }
    if (!preg_match('/\A\+[0-9]{1,3}\.[0-9]+\z/', (string)$value)) {
        throw new RuntimeException('Invalid RFC 5733 telephone in escrow snapshot (' . $element . ').');
    }
    $xml->startElement($element);
    if ($extension !== null && $extension !== '') {
        $xml->writeAttribute('x', (string)$extension);
    }
    $xml->text((string)$value);
    $xml->endElement();
}

function escrowUpdate(XMLWriter $xml, PDO $db, string $prefix, array $row): void
{
    if ($row['upid'] !== null) {
        $xml->writeElement($prefix . ':upRr', escrowClid($db, $row['upid']));
    }
    escrowDate($xml, $prefix . ':upDate', $row['lastupdate']);
}

function escrowTransfer(XMLWriter $xml, PDO $db, string $prefix, array $row): void
{
    escrowDate($xml, $prefix . ':trDate', $row['trdate']);
    if ($row['trstatus'] === null || $row['trstatus'] === '') {
        return;
    }
    $xml->startElement($prefix . ':trnData');
    $xml->writeElement($prefix . ':trStatus', $row['trstatus']);
    $xml->writeElement($prefix . ':reRr', escrowClid($db, $row['reid']));
    $xml->writeElement($prefix . ':reDate', escrowParseDate($row['redate'])->format('Y-m-d\TH:i:s.v\Z'));
    $xml->writeElement($prefix . ':acRr', escrowClid($db, $row['acid']));
    $xml->writeElement($prefix . ':acDate', escrowParseDate($row['acdate'])->format('Y-m-d\TH:i:s.v\Z'));
    if ($prefix === 'rdeDomain') {
        escrowDate($xml, $prefix . ':exDate', $row['transfer_exdate']);
    }
    $xml->endElement();
}

function escrowDnssec(XMLWriter $xml, PDO $db, $domainId): void
{
    $stmt = $db->prepare('SELECT * FROM secdns WHERE domain_id = ? ORDER BY id');
    $stmt->execute([$domainId]);
    $records = $stmt->fetchAll();
    if (!$records) {
        return;
    }
    $interface = $records[0]['interface'];
    if (!in_array($interface, ['dsData', 'keyData'], true)) {
        throw new RuntimeException('Unknown DNSSEC interface in escrow snapshot.');
    }
    $xml->startElement('rdeDomain:secDNS');
    escrowOptional($xml, 'secDNS:maxSigLife', $records[0]['maxsiglife']);
    foreach ($records as $record) {
        if ($record['interface'] !== $interface || $record['maxsiglife'] !== $records[0]['maxsiglife']) {
            throw new RuntimeException('Inconsistent DNSSEC interface or maxSigLife in escrow snapshot.');
        }
        if ($interface === 'dsData') {
            $xml->startElement('secDNS:dsData');
            foreach (['keyTag' => 'keytag', 'alg' => 'alg', 'digestType' => 'digesttype', 'digest' => 'digest'] as $tag => $column) {
                $xml->writeElement('secDNS:' . $tag, escrowRequired($record[$column], 'DNSSEC ' . $tag));
            }
        }
        // DS records may also carry the original DNSKEY.
        if ($interface === 'keyData' || ($record['pubkey'] !== null && $record['pubkey'] !== '')) {
            $xml->startElement('secDNS:keyData');
            foreach (['flags' => 'flags', 'protocol' => 'protocol', 'alg' => 'keydata_alg', 'pubKey' => 'pubkey'] as $tag => $column) {
                $xml->writeElement('secDNS:' . $tag, escrowRequired($record[$column], 'DNSKEY ' . $tag));
            }
            $xml->endElement();
        }
        if ($interface === 'dsData') {
            $xml->endElement();
        }
    }
    $xml->endElement();
}

function escrowDisclosure(XMLWriter $xml, array $contact, array $postalRows): void
{
    // flag=0 lists withheld fields; unlisted fields remain disclosed.
    $xml->startElement('rdeContact:disclose');
    $xml->writeAttribute('flag', '0');
    foreach (['name', 'org', 'addr'] as $field) {
        foreach ($postalRows as $postal) {
            if ((string)$postal['disclose_' . $field . '_' . $postal['type']] === '0') {
                $xml->startElement('contact:' . $field);
                $xml->writeAttribute('type', $postal['type']);
                $xml->endElement();
            }
        }
    }
    foreach (['voice', 'fax', 'email'] as $field) {
        if ((string)$contact['disclose_' . $field] === '0') {
            $xml->writeElement('contact:' . $field);
        }
    }
    $xml->endElement();
}

function escrowNndns(PDO $db, string $tld): array
{
    $names = [];
    $registered = $db->prepare('SELECT 1 FROM domain WHERE name = ?');
    foreach ($db->query('SELECT name FROM reserved_domain_names ORDER BY id') as $row) {
        // Namingo supports both global reserved labels and explicit FQDNs.
        $name = strtolower(escrowRequired($row['name'], 'reserved name'));
        if (!str_contains($name, '.')) {
            $name .= '.' . $tld;
        }
        if (!str_ends_with($name, '.' . $tld)) {
            continue;
        }
        if (strlen($name) > 253 || !preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/', $name)) {
            throw new RuntimeException('Reserved name is not an A-label FQDN in escrow snapshot.');
        }
        $registered->execute([$name]);
        if (!$registered->fetchColumn()) {
            $names[$name] = $name; // An existing domain must not also be an NNDN.
        }
    }
    return array_values($names);
}
