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
    'pid_file' => '/var/log/das/das.pid'
]);

// Register a callback to handle incoming connections
$server->on('connect', function ($server, $fd) {
    echo "Client connected: {$fd}\r\n";
});

// Register a callback to handle incoming requests
$server->on('receive', function ($server, $fd, $reactorId, $data) {
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
    if (preg_match("/[^A-Z0-9\.\-]/", $domain)) {
        $server->send($fd, "domain name invalid format");
        $server->close($fd);
    }
    if (preg_match("/(^-|^\.|-\.|\.-|--|\.\.|-$|\.$)/", $domain)) {
        $server->send($fd, "domain name invalid format");
        $server->close($fd);
    }
    if (!preg_match("/^[A-Z0-9-]+\.(XX|COM\.XX|ORG\.XX|INFO\.XX|PRO\.XX)$/", $domain)) {
        $server->send($fd, "please search only XX domains at least 2 letters");
        $server->close($fd);
    }

    // Connect to the database
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=registry', 'registry-select', 'EPPRegistrySELECT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        $server->send($fd, "Error connecting to database");
        $server->close($fd);
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