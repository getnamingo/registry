<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Selective\XmlDSig\PublicKeyStore;
use Selective\XmlDSig\CryptoVerifier;
use Selective\XmlDSig\XmlSignatureVerifier;

class ApplicationsController extends Controller
{

    public function listApplications(Request $request, Response $response)
    {
        $db = $this->container->get('db');
        $launch_phases = $db->selectValue("SELECT value FROM settings WHERE name = 'launch_phases'");
        if ($launch_phases == 'on') {
            return view($response,'admin/domains/listApplications.twig');
        } else {
            $this->container->get('flash')->addMessage('info', 'Applications are disabled.');
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }
    }

    public function createApplication(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $domainName = $data['domainName'] ?? null;
            // Convert to Punycode if the domain is not in ASCII
            if (!mb_detect_encoding($domainName, 'ASCII', true)) {
                $convertedDomain = idn_to_ascii($domainName, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                if ($convertedDomain === false) {
                    $this->container->get('flash')->addMessage('error', 'Application name conversion to Punycode failed');
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                } else {
                    $domainName = $convertedDomain;
                }
            }
            $registrar_id = $data['registrar'] ?? null;
            $registrars = $db->select("SELECT id, clid, name FROM registrar");
            if ($_SESSION["auth_roles"] != 0) {
                $registrar = true;
            } else {
                $registrar = null;
            }
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $registrar_id;
            }
      
            $contactRegistrant = $data['contactRegistrant'] ?? null;
            $contactAdmin = $data['contactAdmin'] ?? null;
            $contactTech = $data['contactTech'] ?? null;
            $contactBilling = $data['contactBilling'] ?? null;
            
            $phaseType = $data['phaseType'] ?? null;
            $phaseName = $data['phaseName'] ?? null;
            $smd = $data['smd'] ?? null;
            
            $nameservers = !empty($data['nameserver']) ? $data['nameserver'] : null;
            $nameserver_ipv4 = !empty($data['nameserver_ipv4']) ? $data['nameserver_ipv4'] : null;
            $nameserver_ipv6 = !empty($data['nameserver_ipv6']) ? $data['nameserver_ipv6'] : null;

            $authInfo = $data['authInfo'] ?? null;
            $invalid_domain = validate_label($domainName, $db);

            if ($invalid_domain) {
                $this->container->get('flash')->addMessage('error', 'Error creating application: Invalid domain name');
                return $response->withHeader('Location', '/application/create')->withStatus(302);
            }

            $parts = extractDomainAndTLD($domainName);
            $label = $parts['domain'];
            $domain_extension = $parts['tld'];
            
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
                $this->container->get('flash')->addMessage('error', 'Error creating application: Invalid domain extension');
                return $response->withHeader('Location', '/application/create')->withStatus(302);
            }

            $domain_already_exist = $db->selectValue(
                'SELECT id FROM application WHERE name = ? and clid = ? and phase_type = ? LIMIT 1',
                [$domainName, $clid, $phaseType]
            );

            if ($domain_already_exist) {
                $this->container->get('flash')->addMessage('error', 'Error creating application: Application already exists');
                return $response->withHeader('Location', '/application/create')->withStatus(302);
            }
            
            $currentDateTime = new \DateTime();
            $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

            $phase_details = $db->selectValue(
                "SELECT phase_category 
                 FROM launch_phases 
                 WHERE tld_id = ? 
                 AND phase_type = ?
                 AND start_date <= ? 
                 AND (end_date >= ? OR end_date IS NULL OR end_date = '') 
                 ",
                [$tld_id, $phaseType, $currentDate, $currentDate]
            );

            if ($phase_details !== 'Application') {
                $this->container->get('flash')->addMessage('error', 'Error creating application: The launch phase ' . $phaseType . ' is improperly configured. Please check the settings or contact support.');
                return $response->withHeader('Location', '/application/create')->withStatus(302);
            }
            
            if ($phaseType === 'claims') {
                if (!isset($data['noticeid']) || $data['noticeid'] === '' ||
                    !isset($data['notafter']) || $data['notafter'] === '' ||
                    !isset($data['accepted']) || $data['accepted'] === '') {
                    $this->container->get('flash')->addMessage('error', "Error creating application: 'noticeid', 'notafter', or 'accepted' cannot be empty when phaseType is 'claims'");
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                }

                $noticeid = $data['noticeid'];
                $notafter = $data['notafter'];
                $accepted = $data['accepted'];
            } else {
                $noticeid = null;
                $notafter = null;
                $accepted = null;
            }
            
