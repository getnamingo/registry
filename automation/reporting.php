<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;

$c = require_once 'config.php';
require_once 'helpers.php';

// Connect to the database
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$logFilePath = '/var/log/namingo/reporting.log';
$log = setupLogger($logFilePath, 'ICANN_Reporting');
$log->info('job started.');

// Check if the directory exists
if (!file_exists($c['reporting_path'])) {
    if (!mkdir($c['reporting_path'], 0755, true)) {
        $log->error("Failed to create directory: " . $c['reporting_path']);
        exit(1);
    } else {
        $log->info("Directory created successfully: " . $c['reporting_path']);
    }
}

try {
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password'], $options);
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
}

try {
    // Fetch all TLDs
    $query = "SELECT tld FROM domain_tld";
    $tlds = $dbh->query($query)->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tlds as $tld) {
        // Skip TLDs with a dot inside, apart from the beginning
        if (strpos(substr($tld, 1), '.') !== false) {
            continue; // Skip this iteration if an additional dot is found
        }
        
        // Initialize activity and transaction data arrays for each TLD
        $activityData = []; 
        $transactionData = [];

        // Construct activity data for each TLD
        $activityData[] = [
            'operational-registrars' => getOperationalRegistrars($dbh),
            'zfa-passwords' => getZfaPasswords($dbh),
            'whois-43-queries' => getWhois43Queries($dbh),
            'web-whois-queries' => getWebWhoisQueries($dbh),
            'searchable-whois-queries' => getSearchableWhoisQueries($dbh),
            'dns-udp-queries-received' => getDnsUdpQueriesReceived($dbh),
            'dns-udp-queries-responded' => getDnsUdpQueriesResponded($dbh),
            'dns-tcp-queries-received' => getDnsTcpQueriesReceived($dbh),
            'dns-tcp-queries-responded' => getDnsTcpQueriesResponded($dbh),
            'srs-dom-check' => getSrsCommand($dbh, 'check', 'domain'),
            'srs-dom-create' => getSrsCommand($dbh, 'create', 'domain'),
            'srs-dom-delete' => getSrsCommand($dbh, 'delete', 'domain'),
            'srs-dom-info' => getSrsCommand($dbh, 'info', 'domain'),
            'srs-dom-renew' => getSrsCommand($dbh, 'renew', 'domain'),
            'srs-dom-rgp-restore-report' => getSrsDomRgpRestoreReport($dbh),
            'srs-dom-rgp-restore-request' => getSrsDomRgpRestoreRequest($dbh),
            'srs-dom-transfer-approve' => getSrsDomTransferApprove($dbh),
            'srs-dom-transfer-cancel' => getSrsDomTransferCancel($dbh),
            'srs-dom-transfer-query' => getSrsDomTransferQuery($dbh),
            'srs-dom-transfer-reject' => getSrsDomTransferReject($dbh),
            'srs-dom-transfer-request' => getSrsDomTransferRequest($dbh),
            'srs-dom-update' => getSrsCommand($dbh, 'update', 'domain'),
            'srs-host-check' => getSrsCommand($dbh, 'check', 'host'),
            'srs-host-create' => getSrsCommand($dbh, 'create', 'host'),
            'srs-host-delete' => getSrsCommand($dbh, 'delete', 'host'),
            'srs-host-info' => getSrsCommand($dbh, 'info', 'host'),
            'srs-host-update' => getSrsCommand($dbh, 'update', 'host'),
            'srs-cont-check' => getSrsCommand($dbh, 'check', 'contact'),
            'srs-cont-create' => getSrsCommand($dbh, 'create', 'contact'),
            'srs-cont-delete' => getSrsCommand($dbh, 'delete', 'contact'),
            'srs-cont-info' => getSrsCommand($dbh, 'info', 'contact'),
            'srs-cont-transfer-approve' => getSrsContTransferApprove($dbh),
            'srs-cont-transfer-cancel' => getSrsContTransferCancel($dbh),
            'srs-cont-transfer-query' => getSrsContTransferQuery($dbh),
            'srs-cont-transfer-reject' => getSrsContTransferReject($dbh),
            'srs-cont-transfer-request' => getSrsContTransferRequest($dbh),
            'srs-cont-update' => getSrsCommand($dbh, 'update', 'contact'),
        ];

        // Loop through registrars and get transaction data
        $registrars = getRegistrars($dbh);
        foreach ($registrars as $registrar) {
            $transactionData[] = [
                'registrar-name' => $registrar['name'],
                'iana-id' => $registrar['iana_id'],
                'total-domains' => getTotalDomains($dbh, $registrar),
                'total-nameservers' => getTotalNameservers($dbh, $registrar),
                'net-adds-1-yr' => getNetAddsByYear($dbh, $registrar, 1),
                'net-adds-2-yr' => getNetAddsByYear($dbh, $registrar, 2),
                'net-adds-3-yr' => getNetAddsByYear($dbh, $registrar, 3),
                'net-adds-4-yr' => getNetAddsByYear($dbh, $registrar, 4),
                'net-adds-5-yr' => getNetAddsByYear($dbh, $registrar, 5),
                'net-adds-6-yr' => getNetAddsByYear($dbh, $registrar, 6),
                'net-adds-7-yr' => getNetAddsByYear($dbh, $registrar, 7),
                'net-adds-8-yr' => getNetAddsByYear($dbh, $registrar, 8),
                'net-adds-9-yr' => getNetAddsByYear($dbh, $registrar, 9),
                'net-adds-10-yr' => getNetAddsByYear($dbh, $registrar, 10),
                'net-renews-1-yr' => getNetRenewsByYear($dbh, $registrar, 1),
                'net-renews-2-yr' => getNetRenewsByYear($dbh, $registrar, 2),
                'net-renews-3-yr' => getNetRenewsByYear($dbh, $registrar, 3),
                'net-renews-4-yr' => getNetRenewsByYear($dbh, $registrar, 4),
                'net-renews-5-yr' => getNetRenewsByYear($dbh, $registrar, 5),
                'net-renews-6-yr' => getNetRenewsByYear($dbh, $registrar, 6),
                'net-renews-7-yr' => getNetRenewsByYear($dbh, $registrar, 7),
                'net-renews-8-yr' => getNetRenewsByYear($dbh, $registrar, 8),
                'net-renews-9-yr' => getNetRenewsByYear($dbh, $registrar, 9),
                'net-renews-10-yr' => getNetRenewsByYear($dbh, $registrar, 10),
                'transfer-gaining-successful' => getTransferGainingSuccessful($dbh, $registrar),
                'transfer-gaining-nacked' => getTransferGainingNacked($dbh, $registrar),
                'transfer-losing-successful' => getTransferLosingSuccessful($dbh, $registrar),
                'transfer-losing-nacked' => getTransferLosingNacked($dbh, $registrar),
                'transfer-disputed-won' => getTransferDisputedWon($dbh, $registrar),
                'transfer-disputed-lost' => getTransferDisputedLost($dbh, $registrar),
                'transfer-disputed-nodecision' => getTransferDisputedNoDecision($dbh, $registrar),
                'deleted-domains-grace' => getDeletedDomainsGrace($dbh, $registrar),
                'deleted-domains-nograce' => getDeletedDomainsNoGrace($dbh, $registrar),
                'restored-domains' => getRestoredDomains($dbh, $registrar),
                'restored-noreport' => getRestoredNoReport($dbh, $registrar),
                'agp-exemption-requests' => getAgpExemptionRequests($dbh, $registrar),
                'agp-exemptions-granted' => getAgpExemptionsGranted($dbh, $registrar),
                'agp-exempted-domains' => getAgpExemptedDomains($dbh, $registrar),
                'attempted-adds' => getAttemptedAdds($dbh, $registrar),
            ];
        }
        
        $totals = [
            'registrar-name' => 'Totals',
            'iana-id' => '',
            'total-domains' => getTotalDomainsAllRegistrars($dbh),
            'total-nameservers' => getTotalNameserversAllRegistrars($dbh),
            'net-adds-1-yr' => getNetAddsByYearAllRegistrars($dbh, 1),
            'net-adds-2-yr' => getNetAddsByYearAllRegistrars($dbh, 2),
            'net-adds-3-yr' => getNetAddsByYearAllRegistrars($dbh, 3),
            'net-adds-4-yr' => getNetAddsByYearAllRegistrars($dbh, 4),
            'net-adds-5-yr' => getNetAddsByYearAllRegistrars($dbh, 5),
            'net-adds-6-yr' => getNetAddsByYearAllRegistrars($dbh, 6),
            'net-adds-7-yr' => getNetAddsByYearAllRegistrars($dbh, 7),
            'net-adds-8-yr' => getNetAddsByYearAllRegistrars($dbh, 8),
            'net-adds-9-yr' => getNetAddsByYearAllRegistrars($dbh, 9),
            'net-adds-10-yr' => getNetAddsByYearAllRegistrars($dbh, 10),
            'net-renews-1-yr' => getNetRenewsByYearAllRegistrars($dbh, 1),
            'net-renews-2-yr' => getNetRenewsByYearAllRegistrars($dbh, 2),
            'net-renews-3-yr' => getNetRenewsByYearAllRegistrars($dbh, 3),
            'net-renews-4-yr' => getNetRenewsByYearAllRegistrars($dbh, 4),
            'net-renews-5-yr' => getNetRenewsByYearAllRegistrars($dbh, 5),
            'net-renews-6-yr' => getNetRenewsByYearAllRegistrars($dbh, 6),
            'net-renews-7-yr' => getNetRenewsByYearAllRegistrars($dbh, 7),
            'net-renews-8-yr' => getNetRenewsByYearAllRegistrars($dbh, 8),
            'net-renews-9-yr' => getNetRenewsByYearAllRegistrars($dbh, 9),
            'net-renews-10-yr' => getNetRenewsByYearAllRegistrars($dbh, 10),
            'transfer-gaining-successful' => getTransferGainingSuccessfulAllRegistrars($dbh),
            'transfer-gaining-nacked' => getTransferGainingNackedAllRegistrars($dbh),
            'transfer-losing-successful' => getTransferLosingSuccessfulAllRegistrars($dbh),
            'transfer-losing-nacked' => getTransferLosingNackedAllRegistrars($dbh),
            'transfer-disputed-won' => getTransferDisputedWonAllRegistrars($dbh),
            'transfer-disputed-lost' => getTransferDisputedLostAllRegistrars($dbh),
            'transfer-disputed-nodecision' => getTransferDisputedNoDecisionAllRegistrars($dbh),
            'deleted-domains-grace' => getDeletedDomainsGraceAllRegistrars($dbh),
            'deleted-domains-nograce' => getDeletedDomainsNoGraceAllRegistrars($dbh),
            'restored-domains' => getRestoredDomainsAllRegistrars($dbh),
            'restored-noreport' => getRestoredNoReportAllRegistrars($dbh),
            'agp-exemption-requests' => getAgpExemptionRequestsAllRegistrars($dbh),
            'agp-exemptions-granted' => getAgpExemptionsGrantedAllRegistrars($dbh),
            'agp-exempted-domains' => getAgpExemptedDomainsAllRegistrars($dbh),
            'attempted-adds' => getAttemptedAddsAllRegistrars($dbh),
        ];

        $transactionData[] = $totals;

        // Write data to CSV
        $tld_save = strtolower(ltrim($tld, '.'));
        writeCSV("{$c['reporting_path']}/{$tld_save}-activity-" . date('Ym', strtotime('-1 month')) . "-en.csv", $activityData);
        writeCSV("{$c['reporting_path']}/{$tld_save}-transactions-" . date('Ym', strtotime('-1 month')) . "-en.csv", $transactionData);

        // Upload if the $c['reporting_upload'] variable is true
        if ($c['reporting_upload']) {
            // Calculate the period (previous month from now)
            $previousMonth = date('Ym', strtotime('-1 month'));

            // Paths to the files you created
            $activityFile = "{$c['reporting_path']}/{$tld_save}-activity-" . $previousMonth . "-en.csv";
            $transactionFile = "{$c['reporting_path']}/{$tld_save}-transactions-" . $previousMonth . "-en.csv";

            // URLs for upload
            $activityUploadUrl = 'https://ry-api.icann.org/report/registry-functions-activity/' . $tld_save . '/' . $previousMonth;
            $transactionUploadUrl = 'https://ry-api.icann.org/report/registrar-transactions/' . $tld_save . '/' . $previousMonth;

            // Perform the upload    
            try {
                $activityResult = uploadFileWithRetry($activityUploadUrl, $activityFile, $c['reporting_username'], $c['reporting_password']);
                $log->info("Activity report upload result: " . $activityResult);

                $transactionResult = uploadFileWithRetry($transactionUploadUrl, $transactionFile, $c['reporting_username'], $c['reporting_password']);
                $log->info("Transaction report upload result: " . $transactionResult);
            } catch (Exception $e) {
                $log->error("File upload failed: " . $e->getMessage());
            }
        }
    }
    $log->info('job finished successfully.');
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
}

