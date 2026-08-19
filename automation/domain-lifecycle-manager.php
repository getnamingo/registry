<?php

$c = require_once 'config.php';
require_once 'helpers.php';

// Set up logging
$logFilePath = '/var/log/namingo/domain_lifecycle_manager.log';
$log = setupLogger($logFilePath, 'Domain_Lifecycle_Manager');
$log->info('Job started.');

$lockFile = '/tmp/domain-lifecycle-manager.lock';

if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    // Check if the lock file is stale (e.g., older than 10 minutes)
    if (time() - $lockTime > 600) {
        // Remove stale lock file
        unlink($lockFile);
    } else {
        $log->warning('Script is already running. Exiting.');
        exit(0);
    }
}

// Create the lock file
touch($lockFile);

try {
    // Connect to the database
    $dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Instantiate the DomainLifecycleManager
    $manager = new DomainLifecycleManager($dbh, $log, $c);
    $manager->processLifecycle();

    $log->info('Job finished successfully.');
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
    exit(1);
} catch (Exception $e) {
    $log->error('An unexpected error occurred: ' . $e->getMessage());
    exit(1);
} finally {
    // Remove the lock file
    unlink($lockFile);
}

class DomainLifecycleManager {
    private $dbh;
    private $log;
    private $config;

    public function __construct($dbh, $log, $config) {
        $this->dbh = $dbh;
        $this->log = $log;
        $this->config = $config;
    }

    public function processLifecycle() {
        $this->processNeverExpireDomains();

        // Process lifecycle phases
        if ($this->config['enableAutoRenew']) {
            $this->processAutoRenewal();
        } else {
            $this->processGracePeriod();
        }

        $this->cleanUpPeriods();

        if ($this->config['enableRedemptionPeriod']) {
            $this->processPendingDelete();
            $this->processPendingRestore();
        }

        if ($this->config['enablePendingDelete']) {
            $this->processDomainDeletion();
        }
    }

    // ========================
    // 1. Auto-Renewal Phase
    // ========================
    private function processAutoRenewal() {
        $this->log->info('Starting Auto-Renewal Phase.');

        // Fetch domains eligible for auto-renewal
        $sth = $this->dbh->prepare("
            SELECT id, name
            FROM domain 
            WHERE CURRENT_TIMESTAMP > exdate 
              AND rgpstatus IS NULL
        ");
        $sth->execute();

        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $domain_id = $row['id'];
            $name = $row['name'];
            $this->handleAutoRenewal($domain_id, $name);

            $this->log->info("$name (ID $domain_id) processed for auto-renewal.");
        }

        $this->log->info('Completed Auto-Renewal Phase.');
    }

