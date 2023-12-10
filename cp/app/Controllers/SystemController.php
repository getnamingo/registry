<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Respect\Validation\Validator as v;

class SystemController extends Controller
{
    public function registry(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        $db = $this->container->get('db');
        $tlds = $db->select("SELECT id, tld, idn_table, secure FROM domain_tld");

        foreach ($tlds as $key => $tld) {
            // Count the domains for each TLD
            $domainCount = $db->select("SELECT COUNT(name) FROM domain WHERE tldid = ?", [$tld['id']]);
            
            // Add the domain count to the TLD array
            $tlds[$key]['domain_count'] = $domainCount[0]['COUNT(name)'];
        }

        return view($response,'admin/system/registry.twig', [
            'tlds' => $tlds,
        ]);
    }
    
    public function manageTlds(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        $db = $this->container->get('db');

        return view($response,'admin/system/manageTlds.twig');
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
                'extension' => v::stringType()->notEmpty()->length(3, 64),
                'tldType' => v::stringType()->notEmpty(),
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
            
            switch ($data['extension']) {
                case 'ascii':
                    $idntable = '/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i';
                    break;
                case 'cyrillic':
                    $idntable = '/^[а-яА-ЯґҐєЄіІїЇѝЍћЋљЈ]+$/u';
                    break;
                case 'japanese':
                    $idntable = '/^[ぁ-んァ-ン一-龯々]+$/u';
                    break;
                case 'korean':
                    $idntable = '/^[가-힣]+$/u';
                    break;
                default:
                    $idntable = '/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i';
                    break;
            }

            try {
                $db->beginTransaction();
                
                $currentDateTime = new \DateTime();
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

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
            
            $this->container->get('flash')->addMessage('success', 'TLD ' . $data['extension'] . ' has been created successfully');
            return $response->withHeader('Location', '/registry/tlds')->withStatus(302);
        }
        
        $db = $this->container->get('db');

        return view($response,'admin/system/createTld.twig');
    }
}