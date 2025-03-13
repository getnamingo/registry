<?php

namespace App\Controllers;

use App\Models\RegistryTransaction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Nyholm\Psr7\Stream;
use Utopia\System\System;

class ReportsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        $stats = [];
        $db = $this->container->get('db');
        $totalDomains = $db->select('SELECT COUNT(name) as total FROM domain');
        $numT = $totalDomains[0]['total'] ?? 1;

        $registrars = $db->select('SELECT id, name, currency FROM registrar');
        foreach ($registrars as $registrar) {
            $domainCount = $db->select(
                'SELECT COUNT(name) as count FROM domain WHERE clid = ?',
                [$registrar['id']]
            );

            $earnings = $db->select(
                'SELECT SUM(amount) as amt FROM statement WHERE registrar_id = ? AND command <> "deposit"',
                [$registrar['id']]
            );

            $stats[] = [
                'id' => $registrar['id'],
                'registrar' => $registrar['name'],
                'currency' => $registrar['currency'],
                'number' => $domainCount[0]['count'] ?? 0,
                'share' => $numT > 0 
                    ? number_format(($domainCount[0]['count'] ?? 0) / $numT * 100, 2) 
                    : '0.00',
                'earnings' => $earnings[0]['amt'] ?? 0
            ];
        }

        usort($stats, function ($a, $b) {
            return $b['share'] <=> $a['share'];
        });

        return view($response,'admin/reports/index.twig', [
            'stats' => $stats
        ]);
    }
    
    public function exportDomains(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        // Fetch domain data from the database
        $db = $this->container->get('db');
        $domains = $db->select("SELECT name, crdate, exdate FROM domain");

        // Create a temporary memory file for the CSV data
        $csvFile = fopen('php://memory', 'w');
        
        // Define CSV headers
        $headers = ['Domain Name', 'Creation Date', 'Expiration Date'];
        
        // Write the headers to the CSV file
        fputcsv($csvFile, $headers);

        // Write the domain data to the CSV file
        if (!empty($domains) && is_iterable($domains)) {
            foreach ($domains as $domain) {
                fputcsv($csvFile, [
                    $domain['name'], 
                    $domain['crdate'], 
                    $domain['exdate']
                ]);
            }
        }

        // Rewind the file pointer to the beginning of the file
        fseek($csvFile, 0);
        
        // Create a stream from the in-memory file
        $stream = Stream::create($csvFile);
        
        // Prepare the response headers for CSV file download
        $response = $response->withHeader('Content-Type', 'text/csv')
                             ->withHeader('Content-Disposition', 'attachment; filename="domains_export.csv"')
                             ->withHeader('Pragma', 'no-cache')
                             ->withHeader('Expires', '0');

        // Output the CSV content to the response body
        return $response->withBody($stream);
    }

    public function serverHealth(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $csrfTokenName = $this->container->get('csrf')->getTokenName();
        $csrfTokenValue = $this->container->get('csrf')->getTokenValue();
        
        // Helper function to check service status
        $checkServiceStatus = function ($serviceName) {
            $output = @shell_exec("service $serviceName status");
            return ($output && strpos($output, 'active (running)') !== false) ? 'Running' : 'Stopped';
        };

        // Helper function to read the last 50 lines of a log file and convert to string
        $getLogLines = function ($logPrefix) {
            $currentDate = date('Y-m-d');
            $logFile = "/var/log/namingo/{$logPrefix}-$currentDate.log";

            if (file_exists($logFile)) {
                $output = @shell_exec("tail -n 50 " . escapeshellarg($logFile));
                return $output ? $output : "Log file is empty.";
            }

            return "Log file not found: $logFile";
        };
        
        // Check statuses
        $eppStatus = $checkServiceStatus('epp');
        $whoisStatus = $checkServiceStatus('whois');
        $rdapStatus = $checkServiceStatus('rdap');
        $dasStatus = $checkServiceStatus('das');
        $msgbStatus = $checkServiceStatus('msg_producer');
        $msgwStatus = $checkServiceStatus('msg_worker');
        $redisStatus = $checkServiceStatus('redis');

        // Get log lines as strings
        $eppLogs = $getLogLines('epp');
        $whoisLogs = $getLogLines('whois');
        $rdapLogs = $getLogLines('rdap');
        $dasLogs = $getLogLines('das');
        $msgbLogs = $getLogLines('msg_producer');
        $msgwLogs = $getLogLines('msg_worker');

        $system = new System();

        $serverHealth = [
            'getCPUCores' => $system->getCPUCores(),
            'getCPUUsage' => $system->getCPUUsage(),
            'getMemoryTotal' => $system->getMemoryTotal(),
            'getMemoryFree' => $system->getMemoryFree(),
            'getDiskTotal' => $system->getDiskTotal(),
            'getDiskFree' => $system->getDiskFree()
        ];

        $logFile = '/var/log/namingo/backup.log';

        // Check if the file exists
        if (!file_exists($logFile)) {
            $backupSummary = "Backup log file not found.";
        } else {
            // Read and decode JSON file
            $logData = json_decode(file_get_contents($logFile), true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($logData)) {
                $backupSummary = "Invalid JSON format in backup log file.";
            } else {
                // Start building the summary
                $backupSummary = "Backup Summary:\n";
                $backupSummary .= "Timestamp: " . date('Y-m-d H:i:s', $logData['timestamp'] ?? time()) . "\n";
                $backupSummary .= "Duration: " . round($logData['duration'] ?? 0, 2) . " seconds\n";
                $backupSummary .= "Total Backups: " . ($logData['backupCount'] ?? 0) . "\n";
                $backupSummary .= "Failed Backups: " . ($logData['backupFailed'] ?? 0) . "\n";
                $backupSummary .= "Errors: " . ($logData['errorCount'] ?? 0) . "\n";

                if (!empty($logData['backups'])) {
                    foreach ($logData['backups'] as $backup) {
                        $backupSummary .= "\nBackup: " . ($backup['name'] ?? 'Unknown') . "\n";
                        $backupSummary .= "- Status: " . (($backup['status'] ?? 1) === 0 ? 'Success' : 'Failed') . "\n";
                        $backupSummary .= "- Checks: " . ($backup['checks']['executed'] ?? 0) . " executed, " . ($backup['checks']['failed'] ?? 0) . " failed\n";
                        $backupSummary .= "- Syncs: " . ($backup['syncs']['executed'] ?? 0) . " executed, " . ($backup['syncs']['failed'] ?? 0) . " failed\n";
                        $backupSummary .= "- Cleanup: " . ($backup['cleanup']['executed'] ?? 0) . " executed, " . ($backup['cleanup']['failed'] ?? 0) . " failed\n";
                    }
                }

                if (!empty($logData['debug'])) {
                    $backupSummary .= "\nDebug Info (last 5 entries):\n";
                    $debugEntries = array_slice($logData['debug'], -5);
                    foreach ($debugEntries as $entry) {
                        $backupSummary .= "- $entry\n";
                    }
                }
            }
        }

        $db = $this->container->get('db');
        $whoisQueries = $db->selectValue("SELECT value FROM settings WHERE name = 'whois-43-queries'");
        $webWhoisQueries = $db->selectValue("SELECT value FROM settings WHERE name = 'web-whois-queries'");

        return $this->view->render($response, 'admin/reports/serverHealth.twig', [
            'serverHealth' => $serverHealth,
            'csrfTokenName' => $csrfTokenName,
            'csrfTokenValue' => $csrfTokenValue,
            'backupLog' => nl2br(htmlspecialchars($backupSummary)),
            'eppStatus' => $eppStatus,
            'whoisStatus' => $whoisStatus,
            'rdapStatus' => $rdapStatus,
            'dasStatus' => $dasStatus,
            'eppLogs' => $eppLogs,
            'whoisLogs' => $whoisLogs,
            'rdapLogs' => $rdapLogs,
            'dasLogs' => $dasLogs,
            'msgbStatus' => $msgbStatus,
            'msgwStatus' => $msgwStatus,
            'msgbLogs' => $msgbLogs,
            'msgwLogs' => $msgwLogs,
            'redisStatus' => $redisStatus,
            'whoisQueries' => $whoisQueries,
            'webWhoisQueries' => $webWhoisQueries
        ]);
    }

    public function clearCache(Request $request, Response $response): Response
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $result = [
            'success' => true,
            'message' => 'Cache cleared successfully!',
        ];
        $cacheDir = '/var/www/cp/cache';

        try {
            // Check if the cache directory exists
            if (!is_dir($cacheDir)) {
                throw new RuntimeException('Cache directory does not exist.');
            }
            
            // Iterate through the files and directories in the cache directory
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                // Check if the parent directory name is exactly two letters/numbers long
                if (preg_match('/^[a-zA-Z0-9]{2}$/', $fileinfo->getFilename()) ||
                    preg_match('/^[a-zA-Z0-9]{2}$/', basename(dirname($fileinfo->getPathname())))) {
                    $action = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                    $action($fileinfo->getRealPath());
                }
            }

            // Delete the two-letter/number directories themselves
            $dirs = new \DirectoryIterator($cacheDir);
            foreach ($dirs as $dir) {
                if ($dir->isDir() && !$dir->isDot() && preg_match('/^[a-zA-Z0-9]{2}$/', $dir->getFilename())) {
                    rmdir($dir->getRealPath());
                }
            }
        } catch (Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Error clearing cache: ' . $e->getMessage(),
            ];
        }

        // Respond with the result as JSON
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

}