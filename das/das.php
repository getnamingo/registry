<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

// Create a Swoole TCP server
$server = new Swoole\Server('0.0.0.0', 1043);
$server->set([
    'daemonize' => false,
    'log_file' => '/var/log/das/das.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/log/das/das.pid',
    'max_request' => 1000,
    'dispatch_mode' => 2,
    'open_tcp_nodelay' => true,
    'max_conn' => 10000,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120,
    'buffer_output_size' => 2 * 1024 * 1024, // 2MB
    'enable_reuse_port' => true,
    'package_max_length' => 8192, // 8KB
    'open_eof_check' => true,
    'package_eof' => "\r\n"
]);

// Register a callback to handle incoming connections
$server->on('connect', function ($server, $fd) {
    echo "Client connected: {$fd}\r\n";
});

// Register a callback to handle incoming requests
$server->on('receive', function ($server, $fd, $reactorId, $data) {
    // Connect to the database
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=registry', 'registry-select', 'EPPRegistrySELECT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        $server->send($fd, "Error connecting to database");
        $server->close($fd);
    }
	
    // Validate and sanitize the domain name
    $domain = trim($data);
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
	
    // Perform the DAS lookup
	try {
		$query = "SELECT name FROM `registry`.`domain` WHERE `name` = :domain";
		$stmt = $pdo->prepare($query);
		$stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
		$stmt->execute();

		if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$server->send($fd, "1");

			if ($fp = @fopen("/var/log/das/das_request.log",'a')) {
				$clientInfo = $server->getClientInfo($fd);
				$remoteAddr = $clientInfo['remote_ip'];
				fwrite($fp,date('Y-m-d H:i:s')."\t-\t".$remoteAddr."\t-\t".$domain."\n");
				fclose($fp);
			}
			$server->close($fd);
		} else {
			$server->send($fd, "0");

			if ($fp = @fopen("/var/log/das/das_not_found.log",'a')) {
				$clientInfo = $server->getClientInfo($fd);
				$remoteAddr = $clientInfo['remote_ip'];
				fwrite($fp,date('Y-m-d H:i:s')."\t-\t".$remoteAddr."\t-\t".$domain."\n");
				fclose($fp);
			}
			$server->close($fd);
		}
	} catch (PDOException $e) {
        $server->send($fd, "Error connecting to the das database");
        $server->close($fd);
	}

    // Close the connection
    $pdo = null;
});

// Register a callback to handle client disconnections
$server->on('close', function ($server, $fd) {
    echo "Client disconnected: {$fd}\r\n";
});

// Start the server
$server->start();