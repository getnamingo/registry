<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Namingo\Rately\Rately;

$c = require_once 'config.php';
require_once 'helpers.php';
$logFilePath = '/var/log/namingo/rdap.log';
$log = setupLogger($logFilePath, 'RDAP');

// Initialize the PDO connection pool
$pool = new Swoole\Database\PDOPool(
    (new Swoole\Database\PDOConfig())
        ->withDriver($c['db_type'])
        ->withHost($c['db_host'])
        ->withPort($c['db_port'])
        ->withDbName($c['db_database'])
        ->withUsername($c['db_username'])
        ->withPassword($c['db_password'])
        ->withCharset('utf8mb4'), 16
);

// Create a Swoole HTTP server
$http = new Server('127.0.0.1', 7500);
$http->set([
    'daemonize' => false,
    'log_file' => '/var/log/namingo/rdap_application.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/run/rdap.pid',
    'max_request' => 1000,
    'dispatch_mode' => 1,
    'open_tcp_nodelay' => true,
    'max_conn' => 1024,
    'buffer_output_size' => 2 * 1024 * 1024,  // 2MB
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 600,  // 10 minutes
    'package_max_length' => 2 * 1024 * 1024,  // 2MB
    'reload_async' => true,
    'http_compression' => true
]);

$rateLimiter = new Rately();
$log->info('server started.');

// Handle incoming HTTP requests
$http->on('request', function ($request, $response) use ($c, $pool, $log, $rateLimiter) {
    // Get a PDO connection from the pool
    try {
        $pdo = $pool->get();
        if (!$pdo) {
            throw new PDOException("Failed to retrieve a connection from Swoole PDOPool.");
        }
    } catch (PDOException $e) {
        $log->alert("Swoole PDO Pool failed: " . $e->getMessage());
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(500);
        $response->end(json_encode(['error' => 'Database failure. Please try again later.']));
    }

    $remoteAddr = $request->server['remote_addr'];
    if (!isIpWhitelisted($remoteAddr, $pdo)) {
        if (($c['rately'] == true) && ($rateLimiter->isRateLimited('rdap', $remoteAddr, $c['limit'], $c['period']))) {
            $log->error('rate limit exceeded for ' . $remoteAddr);
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(429);
            $response->end(json_encode(['error' => 'Rate limit exceeded. Please try again later.']));
            return;
        }
    }

    try {
        // Extract the request path
        $requestPath = $request->server['request_uri'];
        
        $method = strtoupper($request->server['request_method'] ?? 'GET');

        if ($method === 'HEAD') {
            $response->header('Access-Control-Allow-Origin', '*');
            $response->header('Content-Type', 'application/rdap+json');

            if (preg_match('#^/domain/([^/?]+)#', $requestPath, $m)) {
                $domainName = $m[1];

                $stmt = $pdo->prepare('SELECT 1 FROM domain WHERE name = ? LIMIT 1');
                $stmt->execute([$domainName]);

                $response->status($stmt->fetchColumn() ? 200 : 404);
                $response->end('');
                return;
            }

            if (preg_match('#^/entity/([^/?]+)#', $requestPath, $m)) {
                $handle = $m[1];

                $stmt = $pdo->prepare('SELECT 1 FROM registrar WHERE iana_id = ? LIMIT 1');
                $stmt->execute([$handle]);

                $response->status($stmt->fetchColumn() ? 200 : 404);
                $response->end('');
                return;
            }

            if (preg_match('#^/nameserver/([^/?]+)#', $requestPath, $m)) {
                $ns = $m[1];

                $stmt = $pdo->prepare('SELECT 1 FROM host WHERE name = ? LIMIT 1');
                $stmt->execute([$ns]);

                $response->status($stmt->fetchColumn() ? 200 : 404);
                $response->end('');
                return;
            }

            $response->status(404);
            $response->end('');
            return;
        }

        // Handle domain query
        if (preg_match('#^/domain/([^/?]+)#', $requestPath, $matches)) {
            $domainName = $matches[1];
            handleDomainQuery($request, $response, $pdo, $domainName, $c, $log);
        }
        // Handle entity (contacts) query
        elseif (preg_match('#^/entity/([^/?]+)#', $requestPath, $matches)) {
            $entityHandle = $matches[1];
            handleEntityQuery($request, $response, $pdo, $entityHandle, $c, $log);
        }
        // Handle nameserver query
        elseif (preg_match('#^/nameserver/([^/?]+)#', $requestPath, $matches)) {
            $nameserverHandle = $matches[1];
            handleNameserverQuery($request, $response, $pdo, $nameserverHandle, $c, $log);
        }
        // Handle domain search query
        elseif ($requestPath === '/domains') {
            if (isset($request->server['query_string'])) {
                parse_str($request->server['query_string'], $queryParams);

                if (isset($queryParams['name'])) {
                    $searchPattern = $queryParams['name'];
                    handleDomainSearchQuery($request, $response, $pdo, $searchPattern, $c, $log, 'name');
                } elseif (isset($queryParams['nsLdhName'])) {
                    $searchPattern = $queryParams['nsLdhName'];
                    handleDomainSearchQuery($request, $response, $pdo, $searchPattern, $c, $log, 'nsLdhName');
                } elseif (isset($queryParams['nsIp'])) {
                    $searchPattern = $queryParams['nsIp'];
                    handleDomainSearchQuery($request, $response, $pdo, $searchPattern, $c, $log, 'nsIp');
                } else {
                    $response->header('Content-Type', 'application/rdap+json');
                    $response->status(404);
                    $response->end(json_encode(['error' => 'Object not found']));
                }
            } else {
                    $response->header('Content-Type', 'application/rdap+json');
                    $response->status(404);
                    $response->end(json_encode(['error' => 'Object not found']));
            }
        }
        // Handle nameserver search query
        elseif ($requestPath === '/nameservers') {
            if (isset($request->server['query_string'])) {
                parse_str($request->server['query_string'], $queryParams);

                if (isset($queryParams['name'])) {
                    $searchPattern = $queryParams['name'];
                    handleNameserverSearchQuery($request, $response, $pdo, $searchPattern, $c, $log, 'name');
                } elseif (isset($queryParams['ip'])) {
                    $searchPattern = $queryParams['ip'];
                    handleNameserverSearchQuery($request, $response, $pdo, $searchPattern, $c, $log, 'ip');
                } else {
                    $response->header('Content-Type', 'application/rdap+json');
                    $response->status(404);
                    $response->end(json_encode(['error' => 'Object not found']));
                }
            } else {
                    $response->header('Content-Type', 'application/rdap+json');
                    $response->status(404);
                    $response->end(json_encode(['error' => 'Object not found']));
            }
        }
        // Handle entity search query
        elseif ($requestPath === '/entities') {
            if (isset($request->server['query_string'])) {
                parse_str($request->server['query_string'], $queryParams);

                if (isset($queryParams['fn'])) {
                    $searchPattern = $queryParams['fn'];
                    handleEntitySearchQuery($request, $response, $pdo, $searchPattern, $c, $log, 'fn');
                } elseif (isset($queryParams['handle'])) {
                    $searchPattern = $queryParams['handle'];
                    handleEntitySearchQuery($request, $response, $pdo, $searchPattern, $c, $log, 'handle');
                } else {
                    $response->header('Content-Type', 'application/rdap+json');
                    $response->status(404);
                    $response->end(json_encode(['error' => 'Object not found']));
                }
            } else {
                    $response->header('Content-Type', 'application/rdap+json');
                    $response->status(404);
                    $response->end(json_encode(['error' => 'Object not found']));
            }
        }
        // Handle help query
        elseif ($requestPath === '/help') {
            handleHelpQuery($request, $response, $pdo, $c);
        }
        else {
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode(['errorCode' => 404,'title' => 'Not Found','error' => 'Endpoint not found']));
        }
    } catch (PDOException $e) {
        // Handle database exceptions
        $log->error('Database error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/rdap+json');
        $response->end(json_encode(['Database error:' => $e->getMessage()]));
        return;
    } catch (Throwable $e) {
        // Catch any other exceptions or errors
        $log->error('Error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/rdap+json');
        $response->end(json_encode(['General error:' => $e->getMessage()]));
        return;
    } finally {
        // Return the connection to the pool
        $pool->put($pdo);
    }

});

// Start the server
$http->start();