            if ($phaseType === 'sunrise') {
                if ($smd !== null && $smd !== '') {
                    // Extract the BASE64 encoded part
                    $beginMarker = "-----BEGIN ENCODED SMD-----";
                    $endMarker = "-----END ENCODED SMD-----";
                    $beginPos = strpos($smd, $beginMarker) + strlen($beginMarker);
                    $endPos = strpos($smd, $endMarker);
                    $encodedSMD = trim(substr($smd, $beginPos, $endPos - $beginPos));

                    // Decode the BASE64 content
                    $xmlContent = base64_decode($encodedSMD);

                    // Load the XML content using DOMDocument
                    $domDocument = new \DOMDocument();
                    $domDocument->preserveWhiteSpace = false;
                    $domDocument->formatOutput = true;
                    $domDocument->loadXML($xmlContent);

                    // Parse data
                    $xpath = new \DOMXPath($domDocument);
                    $xpath->registerNamespace('smd', 'urn:ietf:params:xml:ns:signedMark-1.0');
                    $xpath->registerNamespace('mark', 'urn:ietf:params:xml:ns:mark-1.0');

                    $notBefore = new \DateTime($xpath->evaluate('string(//smd:notBefore)'));
                    $notAfter = new \DateTime($xpath->evaluate('string(//smd:notAfter)'));
                    $markName = $xpath->evaluate('string(//mark:markName)');
                    $labels = [];
                    foreach ($xpath->query('//mark:label') as $x_label) {
                        $labels[] = $x_label->nodeValue;
                    }

                    if (!in_array($label, $labels)) {
                        $this->container->get('flash')->addMessage('error', 'Error creating application: SMD file is not valid for the application being created');
                        return $response->withHeader('Location', '/application/create')->withStatus(302);
                    }

                    // Check if current date and time is between notBefore and notAfter
                    $now = new \DateTime();
                    if (!($now >= $notBefore && $now <= $notAfter)) {
                        $this->container->get('flash')->addMessage('error', 'Error creating application: Current time is outside the valid range in the SMD file');
                        return $response->withHeader('Location', '/application/create')->withStatus(302);
                    }

                    // Verify the signature
                    $publicKeyStore = new PublicKeyStore();
                    $publicKeyStore->loadFromDocument($domDocument);
                    $cryptoVerifier = new CryptoVerifier($publicKeyStore);
                    $xmlSignatureVerifier = new XmlSignatureVerifier($cryptoVerifier);
                    $isValid = $xmlSignatureVerifier->verifyXml($xmlContent);

                    if (!$isValid) {
                        $this->container->get('flash')->addMessage('error', 'Error creating application: The XML signature of the SMD file is not valid');
                        return $response->withHeader('Location', '/application/create')->withStatus(302);
                    }
                } else {
                    $this->container->get('flash')->addMessage('error', "Error creating application: SMD upload is required in the 'sunrise' phase.");
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                }
            }

            $domain_already_reserved = $db->selectValue(
                'SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1',
                [$label]
            );

            if ($domain_already_reserved) {
                $this->container->get('flash')->addMessage('error', 'Error creating application: Domain name in application is reserved or restricted');
                return $response->withHeader('Location', '/application/create')->withStatus(302);
            }
       
            $date_add = 0;

            $result = $db->selectRow('SELECT accountBalance, creditLimit FROM registrar WHERE id = ?', [$clid]);

            $registrar_balance = $result['accountBalance'];
            $creditLimit = $result['creditLimit'];
            
            $returnValue = getDomainPrice($db, $domainName, $tld_id, $date_add, 'create', $clid);
            $price = $returnValue['price'];

            if (!$price) {
                $this->container->get('flash')->addMessage('error', 'Error creating application: The price, period and currency for such TLD are not declared');
                return $response->withHeader('Location', '/application/create')->withStatus(302);
            }

