<?php

require_once 'vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

// Configuration
$keyDir = $c['dns_server'] === 'bind' ? '/var/lib/bind' : '/etc/knot/keys';  // Directory containing key files
$localPhpScript = '/path/to/local-registry-update.php';  // Local PHP script for DS record submission
$adminEmail = 'admin@example.com';  // Email to be included for IANA submission logs
$dnssecTool = $c['dns_server'] === 'bind' ? '/usr/bin/dnssec-dsfromkey' : '/usr/bin/keymgr';  // Tool path
$logFilePath = '/var/log/namingo/dnssec-ds-rotator.log';

$log = setupLogger($logFilePath, 'DNSSEC_DS_Rotator');
$log->info("Starting DS record handling for " . strtoupper($c['dns_server']) . ".");

try {
    // Connect to the database
    $dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
    $dbh = new PDO($dsn, $c['db_username'], $c['db_password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Query the domain_tld table
    $query = "SELECT tld FROM domain_tld";
    $stmt = $dbh->query($query);

    // Loop through all rows
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $zoneName = ltrim($row['tld'], '.'); // Remove leading dots

        // Process the zone name
        $log->info("Processing zone: $zoneName");

        if ($c['dns_server'] === 'bind') {
            // Locate all keys for the zone (BIND)
            $keyFiles = glob("$keyDir/K$zoneName.+*.key");
            if (empty($keyFiles)) {
                $log->error("No keys found for $zoneName in $keyDir.");
                continue;
            }

            // Filter for KSKs (flag 257)
            $kskFiles = [];
            foreach ($keyFiles as $keyFile) {
                $keyContent = file_get_contents($keyFile);
                if (strpos($keyContent, '257') !== false) {
                    $kskFiles[] = $keyFile;
                }
            }

            if (empty($kskFiles)) {
                $log->error("No KSKs found for $zoneName in $keyDir.");
                continue;
            }

            // Process each KSK and generate DS records
            $keys = [];
            foreach ($kskFiles as $kskFile) {
                exec("$dnssecTool -a SHA-256 $kskFile", $output, $returnCode);
                if ($returnCode !== 0 || empty($output)) {
                    $log->error("Failed to generate DS record for $zoneName (key file: $kskFile).");
                    continue;
                }

                $dsRecord = implode("\n", $output);
                $keyData = [
                    'keyFile' => $kskFile,
                    'dsRecord' => $dsRecord,
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
                $keys[] = $keyData;

                $log->info("DS Record Generated for KSK file $kskFile: $dsRecord");
            }
        } elseif ($c['dns_server'] === 'knot') {
            // **Knot DNS: Use keymgr to manage keys and DS records**
            $keys = [];
            exec("$dnssecTool ds $zoneName", $output, $returnCode);
            if ($returnCode !== 0 || empty($output)) {
                $log->error("Failed to generate DS record for $zoneName using Knot DNS.");
                continue;
            }

            $dsRecord = implode("\n", $output);
            $keyData = [
                'dsRecord' => $dsRecord,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
            $keys[] = $keyData;

            $log->info("DS Record Generated for zone $zoneName using Knot DNS: $dsRecord");
        }

        // Prepare data to save
        $data = [
            'zoneName' => $zoneName,
            'timestamp' => date('Y-m-d H:i:s'),
            'keys' => $keys,
        ];

        // Save to /tmp as JSON
        $filePath = "/tmp/{$zoneName}.json";
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $log->info("Saved zone data for $zoneName to $filePath");

        // Determine zone type and handle DS submission
        $levelCount = substr_count($zoneName, '.') + 1;

        if ($levelCount === 1) {
            $log->info("Logging DS record details for manual submission to IANA...");
            $ianaDetails = [
                'Zone' => $zoneName,
                'DS Records' => array_column($keys, 'dsRecord'),
                'Admin Contact' => $adminEmail,
            ];
            $log->info(json_encode($ianaDetails, JSON_PRETTY_PRINT));
            foreach ($keys as $key) {
                $log->info($key['dsRecord']);
            }
        } elseif ($levelCount >= 2) {
            $log->info("DS record for $zoneName should be submitted to the parent registry.");
            foreach ($keys as $key) {
                $log->info($key['dsRecord']);
            }
            // Uncomment this block to submit to parent using the local PHP script
            /*
            $log->info("Submitting DS record to parent zone using local PHP script...");
            $response = shell_exec("php $localPhpScript $zoneName '" . json_encode($keys) . "'");
            if (str_contains($response, 'success')) {
                $log->info("DS record successfully submitted to parent zone for $zoneName.");
            } else {
                $log->error("Failed to submit DS record to parent zone for $zoneName.");
                $log->error("Response from PHP script: $response");
                continue;
            }
            */
        } else {
            $log->error("Unsupported zone type for $zoneName.");
            continue;
        }

        $log->info("DS record handling completed successfully for $zoneName.");
    }

    $log->info('Job finished successfully.');
} catch (PDOException $e) {
    $log->error('DB Connection failed: ' . $e->getMessage());
    exit(1);
} catch (Exception $e) {
    $log->error('An unexpected error occurred: ' . $e->getMessage());
    exit(1);
}
