<?php

function processDomainRenew($conn, $db, $xml, $clid, $database_type, $trans) {
    $statementPeriodColumns = $database_type === 'pgsql' ? '"fromS", "toS"' : 'fromS, toS';
    $domainName = (string) $xml->command->renew->children('urn:ietf:params:xml:ns:domain-1.0')->renew->name;
    $curExpDate = (string) $xml->command->renew->children('urn:ietf:params:xml:ns:domain-1.0')->renew->curExpDate;
    $periodElements = $xml->xpath("//domain:renew/domain:period");
    if (!empty($periodElements)) {
        $periodElement = $periodElements[0];
        $period = (int) $periodElement;
        $periodUnit = (string) $periodElement['unit'];
    } else {
        $periodElement = null;
        $period = null;
        $periodUnit = null;
    }
    $clTRID = (string) $xml->command->clTRID;

    if (!$domainName) {
        sendEppError($conn, $db, 2003, 'Please provide domain name', $clTRID, $trans);
        return;
    }
    if (!$curExpDate) {
        sendEppError($conn, $db, 2003, 'Missing curExpDate', $clTRID, $trans);
        return;
    }

    $invalid_label = validate_label($domainName, $db);
    if ($invalid_label || !filter_var($domainName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        sendEppError($conn, $db, 2005, 'Invalid domain name', $clTRID, $trans);
        return;
    }

    $curExpDateObj = DateTime::createFromFormat('Y-m-d', $curExpDate);
    $curExpDateErrors = DateTime::getLastErrors() ?: ['warning_count'=>0,'error_count'=>0];
    if (!$curExpDateObj || $curExpDateErrors['warning_count'] > 0 || $curExpDateErrors['error_count'] > 0) {
        sendEppError($conn, $db, 2005, 'Invalid curExpDate format, expected YYYY-MM-DD', $clTRID, $trans);
        return;
    }
    $curExpDate = $curExpDateObj->format('Y-m-d');

    if ($period !== null) {
        if ($period < 1 || $period > 99) {
            sendEppError($conn, $db, 2004, "domain:period minLength value='1', maxLength value='99'", $clTRID, $trans);
            return;
        }
    } else {
        $period = 1;
    }

    if ($periodUnit !== null && $periodUnit !== '') {
        if (!preg_match('/^(m|y)$/i', $periodUnit)) {
            sendEppError($conn, $db, 2004, "domain:period unit m|y", $clTRID, $trans);
            return;
        }
    } else {
        $periodUnit = 'y';
    }
    $periodUnit = strtolower($periodUnit);

    $clid = getClid($db, $clid);

    $stmt = $db->prepare("SELECT id, name, tldid, exdate, clid FROM domain WHERE name = :domainName LIMIT 1");
    $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
    $stmt->execute();
    $domainData = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    if (!$domainData) {
        sendEppError($conn, $db, 2303, 'Domain does not exist', $clTRID, $trans);
        return;
    }

    if ($clid != $domainData['clid']) {
        sendEppError($conn, $db, 2201, 'It belongs to another registrar', $clTRID, $trans);
        return;
    }

    $stmt = $db->prepare("SELECT status FROM domain_status WHERE domain_id = :domainId");
    $stmt->bindParam(':domainId', $domainData['id'], PDO::PARAM_INT);
    $stmt->execute();
    $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt->closeCursor();

    foreach ($statuses as $status) {
        if (preg_match('/RenewProhibited$/', $status) || preg_match('/^pending/', $status)) {
            sendEppError($conn, $db, 2304, 'It has a status that does not allow renew, first change the status', $clTRID, $trans);
            return;
        }
    }

    $expiration_date = explode(" ", $domainData['exdate'])[0];
    if ($curExpDate !== $expiration_date) {
        sendEppError($conn, $db, 2306, 'The expiration date does not match', $clTRID, $trans);
        return;
    }

    $date_add = 0;
    if ($periodUnit === 'y') {
        $date_add = $period * 12;
    } elseif ($periodUnit === 'm') {
        $date_add = $period;
    }

    if ($date_add > 0) {
        if (!in_array($date_add, [12, 24, 36, 48, 60, 72, 84, 96, 108, 120])) {
            sendEppError($conn, $db, 2306, 'Not less than 1 year and not more than 10', $clTRID, $trans);
            return;
        }

        $after_10_years = (int) (new DateTime('+10 years'))->format('Y');
        $renewedDate = new DateTime($domainData['exdate']);
        $renewedDate->modify("+$date_add months");
        $after_renew = (int) $renewedDate->format('Y');

        // Domains can be renewed at any time, but the expire date cannot be more than 10 years in the future.
        if ($after_renew > $after_10_years) {
            sendEppError($conn, $db, 2306, 'Domains can be renewed at any time, but the expire date cannot be more than 10 years in the future', $clTRID, $trans);
            return;
        }

        try {
            $db->beginTransaction();

            // Serialize renewals of the same domain and re-check mutable state.
            $stmt = $db->prepare(
                "SELECT id, name, tldid, exdate, clid
                 FROM domain
                 WHERE id = :domain_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([':domain_id' => $domainData['id']]);
            $lockedDomain = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!$lockedDomain) {
                $db->rollBack();
                sendEppError($conn, $db, 2303, 'Domain does not exist', $clTRID, $trans);
                return;
            }

            if ((int)$lockedDomain['clid'] !== (int)$clid) {
                $db->rollBack();
                sendEppError($conn, $db, 2201, 'It belongs to another registrar', $clTRID, $trans);
                return;
            }

            if (explode(' ', $lockedDomain['exdate'])[0] !== $curExpDate) {
                $db->rollBack();
                sendEppError($conn, $db, 2306, 'The expiration date does not match', $clTRID, $trans);
                return;
            }

            $stmt = $db->prepare(
                'SELECT status FROM domain_status WHERE domain_id = :domain_id FOR UPDATE'
            );
            $stmt->execute([':domain_id' => $lockedDomain['id']]);
            $lockedStatuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt->closeCursor();

            foreach ($lockedStatuses as $status) {
                if (preg_match('/RenewProhibited$/', $status) || preg_match('/^pending/', $status)) {
                    $db->rollBack();
                    sendEppError($conn, $db, 2304, 'It has a status that does not allow renew, first change the status', $clTRID, $trans);
                    return;
                }
            }

            $stmt = $db->prepare(
                'SELECT accountBalance AS "accountBalance", creditLimit AS "creditLimit", currency
                 FROM registrar
                 WHERE id = :registrar_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([':registrar_id' => $clid]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!$account) {
                throw new RuntimeException('Registrar account does not exist');
            }

            $returnValue = getDomainPrice(
                $db,
                $lockedDomain['name'],
                $lockedDomain['tldid'],
                $date_add,
                'renew',
                $clid,
                $account['currency']
            );
            $price = $returnValue['price'] ?? null;

            if (!isset($price)) {
                $db->rollBack();
                sendEppError($conn, $db, 2400, 'The price, period and currency for such TLD are not declared', $clTRID, $trans);
                return;
            }

            if (($account['accountBalance'] + $account['creditLimit']) < $price) {
                $db->rollBack();
                sendEppError($conn, $db, 2104, 'There is no money on the account to renew', $clTRID, $trans);
                return;
            }

            $from = $lockedDomain['exdate'];
            $newExdate = (new DateTime($from))->modify("+$date_add months")->format('Y-m-d H:i:s.v');

            $stmt = $db->prepare(
                "UPDATE domain
                 SET exdate = :exdate, rgpstatus = 'renewPeriod', renewPeriod = :renew_period,
                     lastupdate = CURRENT_TIMESTAMP(3), upid = :upid, renewedDate = CURRENT_TIMESTAMP(3)
                 WHERE id = :domain_id"
            );
            $stmt->execute([
                ':exdate' => $newExdate,
                ':renew_period' => $date_add,
                ':upid' => $clid,
                ':domain_id' => $lockedDomain['id'],
            ]);

            if (!debitRegistrarBalance($db, (int)$clid, $price)) {
                $db->rollBack();
                sendEppError($conn, $db, 2104, 'There is no money on the account to renew', $clTRID, $trans);
                return;
            }

            $description = "renew domain $domainName for period $date_add MONTH";
            $stmt = $db->prepare(
                'INSERT INTO payment_history (registrar_id, date, description, amount)
                 VALUES (:registrar_id, CURRENT_TIMESTAMP(3), :description, :amount)'
            );
            $stmt->execute([
                ':registrar_id' => $clid,
                ':description' => $description,
                ':amount' => -$price,
            ]);

            $stmt = $db->prepare(
                "INSERT INTO statement
                 (registrar_id, date, command, domain_name, length_in_months, $statementPeriodColumns, amount)
                 VALUES (?, CURRENT_TIMESTAMP(3), ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$clid, 'renew', $domainName, $date_add, $from, $newExdate, $price]);

            $statisticsInsert = $database_type === 'pgsql'
                ? 'INSERT INTO statistics (date) VALUES(CURRENT_DATE) ON CONFLICT (date) DO NOTHING'
                : 'INSERT IGNORE INTO statistics (date) VALUES(CURRENT_DATE)';
            $db->exec($statisticsInsert);
            $db->exec(
                'UPDATE statistics SET renewed_domains = renewed_domains + 1 WHERE date = CURRENT_DATE'
            );

            $db->commit();
            $exdateUpdated = $newExdate;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            sendEppError($conn, $db, 2400, 'Domain could not be renewed due to database error', $clTRID, $trans);
            return;
        }
    }

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