// HELPER FUNCTIONS
function getOperationalRegistrars($dbh) {
    $stmt = $dbh->prepare("SELECT COUNT(id) FROM registrar");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getRegistrars($dbh) {
    return $dbh->query("SELECT id, name, iana_id FROM registrar")->fetchAll();
}

function writeCSV($filename, $data) {
    if (!is_array($data) || empty($data)) {
        throw new Exception("Data for CSV writing must be a non-empty array.");
    }

    $file = fopen($filename, 'w');
    if ($file === false) {
        throw new Exception("Unable to open file '{$filename}' for writing.");
    }

    fputcsv($file, array_keys($data[0]));
    foreach ($data as $row) {
        if (false === fputcsv($file, $row)) {
            throw new Exception("Failed to write data to CSV file.");
        }
    }

    if (false === fclose($file)) {
        throw new Exception("Unable to close the file '{$filename}'.");
    }
}

function getZfaPasswords($dbh) {
    return 'CZDS';
}

function getWhois43Queries($dbh) {
    $stmt = $dbh->prepare("SELECT value FROM settings WHERE name = :settingName");
    $stmt->bindValue(':settingName', 'whois-43-queries');
    $stmt->execute();
    $value = $stmt->fetchColumn();

    // Resetting the value in the database
    $resetStmt = $dbh->prepare("UPDATE settings SET value = 0 WHERE name = :settingName");
    $resetStmt->bindValue(':settingName', 'whois-43-queries');
    $resetStmt->execute();

    return $value;
}

function getWebWhoisQueries($dbh) {
    $stmt = $dbh->prepare("SELECT value FROM settings WHERE name = :settingName");
    $stmt->bindValue(':settingName', 'web-whois-queries');
    $stmt->execute();
    $value = $stmt->fetchColumn();

    // Resetting the value in the database
    $resetStmt = $dbh->prepare("UPDATE settings SET value = 0 WHERE name = :settingName");
    $resetStmt->bindValue(':settingName', 'web-whois-queries');
    $resetStmt->execute();

    return $value;
}

function getSearchableWhoisQueries($dbh) {
    $stmt = $dbh->prepare("SELECT value FROM settings WHERE name = :settingName");
    $stmt->bindValue(':settingName', 'searchable-whois-queries');
    $stmt->execute();
    $value = $stmt->fetchColumn();

    // Resetting the value in the database
    $resetStmt = $dbh->prepare("UPDATE settings SET value = 0 WHERE name = :settingName");
    $resetStmt->bindValue(':settingName', 'searchable-whois-queries');
    $resetStmt->execute();

    return $value;
}

function getDnsUdpQueriesReceived($dbh) {
    $stmt = $dbh->prepare("SELECT value FROM settings WHERE name = :settingName");
    $stmt->bindValue(':settingName', 'dns-udp-queries-received');
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getDnsUdpQueriesResponded($dbh) {
    $stmt = $dbh->prepare("SELECT value FROM settings WHERE name = :settingName");
    $stmt->bindValue(':settingName', 'dns-udp-queries-responded');
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getDnsTcpQueriesReceived($dbh) {
    $stmt = $dbh->prepare("SELECT value FROM settings WHERE name = :settingName");
    $stmt->bindValue(':settingName', 'dns-tcp-queries-received');
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getDnsTcpQueriesResponded($dbh) {
    $stmt = $dbh->prepare("SELECT value FROM settings WHERE name = :settingName");
    $stmt->bindValue(':settingName', 'dns-tcp-queries-responded');
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getSrsCommand($dbh, $cmd, $object) {
    $stmt = $dbh->prepare("SELECT count(cmd) FROM registryTransaction.transaction_identifier WHERE (cldate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND cmd = :cmd AND obj_type = :object");
    $stmt->bindParam(':cmd', $cmd, PDO::PARAM_STR);
    $stmt->bindParam(':object', $object, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getSrsDomRgpRestoreReport($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getSrsDomRgpRestoreRequest($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getSrsDomTransferApprove($dbh) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE (trdate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND trstatus = 'clientApproved' OR trstatus = 'serverApproved'");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getSrsDomTransferCancel($dbh) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE (trdate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND trstatus = 'clientCancelled' OR trstatus = 'serverCancelled'");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getSrsDomTransferQuery($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getSrsDomTransferReject($dbh) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE (trdate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND trstatus = 'clientRejected' OR trstatus = 'serverRejected'");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getSrsDomTransferRequest($dbh) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE (trdate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND trstatus = 'pending'");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getSrsContTransferApprove($dbh) {
    $stmt = $dbh->prepare("SELECT count(identifier) FROM contact WHERE (trdate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND trstatus = 'clientApproved' OR trstatus = 'serverApproved'");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getSrsContTransferCancel($dbh) {
    $stmt = $dbh->prepare("SELECT count(identifier) FROM contact WHERE (trdate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND trstatus = 'clientCancelled' OR trstatus = 'serverCancelled'");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getSrsContTransferQuery($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getSrsContTransferReject($dbh) {
    $stmt = $dbh->prepare("SELECT count(identifier) FROM contact WHERE (trdate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND trstatus = 'clientRejected' OR trstatus = 'serverRejected'");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getSrsContTransferRequest($dbh) {
    $stmt = $dbh->prepare("SELECT count(identifier) FROM contact WHERE (trdate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND trstatus = 'pending'");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getTotalDomains($dbh, $registrar) {
    $stmt = $dbh->prepare("SELECT COUNT(name) FROM domain WHERE clid = ?");
    $stmt->execute([$registrar['id']]);
    return $stmt->fetchColumn();
}

function getTotalNameservers($dbh, $registrar) {
    $stmt = $dbh->prepare("SELECT COUNT(name) FROM host WHERE clid = ?");
    $stmt->execute([$registrar['id']]);
    return $stmt->fetchColumn();
}

function getNetAddsByYear($dbh, $registrar, $years) {
    $months = $years * 12;
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE clid = :registrarId AND (crdate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND addPeriod = :months");
    $stmt->bindParam(':months', $months, PDO::PARAM_INT);
    $stmt->bindParam(':registrarId', $registrar['id'], PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getNetRenewsByYear($dbh, $registrar, $years) {
    $months = $years * 12;
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE clid = :registrarId AND (renewedDate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND renewPeriod = :months");
    $stmt->bindParam(':months', $months, PDO::PARAM_INT);
    $stmt->bindParam(':registrarId', $registrar['id'], PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getTransferGainingSuccessful($dbh, $registrar) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferGainingNacked($dbh, $registrar) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferLosingSuccessful($dbh, $registrar) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferLosingNacked($dbh, $registrar) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferDisputedWon($dbh, $registrar) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferDisputedLost($dbh, $registrar) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferDisputedNoDecision($dbh, $registrar) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getDeletedDomainsGrace($dbh, $registrar) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getDeletedDomainsNoGrace($dbh, $registrar) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getRestoredDomains($dbh, $registrar) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE clid = :registrarId AND (resTime BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month))");
    $stmt->bindParam(':registrarId', $registrar['id'], PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getRestoredNoReport($dbh, $registrar) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE clid = :registrarId AND (resTime BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND rgpresReason IS NULL");
    $stmt->bindParam(':registrarId', $registrar['id'], PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getAgpExemptionRequests($dbh, $registrar) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE clid = :registrarId AND (agp_request BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND agp_status = 'requested'");
    $stmt->bindParam(':registrarId', $registrar['id'], PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getAgpExemptionsGranted($dbh, $registrar) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE clid = :registrarId AND (agp_grant BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND agp_status = 'granted'");
    $stmt->bindParam(':registrarId', $registrar['id'], PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getAgpExemptedDomains($dbh, $registrar) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE clid = :registrarId AND (agp_grant BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND agp_exempted = 1");
    $stmt->bindParam(':registrarId', $registrar['id'], PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getAttemptedAdds($dbh, $registrar) {
    $stmt = $dbh->prepare("SELECT count(cmd) FROM registryTransaction.transaction_identifier WHERE (cldate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND cmd = 'create' AND obj_type = 'domain' AND registrar_id = :registrarId");
    $stmt->bindParam(':registrarId', $registrar['id'], PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getTotalDomainsAllRegistrars($dbh) {
    $stmt = $dbh->prepare("SELECT COUNT(name) FROM domain;");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getTotalNameserversAllRegistrars($dbh) {
    $stmt = $dbh->prepare("SELECT COUNT(name) FROM host;");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getNetAddsByYearAllRegistrars($dbh, $years) {
    $months = $years * 12;
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE (crdate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND addPeriod = :months");
    $stmt->bindParam(':months', $months, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getNetRenewsByYearAllRegistrars($dbh, $years) {
    $months = $years * 12;
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE (renewedDate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND renewPeriod = :months");
    $stmt->bindParam(':months', $months, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getTransferGainingSuccessfulAllRegistrars($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferGainingNackedAllRegistrars($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferLosingSuccessfulAllRegistrars($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferLosingNackedAllRegistrars($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferDisputedWonAllRegistrars($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferDisputedLostAllRegistrars($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getTransferDisputedNoDecisionAllRegistrars($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getDeletedDomainsGraceAllRegistrars($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getDeletedDomainsNoGraceAllRegistrars($dbh) {
    // Placeholder: Replace with actual query/logic
    return 0;
}

function getRestoredDomainsAllRegistrars($dbh) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE (resTime BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month))");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getRestoredNoReportAllRegistrars($dbh) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE (resTime BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND rgpresReason IS NULL");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getAgpExemptionRequestsAllRegistrars($dbh) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE (agp_request BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND agp_status = 'requested'");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getAgpExemptionsGrantedAllRegistrars($dbh) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE (agp_grant BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND agp_status = 'granted'");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getAgpExemptedDomainsAllRegistrars($dbh) {
    $stmt = $dbh->prepare("SELECT count(name) FROM domain WHERE (agp_grant BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND agp_exempted = 1");
    $stmt->execute();
    return $stmt->fetchColumn();
}

function getAttemptedAddsAllRegistrars($dbh) {
    $stmt = $dbh->prepare("SELECT count(cmd) FROM registryTransaction.transaction_identifier WHERE (cldate BETWEEN last_day(curdate() - interval 2 month) + interval 1 day AND last_day(curdate() - interval 1 month)) AND cmd = 'create' AND obj_type = 'domain'");
    $stmt->execute();
    return $stmt->fetchColumn();
}

// Upload function
function uploadFileWithRetry($url, $filePath, $username, $password) {
    // Create a Guzzle client with custom middleware
    $stack = HandlerStack::create();
    
    // Define retry middleware
    $stack->push(Middleware::retry(
        function ($retries, $request, $response, $exception) {
            // Retry on server errors or exceptions
            if ($retries >= 5) {
                return false; // Stop after 5 attempts
            }
            if ($exception instanceof RequestException) {
                return true; // Retry on network errors
            }
            if ($response && $response->getStatusCode() >= 500) {
                return true; // Retry on server errors (5xx)
            }
            return false; // Otherwise, don't retry
        },
        function ($retries) {
            // Implement exponential backoff
            return 1000 * pow(2, $retries); // Wait 1s, 2s, 4s, etc.
        }
    ));

    // Create Guzzle client
    $client = new Client([
        'handler' => $stack,
        'auth' => [$username, $password], // Basic authentication
    ]);

    try {
        // Upload the file
        $response = $client->put($url, [
            'body' => fopen($filePath, 'r'),
        ]);

        // Return success response or status
        return $response->getStatusCode() === 200 ? 'Upload successful' : 'Upload failed';
    } catch (RequestException $e) {
        throw new Exception($e->getMessage());
    }
}