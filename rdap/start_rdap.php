<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

function mapContactToVCard($contactDetails, $role) {
    return [
        'objectClassName' => 'entity',
        'roles' => [$role],
        'vcardArray' => [
            "vcard",
            [
                ["version", "4.0"],
                ["fn", $contactDetails['name']],
                ["org", $contactDetails['org']],
                ["adr", [
                    "", // Post office box
                    $contactDetails['street1'], // Extended address
                    $contactDetails['street2'], // Street address
                    $contactDetails['city'], // Locality
                    $contactDetails['sp'], // Region
                    $contactDetails['pc'], // Postal code
                    $contactDetails['cc']  // Country name
                ]],
                ["tel", $contactDetails['voice'], ["type" => "voice"]],
                ["tel", $contactDetails['fax'], ["type" => "fax"]],
                ["email", $contactDetails['email']],
            ]
        ],
    ];
}

// Create a Swoole HTTP server
$http = new Swoole\Http\Server('0.0.0.0', 7500);
$http->set([
    'daemonize' => false,
    'log_file' => '/var/log/rdap/rdap.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/log/rdap/rdap.pid',
    'max_request' => 1000,
    'dispatch_mode' => 1,
    'open_tcp_nodelay' => true,
    'max_conn' => 10000,
    'buffer_output_size' => 2 * 1024 * 1024,  // 2MB
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 600,  // 10 minutes
    'package_max_length' => 2 * 1024 * 1024,  // 2MB
    'reload_async' => true,
    'http_compression' => true
]);