            if (($registrar_balance + $creditLimit) < $price) {
                $this->container->get('flash')->addMessage('error', 'Error creating application: Low credit: minimum threshold reached');
                return $response->withHeader('Location', '/application/create')->withStatus(302);
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
                    $this->container->get('flash')->addMessage('error', 'Error creating application: Duplicate nameservers detected. Please provide unique nameservers.');
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                }
                
                foreach ($nameservers as $index => $nameserver) {
                    if (preg_match("/^-|^\.-|-\.$|^\.$/", $nameserver)) {
                        $this->container->get('flash')->addMessage('error', 'Error creating application: Invalid hostName');
                        return $response->withHeader('Location', '/application/create')->withStatus(302);
                    }
                    
                    if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $nameserver) && strlen($nameserver) < 254) {
                        $this->container->get('flash')->addMessage('error', 'Error creating application: Invalid hostName');
                        return $response->withHeader('Location', '/application/create')->withStatus(302);
                    }
                }
            }
            
            if ($contactRegistrant) {
                $validRegistrant = validate_identifier($contactRegistrant);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactRegistrant]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Error creating application: Registrant does not exist');
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'Error creating application: The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                }
            }
            
            if ($contactAdmin) {
                $validAdmin = validate_identifier($contactAdmin);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactAdmin]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Error creating application: Admin contact does not exist');
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'Error creating application: The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                }
            }
            
            if ($contactTech) {
                $validTech = validate_identifier($contactTech);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactTech]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Error creating application: Tech contact does not exist');
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'Error creating application: The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                }
            }
            
            if ($contactBilling) {
                $validBilling = validate_identifier($contactBilling);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactBilling]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Error creating application: Billing contact does not exist');
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'Error creating application: The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/application/create')->withStatus(302);
                }
            }
            
            if (!$authInfo) {
                $this->container->get('flash')->addMessage('error', 'Error creating application: Missing application authinfo');
                return $response->withHeader('Location', '/application/create')->withStatus(302);
            }

            if (strlen($authInfo) < 6 || strlen($authInfo) > 16) {
                $this->container->get('flash')->addMessage('error', 'Error creating application: Password needs to be at least 6 and up to 16 characters long');
                return $response->withHeader('Location', '/application/create')->withStatus(302);
            }

            if (!preg_match('/[A-Z]/', $authInfo)) {
                $this->container->get('flash')->addMessage('error', 'Error creating application: Password should have both upper and lower case characters');
                return $response->withHeader('Location', '/application/create')->withStatus(302);
            }
            
            $registrant_id = $db->selectValue(
                'SELECT id FROM contact WHERE identifier = ? LIMIT 1',
                [$contactRegistrant]
            );

            try {
                $db->beginTransaction();
                
                $currentDateTime = new \DateTime();
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                $db->insert('application', [
                    'name' => $domainName,
                    'tldid' => $tld_id,
                    'registrant' => $registrant_id,
                    'crdate' => $crdate,
                    'exdate' => null,
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
                    'rgpstatus' => null,
                    'addPeriod' => null,
                    'authtype' => 'pw',
                    'authinfo' => $authInfo,
                    'phase_name' => $phaseName,
                    'phase_type' => $phaseType,
                    'smd' => $smd,
                    'tm_notice_id' => $noticeid,
                    'tm_notice_accepted' => $accepted,
                    'tm_notice_expires' => $notafter
                ]);
                $domain_id = $db->getlastInsertId();
                
                $uuid = createUuidFromId($domain_id);
                
                $db->update(
                    'application',
                    [
                        'application_id' => $uuid
                    ],
                    [
                        'id' => $domain_id
                    ]
                );
                
                $db->insert('application_status', [
                    'domain_id' => $domain_id,
                    'status' => 'pendingValidation'
                ]);

                $db->exec(
                    'UPDATE registrar SET accountBalance = accountBalance - ? WHERE id = ?',
                    [$price, $clid]
                );

                $db->exec(
                    'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                    [$clid, "create application for $domainName for period $date_add MONTH", "-$price"]
                );

                $row = $db->selectRow(
                    'SELECT crdate FROM application WHERE name = ? LIMIT 1',
                    [$domainName]
                );
                $from = $row['crdate'];

                $currentDateTime = new \DateTime();
                $stdate = $currentDateTime->format('Y-m-d H:i:s.v');
                $db->insert(
                    'statement',
                    [
                        'registrar_id' => $clid,
                        'date' => $stdate,
                        'command' => 'create',
                        'domain_name' => $domainName,
                        'length_in_months' => 0,
                        'fromS' => $from,
                        'toS' => $from,
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
                                'SELECT domain_id FROM application_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1',
                                [$domain_id, $hostName_already_exist]
                            );

                            if (!$domain_host_map_id) {
                                $db->insert(
                                    'application_host_map',
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
                                        'log' => "Application : $domainName ; hostName : $nameserver - is duplicated",
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
                                'application_host_map',
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
                                    $this->container->get('flash')->addMessage('error', 'Error creating application: No IPv4 or IPv6 addresses provided for internal host');
                                    return $response->withHeader('Location', '/application/create')->withStatus(302);
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
                                'application_contact_map',
                                [
                                    'domain_id' => $domain_id,
                                    'contact_id' => $contact_id,
                                    'type' => $type
                                ]
                            );
                        }
                    }
                }
             
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/application/create')->withStatus(302);
            } catch (\Pinga\Db\Throwable\IntegrityConstraintViolationException $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/application/create')->withStatus(302);
            }
            
            $crdate = $db->selectValue(
                "SELECT crdate FROM application WHERE id = ? LIMIT 1",
                [$domain_id]
            );
            
            $this->container->get('flash')->addMessage('success', 'Application ' . $domainName . ' has been created successfully on ' . $crdate);
            return $response->withHeader('Location', '/applications')->withStatus(302);
        }

        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
        } else {
            $registrar = null;
        }

        // Default view for GET requests or if POST data is not set
        $launch_phases = $db->selectValue("SELECT value FROM settings WHERE name = 'launch_phases'");
        if ($launch_phases == 'on') {
            return view($response,'admin/domains/createApplication.twig', [
                'registrars' => $registrars,
                'registrar' => $registrar,
            ]);
        } else {
            $this->container->get('flash')->addMessage('info', 'Applications are disabled.');
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }
    }
    
    public function viewApplication(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = strtolower(trim($args));

            if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                return $response->withHeader('Location', '/applications')->withStatus(302);
            }
        
            $domain = $db->selectRow('SELECT id, name, registrant, crdate, clid, idnlang, authinfo, authtype, phase_name, phase_type, smd, application_id FROM application WHERE name = ?',
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
                        // Redirect to the applications view if the user is not authorized for this contact
                        return $response->withHeader('Location', '/applications')->withStatus(302);
                    }
                }
                
                $domainRegistrant = $db->selectRow('SELECT identifier FROM contact WHERE id = ?',
                [ $domain['registrant'] ]);
                $domainStatus = $db->select('SELECT status FROM application_status WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainHostsQuery = '
                    SELECT dhm.id, dhm.domain_id, dhm.host_id, h.name
                    FROM application_host_map dhm
                    JOIN host h ON dhm.host_id = h.id
                    WHERE dhm.domain_id = ?';

                $domainHosts = $db->select($domainHostsQuery, [$domain['id']]);
                $domainContactsQuery = '
                    SELECT dcm.id, dcm.domain_id, dcm.contact_id, dcm.type, c.identifier 
                    FROM application_contact_map dcm
                    JOIN contact c ON dcm.contact_id = c.id
                    WHERE dcm.domain_id = ?';
                $domainContacts = $db->select($domainContactsQuery, [$domain['id']]);

                if (strpos($domain['name'], 'xn--') === 0) {
                    $domain['name_o'] = $domain['name'];
                    $domain['name'] = idn_to_utf8($domain['name'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                } else {
                    $domain['name_o'] = $domain['name'];
                }

                return view($response,'admin/domains/viewApplication.twig', [
                    'domain' => $domain,
                    'domainStatus' => $domainStatus,
                    'domainRegistrant' => $domainRegistrant,
                    'domainHosts' => $domainHosts,
                    'domainContacts' => $domainContacts,
                    'registrars' => $registrars,
                    'currentUri' => $uri
                ]);
            } else {
                // Domain does not exist, redirect to the applications view
                return $response->withHeader('Location', '/applications')->withStatus(302);
            }

        } else {
            // Redirect to the applications view
            return $response->withHeader('Location', '/applications')->withStatus(302);
        }

    }
    
    public function updateApplication(Request $request, Response $response, $args)
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
                return $response->withHeader('Location', '/applications')->withStatus(302);
            }

            $domain = $db->selectRow('SELECT id, name, registrant, crdate, phase_name, phase_type, clid, application_id FROM application WHERE name = ?',
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
                        // Redirect to the applications view if the user is not authorized for this contact
                        return $response->withHeader('Location', '/applications')->withStatus(302);
                    }
                }
                
                $domainRegistrant = $db->selectRow('SELECT identifier FROM contact WHERE id = ?',
                [ $domain['registrant'] ]);
                $domainStatus = $db->select('SELECT status FROM application_status WHERE domain_id = ?',
                [ $domain['id'] ]);
                $domainHostsQuery = '
                    SELECT dhm.id, dhm.domain_id, dhm.host_id, h.name
                    FROM application_host_map dhm
                    JOIN host h ON dhm.host_id = h.id
                    WHERE dhm.domain_id = ?';

                $domainHosts = $db->select($domainHostsQuery, [$domain['id']]);
                $domainContactsQuery = '
                    SELECT dcm.id, dcm.domain_id, dcm.contact_id, dcm.type, c.identifier 
                    FROM application_contact_map dcm
                    JOIN contact c ON dcm.contact_id = c.id
                    WHERE dcm.domain_id = ?';
                $domainContacts = $db->select($domainContactsQuery, [$domain['id']]);
                
                $csrfTokenName = $this->container->get('csrf')->getTokenName();
                $csrfTokenValue = $this->container->get('csrf')->getTokenValue();
                
                if (strpos($domain['name'], 'xn--') === 0) {
                    $domain['punycode'] = $domain['name'];
                    $domain['name'] = idn_to_utf8($domain['name'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                } else {
                    $domain['punycode'] = $domain['name'];
                }
                $_SESSION['applications_to_update'] = [$domain['punycode']];

                return view($response,'admin/domains/updateApplication.twig', [
                    'domain' => $domain,
                    'domainStatus' => $domainStatus,
                    'domainRegistrant' => $domainRegistrant,
                    'domainHosts' => $domainHosts,
                    'domainContacts' => $domainContacts,
                    'registrar' => $registrars,
                    'currentUri' => $uri,
                    'csrfTokenName' => $csrfTokenName,
                    'csrfTokenValue' => $csrfTokenValue
               ]);
            } else {
                // Domain does not exist, redirect to the applications view
                return $response->withHeader('Location', '/applications')->withStatus(302);
            }

        } else {
            // Redirect to the applications view
            return $response->withHeader('Location', '/applications')->withStatus(302);
        }
    }
    
    public function updateApplicationProcess(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            if (!empty($_SESSION['applications_to_update'])) {
                $domainName = $_SESSION['applications_to_update'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No application specified for update');
                return $response->withHeader('Location', '/applications')->withStatus(302);
            }
            $domain_id = $db->selectValue('SELECT id FROM application WHERE name = ?', [$domainName]);
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                $domain_clid = $db->selectValue('SELECT clid FROM application WHERE name = ?', [$domainName]);
                if ($domain_clid != $clid) {
                    return $response->withHeader('Location', '/applications')->withStatus(302);
                }
            } else {
                $clid = $db->selectValue('SELECT clid FROM application WHERE name = ?', [$domainName]);
            }
            
            $results = $db->select(
                'SELECT status FROM application_status WHERE domain_id = ?',
                [ $domain_id ]
            );

            foreach ($results as $row) {
                $status = $row['status'];
                if (preg_match('/.*(serverUpdateProhibited)$/', $status) || preg_match('/^pendingTransfer/', $status)) {
                    $this->container->get('flash')->addMessage('error', 'It has a status that does not allow update, first change the status');
                    return $response->withHeader('Location', '/application/update/'.$domainName)->withStatus(302);
                }
            }
  
            $nameservers = $data['nameserver'] ?? [];

            try {
                $db->beginTransaction();
                
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                $db->update('application', [
                    'lastupdate' => $update,
                    'upid' => $clid
                ],
                [
                    'name' => $domainName
                ]
                );
                $domain_id = $db->selectValue(
                    'SELECT id FROM application WHERE name = ?',
                    [$domainName]
                );

                foreach ($nameservers as $index => $nameserver) {
                    if (preg_match("/^-|^\.-|-\.$|^\.$/", $nameserver)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid hostName');
                        return $response->withHeader('Location', '/application/update/'.$domainName)->withStatus(302);
                    }
                    
                    if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $nameserver) && strlen($nameserver) < 254) {
                        $this->container->get('flash')->addMessage('error', 'Invalid hostName');
                        return $response->withHeader('Location', '/application/update/'.$domainName)->withStatus(302);
                    }
                
                    $hostName_already_exist = $db->selectValue(
                        'SELECT id FROM host WHERE name = ? LIMIT 1',
                        [$nameserver]
                    );

                    if ($hostName_already_exist) {
                        $domain_host_map_id = $db->selectValue(
                            'SELECT domain_id FROM application_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1',
                            [$domain_id, $hostName_already_exist]
                        );

                        if (!$domain_host_map_id) {
                            $db->insert(
                                'application_host_map',
                                [
                                    'domain_id' => $domain_id,
                                    'host_id' => $hostName_already_exist
                                ]
                            );
                        } else {
                            $host_map_id = $db->selectValue(
                                'SELECT id FROM application_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1',
                                [$domain_id, $hostName_already_exist]
                            );
                            
                            $db->update(
                                'application_host_map',
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
                            'application_host_map',
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

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/application/update/'.$domainName)->withStatus(302);
            } catch (\Pinga\Db\Throwable\IntegrityConstraintViolationException $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/application/update/'.$domainName)->withStatus(302);
            }

            unset($_SESSION['applications_to_update']);
            $this->container->get('flash')->addMessage('success', 'Application ' . $domainName . ' has been updated successfully on ' . $update);
            return $response->withHeader('Location', '/application/update/'.$domainName)->withStatus(302);
        }
    }
    
    public function applicationDeleteHost(Request $request, Response $response)
    {
        $db = $this->container->get('db');
        $data = $request->getParsedBody();
        $uri = $request->getUri()->getPath();

        if ($data['nameserver']) {
            $host_id = $db->selectValue('SELECT id FROM host WHERE name = ?',
                    [ $data['nameserver'] ]);
            $domain_id = $data['domain_id'];
            $domainName = $db->selectValue('SELECT name FROM application WHERE id = ?',
                    [ $domain_id ]);
            $db->delete(
                'application_host_map',
                [
                    'host_id' => $host_id,
                    'domain_id' => $domain_id
                ]
            );
            
            $this->container->get('flash')->addMessage('success', 'Host ' . $data['nameserver'] . ' has been removed from application successfully');

            $jsonData = json_encode([
                'success' => true,
                'redirect' => '/application/update/'.$domainName
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
    
    public function approveApplication(Request $request, Response $response, $args)
    {
       // if ($request->getMethod() === 'POST') {
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();
        
            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/applications')->withStatus(302);
                }
            
                $domain = $db->selectRow('SELECT id, name, clid, registrant, authinfo FROM application WHERE name = ?',
                [ $args ]);
                
                $domain_status = $db->selectRow("SELECT status FROM application_status WHERE domain_id = ? AND status IN ('rejected', 'invalid')",
                [ $domain['id'] ]);

                if ($domain_status) {
                    $this->container->get('flash')->addMessage('error', 'Application can not be modified, as it has been already processed.');
                    return $response->withHeader('Location', '/applications')->withStatus(302);
                }
            
                $domainName = $domain['name'];
                $application_id = $domain['id'];
                $registrant_id = $domain['registrant'];
                $authinfo = $domain['authinfo'];
                $registrar_id_domain = $domain['clid'];
                
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
                
                $result = $db->selectRow('SELECT accountBalance, creditLimit FROM registrar WHERE id = ?', [$clid]);

                $registrar_balance = $result['accountBalance'];
                $creditLimit = $result['creditLimit'];
                
                $date_add = 12;
                
                $returnValue = getDomainPrice($db, $domainName, $tld_id, $date_add, 'create', $clid);
                $price = $returnValue['price'];
                
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
                            'authinfo' => $authinfo
                        ]
                    );

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
                    
                    $host_map_id = $db->select(
                        'SELECT host_id FROM application_host_map WHERE domain_id = ?',
                        [$application_id]
                    );

                    foreach ($host_map_id as $item) {
                        // Insert into domain_host_map for each host_id
                        $db->insert(
                            'domain_host_map',
                            [
                                'domain_id' => $domain_id,
                                'host_id' => $item['host_id']
                            ]
                        );
                    }
                    
                    $contact_map_id = $db->select(
                        'SELECT contact_id, type FROM application_contact_map WHERE domain_id = ?',
                        [$application_id]
                    );
                    
                    foreach ($contact_map_id as $item) {
                        // Insert into domain_contact_map for each contact_id
                        $db->insert(
                            'domain_contact_map',
                            [
                                'domain_id' => $domain_id,
                                'contact_id' => $item['contact_id'],
                                'type' => $item['type']
                            ]
                        );
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
                    
                    $crdate = $db->selectValue(
                        "SELECT crdate FROM domain WHERE id = ? LIMIT 1",
                        [$domain_id]
                    );
                    
                    $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' has been created successfully on ' . $crdate);
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                } catch (Exception $e) {
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                    return $response->withHeader('Location', '/applications')->withStatus(302);
                } catch (\Pinga\Db\Throwable\IntegrityConstraintViolationException $e) {
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                    return $response->withHeader('Location', '/applications')->withStatus(302);
                }

            } else {
                // Redirect to the applications view
                return $response->withHeader('Location', '/applications')->withStatus(302);
            }
        
        //}
    }
    
    public function rejectApplication(Request $request, Response $response, $args)
    {
       // if ($request->getMethod() === 'POST') {
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();
        
            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/applications')->withStatus(302);
                }
            
                $domain = $db->selectRow('SELECT id, name FROM application WHERE name = ?',
                [ $args ]);
            
                $domainName = $domain['name'];
                $domain_id = $domain['id'];

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

                try {
                    $db->beginTransaction();
                        
                    $db->update(
                        'application_status',
                        [
                            'status' => 'rejected'
                        ],
                        [
                            'domain_id' => $domain_id
                        ]
                    );
                                
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                    return $response->withHeader('Location', '/applications')->withStatus(302);
                }
                    
                $this->container->get('flash')->addMessage('success', 'Application ' . $domainName . ' rejected successfully');
                return $response->withHeader('Location', '/applications')->withStatus(302);
            } else {
                // Redirect to the applications view
                return $response->withHeader('Location', '/applications')->withStatus(302);
            }
        
        //}
    }

    public function deleteApplication(Request $request, Response $response, $args)
    {
       // if ($request->getMethod() === 'POST') {
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();
        
            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/applications')->withStatus(302);
                }

                $domain = $db->selectRow('SELECT id, clid, name FROM application WHERE name = ?',
                [ $args ]);
            
                $domainName = $domain['name'];
                $domain_id = $domain['id'];
                $registrar_id_domain = $domain['clid'];

                $parts = extractDomainAndTLD($domainName);
                $label = $parts['domain'];
                $domain_extension = $parts['tld'];
                
                if ($_SESSION["auth_roles"] != 0) {
                    $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                    if ($registrar_id_domain != $clid) {
                        return $response->withHeader('Location', '/applications')->withStatus(302);
                    }
                }

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

                try {
                    $db->beginTransaction();

                    // Delete domain related records
                    $db->delete(
                        'application_contact_map',
                        [
                            'domain_id' => $domain_id
                        ]
                    );
                    $db->delete(
                        'application_host_map',
                        [
                            'domain_id' => $domain_id
                        ]
                    );
                    $db->delete(
                        'application_status',
                        [
                            'domain_id' => $domain_id
                        ]
                    );
                    $db->delete(
                        'application',
                        [
                            'id' => $domain_id
                        ]
                    );
                                
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                    return $response->withHeader('Location', '/applications')->withStatus(302);
                }
                    
                $this->container->get('flash')->addMessage('success', 'Application ' . $domainName . ' deleted successfully');
                return $response->withHeader('Location', '/applications')->withStatus(302);
            } else {
                // Redirect to the applications view
                return $response->withHeader('Location', '/applications')->withStatus(302);
            }
        
        //}
    }

}