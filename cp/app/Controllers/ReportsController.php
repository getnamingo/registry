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
                "SELECT SUM(amount) as amt FROM statement WHERE registrar_id = ? AND command <> 'deposit'",
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
        // Never treat a missing role as administrator.
        if (!isset($_SESSION['auth_roles'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403);
        }

        $role = (int) $_SESSION['auth_roles'];
        $db = $this->container->get('db');

        $sql = '
            SELECT
                d.name,
                c.identifier AS registrant,
                d.crdate,
                d.exdate
            FROM domain d
            LEFT JOIN contact c ON c.id = d.registrant
        ';

        $params = [];

        // Role 0 is registry administrator and may export all domains.
        // Every other role is restricted to its assigned registrar.
        if ($role !== 0) {
            $registrarId = isset($_SESSION['auth_registrar_id'])
                ? (int) $_SESSION['auth_registrar_id']
                : 0;

            // Fail closed if the session has no valid registrar assignment.
            if ($registrarId <= 0) {
                $response->getBody()->write('Forbidden');
                return $response->withStatus(403);
            }

            $sql .= ' WHERE d.clid = ?';
            $params[] = $registrarId;
        }

        $sql .= ' ORDER BY d.name ASC';

        $domains = empty($params)
            ? $db->select($sql)
            : $db->select($sql, $params);

        $csvFile = fopen('php://temp/maxmemory:5242880', 'w+');

        if ($csvFile === false) {
            $response->getBody()->write('Unable to create export');
            return $response->withStatus(500);
        }

        fwrite($csvFile, "\xEF\xBB\xBF");

        fputcsv($csvFile, [
            'Domain Name',
            'Registrant',
            'Creation Date',
            'Expiration Date',
        ]);

        $csvSafe = static function ($value): string {
            if ($value === null) {
                return '';
            }

            $value = (string) $value;

            if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
                return "'" . $value;
            }

            return $value;
        };

        if (!empty($domains) && is_iterable($domains)) {
            foreach ($domains as $domain) {
                fputcsv($csvFile, [
                    $csvSafe($domain['name'] ?? ''),
                    $csvSafe($domain['registrant'] ?? ''),
                    $domain['crdate'] ?? '',
                    $domain['exdate'] ?? '',
                ]);
            }
        }

        rewind($csvFile);

        $stream = Stream::create($csvFile);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="domains-' . date('Y-m-d') . '.csv"'
            )
            ->withHeader(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate'
            )
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($stream);
    }

    public function exportApplications(Request $request, Response $response)
    {
        // Never treat a missing role as administrator.
        if (!isset($_SESSION['auth_roles'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403);
        }

        $role = (int) $_SESSION['auth_roles'];
        $db = $this->container->get('db');

        $sql = '
            SELECT
                d.name,
                c.identifier AS registrant,
                d.crdate,
                d.exdate
            FROM application d
            LEFT JOIN contact c ON c.id = d.registrant
        ';

        $params = [];

        // Role 0 is registry administrator and may export all applications.
        // Every other role is restricted to its assigned registrar.
        if ($role !== 0) {
            $registrarId = isset($_SESSION['auth_registrar_id'])
                ? (int) $_SESSION['auth_registrar_id']
                : 0;

            // Fail closed if the session has no valid registrar assignment.
            if ($registrarId <= 0) {
                $response->getBody()->write('Forbidden');
                return $response->withStatus(403);
            }

            $sql .= ' WHERE d.clid = ?';
            $params[] = $registrarId;
        }

        $sql .= ' ORDER BY d.name ASC';

        $applications = empty($params)
            ? $db->select($sql)
            : $db->select($sql, $params);

        $csvFile = fopen('php://temp/maxmemory:5242880', 'w+');

        if ($csvFile === false) {
            $response->getBody()->write('Unable to create export');
            return $response->withStatus(500);
        }

        fwrite($csvFile, "\xEF\xBB\xBF");

        fputcsv($csvFile, [
            'Application Name',
            'Registrant',
            'Creation Date',
            'Expiration Date',
        ]);

        $csvSafe = static function ($value): string {
            if ($value === null) {
                return '';
            }

            $value = (string) $value;

            if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
                return "'" . $value;
            }

            return $value;
        };

        if (!empty($applications) && is_iterable($applications)) {
            foreach ($applications as $application) {
                fputcsv($csvFile, [
                    $csvSafe($application['name'] ?? ''),
                    $csvSafe($application['registrant'] ?? ''),
                    $application['crdate'] ?? '',
                    $application['exdate'] ?? '',
                ]);
            }
        }

        rewind($csvFile);

        $stream = Stream::create($csvFile);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="applications-' . date('Y-m-d') . '.csv"'
            )
            ->withHeader(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate'
            )
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($stream);
    }

    public function exportHosts(Request $request, Response $response)
    {
        // Never treat a missing role as administrator.
        if (!isset($_SESSION['auth_roles'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403);
        }

        $role = (int) $_SESSION['auth_roles'];
        $db = $this->container->get('db');

        $sql = '
            SELECT
                h.name,
                h.crdate,
                h.lastupdate
            FROM host h
        ';

        $params = [];

        // Role 0 is registry administrator and may export all hosts.
        // Every other role is restricted to its assigned registrar.
        if ($role !== 0) {
            $registrarId = isset($_SESSION['auth_registrar_id'])
                ? (int) $_SESSION['auth_registrar_id']
                : 0;

            // Fail closed if the session has no valid registrar assignment.
            if ($registrarId <= 0) {
                $response->getBody()->write('Forbidden');
                return $response->withStatus(403);
            }

            $sql .= ' WHERE h.clid = ?';
            $params[] = $registrarId;
        }

        $sql .= ' ORDER BY h.name ASC';

        $hosts = empty($params)
            ? $db->select($sql)
            : $db->select($sql, $params);

        $csvFile = fopen('php://temp/maxmemory:5242880', 'w+');

        if ($csvFile === false) {
            $response->getBody()->write('Unable to create export');
            return $response->withStatus(500);
        }

        fwrite($csvFile, "\xEF\xBB\xBF");

        fputcsv($csvFile, [
            'Host Name',
            'Creation Date',
            'Last Updated',
        ]);

        $csvSafe = static function ($value): string {
            if ($value === null) {
                return '';
            }

            $value = (string) $value;

            if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
                return "'" . $value;
            }

            return $value;
        };

        if (!empty($hosts) && is_iterable($hosts)) {
            foreach ($hosts as $host) {
                fputcsv($csvFile, [
                    $csvSafe($host['name'] ?? ''),
                    $host['crdate'] ?? '',
                    $host['exdate'] ?? '',
                ]);
            }
        }

        rewind($csvFile);

        $stream = Stream::create($csvFile);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="hosts-' . date('Y-m-d') . '.csv"'
            )
            ->withHeader(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate'
            )
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($stream);
    }

    public function exportContacts(Request $request, Response $response)
    {
        // Never treat a missing role as administrator.
        if (!isset($_SESSION['auth_roles'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403);
        }

        $role = (int) $_SESSION['auth_roles'];
        $db = $this->container->get('db');

        $sql = '
            SELECT
                c.identifier,
                c.email,
                c.voice,
                c.crdate
            FROM contact c
        ';

        $params = [];

        // Role 0 is registry administrator and may export all contacts.
        // Every other role is restricted to its assigned registrar.
        if ($role !== 0) {
            $registrarId = isset($_SESSION['auth_registrar_id'])
                ? (int) $_SESSION['auth_registrar_id']
                : 0;

            // Fail closed if the session has no valid registrar assignment.
            if ($registrarId <= 0) {
                $response->getBody()->write('Forbidden');
                return $response->withStatus(403);
            }

            $sql .= ' WHERE c.clid = ?';
            $params[] = $registrarId;
        }

        $sql .= ' ORDER BY c.identifier ASC';

        $contacts = empty($params)
            ? $db->select($sql)
            : $db->select($sql, $params);

        $csvFile = fopen('php://temp/maxmemory:5242880', 'w+');

        if ($csvFile === false) {
            $response->getBody()->write('Unable to create export');
            return $response->withStatus(500);
        }

        fwrite($csvFile, "\xEF\xBB\xBF");

        fputcsv($csvFile, [
            'Identifier',
            'Email',
            'Phone',
            'Creation Date',
        ]);

        $csvSafe = static function ($value): string {
            if ($value === null) {
                return '';
            }

            $value = (string) $value;

            if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
                return "'" . $value;
            }

            return $value;
        };

        if (!empty($contacts) && is_iterable($contacts)) {
            foreach ($contacts as $contact) {
                fputcsv($csvFile, [
                    $csvSafe($contact['identifier'] ?? ''),
                    $contact['email'] ?? '',
                    $contact['voice'] ?? '',
                    $contact['crdate'] ?? '',
                ]);
            }
        }

        rewind($csvFile);

        $stream = Stream::create($csvFile);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="contacts-' . date('Y-m-d') . '.csv"'
            )
            ->withHeader(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate'
            )
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($stream);
    }

    public function exportRegistrars(Request $request, Response $response)
    {
        // Never treat a missing role as administrator.
        if (!isset($_SESSION['auth_roles'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403);
        }

        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $role = (int) $_SESSION['auth_roles'];
        $db = $this->container->get('db');

        $sql = '
            SELECT
                r.name,
                r.iana_id,
                r.email
            FROM registrar r
        ';

        $sql .= ' ORDER BY r.name ASC';

        $registrars = $db->select($sql);

        $csvFile = fopen('php://temp/maxmemory:5242880', 'w+');

        if ($csvFile === false) {
            $response->getBody()->write('Unable to create export');
            return $response->withStatus(500);
        }

        fwrite($csvFile, "\xEF\xBB\xBF");

        fputcsv($csvFile, [
            'Name',
            'IANA ID',
            'Email',
        ]);

        $csvSafe = static function ($value): string {
            if ($value === null) {
                return '';
            }

            $value = (string) $value;

            if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
                return "'" . $value;
            }

            return $value;
        };

        if (!empty($registrars) && is_iterable($registrars)) {
            foreach ($registrars as $registrar) {
                fputcsv($csvFile, [
                    $csvSafe($registrar['name'] ?? ''),
                    $registrar['iana_id'] ?? '',
                    $registrar['email'] ?? '',
                ]);
            }
        }

        rewind($csvFile);

        $stream = Stream::create($csvFile);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="registrars-' . date('Y-m-d') . '.csv"'
            )
            ->withHeader(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate'
            )
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($stream);
    }
    
    public function exportUsers(Request $request, Response $response)
    {
        // Never treat a missing role as administrator.
        if (!isset($_SESSION['auth_roles'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403);
        }

        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $role = (int) $_SESSION['auth_roles'];
        $db = $this->container->get('db');

        $sql = '
            SELECT
                u.email,
                u.username,
                u.roles_mask,
                u.verified,
                u.status
            FROM users u
        ';

        $sql .= ' ORDER BY u.email ASC';

        $users = $db->select($sql);

        $csvFile = fopen('php://temp/maxmemory:5242880', 'w+');

        if ($csvFile === false) {
            $response->getBody()->write('Unable to create export');
            return $response->withStatus(500);
        }

        fwrite($csvFile, "\xEF\xBB\xBF");

        fputcsv($csvFile, [
            'Email',
            'User Name',
            'Roles',
            'Verified',
            'Status',
        ]);

        $csvSafe = static function ($value): string {
            if ($value === null) {
                return '';
            }

            $value = (string) $value;

            if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
                return "'" . $value;
            }

            return $value;
        };

        if (!empty($users) && is_iterable($users)) {
            foreach ($users as $user) {
                fputcsv($csvFile, [
                    $csvSafe($user['email'] ?? ''),
                    $user['username'] ?? '',
                    $user['roles_mask'] ?? '',
                    $user['verified'] ?? '',
                    $user['status'] ?? '',
                ]);
            }
        }

        rewind($csvFile);

        $stream = Stream::create($csvFile);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="users-' . date('Y-m-d') . '.csv"'
            )
            ->withHeader(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate'
            )
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($stream);
    }

    public function exportTransactions(Request $request, Response $response)
    {
        // Never treat a missing role as administrator.
        if (!isset($_SESSION['auth_roles'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403);
        }

        $role = (int) $_SESSION['auth_roles'];
        $db = $this->container->get('db');

        $sql = '
            SELECT
                r.name AS registrar,
                s.date,
                s.command,
                s.amount
            FROM statement AS s
            INNER JOIN registrar AS r
                ON r.id = s.registrar_id';

        $params = [];

        // Role 0 is registry administrator and may export all transactions.
        // Every other role is restricted to its assigned registrar.
        if ($role !== 0) {
            $registrarId = isset($_SESSION['auth_registrar_id'])
                ? (int) $_SESSION['auth_registrar_id']
                : 0;

            // Fail closed if the session has no valid registrar assignment.
            if ($registrarId <= 0) {
                $response->getBody()->write('Forbidden');
                return $response->withStatus(403);
            }

            $sql .= ' WHERE s.registrar_id = ?';
            $params[] = $registrarId;
        }

        $sql .= ' ORDER BY s.date DESC';

        $transactions = empty($params)
            ? $db->select($sql)
            : $db->select($sql, $params);

        $csvFile = fopen('php://temp/maxmemory:5242880', 'w+');

        if ($csvFile === false) {
            $response->getBody()->write('Unable to create export');
            return $response->withStatus(500);
        }

        fwrite($csvFile, "\xEF\xBB\xBF");

        fputcsv($csvFile, [
            'Registrar',
            'Date',
            'Command',
            'Amount',
        ]);

        $csvSafe = static function ($value): string {
            if ($value === null) {
                return '';
            }

            $value = (string) $value;

            if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
                return "'" . $value;
            }

            return $value;
        };

        if (!empty($transactions) && is_iterable($transactions)) {
            foreach ($transactions as $transaction) {
                fputcsv($csvFile, [
                    $csvSafe($transaction['registrar'] ?? ''),
                    $transaction['date'] ?? '',
                    $transaction['command'] ?? '',
                    $transaction['amount'] ?? '',
                ]);
            }
        }

        rewind($csvFile);

        $stream = Stream::create($csvFile);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="transactions-' . date('Y-m-d') . '.csv"'
            )
            ->withHeader(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate'
            )
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($stream);
    }

    public function exportEppActivity(Request $request, Response $response)
    {
        // Never treat a missing role as administrator.
        if (!isset($_SESSION['auth_roles'])) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403);
        }

        $role = (int) $_SESSION['auth_roles'];
        $db = $this->container->get('db');

        $sql = '
            SELECT
                r.name AS registrar,
                t.cmd,
                t.obj_type,
                t.obj_id,
                t.code,
                t.msg,
                t.cldate
            FROM registryTransaction.transaction_identifier AS t
            INNER JOIN registrar AS r
                ON r.id = t.registrar_id';

        $params = [];

        // Role 0 is registry administrator and may export all activity.
        // Every other role is restricted to its assigned registrar.
        if ($role !== 0) {
            $registrarId = isset($_SESSION['auth_registrar_id'])
                ? (int) $_SESSION['auth_registrar_id']
                : 0;

            // Fail closed if the session has no valid registrar assignment.
            if ($registrarId <= 0) {
                $response->getBody()->write('Forbidden');
                return $response->withStatus(403);
            }

            $sql .= ' WHERE t.registrar_id = ?';
            $params[] = $registrarId;
        }

        $sql .= ' ORDER BY t.cldate DESC';

        $activity = empty($params)
            ? $db->select($sql)
            : $db->select($sql, $params);

        $csvFile = fopen('php://temp/maxmemory:5242880', 'w+');

        if ($csvFile === false) {
            $response->getBody()->write('Unable to create export');
            return $response->withStatus(500);
        }

        fwrite($csvFile, "\xEF\xBB\xBF");

        fputcsv($csvFile, [
            'Registrar',
            'Command',
            'Object Type',
            'Object',
            'Result',
            'Message',
            'Request Date',
        ]);

        $csvSafe = static function ($value): string {
            if ($value === null) {
                return '';
            }

            $value = (string) $value;

            if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
                return "'" . $value;
            }

            return $value;
        };

        if (!empty($activity) && is_iterable($activity)) {
            foreach ($activity as $transaction) {
                fputcsv($csvFile, [
                    $csvSafe($transaction['registrar'] ?? ''),
                    $transaction['cmd'] ?? '',
                    $transaction['obj_type'] ?? '',
                    $transaction['obj_id'] ?? '',
                    $transaction['code'] ?? '',
                    $transaction['msg'] ?? '',
                    $transaction['cldate'] ?? '',
                ]);
            }
        }

        rewind($csvFile);

        $stream = Stream::create($csvFile);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="epp-activity-' . date('Y-m-d') . '.csv"'
            )
            ->withHeader(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate'
            )
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($stream);
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

        $os = 'Namingo registry with hostname ' . $system->getHostname() . ' running on ' . $system->getOS() . ' (' . $system->getArch() . ')';

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
            'webWhoisQueries' => $webWhoisQueries,
            'os' => $os,
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

            // Clear Slim route cache if it exists
            $routeCacheFile = $cacheDir . '/routes.php';
            if (file_exists($routeCacheFile)) {
                unlink($routeCacheFile);
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