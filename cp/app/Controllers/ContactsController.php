<?php

namespace App\Controllers;

use App\Models\Contact;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use League\ISO3166\ISO3166;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\DNSCheckValidation;
use Egulias\EmailValidator\Validation\MultipleValidationWithAnd;
use Egulias\EmailValidator\Validation\RFCValidation;
use Brick\Postcode\PostcodeFormatter;

class ContactsController extends Controller
{
    public function listContacts(Request $request, Response $response)
    {
        return view($response,'admin/contacts/listContacts.twig');
    }

    public function createContact(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $iso3166 = new ISO3166();
            $countries = $iso3166->all();
            $contactID = $data['contactid'] ?? null;
            $registrar_id = $data['registrar'] ?? null;
            $registrars = $db->select("SELECT id, clid, name FROM registrar");
            if ($_SESSION["auth_roles"] != 0) {
                $registrar = true;
            } else {
                $registrar = null;
            }
            
            $postalInfoIntName = $data['intName'] ?? null;
            $postalInfoIntOrg = $data['org'] ?? null;
            $postalInfoIntStreet1 = $data['street1'] ?? null;
            $postalInfoIntStreet2 = $data['street2'] ?? null;
            $postalInfoIntStreet3 = $data['street3'] ?? null;
            $postalInfoIntCity = $data['city'] ?? null;
            $postalInfoIntSp = $data['sp'] ?? null;
            $postalInfoIntPc = $data['pc'] ?? null;
            $postalInfoIntCc = $data['cc'] ?? null;
            
            $postalInfoLocName = $data['locName'] ?? null;
            $postalInfoLocOrg = $data['locOrg'] ?? null;
            $postalInfoLocStreet1 = $data['locStreet1'] ?? null;
            $postalInfoLocStreet2 = $data['locStreet2'] ?? null;
            $postalInfoLocStreet3 = $data['locStreet3'] ?? null;
            $postalInfoLocCity = $data['locCity'] ?? null;
            $postalInfoLocSp = $data['locSP'] ?? null;
            $postalInfoLocPc = $data['locPC'] ?? null;
            $postalInfoLocCc = $data['locCC'] ?? null;
            
            $voice = $data['voice'] ?? null;
            $fax = $data['fax'] ?? null;
            $email = $data['email'] ?? null;
            $authInfo_pw = $data['authInfo'] ?? null;

            if (!$contactID) {
                $this->container->get('flash')->addMessage('error', 'Unable to create contact: Please provide a contact ID');
                return $response->withHeader('Location', '/contact/create')->withStatus(302);
            }

            // Validation for contact ID
            $invalid_identifier = validate_identifier($contactID);
            if ($invalid_identifier) {
                $this->container->get('flash')->addMessage('error', 'Unable to create contact: ' . $invalid_identifier);
                return $response->withHeader('Location', '/contact/create')->withStatus(302);
            }
            
            $contact = $db->select('SELECT * FROM contact WHERE identifier = ?', [$contactID]);
            if ($contact) {
                $this->container->get('flash')->addMessage('error', 'Unable to create contact: Contact ID already exists');
                return $response->withHeader('Location', '/contact/create')->withStatus(302);
            }

            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $registrar_id;
            }

