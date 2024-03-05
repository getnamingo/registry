<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
$logFilePath = '/var/log/namingo/auto_approve_transfer.log';
$log = setupLogger($logFilePath, 'Auto_Approve_Transfer');
$log->info('job started.');

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

try {
    $dbh->beginTransaction();
    
    $query_domain = "SELECT id, name, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE CURRENT_TIMESTAMP > acdate AND trstatus = 'pending'";
    $stmt_domain = $dbh->prepare($query_domain);
    $stmt_domain->execute();

    while ($row = $stmt_domain->fetch(PDO::FETCH_ASSOC)) {
        // Extracting data from the result set
        extract($row);

        $date_add = 0;
        $price = 0;

        [$registrar_balance, $creditLimit] = $dbh->query("SELECT accountBalance,creditLimit FROM registrar WHERE id = '$reid' LIMIT 1")->fetch(PDO::FETCH_NUM);

        if ($transfer_exdate) {
            [$date_add] = $dbh->query("SELECT PERIOD_DIFF(DATE_FORMAT(transfer_exdate, '%Y%m'), DATE_FORMAT(exdate, '%Y%m')) AS intval FROM domain WHERE name = '$name' LIMIT 1")->fetch(PDO::FETCH_NUM);

            preg_match('/^([^\.]+)\.(.+)$/', $name, $matches);
            $label = $matches[1];
            $domain_extension = $matches[2];

            $tld_id = null;
            $stmt_tld = $dbh->prepare("SELECT id, tld FROM domain_tld");
            $stmt_tld->execute();

            while ($tld_row = $stmt_tld->fetch(PDO::FETCH_ASSOC)) {
                if ('.' . strtoupper($domain_extension) === strtoupper($tld_row['tld'])) {
                    $tld_id = $tld_row['id'];
                    break;
                }
            }
            
            $returnValue = getDomainPrice($dbh, $name, $tld_id, $date_add, 'transfer');
            $price = $returnValue['price'];

            if (($registrar_balance + $creditLimit) < $price) {
                $log->notice($name . ': The registrar who took over this domain has no money to pay the renewal period that resulted from the transfer request');
                continue;
            }
        }
        
        // Fetch contact map
        $stmt = $dbh->prepare('SELECT contact_id, type FROM domain_contact_map WHERE domain_id = ?');
        $stmt->execute([$domain_id]);
        $contactMap = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Prepare an array to hold new contact IDs to prevent duplicating contacts
        $newContactIds = [];

        // Copy registrant data
        $stmt = $dbh->prepare('SELECT * FROM contact WHERE id = ?');
        $stmt->execute([$registrant]);
        $registrantData = $stmt->fetch(PDO::FETCH_ASSOC);
        unset($registrantData['id']);
        $registrantData['identifier'] = generateAuthInfo();
        $registrantData['clid'] = $reid;

        $stmt = $dbh->prepare('INSERT INTO contact (' . implode(', ', array_keys($registrantData)) . ') VALUES (:' . implode(', :', array_keys($registrantData)) . ')');
        foreach ($registrantData as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $newRegistrantId = $dbh->lastInsertId();
        $newContactIds[$registrant] = $newRegistrantId;

        // Copy postal info for the registrant
        $stmt = $dbh->prepare('SELECT * FROM contact_postalInfo WHERE contact_id = ?');
        $stmt->execute([$registrant]);
        $postalInfos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($postalInfos as $postalInfo) {
            unset($postalInfo['id']);
            $postalInfo['contact_id'] = $newRegistrantId;
            $columns = array_keys($postalInfo);
            $stmt = $dbh->prepare('INSERT INTO contact_postalInfo (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
            foreach ($postalInfo as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->execute();
        }

        // Insert auth info and status for the new registrant
        $new_authinfo = generateAuthInfo();
        $dbh->prepare('INSERT INTO contact_authInfo (contact_id, authtype, authinfo) VALUES (?, ?, ?)')->execute([$newRegistrantId, 'pw', $new_authinfo]);
        $dbh->prepare('INSERT INTO contact_status (contact_id, status) VALUES (?, ?)')->execute([$newRegistrantId, 'ok']);

        // Process each contact in the contact map
        foreach ($contactMap as $contact) {
            if (!array_key_exists($contact['contact_id'], $newContactIds)) {
                $stmt = $dbh->prepare('SELECT * FROM contact WHERE id = ?');
                $stmt->execute([$contact['contact_id']]);
                $contactData = $stmt->fetch(PDO::FETCH_ASSOC);
                unset($contactData['id']);
                $contactData['identifier'] = generateAuthInfo();
                $contactData['clid'] = $reid;

                $stmt = $dbh->prepare('INSERT INTO contact (' . implode(', ', array_keys($contactData)) . ') VALUES (:' . implode(', :', array_keys($contactData)) . ')');
                foreach ($contactData as $key => $value) {
                    $stmt->bindValue(':' . $key, $value);
                }
                $stmt->execute();
                $newContactId = $dbh->lastInsertId();
                $newContactIds[$contact['contact_id']] = $newContactId;

                // Repeat postal info and auth info/status insertion for each new contact
                $stmt = $dbh->prepare('SELECT * FROM contact_postalInfo WHERE contact_id = ?');
                $stmt->execute([$contact['contact_id']]);
                $postalInfos = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($postalInfos as $postalInfo) {
                    unset($postalInfo['id']);
                    $postalInfo['contact_id'] = $newContactId;
                    $columns = array_keys($postalInfo);
                    $stmt = $dbh->prepare('INSERT INTO contact_postalInfo (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
                    foreach ($postalInfo as $key => $value) {
                        $stmt->bindValue(':' . $key, $value);
                    }
                    $stmt->execute();
                }

                $new_authinfo = generateAuthInfo();
                $dbh->prepare('INSERT INTO contact_authInfo (contact_id, authtype, authinfo) VALUES (?, ?, ?)')->execute([$newContactId, 'pw', $new_authinfo]);
                $dbh->prepare('INSERT INTO contact_status (contact_id, status) VALUES (?, ?)')->execute([$newContactId, 'ok']);
            }
        }

        $from = $dbh->query("SELECT exdate FROM domain WHERE id = '$domain_id' LIMIT 1")->fetchColumn();

        $stmt_update = $dbh->prepare("UPDATE domain SET exdate = DATE_ADD(exdate, INTERVAL $date_add MONTH), lastupdate = CURRENT_TIMESTAMP, clid = '$reid', upid = '$clid', registrant = '$newRegistrantId', trdate = CURRENT_TIMESTAMP, trstatus = 'serverApproved', acdate = CURRENT_TIMESTAMP, transfer_exdate = NULL WHERE id = '$domain_id'");
        $stmt_update->execute();

        $new_authinfo = generateAuthInfo();
        $stmt_update_auth = $dbh->prepare("UPDATE domain_authInfo SET authinfo = '$new_authinfo' WHERE domain_id = '$domain_id'");
        $stmt_update_auth->execute();

        foreach ($contactMap as $contact) {
            // Construct the SQL update query
            $sql = "UPDATE domain_contact_map SET contact_id = :new_contact_id WHERE domain_id = :domain_id AND type = :type AND contact_id = :contact_id";
            
            // Prepare the SQL statement
            $stmt = $dbh->prepare($sql);
            
            // Bind the values to the placeholders
            $stmt->bindValue(':new_contact_id', $newContactIds[$contact['contact_id']]);
            $stmt->bindValue(':domain_id', $domain_id);
            $stmt->bindValue(':type', $contact['type']);
            $stmt->bindValue(':contact_id', $contact['contact_id']);
            
            // Execute the update statement
            $stmt->execute();
        }

        $stmt_update_host = $dbh->prepare("UPDATE host SET clid = '$reid', upid = NULL, lastupdate = CURRENT_TIMESTAMP, trdate = CURRENT_TIMESTAMP WHERE domain_id = '$domain_id'");
        $stmt_update_host->execute();

        if ($stmt_update->errorCode() != "00000") {
            $log->error($name . ': The domain transfer was not successful, something is wrong | DB Update failed:' . implode(", ", $stmt_update->errorInfo()));
            continue;
        } else {
            $dbh->exec("UPDATE registrar SET accountBalance = (accountBalance - $price) WHERE id = '$reid'");
            $dbh->exec("INSERT INTO payment_history (registrar_id,date,description,amount) VALUES('$reid',CURRENT_TIMESTAMP,'transfer domain $name for period $date_add MONTH','-$price')");

            $to = $dbh->query("SELECT exdate FROM domain WHERE id = '$domain_id' LIMIT 1")->fetchColumn();
            
            $stmt_insert_statement = $dbh->prepare("INSERT INTO statement (registrar_id,date,command,domain_name,length_in_months,fromS,toS,amount) VALUES(?,CURRENT_TIMESTAMP,?,?,?,?,?,?)");
            $stmt_insert_statement->execute([$reid, 'transfer', $name, $date_add, $from, $to, $price]);

            $stmt_select_domain = $dbh->prepare("SELECT id,registrant,crdate,exdate,lastupdate,clid,crid,upid,trdate,trstatus,reid,redate,acid,acdate,transfer_exdate FROM domain WHERE name = ? LIMIT 1");
            $stmt_select_domain->execute([$name]);
            $domain_data = $stmt_select_domain->fetch(PDO::FETCH_ASSOC);
            
            $stmt_auto_approve_transfer = $dbh->prepare("INSERT INTO domain_auto_approve_transfer (name,registrant,crdate,exdate,lastupdate,clid,crid,upid,trdate,trstatus,reid,redate,acid,acdate,transfer_exdate) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt_auto_approve_transfer->execute(array_values($domain_data));
        }

    }
    $stmt_domain = null;

    $stmt_contact = $dbh->prepare("SELECT id, crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE CURRENT_TIMESTAMP > acdate AND trstatus = 'pending'");
    $stmt_contact->execute();

    while ($contact_data = $stmt_contact->fetch(PDO::FETCH_ASSOC)) {
        $contact_id = $contact_data['id'];
        $reid = $contact_data['reid'];

        // The losing registrar has five days once the contact is pending to respond.
        $stmt_update_contact = $dbh->prepare("UPDATE contact SET lastupdate = CURRENT_TIMESTAMP, clid = ?, upid = NULL, trdate = CURRENT_TIMESTAMP, trstatus = 'serverApproved', acdate = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt_update_contact->execute([$reid, $contact_id]);

        if ($stmt_update_contact->errorCode() != "00000") {
            $log->error($contact_id . ': The contact transfer was not successful, something is wrong | DB Update failed:' . implode(", ", $stmt_update_contact->errorInfo()));
            continue;
        } else {
            $stmt_select_contact = $dbh->prepare("SELECT identifier, crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = ? LIMIT 1");
            $stmt_select_contact->execute([$contact_id]);
            $contact_selected_data = $stmt_select_contact->fetch(PDO::FETCH_ASSOC);
            
            $stmt_auto_approve_transfer = $dbh->prepare("INSERT INTO contact_auto_approve_transfer (identifier, crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_auto_approve_transfer->execute(array_values($contact_selected_data));
        }
    }
    $stmt_contact = null;
    $dbh->commit();
    $log->info('job finished successfully.');
} catch (PDOException $e) {
    $dbh->rollBack();
    $log->error('Database error: ' . $e->getMessage());
} catch (PDOException $e) {
    $dbh->rollBack();
    $log->error('Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    $dbh->rollBack();
    $log->error('Error: ' . $e->getMessage());
}