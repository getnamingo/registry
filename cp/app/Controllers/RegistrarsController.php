<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use League\ISO3166\ISO3166;
use Respect\Validation\Validator as v;

class RegistrarsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        return view($response,'admin/registrars/index.twig');
    }
    
    public function create(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $iso3166 = new ISO3166();
            $countries = $iso3166->all();

            $ipAddressValidator = v::when(
                v::arrayType()->notEmpty(), // Condition: If it's a non-empty array
                v::arrayType()->each(v::ip()), // Then: Each element must be a valid IP address
                v::equals('') // Else: Allow it to be an empty string
            );
            
            $data['owner']['cc'] = strtoupper($data['owner']['cc']);
            $data['billing']['cc'] = strtoupper($data['billing']['cc']);
            $data['abuse']['cc'] = strtoupper($data['abuse']['cc']);
            
            $phoneValidator = v::regex('/^\+\d{1,3}\.\d{2,12}$/');
       
            // Define validation for nested fields
            $contactValidator = [
                v::key('first_name', v::stringType()->notEmpty()->length(1, 255), true),
                v::key('last_name', v::stringType()->notEmpty()->length(1, 255), true),
                v::key('org', v::optional(v::stringType()->length(1, 255)), false),
                v::key('street1', v::optional(v::stringType()), false),
                v::key('city', v::stringType()->notEmpty(), true),
                v::key('sp', v::optional(v::stringType()), false),
                v::key('pc', v::optional(v::stringType()), false),
                v::key('cc', v::countryCode(), true),
                v::key('voice', v::optional($phoneValidator), false),
                v::key('fax', v::optional(v::phone()), false),
                v::key('email', v::email(), true)
            ];
            
            $validators = [
                'name' => v::stringType()->notEmpty()->length(1, 255),
                'ianaId' => v::optional(v::positive()->length(1, 5)),
                'email' => v::email(),
                'owner' => v::optional(v::keySet(...$contactValidator)),
                'billing' => v::optional(v::keySet(...$contactValidator)),
                'abuse' => v::optional(v::keySet(...$contactValidator)),
                'whoisServer' => v::domain(),
                'rdapServer' => v::domain(),
                'url' => v::url(),
                'abuseEmail' => v::email(),
                'abusePhone' => v::optional($phoneValidator),
                'accountBalance' => v::numericVal(),
                'creditLimit' => v::numericVal(),
                'creditThreshold' => v::numericVal(),
                'thresholdType' => v::in(['fixed', 'percent']),
                'ipAddress' => v::optional($ipAddressValidator),
                'user_name' => v::stringType()->notEmpty()->length(1, 255),
                'user_email' => v::email(),
                'eppPassword' => v::stringType()->notEmpty(),
                'panelPassword' => v::stringType()->notEmpty(),
            ];

            $errors = [];
            foreach ($validators as $field => $validator) {
                try {
                    $validator->assert(isset($data[$field]) ? $data[$field] : []);
                } catch (\Respect\Validation\Exceptions\NestedValidationException $e) {
                    $errors[$field] = $e->getMessages();
                }
            }

            if (!empty($errors)) {
                // Handle errors
                $errorText = '';

                foreach ($errors as $field => $messages) {
                    $errorText .= ucfirst($field) . ' errors: ' . implode(', ', $messages) . '; ';
                }

                // Trim the final semicolon and space
                $errorText = rtrim($errorText, '; ');

                $this->container->get('flash')->addMessage('error', 'Error creating registrar: ' . $errorText);
                return $response->withHeader('Location', '/registrar/create')->withStatus(302);
            }

            $db->beginTransaction();

            try {
                $currentDateTime = new \DateTime();
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v');
                $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                $randomPrefix = '';
                for ($i = 0; $i < 2; $i++) {
                    $randomPrefix .= $characters[rand(0, strlen($characters) - 1)];
                }
                $currency = $_SESSION['_currency'] ?? 'USD';
                $eppPassword = password_hash($data['eppPassword'], PASSWORD_ARGON2ID, ['memory_cost' => 1024 * 128, 'time_cost' => 6, 'threads' => 4]);
                
                if (empty($data['ianaId']) || !is_numeric($data['ianaId'])) {
                    $data['ianaId'] = null;
                }

                $db->insert(
                    'registrar',
                    [
                        'name' => $data['name'],
                        'iana_id' => $data['ianaId'],
                        'clid' => $data['user_name'],
                        'pw' => $eppPassword,
                        'prefix' => $randomPrefix,
                        'email' => $data['email'],
                        'url' => $data['url'],
                        'whois_server' => $data['whoisServer'],
                        'rdap_server' => $data['rdapServer'],
                        'abuse_email' => $data['abuseEmail'],
                        'abuse_phone' => $data['abusePhone'],
                        'accountBalance' => $data['accountBalance'],
                        'creditLimit' => $data['creditLimit'],
                        'creditThreshold' => $data['creditThreshold'],
                        'thresholdType' => $data['thresholdType'],
                        'currency' => $currency,
                        'crdate' => $crdate,
                        'lastupdate' => $crdate
                    ]
                );
                $registrar_id = $db->getLastInsertId();
                
                $db->exec(
                    'UPDATE registrar SET prefix = ? WHERE id = ?',
                    [
                        'R'.$registrar_id,
                        $registrar_id
                    ]
                );
                
                $db->insert(
                    'registrar_contact',
                    [
                        'registrar_id' => $registrar_id,
                        'type' => 'owner',
                        'first_name' => $data['owner']['first_name'],
                        'last_name' => $data['owner']['last_name'],
                        'org' => $data['owner']['org'],
                        'street1' => $data['owner']['street1'],
                        'city' => $data['owner']['city'],
                        'sp' => $data['owner']['sp'],
                        'pc' => $data['owner']['pc'],
                        'cc' => strtolower($data['owner']['cc']),
                        'voice' => $data['owner']['voice'],
                        'email' => $data['owner']['email']
                    ]
                );

                $db->insert(
                    'registrar_contact',
                    [
                        'registrar_id' => $registrar_id,
                        'type' => 'billing',
                        'first_name' => $data['billing']['first_name'],
                        'last_name' => $data['billing']['last_name'],
                        'org' => $data['billing']['org'],
                        'street1' => $data['billing']['street1'],
                        'city' => $data['billing']['city'],
                        'sp' => $data['billing']['sp'],
                        'pc' => $data['billing']['pc'],
                        'cc' => strtolower($data['billing']['cc']),
                        'voice' => $data['billing']['voice'],
                        'email' => $data['billing']['email']
                    ]
                );
                
                $db->insert(
                    'registrar_contact',
                    [
                        'registrar_id' => $registrar_id,
                        'type' => 'abuse',
                        'first_name' => $data['abuse']['first_name'],
                        'last_name' => $data['abuse']['last_name'],
                        'org' => $data['abuse']['org'],
                        'street1' => $data['abuse']['street1'],
                        'city' => $data['abuse']['city'],
                        'sp' => $data['abuse']['sp'],
                        'pc' => $data['abuse']['pc'],
                        'cc' => strtolower($data['abuse']['cc']),
                        'voice' => $data['abuse']['voice'],
                        'email' => $data['abuse']['email']
                    ]
                );
                
                if (!empty($data['ipAddress'])) {
                    foreach ($data['ipAddress'] as $ip) {
                        $db->insert(
                            'registrar_whitelist',
                            [
                                'registrar_id' => $registrar_id,
                                'addr' => $ip
                            ]
                        );
                    }
                }
                
                $panelPassword = password_hash($data['panelPassword'], PASSWORD_ARGON2ID, ['memory_cost' => 1024 * 128, 'time_cost' => 6, 'threads' => 4]);

                $db->insert(
                    'users',
                    [
                        'email' => $data['user_email'],
                        'password' => $panelPassword,
                        'username' => $data['user_name'],
                        'verified' => 1,
                        'roles_mask' => 4,
                        'registered' => \time()
                    ]
                );
                $user_id = $db->getLastInsertId();
                
                $db->insert(
                    'registrar_users',
                    [
                        'registrar_id' => $registrar_id,
                        'user_id' => $user_id
                    ]
                );
                
                $eppCommands = [
                    'contact:create',
                    'domain:check',
                    'domain:info',
                    'domain:renew',
                    'domain:transfer',
                    'host:create',
                    'host:info',
                    'contact:update',
                    'domain:delete',
                    'poll:request'
                ];

                foreach ($eppCommands as $command) {
                    $db->insert(
                        'registrar_ote',
                        [
                            'registrar_id' => $registrar_id,
                            'command' => $command,
                            'result' => '9',
                        ]
                    );
                }
               
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/registrar/create')->withStatus(302);
            }

            $this->container->get('flash')->addMessage('success', 'Registrar ' . $data['name'] . ' successfully created and is now active.');
            return $response->withHeader('Location', '/registrars')->withStatus(302);
        }
          
        $iso3166 = new ISO3166();
        $countries = $iso3166->all();
        
        // Default view for GET requests or if POST data is not set
        return view($response,'admin/registrars/create.twig', [
            'countries' => $countries,
        ]);
    }
    
    public function viewRegistrar(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim(preg_replace('/\s+/', ' ', $args));

            if (!preg_match('/^[a-zA-Z0-9\s]+$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid registrar');
                return $response->withHeader('Location', '/registrars')->withStatus(302);
            }
            
            $registrar = $db->selectRow('SELECT * FROM registrar WHERE name = ?',
            [ $args ]);

            if ($registrar) {
                // Check if the user is not an admin
                if ($_SESSION["auth_roles"] != 0) {
                    $userRegistrars = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                    // Assuming $userRegistrars returns an array of arrays, each containing 'registrar_id'
                    $userRegistrarIds = array_column($userRegistrars, 'registrar_id');

                    // Check if the registrar's ID is in the user's list of registrar IDs
                    if (!in_array($registrar['id'], $userRegistrarIds)) {
                        // Redirect to the registrars view if the user is not authorized for this contact
                        return $response->withHeader('Location', '/registrars')->withStatus(302);
                    }
                }
                
                $registrarContact = $db->selectRow('SELECT * FROM registrar_contact WHERE registrar_id = ?',
                [ $registrar['id'] ]);
                $registrarOte = $db->select('SELECT * FROM registrar_ote WHERE registrar_id = ? ORDER by command',
                [ $registrar['id'] ]);
                $registrarUsers = $db->selectRow('SELECT user_id FROM registrar_users WHERE registrar_id = ?',
                [ $registrar['id'] ]);
                $userEmail = $db->selectRow('SELECT email FROM users WHERE id = ?',
                [ $registrarUsers['user_id'] ]);
                $registrarWhitelist = $db->select('SELECT addr FROM registrar_whitelist WHERE registrar_id = ?',
                [ $registrar['id'] ]);
                // Check if RegistrarOTE is not empty
                if (is_array($registrarOte) && !empty($registrarOte)) {
                    // Calculate the total number of elements
                    $totalElements = count($registrarOte);

                    // Calculate the size of the first half. If the total number is odd, add 1 to include the extra item in the first half.
                    $firstHalfSize = ceil($totalElements / 2);

                    // Split the array into two halves
                    $firstHalf = array_slice($registrarOte, 0, $firstHalfSize);
                    $secondHalf = array_slice($registrarOte, $firstHalfSize);
                } else {
                    // If RegistrarOTE is empty, set both halves to empty arrays
                    $firstHalf = [];
                    $secondHalf = [];
                }

                return view($response,'admin/registrars/viewRegistrar.twig', [
                    'registrar' => $registrar,
                    'registrarContact' => $registrarContact,
                    'firstHalf' => $firstHalf,
                    'secondHalf' => $secondHalf,
                    'userEmail' => $userEmail,
                    'registrarWhitelist' => $registrarWhitelist,
                    'currentUri' => $uri
                ]);
            } else {
                // Contact does not exist, redirect to the registrars view
                return $response->withHeader('Location', '/registrars')->withStatus(302);
            }

        } else {
            // Redirect to the registrars view
            return $response->withHeader('Location', '/registrars')->withStatus(302);
        }

    }
    
    public function registrar(Request $request, Response $response) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();
        $registrarId = $_SESSION['auth_registrar_id'];
        
        if (isset($registrarId) && $registrarId !== "") {
            $registrar = $db->selectRow('SELECT * FROM registrar WHERE id = ?',
            [ $registrarId ]);

            if ($registrar) {               
                $registrarContact = $db->selectRow('SELECT * FROM registrar_contact WHERE registrar_id = ?',
                [ $registrar['id'] ]);
                $registrarOte = $db->select('SELECT * FROM registrar_ote WHERE registrar_id = ? ORDER by command',
                [ $registrar['id'] ]);
                $registrarUsers = $db->selectRow('SELECT user_id FROM registrar_users WHERE registrar_id = ?',
                [ $registrar['id'] ]);
                $userEmail = $db->selectRow('SELECT email FROM users WHERE id = ?',
                [ $registrarUsers['user_id'] ]);
                $registrarWhitelist = $db->select('SELECT addr FROM registrar_whitelist WHERE registrar_id = ?',
                [ $registrar['id'] ]);
                // Check if RegistrarOTE is not empty
                if (is_array($registrarOte) && !empty($registrarOte)) {
                    // Calculate the total number of elements
                    $totalElements = count($registrarOte);

                    // Calculate the size of the first half. If the total number is odd, add 1 to include the extra item in the first half.
                    $firstHalfSize = ceil($totalElements / 2);

                    // Split the array into two halves
                    $firstHalf = array_slice($registrarOte, 0, $firstHalfSize);
                    $secondHalf = array_slice($registrarOte, $firstHalfSize);
                } else {
                    // If RegistrarOTE is empty, set both halves to empty arrays
                    $firstHalf = [];
                    $secondHalf = [];
                }

                return view($response,'admin/registrars/viewRegistrar.twig', [
                    'registrar' => $registrar,
                    'registrarContact' => $registrarContact,
                    'firstHalf' => $firstHalf,
                    'secondHalf' => $secondHalf,
                    'userEmail' => $userEmail,
                    'registrarWhitelist' => $registrarWhitelist,
                    'currentUri' => $uri
                ]);
            } else {
                // Contact does not exist, redirect to the dashboard view
                return $response->withHeader('Location', '/dashboard')->withStatus(302);
            }
        } else {
            // Redirect to the registrars view
            return $response->withHeader('Location', '/registrars')->withStatus(302);
        }
    }
    
    public function updateRegistrar(Request $request, Response $response, $args)
    {
        $db = $this->container->get('db');
        $iso3166 = new ISO3166();
        $countries = $iso3166->all();
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^[a-z0-9]+$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid registrar');
                return $response->withHeader('Location', '/registrars')->withStatus(302);
            }
            
            $registrar = $db->selectRow('SELECT * FROM registrar WHERE clid = ?',
            [ $args ]);

            if ($registrar) {
                // Check if the user is not an admin (assuming role 0 is admin)
                if ($_SESSION["auth_roles"] != 0) {
                    return $response->withHeader('Location', '/dashboard')->withStatus(302);
                }

                $contacts = $db->select("SELECT * FROM registrar_contact WHERE registrar_id = ?",
                [ $registrar['id'] ]);
                $registrarOte = $db->select("SELECT * FROM registrar_ote WHERE registrar_id = ?",
                [ $registrar['id'] ]);
                $user_id = $db->selectValue("SELECT user_id FROM registrar_users WHERE registrar_id = ?",
                [ $registrar['id'] ]);
                $user = $db->selectRow("SELECT email FROM users WHERE id = ?",
                [ $user_id ]);
                $whitelist = $db->select("SELECT * FROM registrar_whitelist WHERE registrar_id = ?",
                [ $registrar['id'] ]);
                // Check if RegistrarOTE is not empty
                if (is_array($registrarOte) && !empty($registrarOte)) {
                    // Calculate the total number of elements
                    $totalElements = count($registrarOte);

                    // Calculate the size of the first half. If the total number is odd, add 1 to include the extra item in the first half.
                    $firstHalfSize = ceil($totalElements / 2);

                    // Split the array into two halves
                    $firstHalf = array_slice($registrarOte, 0, $firstHalfSize);
                    $secondHalf = array_slice($registrarOte, $firstHalfSize);
                } else {
                    // If RegistrarOTE is empty, set both halves to empty arrays
                    $firstHalf = [];
                    $secondHalf = [];
                }

                $_SESSION['registrars_to_update'] = [$registrar['clid']];
                $_SESSION['registrars_user_email'] = [$user['email']];

                return view($response,'admin/registrars/updateRegistrar.twig', [
                    'registrar' => $registrar,
                    'contacts' => $contacts,
                    'firstHalf' => $firstHalf,
                    'secondHalf' => $secondHalf,
                    'user' => $user,
                    'whitelist' => $whitelist,
                    'currentUri' => $uri,
                    'countries' => $countries
                ]);
            } else {
                // Registrar does not exist, redirect to the registrars view
                return $response->withHeader('Location', '/registrars')->withStatus(302);
            }
        } else {
            // Redirect to the registrars view
            return $response->withHeader('Location', '/registrars')->withStatus(302);
        }
    }
    
    public function updateRegistrarProcess(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            if (!empty($_SESSION['registrars_to_update'])) {
                $registrar = $_SESSION['registrars_to_update'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No registrar specified for update');
                return $response->withHeader('Location', '/registrars')->withStatus(302);
            }

            $data['ipAddress'] = array_filter($data['ipAddress']);
            $iso3166 = new ISO3166();
            $countries = $iso3166->all();

            $ipAddressValidator = v::when(
                v::arrayType()->notEmpty(), // Condition: If it's a non-empty array
                v::arrayType()->each(v::ip()), // Then: Each element must be a valid IP address
                v::equals('') // Else: Allow it to be an empty string
            );
            
            $data['owner']['cc'] = strtoupper($data['owner']['cc']);
            $data['billing']['cc'] = strtoupper($data['billing']['cc']);
            $data['abuse']['cc'] = strtoupper($data['abuse']['cc']);
            
            $phoneValidator = v::regex('/^\+\d{1,3}\.\d{2,12}$/');
       
            // Define validation for nested fields
            $contactValidator = [
                v::key('first_name', v::stringType()->notEmpty()->length(1, 255), true),
                v::key('last_name', v::stringType()->notEmpty()->length(1, 255), true),
                v::key('org', v::optional(v::stringType()->length(1, 255)), false),
                v::key('street1', v::optional(v::stringType()), false),
                v::key('city', v::stringType()->notEmpty(), true),
                v::key('sp', v::optional(v::stringType()), false),
                v::key('pc', v::optional(v::stringType()), false),
                v::key('cc', v::countryCode(), true),
                v::key('voice', v::optional($phoneValidator), false),
                v::key('fax', v::optional(v::phone()), false),
                v::key('email', v::email(), true)
            ];
            
            $validators = [
                'name' => v::stringType()->notEmpty()->length(1, 255),
                'ianaId' => v::optional(v::positive()->length(1, 5)),
                'email' => v::email(),
                'owner' => v::optional(v::keySet(...$contactValidator)),
                'billing' => v::optional(v::keySet(...$contactValidator)),
                'abuse' => v::optional(v::keySet(...$contactValidator)),
                'whoisServer' => v::domain(),
                'rdapServer' => v::domain(),
                'url' => v::url(),
                'abuseEmail' => v::email(),
                'abusePhone' => v::optional($phoneValidator),
                'creditLimit' => v::numericVal(),
                'creditThreshold' => v::numericVal(),
                'ipAddress' => v::optional($ipAddressValidator)
            ];

            $errors = [];
            foreach ($validators as $field => $validator) {
                try {
                    $validator->assert(isset($data[$field]) ? $data[$field] : []);
                } catch (\Respect\Validation\Exceptions\NestedValidationException $e) {
                    $errors[$field] = $e->getMessages();
                }
            }

            if (!empty($errors)) {
                // Handle errors
                $errorText = '';

                foreach ($errors as $field => $messages) {
                    $errorText .= ucfirst($field) . ' errors: ' . implode(', ', $messages) . '; ';
                }

                // Trim the final semicolon and space
                $errorText = rtrim($errorText, '; ');
                
                $this->container->get('flash')->addMessage('error', $errorText);
                return $response->withHeader('Location', '/registrars')->withStatus(302);
            }
            
            if (!empty($_SESSION['registrars_user_email'])) {
                $regEmail = $_SESSION['registrars_user_email'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No email specified for update');
                return $response->withHeader('Location', '/registrars')->withStatus(302);
            }

            $db->beginTransaction();

            try {
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v');
                $currency = $_SESSION['_currency'] ?? 'USD';
                
                if (empty($data['ianaId']) || !is_numeric($data['ianaId'])) {
                    $data['ianaId'] = null;
                }
                
                $updateData = [
                    'name' => $data['name'],
                    'iana_id' => $data['ianaId'],
                    'email' => $data['email'],
                    'url' => $data['url'],
                    'whois_server' => $data['whoisServer'],
                    'rdap_server' => $data['rdapServer'],
                    'abuse_email' => $data['abuseEmail'],
                    'abuse_phone' => $data['abusePhone'],
                    'creditLimit' => $data['creditLimit'],
                    'creditThreshold' => $data['creditThreshold'],
                    'currency' => $currency,
                    'lastupdate' => $crdate
                ];
                
                if (!empty($data['eppPassword'])) {
                    $eppPassword = password_hash($data['eppPassword'], PASSWORD_ARGON2ID, ['memory_cost' => 1024 * 128, 'time_cost' => 6, 'threads' => 4]);
                    $updateData['pw'] = $eppPassword;
                }

                $db->update(
                    'registrar',
                    $updateData,
                    [
                        'clid' => $registrar
                    ]
                );
                $registrar_id = $db->selectValue(
                    'SELECT id FROM registrar WHERE clid = ?',
                    [$registrar]
                );
           
                $db->update(
                    'registrar_contact',
                    [
                        'first_name' => $data['owner']['first_name'],
                        'last_name' => $data['owner']['last_name'],
                        'org' => $data['owner']['org'],
                        'street1' => $data['owner']['street1'],
                        'city' => $data['owner']['city'],
                        'sp' => $data['owner']['sp'],
                        'pc' => $data['owner']['pc'],
                        'cc' => strtolower($data['owner']['cc']),
                        'voice' => $data['owner']['voice'],
                        'email' => $data['owner']['email']
                    ],
                    [
                        'registrar_id' => $registrar_id,
                        'type' => 'owner'
                    ]
                );

                $db->update(
                    'registrar_contact',
                    [
                        'first_name' => $data['billing']['first_name'],
                        'last_name' => $data['billing']['last_name'],
                        'org' => $data['billing']['org'],
                        'street1' => $data['billing']['street1'],
                        'city' => $data['billing']['city'],
                        'sp' => $data['billing']['sp'],
                        'pc' => $data['billing']['pc'],
                        'cc' => strtolower($data['billing']['cc']),
                        'voice' => $data['billing']['voice'],
                        'email' => $data['billing']['email']
                    ],
                    [
                        'registrar_id' => $registrar_id,
                        'type' => 'billing'
                    ]
                );
                
                $db->update(
                    'registrar_contact',
                    [
                        'first_name' => $data['abuse']['first_name'],
                        'last_name' => $data['abuse']['last_name'],
                        'org' => $data['abuse']['org'],
                        'street1' => $data['abuse']['street1'],
                        'city' => $data['abuse']['city'],
                        'sp' => $data['abuse']['sp'],
                        'pc' => $data['abuse']['pc'],
                        'cc' => strtolower($data['abuse']['cc']),
                        'voice' => $data['abuse']['voice'],
                        'email' => $data['abuse']['email']
                    ],
                    [
                        'registrar_id' => $registrar_id,
                        'type' => 'abuse'
                    ]
                );
                             
                if (!empty($data['ipAddress'])) {
                    $db->delete(
                        'registrar_whitelist',
                        [
                            'registrar_id' => $registrar_id
                        ]
                    );
                
                    foreach ($data['ipAddress'] as $ip) {
                        $db->insert(
                            'registrar_whitelist',
                            [
                                'registrar_id' => $registrar_id,
                                'addr' => $ip
                            ]
                        );
                    }
                }
                
                if ($data['panelPassword']) {
                    $panelPassword = password_hash($data['panelPassword'], PASSWORD_ARGON2ID, ['memory_cost' => 1024 * 128, 'time_cost' => 6, 'threads' => 4]);

                    $db->update(
                        'users',
                        [
                            'password' => $panelPassword,
                        ],
                        [
                            'email' => $regEmail
                        ]
                    );
                }
          
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/registrar/update/'.$registrar)->withStatus(302);
            }

            unset($_SESSION['registrars_to_update']);
            unset($_SESSION['registrars_user_email']);
            $this->container->get('flash')->addMessage('success', 'Registrar ' . $data['name'] . ' has been updated successfully on ' . $update);
            return $response->withHeader('Location', '/registrar/update/'.$registrar)->withStatus(302);
        }
    }
    
    public function editRegistrar(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            if (!empty($_SESSION['registrars_to_update'])) {
                $registrar = $_SESSION['registrars_to_update'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No registrar specified for update');
                return $response->withHeader('Location', '/registrars')->withStatus(302);
            }

            $data['ipAddress'] = array_filter($data['ipAddress']);
            $iso3166 = new ISO3166();
            $countries = $iso3166->all();

            $ipAddressValidator = v::when(
                v::arrayType()->notEmpty(), // Condition: If it's a non-empty array
                v::arrayType()->each(v::ip()), // Then: Each element must be a valid IP address
                v::equals('') // Else: Allow it to be an empty string
            );
            
            $data['owner']['cc'] = strtoupper($data['owner']['cc']);
            $data['billing']['cc'] = strtoupper($data['billing']['cc']);
            $data['abuse']['cc'] = strtoupper($data['abuse']['cc']);
            
            $phoneValidator = v::regex('/^\+\d{1,3}\.\d{2,12}$/');
       
            // Define validation for nested fields
            $contactValidator = [
                v::key('first_name', v::stringType()->notEmpty()->length(1, 255), true),
                v::key('last_name', v::stringType()->notEmpty()->length(1, 255), true),
                v::key('org', v::optional(v::stringType()->length(1, 255)), false),
                v::key('street1', v::optional(v::stringType()), false),
                v::key('city', v::stringType()->notEmpty(), true),
                v::key('sp', v::optional(v::stringType()), false),
                v::key('pc', v::optional(v::stringType()), false),
                v::key('cc', v::countryCode(), true),
                v::key('voice', v::optional($phoneValidator), false),
                v::key('fax', v::optional(v::phone()), false),
                v::key('email', v::email(), true)
            ];
            
            $validators = [
                'name' => v::stringType()->notEmpty()->length(1, 255),
                'ianaId' => v::optional(v::positive()->length(1, 5)),
                'email' => v::email(),
                'owner' => v::optional(v::keySet(...$contactValidator)),
                'billing' => v::optional(v::keySet(...$contactValidator)),
                'abuse' => v::optional(v::keySet(...$contactValidator)),
                'whoisServer' => v::domain(),
                'rdapServer' => v::domain(),
                'url' => v::url(),
                'abuseEmail' => v::email(),
                'abusePhone' => v::optional($phoneValidator),
                'ipAddress' => v::optional($ipAddressValidator)
            ];

            $errors = [];
            foreach ($validators as $field => $validator) {
                try {
                    $validator->assert(isset($data[$field]) ? $data[$field] : []);
                } catch (\Respect\Validation\Exceptions\NestedValidationException $e) {
                    $errors[$field] = $e->getMessages();
                }
            }

            if (!empty($errors)) {
                // Handle errors
                $errorText = '';

                foreach ($errors as $field => $messages) {
                    $errorText .= ucfirst($field) . ' errors: ' . implode(', ', $messages) . '; ';
                }

                // Trim the final semicolon and space
                $errorText = rtrim($errorText, '; ');
                
                $this->container->get('flash')->addMessage('error', $errorText);
                return $response->withHeader('Location', '/registrar/edit')->withStatus(302);
            }
            
            if (!empty($_SESSION['registrars_user_email'])) {
                $regEmail = $_SESSION['registrars_user_email'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No email specified for update');
                return $response->withHeader('Location', '/registrar/edit')->withStatus(302);
            }

            $db->beginTransaction();

            try {
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v');
                $currency = $_SESSION['_currency'] ?? 'USD';
                
                if (empty($data['ianaId']) || !is_numeric($data['ianaId'])) {
                    $data['ianaId'] = null;
                }
                
                $updateData = [
                    'name' => $data['name'],
                    'iana_id' => $data['ianaId'],
                    'email' => $data['email'],
                    'url' => $data['url'],
                    'whois_server' => $data['whoisServer'],
                    'rdap_server' => $data['rdapServer'],
                    'abuse_email' => $data['abuseEmail'],
                    'abuse_phone' => $data['abusePhone'],
                    'currency' => $currency,
                    'lastupdate' => $crdate
                ];
                
                if (!empty($data['eppPassword'])) {
                    $eppPassword = password_hash($data['eppPassword'], PASSWORD_ARGON2ID, ['memory_cost' => 1024 * 128, 'time_cost' => 6, 'threads' => 4]);
                    $updateData['pw'] = $eppPassword;
                }

                $db->update(
                    'registrar',
                    $updateData,
                    [
                        'clid' => $registrar
                    ]
                );
                $registrar_id = $db->selectValue(
                    'SELECT id FROM registrar WHERE clid = ?',
                    [$registrar]
                );
           
                $db->update(
                    'registrar_contact',
                    [
                        'first_name' => $data['owner']['first_name'],
                        'last_name' => $data['owner']['last_name'],
                        'org' => $data['owner']['org'],
                        'street1' => $data['owner']['street1'],
                        'city' => $data['owner']['city'],
                        'sp' => $data['owner']['sp'],
                        'pc' => $data['owner']['pc'],
                        'cc' => strtolower($data['owner']['cc']),
                        'voice' => $data['owner']['voice'],
                        'email' => $data['owner']['email']
                    ],
                    [
                        'registrar_id' => $registrar_id,
                        'type' => 'owner'
                    ]
                );

                $db->update(
                    'registrar_contact',
                    [
                        'first_name' => $data['billing']['first_name'],
                        'last_name' => $data['billing']['last_name'],
                        'org' => $data['billing']['org'],
                        'street1' => $data['billing']['street1'],
                        'city' => $data['billing']['city'],
                        'sp' => $data['billing']['sp'],
                        'pc' => $data['billing']['pc'],
                        'cc' => strtolower($data['billing']['cc']),
                        'voice' => $data['billing']['voice'],
                        'email' => $data['billing']['email']
                    ],
                    [
                        'registrar_id' => $registrar_id,
                        'type' => 'billing'
                    ]
                );
                
                $db->update(
                    'registrar_contact',
                    [
                        'first_name' => $data['abuse']['first_name'],
                        'last_name' => $data['abuse']['last_name'],
                        'org' => $data['abuse']['org'],
                        'street1' => $data['abuse']['street1'],
                        'city' => $data['abuse']['city'],
                        'sp' => $data['abuse']['sp'],
                        'pc' => $data['abuse']['pc'],
                        'cc' => strtolower($data['abuse']['cc']),
                        'voice' => $data['abuse']['voice'],
                        'email' => $data['abuse']['email']
                    ],
                    [
                        'registrar_id' => $registrar_id,
                        'type' => 'abuse'
                    ]
                );
                             
                if (!empty($data['ipAddress'])) {
                    $db->delete(
                        'registrar_whitelist',
                        [
                            'registrar_id' => $registrar_id
                        ]
                    );
                
                    foreach ($data['ipAddress'] as $ip) {
                        $db->insert(
                            'registrar_whitelist',
                            [
                                'registrar_id' => $registrar_id,
                                'addr' => $ip
                            ]
                        );
                    }
                }
                
                if ($data['panelPassword']) {
                    $panelPassword = password_hash($data['panelPassword'], PASSWORD_ARGON2ID, ['memory_cost' => 1024 * 128, 'time_cost' => 6, 'threads' => 4]);

                    $db->update(
                        'users',
                        [
                            'password' => $panelPassword,
                        ],
                        [
                            'email' => $regEmail
                        ]
                    );
                }
          
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/registrar/edit')->withStatus(302);
            }

            unset($_SESSION['registrars_to_update']);
            unset($_SESSION['registrars_user_email']);
            $this->container->get('flash')->addMessage('success', 'Registrar ' . $data['name'] . ' has been updated successfully on ' . $update);
            return $response->withHeader('Location', '/registrar/edit')->withStatus(302);
        }

        $db = $this->container->get('db');
        $iso3166 = new ISO3166();
        $countries = $iso3166->all();
        // Get the current URI
        $uri = $request->getUri()->getPath();
        $registrarId = $_SESSION['auth_registrar_id'];

        if (isset($registrarId) && $registrarId !== "") {       
            $registrar = $db->selectRow('SELECT * FROM registrar WHERE id = ?',
            [ $registrarId ]);

            if ($registrar) {
                $contacts = $db->select("SELECT * FROM registrar_contact WHERE registrar_id = ?",
                [ $registrar['id'] ]);
                $registrarOte = $db->select("SELECT * FROM registrar_ote WHERE registrar_id = ?",
                [ $registrar['id'] ]);
                $user_id = $db->selectValue("SELECT user_id FROM registrar_users WHERE registrar_id = ?",
                [ $registrar['id'] ]);
                $user = $db->selectRow("SELECT email FROM users WHERE id = ?",
                [ $user_id ]);
                $whitelist = $db->select("SELECT * FROM registrar_whitelist WHERE registrar_id = ?",
                [ $registrar['id'] ]);
                // Check if RegistrarOTE is not empty
                if (is_array($registrarOte) && !empty($registrarOte)) {
                    // Calculate the total number of elements
                    $totalElements = count($registrarOte);

                    // Calculate the size of the first half. If the total number is odd, add 1 to include the extra item in the first half.
                    $firstHalfSize = ceil($totalElements / 2);

                    // Split the array into two halves
                    $firstHalf = array_slice($registrarOte, 0, $firstHalfSize);
                    $secondHalf = array_slice($registrarOte, $firstHalfSize);
                } else {
                    // If RegistrarOTE is empty, set both halves to empty arrays
                    $firstHalf = [];
                    $secondHalf = [];
                }

                $_SESSION['registrars_to_update'] = [$registrar['clid']];
                $_SESSION['registrars_user_email'] = [$user['email']];

                return view($response,'admin/registrars/updateRegistrarUser.twig', [
                    'registrar' => $registrar,
                    'contacts' => $contacts,
                    'firstHalf' => $firstHalf,
                    'secondHalf' => $secondHalf,
                    'user' => $user,
                    'whitelist' => $whitelist,
                    'currentUri' => $uri,
                    'countries' => $countries
                ]);
            } else {
                // Registrar does not exist, redirect to the dashboard view
                return $response->withHeader('Location', '/dashboard')->withStatus(302);
            }
        } else {
            // Redirect to the registrars view
            return $response->withHeader('Location', '/registrars')->withStatus(302);
        }
    }
    
    public function oteCheck(Request $request, Response $response) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();
        
        if ($_SESSION["auth_roles"] != 0) {
            $reg_id = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if (!$reg_id) {
                return $response->withHeader('Location', '/dashboard')->withStatus(302);
            }
        } else {
            $queryParams = $request->getQueryParams();
            $reg_id = $queryParams['reg'] ?? '1';
        }

        if (!preg_match('/^\d+$/', $reg_id)) {
            $this->container->get('flash')->addMessage('error', 'Invalid registrar');
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
       
        $registrar = $db->selectRow('SELECT id,name FROM registrar WHERE id = ?',
        [ $reg_id ]);

        if ($registrar) {
            $host = envi('DB_HOST');
            $dsn = "mysql:host=$host;dbname=registryTransaction;charset=utf8mb4";

            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ];

            try {
                $pdo = new \PDO($dsn, envi('DB_USERNAME'), envi('DB_PASSWORD'), $options);
                $commands = $db->select('SELECT command FROM registrar_ote WHERE registrar_id = ? ORDER by command',
                [ $registrar['id'] ]);
                $commands = array_map(function($item) {
                    return $item['command'];
                }, $commands);
                
                // Function to execute query and fetch first result
                function fetchFirstMatchingResult($pdo, $sql, $params) {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    return $stmt->fetch(\PDO::FETCH_ASSOC); // Fetch the first row
                }

                foreach ($commands as $command) {
                    if (strpos($command, ':') !== false) {
                        // Command contains ':', split and prepare query for obj_type and cmd
                        list($obj_type, $cmd) = explode(':', $command, 2);
                        $sql = "SELECT * FROM transaction_identifier WHERE registrar_id = ? AND obj_type = ? AND cmd = ? LIMIT 1";
                        $params = [$reg_id, $obj_type, $cmd];
                    } else {
                        // Command is a single word
                        $sql = "SELECT * FROM transaction_identifier WHERE registrar_id = ? AND cmd = ? LIMIT 1";
                        $params = [$reg_id, $command];
                    }

                    $result = fetchFirstMatchingResult($pdo, $sql, $params);

                    if ($result) {
                        $cmd = '';
                        if (isset($result['cmd']) && ($result['cmd'] == 'login' || $result['cmd'] == 'logout')) {
                            $cmd = $result['cmd'];
                        } elseif (isset($result['obj_type']) && isset($result['cmd'])) {
                            $cmd = $result['obj_type'] . ':' . $result['cmd'];
                        }
                        if (isset($result['code']) && strpos(strval($result['code']), '1') === 0) {
                            $db->update(
                                'registrar_ote',
                                [
                                    'result' => 0
                                ],
                                [
                                    'registrar_id' => $reg_id,
                                    'command' => $cmd,
                                ]
                            );
                        } else {
                            $db->update(
                                'registrar_ote',
                                [
                                    'result' => 1
                                ],
                                [
                                    'registrar_id' => $reg_id,
                                    'command' => $cmd,
                                ]
                            );
                        }
                    }
                }
            } catch (\PDOException $e) {
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/registrar/view/'.$registrar['name'])->withStatus(302);
            } catch (Exception $e) {
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/registrar/view/'.$registrar['name'])->withStatus(302);
            } catch (Throwable $e) {
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/registrar/view/'.$registrar['name'])->withStatus(302);
            }
            
            $this->container->get('flash')->addMessage('success', 'Registrar test results updated successfully');
            return $response->withHeader('Location', '/registrar/view/'.$registrar['name'])->withStatus(302);
        } else {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
    }

}