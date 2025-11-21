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
    $pdo = null;

    try {
        $pdo = $pool->get();
        if (!$pdo) {
            throw new PDOException("Failed to retrieve a connection from database pool.");
        }
    } catch (PDOException $e) {
        $log->alert("Swoole PDO Pool failed: " . $e->getMessage());
        $server->send($fd, "Database failure. Please try again later");
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $server->send($fd, "Error");
    } finally {
        if ($pdo instanceof PDO) {
            $pool->put($pdo);
        }
        $server->close($fd);
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

                    $stmt = $pdo->query("SELECT value FROM settings WHERE name = 'handle'");
                    $roid = $stmt->fetchColumn();

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

                    if (empty($c['limited_whois']) || !$c['limited_whois']) {
                        $res .= "Registry Domain ID: D".$f['id']."-".$roid
                            ."\nRegistrar WHOIS Server: ".$clidF['whois_server']
                            ."\nRegistrar URL: ".$clidF['url']
                            ."\nUpdated Date: ".$f['lastupdate']
                            ."\nCreation Date: ".$f['crdate']
                            ."\nRegistry Expiry Date: ".$f['exdate']
                            ."\nRegistrar: ".$clidF['name']
                            ."\nRegistrar IANA ID: ".$clidF['iana_id']
                            ."\nRegistrar Abuse Contact Email: ".$clidF['abuse_email']
                            ."\nRegistrar Abuse Contact Phone: ".$clidF['abuse_phone'];
                    } else {
                        $res .= "Registrar: ".$clidF['name'];
                        $res .= "\nUpdated Date: ".$f['lastupdate'];
                    }

                    if (empty($c['limited_whois']) || !$c['limited_whois']) {
                        $query4 = "SELECT status FROM domain_status WHERE domain_id = :domain_id";
                        $stmt4 = $pdo->prepare($query4);
                        $stmt4->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                        $stmt4->execute();

                        $statusFound = false;

                        while ($f2 = $stmt4->fetch(PDO::FETCH_ASSOC)) {
                            $res .= "\nDomain Status: " . $f2['status'] . " https://icann.org/epp#" . $f2['status'];
                            $statusFound = true;
                        }

                        // Check for additional statuses
                        if (!empty($f['rgpstatus'])) {
                            $res .= "\nDomain Status: " . $f['rgpstatus'] . " https://icann.org/epp#" . $f['rgpstatus'];
                            $statusFound = true;
                        }

                        // If no status is found, default to 'ok'
                        if (!$statusFound) {
                            $res .= "\nDomain Status: ok https://icann.org/epp#ok";
                        }
                    }

                    if (!$minimum_data) {
                        $query5 = "SELECT contact.id,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.fax,contact.email,contact.disclose_voice,contact.disclose_fax,contact.disclose_email,contact_postalInfo.type,contact_postalInfo.disclose_name_int,contact_postalInfo.disclose_name_loc,contact_postalInfo.disclose_org_int,contact_postalInfo.disclose_org_loc,contact_postalInfo.disclose_addr_int,contact_postalInfo.disclose_addr_loc
                        FROM contact,contact_postalInfo WHERE contact.id=:registrant AND contact_postalInfo.contact_id=contact.id";
                        $stmt5 = $pdo->prepare($query5);
                        $stmt5->bindParam(':registrant', $f['registrant'], PDO::PARAM_INT);
                        $stmt5->execute();

                        $f2 = $stmt5->fetch(PDO::FETCH_ASSOC);
                        if ($privacy) {
                        $res .= "\nRegistry Registrant ID: REDACTED FOR PRIVACY"
                            ."\nRegistrant Name: REDACTED FOR PRIVACY"
                            ."\nRegistrant Organization: REDACTED FOR PRIVACY"
                            ."\nRegistrant Street: REDACTED FOR PRIVACY"
                            ."\nRegistrant Street: REDACTED FOR PRIVACY"
                            ."\nRegistrant Street: REDACTED FOR PRIVACY"
                            ."\nRegistrant City: REDACTED FOR PRIVACY"
                            ."\nRegistrant State/Province: REDACTED FOR PRIVACY"
                            ."\nRegistrant Postal Code: REDACTED FOR PRIVACY"
                            ."\nRegistrant Country: REDACTED FOR PRIVACY"
                            ."\nRegistrant Phone: REDACTED FOR PRIVACY"
                            ."\nRegistrant Fax: REDACTED FOR PRIVACY"
                            ."\nRegistrant Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                        } else {
                            $res .= "\nRegistry Registrant ID: C".$f2['id']."-".$roid;
                            
                            // Determine which type of disclosure to use
                            $disclose_name = ($f2['type'] == 'loc') ? $f2['disclose_name_loc'] : $f2['disclose_name_int'];
                            $disclose_org = ($f2['type'] == 'loc') ? $f2['disclose_org_loc'] : $f2['disclose_org_int'];
                            $disclose_addr = ($f2['type'] == 'loc') ? $f2['disclose_addr_loc'] : $f2['disclose_addr_int'];
                            
                            // Registrant Name
                            $res .= ($disclose_name ? "\nRegistrant Name: ".$f2['name'] : "\nRegistrant Name: REDACTED FOR PRIVACY");
                            
                            // Registrant Organization
                            $res .= ($disclose_org ? "\nRegistrant Organization: ".$f2['org'] : "\nRegistrant Organization: REDACTED FOR PRIVACY");
                            
                            // Registrant Address
                            if ($disclose_addr) {
                                $res .= "\nRegistrant Street: ".$f2['street1']
                                      ."\nRegistrant Street: ".$f2['street2']
                                      ."\nRegistrant Street: ".$f2['street3']
                                      ."\nRegistrant City: ".$f2['city']
                                      ."\nRegistrant State/Province: ".$f2['sp']
                                      ."\nRegistrant Postal Code: ".$f2['pc']
                                      ."\nRegistrant Country: ".strtoupper($f2['cc']);
                            } else {
                                $res .= "\nRegistrant Street: REDACTED FOR PRIVACY"
                                      ."\nRegistrant Street: REDACTED FOR PRIVACY"
                                      ."\nRegistrant Street: REDACTED FOR PRIVACY"
                                      ."\nRegistrant City: REDACTED FOR PRIVACY"
                                      ."\nRegistrant State/Province: REDACTED FOR PRIVACY"
                                      ."\nRegistrant Postal Code: REDACTED FOR PRIVACY"
                                      ."\nRegistrant Country: REDACTED FOR PRIVACY";
                            }
                            
                            // Registrant Phone
                            $res .= ($f2['disclose_voice'] ? "\nRegistrant Phone: ".$f2['voice'] : "\nRegistrant Phone: REDACTED FOR PRIVACY");
                            
                            // Registrant Fax
                            $res .= ($f2['disclose_fax'] ? "\nRegistrant Fax: ".$f2['fax'] : "\nRegistrant Fax: REDACTED FOR PRIVACY");
                            
                            // Registrant Email
                            $res .= ($f2['disclose_email'] ? "\nRegistrant Email: ".$f2['email'] : "\nRegistrant Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.");
                        }

                        $query6 = "SELECT contact.id,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.fax,contact.email,contact.disclose_voice,contact.disclose_fax,contact.disclose_email,contact_postalInfo.type,contact_postalInfo.disclose_name_int,contact_postalInfo.disclose_name_loc,contact_postalInfo.disclose_org_int,contact_postalInfo.disclose_org_loc,contact_postalInfo.disclose_addr_int,contact_postalInfo.disclose_addr_loc
                        FROM domain_contact_map,contact,contact_postalInfo WHERE domain_contact_map.domain_id=:domain_id AND domain_contact_map.type='admin' AND domain_contact_map.contact_id=contact.id AND domain_contact_map.contact_id=contact_postalInfo.contact_id";
                        $stmt6 = $pdo->prepare($query6);
                        $stmt6->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                        $stmt6->execute();

                        $f2 = $stmt6->fetch(PDO::FETCH_ASSOC);
                        if ($privacy) {
                        $res .= "\nRegistry Admin ID: REDACTED FOR PRIVACY"
                            ."\nAdmin Name: REDACTED FOR PRIVACY"
                            ."\nAdmin Organization: REDACTED FOR PRIVACY"
                            ."\nAdmin Street: REDACTED FOR PRIVACY"
                            ."\nAdmin Street: REDACTED FOR PRIVACY"
                            ."\nAdmin Street: REDACTED FOR PRIVACY"
                            ."\nAdmin City: REDACTED FOR PRIVACY"
                            ."\nAdmin State/Province: REDACTED FOR PRIVACY"
                            ."\nAdmin Postal Code: REDACTED FOR PRIVACY"
                            ."\nAdmin Country: REDACTED FOR PRIVACY"
                            ."\nAdmin Phone: REDACTED FOR PRIVACY"
                            ."\nAdmin Fax: REDACTED FOR PRIVACY"
                            ."\nAdmin Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                        } else {
                            if (!empty($f2['id'])) {
                                $res .= "\nRegistry Admin ID: C" . $f2['id'] . "-" . $roid;
                            } else {
                                $res .= "\nRegistry Admin ID:";
                            }

                            // Determine which type of disclosure to use
                            $disclose_name = isset($f2['type']) && $f2['type'] == 'loc' ? 
                                (isset($f2['disclose_name_loc']) ? $f2['disclose_name_loc'] : 0) : 
                                (isset($f2['disclose_name_int']) ? $f2['disclose_name_int'] : 0);

                            $disclose_org = isset($f2['type']) && $f2['type'] == 'loc' ? 
                                (isset($f2['disclose_org_loc']) ? $f2['disclose_org_loc'] : 0) : 
                                (isset($f2['disclose_org_int']) ? $f2['disclose_org_int'] : 0);

                            $disclose_addr = isset($f2['type']) && $f2['type'] == 'loc' ? 
                                (isset($f2['disclose_addr_loc']) ? $f2['disclose_addr_loc'] : 0) : 
                                (isset($f2['disclose_addr_int']) ? $f2['disclose_addr_int'] : 0);

                            $disclose_voice = isset($f2['disclose_voice']) ? $f2['disclose_voice'] : 0;
                            $disclose_fax = isset($f2['disclose_fax']) ? $f2['disclose_fax'] : 0;
                            $disclose_email = isset($f2['disclose_email']) ? $f2['disclose_email'] : 0;

                            // Admin Name
                            $res .= ($disclose_name ? "\nAdmin Name: ".$f2['name'] : "\nAdmin Name: REDACTED FOR PRIVACY");

                            // Admin Organization
                            $res .= ($disclose_org ? "\nAdmin Organization: ".$f2['org'] : "\nAdmin Organization: REDACTED FOR PRIVACY");

                            // Admin Address
                            if ($disclose_addr) {
                                $res .= "\nAdmin Street: ".$f2['street1']
                                      ."\nAdmin Street: ".$f2['street2']
                                      ."\nAdmin Street: ".$f2['street3']
                                      ."\nAdmin City: ".$f2['city']
                                      ."\nAdmin State/Province: ".$f2['sp']
                                      ."\nAdmin Postal Code: ".$f2['pc']
                                      ."\nAdmin Country: ".strtoupper($f2['cc']);
                            } else {
                                $res .= "\nAdmin Street: REDACTED FOR PRIVACY"
                                      ."\nAdmin Street: REDACTED FOR PRIVACY"
                                      ."\nAdmin Street: REDACTED FOR PRIVACY"
                                      ."\nAdmin City: REDACTED FOR PRIVACY"
                                      ."\nAdmin State/Province: REDACTED FOR PRIVACY"
                                      ."\nAdmin Postal Code: REDACTED FOR PRIVACY"
                                      ."\nAdmin Country: REDACTED FOR PRIVACY";
                            }

                            // Admin Phone
                            $res .= ($disclose_voice ? "\nAdmin Phone: ".$f2['voice'] : "\nAdmin Phone: REDACTED FOR PRIVACY");

                            // Admin Fax
                            $res .= ($disclose_fax ? "\nAdmin Fax: ".$f2['fax'] : "\nAdmin Fax: REDACTED FOR PRIVACY");

                            // Admin Email
                            $res .= ($disclose_email ? "\nAdmin Email: ".$f2['email'] : "\nAdmin Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.");
                        }

                        $query7 = "SELECT contact.id,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.fax,contact.email,contact.disclose_voice,contact.disclose_fax,contact.disclose_email,contact_postalInfo.type,contact_postalInfo.disclose_name_int,contact_postalInfo.disclose_name_loc,contact_postalInfo.disclose_org_int,contact_postalInfo.disclose_org_loc,contact_postalInfo.disclose_addr_int,contact_postalInfo.disclose_addr_loc
                        FROM domain_contact_map,contact,contact_postalInfo WHERE domain_contact_map.domain_id=:domain_id AND domain_contact_map.type='billing' AND domain_contact_map.contact_id=contact.id AND domain_contact_map.contact_id=contact_postalInfo.contact_id";
                        $stmt7 = $pdo->prepare($query7);
                        $stmt7->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                        $stmt7->execute();

                        $f2 = $stmt7->fetch(PDO::FETCH_ASSOC);
                        if ($privacy) {
                        $res .= "\nRegistry Billing ID: REDACTED FOR PRIVACY"
                            ."\nBilling Name: REDACTED FOR PRIVACY"
                            ."\nBilling Organization: REDACTED FOR PRIVACY"
                            ."\nBilling Street: REDACTED FOR PRIVACY"
                            ."\nBilling Street: REDACTED FOR PRIVACY"
                            ."\nBilling Street: REDACTED FOR PRIVACY"
                            ."\nBilling City: REDACTED FOR PRIVACY"
                            ."\nBilling State/Province: REDACTED FOR PRIVACY"
                            ."\nBilling Postal Code: REDACTED FOR PRIVACY"
                            ."\nBilling Country: REDACTED FOR PRIVACY"
                            ."\nBilling Phone: REDACTED FOR PRIVACY"
                            ."\nBilling Fax: REDACTED FOR PRIVACY"
                            ."\nBilling Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                        } else {
                            if (!empty($f2['id'])) {
                                $res .= "\nRegistry Billing ID: C" . $f2['id'] . "-" . $roid;
                            } else {
                                $res .= "\nRegistry Billing ID:";
                            }

                            // Determine which type of disclosure to use
                            $disclose_name = isset($f2['type']) && $f2['type'] == 'loc' ? 
                                (isset($f2['disclose_name_loc']) ? $f2['disclose_name_loc'] : 0) : 
                                (isset($f2['disclose_name_int']) ? $f2['disclose_name_int'] : 0);

                            $disclose_org = isset($f2['type']) && $f2['type'] == 'loc' ? 
                                (isset($f2['disclose_org_loc']) ? $f2['disclose_org_loc'] : 0) : 
                                (isset($f2['disclose_org_int']) ? $f2['disclose_org_int'] : 0);

                            $disclose_addr = isset($f2['type']) && $f2['type'] == 'loc' ? 
                                (isset($f2['disclose_addr_loc']) ? $f2['disclose_addr_loc'] : 0) : 
                                (isset($f2['disclose_addr_int']) ? $f2['disclose_addr_int'] : 0);

                            $disclose_voice = isset($f2['disclose_voice']) ? $f2['disclose_voice'] : 0;
                            $disclose_fax = isset($f2['disclose_fax']) ? $f2['disclose_fax'] : 0;
                            $disclose_email = isset($f2['disclose_email']) ? $f2['disclose_email'] : 0;

                            // Billing Name
                            $res .= ($disclose_name ? "\nBilling Name: ".$f2['name'] : "\nBilling Name: REDACTED FOR PRIVACY");

                            // Billing Organization
                            $res .= ($disclose_org ? "\nBilling Organization: ".$f2['org'] : "\nBilling Organization: REDACTED FOR PRIVACY");

                            // Billing Address
                            if ($disclose_addr) {
                                $res .= "\nBilling Street: ".$f2['street1']
                                      ."\nBilling Street: ".$f2['street2']
                                      ."\nBilling Street: ".$f2['street3']
                                      ."\nBilling City: ".$f2['city']
                                      ."\nBilling State/Province: ".$f2['sp']
                                      ."\nBilling Postal Code: ".$f2['pc']
                                      ."\nBilling Country: ".strtoupper($f2['cc']);
                            } else {
                                $res .= "\nBilling Street: REDACTED FOR PRIVACY"
                                      ."\nBilling Street: REDACTED FOR PRIVACY"
                                      ."\nBilling Street: REDACTED FOR PRIVACY"
                                      ."\nBilling City: REDACTED FOR PRIVACY"
                                      ."\nBilling State/Province: REDACTED FOR PRIVACY"
                                      ."\nBilling Postal Code: REDACTED FOR PRIVACY"
                                      ."\nBilling Country: REDACTED FOR PRIVACY";
                            }

                            // Billing Phone
                            $res .= ($disclose_voice ? "\nBilling Phone: ".$f2['voice'] : "\nBilling Phone: REDACTED FOR PRIVACY");

                            // Billing Fax
                            $res .= ($disclose_fax ? "\nBilling Fax: ".$f2['fax'] : "\nBilling Fax: REDACTED FOR PRIVACY");

                            // Billing Email
                            $res .= ($disclose_email ? "\nBilling Email: ".$f2['email'] : "\nBilling Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.");
                        }

                        $query8 = "SELECT contact.id,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.fax,contact.email,contact.disclose_voice,contact.disclose_fax,contact.disclose_email,contact_postalInfo.type,contact_postalInfo.disclose_name_int,contact_postalInfo.disclose_name_loc,contact_postalInfo.disclose_org_int,contact_postalInfo.disclose_org_loc,contact_postalInfo.disclose_addr_int,contact_postalInfo.disclose_addr_loc
                        FROM domain_contact_map,contact,contact_postalInfo WHERE domain_contact_map.domain_id=:domain_id AND domain_contact_map.type='tech' AND domain_contact_map.contact_id=contact.id AND domain_contact_map.contact_id=contact_postalInfo.contact_id";
                        $stmt8 = $pdo->prepare($query8);
                        $stmt8->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                        $stmt8->execute();

                        $f2 = $stmt8->fetch(PDO::FETCH_ASSOC);
                        if ($privacy) {
                        $res .= "\nRegistry Tech ID: REDACTED FOR PRIVACY"
                            ."\nTech Name: REDACTED FOR PRIVACY"
                            ."\nTech Organization: REDACTED FOR PRIVACY"
                            ."\nTech Street: REDACTED FOR PRIVACY"
                            ."\nTech Street: REDACTED FOR PRIVACY"
                            ."\nTech Street: REDACTED FOR PRIVACY"
                            ."\nTech City: REDACTED FOR PRIVACY"
                            ."\nTech State/Province: REDACTED FOR PRIVACY"
                            ."\nTech Postal Code: REDACTED FOR PRIVACY"
                            ."\nTech Country: REDACTED FOR PRIVACY"
                            ."\nTech Phone: REDACTED FOR PRIVACY"
                            ."\nTech Fax: REDACTED FOR PRIVACY"
                            ."\nTech Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.";
                        } else {
                            if (!empty($f2['id'])) {
                                $res .= "\nRegistry Tech ID: C" . $f2['id'] . "-" . $roid;
                            } else {
                                $res .= "\nRegistry Tech ID:";
                            }

                            // Determine which type of disclosure to use
                            $disclose_name = isset($f2['type']) && $f2['type'] == 'loc' ? 
                                (isset($f2['disclose_name_loc']) ? $f2['disclose_name_loc'] : 0) : 
                                (isset($f2['disclose_name_int']) ? $f2['disclose_name_int'] : 0);

                            $disclose_org = isset($f2['type']) && $f2['type'] == 'loc' ? 
                                (isset($f2['disclose_org_loc']) ? $f2['disclose_org_loc'] : 0) : 
                                (isset($f2['disclose_org_int']) ? $f2['disclose_org_int'] : 0);

                            $disclose_addr = isset($f2['type']) && $f2['type'] == 'loc' ? 
                                (isset($f2['disclose_addr_loc']) ? $f2['disclose_addr_loc'] : 0) : 
                                (isset($f2['disclose_addr_int']) ? $f2['disclose_addr_int'] : 0);

                            $disclose_voice = isset($f2['disclose_voice']) ? $f2['disclose_voice'] : 0;
                            $disclose_fax = isset($f2['disclose_fax']) ? $f2['disclose_fax'] : 0;
                            $disclose_email = isset($f2['disclose_email']) ? $f2['disclose_email'] : 0;

                            // Tech Name
                            $res .= ($disclose_name ? "\nTech Name: ".$f2['name'] : "\nTech Name: REDACTED FOR PRIVACY");

                            // Tech Organization
                            $res .= ($disclose_org ? "\nTech Organization: ".$f2['org'] : "\nTech Organization: REDACTED FOR PRIVACY");

                            // Tech Address
                            if ($disclose_addr) {
                                $res .= "\nTech Street: ".$f2['street1']
                                      ."\nTech Street: ".$f2['street2']
                                      ."\nTech Street: ".$f2['street3']
                                      ."\nTech City: ".$f2['city']
                                      ."\nTech State/Province: ".$f2['sp']
                                      ."\nTech Postal Code: ".$f2['pc']
                                      ."\nTech Country: ".strtoupper($f2['cc']);
                            } else {
                                $res .= "\nTech Street: REDACTED FOR PRIVACY"
                                      ."\nTech Street: REDACTED FOR PRIVACY"
                                      ."\nTech Street: REDACTED FOR PRIVACY"
                                      ."\nTech City: REDACTED FOR PRIVACY"
                                      ."\nTech State/Province: REDACTED FOR PRIVACY"
                                      ."\nTech Postal Code: REDACTED FOR PRIVACY"
                                      ."\nTech Country: REDACTED FOR PRIVACY";
                            }

                            // Tech Phone
                            $res .= ($disclose_voice ? "\nTech Phone: ".$f2['voice'] : "\nTech Phone: REDACTED FOR PRIVACY");

                            // Tech Fax
                            $res .= ($disclose_fax ? "\nTech Fax: ".$f2['fax'] : "\nTech Fax: REDACTED FOR PRIVACY");

                            // Tech Email
                            $res .= ($disclose_email ? "\nTech Email: ".$f2['email'] : "\nTech Email: Kindly refer to the RDDS server associated with the identified registrar in this output to obtain contact details for the Registrant, Admin, or Tech associated with the queried domain name.");
                        }
                    }
                    
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
                        if ($f2 === false) break;
                        $res .= "\nName Server: ".$f2['name'];
                        if (!empty($f2['ips'])) {
                            $res .= " (".$f2['ips'].")";
                        }
                        $counter++;
                    }

                    if (empty($c['limited_whois']) || !$c['limited_whois']) {
                        $query_dnssec = "SELECT EXISTS(SELECT 1 FROM secdns WHERE domain_id = :domain_id)";
                        $stmt_dnssec = $pdo->prepare($query_dnssec);
                        $stmt_dnssec->bindParam(':domain_id', $f['id'], PDO::PARAM_INT);
                        $stmt_dnssec->execute();

                        $dnssec_exists = $stmt_dnssec->fetchColumn();

                        if ($dnssec_exists) {
                            $res .= "\nDNSSEC: signedDelegation";
                        } else {
                            $res .= "\nDNSSEC: unsigned";
                        }
                        $res .= "\nURL of the ICANN Whois Inaccuracy Complaint Form: https://www.icann.org/wicf/";


                        $currentDateTime = new DateTime();
                        $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
                        $res .= "\n>>> Last update of WHOIS database: {$currentTimestamp} <<<";
                        $res .= "\n";
                        $res .= "\nFor more information on Whois status codes, please visit https://icann.org/epp";
                        $res .= "\n\n";
                        $res .= "Access to {$tld['tld']} WHOIS information is provided to assist persons in"
                        ."\ndetermining the contents of a domain name registration record in the"
                        ."\nDomain Name Registry registry database. The data in this record is provided by"
                        ."\nDomain Name Registry for informational purposes only, and Domain Name Registry does not"
                        ."\nguarantee its accuracy.  This service is intended only for query-based"
                        ."\naccess. You agree that you will use this data only for lawful purposes"
                        ."\nand that, under no circumstances will you use this data to: (a) allow,"
                        ."\nenable, or otherwise support the transmission by e-mail, telephone, or"
                        ."\nfacsimile of mass unsolicited, commercial advertising or solicitations"
                        ."\nto entities other than the data recipient's own existing customers; or"
                        ."\n(b) enable high volume, automated, electronic processes that send"
                        ."\nqueries or data to the systems of Registry Operator, a Registrar, or"
                        ."\nNIC except as reasonably necessary to register domain names or"
                        ."\nmodify existing registrations. All rights reserved. Domain Name Registry reserves"
                        ."\nthe right to modify these terms at any time. By submitting this query,"
                        ."\nyou agree to abide by this policy."
                        ."\n";
                    } else {
                        $currentDateTime = new DateTime();
                        $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
                        $res .= "\n>>> Last update of WHOIS database: {$currentTimestamp} <<<";
                        $res .= "\n";
                    }

                    $server->send($fd, $res . "");
                    
                    $clientInfo = $server->getClientInfo($fd);
                    $remoteAddr = $clientInfo['remote_ip'];
                    $log->notice('new request from ' . $remoteAddr . ' | ' . $domain . ' | FOUND');
                    
                    $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                    $settingName = 'whois-43-queries';
                    $stmt->bindParam(':name', $settingName);
                    $stmt->execute();
                } else {
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
                        
                    $regQuery = "SELECT id,name,iana_id,whois_server,url,abuse_email,abuse_phone FROM registrar WHERE id = :clid";
                    $regStmt = $pdo->prepare($regQuery);
                    $regStmt->bindParam(':clid', $f['clid'], PDO::PARAM_INT);
                    $regStmt->execute();

                    if ($registrar = $regStmt->fetch(PDO::FETCH_ASSOC)) {
                        $res .= "\nRegistrar Name: ".$registrar['name'];
                        if (empty($c['limited_whois']) || !$c['limited_whois']) {
                            $res .= "\nRegistrar WHOIS Server: ".$registrar['whois_server'];
                            $res .= "\nRegistrar URL: ".$registrar['url'];
                            $res .= "\nRegistrar IANA ID: ".$registrar['iana_id'];
                            $res .= "\nRegistrar Abuse Contact Email: ".$registrar['abuse_email'];
                            $res .= "\nRegistrar Abuse Contact Phone: ".$registrar['abuse_phone'];
                        }
                    }

                    if (empty($c['limited_whois']) || !$c['limited_whois']) {
                        $res .= "\nURL of the ICANN Whois Inaccuracy Complaint Form: https://www.icann.org/wicf/";
                        $currentDateTime = new DateTime();
                        $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
                        $res .= "\n>>> Last update of WHOIS database: {$currentTimestamp} <<<";
                        $res .= "\n";
                        $res .= "\nFor more information on Whois status codes, please visit https://icann.org/epp";
                        $res .= "\n\n";
                        $res .= "Access to WHOIS information is provided to assist persons in"
                        ."\ndetermining the contents of a domain name registration record in the"
                        ."\nDomain Name Registry registry database. The data in this record is provided by"
                        ."\nDomain Name Registry for informational purposes only, and Domain Name Registry does not"
                        ."\nguarantee its accuracy.  This service is intended only for query-based"
                        ."\naccess. You agree that you will use this data only for lawful purposes"
                        ."\nand that, under no circumstances will you use this data to: (a) allow,"
                        ."\nenable, or otherwise support the transmission by e-mail, telephone, or"
                        ."\nfacsimile of mass unsolicited, commercial advertising or solicitations"
                        ."\nto entities other than the data recipient's own existing customers; or"
                        ."\n(b) enable high volume, automated, electronic processes that send"
                        ."\nqueries or data to the systems of Registry Operator, a Registrar, or"
                        ."\nNIC except as reasonably necessary to register domain names or"
                        ."\nmodify existing registrations. All rights reserved. Domain Name Registry reserves"
                        ."\nthe right to modify these terms at any time. By submitting this query,"
                        ."\nyou agree to abide by this policy."
                        ."\n";
                    } else {
                        $currentDateTime = new DateTime();
                        $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
                        $res .= "\n>>> Last update of WHOIS database: {$currentTimestamp} <<<";
                        $res .= "\n";
                    }

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
                    if (empty($c['limited_whois']) || !$c['limited_whois']) {
                        $res = "Registrar: ".$f['name']
                            ."\nRegistrar WHOIS Server: ".$f['whois_server']
                            ."\nRegistrar URL: ".$f['url']
                            ."\nRegistrar IANA ID: ".$f['iana_id']
                            ."\nRegistrar Abuse Contact Email: ".$f['abuse_email']
                            ."\nRegistrar Abuse Contact Phone: ".$f['abuse_phone'];
                            
                        $contactQuery = "SELECT * FROM registrar_contact WHERE id = :registrar_id";
                        $contactStmt = $pdo->prepare($contactQuery);
                        $contactStmt->bindParam(':registrar_id', $f['id'], PDO::PARAM_INT);
                        $contactStmt->execute();

                        if ($contact = $contactStmt->fetch(PDO::FETCH_ASSOC)) {
                            $res .= "\nStreet: " . $contact['street1'];
                            $res .= "\nCity: " . $contact['city'];
                            $res .= "\nPostal Code: " . $contact['pc'];
                            $res .= "\nCountry: " . $contact['cc'];
                            $res .= "\nPhone: " . $contact['voice'];
                            $res .= "\nFax: " . $contact['fax'];
                            $res .= "\nPublic Email: " . $contact['email'];
                        }
                            
                        $res .= "\nURL of the ICANN Whois Inaccuracy Complaint Form: https://www.icann.org/wicf/";
                        $currentDateTime = new DateTime();
                        $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
                        $res .= "\n>>> Last update of WHOIS database: {$currentTimestamp} <<<";
                        $res .= "\n";
                        $res .= "\nFor more information on Whois status codes, please visit https://icann.org/epp";
                        $res .= "\n\n";
                        $res .= "Access to WHOIS information is provided to assist persons in"
                        ."\ndetermining the contents of a domain name registration record in the"
                        ."\nDomain Name Registry registry database. The data in this record is provided by"
                        ."\nDomain Name Registry for informational purposes only, and Domain Name Registry does not"
                        ."\nguarantee its accuracy.  This service is intended only for query-based"
                        ."\naccess. You agree that you will use this data only for lawful purposes"
                        ."\nand that, under no circumstances will you use this data to: (a) allow,"
                        ."\nenable, or otherwise support the transmission by e-mail, telephone, or"
                        ."\nfacsimile of mass unsolicited, commercial advertising or solicitations"
                        ."\nto entities other than the data recipient's own existing customers; or"
                        ."\n(b) enable high volume, automated, electronic processes that send"
                        ."\nqueries or data to the systems of Registry Operator, a Registrar, or"
                        ."\nNIC except as reasonably necessary to register domain names or"
                        ."\nmodify existing registrations. All rights reserved. Domain Name Registry reserves"
                        ."\nthe right to modify these terms at any time. By submitting this query,"
                        ."\nyou agree to abide by this policy."
                        ."\n";
                    } else {
                        $res = "Registrar: ".$f['name'];
                        $currentDateTime = new DateTime();
                        $currentTimestamp = $currentDateTime->format("Y-m-d\TH:i:s.v\Z");
                        $res .= "\n>>> Last update of WHOIS database: {$currentTimestamp} <<<";
                        $res .= "\n";
                    }

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
    } catch (Throwable $e) {
        // Catch any other exceptions or errors
        $log->error('Error: ' . $e->getMessage());
        $server->send($fd, "Error");
    } finally {
        if ($pdo instanceof PDO) {
            $pool->put($pdo);
        }
        $server->close($fd);
    }
});

// Register a callback to handle client disconnections
$server->on('close', function ($server, $fd) use ($log) {
    $log->info('client ' . $fd . ' disconnected.');
});

// Start the server
$server->start();