            if ($postalInfoIntName) {
                if (!$postalInfoIntName) {
                    $this->container->get('flash')->addMessage('error', 'Unable to create contact: Missing contact name');
                    return $response->withHeader('Location', '/contact/create')->withStatus(302);
                }

                if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntName) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntName)) {
                    $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid contact name');
                    return $response->withHeader('Location', '/contact/create')->withStatus(302);
                }

                if ($postalInfoIntOrg) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntOrg) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntOrg)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid contact org');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

                if ($postalInfoIntStreet1) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet1) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet1)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid contact street');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

                if ($postalInfoIntStreet2) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet2) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet2)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid contact street 2');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

                if ($postalInfoIntStreet3) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet3) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet3)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid contact street 3');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

                if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoIntCity) || !preg_match('/^[a-z][a-z\-\.\s]{3,}$/i', $postalInfoIntCity)) {
                    $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid contact city');
                    return $response->withHeader('Location', '/contact/create')->withStatus(302);
                }

                if ($postalInfoIntSp) {
                    if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoIntSp) || !preg_match('/^[A-Z][a-zA-Z\-\.\s]{1,}$/', $postalInfoIntSp)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid contact state/province');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

                if ($postalInfoIntPc) {
                    if (preg_match('/(^\-)|(\-\-)|(\-$)/', $postalInfoIntPc) || !preg_match('/^[A-Z0-9\-\s]{3,}$/', $postalInfoIntPc)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid contact postal code');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

            }
            
            if ($postalInfoLocName) {
                if (!$postalInfoLocName) {
                    $this->container->get('flash')->addMessage('error', 'Unable to create contact: Missing loc contact name');
                    return $response->withHeader('Location', '/contact/create')->withStatus(302);
                }

                if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocName) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocName)) {
                    $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid loc contact name');
                    return $response->withHeader('Location', '/contact/create')->withStatus(302);
                }

                if ($postalInfoLocOrg) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocOrg) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocOrg)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid loc contact org');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

                if ($postalInfoLocStreet1) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet1) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet1)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid loc contact street');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

                if ($postalInfoLocStreet2) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet2) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet2)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid loc contact street 2');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

                if ($postalInfoLocStreet3) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet3) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet3)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid loc contact street 3');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

                if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoLocCity) || !preg_match('/^[a-z][a-z\-\.\s]{3,}$/i', $postalInfoLocCity)) {
                    $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid loc contact city');
                    return $response->withHeader('Location', '/contact/create')->withStatus(302);
                }

                if ($postalInfoLocSp) {
                    if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoLocSp) || !preg_match('/^[A-Z][a-zA-Z\-\.\s]{1,}$/', $postalInfoLocSp)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid loc contact state/province');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

                if ($postalInfoLocPc) {
                    if (preg_match('/(^\-)|(\-\-)|(\-$)/', $postalInfoLocPc) || !preg_match('/^[A-Z0-9\-\s]{3,}$/', $postalInfoLocPc)) {
                        $this->container->get('flash')->addMessage('error', 'Unable to create contact: Invalid loc contact postal code');
                        return $response->withHeader('Location', '/contact/create')->withStatus(302);
                    }
                }

            }

            if ($voice && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $voice) || strlen($voice) > 17)) {
                $this->container->get('flash')->addMessage('error', 'Unable to create contact: Voice must be (\+[0-9]{1,3}\.[0-9]{1,14})');
                return $response->withHeader('Location', '/contact/create')->withStatus(302);
            }

            if ($fax && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $fax) || strlen($fax) > 17)) {
                $this->container->get('flash')->addMessage('error', 'Unable to create contact: Fax must be (\+[0-9]{1,3}\.[0-9]{1,14})');
                return $response->withHeader('Location', '/contact/create')->withStatus(302);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->container->get('flash')->addMessage('error', 'Unable to create contact: Email address failed check');
                return $response->withHeader('Location', '/contact/create')->withStatus(302);
            }

            if (!$authInfo_pw) {
                $this->container->get('flash')->addMessage('error', 'Unable to create contact: Email contact authinfo missing');
                return $response->withHeader('Location', '/contact/create')->withStatus(302);
            }

            if ((strlen($authInfo_pw) < 6) || (strlen($authInfo_pw) > 16)) {
                $this->container->get('flash')->addMessage('error', 'Unable to create contact: Password needs to be at least 6 and up to 16 characters long');
                return $response->withHeader('Location', '/contact/create')->withStatus(302);
            }

            if (!preg_match('/[A-Z]/', $authInfo_pw)) {
                $this->container->get('flash')->addMessage('error', 'Unable to create contact: Password should have both upper and lower case characters');
                return $response->withHeader('Location', '/contact/create')->withStatus(302);
            }

            $disclose_voice = isset($data['disclose_voice']) ? 1 : 0;
            $disclose_fax = isset($data['disclose_fax']) ? 1 : 0;
            $disclose_email = isset($data['disclose_email']) ? 1 : 0;
            $disclose_name_int = isset($data['disclose_name_int']) ? 1 : 0;
            $disclose_name_loc = isset($data['disclose_name_loc']) ? 1 : 0;
            $disclose_org_int = isset($data['disclose_org_int']) ? 1 : 0;
            $disclose_org_loc = isset($data['disclose_org_loc']) ? 1 : 0;
            $disclose_addr_int = isset($data['disclose_addr_int']) ? 1 : 0;
            $disclose_addr_loc = isset($data['disclose_addr_loc']) ? 1 : 0;

            if ($data['nin']) {
                $nin = $data['nin'];
                $nin_type = (isset($data['isBusiness']) && $data['isBusiness'] === 'on') ? 'business' : 'personal';

                if (!preg_match('/\d/', $nin)) {
                    $this->container->get('flash')->addMessage('error', 'Unable to create contact: NIN should contain one or more numbers');
                    return $response->withHeader('Location', '/contact/create')->withStatus(302);
                }
            }
            
            try {
                $db->beginTransaction();
                $currentDateTime = new \DateTime();
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v');
                $db->insert(
                    'contact',
                    [
                        'identifier' => $contactID,
                        'voice' => $voice,
                        'voice_x' => null,
                        'fax' => $fax ?? null,
                        'fax_x' => null,
                        'email' => $email,
                        'nin' => $nin ?? null,
                        'nin_type' => $nin_type ?? null,
                        'clid' => $clid,
                        'crid' => $clid,
                        'crdate' => $crdate,
                        'disclose_voice' => $disclose_voice,
                        'disclose_fax' => $disclose_fax,
                        'disclose_email' => $disclose_email
                    ]
                );
                $contact_id = $db->getLastInsertId();
                
                $db->insert(
                    'contact_postalInfo',
                    [
                        'contact_id' => $contact_id,
                        'type' => 'int',
                        'name' => $postalInfoIntName ?? null,
                        'org' => $postalInfoIntOrg ?? null,
                        'street1' => $postalInfoIntStreet1 ?? null,
                        'street2' => $postalInfoIntStreet2 ?? null,
                        'street3' => $postalInfoIntStreet3 ?? null,
                        'city' => $postalInfoIntCity ?? null,
                        'sp' => $postalInfoIntSp ?? null,
                        'pc' => $postalInfoIntPc ?? null,
                        'cc' => $postalInfoIntCc ?? null,
                        'disclose_name_int' => $disclose_name_int,
                        'disclose_org_int' => $disclose_org_int,
                        'disclose_addr_int' => $disclose_addr_int
                    ]
                );

                if ($postalInfoLocName) {
                    $db->insert(
                        'contact_postalInfo',
                        [
                            'contact_id' => $contact_id,
                            'type' => 'loc',
                            'name' => $postalInfoLocName ?? null,
                            'org' => $postalInfoLocOrg ?? null,
                            'street1' => $postalInfoLocStreet1 ?? null,
                            'street2' => $postalInfoLocStreet2 ?? null,
                            'street3' => $postalInfoLocStreet3 ?? null,
                            'city' => $postalInfoLocCity ?? null,
                            'sp' => $postalInfoLocSp ?? null,
                            'pc' => $postalInfoLocPc ?? null,
                            'cc' => $postalInfoLocCc ?? null,
                            'disclose_name_loc' => $disclose_name_loc,
                            'disclose_org_loc' => $disclose_org_loc,
                            'disclose_addr_loc' => $disclose_addr_loc
                        ]
                    );
                }
                
                $db->insert(
                    'contact_authInfo',
                    [
                        'contact_id' => $contact_id,
                        'authtype' => 'pw',
                        'authinfo' => $authInfo_pw
                    ]
                );

                $db->insert(
                    'contact_status',
                    [
                        'contact_id' => $contact_id,
                        'status' => 'ok'
                    ]
                );

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/contact/create')->withStatus(302);
            }
            
            $crdate = $db->selectValue(
                "SELECT crdate FROM contact WHERE id = ? LIMIT 1",
                [$contact_id]
            );
            
            $this->container->get('flash')->addMessage('success', 'Contact ' . $contactID . ' has been created successfully on ' . $crdate);
            return $response->withHeader('Location', '/contacts')->withStatus(302);
        }

        $iso3166 = new ISO3166();
        $db = $this->container->get('db');
        $countries = $iso3166->all();
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
        } else {
            $registrar = null;
        }
        
        // Default view for GET requests or if POST data is not set
        return view($response,'admin/contacts/createContact.twig', [
            'registrars' => $registrars,
            'countries' => $countries,
            'registrar' => $registrar,
        ]);
    }
    
    public function viewContact(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^[a-zA-Z0-9\-]+$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid contact ID format');
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            }
        
            $contact = $db->selectRow('SELECT id, identifier, voice, fax, email, nin, nin_type, crdate, clid, disclose_voice, disclose_fax, disclose_email FROM contact WHERE identifier = ?',
            [ $args ]);

            if ($contact) {
                $registrars = $db->selectRow('SELECT id, clid, name FROM registrar WHERE id = ?', [$contact['clid']]);

                // Check if the user is not an admin (assuming role 0 is admin)
                if ($_SESSION["auth_roles"] != 0) {
                    $userRegistrars = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                    // Assuming $userRegistrars returns an array of arrays, each containing 'registrar_id'
                    $userRegistrarIds = array_column($userRegistrars, 'registrar_id');

                    // Check if the registrar's ID is in the user's list of registrar IDs
                    if (!in_array($registrars['id'], $userRegistrarIds)) {
                        // Redirect to the contacts view if the user is not authorized for this contact
                        return $response->withHeader('Location', '/contacts')->withStatus(302);
                    }
                }
                
                $contactStatus = $db->selectRow('SELECT status FROM contact_status WHERE contact_id = ?',
                [ $contact['id'] ]);
                $contactAuth = $db->selectRow('SELECT authinfo FROM contact_authInfo WHERE contact_id = ?',
                [ $contact['id'] ]);
                $contactLinked = $db->selectRow('SELECT domain_id, type FROM domain_contact_map WHERE contact_id = ?',
                [ $contact['id'] ]);
                $contactPostal = $db->select('SELECT * FROM contact_postalInfo WHERE contact_id = ?',
                [ $contact['id'] ]);
                
                $responseData = [
                    'contact' => $contact,
                    'contactStatus' => $contactStatus,
                    'contactLinked' => $contactLinked,
                    'contactAuth' => $contactAuth,
                    'contactPostal' => $contactPostal,
                    'registrars' => $registrars,
                    'currentUri' => $uri
                ];
                
                $verifyPhone = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyPhone'");
                $verifyEmail = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyEmail'");
                $verifyPostal = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyPostal'");
        
                if ($verifyPhone == 'on' || $verifyEmail == 'on' || $verifyPostal == 'on') {
                    $contact_validation = $db->selectRow('SELECT validation, validation_stamp, validation_log FROM contact WHERE identifier = ?', [ $args ]);
                    $responseData['contact_valid'] = $contact_validation['validation'];
                    $responseData['validation_enabled'] = true;
                }

                return view($response, 'admin/contacts/viewContact.twig', $responseData);
            } else {
                // Contact does not exist, redirect to the contacts view
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            }

        } else {
            // Redirect to the contacts view
            return $response->withHeader('Location', '/contacts')->withStatus(302);
        }

    }
    
    public function updateContact(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^[a-zA-Z0-9\-]+$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid contact ID format');
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            }
            
            $contact = $db->selectRow('SELECT id, identifier, voice, fax, email, nin, nin_type, crdate, clid, disclose_voice, disclose_fax, disclose_email FROM contact WHERE identifier = ?',
            [ $args ]);

            if ($contact) {
                $registrars = $db->selectRow('SELECT id, clid, name FROM registrar WHERE id = ?', [$contact['clid']]);
                $iso3166 = new ISO3166();
                $countries = $iso3166->all();

                // Check if the user is not an admin (assuming role 0 is admin)
                if ($_SESSION["auth_roles"] != 0) {
                    $userRegistrars = $db->select('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                    // Assuming $userRegistrars returns an array of arrays, each containing 'registrar_id'
                    $userRegistrarIds = array_column($userRegistrars, 'registrar_id');

                    // Check if the registrar's ID is in the user's list of registrar IDs
                    if (!in_array($registrars['id'], $userRegistrarIds)) {
                        // Redirect to the contacts view if the user is not authorized for this contact
                        return $response->withHeader('Location', '/contacts')->withStatus(302);
                    }
                }
                
                $contactStatus = $db->selectRow('SELECT status FROM contact_status WHERE contact_id = ?',
                [ $contact['id'] ]);
                $contactAuth = $db->selectRow('SELECT authinfo FROM contact_authInfo WHERE contact_id = ?',
                [ $contact['id'] ]);
                $contactPostal = $db->select('SELECT * FROM contact_postalInfo WHERE contact_id = ?',
                [ $contact['id'] ]);
                
                $responseData = [
                    'contact' => $contact,
                    'contactStatus' => $contactStatus,
                    'contactAuth' => $contactAuth,
                    'contactPostal' => $contactPostal,
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'currentUri' => $uri
                ];
                
                $verifyPhone = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyPhone'");
                $verifyEmail = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyEmail'");
                $verifyPostal = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyPostal'");
        
                if ($verifyPhone == 'on' || $verifyEmail == 'on' || $verifyPostal == 'on') {
                    $contact_validation = $db->selectRow('SELECT validation, validation_stamp, validation_log FROM contact WHERE identifier = ?', [ $args ]);
                    $responseData['contact_valid'] = $contact_validation['validation'];
                    $responseData['validation_enabled'] = true;
                }

                return view($response, 'admin/contacts/updateContact.twig', $responseData);
            } else {
                // Contact does not exist, redirect to the contacts view
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            }

        } else {
            // Redirect to the contacts view
            return $response->withHeader('Location', '/contacts')->withStatus(302);
        }

    }
    
    public function validateContact(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^[a-zA-Z0-9\-]+$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid contact ID format');
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            }
            
            $contact = $db->selectRow('SELECT id, identifier, voice, fax, email, nin, nin_type, crdate, clid, disclose_voice, disclose_fax, disclose_email FROM contact WHERE identifier = ?',
            [ $args ]);
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                $contact_clid = $contact['clid'];
                if ($contact_clid != $clid) {
                    return $response->withHeader('Location', '/contacts')->withStatus(302);
                }
            } else {
                $clid = $contact['clid'];
            }

            if ($contact) {
                $registrars = $db->selectRow('SELECT id, clid, name FROM registrar WHERE id = ?', [$contact['clid']]);
                $iso3166 = new ISO3166();
                $countries = $iso3166->all();

                $contactStatus = $db->selectRow('SELECT status FROM contact_status WHERE contact_id = ?',
                [ $contact['id'] ]);
                $contactAuth = $db->selectRow('SELECT authinfo FROM contact_authInfo WHERE contact_id = ?',
                [ $contact['id'] ]);
                $contactPostal = $db->select('SELECT * FROM contact_postalInfo WHERE contact_id = ?',
                [ $contact['id'] ]);
           
                $responseData = [
                    'contact' => $contact,
                    'contactStatus' => $contactStatus,
                    'contactAuth' => $contactAuth,
                    'contactPostal' => $contactPostal,
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'currentUri' => $uri
                ];
                
                $verifyPhone = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyPhone'");
                $verifyEmail = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyEmail'");
                $verifyPostal = $db->selectValue("SELECT value FROM settings WHERE name = 'verifyPostal'");
        
                if ($verifyPhone == 'on' || $verifyEmail == 'on' || $verifyPostal == 'on') {
                    $contact_validation = $db->selectRow('SELECT validation, validation_stamp, validation_log FROM contact WHERE identifier = ?', [ $args ]);
                    $responseData['contact_valid'] = $contact_validation['validation'];
                    $responseData['validation_enabled'] = true;
                    $responseData['verifyPhone'] = $verifyPhone;
                    $responseData['verifyEmail'] = $verifyEmail;
                    $responseData['verifyPostal'] = $verifyPostal;
                }
                
                if ($verifyPhone == 'on') {
                    $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
                    try {
                        $numberProto = $phoneUtil->parse($contact['voice'], $contactPostal[0]['cc']);
                        $isValid = $phoneUtil->isValidNumber($numberProto);
                        $responseData['phoneDetails'] = $isValid;
                    } catch (\libphonenumber\NumberParseException $e) {
                        $responseData['phoneDetails'] = $e;
                    }
                }
                
                if ($verifyEmail == 'on') {
                    $validator = new EmailValidator();
                    $multipleValidations = new MultipleValidationWithAnd([
                        new RFCValidation(),
                        new DNSCheckValidation()
                    ]);
                    $isValid = $validator->isValid($contact['email'], $multipleValidations);
                    $responseData['emailDetails'] = $isValid;
                }
                
                if ($verifyPostal == 'on') {
                    $formatter = new PostcodeFormatter();
                    try {
                        $isValid = $formatter->format($contactPostal[0]['cc'], $contactPostal[0]['pc']);
                        $responseData['postalDetails'] = $isValid;
                    } catch (\Brick\Postcode\UnknownCountryException $e) {
                        $responseData['postalDetails'] = null;
                        $responseData['postalDetailsI'] = $e;
                    } catch (\Brick\Postcode\InvalidPostcodeException $e) {
                        $responseData['postalDetails'] = null;
                        $responseData['postalDetailsI'] = $e;
                    }
                    
                }

                return view($response, 'admin/contacts/validateContact.twig', $responseData);
            } else {
                // Contact does not exist, redirect to the contacts view
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            }

        } else {
            // Redirect to the contacts view
            return $response->withHeader('Location', '/contacts')->withStatus(302);
        }

    }
    
    public function approveContact(Request $request, Response $response) 
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();
            
            $identifier = trim($data['identifier']);
            
            if (!preg_match('/^[a-zA-Z0-9\-]+$/', $identifier)) {
                $this->container->get('flash')->addMessage('error', 'Invalid contact ID format');
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            }
            
            $contact = $db->selectRow('SELECT id, identifier, voice, fax, email, nin, nin_type, crdate, clid, disclose_voice, disclose_fax, disclose_email FROM contact WHERE identifier = ?',
            [ $identifier ]);
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                $contact_clid = $contact['clid'];
                if ($contact_clid != $clid) {
                    return $response->withHeader('Location', '/contacts')->withStatus(302);
                }
            } else {
                $clid = $contact['clid'];
            }
            
            if ($contact) {
                try {
                    $db->beginTransaction();
                    $currentDateTime = new \DateTime();
                    $stamp = $currentDateTime->format('Y-m-d H:i:s.v');
                    $db->update(
                        'contact',
                        [
                            'validation' => $data['verify'],
                            'validation_stamp' => $stamp,
                            'validation_log' => json_encode($data['v_log']),
                            'upid' => $clid,
                            'lastupdate' => $stamp
                        ],
                        [
                            'identifier' => $identifier
                        ]
                    );                
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                    return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                }
                
                $this->container->get('flash')->addMessage('success', 'Contact ' . $identifier . ' has been validated successfully on ' . $stamp);
                return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);

            } else {
                // Contact does not exist, redirect to the contacts view
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            }

        }
        
    }
    
    public function updateContactProcess(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $iso3166 = new ISO3166();
            $countries = $iso3166->all();
            $identifier = $data['identifier'] ?? null;

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                $contact_clid = $db->selectValue('SELECT clid FROM contact WHERE identifier = ?', [$identifier]);
                if ($contact_clid != $clid) {
                    return $response->withHeader('Location', '/contacts')->withStatus(302);
                }
            } else {
                $clid = $db->selectValue('SELECT clid FROM contact WHERE identifier = ?', [$identifier]);
            }
          
            $postalInfoIntName = $data['intName'] ?? null;
            $postalInfoIntOrg = $data['org'] ?? null;
            $postalInfoIntStreet1 = $data['street1'] ?? null;
            $postalInfoIntStreet2 = $data['street2'] ?? null;
            $postalInfoIntStreet3 = $data['street3'] ?? null;
            $postalInfoIntCity = $data['city'] ?? null;
            $postalInfoIntSp = $data['sp'] ?? null;
            $postalInfoIntPc = $data['pc'] ?? null;
            $postalInfoIntCc = $data['cc'] ?? null;
            
            $postalInfoLocName = $data['locName'] ?? null;
            $postalInfoLocOrg = $data['locOrg'] ?? null;
            $postalInfoLocStreet1 = $data['locStreet1'] ?? null;
            $postalInfoLocStreet2 = $data['locStreet2'] ?? null;
            $postalInfoLocStreet3 = $data['locStreet3'] ?? null;
            $postalInfoLocCity = $data['locCity'] ?? null;
            $postalInfoLocSp = $data['locSP'] ?? null;
            $postalInfoLocPc = $data['locPC'] ?? null;
            $postalInfoLocCc = $data['locCC'] ?? null;
            
            $voice = $data['voice'] ?? null;
            $fax = $data['fax'] ?? null;
            $email = $data['email'] ?? null;
            $authInfo_pw = $data['authInfo'] ?? null;

            if (!$identifier) {
                $this->container->get('flash')->addMessage('error', 'Please provide a contact ID');
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            }

            // Validation for contact ID
            $invalid_identifier = validate_identifier($identifier);
            if ($invalid_identifier) {
                $this->container->get('flash')->addMessage('error', $invalid_identifier);
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            }
            
            if ($postalInfoIntName) {
                if (!$postalInfoIntName) {
                    $this->container->get('flash')->addMessage('error', 'Missing contact name');
                    return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                }

                if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntName) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntName)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid contact name');
                    return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                }

                if ($postalInfoIntOrg) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntOrg) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntOrg)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid contact org');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

                if ($postalInfoIntStreet1) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet1) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet1)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid contact street');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

                if ($postalInfoIntStreet2) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet2) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet2)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid contact street');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

                if ($postalInfoIntStreet3) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet3) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet3)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid contact street');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

                if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoIntCity) || !preg_match('/^[a-z][a-z\-\.\s]{3,}$/i', $postalInfoIntCity)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid contact city');
                    return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                }

                if ($postalInfoIntSp) {
                    if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoIntSp) || !preg_match('/^[A-Z][a-zA-Z\-\.\s]{1,}$/', $postalInfoIntSp)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid contact state/province');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

                if ($postalInfoIntPc) {
                    if (preg_match('/(^\-)|(\-\-)|(\-$)/', $postalInfoIntPc) || !preg_match('/^[A-Z0-9\-\s]{3,}$/', $postalInfoIntPc)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid contact postal code');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

            }
            
            if ($postalInfoLocName) {
                if (!$postalInfoLocName) {
                    $this->container->get('flash')->addMessage('error', 'Missing loc contact name');
                    return $response->withHeader('Location', '/contacts')->withStatus(302);
                }

                if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocName) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocName)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid loc contact name');
                    return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                }

                if ($postalInfoLocOrg) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocOrg) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocOrg)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid loc contact org');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

                if ($postalInfoLocStreet1) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet1) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet1)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid loc contact street');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

                if ($postalInfoLocStreet2) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet2) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet2)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid loc contact street');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

                if ($postalInfoLocStreet3) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet3) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet3)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid loc contact street');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

                if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoLocCity) || !preg_match('/^[a-z][a-z\-\.\s]{3,}$/i', $postalInfoLocCity)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid loc contact city');
                    return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                }

                if ($postalInfoLocSp) {
                    if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoLocSp) || !preg_match('/^[A-Z][a-zA-Z\-\.\s]{1,}$/', $postalInfoLocSp)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid loc contact state/province');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

                if ($postalInfoLocPc) {
                    if (preg_match('/(^\-)|(\-\-)|(\-$)/', $postalInfoLocPc) || !preg_match('/^[A-Z0-9\-\s]{3,}$/', $postalInfoLocPc)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid loc contact postal code');
                        return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                    }
                }

            }

            if ($voice && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $voice) || strlen($voice) > 17)) {
                $this->container->get('flash')->addMessage('error', 'Voice must be (\+[0-9]{1,3}\.[0-9]{1,14})');
                return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
            }

            if ($fax && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $fax) || strlen($fax) > 17)) {
                $this->container->get('flash')->addMessage('error', 'Fax must be (\+[0-9]{1,3}\.[0-9]{1,14})');
                return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->container->get('flash')->addMessage('error', 'Email address failed check');
                return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
            }

            if (!$authInfo_pw) {
                $this->container->get('flash')->addMessage('error', 'Email contact authinfo');
                return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
            }

            if ((strlen($authInfo_pw) < 6) || (strlen($authInfo_pw) > 16)) {
                $this->container->get('flash')->addMessage('error', 'Password needs to be at least 6 and up to 16 characters long');
                return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
            }

            if (!preg_match('/[A-Z]/', $authInfo_pw)) {
                $this->container->get('flash')->addMessage('error', 'Password should have both upper and lower case characters');
                return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
            }

            $disclose_voice = isset($data['disclose_voice']) ? 1 : 0;
            $disclose_fax = isset($data['disclose_fax']) ? 1 : 0;
            $disclose_email = isset($data['disclose_email']) ? 1 : 0;
            $disclose_name_int = isset($data['disclose_name_int']) ? 1 : 0;
            $disclose_name_loc = isset($data['disclose_name_loc']) ? 1 : 0;
            $disclose_org_int = isset($data['disclose_org_int']) ? 1 : 0;
            $disclose_org_loc = isset($data['disclose_org_loc']) ? 1 : 0;
            $disclose_addr_int = isset($data['disclose_addr_int']) ? 1 : 0;
            $disclose_addr_loc = isset($data['disclose_addr_loc']) ? 1 : 0;

            if ($data['nin']) {
                $nin = $data['nin'];
                $nin_type = (isset($data['isBusiness']) && $data['isBusiness'] === 'on') ? 'business' : 'personal';

                if (!preg_match('/\d/', $nin)) {
                    $this->container->get('flash')->addMessage('error', 'NIN should contain one or more numbers');
                    return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
                }
            }
            
            try {
                $db->beginTransaction();
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v');
                $db->update(
                    'contact',
                    [
                        'voice' => $voice,
                        'voice_x' => null,
                        'fax' => $fax ?? null,
                        'fax_x' => null,
                        'email' => $email,
                        'nin' => $nin ?? null,
                        'nin_type' => $nin_type ?? null,
                        'upid' => $clid,
                        'lastupdate' => $update,
                        'disclose_voice' => $disclose_voice,
                        'disclose_fax' => $disclose_fax,
                        'disclose_email' => $disclose_email
                    ],
                    [
                        'identifier' => $identifier
                    ]
                );
                $contact_id = $db->selectValue(
                    'SELECT id FROM contact WHERE identifier = ?',
                    [$identifier]
                );
                
                $db->update(
                    'contact_postalInfo',
                    [
                        'type' => 'int',
                        'name' => $postalInfoIntName ?? null,
                        'org' => $postalInfoIntOrg ?? null,
                        'street1' => $postalInfoIntStreet1 ?? null,
                        'street2' => $postalInfoIntStreet2 ?? null,
                        'street3' => $postalInfoIntStreet3 ?? null,
                        'city' => $postalInfoIntCity ?? null,
                        'sp' => $postalInfoIntSp ?? null,
                        'pc' => $postalInfoIntPc ?? null,
                        'cc' => $postalInfoIntCc ?? null,
                        'disclose_name_int' => $disclose_name_int,
                        'disclose_org_int' => $disclose_org_int,
                        'disclose_addr_int' => $disclose_addr_int
                    ],
                    [
                        'contact_id' => $contact_id
                    ]
                );

                if ($postalInfoLocName) {
                    $does_it_exist = $db->selectValue("SELECT id FROM contact_postalInfo WHERE contact_id = ? AND type = 'loc'", [$contact_id]);
                    
                    if ($does_it_exist) {
                        $db->update(
                            'contact_postalInfo',
                            [
                                'type' => 'loc',
                                'name' => $postalInfoLocName ?? null,
                                'org' => $postalInfoLocOrg ?? null,
                                'street1' => $postalInfoLocStreet1 ?? null,
                                'street2' => $postalInfoLocStreet2 ?? null,
                                'street3' => $postalInfoLocStreet3 ?? null,
                                'city' => $postalInfoLocCity ?? null,
                                'sp' => $postalInfoLocSp ?? null,
                                'pc' => $postalInfoLocPc ?? null,
                                'cc' => $postalInfoLocCc ?? null,
                                'disclose_name_loc' => $disclose_name_loc,
                                'disclose_org_loc' => $disclose_org_loc,
                                'disclose_addr_loc' => $disclose_addr_loc
                            ],
                            [
                                'contact_id' => $contact_id,
                            ]
                        );
                    } else {
                        $db->insert(
                            'contact_postalInfo',
                            [
                                'contact_id' => $contact_id,
                                'type' => 'loc',
                                'name' => $postalInfoLocName ?? null,
                                'org' => $postalInfoLocOrg ?? null,
                                'street1' => $postalInfoLocStreet1 ?? null,
                                'street2' => $postalInfoLocStreet2 ?? null,
                                'street3' => $postalInfoLocStreet3 ?? null,
                                'city' => $postalInfoLocCity ?? null,
                                'sp' => $postalInfoLocSp ?? null,
                                'pc' => $postalInfoLocPc ?? null,
                                'cc' => $postalInfoLocCc ?? null,
                                'disclose_name_loc' => $disclose_name_loc,
                                'disclose_org_loc' => $disclose_org_loc,
                                'disclose_addr_loc' => $disclose_addr_loc
                            ]
                        );
                    }

                }
                
                $db->update(
                    'contact_authInfo',
                    [
                        'authinfo' => $authInfo_pw
                    ],
                    [
                        'contact_id' => $contact_id,
                        'authtype' => 'pw'
                    ]
                );

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
            }
            
            $this->container->get('flash')->addMessage('success', 'Contact ' . $identifier . ' has been updated successfully on ' . $update);
            return $response->withHeader('Location', '/contact/update/'.$identifier)->withStatus(302);
        }
    }
    
    public function deleteContact(Request $request, Response $response, $args)
    {
       // if ($request->getMethod() === 'POST') {
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();
        
            if ($args) {
                $args = trim($args);

                if (!preg_match('/^[a-zA-Z0-9\-]+$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid contact ID format');
                    return $response->withHeader('Location', '/contacts')->withStatus(302);
                }
            
                $contact = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?',
                [ $args ]);
                $contact_id = $contact['id'];
                $registrar_id_contact = $contact['clid'];
                
                if ($_SESSION["auth_roles"] != 0) {
                    $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                    if ($registrar_id_contact != $clid) {
                        return $response->withHeader('Location', '/contacts')->withStatus(302);
                    }
                }
                
                $is_linked_registrant = $db->selectRow('SELECT id FROM domain WHERE registrant = ?',
                [ $contact_id ]);
                
                if ($is_linked_registrant) {
                    $this->container->get('flash')->addMessage('error', 'This contact is associated with a domain as a registrant');
                    return $response->withHeader('Location', '/contacts')->withStatus(302);
                }
                    
                $is_linked_other = $db->selectRow('SELECT contact_id FROM domain_contact_map WHERE contact_id = ?',
                [ $contact_id ]);
                
                if ($is_linked_other) {
                    $this->container->get('flash')->addMessage('error', 'This contact is associated with a domain');
                    return $response->withHeader('Location', '/contacts')->withStatus(302);
                }

                $statuses = $db->select('SELECT status FROM contact_status WHERE contact_id = ?', [$contact_id]);

                foreach ($statuses as $status) {
                    if (preg_match('/.*(UpdateProhibited|DeleteProhibited)$/', $status['status']) || preg_match('/^pending/', $status['status'])) {
                        $this->container->get('flash')->addMessage('error', 'It has a status that does not allow deletion');
                        return $response->withHeader('Location', '/contacts')->withStatus(302);
                    }
                }

                $db->delete(
                    'contact_postalInfo',
                    [
                        'contact_id' => $contact_id
                    ]
                );
                    
                $db->delete(
                    'contact_authInfo',
                    [
                        'contact_id' => $contact_id
                    ]
                );
                
                $db->delete(
                    'contact_status',
                    [
                        'contact_id' => $contact_id
                    ]
                );
                    
                $db->delete(
                    'contact',
                    [
                        'id' => $contact_id
                    ]
                );
                    
                $this->container->get('flash')->addMessage('success', 'Contact ' . $args . ' deleted successfully');
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            } else {
                // Redirect to the contacts view
                return $response->withHeader('Location', '/contacts')->withStatus(302);
            }
        
        //}

    }    

}