<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

use Swoole\Server;

$c = require_once 'config.php';
require_once 'helpers.php';
$logFilePath = '/var/log/namingo/das.log';
$log = setupLogger($logFilePath, 'DAS');

// Initialize the PDO connection pool
$pool = new Swoole\Database\PDOPool(
    (new Swoole\Database\PDOConfig())
        ->withDriver($c['db_type'])
        ->withHost($c['db_host'])
        ->withPort($c['db_port'])
        ->withDbName($c['db_database'])
        ->withUsername($c['db_username'])
        ->withPassword($c['db_password'])
        ->withCharset('utf8mb4')
);

// Create a Swoole TCP server
$server = new Server('0.0.0.0', 1043);
$server->set([
    'daemonize' => false,
    'log_file' => '/var/log/namingo/das_application.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/run/das.pid',
    'max_request' => 1000,
    'dispatch_mode' => 2,
    'open_tcp_nodelay' => true,
    'max_conn' => 1024,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120,
    'buffer_output_size' => 2 * 1024 * 1024, // 2MB
    'enable_reuse_port' => true,
    'package_max_length' => 8192, // 8KB
    'open_eof_check' => true,
    'package_eof' => "\r\n"
]);
$log->info('server started.');

// Register a callback to handle incoming connections
$server->on('connect', function ($server, $fd) use ($log) {
    $log->info('new client connected: ' . $fd);
});

// Register a callback to handle incoming requests
$server->on('receive', function ($server, $fd, $reactorId, $data) use ($c, $pool, $log) {
    // Get a PDO connection from the pool
    $pdo = $pool->get();
    $domain = trim($data);
    
    // Perform the DAS lookup
    try {
        // Validate and sanitize the domain name
        if (!$domain) {
            $server->send($fd, "please enter a domain name");
            $server->close($fd);
        }
        if (strlen($domain) > 68) {
            $server->send($fd, "domain name is too long");
            $server->close($fd);
        }
        $domain = strtoupper($domain);
        if (preg_match("/(^-|^\.|-\.|\.-|--|\.\.|-$|\.$)/", $domain)) {
            $server->send($fd, "domain name invalid format");
            $server->close($fd);
        }
        
        // Extract TLD from the domain and prepend a dot
        $parts = explode('.', $domain);
        $tld = "." . end($parts);

        // Check if the TLD exists in the domain_tld table
        $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM domain_tld WHERE tld = :tld");
        $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
        $stmtTLD->execute();
        $tldExists = $stmtTLD->fetchColumn();

        if (!$tldExists) {
            $server->send($fd, "Invalid TLD. Please search only allowed TLDs");
            $server->close($fd);
            return;
        }
        
        // Check if domain is reserved
        $stmtReserved = $pdo->prepare("SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1");
        $stmtReserved->execute([$parts[0]]);
        $domain_already_reserved = $stmtReserved->fetchColumn();

        if ($domain_already_reserved) {
            $server->send($fd, "Domain name is reserved or restricted");
            $server->close($fd);
            return;
        }

        // Fetch the IDN regex for the given TLD
        $stmtRegex = $pdo->prepare("SELECT idn_table FROM domain_tld WHERE tld = :tld");
        $stmtRegex->bindParam(':tld', $tld, PDO::PARAM_STR);
        $stmtRegex->execute();
        $idnRegex = $stmtRegex->fetchColumn();

        if (!$idnRegex) {
            $server->send($fd, "Failed to fetch domain IDN table");
            $server->close($fd);
            return;
        }

        // Check for invalid characters using fetched regex
        if (!preg_match($idnRegex, $domain)) {
            $server->send($fd, "Domain name invalid format");
            $server->close($fd);
            return;
        }

        $query = "SELECT name FROM registry.domain WHERE name = :domain";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();

        if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $server->send($fd, "1");

            $clientInfo = $server->getClientInfo($fd);
            $remoteAddr = $clientInfo['remote_ip'];
            $log->notice('new request from ' . $remoteAddr . ' | ' . $domain . ' | FOUND');
        } else {
            $server->send($fd, "0");

            $clientInfo = $server->getClientInfo($fd);
            $remoteAddr = $clientInfo['remote_ip'];
            $log->notice('new request from ' . $remoteAddr . ' | ' . $domain . ' | NOT FOUND');
        }
    } catch (PDOException $e) {
        // Handle database exceptions
        $log->error('Database error: ' . $e->getMessage());
        $server->send($fd, "Error connecting to the DAS database");
        $server->close($fd);
    } catch (Throwable $e) {
        // Catch any other exceptions or errors
        $log->error('Error: ' . $e->getMessage());
        $server->send($fd, "Error");
        $server->close($fd);
    } finally {
        // Return the connection to the pool
        $pool->put($pdo);
        $server->close($fd);
    }
});

// Register a callback to handle client disconnections
$server->on('close', function ($server, $fd) use ($log) {
    $log->info('client ' . $fd . ' connected.');
});

// Start the server
$server->start();