function handleDomainQuery($request, $response, $pdo, $domainName, $c, $log) {
    // Extract and validate the domain name from the request
    $domain = urldecode($domainName);
    $domain = trim($domain);
    $domain = strtolower($domain);

    // Empty domain check
    if (!$domain) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Please enter a domain name']));
        return;
    }
    
    // Check domain length
    if (strlen($domain) > 68) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name is too long']));
        return;
    }

    // Convert to Punycode if the domain is not in ASCII
    if (!mb_detect_encoding($domain, 'ASCII', true)) {
        $convertedDomain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($convertedDomain === false) {
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(400); // Bad Request
            $response->end(json_encode(['error' => 'Domain conversion to Punycode failed']));
            return;
        } else {
            $domain = $convertedDomain;
        }
    }

    // Check for prohibited patterns in domain names
    if (!preg_match('/^(?:(xn--[a-zA-Z0-9-]{1,63}|[a-zA-Z0-9-]{1,63})\.){1,3}(xn--[a-zA-Z0-9-]{2,63}|[a-zA-Z]{2,63})$/', $domain)) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name invalid format']));
        return;
    }
    
    // Extract TLD from the domain
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
        $errorData = [
            'rdapConformance' => [
                'rdap_level_0',
                'icann_rdap_response_profile_0',
                'icann_rdap_response_profile_1',
                'icann_rdap_technical_implementation_guide_0',
                'icann_rdap_technical_implementation_guide_1',
            ],
            'errorCode' => 404,
            'title' => 'Invalid TLD',
            'description' => ['Please search only allowed TLDs.'],
        ];

        $response->header('Content-Type', 'application/rdap+json');
        $response->status(404);
        $response->end(json_encode($errorData));
        return;
    }
  
    // Fetch the IDN regex for the given TLD
    $stmtRegex = $pdo->prepare("SELECT idn_table FROM domain_tld WHERE tld = :tld");
    $stmtRegex->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtRegex->execute();
    $idnRegex = $stmtRegex->fetchColumn();

    if (!$idnRegex) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Failed to fetch domain IDN table']));
        return;
    }

    // Check for invalid characters using fetched regex
    if (strpos($parts[0], 'xn--') === 0) {
        $label = idn_to_utf8($parts[0], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    } else {
        $label = $parts[0];
    }
    if (!preg_match($idnRegex, $label)) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name invalid IDN characters']));
        return;
    }

    // Perform the RDAP lookup
    try {
        // Query 1: Get domain details
        $stmt1 = $pdo->prepare("SELECT * FROM domain WHERE name = :domain");
        $stmt1->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt1->execute();
        $domainDetails = $stmt1->fetch(PDO::FETCH_ASSOC);

        // Check if the domain exists
        if (!$domainDetails) {
            // Check if domain is reserved
            $stmtReserved = $pdo->prepare("SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1");
            $stmtReserved->execute([$label]);
            $domain_already_reserved = $stmtReserved->fetchColumn();

            if ($domain_already_reserved) {
                $response->header('Content-Type', 'application/rdap+json');
                $response->status(404);
                $response->end(json_encode(['error' => 'Domain name is reserved or restricted']));
                return;
            }

            // Domain not found, respond with a 404 error
            try {
                $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                $settingName = 'web-whois-queries';
                $stmt->bindParam(':name', $settingName);
                $stmt->execute();
            } catch (PDOException $e) {
                $log->error('DB Connection failed: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['Database error:' => $e->getMessage()]));
                return;
            } catch (Throwable $e) {
                $log->error('Error: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['General error:' => $e->getMessage()]));
                return;
            }
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode([
                'rdapConformance' => [
                    'rdap_level_0',
                    'icann_rdap_response_profile_0',
                    'icann_rdap_response_profile_1',
                    'icann_rdap_technical_implementation_guide_0',
                    'icann_rdap_technical_implementation_guide_1',
                ],
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => ['The requested domain was not found in the RDAP database.'],
                "notices" => [
                    [
                        "title" => "Terms of Service",
                        "description" => [
                            "Access to " . strtoupper($tld) . " RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                        ],
                        "links" => [
                            [
                                "href" => $c['rdap_url'] . "/help",
                                'value' => $c['rdap_url'] . '/domain/' . $domain,
                                "rel" => "terms-of-service",
                                "type" => "application/rdap+json"
                            ],
                            [
                                "href" => $c['registry_url'],
                                "value" => $c['registry_url'],
                                "rel" => "alternate",
                                "type" => "text/html"
                            ],
                        ]
                    ],
                    [
                    "description" => [
                        "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                    ]
                    ],
                    [
                    "description" => [
                        "For more information on domain status codes, please visit https://icann.org/epp"
                    ],
                    "links" => [
                        [
                            "href" => "https://icann.org/epp",
                            "value" => "https://icann.org/epp",
                            "rel" => "glossary",
                            "type" => "text/html"
                        ]
                    ],
                        "title" => "Status Codes"
                    ],
                    [
                        "description" => [
                            "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                        ],
                        "links" => [
                        [
                            "href" => "https://icann.org/wicf",
                            "value" => "https://icann.org/wicf",
                            "rel" => "help",
                            "type" => "text/html"
                        ]
                        ],
                        "title" => "RDDS Inaccuracy Complaint Form"
                    ],
                ]
            ], JSON_UNESCAPED_SLASHES));
            // Close the connection
            $pdo = null;
            return;
        }
        
        $domainDetails['crdate'] = (new DateTime($domainDetails['crdate']))->format('Y-m-d\TH:i:s.v\Z');
        $domainDetails['exdate'] = (new DateTime($domainDetails['exdate']))->format('Y-m-d\TH:i:s.v\Z');

        // Query 2: Get status details
        $stmt2 = $pdo->prepare("SELECT status FROM domain_status WHERE domain_id = :domain_id");
        $stmt2->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmt2->execute();
        $statuses = $stmt2->fetchAll(PDO::FETCH_COLUMN, 0);

        // Add rgpstatus to statuses if it's not empty
        if (!empty($domainDetails['rgpstatus'])) {
            $statuses[] = $domainDetails['rgpstatus'];
        }

        // If statuses array is empty, add 'active' to it
        if (empty($statuses)) {
            $statuses[] = 'active';
        }

        $statuses = mapStatuses($statuses);

        // Query: Get DNSSEC details
        $stmt2a = $pdo->prepare("SELECT interface FROM secdns WHERE domain_id = :domain_id");
        $stmt2a->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmt2a->execute();
        $isDelegationSigned = $stmt2a->fetchColumn() > 0;

        $stmt2b = $pdo->prepare("SELECT secure FROM domain_tld WHERE tld = :tld");
        $stmt2b->bindParam(':tld', $tld, PDO::PARAM_STR);
        $stmt2b->execute();
        $isZoneSigned = ($stmt2b->fetchColumn() == 1);

        // Query 3: Get registrar details
        $stmt3 = $pdo->prepare("SELECT id,name,iana_id,whois_server,rdap_server,url,abuse_email,abuse_phone FROM registrar WHERE id = :clid");
        $stmt3->bindParam(':clid', $domainDetails['clid'], PDO::PARAM_INT);
        $stmt3->execute();
        $registrarDetails = $stmt3->fetch(PDO::FETCH_ASSOC);
        
        // Query: Get registrar abuse details
        $stmt3a = $pdo->prepare("SELECT first_name,last_name FROM registrar_contact WHERE registrar_id = :clid AND type = 'abuse'");
        $stmt3a->bindParam(':clid', $domainDetails['clid'], PDO::PARAM_INT);
        $stmt3a->execute();
        $registrarAbuseDetails = $stmt3a->fetch(PDO::FETCH_ASSOC);

        // Query 4: Get registrant details
        $stmt4 = $pdo->prepare("SELECT contact.id,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.voice_x,contact.fax,contact.fax_x,contact.email,contact.disclose_voice,contact.disclose_fax,contact.disclose_email,contact_postalInfo.type,contact_postalInfo.disclose_name_int,contact_postalInfo.disclose_name_loc,contact_postalInfo.disclose_org_int,contact_postalInfo.disclose_org_loc,contact_postalInfo.disclose_addr_int,contact_postalInfo.disclose_addr_loc FROM contact,contact_postalInfo WHERE contact.id=:registrant AND contact_postalInfo.contact_id=contact.id");
        $stmt4->bindParam(':registrant', $domainDetails['registrant'], PDO::PARAM_INT);
        $stmt4->execute();
        $registrantDetails = $stmt4->fetch(PDO::FETCH_ASSOC);

        // Query 5: Get admin, billing and tech contacts        
        $stmtMap = $pdo->prepare("SELECT contact_id, type FROM domain_contact_map WHERE domain_id = :domain_id");
        $stmtMap->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmtMap->execute();
        $contactMap = $stmtMap->fetchAll(PDO::FETCH_ASSOC);
        
        $adminDetails = [];
        $techDetails = [];
        $billingDetails = [];

        foreach ($contactMap as $map) {
            $stmtDetails = $pdo->prepare("SELECT contact.id, contact_postalInfo.name, contact_postalInfo.org, contact_postalInfo.street1, contact_postalInfo.street2, contact_postalInfo.street3, contact_postalInfo.city, contact_postalInfo.sp, contact_postalInfo.pc, contact_postalInfo.cc, contact.voice, contact.voice_x, contact.fax, contact.fax_x, contact.email, contact.disclose_voice,contact.disclose_fax,contact.disclose_email,contact_postalInfo.type,contact_postalInfo.disclose_name_int,contact_postalInfo.disclose_name_loc,contact_postalInfo.disclose_org_int,contact_postalInfo.disclose_org_loc,contact_postalInfo.disclose_addr_int,contact_postalInfo.disclose_addr_loc FROM contact, contact_postalInfo WHERE contact.id = :contact_id AND contact_postalInfo.contact_id = contact.id");
            $stmtDetails->bindParam(':contact_id', $map['contact_id'], PDO::PARAM_INT);
            $stmtDetails->execute();
    
            $contactDetails = $stmtDetails->fetch(PDO::FETCH_ASSOC);
    
            switch ($map['type']) {
                case 'admin':
                    $adminDetails[] = $contactDetails;
                    break;
                case 'tech':
                    $techDetails[] = $contactDetails;
                    break;
                case 'billing':
                    $billingDetails[] = $contactDetails;
                    break;
            }
        }

        // Query 6: Get nameservers
        $stmt6 = $pdo->prepare("
            SELECT host.name, host.id as host_id 
            FROM domain_host_map, host 
            WHERE domain_host_map.domain_id = :domain_id 
            AND domain_host_map.host_id = host.id
        ");
        $stmt6->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmt6->execute();
        $nameservers = $stmt6->fetchAll(PDO::FETCH_ASSOC);
        
        // Define the basic events
        $events = [
            ['eventAction' => 'registration', 'eventDate' => $domainDetails['crdate']],
            ['eventAction' => 'expiration', 'eventDate' => $domainDetails['exdate']],
            ['eventAction' => 'last update of RDAP database', 'eventDate' => (new DateTime())->format('Y-m-d\TH:i:s.v\Z')],
        ];

        // Check if domain last update is set and not empty
        if (isset($domainDetails['lastupdate']) && !empty($domainDetails['lastupdate'])) {
            $updateDateTime = new DateTime($domainDetails['lastupdate']);
            $events[] = [
                'eventAction' => 'last changed',
                'eventDate' => $updateDateTime->format('Y-m-d\TH:i:s.v\Z')
            ];
        }

        // Check if domain transfer date is set and not empty
        if (isset($domainDetails['trdate']) && !empty($domainDetails['trdate'])) {
            $transferDateTime = new DateTime($domainDetails['trdate']);
            $events[] = [
                'eventAction' => 'transfer',
                'eventDate' => $transferDateTime->format('Y-m-d\TH:i:s.v\Z')
            ];
        }
        
        $abuseContactName = ($registrarAbuseDetails) ? $registrarAbuseDetails['first_name'] . ' ' . $registrarAbuseDetails['last_name'] : '';
        $rdapClean = rtrim(preg_replace('#^.*?//#', '', $registrarDetails['rdap_server'] ?? ''), '/');
        $rdapClean = rtrim($rdapClean, '/') . '/';

        $stmt = $pdo->query("SELECT value FROM settings WHERE name = 'handle'");
        $roid = $stmt->fetchColumn();

        // Construct the RDAP response in JSON format
        $rdapResponse = [
            'rdapConformance' => [
                'rdap_level_0',
                'icann_rdap_response_profile_0',
                'icann_rdap_response_profile_1',
                'icann_rdap_technical_implementation_guide_0',
                'icann_rdap_technical_implementation_guide_1',
            ],
            'objectClassName' => 'domain',
            'entities' => array_merge(
                [
                [
                    'objectClassName' => 'entity',
                    'entities' => [
                    [
                        'objectClassName' => 'entity',
                        'roles' => ["abuse"],
                        "status" => ["active"],
                        "vcardArray" => [
                            "vcard",
                            [
                                ['version', new stdClass(), 'text', '4.0'],
                                ["fn", new stdClass(), "text", ($c['limited_rdap'] ?? false) ? "" : $abuseContactName],
                                ["tel", ["type" => ["voice"]], "uri", ($c['limited_rdap'] ?? false) ? "" : "tel:" . $registrarDetails['abuse_phone']],
                                ["email", new stdClass(), "text", ($c['limited_rdap'] ?? false) ? "" : $registrarDetails['abuse_email']]
                            ]
                        ],
                    ],
                    ],
                    "handle" => (string)($registrarDetails['iana_id'] ?: 'R' . $registrarDetails['id'] . '-' . $roid . ''),
                    "links" => [
                        [
                            "href" => $c['rdap_url'] . "/entity/" . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                            "value" => $c['rdap_url'] . "/entity/" . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                            "rel" => "self",
                            "type" => "application/rdap+json"
                        ],
                        [
                            "href" => 'https://' . $rdapClean,
                            "value" => 'https://' . $rdapClean,
                            "rel" => "about",
                            "type" => "application/rdap+json"
                        ]
                    ],
                    "publicIds" => [
                        [
                            "identifier" => ($config['limited_rdap'] ?? false) ? "" : (string)$registrarDetails['iana_id'],
                            "type" => "IANA Registrar ID"
                        ]
                    ],
                    "remarks" => [
                        [
                            "description" => ["This record contains only a summary. For detailed information, please submit a query specifically for this object."],
                            "title" => "Incomplete Data",
                            "type" => "object truncated due to authorization"
                        ]
                    ],
                    "roles" => ["registrar"],
                    "vcardArray" => [
                        "vcard",
                        [
                            ['version', new stdClass(), 'text', '4.0'],
                            ["fn", new stdClass(), "text", $registrarDetails['name']]
                        ]
                    ],
                    ],
                ],
                !$c['minimum_data']
                    ? array_merge(
                        [mapContactToVCard($registrantDetails, 'registrant', $roid)],
                        array_map(function ($contact) use ($roid) {
                            return mapContactToVCard($contact, 'administrative', $roid);
                        }, $adminDetails),
                        array_map(function ($contact) use ($roid) {
                            return mapContactToVCard($contact, 'technical', $roid);
                        }, $techDetails),
                        array_map(function ($contact) use ($roid) {
                            return mapContactToVCard($contact, 'billing', $roid);
                        }, $billingDetails)
                    )
                    : []
            ),
            'events' => $events,
            'handle' => ($config['limited_rdap'] ?? false) ? '' : 'D' . $domainDetails['id'] . '-' . $roid,
            'ldhName' => $domain,
            'links' => [
                [
                    'href' => $c['rdap_url'] . '/domain/' . $domain,
                    'value' => $c['rdap_url'] . '/domain/' . $domain,
                    'rel' => 'self',
                    'type' => 'application/rdap+json',
                ],
                [
                    'href' => $c['rdap_url'] . '/domain/' . $domain,
                    'value' => 'https://' . $rdapClean . '/domain/' . $domain,
                    'rel' => 'related',
                    'type' => 'application/rdap+json',
                ]
            ],
            'nameservers' => array_map(function ($nameserverDetails) use ($c, $roid) {
                return [
                    'objectClassName' => 'nameserver',
                    'handle' => 'H' . $nameserverDetails['host_id'] . '-' . $roid . '',
                    'ldhName' => $nameserverDetails['name'],
                    'links' => [
                        [
                            'href' => $c['rdap_url'] . '/nameserver/' . $nameserverDetails['name'],
                            'value' => $c['rdap_url'] . '/nameserver/' . $nameserverDetails['name'],
                            'rel' => 'self',
                            'type' => 'application/rdap+json',
                        ],
                    ],
                    'remarks' => [
                        [
                            "description" => [
                                "This record contains only a brief summary. To access the full details, please initiate a specific query targeting this entity."
                            ],
                            "title" => "Incomplete Data",
                            "type" => "object truncated due to authorization"
                        ],
                    ],
                ];
            }, $nameservers),
            "secureDNS" => [
                "delegationSigned" => $isDelegationSigned,
                "zoneSigned" => $isZoneSigned
            ],
            'status' => $statuses,
            "notices" => [
                [
                    "title" => "Terms of Service",
                    "description" => [
                        "Access to " . strtoupper($tld) . " RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                        "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                        "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                        "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                        "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                        "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                    ],
                    "links" => [
                        [
                            "href" => $c['rdap_url'] . "/help",
                            'value' => $c['rdap_url'] . '/domain/' . $domain,
                            "rel" => "terms-of-service",
                            "type" => "application/rdap+json"
                        ],
                        [
                            "href" => $c['registry_url'],
                            "value" => $c['registry_url'],
                            "rel" => "alternate",
                            "type" => "text/html"
                        ],
                    ]
                ],
                [
                "description" => [
                    "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                ]
                ],
                [
                "description" => [
                    "For more information on domain status codes, please visit https://icann.org/epp"
                ],
                "links" => [
                    [
                        "href" => "https://icann.org/epp",
                        "value" => "https://icann.org/epp",
                        "rel" => "glossary",
                        "type" => "text/html"
                    ]
                ],
                    "title" => "Status Codes"
                ],
                [
                    "description" => [
                        "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                    ],
                    "links" => [
                    [
                        "href" => "https://icann.org/wicf",
                        "value" => "https://icann.org/wicf",
                        "rel" => "help",
                        "type" => "text/html"
                    ]
                    ],
                    "title" => "RDDS Inaccuracy Complaint Form"
                ],
            ]
        ];

        // Send the RDAP response
        try {
            $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
            $settingName = 'web-whois-queries';
            $stmt->bindParam(':name', $settingName);
            $stmt->execute();
        } catch (PDOException $e) {
            $log->error('DB Connection failed: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['Database error:' => $e->getMessage()]));
            return;
        } catch (Throwable $e) {
            $log->error('Error: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['General error:' => $e->getMessage()]));
            return;
        }
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(200);
        $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
    } catch (PDOException $e) {
        $log->error('DB Connection failed: ' . $e->getMessage());
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(503);
        $response->end(json_encode(['error' => 'Error connecting to the RDAP database']));
        return;
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/rdap+json');
        $response->end(json_encode(['General error:' => $e->getMessage()]));
        return;
    }
}

function handleEntityQuery($request, $response, $pdo, $entityHandle, $c, $log) {
    // Extract and validate the entity handle from the request
    $entity = trim($entityHandle);

    // Empty entity check
    if (!$entity) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Please enter an entity']));
        return;
    }
    
    // Check for prohibited patterns in RDAP entity handle
    if (!preg_match('/^[A-Za-z0-9-]+$/', $entity)) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Entity handle invalid format']));
        return;
    }

    // Perform the RDAP lookup
    try {
        if (!is_numeric($entity) && !preg_match('/^[A-Za-z0-9-]+$/', $entity)) {
            // Return a 404 response if $entity is not a purely numeric string
            try {
                $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                $settingName = 'web-whois-queries';
                $stmt->bindParam(':name', $settingName);
                $stmt->execute();
            } catch (PDOException $e) {
                $log->error('DB Connection failed: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['Database error:' => $e->getMessage()]));
                return;
            } catch (Throwable $e) {
                $log->error('Error: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['General error:' => $e->getMessage()]));
                return;
            }
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode([
                'rdapConformance' => [
                    'rdap_level_0',
                    'icann_rdap_response_profile_0',
                    'icann_rdap_response_profile_1',
                    'icann_rdap_technical_implementation_guide_0',
                    'icann_rdap_technical_implementation_guide_1',
                ],
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => ['The requested entity was not found in the RDAP database.'],
                "notices" => [
                    [
                        "title" => "Terms of Service",
                        "description" => [
                            "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                        ],
                        "links" => [
                            [
                                "href" => $c['rdap_url'] . "/help",
                                "value" => $c['rdap_url'] . "/entity/" . $entity,
                                "rel" => "terms-of-service",
                                "type" => "application/rdap+json"
                            ],
                            [
                                "href" => $c['registry_url'],
                                "value" => $c['registry_url'],
                                "rel" => "alternate",
                                "type" => "text/html"
                            ],
                        ]
                    ],
                    [
                    "description" => [
                        "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                    ]
                    ],
                    [
                    "description" => [
                        "For more information on domain status codes, please visit https://icann.org/epp"
                    ],
                    "links" => [
                        [
                            "href" => "https://icann.org/epp",
                            "value" => "https://icann.org/epp",
                            "rel" => "glossary",
                            "type" => "text/html"
                        ]
                    ],
                        "title" => "Status Codes"
                    ],
                    [
                        "description" => [
                            "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                        ],
                        "links" => [
                        [
                            "href" => "https://icann.org/wicf",
                            "value" => "https://icann.org/wicf",
                            "rel" => "help",
                            "type" => "text/html"
                        ]
                        ],
                        "title" => "RDDS Inaccuracy Complaint Form"
                    ],
                ]
            ], JSON_UNESCAPED_SLASHES));
            // Close the connection
            $pdo = null;
            return;
        }

        // Query 1: Get registrar details
        $stmt1 = $pdo->prepare("SELECT id,name,clid,iana_id,whois_server,rdap_server,url,email,abuse_email,abuse_phone FROM registrar WHERE iana_id = :iana_id");
        $stmt1->bindParam(':iana_id', $entity, PDO::PARAM_INT);
        $stmt1->execute();
        $registrarDetails = $stmt1->fetch(PDO::FETCH_ASSOC);
        
        // Check if the first query returned a result
        if (!$registrarDetails) {
            // Query 2: Get registrar details by id as a fallback for ccTLDs without iana_id
            $stmt2 = $pdo->prepare("SELECT id, name, clid, iana_id, whois_server, rdap_server, url, email, abuse_email, abuse_phone FROM registrar WHERE id = :id");
            $stmt2->bindParam(':id', $entity, PDO::PARAM_INT);
            $stmt2->execute();
            $registrarDetails = $stmt2->fetch(PDO::FETCH_ASSOC);
        }
        
        // Check if the entity exists
        if (!$registrarDetails) {
            // Entity not found, respond with a 404 error
            try {
                $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                $settingName = 'web-whois-queries';
                $stmt->bindParam(':name', $settingName);
                $stmt->execute();
            } catch (PDOException $e) {
                $log->error('DB Connection failed: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['Database error:' => $e->getMessage()]));
                return;
            } catch (Throwable $e) {
                $log->error('Error: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['General error:' => $e->getMessage()]));
                return;
            }
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode([
                'rdapConformance' => [
                    'rdap_level_0',
                    'icann_rdap_response_profile_0',
                    'icann_rdap_response_profile_1',
                    'icann_rdap_technical_implementation_guide_0',
                    'icann_rdap_technical_implementation_guide_1',
                ],
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => ['The requested entity was not found in the RDAP database.'],
                "notices" => [
                    [
                        "title" => "Terms of Service",
                        "description" => [
                            "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                        ],
                        "links" => [
                            [
                                "href" => $c['rdap_url'] . "/help",
                                "value" => $c['rdap_url'] . "/entity/" . $entity,
                                "rel" => "terms-of-service",
                                "type" => "application/rdap+json"
                            ],
                            [
                                "href" => $c['registry_url'],
                                "value" => $c['registry_url'],
                                "rel" => "alternate",
                                "type" => "text/html"
                            ],
                        ]
                    ],
                    [
                    "description" => [
                        "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                    ]
                    ],
                    [
                    "description" => [
                        "For more information on domain status codes, please visit https://icann.org/epp"
                    ],
                    "links" => [
                        [
                            "href" => "https://icann.org/epp",
                            "value" => "https://icann.org/epp",
                            "rel" => "glossary",
                            "type" => "text/html"
                        ]
                    ],
                        "title" => "Status Codes"
                    ],
                    [
                        "description" => [
                            "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                        ],
                        "links" => [
                        [
                            "href" => "https://icann.org/wicf",
                            "value" => "https://icann.org/wicf",
                            "rel" => "help",
                            "type" => "text/html"
                        ]
                        ],
                        "title" => "RDDS Inaccuracy Complaint Form"
                    ],
                ]
            ], JSON_UNESCAPED_SLASHES));
            // Close the connection
            $pdo = null;
            return;
        }

        // Query 2: Fetch all contact types for a registrar
        $stmt2 = $pdo->prepare("SELECT type, first_name, last_name, voice, email FROM registrar_contact WHERE registrar_id = :clid");
        $stmt2->bindParam(':clid', $registrarDetails['id'], PDO::PARAM_INT);
        $stmt2->execute();
        $contacts = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // Query 3: Get registrar abuse details
        $stmt3 = $pdo->prepare("SELECT org,street1,street2,city,sp,pc,cc FROM registrar_contact WHERE registrar_id = :clid AND type = 'owner'");
        $stmt3->bindParam(':clid', $registrarDetails['id'], PDO::PARAM_INT);
        $stmt3->execute();
        $registrarContact = $stmt3->fetch(PDO::FETCH_ASSOC);

        // Define the basic events
        $events = [
            ['eventAction' => 'last update of RDAP database', 'eventDate' => (new DateTime())->format('Y-m-d\TH:i:s.v\Z')],
        ];

        // Initialize an array to hold entity blocks
        $entityBlocks = [];
        // Define an array of allowed contact types
        $allowedTypes = ['owner', 'billing', 'abuse', 'tech'];

        foreach ($contacts as $contact) {
            // Check if the contact type is one of the allowed types
            if (in_array($contact['type'], $allowedTypes)) {
                // Build the full name
                $fullName = $contact['first_name'] . ' ' . $contact['last_name'];

                // Create an entity block for each allowed contact type
                $entityBlock = [
                    'objectClassName' => 'entity',
                    'roles' => [
                        match ($contact['type']) {
                            'owner' => 'administrative',
                            'tech' => 'technical',
                            default => $contact['type']
                        }
                    ],
                    "status" => ["active"],
                    "vcardArray" => [
                        "vcard",
                        [
                            ['version', new stdClass(), 'text', '4.0'],
                            ["fn", new stdClass(), "text", $fullName],
                            ["tel", ["type" => ["voice"]], $contact['voice'] ? "uri" : "text", $contact['voice'] ? "tel:" . $contact['voice'] : ""],
                            ["email", new stdClass(), "text", $contact['email']]
                        ]
                    ],
                ];

                // Add the entity block to the array
                $entityBlocks[] = $entityBlock;
            }
        }
        
        $stmt = $pdo->query("SELECT value FROM settings WHERE name = 'handle'");
        $roid = $stmt->fetchColumn();

        // Construct the RDAP response in JSON format
        $rdapResponse = [
            'rdapConformance' => [
                'rdap_level_0',
                'icann_rdap_response_profile_0',
                'icann_rdap_response_profile_1',
                'icann_rdap_technical_implementation_guide_0',
                'icann_rdap_technical_implementation_guide_1',
            ],
            'objectClassName' => 'entity',
            'entities' => $entityBlocks,
            "handle" => (string)($registrarDetails['iana_id'] ?: 'R' . $registrarDetails['id'] . '-' . $roid . ''),
            'events' => $events,
            'links' => [
                [
                    'href' => $c['rdap_url'] . '/entity/' . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                    'value' => $c['rdap_url'] . '/entity/' . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                    'rel' => 'self',
                    'type' => 'application/rdap+json',
                ]
            ],
            "publicIds" => [
                [
                    "identifier" => (string)$registrarDetails['iana_id'],
                    "type" => "IANA Registrar ID"
                ]
            ],
            "roles" => ["registrar"],
            "status" => ["active"],
            "lang" => "en",
            "vcardArray" => [
                "vcard",
                [
                    ['version', new stdClass(), 'text', '4.0'],
                    ['fn', new stdClass(), 'text', $registrarContact['org']],
                    ['adr', new stdClass(), 'text', [
                        '',         // PO Box
                        '',         // Extended address
                        $registrarContact['street1'], // Street address
                        $registrarContact['city'],    // City
                        $registrarContact['sp'],      // Region
                        $registrarContact['pc'],      // Postal code
                        strtoupper($registrarContact['cc']) // Country
                    ]],
                    ["tel", ["type" => ["voice"]], $contact['voice'] ? "uri" : "text", $contact['voice'] ? "tel:" . $contact['voice'] : ""],
                    ['email', new stdClass(), 'text', $registrarDetails['email']],
                ]
            ],
            "notices" => [
                    [
                        "title" => "Terms of Service",
                        "description" => [
                            "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                        ],
                        "links" => [
                            [
                                "href" => $c['rdap_url'] . "/help",
                                "value" => $c['rdap_url'] . "/entity/" . $entity,
                                "rel" => "terms-of-service",
                                "type" => "application/rdap+json"
                            ],
                            [
                                "href" => $c['registry_url'],
                                "value" => $c['registry_url'],
                                "rel" => "alternate",
                                "type" => "text/html"
                            ],
                        ]
                    ],
                    [
                    "description" => [
                        "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                    ]
                    ],
                    [
                    "description" => [
                        "For more information on domain status codes, please visit https://icann.org/epp"
                    ],
                    "links" => [
                        [
                            "href" => "https://icann.org/epp",
                            "value" => "https://icann.org/epp",
                            "rel" => "glossary",
                            "type" => "text/html"
                        ]
                    ],
                        "title" => "Status Codes"
                    ],
                    [
                        "description" => [
                            "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                        ],
                        "links" => [
                        [
                            "href" => "https://icann.org/wicf",
                            "value" => "https://icann.org/wicf",
                            "rel" => "help",
                            "type" => "text/html"
                        ]
                        ],
                        "title" => "RDDS Inaccuracy Complaint Form"
                    ],
                ]
        ];

        // Send the RDAP response
        try {
            $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
            $settingName = 'web-whois-queries';
            $stmt->bindParam(':name', $settingName);
            $stmt->execute();
        } catch (PDOException $e) {
            $log->error('DB Connection failed: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['Database error:' => $e->getMessage()]));
            return;
        } catch (Throwable $e) {
            $log->error('Error: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['General error:' => $e->getMessage()]));
            return;
        }
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(200);
        $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
    } catch (PDOException $e) {
        $log->error('DB Connection failed: ' . $e->getMessage());
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(503);
        $response->end(json_encode(['error' => 'Error connecting to the RDAP database']));
        return;
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/rdap+json');
        $response->end(json_encode(['General error:' => $e->getMessage()]));
        return;
    }
}

function handleNameserverQuery($request, $response, $pdo, $nameserverHandle, $c, $log) {
    // Extract and validate the nameserver handle from the request
    $ns = urldecode($nameserverHandle);
    $ns = trim($ns);

    // Empty nameserver check
    if (!$ns) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Please enter a nameserver']));
        return;
    }
    
    // Check nameserver length
    $labels = explode('.', $ns);
    $validLengths = array_map(function ($label) {
        return strlen($label) <= 63;
    }, $labels);

    if (strlen($ns) > 253 || in_array(false, $validLengths, true)) {
        // The nameserver format is invalid due to length
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Nameserver is too long']));
        return;
    }
    
    // Convert to Punycode if the host is not in ASCII
    if (!mb_detect_encoding($ns, 'ASCII', true)) {
        $convertedDomain = idn_to_ascii($ns, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($convertedDomain === false) {
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(400); // Bad Request
            $response->end(json_encode(['error' => 'Host conversion to Punycode failed']));
            return;
        } else {
            $ns = $convertedDomain;
        }
    }

    // Validate nameserver
    if (!isValidHostname($ns)) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Nameserver format is invalid. Expected a fully qualified domain name (FQDN), punycode supported (e.g., ns1.example.com)']));
        return;
    }
    
    // Extract TLD from the domain
    $parts = explode('.', $ns);
    $tld = "." . end($parts);

    // Perform the RDAP lookup
    try {
        // Query 1: Get nameserver details
        $stmt1 = $pdo->prepare("SELECT id,name,clid FROM host WHERE name = :ns");
        $stmt1->bindParam(':ns', $ns, PDO::PARAM_STR);
        $stmt1->execute();
        $hostDetails = $stmt1->fetch(PDO::FETCH_ASSOC);
        
        // Check if the nameserver exists
        if (!$hostDetails) {
            // Nameserver not found, respond with a 404 error
            try {
                $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                $settingName = 'web-whois-queries';
                $stmt->bindParam(':name', $settingName);
                $stmt->execute();
            } catch (PDOException $e) {
                $log->error('DB Connection failed: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['Database error:' => $e->getMessage()]));
                return;
            } catch (Throwable $e) {
                $log->error('Error: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['General error:' => $e->getMessage()]));
                return;
            }
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode([
                'rdapConformance' => [
                    'rdap_level_0',
                    'icann_rdap_response_profile_0',
                    'icann_rdap_response_profile_1',
                    'icann_rdap_technical_implementation_guide_0',
                    'icann_rdap_technical_implementation_guide_1',
                ],
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => ['The requested nameserver was not found in the RDAP database.'],
                "notices" => [
                    [
                        "title" => "Terms of Service",
                        "description" => [
                            "Access to " . strtoupper($tld) . " RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                        ],
                        "links" => [
                            [
                                "href" => $c['rdap_url'] . "/help",
                                "value" => $c['rdap_url'] . "/nameserver/" . $ns,
                                "rel" => "terms-of-service",
                                "type" => "application/rdap+json"
                            ],
                            [
                                "href" => $c['registry_url'],
                                "value" => $c['registry_url'],
                                "rel" => "alternate",
                                "type" => "text/html"
                            ],
                        ]
                    ],
                    [
                    "description" => [
                        "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                    ]
                    ],
                    [
                    "description" => [
                        "For more information on domain status codes, please visit https://icann.org/epp"
                    ],
                    "links" => [
                        [
                            "href" => "https://icann.org/epp",
                            "value" => "https://icann.org/epp",
                            "rel" => "glossary",
                            "type" => "text/html"
                        ]
                    ],
                        "title" => "Status Codes"
                    ],
                    [
                        "description" => [
                            "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                        ],
                        "links" => [
                        [
                            "href" => "https://icann.org/wicf",
                            "value" => "https://icann.org/wicf",
                            "rel" => "help",
                            "type" => "text/html"
                        ]
                        ],
                        "title" => "RDDS Inaccuracy Complaint Form"
                    ],
                ]
            ], JSON_UNESCAPED_SLASHES));
            // Close the connection
            $pdo = null;
            return;
        }

        // Query 2: Get status details
        $stmt2 = $pdo->prepare("SELECT status FROM host_status WHERE host_id = :host_id");
        $stmt2->bindParam(':host_id', $hostDetails['id'], PDO::PARAM_INT);
        $stmt2->execute();
        $statuses = $stmt2->fetchAll(PDO::FETCH_COLUMN, 0);
        
        // Query 2a: Get associated status details
        $stmt2a = $pdo->prepare("SELECT domain_id FROM domain_host_map WHERE host_id = :host_id");
        $stmt2a->bindParam(':host_id', $hostDetails['id'], PDO::PARAM_INT);
        $stmt2a->execute();
        $associated = $stmt2a->fetchAll(PDO::FETCH_COLUMN, 0);
        
        // Query 3: Get IP details
        $stmt3 = $pdo->prepare("SELECT addr,ip FROM host_addr WHERE host_id = :host_id");
        $stmt3->bindParam(':host_id', $hostDetails['id'], PDO::PARAM_INT);
        $stmt3->execute();
        $ipDetails = $stmt3->fetchAll(PDO::FETCH_COLUMN, 0);

        // Query 4: Get registrar details
        $stmt4 = $pdo->prepare("SELECT id,name,iana_id,whois_server,rdap_server,url,abuse_email,abuse_phone FROM registrar WHERE id = :clid");
        $stmt4->bindParam(':clid', $hostDetails['clid'], PDO::PARAM_INT);
        $stmt4->execute();
        $registrarDetails = $stmt4->fetch(PDO::FETCH_ASSOC);
        
        // Query 5: Get registrar abuse details
        $stmt5 = $pdo->prepare("SELECT first_name,last_name FROM registrar_contact WHERE registrar_id = :clid AND type = 'abuse'");
        $stmt5->bindParam(':clid', $hostDetails['clid'], PDO::PARAM_INT);
        $stmt5->execute();
        $registrarAbuseDetails = $stmt5->fetch(PDO::FETCH_ASSOC);
        
        // Define the basic events
        $events = [
            ['eventAction' => 'last update of RDAP database', 'eventDate' => (new DateTime())->format('Y-m-d\TH:i:s.v\Z')],
        ];

        $abuseContactName = ($registrarAbuseDetails) ? $registrarAbuseDetails['first_name'] . ' ' . $registrarAbuseDetails['last_name'] : '';

        // Build the 'ipAddresses' structure
        $ipAddresses = array_reduce($ipDetails, function ($carry, $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $carry['v4'][] = $ip;
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $carry['v6'][] = $ip;
            }
            return $carry;
        }, ['v4' => [], 'v6' => []]);  // Initialize with 'v4' and 'v6' keys

        // If there are associated domains, add 'associated' to the statuses
        if (!empty($associated)) {
            $statuses[] = 'associated';
        }
        $statuses = array_unique(array_map(fn($s) => $s === 'ok' ? 'active' : $s, $statuses));
        
        $stmt = $pdo->query("SELECT value FROM settings WHERE name = 'handle'");
        $roid = $stmt->fetchColumn();

        // Construct the RDAP response in JSON format
        $rdapResponse = [
            'rdapConformance' => [
                'rdap_level_0',
                'icann_rdap_response_profile_0',
                'icann_rdap_response_profile_1',
                'icann_rdap_technical_implementation_guide_0',
                'icann_rdap_technical_implementation_guide_1',
            ],
            'objectClassName' => 'nameserver',
            'entities' => array_merge(
                [
                [
                    'objectClassName' => 'entity',
                    'entities' => [
                    [
                        'objectClassName' => 'entity',
                        'roles' => ["abuse"],
                        "status" => ["active"],
                        "vcardArray" => [
                            "vcard",
                            [
                                ['version', new stdClass(), 'text', '4.0'],
                                ["fn", new stdClass(), "text", ($c['limited_rdap'] ?? false) ? "" : $abuseContactName],
                                ["tel", ["type" => ["voice"]], "uri", ($c['limited_rdap'] ?? false) ? "" : "tel:" . $registrarDetails['abuse_phone']],
                                ["email", new stdClass(), "text", ($c['limited_rdap'] ?? false) ? "" : $registrarDetails['abuse_email']]
                            ]
                        ],
                    ],
                    ],
                    "handle" => (string)($registrarDetails['iana_id'] ?: 'R' . $registrarDetails['id'] . '-' . $roid . ''),
                    "links" => [
                        [
                            "href" => $c['rdap_url'] . "/entity/" . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                            "value" => $c['rdap_url'] . "/entity/" . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                            "rel" => "self",
                            "type" => "application/rdap+json"
                        ]
                    ],
                    "publicIds" => [
                        [
                            "identifier" => ($c['limited_rdap'] ?? false) ? "" : (string)$registrarDetails['iana_id'],
                            "type" => "IANA Registrar ID"
                        ]
                    ],
                    "remarks" => [
                        [
                            "description" => ["This record contains only a summary. For detailed information, please submit a query specifically for this object."],
                            "title" => "Incomplete Data",
                            "type" => "object truncated due to authorization"
                        ]
                    ],
                    "roles" => ["registrar"],
                    "vcardArray" => [
                        "vcard",
                        [
                            ['version', new stdClass(), 'text', '4.0'],
                            ["fn", new stdClass(), "text", $registrarDetails['name']]
                        ]
                    ],
                    ],
                ],
            ),
            'handle' => 'H' . $hostDetails['id'] . '-' . $roid . '',
            ...(
                empty($ipAddresses['v4']) && empty($ipAddresses['v6'])
                ? []
                : ['ipAddresses' => $ipAddresses]
            ),
            'events' => $events,
            'ldhName' => $hostDetails['name'],
            'links' => [
                [
                    'value' => $c['rdap_url'] . '/nameserver/' . $hostDetails['name'],
                    'rel' => 'self',
                    'href' => $c['rdap_url'] . '/nameserver/' . $hostDetails['name'],
                    'type' => 'application/rdap+json',
                ]
            ],
            'status' => $statuses,
            "notices" => [
                [
                    "title" => "Terms of Service",
                    "description" => [
                        "Access to " . strtoupper($tld) . " RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                        "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                        "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                        "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                        "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                        "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                    ],
                    "links" => [
                        [
                            "href" => $c['rdap_url'] . "/help",
                            "value" => $c['rdap_url'] . "/nameserver/" . $ns,
                            "rel" => "terms-of-service",
                            "type" => "application/rdap+json"
                        ],
                        [
                            "href" => $c['registry_url'],
                            "value" => $c['registry_url'],
                            "rel" => "alternate",
                            "type" => "text/html"
                        ],
                    ]
                ],
                [
                "description" => [
                    "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                ]
                ],
                [
                "description" => [
                    "For more information on domain status codes, please visit https://icann.org/epp"
                ],
                "links" => [
                    [
                        "href" => "https://icann.org/epp",
                        "value" => "https://icann.org/epp",
                        "rel" => "glossary",
                        "type" => "text/html"
                    ]
                ],
                    "title" => "Status Codes"
                ],
                [
                    "description" => [
                        "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                    ],
                    "links" => [
                    [
                        "href" => "https://icann.org/wicf",
                        "value" => "https://icann.org/wicf",
                        "rel" => "help",
                        "type" => "text/html"
                    ]
                    ],
                    "title" => "RDDS Inaccuracy Complaint Form"
                ],
            ]
        ];

        // Send the RDAP response
        try {
            $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
            $settingName = 'web-whois-queries';
            $stmt->bindParam(':name', $settingName);
            $stmt->execute();
        } catch (PDOException $e) {
            $log->error('DB Connection failed: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['Database error:' => $e->getMessage()]));
            return;
        } catch (Throwable $e) {
            $log->error('Error: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['General error:' => $e->getMessage()]));
            return;
        }    
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(200);
        $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
    } catch (PDOException $e) {
        $log->error('DB Connection failed: ' . $e->getMessage());
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(503);
        $response->end(json_encode(['error' => 'Error connecting to the RDAP database']));
        return;
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/rdap+json');
        $response->end(json_encode(['General error:' => $e->getMessage()]));
        return;
    }
}

function handleDomainSearchQuery($request, $response, $pdo, $searchPattern, $c, $log, $searchType) {
    // Extract and validate the domain name from the request
    $domain = urldecode($searchPattern);
    $domain = trim($domain);

    // Empty domain check
    if (!$domain) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Please enter a domain name']));
        return;
    }
    
    switch ($searchType) {
        case 'name':
            // Search by domain name
            break;
        case 'nsLdhName':
            // Search by nameserver LDH name
            try {
                $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                $settingName = 'web-whois-queries';
                $stmt->bindParam(':name', $settingName);
                $stmt->execute();
            } catch (PDOException $e) {
                $log->error('DB Connection failed: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['Database error:' => $e->getMessage()]));
                return;
            } catch (Throwable $e) {
                $log->error('Error: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['General error:' => $e->getMessage()]));
                return;
            }
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode([
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => ['The requested nameserver was not found in the RDAP database.'],
            ]));
            // Close the connection
            $pdo = null;
            return;
        case 'nsIp':
            // Search by nameserver IP address
            try {
                $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                $settingName = 'web-whois-queries';
                $stmt->bindParam(':name', $settingName);
                $stmt->execute();
            } catch (PDOException $e) {
                $log->error('DB Connection failed: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['Database error:' => $e->getMessage()]));
                return;
            } catch (Throwable $e) {
                $log->error('Error: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['General error:' => $e->getMessage()]));
                return;
            }
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode([
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => 'The requested IP was not found in the RDAP database.',
            ]));
            // Close the connection
            $pdo = null;
            return;
    }
    
    // Check domain length
    if (strlen($domain) > 68) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name is too long']));
        return;
    }
    
    // Convert to Punycode if the domain is not in ASCII
    if (!mb_detect_encoding($domain, 'ASCII', true)) {
        $convertedDomain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($convertedDomain === false) {
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(400); // Bad Request
            $response->end(json_encode(['error' => 'Domain conversion to Punycode failed']));
            return;
        } else {
            $domain = $convertedDomain;
        }
    }
    
    // Check for prohibited patterns in domain names
    if (!preg_match('/^(?:(xn--[a-zA-Z0-9-]{1,63}|[a-zA-Z0-9-]{1,63})\.){1,3}(xn--[a-zA-Z0-9-]{2,63}|[a-zA-Z]{2,63})$/', $domain)) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name invalid format']));
        return;
    }
    
    // Extract TLD from the domain
    $parts = explode('.', $domain);
    $tld = "." . end($parts);

    // Check if the TLD exists in the domain_tld table
    $stmtTLD = $pdo->prepare("SELECT COUNT(*) FROM domain_tld WHERE tld = :tld");
    $stmtTLD->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtTLD->execute();
    $tldExists = $stmtTLD->fetchColumn();

    if (!$tldExists) {
        $errorData = [
            'rdapConformance' => [
                'rdap_level_0',
                'icann_rdap_response_profile_0',
                'icann_rdap_response_profile_1',
                'icann_rdap_technical_implementation_guide_0',
                'icann_rdap_technical_implementation_guide_1',
            ],
            'errorCode' => 404,
            'title' => 'Invalid TLD',
            'description' => ['Please search only allowed TLDs.'],
        ];

        $response->header('Content-Type', 'application/rdap+json');
        $response->status(404);
        $response->end(json_encode($errorData));
        return;
    }

    // Check if domain is reserved
    $stmtReserved = $pdo->prepare("SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1");
    $stmtReserved->execute([$parts[0]]);
    $domain_already_reserved = $stmtReserved->fetchColumn();

    if ($domain_already_reserved) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name is reserved or restricted']));
        return;
    }
    
    // Fetch the IDN regex for the given TLD
    $stmtRegex = $pdo->prepare("SELECT idn_table FROM domain_tld WHERE tld = :tld");
    $stmtRegex->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtRegex->execute();
    $idnRegex = $stmtRegex->fetchColumn();

    if (!$idnRegex) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Failed to fetch domain IDN table']));
        return;
    }

    // Check for invalid characters using fetched regex
    if (strpos($parts[0], 'xn--') === 0) {
        $label = idn_to_utf8($parts[0], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    } else {
        $label = $parts[0];
    }
    if (!preg_match($idnRegex, $label)) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name invalid IDN characters']));
        return;
    }

    // Perform the RDAP lookup
    try {
        // Query 1: Get domain details
        $stmt1 = $pdo->prepare("SELECT * FROM domain WHERE name = :domain");
        $stmt1->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt1->execute();
        $domainDetails = $stmt1->fetch(PDO::FETCH_ASSOC);

        // Check if the domain exists
        if (!$domainDetails) {
            // Domain not found, respond with a 404 error
            try {
                $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                $settingName = 'web-whois-queries';
                $stmt->bindParam(':name', $settingName);
                $stmt->execute();
            } catch (PDOException $e) {
                $log->error('DB Connection failed: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['Database error:' => $e->getMessage()]));
                return;
            } catch (Throwable $e) {
                $log->error('Error: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['General error:' => $e->getMessage()]));
                return;
            }
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode([
                'rdapConformance' => [
                    'rdap_level_0',
                    'icann_rdap_response_profile_0',
                    'icann_rdap_response_profile_1',
                    'icann_rdap_technical_implementation_guide_0',
                    'icann_rdap_technical_implementation_guide_1',
                ],
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => ['The requested domain was not found in the RDAP database.'],
                "notices" => [
                    [
                        "title" => "Terms of Service",
                        "description" => [
                            "Access to " . strtoupper($tld) . " RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                        ],
                        "links" => [
                            [
                                "href" => $c['rdap_url'] . "/help",
                                "value" => $c['rdap_url'] . "/help",
                                "rel" => "terms-of-service",
                                "type" => "application/rdap+json"
                            ],
                            [
                                "href" => $c['registry_url'],
                                "value" => $c['registry_url'],
                                "rel" => "alternate",
                                "type" => "text/html"
                            ],
                        ]
                    ],
                    [
                    "description" => [
                        "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                    ]
                    ],
                    [
                    "description" => [
                        "For more information on domain status codes, please visit https://icann.org/epp"
                    ],
                    "links" => [
                        [
                            "href" => "https://icann.org/epp",
                            "value" => "https://icann.org/epp",
                            "rel" => "glossary",
                            "type" => "text/html"
                        ]
                    ],
                        "title" => "Status Codes"
                    ],
                    [
                        "description" => [
                            "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                        ],
                        "links" => [
                        [
                            "href" => "https://icann.org/wicf",
                            "value" => "https://icann.org/wicf",
                            "rel" => "help",
                            "type" => "text/html"
                        ]
                        ],
                        "title" => "RDDS Inaccuracy Complaint Form"
                    ],
                ]
            ], JSON_UNESCAPED_SLASHES));
            // Close the connection
            $pdo = null;
            return;
        }
        
        $domainDetails['crdate'] = (new DateTime($domainDetails['crdate']))->format('Y-m-d\TH:i:s.v\Z');
        $domainDetails['exdate'] = (new DateTime($domainDetails['exdate']))->format('Y-m-d\TH:i:s.v\Z');

        // Query 2: Get status details
        $stmt2 = $pdo->prepare("SELECT status FROM domain_status WHERE domain_id = :domain_id");
        $stmt2->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmt2->execute();
        $statuses = $stmt2->fetchAll(PDO::FETCH_COLUMN, 0);
        
        // Add rgpstatus to statuses if it's not empty
        //if (!empty($domainDetails['rgpstatus'])) {
            //$statuses[] = $domainDetails['rgpstatus'];
        //}

        // If statuses array is empty, add 'active' to it
        if (empty($statuses)) {
            $statuses[] = 'active';
        }
        
        // Query: Get DNSSEC details
        $stmt2a = $pdo->prepare("SELECT interface FROM secdns WHERE domain_id = :domain_id");
        $stmt2a->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmt2a->execute();
        $isDelegationSigned = $stmt2a->fetchColumn() > 0;

        $stmt2b = $pdo->prepare("SELECT secure FROM domain_tld WHERE tld = :tld");
        $stmt2b->bindParam(':tld', $tld, PDO::PARAM_STR);
        $stmt2b->execute();
        $isZoneSigned = ($stmt2b->fetchColumn() == 1);

        // Query 3: Get registrar details
        $stmt3 = $pdo->prepare("SELECT id,name,iana_id,whois_server,rdap_server,url,abuse_email,abuse_phone FROM registrar WHERE id = :clid");
        $stmt3->bindParam(':clid', $domainDetails['clid'], PDO::PARAM_INT);
        $stmt3->execute();
        $registrarDetails = $stmt3->fetch(PDO::FETCH_ASSOC);
        
        // Query: Get registrar abuse details
        $stmt3a = $pdo->prepare("SELECT first_name,last_name FROM registrar_contact WHERE registrar_id = :clid AND type = 'abuse'");
        $stmt3a->bindParam(':clid', $domainDetails['clid'], PDO::PARAM_INT);
        $stmt3a->execute();
        $registrarAbuseDetails = $stmt3a->fetch(PDO::FETCH_ASSOC);

        // Query 4: Get registrant details
        $stmt4 = $pdo->prepare("SELECT contact.identifier,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.voice_x,contact.fax,contact.fax_x,contact.email,contact.disclose_voice,contact.disclose_fax,contact.disclose_email,contact_postalInfo.type,contact_postalInfo.disclose_name_int,contact_postalInfo.disclose_name_loc,contact_postalInfo.disclose_org_int,contact_postalInfo.disclose_org_loc,contact_postalInfo.disclose_addr_int,contact_postalInfo.disclose_addr_loc FROM contact,contact_postalInfo WHERE contact.id=:registrant AND contact_postalInfo.contact_id=contact.id");
        $stmt4->bindParam(':registrant', $domainDetails['registrant'], PDO::PARAM_INT);
        $stmt4->execute();
        $registrantDetails = $stmt4->fetch(PDO::FETCH_ASSOC);

        // Query 5: Get admin, billing and tech contacts        
        $stmtMap = $pdo->prepare("SELECT contact_id, type FROM domain_contact_map WHERE domain_id = :domain_id");
        $stmtMap->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmtMap->execute();
        $contactMap = $stmtMap->fetchAll(PDO::FETCH_ASSOC);
        
        $adminDetails = [];
        $techDetails = [];
        $billingDetails = [];

        foreach ($contactMap as $map) {
            $stmtDetails = $pdo->prepare("SELECT contact.identifier, contact_postalInfo.name, contact_postalInfo.org, contact_postalInfo.street1, contact_postalInfo.street2, contact_postalInfo.street3, contact_postalInfo.city, contact_postalInfo.sp, contact_postalInfo.pc, contact_postalInfo.cc, contact.voice, contact.voice_x, contact.fax, contact.fax_x, contact.email,contact.disclose_voice,contact.disclose_fax,contact.disclose_email,contact_postalInfo.type,contact_postalInfo.disclose_name_int,contact_postalInfo.disclose_name_loc,contact_postalInfo.disclose_org_int,contact_postalInfo.disclose_org_loc,contact_postalInfo.disclose_addr_int,contact_postalInfo.disclose_addr_loc FROM contact, contact_postalInfo WHERE contact.id = :contact_id AND contact_postalInfo.contact_id = contact.id");
            $stmtDetails->bindParam(':contact_id', $map['contact_id'], PDO::PARAM_INT);
            $stmtDetails->execute();
    
            $contactDetails = $stmtDetails->fetch(PDO::FETCH_ASSOC);
    
            switch ($map['type']) {
                case 'admin':
                    $adminDetails[] = $contactDetails;
                    break;
                case 'tech':
                    $techDetails[] = $contactDetails;
                    break;
                case 'billing':
                    $billingDetails[] = $contactDetails;
                    break;
            }
        }

        // Query 6: Get nameservers
        $stmt6 = $pdo->prepare("
            SELECT host.name, host.id as host_id 
            FROM domain_host_map, host 
            WHERE domain_host_map.domain_id = :domain_id 
            AND domain_host_map.host_id = host.id
        ");
        $stmt6->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmt6->execute();
        $nameservers = $stmt6->fetchAll(PDO::FETCH_ASSOC);
        
        // Define the basic events
        $events = [
            ['eventAction' => 'registration', 'eventDate' => $domainDetails['crdate']],
            ['eventAction' => 'expiration', 'eventDate' => $domainDetails['exdate']],
            ['eventAction' => 'last update of RDAP database', 'eventDate' => (new DateTime())->format('Y-m-d\TH:i:s.v\Z')],
        ];

        // Check if domain last update is set and not empty
        if (isset($domainDetails['lastupdate']) && !empty($domainDetails['lastupdate'])) {
            $updateDateTime = new DateTime($domainDetails['lastupdate']);
            $events[] = [
                'eventAction' => 'last changed',
                'eventDate' => $updateDateTime->format('Y-m-d\TH:i:s.v\Z')
            ];
        }

        // Check if domain transfer date is set and not empty
        if (isset($domainDetails['trdate']) && !empty($domainDetails['trdate'])) {
            $transferDateTime = new DateTime($domainDetails['trdate']);
            $events[] = [
                'eventAction' => 'transfer',
                'eventDate' => $transferDateTime->format('Y-m-d\TH:i:s.v\Z')
            ];
        }
        
        $abuseContactName = ($registrarAbuseDetails) ? $registrarAbuseDetails['first_name'] . ' ' . $registrarAbuseDetails['last_name'] : '';
        
        $stmt = $pdo->query("SELECT value FROM settings WHERE name = 'handle'");
        $roid = $stmt->fetchColumn();

        // Construct the RDAP response in JSON format
        $rdapResponse = [
            'rdapConformance' => [
                'rdap_level_0',
                'icann_rdap_response_profile_0',
                'icann_rdap_response_profile_1',
                'icann_rdap_technical_implementation_guide_0',
                'icann_rdap_technical_implementation_guide_1',
            ],
            'domainSearchResults' => [
            [
            'objectClassName' => 'domain',
            'entities' => array_merge(
                [
                [
                    'objectClassName' => 'entity',
                    'entities' => [
                    [
                        'objectClassName' => 'entity',
                        'roles' => ["abuse"],
                        "status" => ["active"],
                        "vcardArray" => [
                            "vcard",
                            [
                                ['version', new stdClass(), 'text', '4.0'],
                                ["fn", new stdClass(), "text", $abuseContactName],
                                ["tel", ["type" => ["voice"]], "uri", "tel:" . $registrarDetails['abuse_phone']],
                                ["email", new stdClass(), "text", $registrarDetails['abuse_email']]
                            ]
                        ],
                    ],
                    ],
                    "handle" => (string)($registrarDetails['iana_id'] ?: 'R' . $registrarDetails['id'] . '-' . $roid . ''),
                    "links" => [
                        [
                            "href" => $c['rdap_url'] . "/entity/" . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                            "value" => $c['rdap_url'] . "/entity/" . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                            "rel" => "self",
                            "type" => "application/rdap+json"
                        ]
                    ],
                    "publicIds" => [
                        [
                            "identifier" => (string)$registrarDetails['iana_id'],
                            "type" => "IANA Registrar ID"
                        ]
                    ],
                    "remarks" => [
                        [
                            "description" => ["This record contains only a summary. For detailed information, please submit a query specifically for this object."],
                            "title" => "Incomplete Data",
                            "type" => "object truncated due to authorization"
                        ]
                    ],
                    "roles" => ["registrar"],
                    "vcardArray" => [
                        "vcard",
                        [
                            ['version', new stdClass(), 'text', '4.0'],
                            ["fn", new stdClass(), "text", $registrarDetails['name']]
                        ]
                    ],
                    ],
                ],
                [
                    mapContactToVCard($registrantDetails, 'registrant', $roid)
                ],
                array_map(function ($contact) use ($roid) {
                    return mapContactToVCard($contact, 'admin', $roid);
                }, $adminDetails),
                array_map(function ($contact) use ($roid) {
                    return mapContactToVCard($contact, 'tech', $roid);
                }, $techDetails),
                array_map(function ($contact) use ($roid) {
                    return mapContactToVCard($contact, 'billing', $roid);
                }, $billingDetails)
            ),
            'events' => $events,
            'handle' => ($config['limited_rdap'] ?? false) ? '' : 'D' . $domainDetails['id'] . '-' . $roid,
            'ldhName' => $domain,
            'links' => [
                [
                    'href' => $c['rdap_url'] . '/domain/' . $domain,
                    'rel' => 'self',
                    'type' => 'application/rdap+json',
                ],
                [
                    'href' => $registrarDetails['rdap_server'] . 'domain/' . $domain,
                    'rel' => 'related',
                    'type' => 'application/rdap+json',
                ]
            ],
            'nameservers' => array_map(function ($nameserverDetails) use ($c, $roid) {
                return [
                    'objectClassName' => 'nameserver',
                    'handle' => 'H' . $nameserverDetails['host_id'] . '-' . $roid . '',
                    'ldhName' => $nameserverDetails['name'],
                    'links' => [
                        [
                            'href' => $c['rdap_url'] . '/nameserver/' . $nameserverDetails['name'],
                            'rel' => 'self',
                            'type' => 'application/rdap+json',
                        ],
                    ],
                    'remarks' => [
                        [
                            "description" => [
                                "This record contains only a brief summary. To access the full details, please initiate a specific query targeting this entity."
                            ],
                            "title" => "Incomplete Data",
                            "type" => "object truncated due to authorization"
                        ],
                    ],
                ];
            }, $nameservers),
            "secureDNS" => [
                "delegationSigned" => $isDelegationSigned,
                "zoneSigned" => $isZoneSigned
            ],
            'status' => $statuses,
            ],
            ],
            "notices" => [
                [
                    "title" => "Terms of Service",
                    "description" => [
                        "Access to " . strtoupper($tld) . " RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                        "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                        "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                        "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                        "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                        "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                    ],
                    "links" => [
                        [
                            "href" => $c['rdap_url'] . "/help",
                            "value" => $c['rdap_url'] . "/help",
                            "rel" => "terms-of-service",
                            "type" => "application/rdap+json"
                        ],
                        [
                            "href" => $c['registry_url'],
                            "value" => $c['registry_url'],
                            "rel" => "alternate",
                            "type" => "text/html"
                        ],
                    ]
                ],
                [
                "description" => [
                    "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                ]
                ],
                [
                "description" => [
                    "For more information on domain status codes, please visit https://icann.org/epp"
                ],
                "links" => [
                    [
                        "href" => "https://icann.org/epp",
                        "value" => "https://icann.org/epp",
                        "rel" => "glossary",
                        "type" => "text/html"
                    ]
                ],
                    "title" => "Status Codes"
                ],
                [
                    "description" => [
                        "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                    ],
                    "links" => [
                    [
                        "href" => "https://icann.org/wicf",
                        "value" => "https://icann.org/wicf",
                        "rel" => "help",
                        "type" => "text/html"
                    ]
                    ],
                    "title" => "RDDS Inaccuracy Complaint Form"
                ],
            ]
        ];

        // Send the RDAP response
        try {
            $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
            $settingName = 'web-whois-queries';
            $stmt->bindParam(':name', $settingName);
            $stmt->execute();
        } catch (PDOException $e) {
            $log->error('DB Connection failed: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['Database error:' => $e->getMessage()]));
            return;
        } catch (Throwable $e) {
            $log->error('Error: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['General error:' => $e->getMessage()]));
            return;
        }
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(200);
        $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
    } catch (PDOException $e) {
        $log->error('DB Connection failed: ' . $e->getMessage());
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(503);
        $response->end(json_encode(['error' => 'Error connecting to the RDAP database']));
        return;
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/rdap+json');
        $response->end(json_encode(['General error:' => $e->getMessage()]));
        return;
    }
}

function handleNameserverSearchQuery($request, $response, $pdo, $searchPattern, $c, $log, $searchType) {
    // Extract and validate the nameserver handle from the request
    $ns = urldecode($searchPattern);
    $ns = trim($ns);

    // Perform the RDAP lookup
    try {
        // Query 1: Get nameserver details
        switch ($searchType) {
            case 'name':
                // Search by nameserver
                
                // Empty nameserver check
                if (!$ns) {
                    $response->header('Content-Type', 'application/rdap+json');
                    $response->status(400); // Bad Request
                    $response->end(json_encode(['error' => 'Please enter a nameserver']));
                    return;
                }
                
                // Check nameserver length
                $labels = explode('.', $ns);
                $validLengths = array_map(function ($label) {
                    return strlen($label) <= 63;
                }, $labels);

                if (strlen($ns) > 253 || in_array(false, $validLengths, true)) {
                    // The nameserver format is invalid due to length
                    $response->header('Content-Type', 'application/rdap+json');
                    $response->status(400); // Bad Request
                    $response->end(json_encode(['error' => 'Nameserver is too long']));
                    return;
                }

                // Convert to Punycode if the host is not in ASCII
                if (!mb_detect_encoding($ns, 'ASCII', true)) {
                    $convertedDomain = idn_to_ascii($ns, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    if ($convertedDomain === false) {
                        $response->header('Content-Type', 'application/rdap+json');
                        $response->status(400); // Bad Request
                        $response->end(json_encode(['error' => 'Host conversion to Punycode failed']));
                        return;
                    } else {
                        $ns = $convertedDomain;
                    }
                }

                // Validate nameserver
                if (!isValidHostname($ns)) {
                    $response->header('Content-Type', 'application/rdap+json');
                    $response->status(400); // Bad Request
                    $response->end(json_encode(['error' => 'Nameserver format is invalid. Expected a fully qualified domain name (FQDN), punycode supported (e.g., ns1.example.com)']));
                    return;
                }

                // Extract TLD from the domain
                $parts = explode('.', $ns);
                $tld = "." . end($parts);
                
                $stmt1 = $pdo->prepare("SELECT id, name, clid FROM host WHERE name = :ns");
                $stmt1->bindParam(':ns', $ns, PDO::PARAM_STR);
                $stmt1->execute();
                $hostDetails = $stmt1->fetch(PDO::FETCH_ASSOC);
                $hostS = true;
                $ipS = false;
                break;
            case 'ip':
                // Search by IP
                
                // Empty IP check
                if (!$ns) {
                    $response->header('Content-Type', 'application/rdap+json');
                    $response->status(400); // Bad Request
                    $response->end(json_encode(['error' => 'Please enter an IP address']));
                    return;
                }

                // Validate IP address format
                if (!filter_var($ns, FILTER_VALIDATE_IP)) {
                    $response->header('Content-Type', 'application/rdap+json');
                    $response->status(400); // Bad Request
                    $response->end(json_encode(['error' => 'Invalid IP address format']));
                    return;
                }
                
                $tld = "";
                
                $stmt1 = $pdo->prepare("
                    SELECT h.id, h.name, h.clid 
                    FROM host h
                    INNER JOIN host_addr ha ON h.id = ha.host_id 
                    WHERE ha.addr = :ip
                ");
                $stmt1->bindParam(':ip', $ns, PDO::PARAM_STR);
                $stmt1->execute();
                $hostDetails = $stmt1->fetchAll(PDO::FETCH_ASSOC);
                $ipS = true;
                $hostS = false;
                break;
        }

        // Check if the nameserver exists
        if (!$hostDetails) {
            // Nameserver not found, respond with a 404 error
            try {
                $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                $settingName = 'web-whois-queries';
                $stmt->bindParam(':name', $settingName);
                $stmt->execute();
            } catch (PDOException $e) {
                $log->error('DB Connection failed: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['Database error:' => $e->getMessage()]));
                return;
            } catch (Throwable $e) {
                $log->error('Error: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['General error:' => $e->getMessage()]));
                return;
            }
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode([
                'rdapConformance' => [
                    'rdap_level_0',
                    'icann_rdap_response_profile_0',
                    'icann_rdap_response_profile_1',
                    'icann_rdap_technical_implementation_guide_0',
                    'icann_rdap_technical_implementation_guide_1',
                ],
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => ['The requested nameserver was not found in the RDAP database.'],
                "notices" => [
                    [
                        "title" => "Terms of Service",
                        "description" => [
                            "Access to " . strtoupper($tld) . " RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                        ],
                        "links" => [
                            [
                                "href" => $c['rdap_url'] . "/help",
                                "value" => $c['rdap_url'] . "/nameserver/" . $ns,
                                "rel" => "terms-of-service",
                                "type" => "application/rdap+json"
                            ],
                            [
                                "href" => $c['registry_url'],
                                "value" => $c['registry_url'],
                                "rel" => "alternate",
                                "type" => "text/html"
                            ],
                        ]
                    ],
                    [
                    "description" => [
                        "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                    ]
                    ],
                    [
                    "description" => [
                        "For more information on domain status codes, please visit https://icann.org/epp"
                    ],
                    "links" => [
                        [
                            "href" => "https://icann.org/epp",
                            "value" => "https://icann.org/epp",
                            "rel" => "glossary",
                            "type" => "text/html"
                        ]
                    ],
                        "title" => "Status Codes"
                    ],
                    [
                        "description" => [
                            "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                        ],
                        "links" => [
                        [
                            "href" => "https://icann.org/wicf",
                            "value" => "https://icann.org/wicf",
                            "rel" => "help",
                            "type" => "text/html"
                        ]
                        ],
                        "title" => "RDDS Inaccuracy Complaint Form"
                    ],
                ]
            ], JSON_UNESCAPED_SLASHES));
            // Close the connection
            $pdo = null;
            return;
        }

        $stmt = $pdo->query("SELECT value FROM settings WHERE name = 'handle'");
        $roid = $stmt->fetchColumn();

        if ($ipS) {
            $rdapResult = []; 
            foreach ($hostDetails as $individualHostDetail) {
                // Query 2: Get status details
                $stmt2 = $pdo->prepare("SELECT status FROM host_status WHERE host_id = :host_id");
                $stmt2->bindParam(':host_id', $individualHostDetail['id'], PDO::PARAM_INT);
                $stmt2->execute();
                $statuses = $stmt2->fetchAll(PDO::FETCH_COLUMN, 0);
                
                // Query 2a: Get associated status details
                $stmt2a = $pdo->prepare("SELECT domain_id FROM domain_host_map WHERE host_id = :host_id");
                $stmt2a->bindParam(':host_id', $individualHostDetail['id'], PDO::PARAM_INT);
                $stmt2a->execute();
                $associated = $stmt2a->fetchAll(PDO::FETCH_COLUMN, 0);
                
                // Query 3: Get IP details
                $stmt3 = $pdo->prepare("SELECT addr,ip FROM host_addr WHERE host_id = :host_id");
                $stmt3->bindParam(':host_id', $individualHostDetail['id'], PDO::PARAM_INT);
                $stmt3->execute();
                $ipDetails = $stmt3->fetchAll(PDO::FETCH_COLUMN, 0);

                // Define the basic events
                $events = [
                    ['eventAction' => 'last update of RDAP database', 'eventDate' => (new DateTime())->format('Y-m-d\TH:i:s.v\Z')],
                ];

                // Build the 'ipAddresses' structure
                $ipAddresses = array_reduce($ipDetails, function ($carry, $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $carry['v4'][] = $ip;
                    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $carry['v6'][] = $ip;
                    }
                    return $carry;
                }, ['v4' => [], 'v6' => []]);  // Initialize with 'v4' and 'v6' keys

                // If there are associated domains, add 'associated' to the statuses
                if (!empty($associated)) {
                    $statuses[] = 'associated';
                }
                
                // Build the RDAP response for the current host
                $rdapResult[] = [
                    'objectClassName' => 'nameserver',
                    'handle' => 'H' . $individualHostDetail['id'] . '-' . $roid,
                    ...(
                        empty($ipAddresses['v4']) && empty($ipAddresses['v6'])
                        ? []
                        : ['ipAddresses' => $ipAddresses]
                    ),
                    'events' => $events,
                    'ldhName' => $individualHostDetail['name'],
                    'links' => [
                        [
                            'href' => $c['rdap_url'] . '/nameserver/' . $individualHostDetail['name'],
                            'rel' => 'self',
                            'type' => 'application/rdap+json',
                        ]
                    ],
                    'status' => $statuses,
                    'remarks' => [
                        [
                            'description' => ['This record contains only a summary. For detailed information, please submit a query specifically for this object.'],
                            'title' => 'Incomplete Data',
                            'type' => 'object truncated due to authorization'
                        ]
                    ],
                ];
            }
            
            // Construct the RDAP response in JSON format
            $rdapResponse = [
                'rdapConformance' => [
                    'rdap_level_0',
                    'icann_rdap_response_profile_0',
                    'icann_rdap_response_profile_1',
                    'icann_rdap_technical_implementation_guide_0',
                    'icann_rdap_technical_implementation_guide_1',
                ],
                'nameserverSearchResults' => $rdapResult,
                "notices" => [
                    [
                        "title" => "Terms of Service",
                        "description" => [
                            "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                        ],
                        "links" => [
                            [
                                "href" => $c['rdap_url'] . "/help",
                                "value" => $c['rdap_url'] . "/nameserver/" . $ns,
                                "rel" => "terms-of-service",
                                "type" => "application/rdap+json"
                            ],
                            [
                                "href" => $c['registry_url'],
                                "value" => $c['registry_url'],
                                "rel" => "alternate",
                                "type" => "text/html"
                            ],
                        ]
                    ],
                    [
                    "description" => [
                        "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                    ]
                    ],
                    [
                    "description" => [
                        "For more information on domain status codes, please visit https://icann.org/epp"
                    ],
                    "links" => [
                        [
                            "href" => "https://icann.org/epp",
                            "value" => "https://icann.org/epp",
                            "rel" => "glossary",
                            "type" => "text/html"
                        ]
                    ],
                        "title" => "Status Codes"
                    ],
                    [
                        "description" => [
                            "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                        ],
                        "links" => [
                        [
                            "href" => "https://icann.org/wicf",
                            "value" => "https://icann.org/wicf",
                            "rel" => "help",
                            "type" => "text/html"
                        ]
                        ],
                        "title" => "RDDS Inaccuracy Complaint Form"
                    ],
                ]
            ];
        } elseif ($hostS) {
            // Query 2: Get status details
            $stmt2 = $pdo->prepare("SELECT status FROM host_status WHERE host_id = :host_id");
            $stmt2->bindParam(':host_id', $hostDetails['id'], PDO::PARAM_INT);
            $stmt2->execute();
            $statuses = $stmt2->fetchAll(PDO::FETCH_COLUMN, 0);
            
            // Query 2a: Get associated status details
            $stmt2a = $pdo->prepare("SELECT domain_id FROM domain_host_map WHERE host_id = :host_id");
            $stmt2a->bindParam(':host_id', $hostDetails['id'], PDO::PARAM_INT);
            $stmt2a->execute();
            $associated = $stmt2a->fetchAll(PDO::FETCH_COLUMN, 0);
            
            // Query 3: Get IP details
            $stmt3 = $pdo->prepare("SELECT addr,ip FROM host_addr WHERE host_id = :host_id");
            $stmt3->bindParam(':host_id', $hostDetails['id'], PDO::PARAM_INT);
            $stmt3->execute();
            $ipDetails = $stmt3->fetchAll(PDO::FETCH_COLUMN, 0);

            // Query 4: Get registrar details
            $stmt4 = $pdo->prepare("SELECT id,name,iana_id,whois_server,rdap_server,url,abuse_email,abuse_phone FROM registrar WHERE id = :clid");
            $stmt4->bindParam(':clid', $hostDetails['clid'], PDO::PARAM_INT);
            $stmt4->execute();
            $registrarDetails = $stmt4->fetch(PDO::FETCH_ASSOC);
            
            // Query 5: Get registrar abuse details
            $stmt5 = $pdo->prepare("SELECT first_name,last_name FROM registrar_contact WHERE registrar_id = :clid AND type = 'abuse'");
            $stmt5->bindParam(':clid', $hostDetails['clid'], PDO::PARAM_INT);
            $stmt5->execute();
            $registrarAbuseDetails = $stmt5->fetch(PDO::FETCH_ASSOC);
            
            // Define the basic events
            $events = [
                ['eventAction' => 'last update of RDAP database', 'eventDate' => (new DateTime())->format('Y-m-d\TH:i:s.v\Z')],
            ];

            $abuseContactName = ($registrarAbuseDetails) ? $registrarAbuseDetails['first_name'] . ' ' . $registrarAbuseDetails['last_name'] : '';

            // Build the 'ipAddresses' structure
            $ipAddresses = array_reduce($ipDetails, function ($carry, $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $carry['v4'][] = $ip;
                } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $carry['v6'][] = $ip;
                }
                return $carry;
            }, ['v4' => [], 'v6' => []]);  // Initialize with 'v4' and 'v6' keys

            // If there are associated domains, add 'associated' to the statuses
            if (!empty($associated)) {
                $statuses[] = 'associated';
            }
            
            $stmt = $pdo->query("SELECT value FROM settings WHERE name = 'handle'");
            $roid = $stmt->fetchColumn();

            // Construct the RDAP response in JSON format
            $rdapResponse = [
                'rdapConformance' => [
                    'rdap_level_0',
                    'icann_rdap_response_profile_0',
                    'icann_rdap_response_profile_1',
                    'icann_rdap_technical_implementation_guide_0',
                    'icann_rdap_technical_implementation_guide_1',
                ],
                'nameserverSearchResults' => [
                [
                'objectClassName' => 'nameserver',
                'entities' => array_merge(
                    [
                    [
                        'objectClassName' => 'entity',
                        'entities' => [
                        [
                            'objectClassName' => 'entity',
                            'roles' => ["abuse"],
                            "status" => ["active"],
                            "vcardArray" => [
                                "vcard",
                                [
                                    ['version', new stdClass(), 'text', '4.0'],
                                    ["fn", new stdClass(), "text", $abuseContactName],
                                    ["tel", ["type" => ["voice"]], "uri", "tel:" . $registrarDetails['abuse_phone']],
                                    ["email", new stdClass(), "text", $registrarDetails['abuse_email']]
                                ]
                            ],
                        ],
                        ],
                        "handle" => (string)($registrarDetails['iana_id'] ?: 'R' . $registrarDetails['id'] . '-' . $roid . ''),
                        "links" => [
                            [
                                "href" => $c['rdap_url'] . "/entity/" . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                                "value" => $c['rdap_url'] . "/entity/" . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                                "rel" => "self",
                                "type" => "application/rdap+json"
                            ]
                        ],
                        "publicIds" => [
                            [
                                "identifier" => (string)$registrarDetails['iana_id'],
                                "type" => "IANA Registrar ID"
                            ]
                        ],
                        "remarks" => [
                            [
                                "description" => ["This record contains only a summary. For detailed information, please submit a query specifically for this object."],
                                "title" => "Incomplete Data",
                                "type" => "object truncated"
                            ]
                        ],
                        "roles" => ["registrar"],
                        "vcardArray" => [
                            "vcard",
                            [
                                ['version', new stdClass(), 'text', '4.0'],
                                ["fn", new stdClass(), "text", $registrarDetails['name']]
                            ]
                        ],
                        ],
                    ],
                ),
                'handle' => 'H' . $hostDetails['id'] . '-' . $roid . '',
                ...(
                    empty($ipAddresses['v4']) && empty($ipAddresses['v6'])
                    ? []
                    : ['ipAddresses' => $ipAddresses]
                ),
                'events' => $events,
                'ldhName' => $hostDetails['name'],
                'links' => [
                    [
                        'href' => $c['rdap_url'] . '/nameserver/' . $hostDetails['name'],
                        'rel' => 'self',
                        'type' => 'application/rdap+json',
                    ]
                ],
                'status' => $statuses,
                ],
                ],
                "notices" => [
                    [
                        "title" => "Terms of Service",
                        "description" => [
                            "Access to " . strtoupper($tld) . " RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                        ],
                        "links" => [
                            [
                                "href" => $c['rdap_url'] . "/help",
                                "value" => $c['rdap_url'] . "/nameserver/" . $ns,
                                "rel" => "terms-of-service",
                                "type" => "application/rdap+json"
                            ],
                            [
                                "href" => $c['registry_url'],
                                "value" => $c['registry_url'],
                                "rel" => "alternate",
                                "type" => "text/html"
                            ],
                        ]
                    ],
                    [
                    "description" => [
                        "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                    ]
                    ],
                    [
                    "description" => [
                        "For more information on domain status codes, please visit https://icann.org/epp"
                    ],
                    "links" => [
                        [
                            "href" => "https://icann.org/epp",
                            "value" => "https://icann.org/epp",
                            "rel" => "glossary",
                            "type" => "text/html"
                        ]
                    ],
                        "title" => "Status Codes"
                    ],
                    [
                        "description" => [
                            "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                        ],
                        "links" => [
                        [
                            "href" => "https://icann.org/wicf",
                            "value" => "https://icann.org/wicf",
                            "rel" => "help",
                            "type" => "text/html"
                        ]
                        ],
                        "title" => "RDDS Inaccuracy Complaint Form"
                    ],
                ]
            ];
        }

        // Send the RDAP response
        try {
            $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
            $settingName = 'web-whois-queries';
            $stmt->bindParam(':name', $settingName);
            $stmt->execute();
        } catch (PDOException $e) {
            $log->error('DB Connection failed: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['Database error:' => $e->getMessage()]));
            return;
        } catch (Throwable $e) {
            $log->error('Error: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['General error:' => $e->getMessage()]));
            return;
        }
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(200);
        $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
    } catch (PDOException $e) {
        $log->error('DB Connection failed: ' . $e->getMessage());
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(503);
        $response->end(json_encode(['error' => 'Error connecting to the RDAP database']));
        return;
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/rdap+json');
        $response->end(json_encode(['General error:' => $e->getMessage()]));
        return;
    }
}

function handleEntitySearchQuery($request, $response, $pdo, $searchPattern, $c, $log, $searchType) {
    // Extract and validate the entity handle from the request
    $entity = trim($searchPattern);

    // Empty entity check
    if (!$entity) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Please enter an entity']));
        return;
    }
    
    // Check for prohibited patterns in RDAP entity handle
    if (!preg_match('/^[A-Za-z0-9-]+$/', $entity)) {
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Entity handle invalid format']));
        return;
    }

    // Perform the RDAP lookup
    try {
        switch ($searchType) {
            case 'fn':
                // Invalidate the search when searching by first name
                $entity = null; // Setting to null or an unlikely value to match
                break;
            case 'handle':
                // Handle search by handle
                // Assuming $entity is set somewhere above
                break;
        }
        
        if (!is_numeric($entity) && !preg_match('/^[A-Za-z0-9-]+$/', $entity)) {
            // Return a 404 response if $entity is not a purely numeric string
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode([
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => 'The requested entity was not found in the RDAP database.',
            ]));
            // Close the connection
            $pdo = null;
            return;
        }

        // Query 1: Get registrar details
        $stmt1 = $pdo->prepare("SELECT id,name,clid,iana_id,whois_server,rdap_server,url,email,abuse_email,abuse_phone FROM registrar WHERE iana_id = :iana_id");
        $stmt1->bindParam(':iana_id', $entity, PDO::PARAM_INT);
        $stmt1->execute();
        $registrarDetails = $stmt1->fetch(PDO::FETCH_ASSOC);
        
        // Check if the first query returned a result
        if (!$registrarDetails) {
            // Query 2: Get registrar details by id as a fallback for ccTLDs without iana_id
            $stmt2 = $pdo->prepare("SELECT id, name, clid, iana_id, whois_server, rdap_server, url, email, abuse_email, abuse_phone FROM registrar WHERE id = :id");
            $stmt2->bindParam(':id', $entity, PDO::PARAM_INT);
            $stmt2->execute();
            $registrarDetails = $stmt2->fetch(PDO::FETCH_ASSOC);
        }
        
        // Check if the entity exists
        if (!$registrarDetails) {
            // Entity not found, respond with a 404 error
            try {
                $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
                $settingName = 'web-whois-queries';
                $stmt->bindParam(':name', $settingName);
                $stmt->execute();
            } catch (PDOException $e) {
                $log->error('DB Connection failed: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['Database error:' => $e->getMessage()]));
                return;
            } catch (Throwable $e) {
                $log->error('Error: ' . $e->getMessage());
                $response->status(500);
                $response->header('Content-Type', 'application/rdap+json');
                $response->end(json_encode(['General error:' => $e->getMessage()]));
                return;
            }
            $response->header('Content-Type', 'application/rdap+json');
            $response->status(404);
            $response->end(json_encode([
                'rdapConformance' => [
                    'rdap_level_0',
                    'icann_rdap_response_profile_0',
                    'icann_rdap_response_profile_1',
                    'icann_rdap_technical_implementation_guide_0',
                    'icann_rdap_technical_implementation_guide_1',
                ],
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => ['The requested entity was not found in the RDAP database.'],
                "notices" => [
                    [
                        "title" => "Terms of Service",
                        "description" => [
                            "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                        ],
                        "links" => [
                            [
                                "href" => $c['rdap_url'] . "/help",
                                "value" => $c['rdap_url'] . "/entity/" . $entity,
                                "rel" => "terms-of-service",
                                "type" => "application/rdap+json"
                            ],
                            [
                                "href" => $c['registry_url'],
                                "value" => $c['registry_url'],
                                "rel" => "alternate",
                                "type" => "text/html"
                            ],
                        ]
                    ],
                    [
                    "description" => [
                        "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                    ]
                    ],
                    [
                    "description" => [
                        "For more information on domain status codes, please visit https://icann.org/epp"
                    ],
                    "links" => [
                        [
                            "href" => "https://icann.org/epp",
                            "value" => "https://icann.org/epp",
                            "rel" => "glossary",
                            "type" => "text/html"
                        ]
                    ],
                        "title" => "Status Codes"
                    ],
                    [
                        "description" => [
                            "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                        ],
                        "links" => [
                        [
                            "href" => "https://icann.org/wicf",
                            "value" => "https://icann.org/wicf",
                            "rel" => "help",
                            "type" => "text/html"
                        ]
                        ],
                        "title" => "RDDS Inaccuracy Complaint Form"
                    ],
                ]
            ], JSON_UNESCAPED_SLASHES));
            // Close the connection
            $pdo = null;
            return;
        }

        // Query 2: Fetch all contact types for a registrar
        $stmt2 = $pdo->prepare("SELECT type, first_name, last_name, voice, email FROM registrar_contact WHERE registrar_id = :clid");
        $stmt2->bindParam(':clid', $registrarDetails['id'], PDO::PARAM_INT);
        $stmt2->execute();
        $contacts = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // Query 3: Get registrar abuse details
        $stmt3 = $pdo->prepare("SELECT org,street1,street2,city,sp,pc,cc FROM registrar_contact WHERE registrar_id = :clid AND type = 'owner'");
        $stmt3->bindParam(':clid', $registrarDetails['id'], PDO::PARAM_INT);
        $stmt3->execute();
        $registrarContact = $stmt3->fetch(PDO::FETCH_ASSOC);

        // Define the basic events
        $events = [
            ['eventAction' => 'last update of RDAP database', 'eventDate' => (new DateTime())->format('Y-m-d\TH:i:s.v\Z')],
        ];

        // Initialize an array to hold entity blocks
        $entityBlocks = [];
        // Define an array of allowed contact types
        $allowedTypes = ['owner', 'billing', 'abuse', 'tech'];

        foreach ($contacts as $contact) {
            // Check if the contact type is one of the allowed types
            if (in_array($contact['type'], $allowedTypes)) {
                // Build the full name
                $fullName = $contact['first_name'] . ' ' . $contact['last_name'];

                // Create an entity block for each allowed contact type
                $entityBlock = [
                    'objectClassName' => 'entity',
                    'roles' => [
                        match ($contact['type']) {
                            'owner' => 'administrative',
                            'tech' => 'technical',
                            default => $contact['type']
                        }
                    ],
                    "status" => ["active"],
                    "vcardArray" => [
                        "vcard",
                        [
                            ['version', new stdClass(), 'text', '4.0'],
                            ["fn", new stdClass(), "text", $fullName],
                            ["tel", ["type" => ["voice"]], $contact['voice'] ? "uri" : "text", $contact['voice'] ? "tel:" . $contact['voice'] : ""],
                            ["email", new stdClass(), "text", $contact['email']]
                        ]
                    ],
                ];

                // Add the entity block to the array
                $entityBlocks[] = $entityBlock;
            }
        }

        $stmt = $pdo->query("SELECT value FROM settings WHERE name = 'handle'");
        $roid = $stmt->fetchColumn();

        // Construct the RDAP response in JSON format
        $rdapResponse = [
            'rdapConformance' => [
                'rdap_level_0',
                'icann_rdap_response_profile_0',
                'icann_rdap_response_profile_1',
                'icann_rdap_technical_implementation_guide_0',
                'icann_rdap_technical_implementation_guide_1',
            ],
            'entitySearchResults' => [
            [
            'objectClassName' => 'entity',
            'entities' => $entityBlocks,
            "handle" => (string)($registrarDetails['iana_id'] ?: 'R' . $registrarDetails['id'] . '-' . $roid . ''),
            'events' => $events,
            'links' => [
                [
                    'href' => $c['rdap_url'] . '/entity/' . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                    'value' => $c['rdap_url'] . '/entity/' . ($registrarDetails['iana_id'] ?: $registrarDetails['id']),
                    'rel' => 'self',
                    'type' => 'application/rdap+json',
                ]
            ],
            "publicIds" => [
                [
                    "identifier" => (string)$registrarDetails['iana_id'],
                    "type" => "IANA Registrar ID"
                ]
            ],
            "roles" => ["registrar"],
            "status" => ["active"],
            "lang" => "en",
            "vcardArray" => [
                "vcard",
                [
                    ['version', new stdClass(), 'text', '4.0'],
                    ['fn', new stdClass(), 'text', $registrarContact['org']],
                    ['adr', new stdClass(), 'text', [
                        '',         // PO Box
                        '',         // Extended address
                        $registrarContact['street1'], // Street address
                        $registrarContact['city'],    // City
                        $registrarContact['sp'],      // Region
                        $registrarContact['pc'],      // Postal code
                        strtoupper($registrarContact['cc']) // Country
                    ]],
                    ["tel", ["type" => ["voice"]], $contact['voice'] ? "uri" : "text", $contact['voice'] ? "tel:" . $contact['voice'] : ""],
                    ['email', new stdClass(), 'text', $registrarDetails['email']],
                ]
            ],
            ],
            ],
            "notices" => [
                [
                    "title" => "Terms of Service",
                    "description" => [
                        "Access to " . strtoupper($tld) . " RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
                        "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
                        "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
                        "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
                        "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
                        "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
                    ],
                    "links" => [
                        [
                            "href" => $c['rdap_url'] . "/help",
                            "value" => $c['rdap_url'] . "/entity/" . $entity,
                            "rel" => "terms-of-service",
                            "type" => "application/rdap+json"
                        ],
                        [
                            "href" => $c['registry_url'],
                            "value" => $c['registry_url'],
                            "rel" => "alternate",
                            "type" => "text/html"
                        ],
                    ]
                ],
                [
                "description" => [
                    "This response conforms to the RDAP Operational Profile for gTLD Registries and Registrars version 1.0"
                ]
                ],
                [
                "description" => [
                    "For more information on domain status codes, please visit https://icann.org/epp"
                ],
                "links" => [
                    [
                        "href" => "https://icann.org/epp",
                        "value" => "https://icann.org/epp",
                        "rel" => "glossary",
                        "type" => "text/html"
                    ]
                ],
                    "title" => "Status Codes"
                ],
                [
                    "description" => [
                        "URL of the ICANN RDDS Inaccuracy Complaint Form: https://icann.org/wicf"
                    ],
                    "links" => [
                    [
                        "href" => "https://icann.org/wicf",
                        "value" => "https://icann.org/wicf",
                        "rel" => "help",
                        "type" => "text/html"
                    ]
                    ],
                    "title" => "RDDS Inaccuracy Complaint Form"
                ],
            ]
        ];

        // Send the RDAP response
        try {
            $stmt = $pdo->prepare("UPDATE settings SET value = value + 1 WHERE name = :name");
            $settingName = 'web-whois-queries';
            $stmt->bindParam(':name', $settingName);
            $stmt->execute();
        } catch (PDOException $e) {
            $log->error('DB Connection failed: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['Database error:' => $e->getMessage()]));
            return;
        } catch (Throwable $e) {
            $log->error('Error: ' . $e->getMessage());
            $response->status(500);
            $response->header('Content-Type', 'application/rdap+json');
            $response->end(json_encode(['General error:' => $e->getMessage()]));
            return;
        }
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(200);
        $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
    } catch (PDOException $e) {
        $log->error('DB Connection failed: ' . $e->getMessage());
        $response->header('Content-Type', 'application/rdap+json');
        $response->status(503);
        $response->end(json_encode(['error' => 'Error connecting to the RDAP database']));
        return;
    } catch (Throwable $e) {
        $log->error('Error: ' . $e->getMessage());
        $response->status(500);
        $response->header('Content-Type', 'application/rdap+json');
        $response->end(json_encode(['General error:' => $e->getMessage()]));
        return;
    }
}

function handleHelpQuery($request, $response, $pdo, $c) {
    // Set the RDAP conformance levels
    $rdapConformance = [
        'rdap_level_0',
        'icann_rdap_response_profile_0',
        'icann_rdap_response_profile_1',
        'icann_rdap_technical_implementation_guide_0',
        'icann_rdap_technical_implementation_guide_1',
    ];

    // Set the descriptions and links for the help section
    $helpNotices = [
        "description" => [
            "domain/XXXX",
            "nameserver/XXXX",
            "entity/XXXX",
            "domains?name=XXXX",
            "domains?nsLdhName=XXXX",
            "domains?nsIp=XXXX",
            "nameservers?name=XXXX",
            "nameservers?ip=XXXX",
            "entities?fn=XXXX",
            "entities?handle=XXXX",
            "help/XXXX"
        ],
        'links' => [
            [
                'value' => $c['rdap_url'] . '/help',
                'rel' => 'self',
                'href' => $c['rdap_url'] . '/help',
                'type' => 'application/rdap+json',
            ],
            [
                'value' => 'https://namingo.org',
                'rel' => 'related',
                'href' => 'https://namingo.org',
                'type' => 'application/rdap+json',
            ]
        ],
        "title" => "RDAP Help"
    ];

    // Set the terms of service
    $termsOfService = [
        "title" => "Terms of Service",
        "description" => [
            "Access to RDAP information is provided to assist persons in determining the contents of a domain name registration record in the Domain Name Registry registry database.",
            "The data in this record is provided by Domain Name Registry for informational purposes only, and Domain Name Registry does not guarantee its accuracy. ",
            "This service is intended only for query-based access. You agree that you will use this data only for lawful purposes and that, under no circumstances will you use this data to: (a) allow,",
            "enable, or otherwise support the transmission by e-mail, telephone, or facsimile of mass unsolicited, commercial advertising or solicitations to entities other than the data recipient's own existing customers; or",
            "(b) enable high volume, automated, electronic processes that send queries or data to the systems of Registry Operator, a Registrar, or NIC except as reasonably necessary to register domain names or modify existing registrations.",
            "All rights reserved. Domain Name Registry reserves the right to modify these terms at any time. By submitting this query, you agree to abide by this policy."
        ],
        "links" => [
        [
            "href" => $c['rdap_url'] . "/help",
            "value" => $c['rdap_url'] . "/help",
            "rel" => "terms-of-service",
            "type" => "application/rdap+json"
        ],
        [
            "href" => $c['registry_url'],
            "value" => $c['registry_url'],
            "rel" => "alternate",
            "type" => "text/html"
        ],
        ]
    ];

    // Construct the RDAP response for help query
    $rdapResponse = [
        "rdapConformance" => $rdapConformance,
        "notices" => [
            $helpNotices,
            $termsOfService
        ]
    ];

    // Send the RDAP response
    $response->header('Content-Type', 'application/rdap+json');
    $response->status(200);
    $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
}