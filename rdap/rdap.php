<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

// Create a Swoole HTTP server
$http = new Swoole\Http\Server('0.0.0.0', 8080);

// Register a callback to handle incoming requests
$http->on('request', function ($request, $response) {
    // Connect to the database
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=registry', 'registry-select', 'EPPRegistrySELECT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        $response->end(json_encode(['error' => 'Error connecting to database']));
        return;
    }
    
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
    $domain = $domainName;
    // ... Perform validation as in the WHOIS server ...

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

        // Query 5: Get admin and tech contacts (similar to registrant, with different conditions)
        // ...

        // Query 6: Get nameservers
        $stmt6 = $pdo->prepare("SELECT `name` FROM `domain_host_map`,`host` WHERE `domain_host_map`.`domain_id` = :domain_id AND `domain_host_map`.`host_id` = `host`.`id`");
        $stmt6->bindParam(':domain_id', $domainDetails['id'], PDO::PARAM_INT);
        $stmt6->execute();
        $nameservers = $stmt6->fetchAll(PDO::FETCH_COLUMN, 0);

        // Construct the RDAP response in JSON format
        $rdapResponse = [
            'objectClassName' => 'domain',
            'ldhName' => $domain,
            'status' => $statuses,
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => $domainDetails['crdate']],
                ['eventAction' => 'expiration', 'eventDate' => $domainDetails['exdate']],
                // ... Additional events ...
            ],
            'entities' => [
                [
                    'objectClassName' => 'entity',
                    'roles' => ['registrant'],
                    'vcardArray' => [
                        "vcard",
                        [
                            ["version", "4.0"],
                            ["fn", $registrantDetails['name']],
                            ["org", $registrantDetails['org']],
                            ["adr", [
                                "", // Post office box
                                $registrantDetails['street1'], // Extended address
                                $registrantDetails['street2'], // Street address
                                $registrantDetails['city'], // Locality
                                $registrantDetails['sp'], // Region
                                $registrantDetails['pc'], // Postal code
                                $registrantDetails['cc']  // Country name
                            ]],
                            ["tel", $registrantDetails['voice'], ["type" => "voice"]],
                            ["tel", $registrantDetails['fax'], ["type" => "fax"]],
                            ["email", $registrantDetails['email']],
                            // ... Additional vCard properties ...
                        ]
                    ],
                ],
                // ... Additional entities for admin, tech ...
            ],
            'links' => [
                [
                    'value' => 'http://example.com/rdap/domain/' . $domain,
                    'rel' => 'self',
                    'href' => 'http://example.com/rdap/domain/' . $domain,
                    'type' => 'application/rdap+json',
                ],
                [
                    'value' => 'http://example.com/rdap/tos',
                    'rel' => 'terms-of-service',
                    'href' => 'http://example.com/rdap/tos',
                   'type' => 'text/html',
                ],
                // ... Additional RDAP links ...
            ],
            'nameservers' => array_map(function ($name) {
                return ['ldhName' => $name];
            }, $nameservers),
            // ... Other RDAP fields ...
        ];

        // Send the RDAP response
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($rdapResponse));
    } catch (PDOException $e) {
        $response->end(json_encode(['error' => 'Error connecting to the RDAP database']));
        return;
    }
}
