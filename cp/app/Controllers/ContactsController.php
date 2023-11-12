<?php

namespace App\Controllers;

use App\Models\Contact;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use League\ISO3166\ISO3166;

class ContactsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        return view($response,'admin/contacts/view.twig');
    }

    public function create(Request $request, Response $response)
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
                return view($response, 'admin/contacts/create.twig', [
                    'contactID' => $contactID,
                    'error' => 'Please provide a contact ID',
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'registrar' => $registrar,
                ]);
            }

            // Validation for contact ID
            $invalid_identifier = validate_identifier($contactID);
            if ($invalid_identifier) {
                return view($response, 'admin/contacts/create.twig', [
                    'contactID' => $contactID,
                    'error' => 'Invalid contact ID',
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'registrar' => $registrar,
                ]);
            }
            
            $contact = $db->select('SELECT * FROM contact WHERE identifier = ?', [$contactID]);
            if ($contact) {
                return view($response, 'admin/contacts/create.twig', [
                    'contactID' => $contactID,
                    'error' => 'Contact ID already exists',
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'registrar' => $registrar,
                ]);
            }

            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $registrar_id;
            }

            if ($postalInfoIntName) {
                if (!$postalInfoIntName) {
                    return view($response, 'admin/contacts/create.twig', [
                        'contactID' => $contactID,
                        'error' => 'Missing contact name',
                        'registrars' => $registrars,
                        'countries' => $countries,
                        'registrar' => $registrar,
                    ]);
                }

                if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntName) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntName)) {
                    return view($response, 'admin/contacts/create.twig', [
                        'contactID' => $contactID,
                        'error' => 'Invalid contact name',
                        'registrars' => $registrars,
                        'countries' => $countries,
                        'registrar' => $registrar,
                    ]);
                }

                if ($postalInfoIntOrg) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntOrg) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntOrg)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid contact org',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                if ($postalInfoIntStreet1) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet1) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet1)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid contact street',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                if ($postalInfoIntStreet2) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet2) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet2)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid contact street',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                if ($postalInfoIntStreet3) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoIntStreet3) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoIntStreet3)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid contact street',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoIntCity) || !preg_match('/^[a-z][a-z\-\.\s]{3,}$/i', $postalInfoIntCity)) {
                    return view($response, 'admin/contacts/create.twig', [
                        'contactID' => $contactID,
                        'error' => 'Invalid contact city',
                        'registrars' => $registrars,
                        'countries' => $countries,
                        'registrar' => $registrar,
                    ]);
                }

                if ($postalInfoIntSp) {
                    if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoIntSp) || !preg_match('/^[A-Z][a-zA-Z\-\.\s]{1,}$/', $postalInfoIntSp)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid contact state/province',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                if ($postalInfoIntPc) {
                    if (preg_match('/(^\-)|(\-\-)|(\-$)/', $postalInfoIntPc) || !preg_match('/^[A-Z0-9\-\s]{3,}$/', $postalInfoIntPc)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid contact postal code',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

            }
            
            if ($postalInfoLocName) {
                if (!$postalInfoLocName) {
                    return view($response, 'admin/contacts/create.twig', [
                        'contactID' => $contactID,
                        'error' => 'Missing loc contact name',
                        'registrars' => $registrars,
                        'countries' => $countries,
                        'registrar' => $registrar,
                    ]);
                }

                if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocName) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocName)) {
                    return view($response, 'admin/contacts/create.twig', [
                        'contactID' => $contactID,
                        'error' => 'Invalid loc contact name',
                        'registrars' => $registrars,
                        'countries' => $countries,
                        'registrar' => $registrar,
                    ]);
                }

                if ($postalInfoLocOrg) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocOrg) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocOrg)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid loc contact org',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                if ($postalInfoLocStreet1) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet1) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet1)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid loc contact street',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                if ($postalInfoLocStreet2) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet2) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet2)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid loc contact street',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                if ($postalInfoLocStreet3) {
                    if (preg_match('/(^\-)|(^\,)|(^\.)|(\-\-)|(\,\,)|(\.\.)|(\-$)/', $postalInfoLocStreet3) || !preg_match('/^[a-zA-Z0-9\-\&\,\.\/\s]{5,}$/', $postalInfoLocStreet3)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid loc contact street',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoLocCity) || !preg_match('/^[a-z][a-z\-\.\s]{3,}$/i', $postalInfoLocCity)) {
                    return view($response, 'admin/contacts/create.twig', [
                        'contactID' => $contactID,
                        'error' => 'Invalid loc contact city',
                        'registrars' => $registrars,
                        'countries' => $countries,
                        'registrar' => $registrar,
                    ]);
                }

                if ($postalInfoLocSp) {
                    if (preg_match('/(^\-)|(^\.)|(\-\-)|(\.\.)|(\.\-)|(\-\.)|(\-$)|(\.$)/', $postalInfoLocSp) || !preg_match('/^[A-Z][a-zA-Z\-\.\s]{1,}$/', $postalInfoLocSp)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid loc contact state/province',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

                if ($postalInfoLocPc) {
                    if (preg_match('/(^\-)|(\-\-)|(\-$)/', $postalInfoLocPc) || !preg_match('/^[A-Z0-9\-\s]{3,}$/', $postalInfoLocPc)) {
                        return view($response, 'admin/contacts/create.twig', [
                            'contactID' => $contactID,
                            'error' => 'Invalid loc contact postal code',
                            'registrars' => $registrars,
                            'countries' => $countries,
                            'registrar' => $registrar,
                        ]);
                    }
                }

            }

            if ($voice && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $voice) || strlen($voice) > 17)) {
                return view($response, 'admin/contacts/create.twig', [
                    'contactID' => $contactID,
                    'error' => 'Voice must be (\+[0-9]{1,3}\.[0-9]{1,14})',
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'registrar' => $registrar,
                ]);
            }

            if ($fax && (!preg_match('/^\+\d{1,3}\.\d{1,14}$/', $fax) || strlen($fax) > 17)) {
                return view($response, 'admin/contacts/create.twig', [
                    'contactID' => $contactID,
                    'error' => 'Fax must be (\+[0-9]{1,3}\.[0-9]{1,14})',
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'registrar' => $registrar,
                ]);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return view($response, 'admin/contacts/create.twig', [
                    'contactID' => $contactID,
                    'error' => 'Email address failed check',
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'registrar' => $registrar,
                ]);
            }

            if (!$authInfo_pw) {
                return view($response, 'admin/contacts/create.twig', [
                    'contactID' => $contactID,
                    'error' => 'Email contact authinfo',
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'registrar' => $registrar,
                ]);
            }

            if ((strlen($authInfo_pw) < 6) || (strlen($authInfo_pw) > 16)) {
                return view($response, 'admin/contacts/create.twig', [
                    'contactID' => $contactID,
                    'error' => 'Password needs to be at least 6 and up to 16 characters long',
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'registrar' => $registrar,
                ]);
            }

            if (!preg_match('/[A-Z]/', $authInfo_pw)) {
                return view($response, 'admin/contacts/create.twig', [
                    'contactID' => $contactID,
                    'error' => 'Password should have both upper and lower case characters',
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'registrar' => $registrar,
                ]);
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
                $nin_type = (isset($data['isBusiness']) && $data['isBusiness'] === 1) ? 'business' : 'personal';

                if (!preg_match('/\d/', $nin)) {
                    return view($response, 'admin/contacts/create.twig', [
                        'contactID' => $contactID,
                        'error' => 'NIN should contain one or more numbers',
                        'registrars' => $registrars,
                        'countries' => $countries,
                        'registrar' => $registrar,
                    ]);
                }
            }
            
            $db->beginTransaction();

            try {    
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
                return view($response, 'admin/contacts/create.twig', [
                    'contactID' => $contactID,
                    'error' => $e->getMessage(),
                    'registrars' => $registrars,
                    'countries' => $countries,
                    'registrar' => $registrar,
                ]);
            }
            
            $crdate = $db->selectValue(
                "SELECT crdate FROM contact WHERE id = ? LIMIT 1",
                [$contact_id]
            );
            
            return view($response, 'admin/contacts/create.twig', [
                'contactID' => $contactID,
                'crdate' => $crdate,
                'registrars' => $registrars,
                'countries' => $countries,
                'registrar' => $registrar,
            ]);
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
        return view($response,'admin/contacts/create.twig', [
            'registrars' => $registrars,
            'countries' => $countries,
            'registrar' => $registrar,
        ]);
    }
}