<?php

namespace App\Controllers;

use App\Models\Domain;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class DomainsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        return view($response,'admin/domains/view.twig');
    }
    
    public function check(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $domainName = $data['domain_name'] ?? null;

            if ($domainName) {
                $domainModel = new Domain($this->container->get('db'));
                $availability = $domainModel->getDomainByName($domainName);

                // Convert the DB result into a boolean '0' or '1'
                $availability = $availability ? '0' : '1';
                
                $invalid_label = validate_label($domainName, $this->container->get('db'));
                
                // Check if the domain is Invalid
                if ($invalid_label) {
                    $status = $invalid_label;
                    $isAvailable = 0;
                } else {
                    $isAvailable = $availability;
                    $status = null; 

                    // Check if the domain is unavailable
                    if ($availability === '0') {
                        $status = 'In use';
                    }
                }

                return view($response, 'admin/domains/check.twig', [
                    'isAvailable' => $isAvailable,
                    'domainName' => $domainName,
                    'status' => $status,
                ]);
            }
        }

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/domains/check.twig');
    }
    
    public function create(Request $request, Response $response)
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
            
            $nameservers = $data['nameserver'] ?? [];

            $dsKeyTag = $data['dsKeyTag'] ?? null;
            $dsAlg = $data['dsAlg'] ?? null;
            $dsDigestType = $data['dsDigestType'] ?? null;
            $dsDigest = $data['dsDigest'] ?? null;
            
            $dnskeyFlags = $data['dnskeyFlags'] ?? null;
            $dnskeyProtocol = $data['dnskeyProtocol'] ?? null;
            $dnskeyAlg = $data['dnskeyAlg'] ?? null;
            $dnskeyPubKey = $data['dnskeyPubKey'] ?? null;
            
            $authInfo = $data['authInfo'] ?? null;
            
            list($label, $domain_extension) = explode('.', $domainName, 2);
            $invalid_domain = validate_label($domainName, $db);

            if ($invalid_domain) {
                return view($response, 'admin/domains/create.twig', [
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
                return view($response, 'admin/domains/create.twig', [
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
                return view($response, 'admin/domains/create.twig', [
                    'domainName' => $domainName,
                    'error' => 'Domain name already exists',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            $domain_already_reserved = $db->selectValue(
                'SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1',
                [$domainName]
            );

            if ($domain_already_reserved) {
                return view($response, 'admin/domains/create.twig', [
                    'domainName' => $domainName,
                    'error' => 'Domain name is reserved or restricted',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            if ($registrationYears && (($registrationYears < 1) || ($registrationYears > 10))) {
                return view($response, 'admin/domains/create.twig', [
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

            $priceColumn = "m" . $date_add;
            $price = $db->selectValue(
                'SELECT ' . $db->quoteIdentifier($priceColumn) . ' FROM domain_price WHERE tldid = ? AND command = "create" LIMIT 1',
                [$tld_id]
            );

            if (!$price) {
                return view($response, 'admin/domains/create.twig', [
                    'domainName' => $domainName,
                    'error' => 'The price, period and currency for such TLD are not declared',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            if (($registrar_balance + $creditLimit) < $price) {
                return view($response, 'admin/domains/create.twig', [
                    'domainName' => $domainName,
                    'error' => 'Low credit: minimum threshold reached',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            if (count($nameservers) !== count(array_unique($nameservers))) {
                return view($response, 'admin/domains/create.twig', [
                    'domainName' => $domainName,
                    'error' => 'Duplicate nameservers detected. Please provide unique nameservers.',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }
            
            foreach ($nameservers as $index => $nameserver) {
                if (preg_match("/^-|^\.-|-\.$|^\.$/", $nameserver)) {
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Invalid hostName',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
                
                if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $nameserver) && strlen($nameserver) < 254) {
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Invalid hostName',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
            }
            
            if ($contactRegistrant) {
                $validRegistrant = validate_identifier($contactRegistrant);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactRegistrant]);

                if (!$row) {
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Registrant does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/create.twig', [
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
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Admin contact does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/create.twig', [
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
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Tech contact does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/create.twig', [
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
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Billing contact does not exist',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if ($clid != $row['clid']) {
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'The contact requested in the command does NOT belong to the current registrar',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
            }
            
            if (!$authInfo) {
                return view($response, 'admin/domains/create.twig', [
                    'domainName' => $domainName,
                    'error' => 'Missing domain authinfo',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            if (strlen($authInfo) < 6 || strlen($authInfo) > 16) {
                return view($response, 'admin/domains/create.twig', [
                    'domainName' => $domainName,
                    'error' => 'Password needs to be at least 6 and up to 16 characters long',
                    'registrars' => $registrars,
                    'registrar' => $registrar,
                ]);
            }

            if (!preg_match('/[A-Z]/', $authInfo)) {
                return view($response, 'admin/domains/create.twig', [
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
                    'update' => null,
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
                        return view($response, 'admin/domains/create.twig', [
                            'domainName' => $domainName,
                            'error' => 'Incomplete key tag provided',
                            'registrars' => $registrars,
                            'registrar' => $registrar,
                        ]);
                    }
                
                    if ($dsKeyTag < 0 || $dsKeyTag > 65535) {
                        return view($response, 'admin/domains/create.twig', [
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
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Incomplete algorithm provided',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                // Validate digestType and digest
                if (!empty($dsDigestType) && !is_int($dsDigestType)) {
                    return view($response, 'admin/domains/create.twig', [
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
                if (!empty($validDigests[$dsDigestType])) {
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Unsupported digest type',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }
                if (!empty($dsDigest)) {
                    if (strlen($dsDigest) != $validDigests[$dsDigestType] || !ctype_xdigit($dsDigest)) {
                        return view($response, 'admin/domains/create.twig', [
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
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Invalid flags provided',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                // Validate protocol
                if (!empty($dnskeyProtocol) && $dnskeyProtocol != 3) {
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Invalid protocol provided',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                // Validate algKeyData
                if (!empty($dnskeyAlg)) {
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Invalid algorithm encoding',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                // Validate pubKey
                if (!empty($dnskeyPubKey) && base64_encode(base64_decode($dnskeyPubKey, true)) !== $dnskeyPubKey) {
                    return view($response, 'admin/domains/create.twig', [
                        'domainName' => $domainName,
                        'error' => 'Invalid public key encoding',
                        'registrars' => $registrars,
                        'registrar' => $registrar,
                    ]);
                }

                if (!empty($dsKeyTag) || !empty($dnskeyFlags)) {
                    $db->insert('secdns', [
                        'domain_id' => $domain_id,
                        'maxsiglife' => $maxSigLife,
                        'interface' => 'dsData',
                        'keytag' => $dsKeyTag,
                        'alg' => $dsAlg,
                        'digesttype' => $dsDigestType,
                        'digest' => $dsDigest,
                        'flags' => $dnskeyFlags ?? null,
                        'protocol' => $dnskeyProtocol ?? null,
                        'keydata_alg' => $dnskeyAlg ?? null,
                        'pubkey' => $dnskeyPubKey ?? null
                    ]);
                }
                
                $db->exec(
                    'UPDATE registrar SET accountBalance = accountBalance - ? WHERE id = ?',
                    [$price, $clid]
                );

                $db->exec(
                    'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP, ?, ?)',
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
                        'from' => $from,
                        'to' => $to,
                        'amount' => $price
                    ]
                );

                foreach ($nameservers as $index => $nameserver) {
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
                        $host_id = $db->insert(
                            'host',
                            [
                                'name' => $nameserver,
                                'domain_id' => $domain_id,
                                'clid' => $clid,
                                'crid' => $clid,
                                'crdate' => $host_date
                            ]
                        );

                        $db->insert(
                            'domain_host_map',
                            [
                                'domain_id' => $domain_id,
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
                return view($response, 'admin/domains/create.twig', [
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
            
            return view($response, 'admin/domains/create.twig', [
                'domainName' => $domainName,
                'crdate' => $crdate,
                'registrars' => $registrars,
                'registrar' => $registrar,
            ]);
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
        return view($response,'admin/domains/create.twig', [
            'registrars' => $registrars,
            'currencySymbol' => $symbol,
            'currencyPosition' => $position,
            'registrar' => $registrar,
        ]);
    }
    
    public function transfers(Request $request, Response $response)
    {
        return view($response,'admin/domains/transfers.twig');
    }
    
    public function viewDomain(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $domain = $db->selectRow('SELECT id, name, registrant, crdate, exdate, `update`, clid, idnlang, rgpstatus FROM domain WHERE name = ?',
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
                // Contact does not exist, redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

        } else {
            // Redirect to the domains view
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

    }

}