<?php

namespace App\Controllers;

use App\Models\Host;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class HostsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        return view($response,'admin/hosts/view.twig');
    }
    
    public function create(Request $request, Response $response)
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

            if ($hostName) {
                $hostModel = new Host($this->container->get('db'));
                
                if (preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $hostName) && strlen($hostName) < 254) {
                    $host_id_already_exist = $hostModel->getHostByNom($hostName);
                    if ($host_id_already_exist) {
                        return view($response, 'admin/hosts/create.twig', [
                            'hostName' => $hostName,
                            'error' => 'host name already exists',
                            'registrars' => $registrars,
                        ]);
                    }
                } else {
                    return view($response, 'admin/hosts/create.twig', [
                        'hostName' => $hostName,
                        'error' => 'Invalid host name',
                        'registrars' => $registrars,
                    ]);
                }
                
                $result = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                if (is_array($result)) {
                    $clid = $result['registrar_id'];
                } else if (is_object($result) && method_exists($result, 'fetch')) {
                    $clid = $result->fetch();
                } else {
                    $clid = $registrar_id;
                }

                if ($ipv4) {
                    $ipv4 = normalize_v4_address($ipv4);
                    if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return view($response, 'admin/hosts/create.twig', [
                            'hostName' => $hostName,
                            'error' => 'Invalid host addr v4',
                            'registrars' => $registrars,
                        ]);
                    }
                }

                if ($ipv6) {
                    $ipv6 = normalize_v6_address($ipv6);
                    if (!filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        return view($response, 'admin/hosts/create.twig', [
                            'hostName' => $hostName,
                            'error' => 'Invalid host addr v6',
                            'registrars' => $registrars,
                        ]);
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
                        return view($response, 'admin/hosts/create.twig', [
                            'hostName' => $hostName,
                            'error' => 'A host name object can NOT be created in a repository for which no superordinate domain name object exists',
                            'registrars' => $registrars,
                        ]);
                    }
                    
                    if ($_SESSION['auth_roles'] !== 0) {
                        if ($clid != $clid_domain) {
                            return view($response, 'admin/hosts/create.twig', [
                                'hostName' => $hostName,
                                'error' => 'The domain name belongs to another registrar, you are not allowed to create hosts for it',
                                'registrars' => $registrars,
                            ]);
                        }
                    }
                    
                    $db->beginTransaction();

                    try {
                        $currentDateTime = new \DateTime();
                        $crdate = $currentDateTime->format('Y-m-d H:i:s.v');
                        $db->insert(
                            'host',
                            [
                                'name' => $hostName,
                                'domain_id' => $superordinate_dom,
                                'clid' => $clid,
                                'crid' => $clid,
                                'crdate' => $crdate
                            ]
                        );
                        $host_id = $db->getLastInsertId();

                        if (!$ipv4 && !$ipv6) {
                            return view($response, 'admin/hosts/create.twig', [
                                'hostName' => $hostName,
                                'error' => 'At least one of IPv4 or IPv6 must be provided',
                                'registrars' => $registrars,
                            ]);
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
                        return view($response, 'admin/hosts/create.twig', [
                            'hostName' => $hostName,
                            'error' => $e->getMessage(),
                            'registrars' => $registrars,
                        ]);
                    }

                    $crdate = $db->selectValue(
                        "SELECT crdate FROM host WHERE name = ? LIMIT 1",
                        [$hostName]
                    );
                    
                    return view($response, 'admin/hosts/create.twig', [
                        'hostName' => $hostName,
                        'crdate' => $crdate,
                        'registrars' => $registrars,
                    ]);
                } else {
                    $currentDateTime = new \DateTime();
                    $crdate = $currentDateTime->format('Y-m-d H:i:s.v');
                    $db->insert(
                        'host',
                        [
                            'name' => $hostName,
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
                    
                    return view($response, 'admin/hosts/create.twig', [
                        'hostName' => $hostName,
                        'crdate' => $crdate,
                        'registrars' => $registrars,
                    ]);
                }
            }
        }

        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/hosts/create.twig', [
            'registrars' => $registrars,
        ]);
    }
    
    public function viewHost(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        function isValidHostname($hostname) {
            // Check for IDN and convert to ASCII if necessary
            if (mb_detect_encoding($hostname, 'ASCII', true) === false) {
                $hostname = idn_to_ascii($hostname, 0, INTL_IDNA_VARIANT_UTS46);
            }

            // Regular expression for validating a hostname (simplified version)
            $pattern = '/^([a-zA-Z0-9-]{1,63}\.){1,}[a-zA-Z]{2,63}$/';

            return preg_match($pattern, $hostname);
        }

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

}