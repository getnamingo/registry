<?php

function processDomainRenew($conn, $db, $xml, $clid, $database_type, $trans) {
    $domainName = (string) $xml->command->renew->children('urn:ietf:params:xml:ns:domain-1.0')->renew->name;
    $curExpDate = (string) $xml->command->renew->children('urn:ietf:params:xml:ns:domain-1.0')->renew->curExpDate;
    $periodElements = $xml->xpath("//domain:renew/domain:period");
    $periodElement = $periodElements[0];
    $period = (int) $periodElement;
    $periodUnit = (string) $periodElement['unit'];
    $clTRID = (string) $xml->command->clTRID;

    if (!$domainName) {
        sendEppError($conn, 2003, 'Pleae provide domain name', $clTRID);
        return;
    }

    if ($period) {
        if ($period < 1 || $period > 99) {
            sendEppError($conn, 2004, "domain:period minLength value='1', maxLength value='99'");
            return;
        }
    }

    if ($periodUnit) {
        if (!preg_match("/^(m|y)$/", $periodUnit)) {
            sendEppError($conn, 2004, "domain:period unit m|y");
            return;
        }
    }
    
    $stmt = $db->prepare("SELECT id FROM registrar WHERE clid = :clid LIMIT 1");
    $stmt->bindParam(':clid', $clid, PDO::PARAM_STR);
    $stmt->execute();
    $clid = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT id, tldid, exdate, clid FROM domain WHERE name = :domainName LIMIT 1");
    $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
    $stmt->execute();
    $domainData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$domainData) {
        sendEppError($conn, 2303, 'Domain does not exist', $clTRID);
        return;
    }

    if ($clid['id'] != $domainData['clid']) {
        sendEppError($conn, 2201, 'It belongs to another registrar', $clTRID);
        return;
    }
	
    // The domain name must not be subject to clientRenewProhibited, serverRenewProhibited.
    $stmt = $db->prepare("SELECT status FROM domain_status WHERE domain_id = :domainId");
    $stmt->bindParam(':domainId', $domainData['id'], PDO::PARAM_INT);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        if (preg_match('/.*(RenewProhibited)$/', $status) || preg_match('/^pending/', $status)) {
            sendEppError($conn, 2304, 'It has a status that does not allow renew, first change the status', $clTRID);
            return;
        }
    }

    $expiration_date = explode(" ", $domainData['exdate'])[0];  // remove time, keep only date

    if ($curExpDate !== $expiration_date) {
        sendEppError($conn, 2306, 'The expiration date does not match', $clTRID);
        return;
    }

    $date_add = 0;
    if ($periodUnit === 'y') {
        $date_add = ($period * 12);
    } elseif ($periodUnit === 'm') {
        $date_add = $period;
    }

    if ($date_add > 0) {
        // The number of units available MAY be subject to limits imposed by the server.
        if (!in_array($date_add, [12, 24, 36, 48, 60, 72, 84, 96, 108, 120])) {
            sendEppError($conn, 2306, 'Not less than 1 year and not more than 10', $clTRID);
            return;
        }

        $after_10_years = $db->query("SELECT YEAR(DATE_ADD(CURDATE(),INTERVAL 10 YEAR))")->fetchColumn();
        $stmt = $db->prepare("SELECT YEAR(DATE_ADD(:exdate, INTERVAL :date_add MONTH))");
        $stmt->bindParam(':exdate', $domainData['exdate'], PDO::PARAM_STR);
        $stmt->bindParam(':date_add', $date_add, PDO::PARAM_INT);
        $stmt->execute();
        $after_renew = $stmt->fetchColumn();

        // Domains can be renewed at any time, but the expire date cannot be more than 10 years in the future.
        if ($after_renew > $after_10_years) {
            sendEppError($conn, 2306, 'Domains can be renewed at any time, but the expire date cannot be more than 10 years in the future', $clTRID);
            return;
        }

        // Check registrar account balance
        $stmt = $db->prepare("SELECT accountBalance, creditLimit FROM registrar WHERE id = :registrarId LIMIT 1");
        $stmt->bindParam(':registrarId', $clid['id'], PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $registrar_balance = $row['accountBalance'];
        $creditLimit = $row['creditLimit'];

        $columnName = "m$date_add";
        $stmt = $db->prepare("SELECT $columnName FROM domain_price WHERE tldid = :tldid AND command = 'renew' LIMIT 1");
        $stmt->bindParam(':tldid', $domainData['tldid'], PDO::PARAM_INT);
        $stmt->execute();
        $price = $stmt->fetchColumn();

        if (($registrar_balance + $creditLimit) < $price) {
            sendEppError($conn, 2104, 'There is no money on the account to renew', $clTRID);
            return;
        }
		
        $stmt = $db->prepare("SELECT exdate FROM domain WHERE id = :domain_id LIMIT 1");
        $stmt->bindParam(':domain_id', $domainData['id'], PDO::PARAM_INT);
        $stmt->execute();
        $from = $stmt->fetchColumn();

        $rgpstatus = 'renewPeriod';
        $stmt = $db->prepare("UPDATE domain SET exdate = DATE_ADD(exdate, INTERVAL :date_add MONTH), rgpstatus = :rgpstatus, renewPeriod = :renewPeriod, renewedDate = CURRENT_TIMESTAMP WHERE id = :domain_id");
        $stmt->bindParam(':date_add', $date_add, PDO::PARAM_INT);
        $stmt->bindParam(':rgpstatus', $rgpstatus, PDO::PARAM_STR);
        $stmt->bindParam(':renewPeriod', $date_add, PDO::PARAM_INT);
        $stmt->bindParam(':domain_id', $domainData['id'], PDO::PARAM_INT);
        $stmt->execute();

        // Error check
        $errorInfo = $stmt->errorInfo();
        if (isset($errorInfo[2])) {
            sendEppError($conn, 2400, 'It was not renewed successfully, something is wrong', $clTRID);
            return;
        } else {
            // Update registrar's account balance:
            $stmt = $db->prepare("UPDATE registrar SET accountBalance = (accountBalance - :price) WHERE id = :registrar_id");
            $stmt->bindParam(':price', $price, PDO::PARAM_INT);
            $stmt->bindParam(':registrar_id', $clid['id'], PDO::PARAM_INT);
            $stmt->execute();

            // Insert into payment_history:
			$description = "renew domain $domainName for period $date_add MONTH";
			$negative_price = -$price;
            $stmt = $db->prepare("INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (:registrar_id, CURRENT_TIMESTAMP, :description, :amount)");
            $stmt->bindParam(':registrar_id', $clid['id'], PDO::PARAM_INT);
            $stmt->bindParam(':description', $description, PDO::PARAM_STR);
            $stmt->bindParam(':amount', $negative_price, PDO::PARAM_INT);
            $stmt->execute();

            // Fetch `exdate`:
            $stmt = $db->prepare("SELECT exdate FROM domain WHERE id = :domain_id LIMIT 1");
            $stmt->bindParam(':domain_id', $domainData['id'], PDO::PARAM_INT);
            $stmt->execute();
            $to = $stmt->fetchColumn();

            // Insert into statement:
            if ($database_type === "mysql") {
                $stmt = $db->prepare("INSERT INTO statement (registrar_id, date, command, domain_name, length_in_months, `from`, `to`, amount) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?)");
            } elseif ($database_type === "pgsql") {
                $stmt = $db->prepare('INSERT INTO statement (registrar_id, date, command, domain_name, length_in_months, "from", "to", amount) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?)');
            } else {
                throw new Exception("Unsupported database type: $database_type");
            }
            $stmt->execute([$clid['id'], 'renew', $domainName, $date_add, $from, $to, $price]);
        }
    }
	
    // Fetch exdate for the given domain name
    $stmt = $db->prepare("SELECT exdate FROM domain WHERE name = :name LIMIT 1");
    $stmt->bindParam(':name', $domainName, PDO::PARAM_STR);
    $stmt->execute();
    $exdateUpdated = $stmt->fetchColumn();

    // Check for an existing entry in `statistics` for the current date
    $stmt = $db->prepare("SELECT id FROM statistics WHERE date = CURDATE()");
    $stmt->execute();
    $curdate_id = $stmt->fetchColumn();

    // If there's no entry for the current date, insert one
    if (!$curdate_id) {
        $stmt = $db->prepare("INSERT IGNORE INTO statistics (date) VALUES(CURDATE())");
        $stmt->execute();
    }

    // Update the `renewed_domains` count for the current date
    $stmt = $db->prepare("UPDATE statistics SET renewed_domains = renewed_domains + 1 WHERE date = CURDATE()");
    $stmt->execute();

    $svTRID = generateSvTRID();
    $response = [
        'command' => 'renew_domain',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'name' => $domainName,
        'exDate' => $exdateUpdated,
        'clTRID' => $clTRID,
        'svTRID' => $svTRID,
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    updateTransaction($db, 'renew', 'domain', $domainName, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}