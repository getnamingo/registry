<?php

namespace App\Controllers;

use App\Models\Host;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class HostsController extends Controller
{
    public function listHosts(Request $request, Response $response)
    {
        return view($response,'admin/hosts/listHosts.twig');
    }
    
    public function createHost(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $hostName = $data['hostname'] ?? null;
            $ipv4 = $data['ipv4'] ?? null;
            $ipv6 = $data['ipv6'] ?? null;
            $registrar_id = $data['registrar'] ?? null;
            $registrars = $db->select("SELECT id, clid, name FROM registrar");
            if ($_SESSION["auth_roles"] != 0) {
                $registrar = true;
            } else {
                $registrar = null;
            }

            if ($hostName) {
                $hostModel = new Host($this->container->get('db'));
                
                // Convert to Punycode if the host is not in ASCII
                if (!mb_detect_encoding($hostName, 'ASCII', true)) {
                    $convertedDomain = idn_to_ascii($hostName, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    if ($convertedDomain === false) {
                        $this->container->get('flash')->addMessage('error', 'Host conversion to Punycode failed');
                        return $response->withHeader('Location', '/host/create')->withStatus(302);
                    } else {
                        $hostName = $convertedDomain;
                    }
                }

                if (isValidHostname($hostName)) {
                    $host_id_already_exist = $hostModel->getHostByNom($hostName);
                    if ($host_id_already_exist) {
                        $this->container->get('flash')->addMessage('error', 'Error creating host: host name ' . $hostName . ' already exists');
                        return $response->withHeader('Location', '/host/create')->withStatus(302);
                    }
                } else {
                    $this->container->get('flash')->addMessage('error', 'Error creating host: Invalid host name');
                    return $response->withHeader('Location', '/host/create')->withStatus(302);
                }

                $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                if ($_SESSION["auth_roles"] != 0) {
                    $clid = $result['registrar_id'];
                } else {
                    $clid = $registrar_id;
                }

                if ($ipv4) {
                    $ipv4 = normalize_v4_address($ipv4);
                    if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $this->container->get('flash')->addMessage('error', 'Error creating host: Invalid host addr v4');
                        return $response->withHeader('Location', '/host/create')->withStatus(302);
                    }
                }

                if ($ipv6) {
                    $ipv6 = normalize_v6_address($ipv6);
                    if (!filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $this->container->get('flash')->addMessage('error', 'Error creating host: Invalid host addr v6');
                        return $response->withHeader('Location', '/host/create')->withStatus(302);
                    }
                }

                $internal_host = false;

                $query = "SELECT tld FROM domain_tld";
                $result = $db->select($query);

                foreach ($result as $row) {
                    if (preg_match("/" . preg_quote(strtoupper($row['tld']), '/') . "$/i", $hostName)) {
                        $internal_host = true;
                        break;
                    }
                }

                if ($internal_host) {
                    $domain_exist = false;
                    $clid_domain = 0;
                    $superordinate_dom = 0;
                    
                    $result = $db->select("SELECT id, clid, name FROM domain");

                    foreach ($result as $row) {
                        if (strpos($hostName, $row['name']) !== false) {
                            $domain_exist = true;
                            $clid_domain = $row['clid'];
                            $superordinate_dom = $row['id'];
                            break;
                        }
                    }
                    
                    if (!$domain_exist) {
                        $this->container->get('flash')->addMessage('error', 'Error creating host: A host name object can NOT be created in a repository for which no superordinate domain name object exists');
                        return $response->withHeader('Location', '/host/create')->withStatus(302);
                    }
                    
                    if ($_SESSION['auth_roles'] !== 0) {
                        if ($clid != $clid_domain) {
                            $this->container->get('flash')->addMessage('error', 'Error creating host: The domain name belongs to another registrar, you are not allowed to create hosts for it');
                            return $response->withHeader('Location', '/host/create')->withStatus(302);
                        }
                    }
                    
                    $db->beginTransaction();

                    try {
                        $currentDateTime = new \DateTime();
                        $crdate = $currentDateTime->format('Y-m-d H:i:s.v');
                        $db->insert(
                            'host',
                            [
                                'name' => strtolower($hostName),
                                'domain_id' => $superordinate_dom,
                                'clid' => $clid,
                                'crid' => $clid,
                                'crdate' => $crdate
                            ]
                        );
                        $host_id = $db->getLastInsertId();

                        if (!$ipv4 && !$ipv6) {
                            $this->container->get('flash')->addMessage('error', 'Error creating host: At least one of IPv4 or IPv6 must be provided');
                            return $response->withHeader('Location', '/host/create')->withStatus(302);
                        }

                        if ($ipv4) {
                            $ipv4 = normalize_v4_address($ipv4);
                            $db->insert(
                                'host_addr',
                                [
                                    'host_id' => $host_id,
                                    'addr' => $ipv4,
                                    'ip' => 'v4'
                                ]
                            );
                        }

                        if ($ipv6) {
                            $ipv6 = normalize_v6_address($ipv6);
                            $db->insert(
                                'host_addr',
                                [
                                    'host_id' => $host_id,
                                    'addr' => $ipv6,
                                    'ip' => 'v6'
                                ]
                            );
                        }

                        $host_status = 'ok';
                        $db->insert(
                            'host_status',
                            [
                                'host_id' => $host_id,
                                'status' => $host_status
                            ]
                        );

                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                        return $response->withHeader('Location', '/host/create')->withStatus(302);
                    }

                    $crdate = $db->selectValue(
                        "SELECT crdate FROM host WHERE name = ? LIMIT 1",
                        [$hostName]
                    );
                    
                    $this->container->get('flash')->addMessage('success', 'Host ' . $hostName . ' has been created successfully on ' . $crdate);
                    return $response->withHeader('Location', '/hosts')->withStatus(302);
                } else {
                    $currentDateTime = new \DateTime();
                    $crdate = $currentDateTime->format('Y-m-d H:i:s.v');
                    $db->insert(
                        'host',
                        [
                            'name' => strtolower($hostName),
                            'clid' => $clid,
                            'crid' => $clid,
                            'crdate' => $crdate
                        ]
                    );
                    $host_id = $db->getLastInsertId();
                    
                    $host_status = 'ok';
                    $db->insert(
                        'host_status',
                        [
                            'host_id' => $host_id,
                            'status' => $host_status
                        ]
                    );

                    $crdate = $db->selectValue(
                        "SELECT crdate FROM host WHERE name = ? LIMIT 1",
                        [$hostName]
                    );
                    
                    $this->container->get('flash')->addMessage('success', 'Host ' . $hostName . ' has been created successfully on ' . $crdate);
                    return $response->withHeader('Location', '/hosts')->withStatus(302);
                }
            }
        }

        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
        } else {
            $registrar = null;
        }

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/hosts/createHost.twig', [
            'registrars' => $registrars,
            'registrar' => $registrar,
        ]);
    }
    
    public function viewHost(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args && isValidHostname($args)) {
            $host = $db->selectRow('SELECT id, name, clid, crdate FROM host WHERE name = ?',
            [ $args ]);

            if ($host) {
                $registrars = $db->selectRow('SELECT id, clid, name FROM registrar WHERE id = ?', [$host['clid']]);

                // Check if the user is not an admin (assuming role 0 is admin)
                if ($_SESSION["auth_roles"] != 0) {
                    $userRegistrars = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                    // Assuming $userRegistrars returns an array of arrays, each containing 'registrar_id'
                    $userRegistrarIds = array_column($userRegistrars, 'registrar_id');

                    // Check if the registrar's ID is in the user's list of registrar IDs
                    if (!in_array($registrars['id'], $userRegistrarIds)) {
                        // Redirect to the hosts view if the user is not authorized for this host
                        return $response->withHeader('Location', '/hosts')->withStatus(302);
                    }
                }
                
                $hostStatus = $db->selectRow('SELECT status FROM host_status WHERE host_id = ?',
                [ $host['id'] ]);
                $hostLinked = $db->selectRow('SELECT domain_id FROM domain_host_map WHERE host_id = ?',
                [ $host['id'] ]);
                $hostIPv4 = $db->select("SELECT addr FROM host_addr WHERE host_id = ? AND ip = 'v4'",
                [ $host['id'] ]);
                $hostIPv6 = $db->select("SELECT addr FROM host_addr WHERE host_id = ? AND ip = 'v6'",
                [ $host['id'] ]);
                
                if (strpos($host['name'], 'xn--') === 0) {
                    $host['name'] = idn_to_utf8($host['name'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                }
                return view($response,'admin/hosts/viewHost.twig', [
                    'host' => $host,
                    'hostStatus' => $hostStatus,
                    'hostLinked' => $hostLinked,
                    'hostIPv4' => $hostIPv4,
                    'hostIPv6' => $hostIPv6,
                    'registrars' => $registrars,
                    'currentUri' => $uri
                ]);
            } else {
                // Host does not exist, redirect to the hosts view
                return $response->withHeader('Location', '/hosts')->withStatus(302);
            }

        } else {
            // Redirect to the hosts view
            return $response->withHeader('Location', '/hosts')->withStatus(302);
        }

    }
    
    public function updateHost(Request $request, Response $response, $args)
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args && isValidHostname($args)) {
            $args = trim($args);

            if (mb_detect_encoding($args, 'ASCII', true) === false) {
                $args = idn_to_ascii($args, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                if ($args === false) {
                    // Redirect to the hosts view
                    return $response->withHeader('Location', '/hosts')->withStatus(302);
                }
            }
            $internal_host = false;

            $query = "SELECT tld FROM domain_tld";
            $result = $db->select($query);

            foreach ($result as $row) {
                if (preg_match("/" . preg_quote(strtoupper($row['tld']), '/') . "$/i", $args)) {
                    $internal_host = true;
                    break;
                }
            }

            if (!$internal_host) {
                $host = $db->selectRow('SELECT id, name, clid, crdate FROM host WHERE name = ?',
                [ $args ]);
                
                if ($host) {            
                    return view($response,'admin/hosts/updateInternalHost.twig', [
                        'host' => $host,
                        'currentUri' => $uri
                    ]);
                    
                } else {
                    // Host does not exist, redirect to the hosts view
                    return $response->withHeader('Location', '/hosts')->withStatus(302);
                }
            } else {
                $host = $db->selectRow('SELECT id, name, clid, crdate FROM host WHERE name = ?',
                [ $args ]);

                if ($host) {
                    $registrars = $db->selectRow('SELECT id, clid, name FROM registrar WHERE id = ?', [$host['clid']]);

                    // Check if the user is not an admin (assuming role 0 is admin)
                    if ($_SESSION["auth_roles"] != 0) {
                        $userRegistrars = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                        // Assuming $userRegistrars returns an array of arrays, each containing 'registrar_id'
                        $userRegistrarIds = array_column($userRegistrars, 'registrar_id');

                        // Check if the registrar's ID is in the user's list of registrar IDs
                        if (!in_array($registrars['id'], $userRegistrarIds)) {
                            // Redirect to the hosts view if the user is not authorized for this host
                            return $response->withHeader('Location', '/hosts')->withStatus(302);
                        }
                    }

                    $hostIPv4 = $db->select("SELECT addr FROM host_addr WHERE host_id = ? AND ip = 'v4'",
                    [ $host['id'] ]);
                    $hostIPv6 = $db->select("SELECT addr FROM host_addr WHERE host_id = ? AND ip = 'v6'",
                    [ $host['id'] ]);

                    if (strpos($host['name'], 'xn--') === 0) {
                        $host['punycode'] = $host['name'];
                        $host['name'] = idn_to_utf8($host['name'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    } else {
                        $host['punycode'] = $host['name'];
                    }
                    $_SESSION['hosts_to_update'] = [$host['punycode']];

                    return view($response,'admin/hosts/updateHost.twig', [
                        'host' => $host,
                        'hostIPv4' => $hostIPv4,
                        'hostIPv6' => $hostIPv6,
                        'registrars' => $registrars,
                        'currentUri' => $uri
                    ]);
                } else {
                    // Host does not exist, redirect to the hosts view
                    return $response->withHeader('Location', '/hosts')->withStatus(302);
                }
            }
        } else {
            // Redirect to the hosts view
            return $response->withHeader('Location', '/hosts')->withStatus(302);
        }
    }
    
    public function updateHostProcess(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            if (!empty($_SESSION['hosts_to_update'])) {
                $hostName = $_SESSION['hosts_to_update'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No host specified for update');
                return $response->withHeader('Location', '/hosts')->withStatus(302);
            }
            $host_id = $db->selectValue('SELECT id FROM host WHERE name = ?', [$hostName]);
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                $host_clid = $db->selectValue('SELECT clid FROM host WHERE name = ?', [$hostName]);
                if ($host_clid != $clid) {
                    return $response->withHeader('Location', '/hosts')->withStatus(302);
                }
            } else {
                $clid = $db->selectValue('SELECT clid FROM host WHERE name = ?', [$hostName]);
            }
       
            $ipv4 = $data['ipv4'] ?? null;
            $ipv6 = $data['ipv6'] ?? null;

            // Check if both IPv4 and IPv6 are empty or null
            if (empty($ipv4) && empty($ipv6)) {
                $this->container->get('flash')->addMessage('error', 'At least one IP address (IPv4 or IPv6) is required');
                return $response->withHeader('Location', '/host/update/'.$hostName)->withStatus(302);
            }

            // Validate IPv4 address, if provided
            if (!empty($ipv4) && !filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $this->container->get('flash')->addMessage('error', 'Invalid IPv4 address');
                return $response->withHeader('Location', '/host/update/'.$hostName)->withStatus(302);
            }

            // Validate IPv6 address, if provided
            if (!empty($ipv6) && !filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $this->container->get('flash')->addMessage('error', 'Invalid IPv6 address');
                return $response->withHeader('Location', '/host/update/'.$hostName)->withStatus(302);
            }
            
            try {
                $db->beginTransaction();
         
                if (isset($ipv4)) {
                    if (!empty($ipv4)) {
                        $ipv4 = normalize_v4_address($ipv4);
                        
                        $does_it_exist = $db->selectValue("SELECT id FROM host_addr WHERE host_id = ? AND ip = 'v4'", [$host_id]);
                        
                        if ($does_it_exist) {
                            $db->update(
                                'host_addr',
                                ['addr' => $ipv4],
                                [
                                    'host_id' => $host_id,
                                    'ip' => 'v4'
                                ]
                            );
                        } else {
                            $db->insert(
                                'host_addr',
                                [
                                    'addr' => $ipv4,
                                    'host_id' => $host_id,
                                    'ip' => 'v4'
                                ]
                            );
                        }
                    } else {
                        // If $ipv4 is set but is an empty string, delete the existing IPv4 address entry
                        $db->delete(
                            'host_addr',
                            [
                                'host_id' => $host_id,
                                'ip' => 'v4'
                            ]
                        );
                    }
                }

                if (isset($ipv6)) {
                    if (!empty($ipv6)) {
                        $ipv6 = normalize_v6_address($ipv6);
                        
                        $does_it_exist = $db->selectValue("SELECT id FROM host_addr WHERE host_id = ? AND ip = 'v6'", [$host_id]);
                        
                        if ($does_it_exist) {
                            $db->update(
                                'host_addr',
                                ['addr' => $ipv6],
                                [
                                    'host_id' => $host_id,
                                    'ip' => 'v6'
                                ]
                            );
                        } else {
                            $db->insert(
                                'host_addr',
                                [
                                    'addr' => $ipv6,
                                    'host_id' => $host_id,
                                    'ip' => 'v6'
                                ]
                            );
                        }
                    } else {
                        // If $ipv6 is set but is an empty string, delete the existing IPv6 address entry
                        $db->delete(
                            'host_addr',
                            [
                                'host_id' => $host_id,
                                'ip' => 'v6'
                            ]
                        );
                    }
                }
                
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                $db->update('host', [
                    'lastupdate' => $update,
                    'upid' => $clid
                ],
                [
                    'name' => $hostName
                ]
                );
           
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/host/update/'.$hostName)->withStatus(302);
            }

            unset($_SESSION['hosts_to_update']);
            $this->container->get('flash')->addMessage('success', 'Host ' . $hostName . ' has been updated successfully on ' . $update);
            return $response->withHeader('Location', '/host/update/'.$hostName)->withStatus(302);
        }
    }
    
    public function deleteHost(Request $request, Response $response, $args)
    {
       // if ($request->getMethod() === 'POST') {
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();
        
            if ($args && isValidHostname($args)) {
                $host = $db->selectRow('SELECT id, clid FROM host WHERE name = ?',
                [ $args ]);
                $host_id = $host['id'];
                $registrar_id_host = $host['clid'];
                
                if ($_SESSION["auth_roles"] != 0) {
                    $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                    if ($registrar_id_host != $clid) {
                        return $response->withHeader('Location', '/hosts')->withStatus(302);
                    }
                }
                
                $is_linked = $db->selectRow('SELECT domain_id FROM domain_host_map WHERE host_id = ?',
                [ $host_id ]);
                
                if ($is_linked) {
                    $this->container->get('flash')->addMessage('error', 'It is not possible to delete ' . $args . ' because it is a dependency, it is used by some domain');
                    return $response->withHeader('Location', '/hosts')->withStatus(302);
                } else {
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
                        'host',
                        [
                            'id' => $host_id
                        ]
                    );
                    
                    $this->container->get('flash')->addMessage('success', 'Host ' . $args . ' deleted successfully');
                    return $response->withHeader('Location', '/hosts')->withStatus(302);
                }
            } else {
                // Redirect to the hosts view
                return $response->withHeader('Location', '/hosts')->withStatus(302);
            }
        
        //}

    }

}