    private function handleAutoRenewal($domain_id, $name) {
        $this->dbh->beginTransaction();

        try {
            // Lock and re-check the mutable domain state. Another worker may
            // have processed a row after the initial work-list query.
            $sth = $this->dbh->prepare(
                "SELECT id, name, tldid, exdate, clid
                 FROM domain
                 WHERE id = ?
                   AND CURRENT_TIMESTAMP > exdate
                   AND rgpstatus IS NULL
                 FOR UPDATE"
            );
            $sth->execute([$domain_id]);
            $domain = $sth->fetch(PDO::FETCH_ASSOC);
            $sth->closeCursor();

            if (!$domain) {
                $this->dbh->rollBack();
                return;
            }

            $sth = $this->dbh->prepare(
                'SELECT status FROM domain_status WHERE domain_id = ? FOR UPDATE'
            );
            $sth->execute([$domain_id]);
            $statuses = $sth->fetchAll(PDO::FETCH_COLUMN);
            $sth->closeCursor();

            foreach ($statuses as $status) {
                if (preg_match('/(serverUpdateProhibited|serverDeleteProhibited)$/', $status) || preg_match('/^pending/', $status)) {
                    $this->dbh->rollBack();
                    return;
                }
            }

            $sthRegistrar = $this->dbh->prepare(
                'SELECT accountBalance AS "accountBalance", creditLimit AS "creditLimit", currency
                 FROM registrar
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $sthRegistrar->execute([$domain['clid']]);
            $registrar = $sthRegistrar->fetch(PDO::FETCH_ASSOC);
            $sthRegistrar->closeCursor();

            if (!$registrar) {
                throw new RuntimeException("Registrar {$domain['clid']} does not exist");
            }

            $returnValue = getDomainPrice(
                $this->dbh,
                $domain['name'],
                $domain['tldid'],
                12,
                'renew',
                $domain['clid'],
                $registrar['currency']
            );
            $price = $returnValue['price'] ?? null;
            if (!isset($price) || ($returnValue['type'] ?? 'not_found') === 'not_found') {
                throw new RuntimeException("Renewal price is not configured for {$domain['name']}");
            }

            if (($registrar['accountBalance'] + $registrar['creditLimit']) < $price) {
                $this->dbh->prepare('DELETE FROM domain_status WHERE domain_id = ?')->execute([$domain_id]);
                $deleteTime = (new DateTime($domain['exdate']))
                    ->modify('+' . (int)$this->config['gracePeriodDays'] . ' days')
                    ->format('Y-m-d H:i:s.v');
                $this->dbh->prepare(
                    "UPDATE domain SET rgpstatus = 'redemptionPeriod', delTime = ? WHERE id = ?"
                )->execute([$deleteTime, $domain_id]);
                $this->dbh->prepare(
                    "INSERT INTO domain_status (domain_id, status) VALUES (?, 'pendingDelete')"
                )->execute([$domain_id]);

                $this->dbh->commit();
                $this->log->notice("{$domain['name']} moved to redemption period because registrar {$domain['clid']} has insufficient funds.");
                return;
            }

            $from = $domain['exdate'];
            $renewedDate = (new DateTime($from))->modify('+12 months')->format('Y-m-d H:i:s.v');
            $sth = $this->dbh->prepare("UPDATE domain SET rgpstatus = 'autoRenewPeriod', exdate = ?, autoRenewPeriod = '12', renewedDate = exdate WHERE id = ?");
            $sth->execute([$renewedDate, $domain_id]);

            if (!debitRegistrarBalance($this->dbh, (int)$domain['clid'], $price)) {
                throw new RuntimeException("Registrar {$domain['clid']} has insufficient funds for auto-renewal");
            }

            $sth = $this->dbh->prepare("INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP, ?, ?)");
            $description = "autoRenew domain {$domain['name']} for period 12 MONTH";
            $sth->execute([$domain['clid'], $description, -$price]);

            $statementPeriodColumns = $this->config['db_type'] === 'pgsql' ? '"fromS", "toS"' : 'fromS, toS';
            $sthStatement = $this->dbh->prepare("INSERT INTO statement (registrar_id, date, command, domain_name, length_in_months, $statementPeriodColumns, amount) VALUES(?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?)");
            $sthStatement->execute([$domain['clid'], 'autoRenew', $domain['name'], '12', $from, $renewedDate, $price]);

            $this->updateStatistics('renewed_domains');

            $this->dbh->commit();
        } catch (Throwable $e) {
            if ($this->dbh->inTransaction()) {
                $this->dbh->rollBack();
            }
            $this->log->error("Failed to auto-renew $name (ID $domain_id): " . $e->getMessage());
        }
    }

    private function moveToRedemptionPeriod($domain_id, $exdate) {
        $this->dbh->beginTransaction();
        try {
            $this->dbh->prepare("DELETE FROM domain_status WHERE domain_id = ?")->execute([$domain_id]);
            $deleteTime = (new DateTime($exdate))->modify('+' . (int) $this->config['gracePeriodDays'] . ' days')->format('Y-m-d H:i:s.v');
            $this->dbh->prepare("UPDATE domain SET rgpstatus = 'redemptionPeriod', delTime = ? WHERE id = ?")->execute([$deleteTime, $domain_id]);
            $this->dbh->prepare("INSERT INTO domain_status (domain_id, status) VALUES(?, 'pendingDelete')")->execute([$domain_id]);

            $this->dbh->commit();
        } catch (Exception $e) {
            $this->dbh->rollBack();
            $this->log->error("Failed to move domain ID $domain_id to redemption period: " . $e->getMessage());
        }
    }

    // ========================
    // 2. Grace Period Phase
    // ========================
    private function processGracePeriod() {
        $this->log->info('Starting Grace Period Phase.');

        $gracePeriodDays = $this->config['gracePeriodDays'];

        // Fetch domains eligible for grace period
        $graceThreshold = (new DateTime("-$gracePeriodDays days"))->format('Y-m-d H:i:s.v');
        $sth = $this->dbh->prepare("
            SELECT id, name, exdate 
            FROM domain 
            WHERE exdate < ?
              AND rgpstatus IS NULL
        ");
        $sth->execute([$graceThreshold]);

        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $domain_id = $row['id'];
            $name = $row['name'];
            $exdate = $row['exdate'];

            // Check if domain can be moved to redemption period
            if ($this->canSetGracePeriod($domain_id)) {
                $this->moveToRedemptionPeriod($domain_id, $exdate);
                $this->log->info("$name (ID $domain_id) moved to redemption period.");
            }
        }

        $this->log->info('Completed Grace Period Phase.');
    }

    private function canSetGracePeriod($domain_id) {
        $sth_status = $this->dbh->prepare("SELECT status FROM domain_status WHERE domain_id = ?");
        $sth_status->execute([$domain_id]);

        while ($status_row = $sth_status->fetch(PDO::FETCH_ASSOC)) {
            $status = $status_row['status'];
            if (preg_match("/(serverUpdateProhibited|serverDeleteProhibited)$/", $status) || preg_match("/^pending/", $status)) {
                return false;
            }
        }
        return true;
    }

    // ========================
    // 3. Clean-Up Periods
    // ========================
    private function cleanUpPeriods() {
        $this->log->info('Starting Clean-Up Periods Phase.');

        $periods = [
            'autoRenewPeriod' => $this->config['autoRenewPeriodDays'],
            'addPeriod' => $this->config['addPeriodDays'],
            'renewPeriod' => $this->config['renewPeriodDays'],
            'transferPeriod' => $this->config['transferPeriodDays'],
        ];

        foreach ($periods as $periodName => $days) {
            $this->cleanupPeriod($periodName, $days);
        }

        $this->log->info('Completed Clean-Up Periods Phase.');
    }

    private function cleanupPeriod($periodName, $days) {
        $column = $periodName === 'addPeriod' ? 'crdate' : ($periodName === 'renewPeriod' ? 'renewedDate' : ($periodName === 'transferPeriod' ? 'trdate' : 'exdate'));
        $threshold = (new DateTime("-$days days"))->format('Y-m-d H:i:s.v');
        $sth = $this->dbh->prepare("UPDATE domain SET rgpstatus = NULL WHERE $column < ? AND rgpstatus = ?");
        $sth->execute([$threshold, $periodName]);
    }

    // ========================
    // 4. Pending Delete Phase
    // ========================
    private function processPendingDelete() {
        $this->log->info('Starting Pending Delete Phase.');

        $redemptionPeriodDays = $this->config['redemptionPeriodDays'];

        // Fetch domains eligible for pending delete
        $pendingDeleteThreshold = (new DateTime("-$redemptionPeriodDays days"))->format('Y-m-d H:i:s.v');
        $sth = $this->dbh->prepare("
            SELECT id, name, exdate 
            FROM domain 
            WHERE delTime < ?
              AND rgpstatus = 'redemptionPeriod'
        ");
        $sth->execute([$pendingDeleteThreshold]);

        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $domain_id = $row['id'];
            $name = $row['name'];

            if ($this->canSetPendingDelete($domain_id)) {
                $sthUpdate = $this->dbh->prepare("UPDATE domain SET rgpstatus = 'pendingDelete' WHERE id = ?");
                $sthUpdate->execute([$domain_id]);
                $this->log->info("$name (ID $domain_id) moved to pending delete.");
            }
        }

        $this->log->info('Completed Pending Delete Phase.');
    }

    private function canSetPendingDelete($domain_id) {
        $sth_status = $this->dbh->prepare("SELECT status FROM domain_status WHERE domain_id = ?");
        $sth_status->execute([$domain_id]);

        while ($status_row = $sth_status->fetch(PDO::FETCH_ASSOC)) {
            $status = $status_row['status'];
            if (preg_match("/(serverUpdateProhibited|serverDeleteProhibited)$/", $status)) {
                return false;
            }
        }
        return true;
    }

    // ========================
    // 5. Pending Restore Phase
    // ========================
    private function processPendingRestore() {
        $this->log->info('Starting Pending Restore Phase.');

        // Fetch domains in pendingRestore status that have exceeded the restore period
        $restoreThreshold = (new DateTime('-7 days'))->format('Y-m-d H:i:s.v');
        $sth = $this->dbh->prepare("
            SELECT id, name 
            FROM domain 
            WHERE rgpstatus = 'pendingRestore' 
              AND resTime < ?
        ");
        $sth->execute([$restoreThreshold]);

        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $domain_id = $row['id'];
            $name = $row['name'];

            $sthUpdate = $this->dbh->prepare("UPDATE domain SET rgpstatus = 'redemptionPeriod' WHERE id = ?");
            $sthUpdate->execute([$domain_id]);

            $this->log->info("$name (ID $domain_id) returned to redemption period from pending restore.");
        }

        $this->log->info('Completed Pending Restore Phase.');
    }

    // ========================
    // 6. Domain Deletion Phase
    // ========================
    private function processDomainDeletion() {
        $this->log->info('Starting Domain Deletion Phase.');

        $totalPendingDays = $this->config['redemptionPeriodDays'] + $this->config['pendingDeletePeriodDays'];

        // Fetch domains eligible for deletion
        $deletionThreshold = (new DateTime("-$totalPendingDays days"))->format('Y-m-d H:i:s.v');
        $sth = $this->dbh->prepare("
            SELECT id, name, delTime AS \"delTime\"
            FROM domain 
            WHERE delTime < ?
              AND rgpstatus = 'pendingDelete'
        ");
        $sth->execute([$deletionThreshold]);

        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $domain_id = $row['id'];
            $name = $row['name'];
            $delTime = $row['delTime'];

            if ($this->shouldDeleteDomainNow($delTime)) {
                if ($this->canDeleteDomain($domain_id)) {
                    $this->deleteDomain($domain_id, $name);
                    $this->log->info("$name (ID $domain_id) has been deleted.");
                }
            }
        }

        $this->log->info('Completed Domain Deletion Phase.');
    }

    private function shouldDeleteDomainNow($delTime) {
        $currentTime = new DateTime();
        $deletionTime = new DateTime($delTime);
        $totalPendingDays = $this->config['redemptionPeriodDays'] + $this->config['pendingDeletePeriodDays'];
        $deletionTime->modify("+$totalPendingDays days");

        if ($this->config['dropStrategy'] === 'fixed') {
            $dropTime = $this->config['dropTime'];
            $deletionTime->setTime((int)substr($dropTime, 0, 2), (int)substr($dropTime, 3, 2), (int)substr($dropTime, 6, 2));
            return $currentTime >= $deletionTime;
        } elseif ($this->config['dropStrategy'] === 'random') {
            $randomDays = rand(0, $this->config['pendingDeletePeriodDays']);
            $deletionTime->modify("+$randomDays days");
            return $currentTime >= $deletionTime;
        } else {
            return $currentTime >= $deletionTime;
        }
    }

    private function canDeleteDomain($domain_id) {
        $sth_status = $this->dbh->prepare("SELECT status FROM domain_status WHERE domain_id = ?");
        $sth_status->execute([$domain_id]);

        $delete_domain = false;
        while ($status_row = $sth_status->fetch(PDO::FETCH_ASSOC)) {
            $status = $status_row['status'];
            if ($status == 'pendingDelete') {
                $delete_domain = true;
            }
            if (preg_match("/(serverUpdateProhibited|serverDeleteProhibited)$/", $status)) {
                return false;
            }
        }
        return $delete_domain;
    }

    private function deleteDomain($domain_id, $name) {
        $minimum_data = $this->config['minimum_data'];

        $this->dbh->beginTransaction();
        try {
            // Delete associated hosts
            $sth = $this->dbh->prepare("SELECT id FROM host WHERE domain_id = ?");
            $sth->execute([$domain_id]);
            while ($host_row = $sth->fetch(PDO::FETCH_ASSOC)) {
                $host_id = $host_row['id'];
                $this->dbh->prepare("DELETE FROM host_addr WHERE host_id = ?")->execute([$host_id]);
                $this->dbh->prepare("DELETE FROM host_status WHERE host_id = ?")->execute([$host_id]);
                $this->dbh->prepare("DELETE FROM domain_host_map WHERE host_id = ?")->execute([$host_id]);
            }

            if (!$minimum_data) {
                $this->dbh->prepare("DELETE FROM domain_contact_map WHERE domain_id = ?")->execute([$domain_id]);
            }
            $this->dbh->prepare("DELETE FROM domain_host_map WHERE domain_id = ?")->execute([$domain_id]);
            $this->dbh->prepare("DELETE FROM domain_authInfo WHERE domain_id = ?")->execute([$domain_id]);
            $this->dbh->prepare("DELETE FROM domain_status WHERE domain_id = ?")->execute([$domain_id]);
            $this->dbh->prepare("DELETE FROM host WHERE domain_id = ?")->execute([$domain_id]);

            $this->dbh->prepare("DELETE FROM domain WHERE id = ?")->execute([$domain_id]);

            // Update statistics
            $this->updateStatistics('deleted_domains');

            $this->dbh->commit();
        } catch (Exception $e) {
            $this->dbh->rollBack();
            $this->log->error("$domain_id|$name could not be deleted: " . $e->getMessage());
        }
    }

    // ========================
    // Helper Methods
    // ========================
    private function processNeverExpireDomains() {
        $raw = trim((string)($this->config['never_expire_domains'] ?? ''));

        if ($raw === '' || $raw === '0') {
            return;
        }

        $domains = array_unique(array_filter(array_map(
            static fn($name) => strtolower(rtrim(trim($name), '.')),
            explode(',', $raw)
        )));

        $now = new DateTimeImmutable();
        $renewBefore = $now->modify('+31 days');

        $select = $this->dbh->prepare("
            SELECT id, name, exdate
            FROM domain
            WHERE name = ?
              AND (
                  rgpstatus IS NULL
                  OR rgpstatus NOT IN ('redemptionPeriod', 'pendingDelete', 'pendingRestore')
              )
            LIMIT 1
        ");

        $update = $this->dbh->prepare("
            UPDATE domain
            SET exdate = ?
            WHERE id = ?
              AND exdate = ?
              AND (
                  rgpstatus IS NULL
                  OR rgpstatus NOT IN ('redemptionPeriod', 'pendingDelete', 'pendingRestore')
              )
        ");

        foreach ($domains as $name) {
            $select->execute([$name]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            $select->closeCursor();

            if (!$row) {
                continue;
            }

            $expiry = new DateTimeImmutable($row['exdate']);
            if ($expiry > $renewBefore) {
                continue;
            }

            $base = $expiry > $now ? $expiry : $now;
            $newExpiry = $base->modify('+12 months')->format('Y-m-d H:i:s.v');

            $update->execute([$newExpiry, $row['id'], $row['exdate']]);

            if ($update->rowCount() === 1) {
                $this->log->info("{$row['name']} (ID {$row['id']}) expiry extended to $newExpiry by never-expire policy.");
            }
        }
    }

    private function updateStatistics($field) {
        // Ensure today's statistics record exists
        $statisticsInsert = $this->config['db_type'] === 'pgsql'
            ? 'INSERT INTO statistics (date) VALUES (CURRENT_DATE) ON CONFLICT (date) DO NOTHING'
            : 'INSERT IGNORE INTO statistics (date) VALUES (CURRENT_DATE)';
        $this->dbh->exec($statisticsInsert);
        // Update the specific field
        $sthUpdate = $this->dbh->prepare("UPDATE statistics SET $field = $field + 1 WHERE date = CURRENT_DATE");
        $sthUpdate->execute();
    }
}
