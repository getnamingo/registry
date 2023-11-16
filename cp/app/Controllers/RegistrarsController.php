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

                return view($response, 'admin/registrars/create.twig', [
                    'countries' => $countries,
                    'error' => $errorText,
                ]);
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
                $eppPassword = password_hash($data['eppPassword'], PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 4, 'threads' => 4]);
                
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
                        'update' => $crdate
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
                
                $panelPassword = password_hash($data['panelPassword'], PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 4, 'threads' => 4]);

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
                return view($response, 'admin/registrars/create.twig', [
                    'error' => $e->getMessage(),
                    'countries' => $countries,
                ]);
            }

            return view($response,'admin/registrars/create.twig', [
                'registrar' => $data['name'],
                'countries' => $countries,
            ]);
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
            $registrar = $db->selectRow('SELECT * FROM registrar WHERE name = ?',
            [ $args ]);

            if ($registrar) {
                // Check if the user is not an admin (assuming role 0 is admin)
                if ($_SESSION["auth_roles"] != 0) {
                    $userRegistrars = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                    // Assuming $userRegistrars returns an array of arrays, each containing 'registrar_id'
                    $userRegistrarIds = array_column($userRegistrars, 'registrar_id');

                    // Check if the registrar's ID is in the user's list of registrar IDs
                    if (!in_array($registrars['id'], $userRegistrarIds)) {
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
                if (!empty($registrarOte)) {
                    // Split the results into two groups
                    $firstHalf = array_slice($registrarOte, 0, 5);
                    $secondHalf = array_slice($registrarOte, 5);
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
}