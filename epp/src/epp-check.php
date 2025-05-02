<?php

function processContactCheck($conn, $db, $xml, $trans) {
    $contactIDs = $xml->command->check->children('urn:ietf:params:xml:ns:contact-1.0')->check->{'id'};
    $clTRID = (string) $xml->command->clTRID;

    // Check if contactIDs is null or empty
    if ($contactIDs === null || count($contactIDs) == 0) {
        sendEppError($conn, $db, 2003, 'contact id', $clTRID, $trans);
        return;
    }

    $results = [];
    foreach ($contactIDs as $contactID) {
        $contactID = (string)$contactID;

        $stmt = $db->prepare("SELECT 1 FROM contact WHERE identifier = :id");
        $stmt->execute(['id' => $contactID]);

        $results[$contactID] = $stmt->fetch() ? '0' : '1'; // 0 if exists, 1 if not
        $stmt->closeCursor();
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

    // Check if hosts is null or empty
    if ($hosts === null || count($hosts) == 0) {
        sendEppError($conn, $db, 2003, 'host name', $clTRID, $trans);
        return;
    }

    $results = [];
    foreach ($hosts as $host) {
        $host = (string)$host;

        if (
            strpos($host, '.') === false ||                         // No dot = not FQDN
            preg_match('/^\./', $host) ||                           // Starts with dot
            preg_match('/^-/', $host) ||                            // Starts with dash
            preg_match('/[^\w.-]/', $host)                          // Invalid characters
        ) {
            sendEppError($conn, $db, 2306, 'Host name must be fully qualified (FQDN)', $clTRID, $trans);
            return;
        }

        // Validation for host name
        if (!validateHostName($host)) {
            sendEppError($conn, $db, 2005, 'Invalid host name', $clTRID, $trans);
            return;
        }

        $stmt = $db->prepare("SELECT 1 FROM host WHERE name = :name");
        $stmt->execute(['name' => $host]);

        $results[$host] = $stmt->fetch() ? '0' : '1'; // 0 if exists, 1 if not
        $stmt->closeCursor();
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

function processDomainCheck($conn, $db, $xml, $trans, $clid) {
    $domains = $xml->command->check->children('urn:ietf:params:xml:ns:domain-1.0')->check->name;
    $clTRID = (string) $xml->command->clTRID;

    // Check if domains is null or empty
    if ($domains === null || count($domains) == 0) {
        sendEppError($conn, $db, 2003, 'domain name', $clTRID, $trans);
        return;
    }

    $extensionNode = $xml->command->extension;
    if (isset($extensionNode)) {
        $launch_check = $xml->xpath('//launch:check')[0] ?? null;
        $fee_check = $xml->xpath('//fee:check')[0] ?? null;
        $allocation_token = $xml->xpath('//allocationToken:allocationToken')[0] ?? null;
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
                $stmt->closeCursor();
                
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
                
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $launchPhaseName)) {
                    sendEppError($conn, $db, 2005, 'Error in launch phase name', $clTRID, $trans);
                    return;
                }
                
                $names = [];
                foreach ($domains as $domain) {
                    $domainName = (string) $domain;

                    // Check if the domain is already taken
                    $stmt = $db->prepare("SELECT name FROM domain WHERE name = :domainName AND tm_phase = :phase");
                    $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
                    $stmt->bindParam(':phase', $launchPhaseName, PDO::PARAM_STR);
                    $stmt->execute();
                    $taken = $stmt->fetchColumn();
                    $stmt->closeCursor();
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
                        $stmt->closeCursor();

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
            $domainEntry = [$domainName];

            $invalid_label = validate_label($domainName, $db);
            if ($invalid_label) {
                $domainEntry[] = 0; // Unavailable
                $domainEntry[] = ucfirst($invalid_label);
                $names[] = $domainEntry;
                continue;
            }

            $parts = extractDomainAndTLD($domainName);
            $label = $parts['domain'];

            $stmt = $db->prepare("
                SELECT 'taken' AS type FROM domain WHERE name = :domainName
                UNION ALL
                SELECT type FROM reserved_domain_names WHERE name = :label
                LIMIT 1
            ");
            $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
            $stmt->bindParam(':label', $label, PDO::PARAM_STR);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if ($result) {
                if ($result['type'] === 'taken') {
                    // Domain is registered
                    $domainEntry[] = 0; // Unavailable
                    $domainEntry[] = 'In use';
                } else {
                    // Domain is reserved
                    if ($allocation_token !== null) {
                        $allocationTokenValue = (string)$allocation_token;
                        $stmt = $db->prepare("SELECT token FROM allocation_tokens WHERE domain_name = :label AND token = :token LIMIT 1");
                        $stmt->bindParam(':label', $label, PDO::PARAM_STR);
                        $stmt->bindParam(':token', $allocationTokenValue, PDO::PARAM_STR);
                        $stmt->execute();
                        $token = $stmt->fetchColumn();
                        $stmt->closeCursor();

                        if ($token) {
                            $domainEntry[] = 1; // Available with a valid allocation token
                        } else {
                            $domainEntry[] = 0;
                            $domainEntry[] = 'Allocation Token mismatch';
                        }
                    } else {
                        $domainEntry[] = 0; // Unavailable
                        $domainEntry[] = ucfirst($result['type']); // Reserved reason
                    }
                }
            } else {
                // Domain is available
                $domainEntry[] = 1;
            }

            // Append this domain entry to names
            $names[] = $domainEntry;

            if (isset($fee_check)) {
                $currency = (string) $fee_check->children('urn:ietf:params:xml:ns:epp:fee-1.0')->currency;
                $commands = $fee_check->xpath('//fee:command');

                $feeResponses = [];
                foreach ($commands as $command) {
                    $commandName = (string) $command->attributes()->name;
                    $periodElement = $command->xpath('.//fee:period')[0] ?? null;

                    if ($periodElement !== null) {
                        $period = (int) $periodElement;
                        $period_unit = (string) $periodElement->attributes()->unit;
                    } else {
                        $period = 1;
                        $period_unit = 'y';
                    }

                    if ($period && (($period < 1) || ($period > 99))) {
                        sendEppError($conn, $db, 2004, 'fee:period minLength value=1, maxLength value=99', $clTRID, $trans);
                        return;
                    } elseif (!$period) {
                        $period = 1;
                    }

                    if ($period_unit) {
                        if (!preg_match('/^(m|y)$/i', $period_unit)) {
                        sendEppError($conn, $db, 2004, 'fee:period unit m|y', $clTRID, $trans);
                        return;
                        }
                    } else {
                        $period_unit = 'y';
                    }

                    $date_add = 0;
                    if ($period_unit === 'y') {
                        $date_add = ($period * 12);
                    } elseif ($period_unit === 'm') {
                        $date_add = $period;
                    }

                    if (!preg_match("/^(12|24|36|48|60|72|84|96|108|120)$/", $date_add)) {
                        sendEppError($conn, $db, 2306, 'A fee period can be for 1-10 years', $clTRID, $trans);
                        return;
                    }
                    
                    $parts = extractDomainAndTLD($domainName);
                    $label = $parts['domain'];
                    $domain_extension = '.'.$parts['tld'];

                    $stmt = $db->prepare("SELECT id FROM domain_tld WHERE tld = :domain_extension");
                    $stmt->bindParam(':domain_extension', $domain_extension, PDO::PARAM_STR);
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();

                    if ($result != false) {
                        $tld_id = $result['id'];

                        // Calculate or retrieve fee for this command
                        $returnValue = getDomainPrice($db, $domainName, $tld_id, $date_add, $commandName, $clid);
                        $price = $returnValue['price'];

                        $restore_price = getDomainRestorePrice($db, $tld_id, $clid, $currency);

                        if ($commandName == 'restore') {
                            $feeResponses[] = [
                                'command' => $commandName,
                                'period' => $period,
                                'period_unit' => $period_unit,
                                'avail' => $domainEntry[1],
                                'fee' => $restore_price,
                                'name' => $domainName,
                            ];
                        } else {
                            $feeResponses[] = [
                                'command' => $commandName,
                                'period' => $period,
                                'period_unit' => $period_unit,
                                'avail' => $domainEntry[1],
                                'fee' => $price,
                                'name' => $domainName,
                            ];
                        }
                    } else {
                        $feeResponses[] = [
                            'command' => $commandName,
                            'avail' => $domainEntry[1],
                            'reason' => $domainEntry[2],
                            'name' => $domainName,
                        ];
                        continue; // Skip to the next iteration
                    }
                }
                $fees[] = $feeResponses;
            } else {
                $fees = null;
            }
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
        if (!empty($fees)) {
            $response['fees'] = $fees;
        }
    }

    $epp = new EPP\EppWriter();
    $xml = $epp->epp_writer($response);
    if (is_array($names)) {
        $names = implode(',', array_column($names, 0));
    }
    updateTransaction($db, 'check', 'domain', $names, 1000, 'Command completed successfully', $svTRID, $xml, $trans);
    sendEppResponse($conn, $xml);
}