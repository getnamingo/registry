<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

use Swoole\Server;
use Namingo\Rately\Rately;

$c = require_once 'config.php';
require_once 'helpers.php';
$logFilePath = '/var/log/namingo/whois.log';
$log = setupLogger($logFilePath, 'WHOIS');

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
$server = new Server($c['whois_ipv4'], 43);
if ($c['whois_ipv6'] !== false) {
    $server->addListener($c['whois_ipv6'], 43, SWOOLE_SOCK_TCP6);
}
$server->set([
    'daemonize' => false,
    'log_file' => '/var/log/namingo/whois_application.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/run/whois.pid',
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

$rateLimiter = new Rately();
$log->info('server started.');

// Register a callback to handle incoming connections
$server->on('connect', function ($server, $fd) use ($log) {
    $log->info('new client connected: ' . $fd);
});

// Register a callback to handle incoming requests
$server->on('receive', function ($server, $fd, $reactorId, $data) use ($c, $pool, $log, $rateLimiter) {
    // Get a PDO connection from the pool
    try {
        $pdo = $pool->get();
        if (!$pdo) {
            throw new PDOException("Failed to retrieve a connection from Swoole PDOPool.");
        }
    } catch (PDOException $e) {
        $log->alert("Swoole PDO Pool failed: " . $e->getMessage());
        $server->send($fd, "Database failure. Please try again later");
        $server->close($fd);
        return;
    }
    $privacy = $c['privacy'];
    $minimum_data = $c['minimum_data'];
    $parsedQuery = parseQuery($data);
    $queryType = $parsedQuery['type'];
    $queryData = $parsedQuery['data'];
    
    $clientInfo = $server->getClientInfo($fd);
    $remoteAddr = $clientInfo['remote_ip'];

    if (!isIpWhitelisted($remoteAddr, $pdo)) {
        if (($c['rately'] == true) && ($rateLimiter->isRateLimited('whois', $remoteAddr, $c['limit'], $c['period']))) {
            $log->error('rate limit exceeded for ' . $remoteAddr);
            $server->send($fd, "rate limit exceeded. Please try again later");
            $server->close($fd);
            return;
        }
    }

    // Handle the WHOIS query
    try {
        switch ($queryType) {
            case 'domain':
                // Handle domain query
                $domain = $queryData;
                
                if (!$domain) {
                    $server->send($fd, "please enter a domain name");
                    $server->close($fd);
                    return;
                }
                if (strlen($domain) > 68) {
                    $server->send($fd, "domain name is too long");
                    $server->close($fd);
                    return;
                }
                // Convert to Punycode if the domain is not in ASCII
                if (!mb_detect_encoding($domain, 'ASCII', true)) {
                    $convertedDomain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    if ($convertedDomain === false) {
                        $server->send($fd, "Domain conversion to Punycode failed");
                        $server->close($fd);
                        return;
                    } else {
                        $domain = $convertedDomain;
                    }
                }
                if (!preg_match('/^(?:(xn--[a-zA-Z0-9-]{1,63}|[a-zA-Z0-9-]{1,63})\.){1,3}(xn--[a-zA-Z0-9-]{2,63}|[a-zA-Z]{2,63})$/', $domain)) {
                    $server->send($fd, "domain name invalid format");
                    $server->close($fd);
                    return;
                }
                $domain = strtoupper($domain);
            
                // Extract TLD from the domain and prepend a dot
                $parts = explode('.', $domain);
                
                // Handle multi-segment TLDs (e.g., co.uk, ngo.us, etc.)
                if (count($parts) > 2) {
                    $tld = "." . $parts[count($parts) - 2] . "." . $parts[count($parts) - 1];
                } else {
                    $tld = "." . end($parts);
                }

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
                if (strpos(strtolower($parts[0]), 'xn--') === 0) {
                    $label = idn_to_utf8(strtolower($parts[0]), IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                } else {
                    $label = strtolower($parts[0]);
                }
                if (!preg_match($idnRegex, $label)) {
                    $server->send($fd, "Domain name invalid IDN characters");
                    $server->close($fd);
                    return;
                }
                
                $query = "SELECT * FROM registry.domain WHERE name = :domain";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':domain', $domain, PDO::PARAM_STR);
                $stmt->execute();

                if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $f['crdate'] = (new DateTime($f['crdate']))->format('Y-m-d\TH:i:s.v\Z');
                    if (isset($f['lastupdate']) && $f['lastupdate'] !== null) {
                        $f['lastupdate'] = (new DateTime($f['lastupdate']))->format('Y-m-d\TH:i:s.v\Z');
                    } else {
                        $f['lastupdate'] = '';
                    }
                    $f['exdate'] = (new DateTime($f['exdate']))->format('Y-m-d\TH:i:s.v\Z');
                    
                    $query2 = "SELECT tld FROM domain_tld WHERE id = :tldid";
                    $stmt2 = $pdo->prepare($query2);
                    $stmt2->bindParam(':tldid', $f['tldid'], PDO::PARAM_INT);
                    $stmt2->execute();

                    $tld = $stmt2->fetch(PDO::FETCH_ASSOC);
                
                    $query3 = "SELECT name,iana_id,whois_server,url,abuse_email,abuse_phone FROM registrar WHERE id = :clid";
                    $stmt3 = $pdo->prepare($query3);
                    $stmt3->bindParam(':clid', $f['clid'], PDO::PARAM_INT);
                    $stmt3->execute();

                    $clidF = $stmt3->fetch(PDO::FETCH_ASSOC);

                    // Check if the domain name is non-ASCII or starts with 'xn--'
                    $isNonAsciiOrPunycode = !mb_check_encoding($f['name'], 'ASCII') || strpos($f['name'], 'xn--') === 0;

                    $res = "Domain Name: ".strtoupper($f['name'])
                        ."\n";

                    // Add the Internationalized Domain Name line if the condition is met
                    if ($isNonAsciiOrPunycode) {
                        // Convert the domain name to UTF-8 and make it uppercase
                        $internationalizedName = idn_to_utf8($f['name'], 0, INTL_IDNA_VARIANT_UTS46);
                        $res .= "Internationalized Domain Name: " . mb_strtoupper($internationalizedName) . "\n";
                    }

                    $res .= "Registrar: ".$clidF['name'];
                        
                    $query9 = "SELECT h.name, 
                              GROUP_CONCAT(ha.addr ORDER BY INET6_ATON(ha.addr) SEPARATOR ', ') AS ips 
                           FROM domain_host_map dhm
                           JOIN host h ON dhm.host_id = h.id
                           LEFT JOIN host_addr ha ON h.id = ha.host_id
                           WHERE dhm.domain_id = :domain_id
                           GROUP BY h.name";
                    $stmt9 = $pdo->prepare($query9);
                    $stmt9->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                    $stmt9->execute();

                    $counter = 0;
                    while ($counter < 13) {
                        $f2 = $stmt9->fetch(PDO::FETCH_ASSOC);
                        if ($f2 === false) break; // Break if there are no more rows
                        $res .= "\nName Server: ".$f2['name'];
                        if (!empty($f2['ips'])) {
                            $res .= " (".$f2['ips'].")"; // Append IPs if available
                        }
                        $counter++;
                    }

                    $currentDateTime = new DateTime();
                    $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
                    $res .= "\n";
                    $server->send($fd, $res . "");
                    
                    $clientInfo = $server->getClientInfo($fd);
                    $remoteAddr = $clientInfo['remote_ip'];
                    $log->notice('new request from ' . $remoteAddr . ' | ' . $domain . ' | FOUND');
                    
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                } else {
                    // Check if domain is reserved
                    $stmtReserved = $pdo->prepare("SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1");
                    $stmtReserved->execute([$parts[0]]);
                    $domain_already_reserved = $stmtReserved->fetchColumn();

                    if ($domain_already_reserved) {
                        $server->send($fd, "Domain name is reserved or restricted");
                        $server->close($fd);
                        return;
                    }

                    //NOT FOUND or No match for;
                    $server->send($fd, "NOT FOUND");
                    
                    $clientInfo = $server->getClientInfo($fd);
                    $remoteAddr = $clientInfo['remote_ip'];
                    $log->notice('new request from ' . $remoteAddr . ' | ' . $domain . ' | NOT FOUND');
                    
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                }
                break;
            case 'nameserver':
                // Handle nameserver query
                $nameserver = $queryData;
                
                if (!$nameserver) {
                    $server->send($fd, "please enter a nameserver");
                    $server->close($fd);
                }
                if (strlen($nameserver) > 63) {
                    $server->send($fd, "nameserver is too long");
                    $server->close($fd);
                }

                // Convert to Punycode if the host is not in ASCII
                if (!mb_detect_encoding($nameserver, 'ASCII', true)) {
                    $convertedDomain = idn_to_ascii($nameserver, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    if ($convertedDomain === false) {
                        $server->send($fd, "Host conversion to Punycode failed.");
                        $server->close($fd);
                    } else {
                        $nameserver = $convertedDomain;
                    }
                }

                if (!isValidHostname($nameserver)) {
                    $server->send($fd, "Nameserver contains invalid characters or is not in the correct format.");
                    $server->close($fd);
                }

                $query = "SELECT name,clid FROM host WHERE name = :nameserver";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':nameserver', $nameserver, PDO::PARAM_STR);
                $stmt->execute();

                if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $res = "Server Name: ".$f['name'];
                        
                    // Fetch the registrar details for this registrar using the id
                    $regQuery = "SELECT id,name,iana_id,whois_server,url,abuse_email,abuse_phone FROM registrar WHERE id = :clid";
                    $regStmt = $pdo->prepare($regQuery);
                    $regStmt->bindParam(':clid', $f['clid'], PDO::PARAM_INT);
                    $regStmt->execute();

                    if ($registrar = $regStmt->fetch(PDO::FETCH_ASSOC)) {
                        // Append the registrar details to the response
                        $res .= "\nRegistrar Name: ".$registrar['name'];
                    }
                        
                    $res .= "\n";
                    $server->send($fd, $res . "");
                        
                    $clientInfo = $server->getClientInfo($fd);
                    $remoteAddr = $clientInfo['remote_ip'];
                    $log->notice('new request from ' . $remoteAddr . ' | ' . $nameserver . ' | FOUND');
                        
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                } else {
                    //NOT FOUND or No match for;
                    $server->send($fd, "NOT FOUND");
                    
                    $clientInfo = $server->getClientInfo($fd);
                    $remoteAddr = $clientInfo['remote_ip'];
                    $log->notice('new request from ' . $remoteAddr . ' | ' . $nameserver . ' | NOT FOUND');
                    
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                }
                break;
            case 'registrar':
                // Handle registrar query
                $registrar = $queryData;
                
                if (!$registrar) {
                    $server->send($fd, "please enter a registrar name");
                    $server->close($fd);
                }
                if (strlen($registrar) > 50) {
                    $server->send($fd, "registrar name is too long");
                    $server->close($fd);
                }
                
                if (!preg_match('/^[a-zA-Z0-9\s\-]+$/', $registrar)) {
                    $server->send($fd, "Registrar name contains invalid characters.");
                    $server->close($fd);
                }
                
                $query = "SELECT id,name,iana_id,whois_server,url,abuse_email,abuse_phone FROM registrar WHERE name = :registrar";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':registrar', $registrar, PDO::PARAM_STR);
                $stmt->execute();

                if ($f = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $res = "Registrar: ".$f['name'];

                    $res .= "\n";
                    $server->send($fd, $res . "");

                    $clientInfo = $server->getClientInfo($fd);
                    $remoteAddr = $clientInfo['remote_ip'];
                    $log->notice('new request from ' . $remoteAddr . ' | ' . $registrar . ' | FOUND');

                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                } else {
                    //NOT FOUND or No match for;
                    $server->send($fd, "NOT FOUND");
                    
                    $clientInfo = $server->getClientInfo($fd);
                    $remoteAddr = $clientInfo['remote_ip'];
                    $log->notice('new request from ' . $remoteAddr . ' | ' . $registrar . ' | NOT FOUND');
                    
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                }
                break;
            default:
                // Handle unknown query type
                $log->error('Error');
                $server->send($fd, "Error");
        }
    } catch (PDOException $e) {
        // Handle database exceptions
        $log->error('Database error: ' . $e->getMessage());
        $server->send($fd, "Error connecting to the whois database");
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
    $log->info('client ' . $fd . ' disconnected.');
});

// Start the server
$server->start();