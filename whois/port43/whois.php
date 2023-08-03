<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

// Create a Swoole TCP server
$server = new Swoole\Server('0.0.0.0', 43);

// Register a callback to handle incoming connections
$server->on('connect', function ($server, $fd) {
    echo "Client connected: {$fd}";
});

// Register a callback to handle incoming requests
$server->on('receive', function ($server, $fd, $reactorId, $data) {
    // Validate and sanitize the domain name
    $domain = trim($data);
    if (!$domain) {
        $server->send($fd, "please enter a domain name");
        return;
    }
    if (strlen($domain) > 68) {
        $server->send($fd, "domain name is too long");
        return;
    }
    $domain = strtoupper($domain);
    if (preg_match("/[^A-Z0-9\.\-]/", $domain)) {
        $server->send($fd, "domain name invalid format");
        return;
    }
    if (preg_match("/(^-|^\.|-\.|\.-|--|\.\.|-$|\.$)/", $domain)) {
        $server->send($fd, "domain name invalid format");
        return;
    }
    if (!preg_match("/^[A-Z0-9-]+\.(XX|COM\.XX|ORG\.XX|INFO\.XX|PRO\.XX)$/", $domain)) {
        $server->send($fd, "please search only XX domains at least 2 letters");
        return;
    }

    // Connect to the database
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=registry', 'registry-select', 'EPPRegistrySELECT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        $server->send($fd, "Error connecting to database");
        return;
    }
	
    // Perform the WHOIS lookup
	try {
		$query = "SELECT *,
			DATE_FORMAT(`crdate`, '%d-%b-%Y %T') AS `crdate`,
			DATE_FORMAT(`update`, '%d-%b-%Y %T') AS `update`,
			DATE_FORMAT(`exdate`, '%d-%b-%Y %T') AS `exdate`
			FROM `registry`.`domain` WHERE `name` = :domain";
		$stmt = $pdo->prepare($query);
		$stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
		$stmt->execute();

		if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
			if ($f['crdate']) $f['crdate'] .= ' UTC';
			if ($f['update']) $f['update'] .= ' UTC';
			if ($f['exdate']) $f['exdate'] .= ' UTC';

			$query2 = "SELECT `tld` FROM `domain_tld` WHERE `id` = :tldid";
			$stmt2 = $pdo->prepare($query2);
			$stmt2->bindParam(':tldid', $f['tldid'], PDO::PARAM_INT);
			$stmt2->execute();

			$tld = $stmt2->fetch(PDO::FETCH_ASSOC);
			
			$res = "Access to {$tld['tld']} WHOIS information is provided to assist persons in"
			."\ndetermining the contents of a domain name registration record in the"
			."\nDomain Name Registry registry database. The data in this record is provided by"
			."\nDomain Name Registry for informational purposes only, and Domain Name Registry does not"
			."\nguarantee its accuracy.  This service is intended only for query-based"
			."\naccess. You agree that you will use this data only for lawful purposes"
			."\nand that, under no circumstances will you use this data to: (a) allow,"
			."\nenable, or otherwise support the transmission by e-mail, telephone, or"
			."\nfacsimile of mass unsolicited, commercial advertising or solicitations"
			."\nto entities other than the data recipient's own existing customers; or"
			."\n(b) enable high volume, automated, electronic processes that send"
			."\nqueries or data to the systems of Registry Operator, a Registrar, or"
			."\nNIC except as reasonably necessary to register domain names or"
			."\nmodify existing registrations. All rights reserved. Domain Name Registry reserves"
			."\nthe right to modify these terms at any time. By submitting this query,"
			."\nyou agree to abide by this policy."
			."\n";
				
			$query3 = "SELECT `name`,`whois_server`,`url`,`abuse_email`,`abuse_phone` FROM `registrar` WHERE `id` = :clid";
			$stmt3 = $pdo->prepare($query3);
			$stmt3->bindParam(':clid', $f['clid'], PDO::PARAM_INT);
			$stmt3->execute();

			$clidF = $stmt3->fetch(PDO::FETCH_ASSOC);

			$res .= ""
				."\nRegistry Domain ID:".$f['id']
				."\nDomain Name:".strtoupper($f['name'])
				."\nCreated On:".$f['crdate']
				."\nLast Updated On:".$f['update']
				."\nExpiration Date:".$f['exdate']
				."\nRegistrar:".$clidF['name']
				."\nRegistrar WHOIS Server:".$clidF['whois_server']
				."\nRegistrar URL:".$clidF['url']
				."\nRegistrar Abuse Contact Email:".$clidF['abuse_email']
				."\nRegistrar Abuse Contact Phone:".$clidF['abuse_phone'];
					
			$query4 = "SELECT `status` FROM `domain_status` WHERE `domain_id` = :domain_id";
			$stmt4 = $pdo->prepare($query4);
			$stmt4->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
			$stmt4->execute();

			while ($f2 = $stmt4->fetch(PDO::FETCH_ASSOC)) {
				$res .= "\nStatus:".$f2['status'];
			}

			$query5 = "SELECT contact.identifier,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.voice_x,contact.fax,contact.fax_x,contact.email
				FROM contact,contact_postalInfo WHERE contact.id=:registrant AND contact_postalInfo.contact_id=contact.id";
			$stmt5 = $pdo->prepare($query5);
			$stmt5->bindParam(':registrant', $f['registrant'], PDO::PARAM_INT);
			$stmt5->execute();

			$f2 = $stmt5->fetch(PDO::FETCH_ASSOC);
			$res .= "\nRegistry Registrant ID:".$f2['identifier']
				."\nRegistrant Name:".$f2['name']
				."\nRegistrant Organization:".$f2['org']
				."\nRegistrant Street1:".$f2['street1']
				."\nRegistrant Street2:".$f2['street2']
				."\nRegistrant Street3:".$f2['street3']
				."\nRegistrant City:".$f2['city']
				."\nRegistrant State/Province:".$f2['sp']
				."\nRegistrant Postal Code:".$f2['pc']
				."\nRegistrant Country:".$f2['cc']
				."\nRegistrant Phone:".$f2['voice']
				."\nRegistrant Phone Ext.:".$f2['voice_x']
				."\nRegistrant FAX:".$f2['fax']
				."\nRegistrant FAX Ext.:".$f2['fax_x']
				."\nRegistrant Email:".$f2['email'];

			$query6 = "SELECT contact.identifier,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.voice_x,contact.fax,contact.fax_x,contact.email
				FROM domain_contact_map,contact,contact_postalInfo WHERE domain_contact_map.domain_id=:domain_id AND domain_contact_map.type='admin' AND domain_contact_map.contact_id=contact.id AND domain_contact_map.contact_id=contact_postalInfo.contact_id";
			$stmt6 = $pdo->prepare($query6);
			$stmt6->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
			$stmt6->execute();

			$f2 = $stmt6->fetch(PDO::FETCH_ASSOC);
			$res .= "\nRegistry Admin ID:".$f2['identifier']
				."\nAdmin Name:".$f2['name']
				."\nAdmin Organization:".$f2['org']
				."\nAdmin Street1:".$f2['street1']
				."\nAdmin Street2:".$f2['street2']
				."\nAdmin Street3:".$f2['street3']
				."\nAdmin City:".$f2['city']
				."\nAdmin State/Province:".$f2['sp']
				."\nAdmin Postal Code:".$f2['pc']
				."\nAdmin Country:".$f2['cc']
				."\nAdmin Phone:".$f2['voice']
				."\nAdmin Phone Ext.:".$f2['voice_x']
				."\nAdmin FAX:".$f2['fax']
				."\nAdmin FAX Ext.:".$f2['fax_x']
				."\nAdmin Email:".$f2['email'];

			$query7 = "SELECT contact.identifier,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.voice_x,contact.fax,contact.fax_x,contact.email
				FROM domain_contact_map,contact,contact_postalInfo WHERE domain_contact_map.domain_id=:domain_id AND domain_contact_map.type='billing' AND domain_contact_map.contact_id=contact.id AND domain_contact_map.contact_id=contact_postalInfo.contact_id";
			$stmt7 = $pdo->prepare($query7);
			$stmt7->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
			$stmt7->execute();

			$f2 = $stmt7->fetch(PDO::FETCH_ASSOC);
			$res .= "\nRegistry Billing ID:".$f2['identifier']
				."\nBilling Name:".$f2['name']
				."\nBilling Organization:".$f2['org']
				."\nBilling Street1:".$f2['street1']
				."\nBilling Street2:".$f2['street2']
				."\nBilling Street3:".$f2['street3']
				."\nBilling City:".$f2['city']
				."\nBilling State/Province:".$f2['sp']
				."\nBilling Postal Code:".$f2['pc']
				."\nBilling Country:".$f2['cc']
				."\nBilling Phone:".$f2['voice']
				."\nBilling Phone Ext.:".$f2['voice_x']
				."\nBilling FAX:".$f2['fax']
				."\nBilling FAX Ext.:".$f2['fax_x']
				."\nBilling Email:".$f2['email'];

			$query8 = "SELECT contact.identifier,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.voice_x,contact.fax,contact.fax_x,contact.email
				FROM domain_contact_map,contact,contact_postalInfo WHERE domain_contact_map.domain_id=:domain_id AND domain_contact_map.type='tech' AND domain_contact_map.contact_id=contact.id AND domain_contact_map.contact_id=contact_postalInfo.contact_id";
			$stmt8 = $pdo->prepare($query8);
			$stmt8->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
			$stmt8->execute();

			$f2 = $stmt8->fetch(PDO::FETCH_ASSOC);
			$res .= "\nRegistry Tech ID:".$f2['identifier']
				."\nTech Name:".$f2['name']
				."\nTech Organization:".$f2['org']
				."\nTech Street1:".$f2['street1']
				."\nTech Street2:".$f2['street2']
				."\nTech Street3:".$f2['street3']
				."\nTech City:".$f2['city']
				."\nTech State/Province:".$f2['sp']
				."\nTech Postal Code:".$f2['pc']
				."\nTech Country:".$f2['cc']
				."\nTech Phone:".$f2['voice']
				."\nTech Phone Ext.:".$f2['voice_x']
				."\nTech FAX:".$f2['fax']
				."\nTech FAX Ext.:".$f2['fax_x']
				."\nTech Email:".$f2['email'];

			$query9 = "SELECT `name` FROM `domain_host_map`,`host` WHERE `domain_host_map`.`domain_id` = :domain_id AND `domain_host_map`.`host_id` = `host`.`id`";
			$stmt9 = $pdo->prepare($query9);
			$stmt9->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
			$stmt9->execute();

			for ($i=0; $i<13; $i++) {
				$f2 = $stmt9->fetch(PDO::FETCH_ASSOC);
				$res .= "\nName Server:".$f2['name'];
			}

			$res .= "\nDNSSEC:Unsigned";
			$res .= "\n\n";
			$server->send($fd, $res . "");

			if ($fp = @fopen("/var/log/whois/whois.log",'a')) {
				fwrite($fp,date('Y-m-d H:i:s')."\t-\t".getenv('REMOTE_ADDR')."\t-\t".$domain."\n");
				fclose($fp);
			}
		} else {
			//NOT FOUND or No match for;
			$server->send($fd, "NOT FOUND");

			if ($fp = @fopen("/var/log/whois/whois_not_found.log",'a')) {
				fwrite($fp,date('Y-m-d H:i:s')."\t-\t".getenv('REMOTE_ADDR')."\t-\t".$domain."\n");
				fclose($fp);
			}
		}
	} catch (PDOException $e) {
        $server->send($fd, "Error connecting to the whois database");
        return;
	}

    // Close the connection
    $pdo = null;
});

// Register a callback to handle client disconnections
$server->on('close', function ($server, $fd) {
    echo "Client disconnected: {$fd}
";
});

// Start the server
$server->start();
?>
