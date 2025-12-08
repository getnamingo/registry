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
    exit(1);
}

try {
    $minimum_data = $c['minimum_data'];
    
    $query_domain = "SELECT id, name, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE CURRENT_TIMESTAMP > acdate AND trstatus = 'pending'";
    $stmt_domain = $dbh->prepare($query_domain);
    $stmt_domain->execute();
    $domains = $stmt_domain->fetchAll(PDO::FETCH_ASSOC);

    foreach ($domains as $row) {
        $dbh->beginTransaction();
        try {
            extract($row);

            $date_add = 0;
            $price = 0;
            $domain_id = $id;
            $newRegistrantId = null;

            $stmt = $dbh->prepare("
                SELECT accountBalance, creditLimit, currency 
                FROM registrar 
                WHERE id = ? 
                LIMIT 1
            ");
            $stmt->execute([$reid]);

            [$registrar_balance, $creditLimit, $currency] = $stmt->fetch(PDO::FETCH_NUM);

            if ($transfer_exdate) {
                [$date_add] = $dbh->query("SELECT PERIOD_DIFF(DATE_FORMAT(transfer_exdate, '%Y%m'), DATE_FORMAT(exdate, '%Y%m')) AS intval FROM domain WHERE name = '$name' LIMIT 1")->fetch(PDO::FETCH_NUM);

                preg_match('/^([^\.]+)\.(.+)$/', $name, $matches);
                $domain_extension = $matches[2];

                $stmt_tld = $dbh->prepare("SELECT id FROM domain_tld WHERE UPPER(tld) = :tld LIMIT 1");
                $stmt_tld->execute([':tld' => '.' . strtoupper($domain_extension)]);
                $tld_id = $stmt_tld->fetchColumn();

                $returnValue = getDomainPrice($dbh, $name, $tld_id, $date_add, 'transfer', $reid, $currency);
                $price = $returnValue['price'];

                if (($registrar_balance + $creditLimit) < $price) {
                    $log->notice($name . ': The registrar who took over this domain has no money to pay the renewal period that resulted from the transfer request');
                    $log->error("Registrar $reid failed to process transfer for domain $name: Insufficient funds (Balance: $registrar_balance, Credit Limit: $creditLimit, Required: $price).");
                    $dbh->rollBack();
                    continue;
                }
            }
            
            if (!$minimum_data) {
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
            }

            $from = $dbh->query("SELECT exdate FROM domain WHERE id = '$domain_id' LIMIT 1")->fetchColumn();

            $stmt_update = $dbh->prepare("
                UPDATE domain
                SET
                    exdate        = DATE_ADD(exdate, INTERVAL :date_add MONTH),
                    lastupdate    = CURRENT_TIMESTAMP,
                    clid          = :reid,
                    upid          = :clid,
                    registrant    = :newRegistrantId,
                    trdate        = CURRENT_TIMESTAMP,
                    trstatus      = 'serverApproved',
                    acdate        = CURRENT_TIMESTAMP,
                    transfer_exdate = NULL
                WHERE id = :domain_id
            ");

            $stmt_update->execute([
                ':date_add'        => (int)$date_add,
                ':reid'            => $reid,
                ':clid'            => $clid,
                ':newRegistrantId' => $newRegistrantId,
                ':domain_id'       => $domain_id,
            ]);

            if (!$minimum_data) {
                $new_authinfo = generateAuthInfo();
                $stmt_update_auth = $dbh->prepare("UPDATE domain_authInfo SET authinfo = '$new_authinfo' WHERE domain_id = '$domain_id'");
                $stmt_update_auth->execute();
            }

            // Remove "pendingTransfer" status
            $stmt_remove_pending = $dbh->prepare("DELETE FROM domain_status WHERE domain_id = :domain_id AND status = 'pendingTransfer'");
            $stmt_remove_pending->execute([
                ':domain_id' => $domain_id
            ]);

            // Insert "ok" status
            $stmt_insert_ok = $dbh->prepare("INSERT INTO domain_status (domain_id, status) VALUES (:domain_id, 'ok')");
            $stmt_insert_ok->execute([
                ':domain_id' => $domain_id
            ]);

            if (!$minimum_data) {
                foreach ($contactMap as $contact) {
                    $sql = "UPDATE domain_contact_map SET contact_id = :new_contact_id WHERE domain_id = :domain_id AND type = :type AND contact_id = :contact_id";
                    $stmt = $dbh->prepare($sql);
                    $stmt->bindValue(':new_contact_id', $newContactIds[$contact['contact_id']]);
                    $stmt->bindValue(':domain_id', $domain_id);
                    $stmt->bindValue(':type', $contact['type']);
                    $stmt->bindValue(':contact_id', $contact['contact_id']);
                    $stmt->execute();
                }
            }

            $stmt_update_host = $dbh->prepare("UPDATE host SET clid = '$reid', upid = NULL, lastupdate = CURRENT_TIMESTAMP, trdate = CURRENT_TIMESTAMP WHERE domain_id = '$domain_id'");
            $stmt_update_host->execute();

            $dbh->exec("UPDATE registrar SET accountBalance = (accountBalance - $price) WHERE id = '$reid'");
            $dbh->exec("INSERT INTO payment_history (registrar_id,date,description,amount) VALUES('$reid',CURRENT_TIMESTAMP,'transfer domain $name for period $date_add MONTH','-$price')");

            $to = $dbh->query("SELECT exdate FROM domain WHERE id = '$domain_id' LIMIT 1")->fetchColumn();
                
            $stmt_insert_statement = $dbh->prepare("INSERT INTO statement (registrar_id,date,command,domain_name,length_in_months,fromS,toS,amount) VALUES(?,CURRENT_TIMESTAMP,?,?,?,?,?,?)");
            $stmt_insert_statement->execute([$reid, 'transfer', $name, $date_add, $from, $to, $price]);

            $stmt_select_domain = $dbh->prepare("
                SELECT
                    name,
                    registrant,
                    crdate,
                    exdate,
                    lastupdate,
                    clid,
                    crid,
                    upid,
                    trdate,
                    trstatus,
                    reid,
                    redate,
                    acid,
                    acdate,
                    transfer_exdate
                FROM domain
                WHERE name = ?
                LIMIT 1
            ");
            $stmt_select_domain->execute([$name]);
            $domain_data = $stmt_select_domain->fetch(PDO::FETCH_ASSOC);

            $stmt_auto_approve_transfer = $dbh->prepare("
                INSERT INTO domain_auto_approve_transfer
                    (name,registrant,crdate,exdate,lastupdate,clid,crid,upid,trdate,trstatus,reid,redate,acid,acdate,transfer_exdate)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt_auto_approve_transfer->execute(array_values($domain_data));

            $stmt_log = $dbh->prepare("INSERT INTO error_log (channel, level, level_name, message, context, extra) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_log->execute([
                'auto_transfer',
                250,
                'NOTICE',
                "Domain transfer auto-approved: $name (New registrant: $newRegistrantId, Registrar: $reid)",
                json_encode(['domain_id' => $domain_id, 'new_registrant' => $newRegistrantId, 'registrar' => $reid]),
                json_encode([
                    'received_on' => date('Y-m-d H:i:s'),
                    'read_on' => null,
                    'is_read' => false,
                    'message_type' => 'auto_transfer_approval'
                ])
            ]);

            $log->notice("Domain transfer processed: $name (New registrant: $newRegistrantId, Registrar: $reid, New Expiry: $to)");

            $dbh->commit();
        } catch (Throwable $e) {
            $dbh->rollBack();
            $log->error("Failed auto-approve for {$row['name']} (id {$row['id']}): " . $e->getMessage());
        }
    }

    if (!$minimum_data) {
        $stmt_contact = $dbh->prepare("SELECT id, crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE CURRENT_TIMESTAMP > acdate AND trstatus = 'pending'");
        $stmt_contact->execute();
        $contacts = $stmt_contact->fetchAll(PDO::FETCH_ASSOC);

        foreach ($contacts as $contact_data) {
            $dbh->beginTransaction();
            try {
                $contact_id = $contact_data['id'];
                $reid = $contact_data['reid'];

                // The losing registrar has five days once the contact is pending to respond.
                $stmt_update_contact = $dbh->prepare("UPDATE contact SET lastupdate = CURRENT_TIMESTAMP, clid = ?, upid = NULL, trdate = CURRENT_TIMESTAMP, trstatus = 'serverApproved', acdate = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt_update_contact->execute([$reid, $contact_id]);

                $stmt_select_contact = $dbh->prepare("SELECT identifier, crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate FROM contact WHERE id = ? LIMIT 1");
                $stmt_select_contact->execute([$contact_id]);
                $contact_selected_data = $stmt_select_contact->fetch(PDO::FETCH_ASSOC);

                $stmt_auto_approve_transfer = $dbh->prepare("INSERT INTO contact_auto_approve_transfer (identifier, crid, crdate, upid, lastupdate, trdate, trstatus, reid, redate, acid, acdate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_auto_approve_transfer->execute(array_values($contact_selected_data));

                $dbh->commit();
            } catch (Throwable $e) {
                $dbh->rollBack();
                $log->error("Failed auto-approve contact {$contact_data['id']}: " . $e->getMessage());
            }
        }
    }
    $log->info('job finished successfully.');
} catch (Throwable $e) {
    $log->error('Fatal error in auto_approve_transfer: ' . $e->getMessage());
    exit(1);
}