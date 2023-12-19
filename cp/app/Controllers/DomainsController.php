<?php

namespace App\Controllers;

use App\Models\Domain;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class DomainsController extends Controller
{
    public function listDomains(Request $request, Response $response)
    {
        return view($response,'admin/domains/listDomains.twig');
    }
   
    public function checkDomain(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $domainName = $data['domain_name'] ?? null;
            $token = $data['token'] ?? null;
            $claims = $data['claims'] ?? null;

            if ($domainName) {
                $domainName = preg_replace('/[^\p{L}0-9-.]/u', '', $domainName);
                $parts = extractDomainAndTLD($domainName);

                $domainModel = new Domain($this->container->get('db'));
                $availability = $domainModel->getDomainByName($domainName);

                // Convert the DB result into a boolean '0' or '1'
                $availability = $availability ? '0' : '1';
                
                $invalid_label = validate_label($domainName, $this->container->get('db'));
                
                if (isset($claims)) {
                    $claim_key = $this->container->get('db')->selectValue('SELECT claim_key FROM tmch_claims WHERE domain_label = ? LIMIT 1',[$parts['domain']]);
                    
                    if ($claim_key) {
                        $claim = 1;
                    } else {
                        $claim = 0;
                    }
                } else {
                    $claim = 2;
                }
                
                // Check if the domain is Invalid
                if ($invalid_label) {
                    $this->container->get('flash')->addMessage('error', 'Domain ' . $domainName . ' is not available: ' . $invalid_label);
                    return $response->withHeader('Location', '/domain/check')->withStatus(302);
                } else {
                    // If the domain is not taken, check if it's reserved
                    if ($availability === '1') {
                        $domain_already_reserved = $this->container->get('db')->selectRow('SELECT id,type FROM reserved_domain_names WHERE name = ? LIMIT 1',[$parts['domain']]);

                        if ($domain_already_reserved) {
                            $this->container->get('flash')->addMessage('info', 'Domain ' . $domainName . ' is not available, as it is ' . $domain_already_reserved['type'] . '!');
                            return $response->withHeader('Location', '/domain/check')->withStatus(302);
                        } else {
                            if ($claim == 1) {
                                $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' is available!<br />Claim exists.<br />Claim key is: ' . $claim_key);
                                return $response->withHeader('Location', '/domain/check')->withStatus(302);
                            } elseif ($claim == 2) {
                                $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' is available!');
                                return $response->withHeader('Location', '/domain/check')->withStatus(302);
                            } elseif ($claim == 0) {
                                $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' is available!<br />Claim does not exist');
                                return $response->withHeader('Location', '/domain/check')->withStatus(302);
                            }
                        }
                    } else {
                        $this->container->get('flash')->addMessage('error', 'Domain ' . $domainName . ' is not available: In use');
                        return $response->withHeader('Location', '/domain/check')->withStatus(302);
                    }
                }
            }
        }

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/domains/checkDomain.twig');
    }
    
    public function createDomain(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $domainName = $data['domainName'] ?? null;
            $registrar_id = $data['registrar'] ?? null;
            $registrars = $db->select("SELECT id, clid, name FROM registrar");
            if ($_SESSION["auth_roles"] != 0) {
                $registrar = true;
            } else {
                $registrar = null;
            }
            
            $registrationYears = $data['registrationYears'];
            
            $contactRegistrant = $data['contactRegistrant'] ?? null;
            $contactAdmin = $data['contactAdmin'] ?? null;
            $contactTech = $data['contactTech'] ?? null;
            $contactBilling = $data['contactBilling'] ?? null;
            
            $nameservers = !empty($data['nameserver']) ? $data['nameserver'] : null;
            $nameserver_ipv4 = !empty($data['nameserver_ipv4']) ? $data['nameserver_ipv4'] : null;
            $nameserver_ipv6 = !empty($data['nameserver_ipv6']) ? $data['nameserver_ipv6'] : null;
            
            $dsKeyTag = isset($data['dsKeyTag']) ? (int)$data['dsKeyTag'] : null;
            $dsAlg = $data['dsAlg'] ?? null;
            $dsDigestType = isset($data['dsDigestType']) ? (int)$data['dsDigestType'] : null;
            $dsDigest = $data['dsDigest'] ?? null;
            
            $dnskeyFlags = $data['dnskeyFlags'] ?? null;
            $dnskeyProtocol = $data['dnskeyProtocol'] ?? null;
            $dnskeyAlg = $data['dnskeyAlg'] ?? null;
            $dnskeyPubKey = $data['dnskeyPubKey'] ?? null;
            
            $authInfo = $data['authInfo'] ?? null;
            
            $parts = extractDomainAndTLD($domainName);
            $label = $parts['domain'];
            $domain_extension = $parts['tld'];
            $invalid_domain = validate_label($domainName, $db);

            if ($invalid_domain) {
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'Invalid domain name',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            $valid_tld = false;
            $result = $db->select('SELECT id, tld FROM domain_tld');

            foreach ($result as $row) {
                if ('.' . strtoupper($domain_extension) === strtoupper($row['tld'])) {
                    $valid_tld = true;
                    $tld_id = $row['id'];
                    break;
                }
            }

            if (!$valid_tld) {
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'Invalid domain extension',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            $domain_already_exist = $db->selectValue(
                'SELECT id FROM domain WHERE name = ? LIMIT 1',
                [$domainName]
            );

            if ($domain_already_exist) {
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'Domain name already exists',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            $domain_already_reserved = $db->selectValue(
                'SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1',
                [$label]
            );

            if ($domain_already_reserved) {
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'Domain name is reserved or restricted',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            if ($registrationYears && (($registrationYears < 1) || ($registrationYears > 10))) {
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'Domain period must be from 1 to 10',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            } elseif (!$registrationYears) {
                $registrationYears = 1;
            }
            
            $date_add = 0;
            $date_add = ($registrationYears * 12);
    
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $registrar_id;
            }
            
            $result = $db->selectRow('SELECT accountBalance, creditLimit FROM registrar WHERE id = ?', [$clid]);

            $registrar_balance = $result['accountBalance'];
            $creditLimit = $result['creditLimit'];
            
            $returnValue = getDomainPrice($db, $domainName, $tld_id, $date_add, 'create');
            $price = $returnValue['price'];

            if (!$price) {
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'The price, period and currency for such TLD are not declared',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            if (($registrar_balance + $creditLimit) < $price) {
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'Low credit: minimum threshold reached',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            $nameservers = array_filter($data['nameserver'] ?? [], function($value) {
                return !empty($value) && $value !== null;
            });
            $nameserver_ipv4 = array_filter($data['nameserver_ipv4'] ?? [], function($value) {
                return !empty($value) && $value !== null;
            });
            $nameserver_ipv6 = array_filter($data['nameserver_ipv6'] ?? [], function($value) {
                return !empty($value) && $value !== null;
            });
            
            if (!empty($nameservers)) {
                if (count($nameservers) !== count(array_unique($nameservers))) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Duplicate nameservers detected. Please provide unique nameservers.',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
                
                foreach ($nameservers as $index => $nameserver) {
                    if (preg_match("/^-|^\.-|-\.$|^\.$/", $nameserver)) {
                        return view($response, 'admin/domains/createDomain.twig', [
                            'domainName' => $domainName,
                            'error' => 'Invalid hostName',
                            'registrars' => $registrars,
                            'registrar' => $registrar,
                        ]);
                    }
                    
                    if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $nameserver) && strlen($nameserver) < 254) {
                        return view($response, 'admin/domains/createDomain.twig', [
                            'domainName' => $domainName,
                            'error' => 'Invalid hostName',
                            'registrars' => $registrars,
                            'registrar' => $registrar,
                        ]);
                    }
                }
            }
            
            if ($contactRegistrant) {
                $validRegistrant = validate_identifier($contactRegistrant);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactRegistrant]);

                if (!$row) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Registrant does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'The contact requested in the command does NOT belong to the current registrar',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
            }
            
            if ($contactAdmin) {
                $validAdmin = validate_identifier($contactAdmin);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactAdmin]);

                if (!$row) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Admin contact does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'The contact requested in the command does NOT belong to the current registrar',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
            }
            
            if ($contactTech) {
                $validTech = validate_identifier($contactTech);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactTech]);

                if (!$row) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Tech contact does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'The contact requested in the command does NOT belong to the current registrar',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
            }
            
            if ($contactBilling) {
                $validBilling = validate_identifier($contactBilling);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactBilling]);

                if (!$row) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Billing contact does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'The contact requested in the command does NOT belong to the current registrar',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
            }
            
            if (!$authInfo) {
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'Missing domain authinfo',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            if (strlen($authInfo) < 6 || strlen($authInfo) > 16) {
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'Password needs to be at least 6 and up to 16 characters long',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            if (!preg_match('/[A-Z]/', $authInfo)) {
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'Password should have both upper and lower case characters',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            $registrant_id = $db->selectValue(
                'SELECT id FROM contact WHERE identifier = ? LIMIT 1',
                [$contactRegistrant]
            );

            try {
                $db->beginTransaction();
                
                $currentDateTime = new \DateTime();
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                $currentDateTime = new \DateTime();
                $currentDateTime->modify("+$date_add months");
                $exdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Expiry timestamp after $date_add months

                $db->insert('domain', [
                    'name' => $domainName,
                    'tldid' => $tld_id,
                    'registrant' => $registrant_id,
                    'crdate' => $crdate,
                    'exdate' => $exdate,
                    'lastupdate' => null,
                    'clid' => $clid,
                    'crid' => $clid,
                    'upid' => null,
                    'trdate' => null,
                    'trstatus' => null,
                    'reid' => null,
                    'redate' => null,
                    'acid' => null,
                    'acdate' => null,
                    'rgpstatus' => 'addPeriod',
                    'addPeriod' => $date_add
                ]);
                $domain_id = $db->getlastInsertId();

                $db->insert(
                    'domain_authInfo',
                    [
                        'domain_id' => $domain_id,
                        'authtype' => 'pw',
                        'authinfo' => $authInfo
                    ]
                );
                
                // Data sanity checks
                // Validate keyTag
                if (!empty($dsKeyTag)) {
                    if (!is_int($dsKeyTag)) {
                        return view($response, 'admin/domains/createDomain.twig', [
                            'domainName' => $domainName,
                            'error' => 'Incomplete key tag provided',
                            'registrars' => $registrars,
                            'registrar' => $registrar,
                        ]);
                    }
                
                    if ($dsKeyTag < 0 || $dsKeyTag > 65535) {
                        return view($response, 'admin/domains/createDomain.twig', [
                            'domainName' => $domainName,
                            'error' => 'Incomplete key tag provided',
                            'registrars' => $registrars,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                // Validate alg
                $validAlgorithms = [2, 3, 5, 6, 7, 8, 10, 13, 14, 15, 16];
                if (!empty($dsAlg) && !in_array($dsAlg, $validAlgorithms)) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Incomplete algorithm provided',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                // Validate digestType and digest
                if (!empty($dsDigestType) && !is_int($dsDigestType)) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Incomplete digest type provided',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
                $validDigests = [
                1 => 40,  // SHA-1
                2 => 64,  // SHA-256
                4 => 96   // SHA-384
                ];
                if (empty($validDigests[$dsDigestType])) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Unsupported digest type',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
                if (!empty($dsDigest)) {
                    if (strlen($dsDigest) != $validDigests[$dsDigestType] || !ctype_xdigit($dsDigest)) {
                        return view($response, 'admin/domains/createDomain.twig', [
                            'domainName' => $domainName,
                            'error' => 'Invalid digest length or format',
                            'registrars' => $registrars,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                // Data sanity checks for keyData
                // Validate flags
                $validFlags = [256, 257];
                if (!empty($dnskeyFlags) && !in_array($dnskeyFlags, $validFlags)) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Invalid flags provided',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                // Validate protocol
                if (!empty($dnskeyProtocol) && $dnskeyProtocol != 3) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Invalid protocol provided',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                // Validate algKeyData
                if (!empty($dnskeyAlg)) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Invalid algorithm encoding',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                // Validate pubKey
                if (!empty($dnskeyPubKey) && base64_encode(base64_decode($dnskeyPubKey, true)) !== $dnskeyPubKey) {
                    return view($response, 'admin/domains/createDomain.twig', [
                        'domainName' => $domainName,
                        'error' => 'Invalid public key encoding',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if (!empty($dsKeyTag)) {
                    // Base data for the insert
                    $insertData = [
                        'domain_id' => $domain_id,
                        'maxsiglife' => $maxSigLife,
                        'interface' => 'dsData',
                        'keytag' => $dsKeyTag,
                        'alg' => $dsAlg,
                        'digesttype' => $dsDigestType,
                        'digest' => $dsDigest,
                        'flags' => null,
                        'protocol' => null,
                        'keydata_alg' => null,
                        'pubkey' => null
                    ];

                    // Check additional conditions for dnskeyFlags
                    if (isset($dnskeyFlags) && $dnskeyFlags !== "") {
                        $insertData['flags'] = $dnskeyFlags;
                        $insertData['protocol'] = $dnskeyProtocol;
                        $insertData['keydata_alg'] = $dnskeyAlg;
                        $insertData['pubkey'] = $dnskeyPubKey;
                    }

                    // Perform the insert
                    $db->insert('secdns', $insertData);
                }
                
                $db->exec(
                    'UPDATE registrar SET accountBalance = accountBalance - ? WHERE id = ?',
                    [$price, $clid]
                );

                $db->exec(
                    'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                    [$clid, "create domain $domainName for period $date_add MONTH", "-$price"]
                );

                $row = $db->selectRow(
                    'SELECT crdate, exdate FROM domain WHERE name = ? LIMIT 1',
                    [$domainName]
                );
                $from = $row['crdate'];
                $to = $row['exdate'];

                $currentDateTime = new \DateTime();
                $stdate = $currentDateTime->format('Y-m-d H:i:s.v');
                $db->insert(
                    'statement',
                    [
                        'registrar_id' => $clid,
                        'date' => $stdate,
                        'command' => 'create',
                        'domain_name' => $domainName,
                        'length_in_months' => $date_add,
                        'fromS' => $from,
                        'toS' => $to,
                        'amount' => $price
                    ]
                );

                if (!empty($nameservers)) {
                    foreach ($nameservers as $index => $nameserver) {
                        
                        $internal_host = false;
                        
                        $result = $db->select('SELECT tld FROM domain_tld');

                        foreach ($result as $row) {
                            if ('.' . strtoupper($domain_extension) === strtoupper($row['tld'])) {
                                $internal_host = true;
                                break;
                            }
                        }

                        $hostName_already_exist = $db->selectValue(
                            'SELECT id FROM host WHERE name = ? LIMIT 1',
                            [$nameserver]
                        );

                        if ($hostName_already_exist) {
                            $domain_host_map_id = $db->selectValue(
                                'SELECT domain_id FROM domain_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1',
                                [$domain_id, $hostName_already_exist]
                            );

                            if (!$domain_host_map_id) {
                                $db->insert(
                                    'domain_host_map',
                                    [
                                        'domain_id' => $domain_id,
                                        'host_id' => $hostName_already_exist
                                    ]
                                );
                            } else {
                                $currentDateTime = new \DateTime();
                                $logdate = $currentDateTime->format('Y-m-d H:i:s.v');
                                $db->insert(
                                    'error_log',
                                    [
                                        'registrar_id' => $clid,
                                        'log' => "Domain : $domainName ; hostName : $nameserver - is duplicated",
                                        'date' => $logdate
                                    ]
                                );
                            }
                        } else {
                            $currentDateTime = new \DateTime();
                            $host_date = $currentDateTime->format('Y-m-d H:i:s.v');
                            
                            if ($internal_host) {
                                $db->insert(
                                    'host',
                                    [
                                        'name' => $nameserver,
                                        'domain_id' => $domain_id,
                                        'clid' => $clid,
                                        'crid' => $clid,
                                        'crdate' => $host_date
                                    ]
                                );
                                $host_id = $db->getlastInsertId();
                            } else {
                                $db->insert(
                                    'host',
                                    [
                                        'name' => $nameserver,
                                        'clid' => $clid,
                                        'crid' => $clid,
                                        'crdate' => $host_date
                                    ]
                                );
                                $host_id = $db->getlastInsertId();
                            }

                            $db->insert(
                                'domain_host_map',
                                [
                                    'domain_id' => $domain_id,
                                    'host_id' => $host_id
                                ]
                            );
                            
                            $db->insert(
                                'host_status',
                                [
                                    'status' => 'ok',
                                    'host_id' => $host_id
                                ]
                            );
                            
                            if ($internal_host) {
                                if (empty($nameserver_ipv4[$index]) && empty($nameserver_ipv6[$index])) {
                                    return view($response, 'admin/domains/createDomain.twig', [
                                        'domainName' => $domainName,
                                        'error' => 'Error: No IPv4 or IPv6 addresses provided for internal host',
                                        'registrars' => $registrars,
                                        'registrar' => $registrar,
                                    ]);
                                }
    
                                if (isset($nameserver_ipv4[$index]) && !empty($nameserver_ipv4[$index])) {
                                    $ipv4 = normalize_v4_address($nameserver_ipv4[$index]);
                                    
                                    $db->insert(
                                        'host_addr',
                                        [
                                            'host_id' => $host_id,
                                            'addr' => $ipv4,
                                            'ip' => 'v4'
                                        ]
                                    );
                                }

                                if (isset($nameserver_ipv6[$index]) && !empty($nameserver_ipv6[$index])) {
                                    $ipv6 = normalize_v6_address($nameserver_ipv6[$index]);
                                    
                                    $db->insert(
                                        'host_addr',
                                        [
                                            'host_id' => $host_id,
                                            'addr' => $ipv6,
                                            'ip' => 'v6'
                                        ]
                                    );
                                }
                            }
                            
                        }
                    }
                }
                
                $contacts = [
                    'admin' => $data['contactAdmin'] ?? null,
                    'tech' => $data['contactTech'] ?? null,
                    'billing' => $data['contactBilling'] ?? null
                ];

                foreach ($contacts as $type => $contact) {
                    if ($contact !== null) {
                        $contact_id = $db->selectValue(
                            'SELECT id FROM contact WHERE identifier = ? LIMIT 1',
                            [$contact]
                        );

                        // Check if $contact_id is not null before insertion
                        if ($contact_id !== null) {
                            $db->insert(
                                'domain_contact_map',
                                [
                                    'domain_id' => $domain_id,
                                    'contact_id' => $contact_id,
                                    'type' => $type
                                ]
                            );
                        }
                    }
                }

                $result = $db->selectRow(
                    'SELECT crdate,exdate FROM domain WHERE name = ? LIMIT 1',
                    [$domainName]
                );
                $crdate = $result['crdate'];
                $exdate = $result['exdate'];

                $curdate_id = $db->selectValue(
                    'SELECT id FROM statistics WHERE date = CURDATE()'
                );

                if (!$curdate_id) {
                    $db->exec(
                        'INSERT IGNORE INTO statistics (date) VALUES(CURDATE())'
                    );
                }

                $db->exec(
                    'UPDATE statistics SET created_domains = created_domains + 1 WHERE date = CURDATE()'
                );
                
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'Database failure: ' . $e->getMessage(),
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            } catch (\Pinga\Db\Throwable\IntegrityConstraintViolationException $e) {
                $db->rollBack();
                return view($response, 'admin/domains/createDomain.twig', [
                    'domainName' => $domainName,
                    'error' => 'Database failure: ' . $e->getMessage(),
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            $crdate = $db->selectValue(
                "SELECT crdate FROM domain WHERE id = ? LIMIT 1",
                [$domain_id]
            );
            
            $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' has been created successfully on ' . $crdate);
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
        } else {
            $registrar = null;
        }

        $locale = (isset($_SESSION['_lang']) && !empty($_SESSION['_lang'])) ? $_SESSION['_lang'] : 'en_US';
        $currency = $_SESSION['_currency'] ?? 'USD'; // Default to USD if not set

        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currency);

        $symbol = $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
        $pattern = $formatter->getPattern();

        // Determine currency position (before or after)
        $position = (strpos($pattern, '¤') < strpos($pattern, '#')) ? 'before' : 'after';

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/domains/createDomain.twig', [
            'registrars' => $registrars,
            'currencySymbol' => $symbol,
            'currencyPosition' => $position,
            'registrar' => $registrar,
        ]);
    }
    
    public function viewDomain(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = strtolower(trim($args));

            if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
        
            $domain = $db->selectRow('SELECT id, name, registrant, crdate, exdate, lastupdate, clid, idnlang, rgpstatus FROM domain WHERE name = ?',
            [ $args ]);

            if ($domain) {
                $registrars = $db->selectRow('SELECT id, clid, name FROM registrar WHERE id = ?', [$domain['clid']]);

                // Check if the user is not an admin (assuming role 0 is admin)
                if ($_SESSION["auth_roles"] != 0) {
                    $userRegistrars = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                    // Assuming $userRegistrars returns an array of arrays, each containing 'registrar_id'
                    $userRegistrarIds = array_column($userRegistrars, 'registrar_id');

                    // Check if the registrar's ID is in the user's list of registrar IDs
                    if (!in_array($registrars['id'], $userRegistrarIds)) {
                        // Redirect to the domains view if the user is not authorized for this contact
                        return $response->withHeader('Location', '/domains')->withStatus(302);
                    }
                }
                
                $domainRegistrant = $db->selectRow('SELECT identifier FROM contact WHERE id = ?',
                [ $domain['registrant'] ]);
                $domainStatus = $db->select('SELECT status FROM domain_status WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainAuth = $db->selectRow('SELECT * FROM domain_authInfo WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainSecdns = $db->select('SELECT * FROM secdns WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainHostsQuery = '
                    SELECT dhm.id, dhm.domain_id, dhm.host_id, h.name
                    FROM domain_host_map dhm
                    JOIN host h ON dhm.host_id = h.id
                    WHERE dhm.domain_id = ?';

                $domainHosts = $db->select($domainHostsQuery, [$domain['id']]);
                $domainContactsQuery = '
                    SELECT dcm.id, dcm.domain_id, dcm.contact_id, dcm.type, c.identifier 
                    FROM domain_contact_map dcm
                    JOIN contact c ON dcm.contact_id = c.id
                    WHERE dcm.domain_id = ?';
                $domainContacts = $db->select($domainContactsQuery, [$domain['id']]);

                return view($response,'admin/domains/viewDomain.twig', [
                    'domain' => $domain,
                    'domainStatus' => $domainStatus,
                    'domainAuth' => $domainAuth,
                    'domainRegistrant' => $domainRegistrant,
                    'domainSecdns' => $domainSecdns,
                    'domainHosts' => $domainHosts,
                    'domainContacts' => $domainContacts,
                    'registrars' => $registrars,
                    'currentUri' => $uri
                ]);
            } else {
                // Domain does not exist, redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

        } else {
            // Redirect to the domains view
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

    }
    
    public function updateDomain(Request $request, Response $response, $args)
    {
        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
        } else {
            $registrar = null;
        }
        
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = strtolower(trim($args));

            if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

            $domain = $db->selectRow('SELECT id, name, registrant, crdate, exdate, lastupdate, clid, idnlang, rgpstatus FROM domain WHERE name = ?',
            [ $args ]);

            if ($domain) {
                $registrars = $db->selectRow('SELECT id, clid, name FROM registrar WHERE id = ?', [$domain['clid']]);

                // Check if the user is not an admin (assuming role 0 is admin)
                if ($_SESSION["auth_roles"] != 0) {
                    $userRegistrars = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                    // Assuming $userRegistrars returns an array of arrays, each containing 'registrar_id'
                    $userRegistrarIds = array_column($userRegistrars, 'registrar_id');

                    // Check if the registrar's ID is in the user's list of registrar IDs
                    if (!in_array($registrars['id'], $userRegistrarIds)) {
                        // Redirect to the domains view if the user is not authorized for this contact
                        return $response->withHeader('Location', '/domains')->withStatus(302);
                    }
                }
                
                $domainRegistrant = $db->selectRow('SELECT identifier FROM contact WHERE id = ?',
                [ $domain['registrant'] ]);
                $domainStatus = $db->select('SELECT status FROM domain_status WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainAuth = $db->selectRow('SELECT authinfo FROM domain_authInfo WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainSecdns = $db->select('SELECT * FROM secdns WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainHostsQuery = '
                    SELECT dhm.id, dhm.domain_id, dhm.host_id, h.name
                    FROM domain_host_map dhm
                    JOIN host h ON dhm.host_id = h.id
                    WHERE dhm.domain_id = ?';

                $domainHosts = $db->select($domainHostsQuery, [$domain['id']]);
                $domainContactsQuery = '
                    SELECT dcm.id, dcm.domain_id, dcm.contact_id, dcm.type, c.identifier 
                    FROM domain_contact_map dcm
                    JOIN contact c ON dcm.contact_id = c.id
                    WHERE dcm.domain_id = ?';
                $domainContacts = $db->select($domainContactsQuery, [$domain['id']]);
                
                $csrfTokenName = $this->container->get('csrf')->getTokenName();
                $csrfTokenValue = $this->container->get('csrf')->getTokenValue();


                return view($response,'admin/domains/updateDomain.twig', [
                    'domain' => $domain,
                    'domainStatus' => $domainStatus,
                    'domainAuth' => $domainAuth,
                    'domainRegistrant' => $domainRegistrant,
                    'domainSecdns' => $domainSecdns,
                    'domainHosts' => $domainHosts,
                    'domainContacts' => $domainContacts,
                    'registrar' => $registrars,
                    'currentUri' => $uri,
                    'csrfTokenName' => $csrfTokenName,
                    'csrfTokenValue' => $csrfTokenValue
               ]);
            } else {
                // Domain does not exist, redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

        } else {
            // Redirect to the domains view
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }
    }
    
    public function updateDomainProcess(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $domainName = $data['domainName'] ?? null;
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
            }
            
            $domain_id = $db->selectValue(
                'SELECT id FROM domain WHERE name = ?',
                [$domainName]
            );
            $results = $db->select(
                'SELECT status FROM domain_status WHERE domain_id = ?',
                [ $domain_id ]
            );

            foreach ($results as $row) {
                $status = $row['status'];
                if (preg_match('/.*(serverUpdateProhibited)$/', $status) || preg_match('/^pendingTransfer/', $status)) {
                    $this->container->get('flash')->addMessage('error', 'It has a status that does not allow renew, first change the status');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            }
            
            $contactRegistrant = $data['contactRegistrant'] ?? null;
            $contactAdmin = $data['contactAdmin'] ?? null;
            $contactTech = $data['contactTech'] ?? null;
            $contactBilling = $data['contactBilling'] ?? null;
            
            $nameservers = $data['nameserver'] ?? [];

            $dsKeyTag = isset($data['dsKeyTag']) ? (int)$data['dsKeyTag'] : null;
            $dsAlg = $data['dsAlg'] ?? null;
            $dsDigestType = isset($data['dsDigestType']) ? (int)$data['dsDigestType'] : null;
            $dsDigest = $data['dsDigest'] ?? null;
            
            $dnskeyFlags = $data['dnskeyFlags'] ?? null;
            $dnskeyProtocol = $data['dnskeyProtocol'] ?? null;
            $dnskeyAlg = $data['dnskeyAlg'] ?? null;
            $dnskeyPubKey = $data['dnskeyPubKey'] ?? null;
            
            $authInfo = $data['authInfo'] ?? null;
            
            if ($contactRegistrant) {
                $validRegistrant = validate_identifier($contactRegistrant);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactRegistrant]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Registrant does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } else {
                $this->container->get('flash')->addMessage('error', 'Please provide registrant identifier');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
            
            if ($contactAdmin) {
                $validAdmin = validate_identifier($contactAdmin);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactAdmin]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Admin contact does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } else {
                $this->container->get('flash')->addMessage('error', 'Please provide admin contact identifier');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
            
            if ($contactTech) {
                $validTech = validate_identifier($contactTech);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactTech]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Tech contact does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } else {
                $this->container->get('flash')->addMessage('error', 'Please provide tech contact identifier');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
            
            if ($contactBilling) {
                $validBilling = validate_identifier($contactBilling);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactBilling]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Billing contact does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } else {
                $this->container->get('flash')->addMessage('error', 'Please provide billing contact identifier');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
            
            if (!$authInfo) {
                $this->container->get('flash')->addMessage('error', 'Domain ' . $domainName . ' can not be updated: Missing domain authinfo');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }

            if (strlen($authInfo) < 6 || strlen($authInfo) > 16) {
                $this->container->get('flash')->addMessage('error', 'Password needs to be at least 6 and up to 16 characters long');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }

            if (!preg_match('/[A-Z]/', $authInfo)) {
                $this->container->get('flash')->addMessage('error', 'Password should have both upper and lower case characters');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
            
            $registrant_id = $db->selectValue(
                'SELECT id FROM contact WHERE identifier = ? LIMIT 1',
                [$contactRegistrant]
            );
            
            try {
                $db->beginTransaction();
                
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                $db->update('domain', [
                    'registrant' => $registrant_id,
                    'lastupdate' => $update,
                    'upid' => $clid
                ],
                [
                    'name' => $domainName
                ]
                );
                $domain_id = $db->selectValue(
                    'SELECT id FROM domain WHERE name = ?',
                    [$domainName]
                );

                $db->update(
                    'domain_authInfo',
                    [
                        'authinfo' => $authInfo
                    ],
                    [
                        'id' => $domain_id,
                        'authtype' => 'pw'
                    ]
                );
                
                // Data sanity checks
                // Validate keyTag
                if (!empty($dsKeyTag)) {
                    if (!is_int($dsKeyTag)) {
                        $this->container->get('flash')->addMessage('error', 'Incomplete key tag provided');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                
                    if ($dsKeyTag < 0 || $dsKeyTag > 65535) {
                        $this->container->get('flash')->addMessage('error', 'Incomplete key tag provided');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                }

                // Validate alg
                $validAlgorithms = [2, 3, 5, 6, 7, 8, 10, 13, 14, 15, 16];
                if (!empty($dsAlg) && !in_array($dsAlg, $validAlgorithms)) {
                    $this->container->get('flash')->addMessage('error', 'Incomplete algorithm provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate digestType and digest
                if (!empty($dsDigestType) && !is_int($dsDigestType)) {
                    $this->container->get('flash')->addMessage('error', 'Incomplete digest type provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
                $validDigests = [
                    1 => 40,  // SHA-1
                    2 => 64,  // SHA-256
                    4 => 96   // SHA-384
                ];
                if (empty($validDigests[$dsDigestType])) {
                    $this->container->get('flash')->addMessage('error', 'Unsupported digest type');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
                if (!empty($dsDigest)) {
                    if (strlen($dsDigest) != $validDigests[$dsDigestType] || !ctype_xdigit($dsDigest)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid digest length or format');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                }

                // Data sanity checks for keyData
                // Validate flags
                $validFlags = [256, 257];
                if (!empty($dnskeyFlags) && !in_array($dnskeyFlags, $validFlags)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid flags provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate protocol
                if (!empty($dnskeyProtocol) && $dnskeyProtocol != 3) {
                    $this->container->get('flash')->addMessage('error', 'Invalid protocol provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate algKeyData
                if (!empty($dnskeyAlg)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid algorithm encoding');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate pubKey
                if (!empty($dnskeyPubKey) && base64_encode(base64_decode($dnskeyPubKey, true)) !== $dnskeyPubKey) {
                    $this->container->get('flash')->addMessage('error', 'Invalid public key encoding');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if (!empty($dsKeyTag)) {
                    // Base data for the insert
                    $insertData = [
                        'domain_id' => $domain_id,
                        'maxsiglife' => $maxSigLife,
                        'interface' => 'dsData',
                        'keytag' => $dsKeyTag,
                        'alg' => $dsAlg,
                        'digesttype' => $dsDigestType,
                        'digest' => $dsDigest,
                        'flags' => null,
                        'protocol' => null,
                        'keydata_alg' => null,
                        'pubkey' => null
                    ];

                    // Check additional conditions for dnskeyFlags
                    if (isset($dnskeyFlags) && $dnskeyFlags !== "") {
                        $insertData['flags'] = $dnskeyFlags;
                        $insertData['protocol'] = $dnskeyProtocol;
                        $insertData['keydata_alg'] = $dnskeyAlg;
                        $insertData['pubkey'] = $dnskeyPubKey;
                    }

                    // Perform the insert
                    $db->insert('secdns', $insertData);
                }
   
                foreach ($nameservers as $index => $nameserver) {
                    if (preg_match("/^-|^\.-|-\.$|^\.$/", $nameserver)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid hostName');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                    
                    if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $nameserver) && strlen($nameserver) < 254) {
                        $this->container->get('flash')->addMessage('error', 'Invalid hostName');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                
                    $hostName_already_exist = $db->selectValue(
                        'SELECT id FROM host WHERE name = ? LIMIT 1',
                        [$nameserver]
                    );

                    if ($hostName_already_exist) {
                        $domain_host_map_id = $db->selectValue(
                            'SELECT domain_id FROM domain_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1',
                            [$domain_id, $hostName_already_exist]
                        );

                        if (!$domain_host_map_id) {
                            $db->insert(
                                'domain_host_map',
                                [
                                    'domain_id' => $domain_id,
                                    'host_id' => $hostName_already_exist
                                ]
                            );
                        } else {
                            $host_map_id = $db->selectValue(
                                'SELECT id FROM domain_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1',
                                [$domain_id, $hostName_already_exist]
                            );
                            
                            $db->update(
                                'domain_host_map',
                                [
                                    'host_id' => $hostName_already_exist
                                ],
                                [
                                    'domain_id' => $domain_id,
                                    'id' => $host_map_id
                                ]
                            );
                        }
                    } else {
                        $currentDateTime = new \DateTime();
                        $host_date = $currentDateTime->format('Y-m-d H:i:s.v');
                        $db->insert(
                            'host',
                            [
                                'name' => $nameserver,
                                'domain_id' => $domain_id,
                                'clid' => $clid,
                                'crid' => $clid,
                                'crdate' => $host_date
                            ]
                        );
                        $host_id = $db->getlastInsertId();

                        $db->insert(
                            'domain_host_map',
                            [
                                'domain_id' => $domain_id,
                                'host_id' => $host_id
                            ]
                        );
                        
                        $db->insert(
                            'host_status',
                            [
                                'status' => 'ok',
                                'host_id' => $host_id
                            ]
                        );
                        
                        if (isset($nameserver_ipv4[$index]) && !empty($nameserver_ipv4[$index])) {
                            $ipv4 = normalize_v4_address($nameserver_ipv4[$index]);
                            
                            $db->insert(
                                'host_addr',
                                [
                                    'host_id' => $host_id,
                                    'addr' => $ipv4,
                                    'ip' => 'v4'
                                ]
                            );
                        }

                        if (isset($nameserver_ipv6[$index]) && !empty($nameserver_ipv6[$index])) {
                            $ipv6 = normalize_v6_address($nameserver_ipv6[$index]);
                            
                            $db->insert(
                                'host_addr',
                                [
                                    'host_id' => $host_id,
                                    'addr' => $ipv6,
                                    'ip' => 'v6'
                                ]
                            );
                        }
                        
                    }
                }

                $contacts = [
                    'admin' => $data['contactAdmin'] ?? null,
                    'tech' => $data['contactTech'] ?? null,
                    'billing' => $data['contactBilling'] ?? null
                ];

                foreach ($contacts as $type => $contact) {
                    if ($contact !== null) {
                        $contact_id = $db->selectValue(
                            'SELECT id FROM contact WHERE identifier = ? LIMIT 1',
                            [$contact]
                        );

                        $contact_map_id = $db->selectRow(
                            'SELECT * FROM domain_contact_map WHERE domain_id = ? AND type = ?',
                            [$domain_id, $type]
                        );

                        // Check if $contact_id is not null before update
                        if ($contact_id !== null) {
                            $db->update(
                                'domain_contact_map',
                                [
                                    'contact_id' => $contact_id,
                                ],
                                [
                                    'id' => $contact_map_id['id']
                                ]
                            );
                        }
                    }
                }
           
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            } catch (\Pinga\Db\Throwable\IntegrityConstraintViolationException $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
   
            $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' has been updated successfully on ' . $update);
            return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
        }
    }
    
    public function domainDeleteHost(Request $request, Response $response)
    {
        $db = $this->container->get('db');
        $data = $request->getParsedBody();
        $uri = $request->getUri()->getPath();

        if ($data['nameserver']) {
            $host_id = $db->selectValue('SELECT id FROM host WHERE name = ?',
                    [ $data['nameserver'] ]);
            $domain_id = $db->selectValue('SELECT domain_id FROM domain_host_map WHERE host_id = ?',
                    [ $host_id ]);
            $domainName = $db->selectValue('SELECT name FROM domain WHERE id = ?',
                    [ $domain_id ]);
            $db->delete(
                'domain_host_map',
                [
                    'host_id' => $host_id,
                    'domain_id' => $domain_id
                ]
            );
            
            $this->container->get('flash')->addMessage('success', 'Host ' . $data['nameserver'] . ' has been removed from domain successfully');

            $jsonData = json_encode([
                'success' => true,
                'redirect' => '/domain/update/'.$domainName
            ]);

            $response = new \Nyholm\Psr7\Response(
                200, // Status code
                ['Content-Type' => 'application/json'], // Headers
                $jsonData // Body
            );

            return $response;
        } else {
            $jsonData = json_encode([
                'success' => false,
                'error' => 'An error occurred while processing your request.'
            ]);

            return new \Nyholm\Psr7\Response(
                400,
                ['Content-Type' => 'application/json'],
                $jsonData
            );
        }
    }
    
    public function domainDeleteSecdns(Request $request, Response $response)
    {
        $db = $this->container->get('db');
        $data = $request->getParsedBody();
        $uri = $request->getUri()->getPath();

        if ($data['record']) {
            $record = filter_var($data['record'], FILTER_SANITIZE_NUMBER_INT);
            $domain_id = filter_var($data['domain_id'], FILTER_SANITIZE_NUMBER_INT);
            
            $domainName = $db->selectValue('SELECT name FROM domain WHERE id = ?',
                    [ $domain_id ]);
            $db->delete(
                'secdns',
                [
                    'id' => $record,
                    'domain_id' => $domain_id
                ]
            );
            
            $this->container->get('flash')->addMessage('success', 'Record has been removed from domain successfully');

            $jsonData = json_encode([
                'success' => true,
                'redirect' => '/domain/update/'.$domainName
            ]);

            $response = new \Nyholm\Psr7\Response(
                200, // Status code
                ['Content-Type' => 'application/json'], // Headers
                $jsonData // Body
            );

            return $response;
        } else {
            $jsonData = json_encode([
                'success' => false,
                'error' => 'An error occurred while processing your request.'
            ]);

            return new \Nyholm\Psr7\Response(
                400,
                ['Content-Type' => 'application/json'],
                $jsonData
            );
        }
    }
    
    public function renewDomain(Request $request, Response $response, $args)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $domainName = $data['domainName'] ?? null;
            $renewalYears = $data['renewalYears'] ?? null;
            
            $parts = extractDomainAndTLD($domainName);
            $label = $parts['domain'];
            $domain_extension = $parts['tld'];

            $result = $db->select('SELECT id, tld FROM domain_tld');
            foreach ($result as $row) {
                if ('.' . strtoupper($domain_extension) === strtoupper($row['tld'])) {
                    $tld_id = $row['id'];
                    break;
                }
            }
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
            }
                
            $date_add = 0;
            $date_add = ($renewalYears * 12);
            
            $result = $db->selectRow('SELECT accountBalance, creditLimit FROM registrar WHERE id = ?', [$clid]);

            $registrar_balance = $result['accountBalance'];
            $creditLimit = $result['creditLimit'];
            
            $returnValue = getDomainPrice($db, $domainName, $tld_id, $date_add, 'renew');
            $price = $returnValue['price'];

            if (!$price) {
                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                return $response->withHeader('Location', '/domain/renew/'.$domainName)->withStatus(302);
            }

            if (($registrar_balance + $creditLimit) < $price) {
                $this->container->get('flash')->addMessage('error', 'Low credit: minimum threshold reached');
                return $response->withHeader('Location', '/domain/renew/'.$domainName)->withStatus(302);
            }
            
            $domain_id = $db->selectValue(
                'SELECT id FROM domain WHERE name = ?',
                [$domainName]
            );
            $results = $db->select(
                'SELECT status FROM domain_status WHERE domain_id = ?',
                [ $domain_id ]
            );

            foreach ($results as $row) {
                $status = $row['status'];
                if (preg_match('/.*(RenewProhibited)$/', $status) || preg_match('/^pending/', $status)) {
                    $this->container->get('flash')->addMessage('error', 'It has a status that does not allow renew, first change the status');
                    return $response->withHeader('Location', '/domain/renew/'.$domainName)->withStatus(302);
                }
            }
            
            try {
                $db->beginTransaction();
                        
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
                
                $row = $db->selectRow(
                    'SELECT exdate FROM domain WHERE name = ? LIMIT 1',
                    [$domainName]
                );
                $from = $row['exdate'];
                $rgpstatus = 'renewPeriod';
                
                $db->exec(
                    'UPDATE domain SET exdate = DATE_ADD(exdate, INTERVAL ? MONTH), rgpstatus = ?, renewPeriod = ?, renewedDate = CURRENT_TIMESTAMP(3) WHERE name = ?',
                    [
                        $date_add,
                        $rgpstatus,
                        $date_add,
                        $domainName
                    ]
                );
                $domain_id = $db->selectValue(
                    'SELECT id FROM domain WHERE name = ?',
                    [$domainName]
                );

                $db->exec(
                    'UPDATE registrar SET accountBalance = accountBalance - ? WHERE id = ?',
                    [$price, $clid]
                );
                
                $db->exec(
                    'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                    [$clid, "renew domain $domainName for period $date_add MONTH", "-$price"]
                );

                $row = $db->selectRow(
                    'SELECT exdate FROM domain WHERE name = ? LIMIT 1',
                    [$domainName]
                );
                $to = $row['exdate'];

                $currentDateTime = new \DateTime();
                $stdate = $currentDateTime->format('Y-m-d H:i:s.v');
                $db->insert(
                    'statement',
                    [
                        'registrar_id' => $clid,
                        'date' => $stdate,
                        'command' => 'renew',
                        'domain_name' => $domainName,
                        'length_in_months' => $date_add,
                        'fromS' => $from,
                        'toS' => $to,
                        'amount' => $price
                    ]
                  );

                $curdate_id = $db->selectValue(
                    'SELECT id FROM statistics WHERE date = CURDATE()'
                );

                if (!$curdate_id) {
                    $db->exec(
                        'INSERT IGNORE INTO statistics (date) VALUES(CURDATE())'
                    );
                }

                $db->exec(
                    'UPDATE statistics SET renewed_domains = renewed_domains + 1 WHERE date = CURDATE()'
                );
                 
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during renew: ' . $e->getMessage());
                return $response->withHeader('Location', '/domain/renew/'.$domainName)->withStatus(302);
            }
           
            $this->container->get('flash')->addMessage('success','Domain ' . $domainName . ' has been renewed for ' . $renewalYears . ' ' . ($renewalYears > 1 ? 'years' : 'year'));
            return $response->withHeader('Location', '/domain/renew/'.$domainName)->withStatus(302);
        }

        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
        } else {
            $registrar = null;
        }
        
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = strtolower(trim($args));

            if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
            
            $domain = $db->selectRow('SELECT id, name, registrant, crdate, exdate, lastupdate, clid, idnlang, rgpstatus FROM domain WHERE name = ?',
            [ $args ]);

            if ($domain) {
                $registrars = $db->selectRow('SELECT id, clid, name FROM registrar WHERE id = ?', [$domain['clid']]);

                // Check if the user is not an admin (assuming role 0 is admin)
                if ($_SESSION["auth_roles"] != 0) {
                    $userRegistrars = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                    // Assuming $userRegistrars returns an array of arrays, each containing 'registrar_id'
                    $userRegistrarIds = array_column($userRegistrars, 'registrar_id');

                    // Check if the registrar's ID is in the user's list of registrar IDs
                    if (!in_array($registrars['id'], $userRegistrarIds)) {
                        // Redirect to the domains view if the user is not authorized for this contact
                        return $response->withHeader('Location', '/domains')->withStatus(302);
                    }
                }
                
                $domainStatus = $db->select('SELECT status FROM domain_status WHERE domain_id = ?',
                [ $domain['id'] ]);

                $expirationDate = new \DateTime($domain['exdate']);
                $currentYear = (int)date("Y");
                $expirationYear = (int)$expirationDate->format("Y");
                $yearsUntilExpiration = $expirationYear - $currentYear;
                $maxYears = 10 - $yearsUntilExpiration;
                
                $locale = (isset($_SESSION['_lang']) && !empty($_SESSION['_lang'])) ? $_SESSION['_lang'] : 'en_US';
                $currency = $_SESSION['_currency'] ?? 'USD'; // Default to USD if not set

                $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
                $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currency);

                $symbol = $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
                $pattern = $formatter->getPattern();

                // Determine currency position (before or after)
                $position = (strpos($pattern, '¤') < strpos($pattern, '#')) ? 'before' : 'after';

                return view($response,'admin/domains/renewDomain.twig', [
                    'domain' => $domain,
                    'domainStatus' => $domainStatus,
                    'registrar' => $registrars,
                    'maxYears' => $maxYears,
                    'currentUri' => $uri,
                    'currencySymbol' => $symbol,
                    'currencyPosition' => $position
               ]);
            } else {
                // Domain does not exist, redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

        } else {
            // Redirect to the domains view
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }
    }
    
    public function deleteDomain(Request $request, Response $response, $args)
    {
       // if ($request->getMethod() === 'POST') {
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();
        
            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
            
                $domain = $db->selectRow('SELECT id, name, tldid, registrant, crdate, exdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, rgpstatus, addPeriod, autoRenewPeriod, renewPeriod, renewedDate, transferPeriod FROM domain WHERE name = ?',
                [ $args ]);
            
                $domainName = $domain['name'];
                $domain_id = $domain['id'];
                $tldid = $domain['tldid'];
                $registrant = $domain['registrant'];
                $crdate = $domain['crdate'];
                $exdate = $domain['exdate'];
                $registrar_id_domain = $domain['clid'];
                $crid = $domain['crid'];
                $upid = $domain['upid'];
                $trdate = $domain['trdate'];
                $trstatus = $domain['trstatus'];
                $reid = $domain['reid'];
                $redate = $domain['redate'];
                $acid = $domain['acid'];
                $acdate = $domain['acdate'];
                $rgpstatus = $domain['rgpstatus'];
                $addPeriod = $domain['addPeriod'];
                $autoRenewPeriod = $domain['autoRenewPeriod'];
                $renewPeriod = $domain['renewPeriod'];
                $renewedDate = $domain['renewedDate'];
                $transferPeriod = $domain['transferPeriod'];

                $parts = extractDomainAndTLD($domainName);
                $label = $parts['domain'];
                $domain_extension = $parts['tld'];

                $result = $db->select('SELECT id, tld FROM domain_tld');
                foreach ($result as $row) {
                    if ('.' . strtoupper($domain_extension) === strtoupper($row['tld'])) {
                        $tld_id = $row['id'];
                        break;
                    }
                }
                
                $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                if ($_SESSION["auth_roles"] != 0) {
                    $clid = $result['registrar_id'];
                } else {
                    $clid = $registrar_id_domain;
                }
                
                $results = $db->select(
                    'SELECT status FROM domain_status WHERE domain_id = ?',
                    [ $domain_id ]
                );

                foreach ($results as $row) {
                    $status = $row['status'];
                    if (preg_match('/.*(UpdateProhibited|DeleteProhibited)$/', $status) || preg_match('/^pending/', $status)) {
                        $this->container->get('flash')->addMessage('error', 'It has a status that does not allow deletion, first change the status');
                        return $response->withHeader('Location', '/domains')->withStatus(302);
                    }
                }
                
                $grace_period = 30;
                
                $db->delete(
                    'domain_status',
                    [
                        'domain_id' => $domain_id
                    ]
                );
                
                $db->exec(
                    'UPDATE domain SET rgpstatus = ?, delTime = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL ? DAY) WHERE id = ?',
                    ['redemptionPeriod', $grace_period, $domain_id]
                );
                
                $db->insert(
                    'domain_status',
                    [
                        'domain_id' => $domain_id,
                        'status' => 'pendingDelete'
                    ]
                );
                    
                if ($rgpstatus) {
                    if ($rgpstatus === 'addPeriod') {
                        $addPeriod_id = $db->selectValue(
                            'SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(crdate, INTERVAL 5 DAY)) LIMIT 1',
                            [
                                $domain_id
                            ]
                        );
                        if ($addPeriod_id) {
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $addPeriod, 'create');
                            $price = $returnValue['price'];
            
                            if (!$price) {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }
                            
                            try {
                                $db->beginTransaction();
                            
                                $db->exec(
                                    'UPDATE registrar SET accountBalance = accountBalance + ? WHERE id = ?',
                                    [$price, $clid]
                                );
                                
                                $description = "domain name is deleted by the registrar during grace addPeriod, the registry provides a credit for the cost of the registration domain $domainName for period $addPeriod MONTH";
                                $db->exec(
                                    'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                    [$clid, $description, $price]
                                );
                                
                                $hostIds = $db->select(
                                    'SELECT id FROM host WHERE domain_id = ?',
                                    [$domain_id]
                                );
                                
                                foreach ($hostIds as $host) {
                                    $host_id = $host['id'];

                                    // Delete operations
                                    $db->delete(
                                        'host_addr',
                                        [
                                            'host_id' => $host_id
                                        ]
                                    );
                                    $db->delete(
                                        'host_status',
                                        [
                                            'host_id' => $host_id
                                        ]
                                    );
                                    $db->delete(
                                        'domain_host_map',
                                        [
                                            'host_id' => $host_id
                                        ]
                                    );
                                }
                                
                                // Delete domain related records
                                $db->delete(
                                    'domain_contact_map',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'domain_host_map',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'domain_authInfo',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'domain_status',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'host',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'secdns',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'domain',
                                    [
                                        'id' => $domain_id
                                    ]
                                );
                                
                                $curdate_id = $db->selectValue(
                                    'SELECT id FROM statistics WHERE date = CURDATE()'
                                );

                                if (!$curdate_id) {
                                    $db->exec(
                                        'INSERT IGNORE INTO statistics (date) VALUES(CURDATE())'
                                    );
                                }

                                $db->exec(
                                    'UPDATE statistics SET deleted_domains = deleted_domains + 1 WHERE date = CURDATE()'
                                );
                            
                                $db->commit();
                            } catch (Exception $e) {
                                $db->rollBack();
                                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                                return $response->withHeader('Location', '/domain/renew/'.$domainName)->withStatus(302);
                            }
                            $isImmediateDeletion = true;
                        }
                    } elseif ($rgpstatus === 'autoRenewPeriod') {
                        $autoRenewPeriod_id = $db->selectValue(
                            'SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(renewedDate, INTERVAL 45 DAY)) LIMIT 1',
                            [
                                $domain_id
                            ]
                        );
                        if ($autoRenewPeriod_id) {
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $autoRenewPeriod, 'renew');
                            $price = $returnValue['price'];
                            
                            if (!$price) {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }

                            $db->exec(
                                'UPDATE registrar SET accountBalance = accountBalance + ? WHERE id = ?',
                                [$price, $clid]
                            );
                            
                            $description = "domain name is deleted by the registrar during grace autoRenewPeriod, the registry provides a credit for the cost of the renewal domain $domainName for period $autoRenewPeriod MONTH";
                            $db->exec(
                                'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                [$clid, $description, $price]
                            );
                        }
                    } elseif ($rgpstatus === 'renewPeriod') {
                        $renewPeriod_id = $db->selectValue(
                            'SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(renewedDate, INTERVAL 5 DAY)) LIMIT 1',
                            [
                                $domain_id
                            ]
                        );
                        if ($renewPeriod_id) {
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $renewPeriod, 'renew');
                            $price = $returnValue['price'];

                            if (!$price) {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }

                            $db->exec(
                                'UPDATE registrar SET accountBalance = accountBalance + ? WHERE id = ?',
                                [$price, $clid]
                            );
                            
                            $description = "domain name is deleted by the registrar during grace renewPeriod, the registry provides a credit for the cost of the renewal domain $domainName for period $renewPeriod MONTH";
                            $db->exec(
                                'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                [$clid, $description, $price]
                            );
                        }
                    } elseif ($rgpstatus === 'transferPeriod') {
                        $transferPeriod_id = $db->selectValue(
                            'SELECT id FROM domain WHERE id = ? AND (CURRENT_TIMESTAMP(3) < DATE_ADD(trdate, INTERVAL 5 DAY)) LIMIT 1',
                            [
                                $domain_id
                            ]
                        );
                        if ($transferPeriod_id) {
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $transferPeriod, 'renew');
                            $price = $returnValue['price'];
                            
                            if (!$price) {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }

                            $db->exec(
                                'UPDATE registrar SET accountBalance = accountBalance + ? WHERE id = ?',
                                [$price, $clid]
                            );
                            
                            $description = "domain name is deleted by the registrar during grace transferPeriod, the registry provides a credit for the cost of the transfer domain $domainName for period $transferPeriod MONTH";
                            $db->exec(
                                'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                [$clid, $description, $price]
                            );
                        }
                    }
                }
                    
                if ($isImmediateDeletion) {
                    $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' deleted successfully');
                } else {
                    $this->container->get('flash')->addMessage('info', 'Deletion process for domain ' . $domainName . ' has been initiated');
                }
                return $response->withHeader('Location', '/domains')->withStatus(302);
            } else {
                // Redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
        
        //}
    }

    public function listTransfers(Request $request, Response $response)
    {
        $db = $this->container->get('db');
        $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

        if ($_SESSION["auth_roles"] != 0) {
            $clid = $result['registrar_id'];
        } else {
            $clid = 0;
        }
    
        return view($response,'admin/domains/listTransfers.twig', [
            'clid' => $clid
        ]);
    }
    
    public function requestTransfer(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $domainName = $data['domainName'] ?? null;
            $registrar_id = $data['registrar'] ?? null;
            $authInfo = $data['authInfo'] ?? null;
            $transferYears = $data['transferYears'] ?? null;

            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $domain = $db->selectRow('SELECT id, tldid, clid FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);
            
            $domain_id = $domain['id'];
            $tldid = $domain['tldid'];
            $registrar_id_domain = $domain['clid'];
            
            if (!$domain_id) {
                $this->container->get('flash')->addMessage('error', 'Domain does not exist in registry');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $registrar_id;
            }
            
            $days_from_registration = $db->selectValue(
                 'SELECT DATEDIFF(CURRENT_TIMESTAMP(3), crdate) FROM domain WHERE id = ? LIMIT 1',
                [
                    $domain_id
                ]
            );
            
            if ($days_from_registration < 60) {
                $this->container->get('flash')->addMessage('error', 'The domain name must not be within 60 days of its initial registration');
                return $response->withHeader('Location', '/transfer/request')->withStatus(302);
            }
            
            $last_transfer = $db->selectRow(
                 'SELECT trdate, DATEDIFF(CURRENT_TIMESTAMP(3),trdate) AS intval FROM domain WHERE id = ? LIMIT 1',
                [
                    $domain_id
                ]
            );
            $last_trdate = $last_transfer["trdate"];
            $days_from_last_transfer = $last_transfer["intval"];
            
            if ($last_trdate && $days_from_last_transfer < 60) {
                $this->container->get('flash')->addMessage('error', 'The domain name must not be within 60 days of its last transfer from another registrar');
                return $response->withHeader('Location', '/transfer/request')->withStatus(302);
            }

            $days_from_expiry_date = $db->selectValue(
                 'SELECT DATEDIFF(CURRENT_TIMESTAMP(3),exdate) FROM domain WHERE id = ? LIMIT 1',
                [
                    $domain_id
                ]
            );
            
            if ($days_from_expiry_date > 30) {
                $this->container->get('flash')->addMessage('error', 'The domain name must not be more than 30 days past its expiry date');
                return $response->withHeader('Location', '/transfer/request')->withStatus(302);
            }

            $domain_authinfo_id = $db->selectValue(
                 'SELECT id FROM domain_authInfo WHERE domain_id = ? AND authtype = \'pw\' AND authinfo = ? LIMIT 1',
                [
                    $domain_id, $authInfo
                ]
            );
            
            if (!$domain_authinfo_id) {
                $this->container->get('flash')->addMessage('error', 'auth Info pw is not correct');
                return $response->withHeader('Location', '/transfer/request')->withStatus(302);
            }
            
            $results = $db->select(
                'SELECT status FROM domain_status WHERE domain_id = ?',
                [ $domain_id ]
            );
            foreach ($results as $row) {
                $status = $row['status'];
                if (preg_match('/.*(TransferProhibited)$/', $status) || preg_match('/^pending/', $status)) {
                    $this->container->get('flash')->addMessage('error', 'It has a status that does not allow the transfer');
                    return $response->withHeader('Location', '/transfer/request')->withStatus(302);
                }
            }

            if ($clid == $registrar_id_domain) {
                $this->container->get('flash')->addMessage('error', 'Destination client of the transfer operation is the domain sponsoring client');
                return $response->withHeader('Location', '/transfer/request')->withStatus(302);
            }
            
            $domain = $db->selectRow('SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);
            
            $registrant = $domain['registrant'];
            $crdate = $domain['crdate'];
            $exdate = $domain['exdate'];
            $update = $domain['lastupdate'];
            $crid = $domain['crid'];
            $upid = $domain['upid'];
            $trdate = $domain['trdate'];
            $trstatus = $domain['trstatus'];
            $reid = $domain['reid'];
            $redate = $domain['redate'];
            $acid = $domain['acid'];
            $acdate = $domain['acdate'];
            
            if (!$trstatus || $trstatus !== 'pending') {
                
                if (!$transferYears) {
                    $this->container->get('flash')->addMessage('error', 'Please provide a year with the domain transfer');
                    return $response->withHeader('Location', '/transfer/request')->withStatus(302);
                }
                
                $date_add = 0;
                $date_add = $transferYears * 12;

                if ($date_add > 0) {
                    $result = $db->selectRow('SELECT accountBalance, creditLimit FROM registrar WHERE id = ?', [$clid]);
                    $registrar_balance = $result['accountBalance'];
                    $creditLimit = $result['creditLimit'];
                    
                    $returnValue = getDomainPrice($db, $domainName, $tldid, $date_add, 'transfer');
                    $price = $returnValue['price'];

                    if (!$price) {
                        $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                        return $response->withHeader('Location', '/transfer/request')->withStatus(302);
                    }

                    if (($registrar_balance + $creditLimit) < $price) {
                        $this->container->get('flash')->addMessage('error', 'The registrar who wants to take over this domain has no money');
                        return $response->withHeader('Location', '/transfer/request')->withStatus(302);
                    }

                    try {
                        $db->beginTransaction();
                        
                        $waiting_period = 5;
                        $db->exec(
                            'UPDATE domain SET trstatus = ?, reid = ?, redate = CURRENT_TIMESTAMP(3), acid = ?, acdate = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL ? DAY), transfer_exdate = DATE_ADD(exdate, INTERVAL ? MONTH) WHERE id = ?',
                            ['pending', $clid, $registrar_id_domain, $waiting_period, $date_add, $domain_id]
                        );

                        $result = $db->selectRow('SELECT id, registrant, crdate, exdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = ? LIMIT 1',
                        [ $domainName ]);
                        
                        list($domain_id, $registrant, $crdate, $exdate, $registrar_id_domain, $crid, $upid, $trdate, $trstatus, $reid, $redate, $acid, $acdate, $transfer_exdate) = array_values($result);

                        $reid_identifier = $db->selectValue(
                            'SELECT clid FROM registrar WHERE id = ? LIMIT 1',
                            [$reid]
                        );
                        
                        $acid_identifier = $db->selectValue(
                            'SELECT clid FROM registrar WHERE id = ? LIMIT 1',
                            [$acid]
                        );
                        
                        $currentDateTime = new \DateTime();
                        $qdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
                        
                        // The current sponsoring registrar will receive a notification of a pending transfer
                        $db->insert('poll', [
                            'registrar_id' => $registrar_id_domain,
                            'qdate' => $qdate,
                            'msg' => 'Transfer requested.',
                            'msg_type' => 'domainTransfer',
                            'obj_name_or_id' => $domainName,
                            'obj_trStatus' => 'pending',
                            'obj_reID' => $reid_identifier,
                            'obj_reDate' => $redate,
                            'obj_acID' => $acid_identifier,
                            'obj_acDate' => $acdate,
                            'obj_exDate' => $transfer_exdate
                        ]);
                    
                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                        return $response->withHeader('Location', '/transfer/request/')->withStatus(302);
                    }
                                      
                    $this->container->get('flash')->addMessage('info', 'Transfer for ' . $domainName . ' has been started successfully on ' . $qdate . ' An action is pending');
                    return $response->withHeader('Location', '/transfers')->withStatus(302);
                } else {
                    try {
                        $db->beginTransaction();
                        
                        $waiting_period = 5;
                        $db->exec(
                            'UPDATE domain SET trstatus = ?, reid = ?, redate = CURRENT_TIMESTAMP(3), acid = ?, acdate = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL ? DAY), transfer_exdate = NULL WHERE id = ?',
                            ['pending', $clid, $registrar_id_domain, $waiting_period, $domain_id]
                        );

                        $result = $db->selectRow('SELECT id, registrant, crdate, exdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = ? LIMIT 1',
                        [ $domainName ]);
                        
                        list($domain_id, $registrant, $crdate, $exdate, $registrar_id_domain, $crid, $upid, $trdate, $trstatus, $reid, $redate, $acid, $acdate, $transfer_exdate) = array_values($result);

                        $reid_identifier = $db->selectValue(
                            'SELECT clid FROM registrar WHERE id = ? LIMIT 1',
                            [$reid]
                        );
                        
                        $acid_identifier = $db->selectValue(
                            'SELECT clid FROM registrar WHERE id = ? LIMIT 1',
                            [$acid]
                        );
                        
                        $currentDateTime = new \DateTime();
                        $qdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                        // The current sponsoring registrar will receive a notification of a pending transfer
                        $db->insert('poll', [
                            'registrar_id' => $registrar_id_domain,
                            'qdate' => $qdate,
                            'msg' => 'Transfer requested.',
                            'msg_type' => 'domainTransfer',
                            'obj_name_or_id' => $domainName,
                            'obj_trStatus' => 'pending',
                            'obj_reID' => $reid_identifier,
                            'obj_reDate' => $redate,
                            'obj_acID' => $acid_identifier,
                            'obj_acDate' => $acdate,
                            'obj_exDate' => $transfer_exdate
                        ]);
                    
                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                        return $response->withHeader('Location', '/transfer/request/')->withStatus(302);
                    }
                                      
                    $this->container->get('flash')->addMessage('info', 'Transfer for ' . $domainName . ' has been started successfully on ' . $qdate . ' An action is pending');
                    return $response->withHeader('Location', '/transfers')->withStatus(302);
                }
            } elseif ($trstatus === 'pending') {
                $this->container->get('flash')->addMessage('error', 'Command failed as the domain is pending transfer');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
        }
        
        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
            
        $locale = (isset($_SESSION['_lang']) && !empty($_SESSION['_lang'])) ? $_SESSION['_lang'] : 'en_US';
        $currency = $_SESSION['_currency'] ?? 'USD'; // Default to USD if not set

        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currency);

        $symbol = $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
        $pattern = $formatter->getPattern();

        // Determine currency position (before or after)
        $position = (strpos($pattern, '¤') < strpos($pattern, '#')) ? 'before' : 'after';

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/domains/requestTransfer.twig', [
            'registrars' => $registrars,
            'currencySymbol' => $symbol,
            'currencyPosition' => $position,
        ]);
    }
    
    public function approveTransfer(Request $request, Response $response, $args)
    {
       //if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            
            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $domainName = $args ?? null;
            }

            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $domain = $db->selectRow('SELECT id, tldid, clid FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);
            
            $domain_id = $domain['id'];
            $tldid = $domain['tldid'];
            $registrar_id_domain = $domain['clid'];
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
            }

            if ($clid !== $registrar_id_domain) {
                $this->container->get('flash')->addMessage('error', 'Only LOSING REGISTRAR can approve');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
        
            $domain = $db->selectRow('SELECT id, registrant, crdate, exdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, rgpstatus, addPeriod, autoRenewPeriod, renewPeriod, renewedDate, transferPeriod, transfer_exdate FROM domain WHERE name = ?',
            [ $domainName ]);
            
            $domain_id = $domain['id'];
            $registrant = $domain['registrant'];
            $crdate = $domain['crdate'];
            $exdate = $domain['exdate'];
            $registrar_id_domain = $domain['clid'];
            $crid = $domain['crid'];
            $upid = $domain['upid'];
            $trdate = $domain['trdate'];
            $trstatus = $domain['trstatus'];
            $reid = $domain['reid'];
            $redate = $domain['redate'];
            $acid = $domain['acid'];
            $acdate = $domain['acdate'];
            $rgpstatus = $domain['rgpstatus'];
            $addPeriod = $domain['addPeriod'];
            $autoRenewPeriod = $domain['autoRenewPeriod'];
            $renewPeriod = $domain['renewPeriod'];
            $renewedDate = $domain['renewedDate'];
            $transferPeriod = $domain['transferPeriod'];
            $transfer_exdate = $domain['transfer_exdate'];
            
            if ($domain && $trstatus === 'pending') {
                $date_add = 0;
                $price = 0;
                
                $result = $db->selectRow('SELECT accountBalance, creditLimit FROM registrar WHERE id = ?', [$reid]);
                $registrar_balance = $result['accountBalance'];
                $creditLimit = $result['creditLimit'];
                
                if ($transfer_exdate) {
                    $date_add = $db->selectValue(
                         "SELECT PERIOD_DIFF(DATE_FORMAT(transfer_exdate, '%Y%m'), DATE_FORMAT(exdate, '%Y%m')) AS intval FROM domain WHERE name = ? LIMIT 1",
                        [
                            $domainName
                        ]
                    );
                    
                    $returnValue = getDomainPrice($db, $domainName, $tldid, $date_add, 'transfer');
                    $price = $returnValue['price'];
                    
                    if (($registrar_balance + $creditLimit) < $price) {
                        $this->container->get('flash')->addMessage('error', 'The registrar who took over this domain has no money to pay the renewal period that resulted from the transfer request');
                        return $response->withHeader('Location', '/transfers')->withStatus(302);
                    }
                }

                try {
                    $db->beginTransaction();
                    
                    $row = $db->selectRow(
                        'SELECT exdate FROM domain WHERE name = ? LIMIT 1',
                        [$domainName]
                    );
                    $from = $row['exdate'];
                            
                    $db->exec(
                        'UPDATE domain SET exdate = DATE_ADD(exdate, INTERVAL ? MONTH), lastupdate = CURRENT_TIMESTAMP(3), clid = ?, upid = ?, trdate = CURRENT_TIMESTAMP(3), trstatus = ?, acdate = CURRENT_TIMESTAMP(3), transfer_exdate = NULL, rgpstatus = ?, transferPeriod = ? WHERE id = ?',
                        [$date_add, $reid, $clid, 'clientApproved', 'transferPeriod', $date_add, $domain_id]
                    );

                    $db->exec(
                        'UPDATE host SET clid = ?, upid = ?, lastupdate = CURRENT_TIMESTAMP(3), trdate = CURRENT_TIMESTAMP(3) WHERE domain_id = ?',
                        [$reid, $clid, $domain_id]
                    );

                    $db->exec(
                        'UPDATE registrar SET accountBalance = accountBalance - ? WHERE id = ?',
                        [$price, $reid]
                    );
                    
                    $db->exec(
                        'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                        [$reid, "transfer domain $domainName for period $date_add MONTH", "-$price"]
                    );

                    $row = $db->selectRow(
                        'SELECT exdate FROM domain WHERE name = ? LIMIT 1',
                        [$domainName]
                    );
                    $to = $row['exdate'];

                    $currentDateTime = new \DateTime();
                    $stdate = $currentDateTime->format('Y-m-d H:i:s.v');
                    $db->insert(
                        'statement',
                        [
                            'registrar_id' => $reid,
                            'date' => $stdate,
                            'command' => 'transfer',
                            'domain_name' => $domainName,
                            'length_in_months' => $date_add,
                            'fromS' => $from,
                            'toS' => $to,
                            'amount' => $price
                        ]
                      );

                    $curdate_id = $db->selectValue(
                        'SELECT id FROM statistics WHERE date = CURDATE()'
                    );

                    if (!$curdate_id) {
                        $db->exec(
                            'INSERT IGNORE INTO statistics (date) VALUES(CURDATE())'
                        );
                    }

                    $db->exec(
                        'UPDATE statistics SET transfered_domains = transfered_domains + 1 WHERE date = CURDATE()'
                    );

                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                    return $response->withHeader('Location', '/transfers')->withStatus(302);
                }

                $this->container->get('flash')->addMessage('success', 'Transfer for ' . $domainName . ' has been completed');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'The domain is NOT pending transfer');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
        //}
    }
    
    public function rejectTransfer(Request $request, Response $response, $args)
    {
        //if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            
            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $domainName = $args ?? null;
            }

            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $domain = $db->selectRow('SELECT id, tldid, clid FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);
            
            $domain_id = $domain['id'];
            $tldid = $domain['tldid'];
            $registrar_id_domain = $domain['clid'];
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
            }

            if ($clid !== $registrar_id_domain) {
                $this->container->get('flash')->addMessage('error', 'Only LOSING REGISTRAR can reject');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
          
            $domain = $db->selectRow('SELECT id, trstatus FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);

            $trstatus = $domain['trstatus'];
            
            if ($trstatus === 'pending') {
                $db->update('domain', [
                    'trstatus' => 'clientRejected'
                ],
                [
                    'name' => $domainName
                ]
                );
                
                $this->container->get('flash')->addMessage('success', 'Transfer for ' . $domainName . ' has been rejected successfully');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'The domain is NOT pending transfer');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
        //}
    }
    
    public function cancelTransfer(Request $request, Response $response, $args)
    {
        //if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $db = $this->container->get('db');

            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $domainName = $args ?? null;
            }

            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $domain = $db->selectRow('SELECT id, tldid, clid FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);
            
            $domain_id = $domain['id'];
            $tldid = $domain['tldid'];
            $registrar_id_domain = $domain['clid'];
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
            }

            if ($clid === $registrar_id_domain) {
                $this->container->get('flash')->addMessage('error', 'Only the APPLICANT can cancel');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
         
            $domain = $db->selectRow('SELECT id, trstatus FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);

            $trstatus = $domain['trstatus'];
            
            if ($trstatus === 'pending') {
                $db->update('domain', [
                    'trstatus' => 'clientCancelled'
                ],
                [
                    'name' => $domainName
                ]
                );
                
                $this->container->get('flash')->addMessage('success', 'Transfer for ' . $domainName . ' has been cancelled successfully');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'The domain is NOT pending transfer');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
        //}
    }
    
    public function restoreDomain(Request $request, Response $response, $args)
    {
        //if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $db = $this->container->get('db');

            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $domainName = $args ?? null;
            }

            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $temp_id_rgpstatus = $db->selectValue(
                'SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = ? AND name = ? LIMIT 1',
                ['redemptionPeriod', $domainName]
            );

            if ($temp_id_rgpstatus == 0) {
                $this->container->get('flash')->addMessage('error', 'pendingRestore can only be done if the domain is now in redemptionPeriod rgpStatus');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
            
            $domain_id = $db->selectValue(
                'SELECT id FROM domain WHERE name = ?',
                [$domainName]
            );
            
            $temp_id_status = $db->selectValue(
                'SELECT COUNT(id) AS ids FROM domain_status WHERE status = ? AND domain_id = ? LIMIT 1',
                ['pendingDelete', $domain_id]
            );
            
            if ($temp_id_status == 0) {
                $this->container->get('flash')->addMessage('error', 'pendingRestore can only be done if the domain is now in pendingDelete status');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

            $temp_id = $db->selectValue(
                'SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = ? AND id = ?',
                ['redemptionPeriod', $domain_id]
            );
            
            if ($temp_id == 1) {
                $currentDateTime = new \DateTime();
                $date = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
                
                $db->update('domain', [
                    'rgpstatus' => 'pendingRestore',
                    'resTime' => $date,
                    'lastupdate' => $date
                ],
                [
                    'id' => $domain_id
                ]
                );
                
                $this->container->get('flash')->addMessage('info', 'Restore process for ' . $domainName . ' has started successfully');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'pendingRestore can only be done if the domain is now in redemptionPeriod');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
        //}
    }
    
    public function reportDomain(Request $request, Response $response, $args)
    {
        //if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $db = $this->container->get('db');

            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $domainName = $args ?? null;
            }
            
            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $temp_id = $db->selectValue(
                'SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = ? AND name = ? LIMIT 1',
                ['pendingRestore', $domainName]
            );
            
            if ($temp_id == 0) {
                $this->container->get('flash')->addMessage('error', 'report can only be sent if the domain is in pendingRestore status');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
            
            $temp_id = $db->selectValue(
                'SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = ? AND name = ?',
                ['pendingRestore', $domainName]
            );
            
            if ($temp_id == 1) {
                $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                if ($_SESSION["auth_roles"] != 0) {
                    $clid = $result['registrar_id'];
                } else {
                    $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
                }
                
                $domain = $db->selectRow('SELECT tldid, exdate FROM domain WHERE name = ? LIMIT 1',
                [ $domainName ]);
                $tldid = $domain['tldid'];
                
                $result = $db->selectRow('SELECT accountBalance, creditLimit FROM registrar WHERE id = ?', [$clid]);

                $registrar_balance = $result['accountBalance'];
                $creditLimit = $result['creditLimit'];

                $renew_price = $db->selectValue(
                    'SELECT m12 FROM domain_price WHERE tldid = ? AND command = ? LIMIT 1',
                    [$tldid, 'renew']
                );
                
                $restore_price = $db->selectValue(
                    'SELECT price FROM domain_restore_price WHERE tldid = ? LIMIT 1',
                    [$tldid]
                );

                if (($registrar_balance + $creditLimit) < ($renew_price + $restore_price)) {
                    $this->container->get('flash')->addMessage('error', 'There is no money on the account for restore and renew');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $from = $domain['exdate'];
                
                try {
                    $db->beginTransaction();
                            
                    $db->exec(
                        'UPDATE domain SET exdate = DATE_ADD(exdate, INTERVAL 12 MONTH), rgpstatus = NULL, rgpresTime = CURRENT_TIMESTAMP(3), lastupdate = CURRENT_TIMESTAMP(3) WHERE name = ?',
                        [
                            $domainName
                        ]
                    );
                    $domain_id = $db->selectValue(
                        'SELECT id FROM domain WHERE name = ?',
                        [$domainName]
                    );

                    $db->delete(
                        'domain_status',
                        [
                            'domain_id' => $domain_id,
                            'status' => 'pendingDelete'
                        ]
                    );

                    $db->exec(
                        'UPDATE registrar SET accountBalance = (accountBalance - ? - ?) WHERE id = ?',
                        [$renew_price, $restore_price, $clid]
                    );
                    
                    $db->exec(
                        'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                        [$clid, "restore domain $domainName", "-$restore_price"]
                    );
                    
                    $db->exec(
                        'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                        [$clid, "renew domain $domainName for period 12 MONTH", "-$renew_price"]
                    );

                    $row = $db->selectRow(
                        'SELECT exdate FROM domain WHERE name = ? LIMIT 1',
                        [$domainName]
                    );
                    $to = $row['exdate'];

                    $currentDateTime = new \DateTime();
                    $stdate = $currentDateTime->format('Y-m-d H:i:s.v');
                    $db->insert(
                        'statement',
                        [
                            'registrar_id' => $clid,
                            'date' => $stdate,
                            'command' => 'restore',
                            'domain_name' => $domainName,
                            'length_in_months' => 0,
                            'fromS' => $from,
                            'toS' => $from,
                            'amount' => $restore_price
                        ]
                      );
                      
                    $db->insert(
                        'statement',
                        [
                            'registrar_id' => $clid,
                            'date' => $stdate,
                            'command' => 'renew',
                            'domain_name' => $domainName,
                            'length_in_months' => 12,
                            'fromS' => $from,
                            'toS' => $to,
                            'amount' => $renew_price
                        ]
                      );

                    $curdate_id = $db->selectValue(
                        'SELECT id FROM statistics WHERE date = CURDATE()'
                    );

                    if (!$curdate_id) {
                        $db->exec(
                            'INSERT IGNORE INTO statistics (date) VALUES(CURDATE())'
                        );
                    }

                    $db->exec(
                        'UPDATE statistics SET restored_domains = restored_domains + 1 WHERE date = CURDATE()'
                    );
                    
                    $db->exec(
                        'UPDATE statistics SET renewed_domains = renewed_domains + 1 WHERE date = CURDATE()'
                    );
                     
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'Database failure during restore: ' . $e->getMessage());
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
               
                $this->container->get('flash')->addMessage('success','Domain ' . $domainName . ' has been restored successfully');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'report can only be sent if the domain is in pendingRestore status');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
        }

}