<?php

function processContactCheck($conn, $db, $xml, $trans) {
    $contactIDs = $xml->command->check->children('urn:ietf:params:xml:ns:contact-1.0')->check->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    $results = [];
    foreach ($contactIDs as $contactID) {
        $contactID = (string)$contactID;

        $stmt = $db->prepare("SELECT 1 FROM contact WHERE identifier = :id");
        $stmt->execute(['id' => $contactID]);

        $results[$contactID] = $stmt->fetch() ? '0' : '1'; // 0 if exists, 1 if not
    }

    $ids = [];
    foreach ($results as $id => $available) {
        $invalid_identifier = validate_identifier($contactID);
        $entry = [$id];

        // Check if the contact ID is Invalid
        if ($invalid_identifier) {
            $entry[] = 0;  // Set status to unavailable
            $entry[] = $invalid_identifier;
        } else {
            $entry[] = $available;

            // Check if the contact is unavailable
            if (!$available) {
                $entry[] = "In use";
            }
        }
    
        $ids[] = $entry;
    }
    
    $svTRID = generateSvTRID();
    $response = [
        'command' => 'check_contact',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'ids' => $ids,
        'clTRID' => $clTRID,
        'svTRID' => $svTRID,
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    if (is_array($ids)) {
        $ids = implode(',', array_column($ids, 0));
    }
    updateTransaction($db, 'check', 'contact', $ids, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}

function processHostCheck($conn, $db, $xml, $trans) {
    $hosts = $xml->command->check->children('urn:ietf:params:xml:ns:host-1.0')->check->{'name'};
    $clTRID = (string) $xml->command->clTRID;

    $results = [];
    foreach ($hosts as $host) {
        $host = (string)$host;

        // Validation for host name
        if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $host) && strlen($host) > 254) {
            sendEppError($conn, $db, 2005, 'Invalid host name', $clTRID, $trans);
            return;
        }

        $stmt = $db->prepare("SELECT 1 FROM host WHERE name = :name");
        $stmt->execute(['name' => $host]);

        $results[$host] = $stmt->fetch() ? '0' : '1'; // 0 if exists, 1 if not
    }

    $names = [];
    foreach ($results as $id => $available) {
        $entry = [$id, $available];
        // Check if the host is unavailable
        if (!$available) {
            $entry[] = "In use";
        }
        $names[] = $entry;
    }

    $svTRID = generateSvTRID();
    $response = [
        'command' => 'check_host',
        'resultCode' => 1000,
        'lang' => 'en-US',
        'message' => 'Command completed successfully',
        'names' => $names,
        'clTRID' => $clTRID,
        'svTRID' => $svTRID,
    ];

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    if (is_array($names)) {
        $names = implode(',', array_column($names, 0));
    }
    updateTransaction($db, 'check', 'host', $names, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}

function processDomainCheck($conn, $db, $xml, $trans) {
    $domains = $xml->command->check->children('urn:ietf:params:xml:ns:domain-1.0')->check->name;
    $clTRID = (string) $xml->command->clTRID;
    
    $extensionNode = $xml->command->extension;
    if (isset($extensionNode)) {
        $launch_check = $xml->xpath('//launch:check')[0] ?? null;
    }

    if (isset($launch_check)) {
        // Extract the 'type' attribute from <launch:check>
        $launchCheckType = (string) $xml->xpath('//launch:check/@type')[0];

        // Extract <launch:phase>
        $launchPhaseText = (string) $xml->xpath('//launch:phase')[0];
        
        if ($launchCheckType === 'claims' || $launchCheckType === 'trademark') {
            // Check if the domain has claims
            $names = [];
            foreach ($domains as $domain) {
                $domainName = (string) $domain;
                
                // Initialize a new domain entry with the domain name
                $domainEntry = [$domainName];
            
                $parts = extractDomainAndTLD($domainName);
                $label = $parts['domain'];
                
                $stmt = $db->prepare("SELECT claim_key FROM tmch_claims WHERE domain_label = :domainName LIMIT 1");
                $stmt->bindParam(':domainName', $label, PDO::PARAM_STR);
                $stmt->execute();
                $claim_key = $stmt->fetchColumn();
                
                if ($claim_key) {
                    $domainEntry[] = 1;
                    $domainEntry[] = $claim_key;
                } else {
                    $domainEntry[] = 0;
                }

                // Append this domain entry to names
                $names[] = $domainEntry;
            }
            
            $svTRID = generateSvTRID();
            $response = [
                'command' => 'check_domain',
                'resultCode' => 1000,
                'lang' => 'en-US',
                'message' => 'Command completed successfully',
                'names' => $names,
                'launchCheck' => 1,
                'launchCheckType' => 'claims',
                'clTRID' => $clTRID,
                'svTRID' => $svTRID,
            ];
        } else if ($launchCheckType === 'avail') {
            if ($launchPhaseText === 'custom') {
                $launchPhaseName = (string) $xml->xpath('//launch:phase/@name')[0];
                
                $names = [];
                foreach ($domains as $domain) {
                    $domainName = (string) $domain;

                    // Check if the domain is already taken
                    $stmt = $db->prepare("SELECT name FROM domain WHERE name = :domainName");
                    $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
                    $stmt->execute();
                    $taken = $stmt->fetchColumn();
                    $availability = $taken ? '0' : '1';

                    // Initialize a new domain entry with the domain name
                    $domainEntry = [$domainName];
                    
                    if ($availability === '0') {
                        // Domain is taken
                        $domainEntry[] = 0; // Set status to unavailable
                        $domainEntry[] = 'In use';
                    } else {
                        // Check if the domain is reserved
                        $parts = extractDomainAndTLD($domainName);
                        $label = $parts['domain'];

                        $stmt = $db->prepare("SELECT type FROM reserved_domain_names WHERE name = :domainName LIMIT 1");
                        $stmt->bindParam(':domainName', $label, PDO::PARAM_STR);
                        $stmt->execute();
                        $reserved = $stmt->fetchColumn();

                        if ($reserved) {
                            $domainEntry[] = 0; // Set status to unavailable
                            $domainEntry[] = ucfirst($reserved); // Capitalize the first letter
                        } else {
                            $invalid_label = validate_label($domainName, $db);

                            // Check if the domain is Invalid
                            if ($invalid_label) {
                                $domainEntry[] = 0;  // Set status to unavailable
                                $domainEntry[] = ucfirst($invalid_label); // Capitalize the first letter
                            } else {
                                $domainEntry[] = 1; // Domain is available
                            }
                        }
                    }

                    // Append this domain entry to names
                    $names[] = $domainEntry;
                }
                
                $svTRID = generateSvTRID();
                $response = [
                    'command' => 'check_domain',
                    'resultCode' => 1000,
                    'lang' => 'en-US',
                    'message' => 'Command completed successfully',
                    'names' => $names,
                    'clTRID' => $clTRID,
                    'svTRID' => $svTRID,
                ];
            }
        }
    } else {
        $names = [];
        foreach ($domains as $domain) {
            $domainName = (string) $domain;

            // Check if the domain is already taken
            $stmt = $db->prepare("SELECT name FROM domain WHERE name = :domainName");
            $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
            $stmt->execute();
            $taken = $stmt->fetchColumn();
            $availability = $taken ? '0' : '1';

            // Initialize a new domain entry with the domain name
            $domainEntry = [$domainName];
            
            if ($availability === '0') {
                // Domain is taken
                $domainEntry[] = 0; // Set status to unavailable
                $domainEntry[] = 'In use';
            } else {
                // Check if the domain is reserved
                $parts = extractDomainAndTLD($domainName);
                $label = $parts['domain'];

                $stmt = $db->prepare("SELECT type FROM reserved_domain_names WHERE name = :domainName LIMIT 1");
                $stmt->bindParam(':domainName', $label, PDO::PARAM_STR);
                $stmt->execute();
                $reserved = $stmt->fetchColumn();

                if ($reserved) {
                    $domainEntry[] = 0; // Set status to unavailable
                    $domainEntry[] = ucfirst($reserved); // Capitalize the first letter
                } else {
                    $invalid_label = validate_label($domainName, $db);

                    // Check if the domain is Invalid
                    if ($invalid_label) {
                        $domainEntry[] = 0;  // Set status to unavailable
                        $domainEntry[] = ucfirst($invalid_label); // Capitalize the first letter
                    } else {
                        $domainEntry[] = 1; // Domain is available
                    }
                }
            }

            // Append this domain entry to names
            $names[] = $domainEntry;
        }
        
        $svTRID = generateSvTRID();
        $response = [
            'command' => 'check_domain',
            'resultCode' => 1000,
            'lang' => 'en-US',
            'message' => 'Command completed successfully',
            'names' => $names,
            'clTRID' => $clTRID,
            'svTRID' => $svTRID,
        ];
    }

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    if (is_array($names)) {
        $names = implode(',', array_column($names, 0));
    }
    updateTransaction($db, 'check', 'domain', $names, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}