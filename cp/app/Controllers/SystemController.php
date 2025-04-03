<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Respect\Validation\Validator as v;
use League\ISO3166\ISO3166;
use Ramsey\Uuid\Uuid;

class SystemController extends Controller
{
    public function registry(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            
            // Error message initialization
            $error = '';

            // Check each field
            foreach ($data as $key => $value) {
                if (empty($value)) {
                    // Construct error message
                    $error .= "Error: '$key' cannot be empty.\n";
                }
            }

            // Display error messages if any
            if (!empty($error)) {
                $this->container->get('flash')->addMessage('error', $error);
                return $response->withHeader('Location', '/registry')->withStatus(302);
            }
            
            try {
                $db->beginTransaction();
                
                $currentDateTime = new \DateTime();
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['registryOperator']
                    ],
                    [
                        'name' => "company_name"
                    ]
                );
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['registryOperatorVat']
                    ],
                    [
                        'name' => "vat_number"
                    ]
                );

                $db->update(
                    'settings',
                    [
                        'value' => $data['contactAddress']
                    ],
                    [
                        'name' => "address"
                    ]
                );
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['contactAddress2']
                    ],
                    [
                        'name' => "address2"
                    ]
                );

                $db->update(
                    'settings',
                    [
                        'value' => $data['contactEmail']
                    ],
                    [
                        'name' => "email"
                    ]
                );
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['contactPhone']
                    ],
                    [
                        'name' => "phone"
                    ]
                );
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['registryHandle']
                    ],
                    [
                        'name' => "handle"
                    ]
                );
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['launchPhases']
                    ],
                    [
                        'name' => "launch_phases"
                    ]
                );
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['verifyPhone']
                    ],
                    [
                        'name' => "verifyPhone"
                    ]
                );
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['verifyEmail']
                    ],
                    [
                        'name' => "verifyEmail"
                    ]
                );
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['verifyPostal']
                    ],
                    [
                        'name' => "verifyPostal"
                    ]
                );
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['whoisServer']
                    ],
                    [
                        'name' => "whois_server"
                    ]
                );
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['rdapServer']
                    ],
                    [
                        'name' => "rdap_server"
                    ]
                );
                
                $db->update(
                    'settings',
                    [
                        'value' => $data['currency']
                    ],
                    [
                        'name' => "currency"
                    ]
                );

                $db->commit();
                $_SESSION['_currency'] = $data['currency'];
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/registry')->withStatus(302);
            }

            $currentDateTime = new \DateTime();
            $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
            $db->insert(
                'users_audit',
                [
                    'user_id' => $_SESSION['auth_user_id'],
                    'user_event' => 'settings.update',
                    'user_resource' => 'control.panel',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                    'user_ip' => get_client_ip(),
                    'user_location' => get_client_location(),
                    'event_time' => $currentDate,
                    'user_data' => null
                ]
            );

            $this->container->get('flash')->addMessage('success', 'Registry details have been updated successfully');
            return $response->withHeader('Location', '/registry')->withStatus(302);
            
        }

        $iso3166 = new ISO3166();
        $countries = $iso3166->all();
        $db = $this->container->get('db');
        $company_name = $db->selectValue("SELECT value FROM settings WHERE name = 'company_name'");
        $vat_number = $db->selectValue("SELECT value FROM settings WHERE name = 'vat_number'");
        $address = $db->selectValue("SELECT value FROM settings WHERE name = 'address'");
        $address2 = $db->selectValue("SELECT value FROM settings WHERE name = 'address2'");
        $phone = $db->selectValue("SELECT value FROM settings WHERE name = 'phone'");
        $email = $db->selectValue("SELECT value FROM settings WHERE name = 'email'");
        $handle = $db->selectValue("SELECT value FROM settings WHERE name = 'handle'");
        $launch_phases = $db->selectValue("SELECT value FROM settings WHERE name = 'launch_phases'");
        $whois_server = $db->selectValue("SELECT value FROM settings WHERE name = 'whois_server'");
        $rdap_server = $db->selectValue("SELECT value FROM settings WHERE name = 'rdap_server'");
        $currency = $db->selectValue("SELECT value FROM settings WHERE name = 'currency'");
        $verifyPhone = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyPhone'");
        $verifyEmail = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyEmail'");
        $verifyPostal = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyPostal'");
        
        $uniqueCurrencies = [];
        foreach ($countries as $country) {
            // Assuming each country has a 'currency' field with an array of currencies
            foreach ($country['currency'] as $currencyCode) {
                if (!array_key_exists($currencyCode, $uniqueCurrencies)) {
                    $uniqueCurrencies[$currencyCode] = $currencyCode; // Or any other currency detail you have
                }
            }
        }
        
        return view($response,'admin/system/registry.twig', [
            'company_name' => $company_name,
            'vat_number' => $vat_number,
            'address' => $address,
            'address2' => $address2,
            'phone' => $phone,
            'email' => $email,
            'handle' => $handle,
            'launch_phases' => $launch_phases,
            'whois_server' => $whois_server,
            'rdap_server' => $rdap_server,
            'uniqueCurrencies' => $uniqueCurrencies,
            'currency' => $currency,
            'verifyPhone' => $verifyPhone,
            'verifyEmail' => $verifyEmail,
            'verifyPostal' => $verifyPostal
        ]);
    }
    
    public function listTlds(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        $db = $this->container->get('db');

        return view($response,'admin/system/listTlds.twig');
    }
    
    public function createTld(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');

            if (isset($data['extension'])) {
                $extension = $data['extension'];

                // Remove any leading and trailing dots
                $extension = trim($extension, '.');

                // Add a dot at the beginning if it's missing
                if ($extension !== '' && $extension[0] !== '.') {
                    $extension = '.' . $extension;
                }

                // Store the modified 'extension' value back in $data
                $data['extension'] = $extension;
            }

            $validators = [
                'extension' => v::stringType()->notEmpty()->length(2, 64),
                'script' => v::stringType()->notEmpty(),
                'createm0' => v::numericVal()->between(0.00, 9999999.99, true),
                'createm12' => v::numericVal()->between(0.00, 9999999.99, true),
                'createm24' => v::numericVal()->between(0.00, 9999999.99, true),
                'createm36' => v::numericVal()->between(0.00, 9999999.99, true),
                'createm48' => v::numericVal()->between(0.00, 9999999.99, true),
                'createm60' => v::numericVal()->between(0.00, 9999999.99, true),
                'createm72' => v::numericVal()->between(0.00, 9999999.99, true),
                'createm84' => v::numericVal()->between(0.00, 9999999.99, true),
                'createm96' => v::numericVal()->between(0.00, 9999999.99, true),
                'createm108' => v::numericVal()->between(0.00, 9999999.99, true),
                'createm120' => v::numericVal()->between(0.00, 9999999.99, true),
                'renewm0' => v::numericVal()->between(0.00, 9999999.99, true),
                'renewm12' => v::numericVal()->between(0.00, 9999999.99, true),
                'renewm24' => v::numericVal()->between(0.00, 9999999.99, true),
                'renewm36' => v::numericVal()->between(0.00, 9999999.99, true),
                'renewm48' => v::numericVal()->between(0.00, 9999999.99, true),
                'renewm60' => v::numericVal()->between(0.00, 9999999.99, true),
                'renewm72' => v::numericVal()->between(0.00, 9999999.99, true),
                'renewm84' => v::numericVal()->between(0.00, 9999999.99, true),
                'renewm96' => v::numericVal()->between(0.00, 9999999.99, true),
                'renewm108' => v::numericVal()->between(0.00, 9999999.99, true),
                'renewm120' => v::numericVal()->between(0.00, 9999999.99, true),
                'transferm0' => v::numericVal()->between(0.00, 9999999.99, true),
                'transferm12' => v::numericVal()->between(0.00, 9999999.99, true),
                'transferm24' => v::numericVal()->between(0.00, 9999999.99, true),
                'transferm36' => v::numericVal()->between(0.00, 9999999.99, true),
                'transferm48' => v::numericVal()->between(0.00, 9999999.99, true),
                'transferm60' => v::numericVal()->between(0.00, 9999999.99, true),
                'transferm72' => v::numericVal()->between(0.00, 9999999.99, true),
                'transferm84' => v::numericVal()->between(0.00, 9999999.99, true),
                'transferm96' => v::numericVal()->between(0.00, 9999999.99, true),
                'transferm108' => v::numericVal()->between(0.00, 9999999.99, true),
                'transferm120' => v::numericVal()->between(0.00, 9999999.99, true),
                'restorePrice' => v::numericVal()->between(0.00, 9999999.99, true),
                'premiumNamesFile' => v::optional(v::file()->mimetype('text/csv')->size(5 * 1024 * 1024)),
                'categoryPrice1' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                'categoryPrice2' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                'categoryPrice3' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                'categoryPrice4' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                'categoryPrice5' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                'categoryPrice6' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                'categoryPrice7' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                'categoryPrice8' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                'categoryPrice9' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                'categoryPrice10' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                'categoryName1' => v::optional(v::stringType()->length(1, 50)),
                'categoryName2' => v::optional(v::stringType()->length(1, 50)),
                'categoryName3' => v::optional(v::stringType()->length(1, 50)),
                'categoryName4' => v::optional(v::stringType()->length(1, 50)),
                'categoryName5' => v::optional(v::stringType()->length(1, 50)),
                'categoryName6' => v::optional(v::stringType()->length(1, 50)),
                'categoryName7' => v::optional(v::stringType()->length(1, 50)),
                'categoryName8' => v::optional(v::stringType()->length(1, 50)),
                'categoryName9' => v::optional(v::stringType()->length(1, 50)),
                'categoryName10' => v::optional(v::stringType()->length(1, 50))
            ];

            $errors = [];
            foreach ($validators as $field => $validator) {
                // If the field is not set and it's optional, skip validation
                if (!isset($data[$field]) && strpos($field, 'category') === 0) {
                    continue;
                }

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
                return $response->withHeader('Location', '/registry/tld/create')->withStatus(302);
            }
         
            $result = $db->select(
                'SELECT id FROM domain_tld WHERE tld = ?',
                [ $data['extension'] ]
            );

            if (!empty($result)) {
                $this->container->get('flash')->addMessage('error', 'The TLD you are trying to add already exists');
                return $response->withHeader('Location', '/registry/tld/create')->withStatus(302);
            }
            
            switch ($data['script']) {
                case 'ascii':
                    $idntable = '/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i';
                    break;
                case 'cyrillic':
                    $idntable = '/^[а-яА-ЯґҐєЄіІїЇѝЍћЋљЈ0-9ʼѫѣѭ]+$/u';
                    break;
                case 'japanese':
                    $idntable = '/^[ぁ-んァ-ン一-龯々0-9]+$/u';
                    break;
                case 'korean':
                    $idntable = '/^[가-힣0-9]+$/u';
                    break;
                case 'arabic':
                    $idntable = '/^(?!-)(?!.*--)[\x{0621}-\x{064A}\x{0660}-\x{0669}\x{0671}-\x{06D3}-]{1,63}(?<!-)$/u';
                    break;
                default:
                    $idntable = '/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i';
                    break;
            }

            try {
                $db->beginTransaction();

                $currentDateTime = new \DateTime();
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                // Convert to Punycode if the domain is not in ASCII
                if (!mb_detect_encoding($data['extension'], 'ASCII', true)) {
                    $data['extension'] = str_replace('.', '', $data['extension']);
                    $convertedDomain = idn_to_ascii($data['extension'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    if ($convertedDomain === false) {
                        $this->container->get('flash')->addMessage('error', 'TLD conversion to Punycode failed');
                        return $response->withHeader('Location', '/registry/tld/create')->withStatus(302);
                    } else {
                        $data['extension'] = '.' . $convertedDomain;
                    }
                }

                $db->insert('domain_tld', [
                    'tld' => $data['extension'],
                    'idn_table' => $idntable,
                    'secure' => 0,
                ]);
                $tld_id = $db->getlastInsertId();

                $db->insert(
                    'domain_price',
                    [
                        'tldid' => $tld_id,
                        'command' => 'create',
                        'm0' => $data['createm0'],
                        'm12' => $data['createm12'],
                        'm24' => $data['createm24'],
                        'm36' => $data['createm36'],
                        'm48' => $data['createm48'],
                        'm60' => $data['createm60'],
                        'm72' => $data['createm72'],
                        'm84' => $data['createm84'],
                        'm96' => $data['createm96'],
                        'm108' => $data['createm108'],
                        'm120' => $data['createm120']
                    ]
                );
                
                $db->insert(
                    'domain_price',
                    [
                        'tldid' => $tld_id,
                        'command' => 'renew',
                        'm0' => $data['renewm0'],
                        'm12' => $data['renewm12'],
                        'm24' => $data['renewm24'],
                        'm36' => $data['renewm36'],
                        'm48' => $data['renewm48'],
                        'm60' => $data['renewm60'],
                        'm72' => $data['renewm72'],
                        'm84' => $data['renewm84'],
                        'm96' => $data['renewm96'],
                        'm108' => $data['renewm108'],
                        'm120' => $data['renewm120']
                    ]
                );
                
                $db->insert(
                    'domain_price',
                    [
                        'tldid' => $tld_id,
                        'command' => 'transfer',
                        'm0' => $data['transferm0'],
                        'm12' => $data['transferm12'],
                        'm24' => $data['transferm24'],
                        'm36' => $data['transferm36'],
                        'm48' => $data['transferm48'],
                        'm60' => $data['transferm60'],
                        'm72' => $data['transferm72'],
                        'm84' => $data['transferm84'],
                        'm96' => $data['transferm96'],
                        'm108' => $data['transferm108'],
                        'm120' => $data['transferm120']
                    ]
                );
                
                $db->insert(
                    'domain_restore_price',
                    [
                        'tldid' => $tld_id,
                        'price' => $data['restorePrice']
                    ]
                );
                
                for ($i = 1; $i <= 10; $i++) {
                    $categoryNameKey = 'categoryName' . $i;
                    $categoryPriceKey = 'categoryPrice' . $i;

                    if (isset($data[$categoryNameKey]) && isset($data[$categoryPriceKey]) && $data[$categoryNameKey] !== '' && $data[$categoryPriceKey] !== '') {
                        $db->exec(
                            'INSERT INTO premium_domain_categories (category_name, category_price) VALUES (?, ?) ON DUPLICATE KEY UPDATE category_price = VALUES(category_price)',
                            [
                                $data[$categoryNameKey],
                                $data[$categoryPriceKey]
                            ]
                        );
                    }
                }

                $uploadedFiles = $request->getUploadedFiles();

                if (!empty($uploadedFiles['premiumNamesFile'])) {
                    $file = $uploadedFiles['premiumNamesFile'];

                    // Check if the upload was successful
                    if ($file->getError() !== UPLOAD_ERR_OK) {
                        $this->container->get('flash')->addMessage('error', 'Upload failed with error code ' . $file->getError());
                        return $response->withHeader('Location', '/registry/tld/create')->withStatus(302);
                    }

                    // Validate file type and size
                    if ($file->getClientMediaType() !== 'text/csv' || $file->getSize() > 5 * 1024 * 1024) {
                        $this->container->get('flash')->addMessage('error', 'Invalid file type or size');
                        return $response->withHeader('Location', '/registry/tld/create')->withStatus(302);
                    }

                    // Process the CSV file
                    $stream = $file->getStream();
                    $csvContent = $stream->getContents();

                    $lines = explode(PHP_EOL, $csvContent);
                    foreach ($lines as $line) {
                        $data = str_getcsv($line);
                        if (count($data) >= 2) {
                            $domainName = $data[0];
                            $categoryName = $data[1];

                            // Find the category ID
                            $categoryResult = $this->db->select("SELECT id FROM premium_domain_categories WHERE category_name = :categoryName", ['categoryName' => $categoryName]);

                            if ($categoryResult) {
                                $categoryId = $categoryResult[0]['id'];

                                // Insert into premium_domain_pricing
                                $db->exec(
                                    'INSERT INTO premium_domain_pricing (domain_name, category_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id)',
                                    [
                                        $domainName,
                                        $categoryId
                                    ]
                                );
                            } else {
                                $this->container->get('flash')->addMessage('error', 'Premium names category ' . $categoryName . ' not found');
                                return $response->withHeader('Location', '/registry/tld/create')->withStatus(302);
                            }
                        }
                    }
                }

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/registry/tld/create')->withStatus(302);
            }

            $currentDateTime = new \DateTime();
            $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
            $db->insert(
                'users_audit',
                [
                    'user_id' => $_SESSION['auth_user_id'],
                    'user_event' => 'tld.created',
                    'user_resource' => 'control.panel',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                    'user_ip' => get_client_ip(),
                    'user_location' => get_client_location(),
                    'event_time' => $currentDate,
                    'user_data' => null
                ]
            );

            $this->container->get('flash')->addMessage('success', 'TLD ' . $data['extension'] . ' has been created successfully');
            return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
        }
        
        $db = $this->container->get('db');

        return view($response,'admin/system/createTld.twig');
    }

    public function manageTld(Request $request, Response $response, $args)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            
            if ($args) {
                $args = trim($args);
                
                if (!empty($_SESSION['u_tld_extension'])) {
                    $tld_extension = $_SESSION['u_tld_extension'][0];
                } else {
                    $this->container->get('flash')->addMessage('error', 'No TLD specified for update');
                    return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
                }

                if (!preg_match('/^\.(xn--[a-zA-Z0-9-]+|[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)?)$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid TLD format');
                    return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
                }

                $validators = [
                    'createm0' => v::numericVal()->between(0.00, 9999999.99, true),
                    'createm12' => v::numericVal()->between(0.00, 9999999.99, true),
                    'createm24' => v::numericVal()->between(0.00, 9999999.99, true),
                    'createm36' => v::numericVal()->between(0.00, 9999999.99, true),
                    'createm48' => v::numericVal()->between(0.00, 9999999.99, true),
                    'createm60' => v::numericVal()->between(0.00, 9999999.99, true),
                    'createm72' => v::numericVal()->between(0.00, 9999999.99, true),
                    'createm84' => v::numericVal()->between(0.00, 9999999.99, true),
                    'createm96' => v::numericVal()->between(0.00, 9999999.99, true),
                    'createm108' => v::numericVal()->between(0.00, 9999999.99, true),
                    'createm120' => v::numericVal()->between(0.00, 9999999.99, true),
                    'renewm0' => v::numericVal()->between(0.00, 9999999.99, true),
                    'renewm12' => v::numericVal()->between(0.00, 9999999.99, true),
                    'renewm24' => v::numericVal()->between(0.00, 9999999.99, true),
                    'renewm36' => v::numericVal()->between(0.00, 9999999.99, true),
                    'renewm48' => v::numericVal()->between(0.00, 9999999.99, true),
                    'renewm60' => v::numericVal()->between(0.00, 9999999.99, true),
                    'renewm72' => v::numericVal()->between(0.00, 9999999.99, true),
                    'renewm84' => v::numericVal()->between(0.00, 9999999.99, true),
                    'renewm96' => v::numericVal()->between(0.00, 9999999.99, true),
                    'renewm108' => v::numericVal()->between(0.00, 9999999.99, true),
                    'renewm120' => v::numericVal()->between(0.00, 9999999.99, true),
                    'transferm0' => v::numericVal()->between(0.00, 9999999.99, true),
                    'transferm12' => v::numericVal()->between(0.00, 9999999.99, true),
                    'transferm24' => v::numericVal()->between(0.00, 9999999.99, true),
                    'transferm36' => v::numericVal()->between(0.00, 9999999.99, true),
                    'transferm48' => v::numericVal()->between(0.00, 9999999.99, true),
                    'transferm60' => v::numericVal()->between(0.00, 9999999.99, true),
                    'transferm72' => v::numericVal()->between(0.00, 9999999.99, true),
                    'transferm84' => v::numericVal()->between(0.00, 9999999.99, true),
                    'transferm96' => v::numericVal()->between(0.00, 9999999.99, true),
                    'transferm108' => v::numericVal()->between(0.00, 9999999.99, true),
                    'transferm120' => v::numericVal()->between(0.00, 9999999.99, true),
                    'restorePrice' => v::numericVal()->between(0.00, 9999999.99, true),
                    'premiumNamesFile' => v::optional(v::file()->mimetype('text/csv')->size(5 * 1024 * 1024)),
                    'categoryPrice1' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPrice2' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPrice3' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPrice4' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPrice5' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPrice6' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPrice7' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPrice8' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPrice9' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPrice10' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPriceNew1' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPriceNew2' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPriceNew3' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPriceNew4' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryPriceNew5' => v::optional(v::numericVal()->between(0.00, 9999999.99, true)),
                    'categoryName1' => v::optional(v::stringType()->length(1, 50)),
                    'categoryName2' => v::optional(v::stringType()->length(1, 50)),
                    'categoryName3' => v::optional(v::stringType()->length(1, 50)),
                    'categoryName4' => v::optional(v::stringType()->length(1, 50)),
                    'categoryName5' => v::optional(v::stringType()->length(1, 50)),
                    'categoryName6' => v::optional(v::stringType()->length(1, 50)),
                    'categoryName7' => v::optional(v::stringType()->length(1, 50)),
                    'categoryName8' => v::optional(v::stringType()->length(1, 50)),
                    'categoryName9' => v::optional(v::stringType()->length(1, 50)),
                    'categoryName10' => v::optional(v::stringType()->length(1, 50)),
                    'categoryNameNew1' => v::optional(v::stringType()->length(1, 50)),
                    'categoryNameNew2' => v::optional(v::stringType()->length(1, 50)),
                    'categoryNameNew3' => v::optional(v::stringType()->length(1, 50)),
                    'categoryNameNew4' => v::optional(v::stringType()->length(1, 50)),
                    'categoryNameNew5' => v::optional(v::stringType()->length(1, 50))
                ];

                $errors = [];
                foreach ($validators as $field => $validator) {
                    // If the field is not set and it's optional, skip validation
                    if (!isset($data[$field]) && strpos($field, 'category') === 0) {
                        continue;
                    }

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
                    return $response->withHeader('Location', '/registry/tld/'.$tld_extension)->withStatus(302);
                }

                try {
                    $db->beginTransaction();
                    
                    $tld_id = $db->selectValue(
                        'SELECT id FROM domain_tld WHERE tld = ?',
                        [$tld_extension]
                    );
                    
                    $db->update(
                        'domain_price',
                        [
                            'm0' => $data['createm0'],
                            'm12' => $data['createm12'],
                            'm24' => $data['createm24'],
                            'm36' => $data['createm36'],
                            'm48' => $data['createm48'],
                            'm60' => $data['createm60'],
                            'm72' => $data['createm72'],
                            'm84' => $data['createm84'],
                            'm96' => $data['createm96'],
                            'm108' => $data['createm108'],
                            'm120' => $data['createm120']
                        ],
                        [
                            'tldid' => $tld_id,
                            'command' => 'create'
                        ]
                    );
                    
                    $db->update(
                        'domain_price',
                        [
                            'm0' => $data['renewm0'],
                            'm12' => $data['renewm12'],
                            'm24' => $data['renewm24'],
                            'm36' => $data['renewm36'],
                            'm48' => $data['renewm48'],
                            'm60' => $data['renewm60'],
                            'm72' => $data['renewm72'],
                            'm84' => $data['renewm84'],
                            'm96' => $data['renewm96'],
                            'm108' => $data['renewm108'],
                            'm120' => $data['renewm120']
                        ],
                        [
                            'tldid' => $tld_id,
                            'command' => 'renew'
                        ]
                    );
                    
                    $db->update(
                        'domain_price',
                        [
                            'm0' => $data['transferm0'],
                            'm12' => $data['transferm12'],
                            'm24' => $data['transferm24'],
                            'm36' => $data['transferm36'],
                            'm48' => $data['transferm48'],
                            'm60' => $data['transferm60'],
                            'm72' => $data['transferm72'],
                            'm84' => $data['transferm84'],
                            'm96' => $data['transferm96'],
                            'm108' => $data['transferm108'],
                            'm120' => $data['transferm120']
                        ],
                        [
                            'tldid' => $tld_id,
                            'command' => 'transfer'
                        ]
                    );

                    $db->update(
                        'domain_restore_price',
                        [
                            'price' => $data['restorePrice']
                        ],
                        [
                            'tldid' => $tld_id
                        ]
                    );

                    // Loop through category indices from 1 to 10
                    for ($i = 1; $i <= 10; $i++) {
                        $categoryNameKey = 'categoryName' . $i;
                        $categoryPriceKey = 'categoryPrice' . $i;

                        // Check if the category name is provided and non-empty
                        if (!empty($data[$categoryNameKey])) {
                            $db->update(
                                'premium_domain_categories',
                                [
                                    'category_price' => $data[$categoryPriceKey]
                                ],
                                [
                                    'category_name' => $data[$categoryNameKey]
                                ]
                            );
                        }
                    }
                    
                    for ($i = 1; $i <= 5; $i++) {
                        $categoryNameNewKey = 'categoryNameNew' . $i;
                        $categoryPriceNewKey = 'categoryPriceNew' . $i;

                        if (isset($data[$categoryNameNewKey]) && isset($data[$categoryPriceNewKey]) && $data[$categoryNameNewKey] !== '' && $data[$categoryPriceNewKey] !== '') {
                            $db->exec(
                                'INSERT INTO premium_domain_categories (category_name, category_price) VALUES (?, ?) ON DUPLICATE KEY UPDATE category_price = VALUES(category_price)',
                                [
                                    $data[$categoryNameNewKey],
                                    $data[$categoryPriceNewKey]
                                ]
                            );
                        }
                    }

                    $uploadedFiles = $request->getUploadedFiles();

                    if (!empty($uploadedFiles['premiumNamesFile'])) {
                        $file = $uploadedFiles['premiumNamesFile'];

                        // Check if the upload was successful
                        if ($file->getError() !== UPLOAD_ERR_OK) {
                            $this->container->get('flash')->addMessage('error', 'Upload failed with error code ' . $file->getError());
                            return $response->withHeader('Location', '/registry/tld/'.$tld_extension)->withStatus(302);
                        }

                        // Validate file type and size
                        if ($file->getClientMediaType() !== 'text/csv' || $file->getSize() > 5 * 1024 * 1024) {
                            $this->container->get('flash')->addMessage('error', 'Invalid file type or size');
                            return $response->withHeader('Location', '/registry/tld/'.$tld_extension)->withStatus(302);
                        }

                        // Process the CSV file
                        $stream = $file->getStream();
                        $csvContent = $stream->getContents();

                        $lines = explode(PHP_EOL, $csvContent);
                        foreach ($lines as $line) {
                            $data = str_getcsv($line);
                            if (count($data) >= 2) {
                                $domainName = $data[0];
                                $categoryName = $data[1];

                                // Find the category ID
                                $categoryResult = $this->db->select("SELECT id FROM premium_domain_categories WHERE category_name = :categoryName", ['categoryName' => $categoryName]);

                                if ($categoryResult) {
                                    $categoryId = $categoryResult[0]['id'];

                                    // Insert into premium_domain_pricing
                                    $db->exec(
                                        'INSERT INTO premium_domain_pricing (domain_name, category_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id)',
                                        [
                                            $domainName,
                                            $categoryId
                                        ]
                                    );
                                } else {
                                    $this->container->get('flash')->addMessage('error', 'Premium names category ' . $categoryName . ' not found');
                                    return $response->withHeader('Location', '/registry/tld/'.$tld_extension)->withStatus(302);
                                }
                            }
                        }
                    }

                    $db->commit();

                    unset($_SESSION['u_tld_id']);
                    unset($_SESSION['u_tld_extension']);
                    
                    $currentDateTime = new \DateTime();
                    $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
                    $db->insert(
                        'users_audit',
                        [
                            'user_id' => $_SESSION['auth_user_id'],
                            'user_event' => 'tld.updated',
                            'user_resource' => 'control.panel',
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                            'user_ip' => get_client_ip(),
                            'user_location' => get_client_location(),
                            'event_time' => $currentDate,
                            'user_data' => null
                        ]
                    );

                    $this->container->get('flash')->addMessage('success', 'TLD ' . $tld_extension . ' has been updated successfully');
                    return $response->withHeader('Location', '/registry/tld/'.$tld_extension)->withStatus(302);
                } catch (Exception $e) {
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                    return $response->withHeader('Location', '/registry/tld/'.$tld_extension)->withStatus(302);
                }
            } else {
                // Redirect to the tlds view
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }
        }
        
        $db = $this->container->get('db');
        
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^\.(xn--[a-zA-Z0-9-]+|[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)?)$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid TLD format');
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }
                
            $tld = $db->selectRow('SELECT id, tld, idn_table, secure FROM domain_tld WHERE tld = ?',
            [ $args ]);

            if ($tld) {
                $createPrices = $db->selectRow('SELECT * FROM domain_price WHERE tldid = ? AND registrar_id IS NULL AND command = ?', [$tld['id'], 'create']);
                $renewPrices = $db->selectRow('SELECT * FROM domain_price WHERE tldid = ? AND registrar_id IS NULL AND command = ?', [$tld['id'], 'renew']);
                $transferPrices = $db->selectRow('SELECT * FROM domain_price WHERE tldid = ? AND registrar_id IS NULL AND command = ?', [$tld['id'], 'transfer']);
                $tld_restore = $db->selectRow('SELECT * FROM domain_restore_price WHERE tldid = ? AND registrar_id IS NULL ',
                [ $tld['id'] ]);
                $premium_pricing = $db->selectRow('SELECT * FROM premium_domain_pricing WHERE tld_id = ?',
                [ $tld['id'] ]);
                $premium_categories = $db->select('SELECT * FROM premium_domain_categories');

                // Mapping of regex patterns to script names
                $regexToScriptName = [
                    '/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i' => 'ASCII',
                    '/^[а-яА-ЯґҐєЄіІїЇѝЍћЋљЈ0-9ʼѫѣѭ]+$/u' => 'Cyrillic',
                    '/^[ぁ-んァ-ン一-龯々]+$/u' => 'Japanese',
                    '/^[가-힣]+$/u' => 'Korean',
                    '/^(?!-)(?!.*--)[\x{0621}-\x{064A}\x{0660}-\x{0669}\x{0671}-\x{06D3}-]{1,63}(?<!-)$/u' => 'Arabic',
                ];

                $idnRegex = $tld['idn_table'];
                $scriptName = '';

                // Determine the script name based on the regex
                if (array_key_exists($idnRegex, $regexToScriptName)) {
                    $scriptName = $regexToScriptName[$idnRegex];
                } else {
                    $scriptName = 'Unknown'; // Default or fallback script name
                }

                if (strpos(strtolower($tld['tld']), '.xn--') === 0) {
                    $tld['tld'] = ltrim($tld['tld'], '.');
                    $tld_u = '.'.idn_to_utf8($tld['tld'], 0, INTL_IDNA_VARIANT_UTS46);
                    $tld['tld'] = '.'.$tld['tld'];
                } else {
                    $tld_u = $tld['tld'];
                }

                $_SESSION['u_tld_id'] = [$tld['id']];
                $_SESSION['u_tld_extension'] = [$tld['tld']];

                $secureTld = $tld['secure'];
                if ($secureTld === 1) {
                    if (file_exists('/usr/sbin/rndc')) {
                        $zone = ltrim($tld['tld'], '.');
                        $statusOutput = shell_exec("sudo rndc dnssec -status " . escapeshellarg($zone) . " 2>&1");

                        if (!$statusOutput) {
                            $dnssecData = ['error' => "Unable to fetch DNSSEC status for $zone."];
                        } else {
                            // Extract all KSKs regardless of algorithm
                            preg_match_all('/key: (\d+) \((\w+)\), KSK/', $statusOutput, $matches, PREG_SET_ORDER);

                            $dnssecData = [
                                'zoneName' => $tld['tld'],
                                'timestamp' => date('Y-m-d H:i:s'),
                                'keys' => [],
                            ];

                            foreach ($matches as $match) {
                                $keyId = $match[1];
                                $algorithm = $match[2];
                                
                                // Convert algorithm name to corresponding number for dnssec-dsfromkey
                                $algoMap = [
                                    'RSASHA1' => '005', 'RSASHA1-NSEC3-SHA1' => '007',
                                    'RSASHA256' => '008', 'RSASHA512' => '010',
                                    'ECDSAP256SHA256' => '013', 'ECDSAP384SHA384' => '014',
                                    'ED25519' => '015', 'ED448' => '016'
                                ];
                                $algoNum = $algoMap[$algorithm] ?? '000'; // Default to unknown if missing

                                // Determine if key is active or in rollover state
                                preg_match("/key: $keyId.*?(?=\\nkey:|\\z)/s", $statusOutput, $keyBlockMatch);
                                $keyBlock = $keyBlockMatch[0] ?? '';

                                // Skip keys explicitly removed from the zone
                                if (strpos($keyBlock, 'Key has been removed from the zone') !== false) {
                                    continue;
                                }

                                // Determine key status accurately
                                $keyStatus = strpos($keyBlock, 'key signing:    yes') !== false ? 'Active' : 'Pending Rollover';

                                // Extract next rollover date
                                preg_match('/Next rollover scheduled on ([^\\n]+)/', $keyBlock, $rolloverMatch);
                                $nextRollover = $rolloverMatch[1] ?? null;

                                // Extract retirement date, if present
                                preg_match('/Key.*removed.*on ([^\\n]+)/', $keyBlock, $retirementMatch);
                                $retirementDate = $retirementMatch[1] ?? null;
                                
                                // Extract published date
                                preg_match('/published:\s+yes\s+-\s+since\s+([^\n]+)/', $keyBlock, $publishedMatch);
                                $publishedDate = isset($publishedMatch[1]) ? trim($publishedMatch[1]) : null;

                                // Extract DS status ("rumoured" or "omnipresent")
                                preg_match('/- ds:\s+(\w+)/', $keyBlock, $dsMatch);
                                $dsStatus = $dsMatch[1] ?? null;

                                // Extract DS record for this key
                                $dsRecord = shell_exec("dnssec-dsfromkey -2 /var/lib/bind/K{$zone}.+{$algoNum}+{$keyId}.key");
                                $dsRecord = $dsRecord ? trim($dsRecord) : 'N/A';

                                // Append key details
                                $dnssecData['keys'][] = [
                                    'key_id' => $keyId,
                                    'algorithm' => $algorithm,
                                    'ds_record' => $dsRecord,
                                    'status' => $keyStatus,
                                    'timestamp' => date('Y-m-d H:i:s'),
                                    'next_rollover' => $nextRollover,
                                    'retirement_date' => $retirementDate,
                                    'published_date' => $publishedDate,
                                    'ds_status' => $dsStatus,
                                ];
                            }

                            // If no keys were found, set an error message
                            if (empty($dnssecData['keys'])) {
                                $dnssecData = ['error' => "No DNSSEC keys found for $zone."];
                            }
                        }
                    } elseif (file_exists('/usr/sbin/knotc')) {
                        $zone = ltrim($tld['tld'], '.');
                        $keyDir = '/etc/knot/keys';

                        // Use knotc to get key statuses
                        $keyListOutput = shell_exec("sudo knotc zone-key list " . escapeshellarg($zone) . " 2>&1");

                        if (!$keyListOutput) {
                            $dnssecData = ['error' => "Unable to fetch DNSSEC status for $zone (Knot DNS)."];
                        } else {
                            preg_match_all('/([0-9]+)\s+KSK\s+(\w+)\s+(\w+)\s+(\w+)\s+([0-9T:-]+)/', $keyListOutput, $matches, PREG_SET_ORDER);

                            $dnssecData = [
                                'zoneName' => '.' . $zone,
                                'timestamp' => date('Y-m-d H:i:s'),
                                'keys' => [],
                            ];

                            foreach ($matches as $match) {
                                $keyId = $match[1];
                                $algorithm = 'ED25519'; // Knot uses policy-defined algorithm
                                $keyStatus = ($match[2] === 'active') ? 'Active' : 'Pending Rollover';
                                $publishedDate = $match[5] ?? null;

                                // Extract DS record from keymgr
                                $dsRecord = shell_exec("keymgr ds " . escapeshellarg($zone) . " 2>/dev/null");
                                $dsRecord = $dsRecord ? trim($dsRecord) : 'N/A';

                                // Since Knot DNS doesn't explicitly show rollover in this output, set as null
                                $nextRollover = null;
                                $retirementDate = null;

                                // DS Status isn't directly shown; you might manually track submission
                                $dsStatus = null;

                                $dnssecData['keys'][] = [
                                    'key_id' => $match[1],
                                    'algorithm' => 'ED25519',
                                    'ds_record' => $dsRecord,
                                    'status' => (strpos($match[0], 'active') !== false ? 'Active' : 'Pending Rollover'),
                                    'timestamp' => date('Y-m-d H:i:s'),
                                    'next_rollover' => $nextRollover,
                                    'retirement_date' => null,
                                    'published_date' => $publishedDate,
                                    'ds_status' => null,
                                ];
                            }

                            if (empty($dnssecData['keys'])) {
                                $dnssecData = ['error' => "No DNSSEC keys found for $zone using Knot DNS."];
                            }
                        }
                    }
                } else {
                    $dnssecData = ['error' => "DNSSEC is not enabled for this TLD."];
                }

                if (!empty($launch_phases) && is_array($launch_phases)) {
                    $launch_phases = array_map(function ($phase) {
                        if (!in_array($phase['phase_type'], ['custom', 'open'])) {
                            $phase['phase_name'] = '';
                        }
                        return $phase;
                    }, $launch_phases);
                }

                return view($response,'admin/system/manageTld.twig', [
                    'tld' => $tld,
                    'tld_u' => $tld_u,
                    'scriptName' => $scriptName,
                    'createPrices' => $createPrices,
                    'renewPrices' => $renewPrices,
                    'transferPrices' => $transferPrices,
                    'tld_restore' => $tld_restore,
                    'premium_pricing' => $premium_pricing,
                    'premium_categories' => $premium_categories,
                    'secureTld' => $secureTld,
                    'dnssecData' => $dnssecData,
                    'currentUri' => $uri
                ]);
            } else {
                // TLD does not exist, redirect to the tlds view
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }

        } else {
            // Redirect to the tlds view
            return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
        }

    }
    
    public function manageReserved(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            
            $domainCategories = [];

            foreach ($data as $key => $value) {
                if (strpos($key, 'domains_') === 0) { // Check if the key starts with 'domains_'
                    $domains = explode("\n", trim($value));
                    $domains = array_filter(array_map('trim', $domains));
                    $domainCategories[substr($key, 8)] = $domains;
                }
            }

            try {
                // Fetch existing names
                $existingDomains = $db->select('SELECT name, type FROM reserved_domain_names');

                // Organize existing names by type
                $existingByType = [];
                foreach ($existingDomains as $domain) {
                    $existingByType[$domain['type']][] = $domain['name'];
                }

                $db->beginTransaction();

                foreach ($domainCategories as $type => $submittedDomains) {
                    // Find domains to delete
                    $domainsToDelete = array_diff($existingByType[$type] ?? [], $submittedDomains);

                    // Delete domains not in the submitted list
                    foreach ($domainsToDelete as $domain) {
                        $db->exec(
                            "DELETE FROM reserved_domain_names WHERE name = ? AND type = ?",
                            [$domain, $type]
                        );
                    }

                    // Insert or ignore new domains
                    foreach ($submittedDomains as $domain) {
                        $db->exec(
                            "INSERT IGNORE INTO reserved_domain_names (name, type) VALUES (?, ?)",
                            [$domain, $type]
                        );
                    }
                }

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/registry/reserved')->withStatus(302);
            }

            $currentDateTime = new \DateTime();
            $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
            $db->insert(
                'users_audit',
                [
                    'user_id' => $_SESSION['auth_user_id'],
                    'user_event' => 'reserved.updated',
                    'user_resource' => 'control.panel',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                    'user_ip' => get_client_ip(),
                    'user_location' => get_client_location(),
                    'event_time' => $currentDate,
                    'user_data' => null
                ]
            );

            $this->container->get('flash')->addMessage('success', 'Reserved names have been updated successfully');
            return $response->withHeader('Location', '/registry/reserved')->withStatus(302);
            
        }

        $db = $this->container->get('db');
        $uri = $request->getUri()->getPath();
        $typesResult = $db->select("SELECT DISTINCT type FROM reserved_domain_names");

        // Initialize $types as an empty array if the query result is null
        $types = $typesResult ?: [];

        // Ensure all default types are represented
        $defaultTypes = ['reserved', 'restricted'];
        foreach ($defaultTypes as $defaultType) {
            $found = false;
            foreach ($types as $type) {
                if ($type['type'] === $defaultType) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $types[] = ['type' => $defaultType];
            }
        }

        $categories = [];
        foreach ($types as $type) {
            $typeNames = $db->select(
                'SELECT name FROM reserved_domain_names WHERE type = ?',
                [ $type['type'] ]
            );

            $categories[$type['type']] = $typeNames ? array_column($typeNames, 'name') : [];
        }

        return view($response,'admin/system/manageReserved.twig', [
            'categories' => $categories,
            'currentUri' => $uri
        ]);
    }

    public function generateTokens(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $db = $this->container->get('db');

        try {         
            $currentDateTime = new \DateTime();
            $crdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

            $db->beginTransaction();

            for ($i = 0; $i < 10; $i++) {
                $uuid = Uuid::uuid4()->toString();

                $db->insert('allocation_tokens', [
                    'token' => $uuid,
                    'domain_name' => null,
                    'tokenStatus' => 'new',
                    'tokenType' => 'simple',
                    'crdate' => $crdate
                ]);
            }

            $db->commit();

            $this->container->get('flash')->addMessage('success', '10 tokens successfully created');
            return $response->withHeader('Location', '/registry/tokens')->withStatus(302);
        } catch (Exception $e) {
            $db->rollBack();
            $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
            return $response->withHeader('Location', '/registry/tokens')->withStatus(302);
        }
    }

    public function manageTokens(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        $uri = $request->getUri()->getPath();

        return view($response,'admin/system/manageTokens.twig', [
            'currentUri' => $uri
        ]);
    }

    public function updateToken(Request $request, Response $response, $args)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^[a-zA-Z0-9\-]+$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid token format');
                return $response->withHeader('Location', '/registry/tokens')->withStatus(302);
            }

            $token = $db->selectRow('SELECT token, domain_name, crdate, lastupdate, tokenStatus FROM allocation_tokens WHERE token = ?', [ $args ]);
            if ($token) {
                $_SESSION['token_to_update'] = $token;
                return view($response,'admin/system/updateToken.twig', [
                    'token' => $token,
                    'currentUri' => $uri
                ]);
            } else {
                // Token does not exist, redirect to the tokens view
                return $response->withHeader('Location', '/registry/tokens')->withStatus(302);
            }
        } else {
            // Redirect to the tokens view
            return $response->withHeader('Location', '/registry/tokens')->withStatus(302);
        }
    }

    public function updateTokenProcess(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            if (!empty($_SESSION['token_to_update'])) {
                $token = $_SESSION['token_to_update']['token'];
            } else {
                $this->container->get('flash')->addMessage('error', 'No token specified for update');
                return $response->withHeader('Location', '/registry/tokens')->withStatus(302);
            }
            $domain_name = $data['domain_name'] ?? null;

            if (empty($domain_name)) {
                $this->container->get('flash')->addMessage('error', 'Domain name must be provided');
                return $response->withHeader('Location', '/registry/tokens/update/'.$token)->withStatus(302);
            }

            $invalid_domain = validate_label($domain_name, $this->container->get('db'));
            if ($invalid_domain) {
                $this->container->get('flash')->addMessage('error', 'Domain ' . $domainName . ' is invalid: ' . $invalid_domain);
                return $response->withHeader('Location', '/registry/tokens/update/'.$token)->withStatus(302);
            }

            try {
                $db->beginTransaction();

                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                $db->update('allocation_tokens', [
                    'domain_name' => $domain_name,
                    'tokenStatus' => 'active',
                    'lastupdate' => $update
                ],
                [
                    'token' => $token
                ]
                );

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/registry/tokens/update/'.$token)->withStatus(302);
            }

            unset($_SESSION['token_to_update']);
            $this->container->get('flash')->addMessage('success', 'Token ' . $token . ' has been updated successfully on ' . $update);
            return $response->withHeader('Location', '/registry/tokens/update/'.$token)->withStatus(302);
        }
    }

    public function deleteToken(Request $request, Response $response, $args)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

       // if ($request->getMethod() === 'POST') {
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();

            if ($args) {
                $args = trim($args);

                if (!preg_match('/^[a-zA-Z0-9\-]+$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid token format');
                    return $response->withHeader('Location', '/registry/tokens')->withStatus(302);
                }

                $db->delete(
                    'allocation_tokens',
                    [
                        'token' => $args
                    ]
                );
                    
                $this->container->get('flash')->addMessage('success', 'Token ' . $args . ' deleted successfully');
                return $response->withHeader('Location', '/registry/tokens')->withStatus(302);
            } else {
                // Redirect to the tokens view
                return $response->withHeader('Location', '/registry/tokens')->withStatus(302);
            }

        //}
    }

    public function viewPromo(Request $request, Response $response, $args)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^\.(xn--[a-zA-Z0-9-]+|[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)?)$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid TLD format');
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }
                
            $tld = $db->selectRow('SELECT id, tld, idn_table, secure FROM domain_tld WHERE tld = ?',
            [ $args ]);

            if ($tld) {
                $promotions = $db->select('SELECT * FROM promotion_pricing WHERE tld_id = ?',
                [ $tld['id'] ]);

                if (strpos(strtolower($tld['tld']), '.xn--') === 0) {
                    $tld['tld'] = ltrim($tld['tld'], '.');
                    $tld_u = '.'.idn_to_utf8($tld['tld'], 0, INTL_IDNA_VARIANT_UTS46);
                    $tld['tld'] = '.'.$tld['tld'];
                } else {
                    $tld_u = $tld['tld'];
                }

                $_SESSION['u_tld_id'] = [$tld['id']];
                $_SESSION['u_tld_extension'] = [$tld['tld']];

                return view($response,'admin/system/viewPromo.twig', [
                    'tld' => $tld,
                    'tld_u' => $tld_u,
                    'promotions' => $promotions,
                    'currentUri' => $uri
                ]);
            } else {
                // TLD does not exist, redirect to the tlds view
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }

        } else {
            // Redirect to the tlds view
            return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
        }

    }

    public function managePromo(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            
            if (!empty($_SESSION['u_tld_id'])) {
                $tld_id = $_SESSION['u_tld_id'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No TLD specified for promotions');
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }
            
            if (!empty($_SESSION['u_tld_extension'])) {
                $tld_extension = $_SESSION['u_tld_extension'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No TLD specified for promotions');
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }

            $sData = array();

            $sData['tldid'] = filter_var($tld_id, FILTER_SANITIZE_NUMBER_INT);
            $sData['extension'] = substr(trim($tld_extension), 0, 10);
            $sData['promotionName'] = substr(trim($data['promotionName']), 0, 255);
            $sData['promotionStart'] = str_replace('T', ' ', $data['promotionStart']) . ':00';
            $sData['promotionEnd'] = str_replace('T', ' ', $data['promotionEnd']) . ':00';
            $sData['discountType'] = in_array($data['discountType'], ['percentage', 'amount']) ? $data['discountType'] : 'percentage';
            $sData['discountValue'] = filter_var($data['discountValue'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $sData['max_count'] = ($data['max_count'] === "") ? null : filter_var($data['max_count'], FILTER_SANITIZE_NUMBER_INT);
            $sData['promotionConditions'] = substr(trim($data['promotionConditions']), 0, 1000); 
            $sData['promotionDescription'] = substr(trim($data['promotionDescription']), 0, 1000);

            try {
                $discount_percentage = NULL;
                $discount_amount = NULL;

                // Determine which column to populate based on discountType
                if ($sData['discountType'] == 'percentage') {
                    // Ensure the percentage value is within a valid range (0 to 100)
                    $discount_percentage = min(100, max(0, floatval($sData['discountValue'])));
                } elseif ($sData['discountType'] == 'amount') {
                    // Ensure the amount is a valid positive number
                    $discount_amount = max(0, floatval($sData['discountValue']));
                }
                
                $currentDateTime = new \DateTime();
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                $db->beginTransaction();

                $db->insert(
                    'promotion_pricing',
                    [
                        'tld_id' => $sData['tldid'],
                        'promo_name' => $sData['promotionName'],
                        'start_date' => $sData['promotionStart'],
                        'end_date' => $sData['promotionEnd'],
                        'discount_percentage' => $discount_percentage,
                        'discount_amount' => $discount_amount,
                        'description' => $sData['promotionDescription'],
                        'conditions' => $sData['promotionConditions'],
                        'promo_type' => 'full',
                        'status' => 'active',
                        'max_count' => $sData['max_count'],
                        'created_by' => $_SESSION['auth_user_id'],
                        'created_at' => $crdate
                    ]
                );

                $db->commit();
                
                unset($_SESSION['u_tld_id']);
                unset($_SESSION['u_tld_extension']);
                
                $this->container->get('flash')->addMessage('success', 'Promotion updates for the ' . $sData['extension'] . ' TLD have been successfully applied');
                return $response->withHeader('Location', '/registry/promotion/'.$sData['extension'])->withStatus(302);
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
               return $response->withHeader('Location', '/registry/promotion/'.$sData['extension'])->withStatus(302);
            }
            
        } else {
            // Redirect to the tlds view
            return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
        }

    }

    public function viewPhases(Request $request, Response $response, $args)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^\.(xn--[a-zA-Z0-9-]+|[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)?)$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid TLD format');
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }
                
            $tld = $db->selectRow('SELECT id, tld, idn_table, secure FROM domain_tld WHERE tld = ?',
            [ $args ]);

            if ($tld) {
                $launch_phases = $db->select('SELECT * FROM launch_phases WHERE tld_id = ?',
                [ $tld['id'] ]);

                if (strpos(strtolower($tld['tld']), '.xn--') === 0) {
                    $tld['tld'] = ltrim($tld['tld'], '.');
                    $tld_u = '.'.idn_to_utf8($tld['tld'], 0, INTL_IDNA_VARIANT_UTS46);
                    $tld['tld'] = '.'.$tld['tld'];
                } else {
                    $tld_u = $tld['tld'];
                }

                $_SESSION['u_tld_id'] = [$tld['id']];
                $_SESSION['u_tld_extension'] = [$tld['tld']];

                return view($response,'admin/system/viewPhase.twig', [
                    'tld' => $tld,
                    'tld_u' => $tld_u,
                    'launch_phases' => $launch_phases,
                    'currentUri' => $uri
                ]);
            } else {
                // TLD does not exist, redirect to the tlds view
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }

        } else {
            // Redirect to the tlds view
            return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
        }

    }

    public function managePhases(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            
            if (!empty($_SESSION['u_tld_id'])) {
                $tld_id = $_SESSION['u_tld_id'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No TLD specified for launch phases');
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }
            
            if (!empty($_SESSION['u_tld_extension'])) {
                $tld_extension = $_SESSION['u_tld_extension'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No TLD specified for launch phases');
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }

            $sData = array();

            $sData['tldid'] = filter_var($tld_id, FILTER_SANITIZE_NUMBER_INT);
            $sData['extension'] = substr(trim($tld_extension), 0, 10);
            $sData['phaseName'] = !empty(trim($data['phaseName'])) 
                ? substr(trim($data['phaseName']), 0, 255) 
                : substr($sData['tldid'] . '-' . str_replace('.', '', $sData['extension']) . '-' . strtotime($data['phaseStart']), 0, 30);
            $sData['phaseCategory'] = substr(trim($data['phaseCategory']), 0, 255);
            $sData['phaseType'] = substr(trim($data['phaseType']), 0, 255);
            $sData['phaseDescription'] = substr(trim($data['phaseDescription']), 0, 1000);
            $sData['phaseStart'] = str_replace('T', ' ', $data['phaseStart']) . ':00';
            if (!empty($data['phaseEnd'])) {
                $sData['phaseEnd'] = str_replace('T', ' ', $data['phaseEnd']) . ':00';
            } elseif (!in_array($sData['phaseType'], ['open', 'custom'])) {
                $this->container->get('flash')->addMessage('error', "Error: phaseEnd is required for phaseType '{$sData['phaseType']}'");
                return $response->withHeader('Location', '/registry/phases/'.$sData['extension'])->withStatus(302);
            }

            try {           
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                $db->beginTransaction();

                // Check if phaseType is 'custom' and phaseName is empty
                if ($sData['phaseType'] === 'custom' && (empty($sData['phaseName']) || is_null($sData['phaseName']))) {
                    // Handle the error scenario
                    $this->container->get('flash')->addMessage('error', 'Phase name is required when the type is Custom.');
                    return $response->withHeader('Location', '/registry/phases/'.$sData['extension'])->withStatus(302);
                }

                // Check for existing phase_type (excluding 'custom' with different phase_name) or date overlap (excluding 'custom' and 'open' types)
                $query = "SELECT 
                             (SELECT COUNT(*) 
                              FROM launch_phases 
                              WHERE tld_id = ? 
                                AND phase_type = ? 
                                AND (phase_type <> 'custom' OR (phase_type = 'custom' AND phase_name = ?))
                             ) AS phaseTypeExists, 
                             
                            (SELECT COUNT(*) 
                             FROM launch_phases 
                             WHERE tld_id = ?  
                               AND phase_type NOT IN ('custom', 'open') 
                               AND (
                                     (start_date <= ? AND (end_date IS NULL OR end_date >= ?)) 
                                  OR (start_date <= ? AND (end_date IS NULL OR end_date >= ?)) 
                                  OR (start_date >= ? AND (end_date IS NULL OR end_date <= ?))
                               )
                               AND (end_date IS NULL OR end_date >= NOW()) -- Ensures ongoing phases count
                            ) AS dateOverlapExists,

                            (SELECT COUNT(*) 
                             FROM launch_phases 
                             WHERE tld_id = ? 
                               AND phase_name = ?
                            ) AS duplicatePhaseNameExists";

                $result = $db->selectRow(
                    $query,
                    [
                        $sData['tldid'], $sData['phaseType'], $sData['phaseName'],
                        $sData['tldid'], $sData['phaseEnd'], $sData['phaseStart'],
                        $sData['phaseStart'], $sData['phaseEnd'],
                        $sData['phaseStart'], $sData['phaseEnd'],
                        $sData['tldid'], $sData['phaseName']
                    ]
                );

                if ($result['phaseTypeExists'] > 0) {
                    // phase_type already exists for the tldid
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'The phase type already exists for this TLD.');
                    return $response->withHeader('Location', '/registry/phases/'.$sData['extension'])->withStatus(302);
                }

                if ($result['duplicatePhaseNameExists'] > 0) {
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'A phase with this name already exists for this TLD.');
                    return $response->withHeader('Location', '/registry/phases/'.$sData['extension'])->withStatus(302);
                }

                if ($result['dateOverlapExists'] > 0) {
                    // Date range overlaps with an existing entry
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'Date range overlaps with an existing phase for this TLD.');
                    return $response->withHeader('Location', '/registry/phases/'.$sData['extension'])->withStatus(302);
                }

                $db->insert(
                    'launch_phases',
                    [
                        'tld_id' => $sData['tldid'],
                        'phase_name' => $sData['phaseName'],
                        'phase_type' => $sData['phaseType'],
                        'phase_category' => $sData['phaseCategory'],
                        'phase_description' => $sData['phaseDescription'],
                        'start_date' => $sData['phaseStart'],
                        'end_date' => $sData['phaseEnd'],
                        'lastupdate' => $update
                    ]
                );

                $db->commit();
                
                unset($_SESSION['u_tld_id']);
                unset($_SESSION['u_tld_extension']);
                
                $this->container->get('flash')->addMessage('success', 'Launch phase updates for the ' . $sData['extension'] . ' TLD have been successfully applied');
                return $response->withHeader('Location', '/registry/phases/'.$sData['extension'])->withStatus(302);
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
               return $response->withHeader('Location', '/registry/phases/'.$sData['extension'])->withStatus(302);
            }
            
        } else {
            // Redirect to the tlds view
            return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
        }

    }

    public function idnexport(Request $request, Response $response, $args)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $db = $this->container->get('db');

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^\.(xn--[a-zA-Z0-9-]+|[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)?)$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid TLD format');
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }

            $idn_table = $db->selectValue('SELECT idn_table FROM domain_tld WHERE tld = ?',
            [ $args ]);

            if (!$idn_table) {
                $this->container->get('flash')->addMessage('error', 'TLD does not exist');
                return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
            }

            $idn_table_map = [
                '/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i' => 'ascii',
                '/^[а-яА-ЯґҐєЄіІїЇѝЍћЋљЈ0-9ʼѫѣѭ]+$/u' => 'cyrillic',
                '/^[ぁ-んァ-ン一-龯々0-9]+$/u' => 'japanese',
                '/^[가-힣0-9]+$/u' => 'korean',
                '/^(?!-)(?!.*--)[\x{0621}-\x{064A}\x{0660}-\x{0669}\x{0671}-\x{06D3}-]{1,63}(?<!-)$/u' => 'arabic'
            ];

            $idn_table_name = 'ascii'; // Default

            foreach ($idn_table_map as $regex => $name) {
                if ($idn_table === $regex) {
                    $idn_table_name = $name;
                    break;
                }
            }

            $company_name = $db->selectValue("SELECT value FROM settings WHERE name = 'company_name'");
            $address = $db->selectValue("SELECT value FROM settings WHERE name = 'address'");
            $address2 = $db->selectValue("SELECT value FROM settings WHERE name = 'address2'");
            $cc = $db->selectValue("SELECT value FROM settings WHERE name = 'cc'");
            $phone = $db->selectValue("SELECT value FROM settings WHERE name = 'phone'");
            $email = $db->selectValue("SELECT value FROM settings WHERE name = 'email'");

            // Set the regex and metadata based on the script.
            switch ($idn_table_name) {
                case 'ascii':
                    $idntable = '/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i';
                    $metadata = [
                        'Registry'      => $company_name,
                        'Script'        => 'latn',
                        'Version'       => '1.0',
                        'Effective Date'=> date('Y-m-d'),
                        'Contact'       => $email,
                        'Address'       => $address . ', ' . $address2 . ', ' . $cc,
                        'Telephone'     => $phone,
                        'Website'       => 'www.example.com',
                        'Notes'         => 'This table describes codepoints allowed for the Latin script.'
                    ];
                    break;
                case 'cyrillic':
                    $idntable = '/^[а-яА-ЯґҐєЄіІїЇѝЍћЋљЈ0-9ʼѫѣѭ]+$/u';
                    $metadata = [
                        'Registry'      => $company_name,
                        'Script'        => 'cyrl',
                        'Version'       => '1.0',
                        'Effective Date'=> date('Y-m-d'),
                        'Contact'       => $email,
                        'Address'       => $address . ', ' . $address2 . ', ' . $cc,
                        'Telephone'     => $phone,
                        'Website'       => 'www.example.com',
                        'Notes'         => 'This table describes codepoints allowed for the Cyrillic script.'
                    ];
                    break;
                case 'japanese':
                    $idntable = '/^[ぁ-んァ-ン一-龯々0-9]+$/u';
                    $metadata = [
                        'Registry'      => $company_name,
                        'Script'        => 'jpan',
                        'Version'       => '1.0',
                        'Effective Date'=> date('Y-m-d'),
                        'Contact'       => $email,
                        'Address'       => $address . ', ' . $address2 . ', ' . $cc,
                        'Telephone'     => $phone,
                        'Website'       => 'www.example.com',
                        'Notes'         => 'This table describes codepoints allowed for the Japanese script.'
                    ];
                    break;
                case 'korean':
                    $idntable = '/^[가-힣0-9]+$/u';
                    $metadata = [
                        'Registry'      => $company_name,
                        'Script'        => 'kore',
                        'Version'       => '1.0',
                        'Effective Date'=> date('Y-m-d'),
                        'Contact'       => $email,
                        'Address'       => $address . ', ' . $address2 . ', ' . $cc,
                        'Telephone'     => $phone,
                        'Website'       => 'www.example.com',
                        'Notes'         => 'This table describes codepoints allowed for the Korean script.'
                    ];
                    break;
                case 'arabic':
                    $idntable = '/^(?!-)(?!.*--)[\x{0621}-\x{064A}\x{0660}-\x{0669}\x{0671}-\x{06D3}-]{1,63}(?<!-)$/u';
                    $metadata = [
                        'Registry'      => $company_name,
                        'Script'        => 'arab',
                        'Version'       => '1.0',
                        'Effective Date'=> date('Y-m-d'),
                        'Contact'       => $email,
                        'Address'       => $address . ', ' . $address2 . ', ' . $cc,
                        'Telephone'     => $phone,
                        'Website'       => 'www.example.com',
                        'Notes'         => 'This table describes codepoints allowed for the Arabic script.'
                    ];
                    break;
                default:
                    $idntable = '/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i';
                    $metadata = [
                        'Registry'      => $company_name,
                        'Script'        => 'latn',
                        'Version'       => '1.0',
                        'Effective Date'=> date('Y-m-d'),
                        'Contact'       => $email,
                        'Address'       => $address . ', ' . $address2 . ', ' . $cc,
                        'Telephone'     => $phone,
                        'Website'       => 'www.example.com',
                        'Notes'         => 'This table describes codepoints allowed for the Latin script.'
                    ];
                    break;
            }

            try {
                $table = generateIanaIdnTable($idntable, $metadata);
                $response->getBody()->write($table);
                return $response->withHeader('Content-Type', 'text/plain');
            } catch (Exception $e) {
                $this->container->get('flash')->addMessage('error', 'Error generating table: ' . $e->getMessage());
                return $response->withHeader('Location', '/registry/tld/'.$args)->withStatus(302);
            }

        } else {
            // Redirect to the tlds view
            return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
        }

    }

    public function manageDnssec(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $db = $this->container->get('db');

        if (!empty($_SESSION['u_tld_id'])) {
            $tld_id = $_SESSION['u_tld_id'][0];
        } else {
            $this->container->get('flash')->addMessage('error', 'No TLD specified for DNSSEC');
            return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
        }

        if (!empty($_SESSION['u_tld_extension'])) {
            $tld_extension = $_SESSION['u_tld_extension'][0];
        } else {
            $this->container->get('flash')->addMessage('error', 'No TLD specified for DNSSEC');
            return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
        }

        try {
            $db->update(
                'domain_tld',
                [
                    'secure' => 1
                ],
                [
                    'id' => $tld_id
                ]
            );

            unset($_SESSION['u_tld_id']);
            unset($_SESSION['u_tld_extension']);

            $this->container->get('flash')->addMessage('success', 'DNSSEC for ' . $tld_extension . ' has been successfully activated');
            return $response->withHeader('Location', '/registry/tld/'.$tld_extension)->withStatus(302);
        } catch (Exception $e) {
            $db->rollBack();
            $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
           return $response->withHeader('Location', '/registry/tld/'.$tld_extension)->withStatus(302);
        }

    }

}