// Connect to the database
try {
    $c = require_once 'config.php';
    $pdo = new PDO("{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']}", $c['db_username'], $c['db_password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode(['error' => 'Error connecting to database']));
    return;
}

// Register a callback to handle incoming requests
$http->on('request', function ($request, $response) use ($c, $pdo) {
    
    // Extract the request path
    $requestPath = $request->server['request_uri'];

    // Handle domain query
    if (preg_match('#^/domain/([^/?]+)#', $requestPath, $matches)) {
        $domainName = $matches[1];
        handleDomainQuery($request, $response, $pdo, $domainName);
    }
    // Handle entity (contacts) query
    elseif (preg_match('#^/entity/([^/?]+)#', $requestPath, $matches)) {
        $entityHandle = $matches[1];
        handleEntityQuery($request, $response, $pdo, $entityHandle);
    }
    // Handle nameserver query
    elseif (preg_match('#^/nameserver/([^/?]+)#', $requestPath, $matches)) {
        $nameserverHandle = $matches[1];
        handleNameserverQuery($request, $response, $pdo, $nameserverHandle);
    }
    // Handle help query
    elseif ($requestPath === '/help') {
        handleHelpQuery($request, $response, $pdo);
    }
    // Handle search query (e.g., search for domains by pattern)
    elseif (preg_match('#^/domains\?name=([^/?]+)#', $requestPath, $matches)) {
        $searchPattern = $matches[1];
        handleSearchQuery($request, $response, $pdo, $searchPattern);
    }
    else {
        $response->header('Content-Type', 'application/json');
        $response->status(404);
        $response->end(json_encode(['error' => 'Endpoint not found']));
    }

    // Close the connection
    $pdo = null;
});

// Start the server
$http->start();

function handleDomainQuery($request, $response, $pdo, $domainName) {
    // Extract and validate the domain name from the request
    $domain = trim($domainName);
    
    // Empty domain check
    if (!$domain) {
        $response->header('Content-Type', 'application/json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Please enter a domain name']));
        return;
    }
    
    // Check domain length
    if (strlen($domain) > 68) {
        $response->header('Content-Type', 'application/json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name is too long']));
        return;
    }
    
    // Check for prohibited patterns in domain names
    if (preg_match("/(^-|^\.|-\.|\.-|--|\.\.|-$|\.$)/", $domain)) {
        $response->header('Content-Type', 'application/json');
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
        $response->header('Content-Type', 'application/json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Invalid TLD. Please search only allowed TLDs']));
        return;
    }
    
    // Fetch the IDN regex for the given TLD
    $stmtRegex = $pdo->prepare("SELECT idn_table FROM domain_tld WHERE tld = :tld");
    $stmtRegex->bindParam(':tld', $tld, PDO::PARAM_STR);
    $stmtRegex->execute();
    $idnRegex = $stmtRegex->fetchColumn();

    if (!$idnRegex) {
        $response->header('Content-Type', 'application/json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Failed to fetch domain IDN table']));
        return;
    }

    // Check for invalid characters using fetched regex
    if (!preg_match($idnRegex, $domain)) {
        $response->header('Content-Type', 'application/json');
        $response->status(400); // Bad Request
        $response->end(json_encode(['error' => 'Domain name invalid format']));
        return;
    }

    // Perform the RDAP lookup
    try {
        // Query 1: Get domain details
        $stmt1 = $pdo->prepare("SELECT *, DATE_FORMAT(`crdate`, '%Y-%m-%dT%TZ') AS `crdate`, DATE_FORMAT(`exdate`, '%Y-%m-%dT%TZ') AS `exdate` FROM `registry`.`domain` WHERE `name` = :domain");
        $stmt1->bindParam(':domain', $domain, PDO::PARAM_STR);
        $stmt1->execute();
        $domainDetails = $stmt1->fetch(PDO::FETCH_ASSOC);
        
        // Check if the domain exists
        if (!$domainDetails) {
            // Domain not found, respond with a 404 error
            $response->header('Content-Type', 'application/json');
            $response->status(404);
            $response->end(json_encode([
                'errorCode' => 404,
                'title' => 'Not Found',
                'description' => 'The requested domain was not found in the RDAP database.',
            ]));
            // Close the connection
            $pdo = null;
            return;
        }

        // Query 2: Get status details
        $stmt2 = $pdo->prepare("SELECT `status` FROM `domain_status` WHERE `domain_id` = :domain_id");
        $stmt2->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmt2->execute();
        $statuses = $stmt2->fetchAll(PDO::FETCH_COLUMN, 0);

        // Query 3: Get registrar details
        $stmt3 = $pdo->prepare("SELECT `name`,`whois_server`,`url`,`abuse_email`,`abuse_phone` FROM `registrar` WHERE `id` = :clid");
        $stmt3->bindParam(':clid', $domainDetails['clid'], PDO::PARAM_INT);
        $stmt3->execute();
        $registrarDetails = $stmt3->fetch(PDO::FETCH_ASSOC);

        // Query 4: Get registrant details
        $stmt4 = $pdo->prepare("SELECT contact.identifier,contact_postalInfo.name,contact_postalInfo.org,contact_postalInfo.street1,contact_postalInfo.street2,contact_postalInfo.street3,contact_postalInfo.city,contact_postalInfo.sp,contact_postalInfo.pc,contact_postalInfo.cc,contact.voice,contact.voice_x,contact.fax,contact.fax_x,contact.email FROM contact,contact_postalInfo WHERE contact.id=:registrant AND contact_postalInfo.contact_id=contact.id");
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
            $stmtDetails = $pdo->prepare("SELECT contact.identifier, contact_postalInfo.name, contact_postalInfo.org, contact_postalInfo.street1, contact_postalInfo.street2, contact_postalInfo.street3, contact_postalInfo.city, contact_postalInfo.sp, contact_postalInfo.pc, contact_postalInfo.cc, contact.voice, contact.voice_x, contact.fax, contact.fax_x, contact.email FROM contact, contact_postalInfo WHERE contact.id = :contact_id AND contact_postalInfo.contact_id = contact.id");
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
            ['eventAction' => 'last rdap database update', 'eventDate' => date('Y-m-d\TH:i:s\Z')],
        ];

        // Check if domain last update is set and not empty
        if (isset($domainDetails['update']) && !empty($domainDetails['update'])) {
            $events[] = ['eventAction' => 'last domain update', 'eventDate' => date('Y-m-d', strtotime($domainDetails['update']))];
        }

        // Check if domain transfer date is set and not empty
        if (isset($domainDetails['trdate']) && !empty($domainDetails['trdate'])) {
            $events[] = ['eventAction' => 'domain transfer', 'eventDate' => date('Y-m-d', strtotime($domainDetails['trdate']))];
        }

        // Construct the RDAP response in JSON format
        $rdapResponse = [
            'rdapConformance' => [
                'rdap_level_0',
                'icann_rdap_response_profile_0',
                'icann_rdap_technical_implementation_guide_0',
            ],
            'objectClassName' => 'domain',
            'entities' => array_merge(
                [
                    mapContactToVCard($registrantDetails, 'registrant')
                ],
                array_map(function ($contact) {
                    return mapContactToVCard($contact, 'admin');
                }, $adminDetails),
                array_map(function ($contact) {
                    return mapContactToVCard($contact, 'tech');
                }, $techDetails),
                array_map(function ($contact) {
                    return mapContactToVCard($contact, 'billing');
                }, $billingDetails)
            ),
            'events' => $events,
            'handle' => $domainDetails['id'] . '',
            'ldhName' => $domain,
            'status' => $statuses,
            'links' => [
                [
                    'href' => 'https://rdap.example.com/domain/' . $domain,
                    'rel' => 'self',
                    'type' => 'application/rdap+json',
                ],
                [
                    'href' => 'https://rdap.registrar.com/domain/' . $domain,
                    'rel' => 'related',
                    'type' => 'application/rdap+json',
                ]
            ],
            'nameservers' => array_map(function ($nameserverDetails) {
                return [
                    'objectClassName' => 'nameserver',
                    'handle' => $nameserverDetails['host_id'] . '',
                    'ldhName' => $nameserverDetails['name'],
                    'links' => [
                        [
                            'href' => 'https://rdap.example.com/nameserver/' . $nameserverDetails['name'],
                            'rel' => 'self',
                            'type' => 'application/rdap+json',
                        ],
                    ],
                ];
            }, $nameservers),
            "notices" => [
                [
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
                        "href" => "https://rdap.example.com/help",
                        "rel" => "self",
                        "type" => "application/rdap+json"
                    ],
                    [
                        "href" => "https://example.com/rdap-terms",
                        "rel" => "alternate",
                        "type" => "text/html"
                    ],
                ],
                    "title" => "RDAP Terms of Service"
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
                        "rel" => "alternate",
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
                        "rel" => "alternate",
                        "type" => "text/html"
                    ]
                ],
                    "title" => "RDDS Inaccuracy Complaint Form"
                ],
            ]
        ];

        // Send the RDAP response
        $response->header('Content-Type', 'application/json');
        $response->status(200);
        $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
    } catch (PDOException $e) {
        $response->header('Content-Type', 'application/json');
        $response->status(503);
        $response->end(json_encode(['error' => 'Error connecting to the RDAP database']));
        return;
    }
}

function handleHelpQuery($request, $response, $pdo) {
    // Set the RDAP conformance levels
    $rdapConformance = [
        "rdap_level_0",
        "icann_rdap_response_profile_0",
        "icann_rdap_technical_implementation_guide_0"
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
                'href' => 'https://rdap.example.com/help',
                'rel' => 'self',
                'type' => 'application/rdap+json',
            ],
            [
                'href' => 'https://namingo.org',
                'rel' => 'related',
                'type' => 'application/rdap+json',
            ]
        ],
        "title" => "RDAP Help"
    ];

    // Set the terms of service
    $termsOfService = [
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
            "href" => "https://rdap.example.com/help",
            "rel" => "self",
            "type" => "application/rdap+json"
        ],
        [
            "href" => "https://example.com/rdap-terms",
            "rel" => "alternate",
            "type" => "text/html"
        ],
        ],
        "title" => "RDAP Terms of Service"
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
    $response->header('Content-Type', 'application/json');
    $response->status(200);
    $response->end(json_encode($rdapResponse, JSON_UNESCAPED_SLASHES));
}