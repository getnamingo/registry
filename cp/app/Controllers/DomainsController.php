<?php

namespace App\Controllers;

use App\Models\Domain;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use League\ISO3166\ISO3166;

class DomainsController extends Controller
{
    public function listDomains(Request $request, Response $response)
    {
        return view($response,'admin/domains/listDomains.twig');
    }
   
    public function checkDomain(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $domainName = $data['domain_name'] ?? null;
            $token = $data['token'] ?? null;
            $claims = $data['claims'] ?? null;
            $db = $this->container->get('db');

            if ($domainName) {
                // Convert to Punycode if the domain is not in ASCII
                if (!mb_detect_encoding($domainName, 'ASCII', true)) {
                    $convertedDomain = idn_to_ascii($domainName, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    if ($convertedDomain === false) {
                        $this->container->get('flash')->addMessage('error', 'Domain conversion to Punycode failed');
                        return $response->withHeader('Location', '/domain/check')->withStatus(302);
                    } else {
                        $domainName = $convertedDomain;
                    }
                }

                $invalid_domain = validate_label($domainName, $db);
                if ($invalid_domain) {
                    $this->container->get('flash')->addMessage('error', 'Domain ' . $domainName . ' is not available: ' . $invalid_domain);
                    return $response->withHeader('Location', '/domain/check')->withStatus(302);
                }

                try {
                    $parts = extractDomainAndTLD($domainName, $db);
                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();
                    $this->container->get('flash')->addMessage('error', "Error: " . $errorMessage);
                    return $response->withHeader('Location', '/domain/check')->withStatus(302);
                }

                $domainModel = new Domain($db);
                $availability = $domainModel->getDomainByName($domainName);

                // Convert the DB result into a boolean '0' or '1'
                $availability = $availability ? '0' : '1';

                if (isset($claims)) {
                    $claim_key = $db->selectValue('SELECT claim_key FROM tmch_claims WHERE domain_label = ? LIMIT 1',[$parts['domain']]);
                    
                    if ($claim_key) {
                        $claim = 1;
                    } else {
                        $claim = 0;
                    }
                } else {
                    $claim = 2;
                }

                // If the domain is not taken, check if it's reserved
                if ($availability === '1') {
                    $domain_already_reserved = $db->selectRow('SELECT id,type FROM reserved_domain_names WHERE name = ? LIMIT 1',[$parts['domain']]);

                    if ($domain_already_reserved) {
                        if ($token !== null && $token !== '') {
                            $allocation_token = $db->selectValue('SELECT token FROM allocation_tokens WHERE domain_name = ? AND token = ?',[$domainName,$token]);
                                
                            if ($allocation_token) {
                                $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' is available!<br />Allocation token valid');
                                return $response->withHeader('Location', '/domain/check')->withStatus(302);
                            } else {
                                $this->container->get('flash')->addMessage('error', 'Domain ' . $domainName . ' is not available: Allocation Token mismatch');
                                return $response->withHeader('Location', '/domain/check')->withStatus(302);
                            }
                        } else {
                            $this->container->get('flash')->addMessage('info', 'Domain ' . $domainName . ' is not available, as it is ' . $domain_already_reserved['type'] . '!');
                            return $response->withHeader('Location', '/domain/check')->withStatus(302);
                        }
                    } else {
                        if ($claim == 1) {
                            $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' is available!<br />Claim exists.<br />Claim key is: ' . $claim_key);
                            return $response->withHeader('Location', '/domain/check')->withStatus(302);
                        } elseif ($claim == 2) {
                            $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' is available!');
                            return $response->withHeader('Location', '/domain/check')->withStatus(302);
                        } elseif ($claim == 0) {
                            $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' is available!<br />Claim does not exist');
                            return $response->withHeader('Location', '/domain/check')->withStatus(302);
                        }
                    }
                } else {
                    $this->container->get('flash')->addMessage('error', 'Domain ' . $domainName . ' is not available: In use');
                    return $response->withHeader('Location', '/domain/check')->withStatus(302);
                }
            }
        }

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/domains/checkDomain.twig');
    }
    
    public function createDomain(Request $request, Response $response)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $secureAuthInfoTransfer = isSecureAuthInfoTransferEnabled($db);
            $domainName = $data['domainName'] ?? null;
            // Convert to Punycode if the domain is not in ASCII
            if (!mb_detect_encoding($domainName, 'ASCII', true)) {
                $convertedDomain = idn_to_ascii($domainName, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                if ($convertedDomain === false) {
                    $this->container->get('flash')->addMessage('error', 'Domain conversion to Punycode failed');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
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
            $launch_phases = $db->selectValue("SELECT value FROM settings WHERE name = 'launch_phases'");
            
            $registrationYears = $data['registrationYears'];
            
            $contactRegistrant = $data['contactRegistrant'] ?? null;
            $contactAdmin = $data['contactAdmin'] ?? null;
            $contactTech = $data['contactTech'] ?? null;
            $contactBilling = $data['contactBilling'] ?? null;

            $phaseType = $data['phaseType'] ?? 'none';
            $phaseName = isset($data['phaseName']) && trim($data['phaseName']) !== '' ? $data['phaseName'] : null;

            $token = $data['token'] ?? null;

            $nameservers = !empty($data['nameserver']) ? $data['nameserver'] : null;
            $nameserver_ipv4 = !empty($data['nameserver_ipv4']) ? $data['nameserver_ipv4'] : null;
            $nameserver_ipv6 = !empty($data['nameserver_ipv6']) ? $data['nameserver_ipv6'] : null;

            $dsKeyTag = isset($data['dsKeyTag']) ? (int)$data['dsKeyTag'] : null;
            $dsAlg = $data['dsAlg'] ?? null;
            $dsDigestType = isset($data['dsDigestType']) ? (int)$data['dsDigestType'] : null;
            $dsDigest = $data['dsDigest'] ?? null;

            $dnskeyFlags = $data['dnskeyFlags'] ?? null;
            $dnskeyProtocol = $data['dnskeyProtocol'] ?? null;
            $dnskeyAlg = $data['dnskeyAlg'] ?? null;
            $dnskeyPubKey = $data['dnskeyPubKey'] ?? null;

            $authInfo = isset($data['authInfo']) && is_string($data['authInfo']) ? $data['authInfo'] : null;
            if ($secureAuthInfoTransfer && $authInfo === null) {
                $authInfo = '';
            }
            $invalid_domain = validate_label($domainName, $db);

            $uploadedFiles = $request->getUploadedFiles();
            $smdFile = $uploadedFiles['smd'] ?? null;
            $smd = null;

            if ($phaseType === 'sunrise' || $phaseType === 'landrush') {
                if ($smdFile && $smdFile->getError() === UPLOAD_ERR_OK) {
                    $smd = $smdFile->getStream()->getContents();
                    $smd_filename = $smdFile->getClientFilename();
                    
                    if (pathinfo($smd_filename, PATHINFO_EXTENSION) !== 'smd') {
                        $this->container->get('flash')->addMessage('error', 'Only .smd files are allowed');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }
                } else {
                    $this->container->get('flash')->addMessage('error', 'SMD file upload failed');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
            }

            if ($invalid_domain) {
                $this->container->get('flash')->addMessage('error', 'Error creating domain: Invalid domain name');
                return $response->withHeader('Location', '/domain/create')->withStatus(302);
            }

            try {
                $parts = extractDomainAndTLD($domainName, $db);
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $this->container->get('flash')->addMessage('error', "Error: " . $errorMessage);
                return $response->withHeader('Location', '/domain/create')->withStatus(302);
            }
            $label = $parts['domain'];
            $domain_extension = '.' . strtoupper($parts['tld']);

            $tld_id = $db->selectValue(
                "SELECT id FROM domain_tld WHERE UPPER(tld) = ?",
                [$domain_extension]
            );

            if (!$tld_id) {
                $this->container->get('flash')->addMessage('error', 'Error creating domain: Invalid domain extension');
                return $response->withHeader('Location', '/domain/create')->withStatus(302);
            }

            $domain_already_exist = $db->selectValue(
                'SELECT id FROM domain WHERE name = ? LIMIT 1',
                [$domainName]
            );

            if ($domain_already_exist) {
                $this->container->get('flash')->addMessage('error', 'Error creating domain: Domain name already exists');
                return $response->withHeader('Location', '/domain/create')->withStatus(302);
            }
            
            $currentDateTime = new \DateTime();
            $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

            $phase_details = $db->selectValue(
                "SELECT phase_category 
                 FROM launch_phases 
                 WHERE tld_id = ? 
                 AND start_date <= ? 
                 AND (end_date >= ? OR end_date IS NULL)
                 ",
                [$tld_id, $currentDate, $currentDate]
            );

            $noticeid = null;
            $notafter = null;
            $accepted = null;

            // Check if the phase requires application submission
            if ($phase_details && $phase_details === 'Application') {
                $this->container->get('flash')->addMessage('error', 'Domain registration is not allowed for this TLD. You must submit a new application instead.');
                return $response->withHeader('Location', '/domain/create')->withStatus(302);
            }

            if ($phase_details !== 'First-Come-First-Serve') {
                if ($phaseType !== 'none') {
                    if ($phaseType == null && $phaseType == '') {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: The launch phase ' . $phaseType . ' is improperly configured. Please check the settings or contact support.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    } else if ($phase_details == null) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: The launch phase ' . $phaseType . ' is currently not active.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }
                }
            } else if ($phaseType !== 'none') {
                if ($phaseType == null && $phaseType == '') {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: The launch phase ' . $phaseType . ' is improperly configured. Please check the settings or contact support.');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                } else if ($phase_details == null) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: The launch phase ' . $phaseType . ' is currently not active.');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
            }

            if ($phaseType === 'claims') {
                if (!isset($data['noticeid']) || $data['noticeid'] === '' ||
                    !isset($data['notafter']) || $data['notafter'] === '' ||
                    !isset($data['accepted']) || $data['accepted'] === '') {
                    $this->container->get('flash')->addMessage('error', "Error creating domain: 'noticeid', 'notafter', or 'accepted' cannot be empty when phaseType is 'claims'");
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                $noticeid = $data['noticeid'];
                $notafter = $data['notafter'];
                $accepted = $data['accepted'];

                // Validate that acceptedDate is before notAfter
                try {
                    $acceptedDate = DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', $data['accepted']);
                    $notAfterDate = DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', $data['notafter']);
                    
                    if (!$acceptedDate || !$notAfterDate) {
                        $this->container->get('flash')->addMessage('error', "Invalid date format");
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    if ($acceptedDate >= $notAfterDate) {
                        $this->container->get('flash')->addMessage('error', "Invalid dates: acceptedDate must be before notAfter");
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }
                } catch (Exception $e) {
                    $this->container->get('flash')->addMessage('error', "Invalid date format");
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                if (!validateTcnId($domainName, $noticeid, $data['notafter'])) {
                    $this->container->get('flash')->addMessage('error', "Invalid TMCH claims noticeID format");
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
            }

            if ($phaseType === 'sunrise') {
                if ($smd !== null && $smd !== '') {
                    // Extract the BASE64 encoded part
                    $beginMarker = "-----BEGIN ENCODED SMD-----";
                    $endMarker = "-----END ENCODED SMD-----";
                    $beginPos = strpos($smd, $beginMarker);
                    $endPos = strpos($smd, $endMarker);
                    if ($beginPos === false || $endPos === false || $endPos <= $beginPos) {
                        $this->container->get('flash')->addMessage('error', 'Invalid SMD file envelope.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $beginPos += strlen($beginMarker);
                    $encodedSMD = preg_replace('/\s+/', '', substr($smd, $beginPos, $endPos - $beginPos));

                    // Decode and parse the signed XML without modifying it.
                    $xmlContent = base64_decode($encodedSMD, true);
                    if ($xmlContent === false) {
                        $this->container->get('flash')->addMessage('error', 'SMD contains invalid BASE64 data.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $domDocument = new \DOMDocument();
                    $domDocument->preserveWhiteSpace = true;
                    $domDocument->formatOutput = false;
                    if (!$domDocument->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || $domDocument->doctype !== null) {
                        $this->container->get('flash')->addMessage('error', 'SMD contains invalid XML.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $xpath = new \DOMXPath($domDocument);
                    $xpath->registerNamespace('smd', 'urn:ietf:params:xml:ns:signedMark-1.0');
                    $xpath->registerNamespace('mark', 'urn:ietf:params:xml:ns:mark-1.0');
                    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

                    $signedMark = $domDocument->documentElement;
                    $signatureNodes = $xpath->query('//ds:Signature');
                    if (
                        !($signedMark instanceof \DOMElement) ||
                        $signedMark->localName !== 'signedMark' ||
                        $signedMark->namespaceURI !== 'urn:ietf:params:xml:ns:signedMark-1.0' ||
                        $signedMark->getAttribute('id') === '' ||
                        $signatureNodes === false ||
                        $signatureNodes->length !== 1
                    ) {
                        $this->container->get('flash')->addMessage('error', 'SMD has an invalid signed-mark structure.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $signatureNode = $signatureNodes->item(0);
                    if (
                        !($signatureNode instanceof \DOMElement) ||
                        !($signatureNode->parentNode instanceof \DOMElement) ||
                        !$signatureNode->parentNode->isSameNode($signedMark)
                    ) {
                        $this->container->get('flash')->addMessage('error', 'SMD signature is not attached to the signed-mark root.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $referenceNodes = $xpath->query('./ds:SignedInfo/ds:Reference', $signatureNode);
                    $expectedReference = '#' . $signedMark->getAttribute('id');
                    if (
                        $referenceNodes === false ||
                        $referenceNodes->length !== 1 ||
                        $referenceNodes->item(0)->getAttribute('URI') !== $expectedReference ||
                        $xpath->evaluate('string(./ds:SignedInfo/ds:CanonicalizationMethod/@Algorithm)', $signatureNode) !== \RobRichards\XMLSecLibs\XMLSecurityDSig::EXC_C14N ||
                        $xpath->evaluate('string(./ds:SignedInfo/ds:SignatureMethod/@Algorithm)', $signatureNode) !== \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256 ||
                        $xpath->evaluate('string(./ds:SignedInfo/ds:Reference/ds:DigestMethod/@Algorithm)', $signatureNode) !== \RobRichards\XMLSecLibs\XMLSecurityDSig::SHA256
                    ) {
                        $this->container->get('flash')->addMessage('error', 'SMD uses an invalid signature profile.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $certNodes = $xpath->query('./ds:KeyInfo/ds:X509Data/ds:X509Certificate', $signatureNode);
                    if ($certNodes === false || $certNodes->length !== 1) {
                        $this->container->get('flash')->addMessage('error', 'SMD must contain exactly one signing certificate.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $certNode = $certNodes->item(0)->textContent;
                    $certBase64 = preg_replace('/\s+/', '', $certNode);
                    if ($certBase64 === '' || base64_decode($certBase64, true) === false) {
                        $this->container->get('flash')->addMessage('error', 'Invalid SMD certificate format.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $certPem = "-----BEGIN CERTIFICATE-----\n" .
                               chunk_split($certBase64, 64, "\n") .
                               "-----END CERTIFICATE-----\n";

                    $tmchRoot = '/etc/ssl/certs/tmch.pem';
                    if (!is_readable($tmchRoot)) {
                        $this->container->get('flash')->addMessage('error', 'TMCH root certificate is missing on server.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $certRes = openssl_x509_read($certPem);
                    if ($certRes === false) {
                        $this->container->get('flash')->addMessage('error', 'Invalid SMD certificate format.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $ok = openssl_x509_checkpurpose($certRes, X509_PURPOSE_ANY, [$tmchRoot]);
                    if ($ok !== true && $ok !== 1) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: SMD certificate is not issued by the trusted TMCH root.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    // Load the SMD certificate
                    $x509 = new \phpseclib3\File\X509();
                    $cert = $x509->loadX509($certPem);
                    $serial = strtoupper($cert['tbsCertificate']['serialNumber']->toHex()); // serial as hex

                    // Get latest CRL from DB
                    $crlDer = $db->selectValue('SELECT content FROM tmch_crl ORDER BY update_timestamp DESC LIMIT 1');

                    if (!is_string($crlDer) || $crlDer === '') {
                        $this->container->get('flash')->addMessage('error', 'TMCH certificate revocation list is unavailable.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    // Load and parse the CRL
                    $crl = new \phpseclib3\File\X509();
                    $crlData = $crl->loadCRL($crlDer);
                    if (!is_array($crlData)) {
                        $this->container->get('flash')->addMessage('error', 'TMCH certificate revocation list is invalid.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    // Check revoked serials
                    $revoked = $crlData['tbsCertList']['revokedCertificates'] ?? [];
                    foreach ($revoked as $entry) {
                        $revokedSerial = strtoupper($entry['userCertificate']->toHex());
                        if ($revokedSerial === $serial) {
                            $this->container->get('flash')->addMessage('error', 'Error creating domain: SMD certificate has been revoked');
                            return $response->withHeader('Location', '/domain/create')->withStatus(302);
                        }
                    }

                    // Verify both SignedInfo and the digest of the referenced signedMark.
                    try {
                        $dsig = new \RobRichards\XMLSecLibs\XMLSecurityDSig();
                        $locatedSignature = $dsig->locateSignature($domDocument);
                        if ($locatedSignature === null || !$locatedSignature->isSameNode($signatureNode)) {
                            throw new \RuntimeException('Unable to locate the expected SMD signature.');
                        }

                        $dsig->idKeys = ['id'];
                        $dsig->canonicalizeSignedInfo();

                        $key = new \RobRichards\XMLSecLibs\XMLSecurityKey(
                            \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256,
                            ['type' => 'public']
                        );
                        $key->loadKey($certPem, false, true);

                        $dsig->validateReference();
                        $validatedNodes = $dsig->getValidatedNodes();
                        $validatedNode = is_array($validatedNodes) && count($validatedNodes) === 1
                            ? reset($validatedNodes)
                            : null;
                        if (
                            !($validatedNode instanceof \DOMElement) ||
                            !$validatedNode->isSameNode($signedMark) ||
                            $dsig->verify($key) !== 1
                        ) {
                            throw new \RuntimeException('SMD signature validation failed.');
                        }
                    } catch (\Throwable $e) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: The XML signature of the SMD file is not valid.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    // Read business data only from the node whose digest was validated.
                    $smdId = trim((string) $xpath->evaluate('string(./smd:id[1])', $signedMark));
                    $notBeforeValue = trim((string) $xpath->evaluate('string(./smd:notBefore[1])', $signedMark));
                    $notAfterValue = trim((string) $xpath->evaluate('string(./smd:notAfter[1])', $signedMark));
                    $markName = trim((string) $xpath->evaluate('string(.//mark:markName[1])', $signedMark));
                    $markId = trim((string) $xpath->evaluate('string(.//mark:id[1])', $signedMark));
                    $labels = [];
                    foreach ($xpath->query('.//mark:label', $signedMark) as $x_label) {
                        $labels[] = strtolower(trim($x_label->nodeValue));
                    }

                    if ($smdId === '' || $notBeforeValue === '' || $notAfterValue === '' || $markName === '' || $markId === '') {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: SMD is missing required signed data.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $isRevoked = $db->selectValue(
                        "SELECT 1 FROM tmch_revocation WHERE smd_id = ?",
                        [ $smdId ]
                    );
                    if ($isRevoked) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: SMD certificate has been revoked');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    try {
                        $notBefore = new \DateTime($notBeforeValue);
                        $notafter = new \DateTime($notAfterValue);
                    } catch (\Throwable $e) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: SMD contains invalid validity dates.');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    if (!in_array(strtolower($label), $labels, true)) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: SMD file is not valid for the domain name being registered');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    // Check if current date and time is between notBefore and notAfter
                    $now = new \DateTime();
                    if (!($now >= $notBefore && $now <= $notafter)) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: Current time is outside the valid range in the SMD');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }

                    $accepted = (new \DateTime())->format('Y-m-d H:i:s.v');
                } else {
                    $this->container->get('flash')->addMessage('error', "Error creating domain: SMD upload is required in the 'sunrise' phase");
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
            }

            $domain_already_reserved = $db->selectValue(
                'SELECT id FROM reserved_domain_names WHERE name = ? LIMIT 1',
                [$label]
            );

            if ($domain_already_reserved) {
                if ($token !== null && $token !== '') {
                    $allocation_token = $db->selectValue('SELECT token FROM allocation_tokens WHERE domain_name = ? AND token = ?',[$domainName,$token]);
                                
                    if (!$allocation_token) {
                        $this->container->get('flash')->addMessage('error', 'Domain ' . $domainName . ' is not available: Allocation Token mismatch');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }
                } else {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Domain name is reserved or restricted');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
            }
            
            if ($registrationYears && (($registrationYears < 1) || ($registrationYears > 10))) {
                $this->container->get('flash')->addMessage('error', 'Error creating domain: Domain period must be from 1 to 10');
                return $response->withHeader('Location', '/domain/create')->withStatus(302);
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
            
            $result = $db->selectRow('SELECT accountBalance AS "accountBalance", creditLimit AS "creditLimit", currency FROM registrar WHERE id = ?', [$clid]);

            $registrar_balance = $result['accountBalance'];
            $creditLimit = $result['creditLimit'];
            $currency = $result['currency'];
            
            $returnValue = getDomainPrice($db, $domainName, $tld_id, $date_add, 'create', $clid, $currency);
            $price = $returnValue['price'];

            if (!isset($price) || ($returnValue['type'] ?? 'not_found') === 'not_found') {
                $this->container->get('flash')->addMessage('error', 'Error creating domain: The price, period and currency for such TLD are not declared');
                return $response->withHeader('Location', '/domain/create')->withStatus(302);
            }

            if (($registrar_balance + $creditLimit) < $price) {
                $this->container->get('flash')->addMessage('error', 'Error creating domain: Low credit: minimum threshold reached');
                return $response->withHeader('Location', '/domain/create')->withStatus(302);
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
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Duplicate nameservers detected. Please provide unique nameservers.');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
                
                foreach ($nameservers as $index => $nameserver) {
                    if (preg_match("/^-|^\.-|-\.$|^\.$/", $nameserver)) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: Invalid hostName');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }
                    
                    if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $nameserver) && strlen($nameserver) < 254) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: Invalid hostName');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }
                }
            }
            
            if ($contactRegistrant) {
                $validRegistrant = validate_identifier($contactRegistrant);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactRegistrant]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Registrant does not exist');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
            }
            
            if ($contactAdmin) {
                $validAdmin = validate_identifier($contactAdmin);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactAdmin]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Admin contact does not exist');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
            }
            
            if ($contactTech) {
                $validTech = validate_identifier($contactTech);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactTech]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Tech contact does not exist');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
            }
            
            if ($contactBilling) {
                $validBilling = validate_identifier($contactBilling);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactBilling]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Billing contact does not exist');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
            }
            
            if (!$secureAuthInfoTransfer) {
                if (!$authInfo) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Missing domain authinfo');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                if (strlen($authInfo) < 6 || strlen($authInfo) > 16) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Password needs to be at least 6 and up to 16 characters long');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                if (!preg_match('/[A-Z]/', $authInfo)) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Password should have both upper and lower case characters');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
            } elseif ($authInfo !== '' && !isSecureAuthInfo($authInfo)) {
                $this->container->get('flash')->addMessage('error', 'Error creating domain: Non-empty authInfo must be 20 to 64 printable ASCII characters (25 if alphanumeric only)');
                return $response->withHeader('Location', '/domain/create')->withStatus(302);
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
                    'name' => strtolower($domainName),
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
                    'addperiod' => $date_add,
                    'phase_name' => $phaseName ?? null,
                    'tm_phase' => $phaseType ?? 'none',
                    'tm_smd_id' => $markId ?? null,
                    'tm_notice_id' => $noticeid ?? null,
                    'tm_notice_accepted' => $accepted ?? null,
                    'tm_notice_expires' => isset($notafter) ? ($notafter instanceof \DateTime ? $notafter->format('Y-m-d H:i:s.v') : $notafter) : null
                ]);
                $domain_id = $db->getlastInsertId(envi('DB_DRIVER') === 'pgsql' ? 'domain_id_seq' : null);

                if ($secureAuthInfoTransfer) {
                    storeAuthInfo($db, 'domain', (int)$domain_id, $authInfo);
                } else {
                    $db->insert(
                        envi('DB_DRIVER') === 'pgsql' ? 'domain_authinfo' : 'domain_authInfo',
                        [
                            'domain_id' => $domain_id,
                            'authtype' => 'pw',
                            'authinfo' => $authInfo
                        ]
                    );
                }

                if ($_SESSION["auth_roles"] != 0) {
                    $clientStatuses = $data['clientStatuses'] ?? [];
                    
                    foreach ($clientStatuses as $status => $value) {
                        if ($value === 'on') {
                            // Insert or update the status in the database
                            $db->exec(
                                envi('DB_DRIVER') === 'pgsql'
                                    ? 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON CONFLICT (domain_id, status) DO NOTHING'
                                    : 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)',
                                [
                                    $domain_id,
                                    $status
                                ]
                            );
                        }
                    }

                } else {
                    $clientStatuses = $data['clientStatuses'] ?? [];
                    $serverStatuses = $data['serverStatuses'] ?? [];
                                     
                    foreach ($clientStatuses as $status => $value) {
                        if ($value === 'on') {
                            // Insert or update the status in the database
                            $db->exec(
                                envi('DB_DRIVER') === 'pgsql'
                                    ? 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON CONFLICT (domain_id, status) DO NOTHING'
                                    : 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)',
                                [
                                    $domain_id,
                                    $status
                                ]
                            );
                        }
                    }
                    
                    foreach ($serverStatuses as $status => $value) {
                        if ($value === 'on') {
                            // Insert or update the status in the database
                            $db->exec(
                                envi('DB_DRIVER') === 'pgsql'
                                    ? 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON CONFLICT (domain_id, status) DO NOTHING'
                                    : 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)',
                                [
                                    $domain_id,
                                    $status
                                ]
                            );
                        }
                    }
                }
                
                // Data sanity checks
                // Validate keyTag
                if (!empty($dsKeyTag)) {
                    if (!is_int($dsKeyTag)) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: Incomplete key tag provided');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }
                
                    if ($dsKeyTag < 0 || $dsKeyTag > 65535) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: Incomplete key tag provided');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }
                }

                // Validate alg
                $validAlgorithms = [8, 13, 14, 15, 16];
                if (!empty($dsAlg) && !in_array($dsAlg, $validAlgorithms)) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Incomplete algorithm provided');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                // Validate digestType and digest
                if (!empty($dsDigestType) && !is_int($dsDigestType)) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Incomplete digest type provided');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }
                $validDigests = [
                2 => 64,  // SHA-256
                4 => 96   // SHA-384
                ];
                if (!empty($dsDigest)) {
                    if (strlen($dsDigest) != $validDigests[$dsDigestType] || !ctype_xdigit($dsDigest)) {
                        $this->container->get('flash')->addMessage('error', 'Error creating domain: Invalid digest length or format');
                        return $response->withHeader('Location', '/domain/create')->withStatus(302);
                    }
                }

                // Data sanity checks for keyData
                // Validate flags
                $validFlags = [256, 257];
                if (!empty($dnskeyFlags) && !in_array($dnskeyFlags, $validFlags)) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Invalid flags provided');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                // Validate protocol
                if (!empty($dnskeyProtocol) && $dnskeyProtocol != 3) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Invalid protocol provided');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                // Validate algKeyData
                if (!empty($dnskeyAlg)) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Invalid algorithm encoding');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                // Validate pubKey
                if (!empty($dnskeyPubKey) && base64_encode(base64_decode($dnskeyPubKey, true)) !== $dnskeyPubKey) {
                    $this->container->get('flash')->addMessage('error', 'Error creating domain: Invalid public key encoding');
                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
                }

                if (!empty($dsKeyTag)) {
                    // Base data for the insert
                    $insertData = [
                        'domain_id' => $domain_id,
                        'maxsiglife' => $maxSigLife,
                        'interface' => 'dsData',
                        'keytag' => $dsKeyTag,
                        'alg' => $dsAlg,
                        'digesttype' => $dsDigestType,
                        'digest' => $dsDigest,
                        'flags' => null,
                        'protocol' => null,
                        'keydata_alg' => null,
                        'pubkey' => null
                    ];

                    // Check additional conditions for dnskeyFlags
                    if (isset($dnskeyFlags) && $dnskeyFlags !== "") {
                        $insertData['flags'] = $dnskeyFlags;
                        $insertData['protocol'] = $dnskeyProtocol;
                        $insertData['keydata_alg'] = $dnskeyAlg;
                        $insertData['pubkey'] = $dnskeyPubKey;
                    }

                    // Perform the insert
                    $db->insert('secdns', $insertData);
                }
                
                if (!debitRegistrarBalance($db, (int)$clid, $price)) {
                    throw new \RuntimeException('Low credit: minimum threshold reached');
                }

                $db->exec(
                    'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                    [$clid, "create domain " . strtolower($domainName) . " for period $date_add MONTH", "-$price"]
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
                        'domain_name' => strtolower($domainName),
                        'length_in_months' => $date_add,
                        'fromS' => $from,
                        'toS' => $to,
                        'amount' => $price
                    ]
                );

                if (!empty($nameservers)) {
                    foreach ($nameservers as $index => $nameserver) {

                        $parts_host = extractHostTLD($nameserver);
                        $host_extension = $parts_host['tld'] ?? ''; // Extract only the TLD

                        // Initialize $internal_host as false
                        $internal_host = false;

                        // Validate the extracted TLD before querying
                        if (!empty($host_extension)) {
                            $tldExists = $db->selectValue(
                                'SELECT 1 FROM domain_tld WHERE tld = ? LIMIT 1',
                                ['.' . strtolower($host_extension)]
                            );

                            // Correctly set $internal_host to true only if $tldExists is not NULL
                            $internal_host = $tldExists !== null;
                        }

                        // Check if the host name already exists
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
                                        'channel' => 'control_panel',
                                        'level' => 300,
                                        'level_name' => 'WARNING',
                                        'message' => "Domain: $domainName; hostName: $nameserver - is duplicated",
                                        'context' => json_encode([
                                            'registrar_id' => $clid, 
                                            'domain' => $domainName, 
                                            'host' => $nameserver
                                        ]),
                                        'extra' => json_encode([]),
                                        'created_at' => $logdate
                                    ]
                                );
                            }
                        } else {
                            $currentDateTime = new \DateTime();
                            $host_date = $currentDateTime->format('Y-m-d H:i:s.v');

                            if ($internal_host) {
                                if (str_ends_with(strtolower(trim($nameserver)), strtolower(trim($domainName)))) {
                                    $db->insert(
                                        'host',
                                        [
                                            'name' => strtolower($nameserver),
                                            'domain_id' => $domain_id,
                                            'clid' => $clid,
                                            'crid' => $clid,
                                            'crdate' => $host_date
                                        ]
                                    );
                                } else {
                                    $db->insert(
                                        'host',
                                        [
                                            'name' => strtolower($nameserver),
                                            'domain_id' => null,
                                            'clid' => $clid,
                                            'crid' => $clid,
                                            'crdate' => $host_date
                                        ]
                                    );
                                }
                                $host_id = $db->getlastInsertId(envi('DB_DRIVER') === 'pgsql' ? 'host_id_seq' : null);
                            } else {
                                $db->insert(
                                    'host',
                                    [
                                        'name' => strtolower($nameserver),
                                        'clid' => $clid,
                                        'crid' => $clid,
                                        'crdate' => $host_date
                                    ]
                                );
                                $host_id = $db->getlastInsertId(envi('DB_DRIVER') === 'pgsql' ? 'host_id_seq' : null);
                            }

                            $db->insert(
                                'domain_host_map',
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
                                    $this->container->get('flash')->addMessage('error', 'Error creating domain: No IPv4 or IPv6 addresses provided for internal host');
                                    return $response->withHeader('Location', '/domain/create')->withStatus(302);
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

                $db->exec(envi('DB_DRIVER') === 'pgsql'
                    ? 'INSERT INTO statistics (date) VALUES(CURRENT_DATE) ON CONFLICT (date) DO NOTHING'
                    : 'INSERT IGNORE INTO statistics (date) VALUES(CURRENT_DATE)');

                $db->exec(
                    'UPDATE statistics SET created_domains = created_domains + 1 WHERE date = CURRENT_DATE'
                );
                
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/domain/create')->withStatus(302);
            } finally {
                // Validation failures above can return from inside the try.
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
            }
            
            $crdate = $db->selectValue(
                "SELECT crdate FROM domain WHERE id = ? LIMIT 1",
                [$domain_id]
            );
            
            $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' has been created successfully on ' . $crdate);
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
            $currency = $_SESSION['_currency'] ?? 'EUR';
            if (!empty($_SESSION['auth_registrar_id'])) {
                $currency = $db->selectValue(
                    'SELECT currency FROM registrar WHERE id = ?',
                    [$_SESSION['auth_registrar_id']]
                ) ?? 'EUR'; // Default to EUR if no result
            }
        } else {
            $registrar = null;
            $currency = $_SESSION['_currency'] ?? 'EUR';
        }
        $registry_currency = $_SESSION['registry_currency'] ?? 'EUR';

        $locale = (isset($_SESSION['_lang']) && !empty($_SESSION['_lang'])) ? $_SESSION['_lang'] : 'en_US';

        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currency);

        $symbol = $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
        $pattern = $formatter->getPattern();

        // Determine currency position (before or after)
        $position = (strpos($pattern, '¤') < strpos($pattern, '#')) ? 'before' : 'after';
        
        $launch_phases = $db->selectValue("SELECT value FROM settings WHERE name = 'launch_phases'");

        $iso3166 = new ISO3166();
        $countries = $iso3166->all();

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/domains/createDomain.twig', [
            'registrars' => $registrars,
            'currencySymbol' => $symbol,
            'currencyPosition' => $position,
            'registrar' => $registrar,
            'countries' => $countries,
            'launch_phases' => $launch_phases,
            'currency' => $currency,
            'registry_currency' => $registry_currency,
            'secureAuthInfoTransfer' => isSecureAuthInfoTransferEnabled($db),
        ]);
    }
    
    public function viewDomain(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = strtolower(trim($args));

            if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
        
            $domain = $db->selectRow('SELECT id, name, registrant, crdate, exdate, lastupdate, clid, idnlang, rgpstatus FROM domain WHERE name = ?',
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
                $secureAuthInfoTransfer = isSecureAuthInfoTransferEnabled($db);
                $domainAuth = $secureAuthInfoTransfer ? null : $db->selectRow('SELECT * FROM domain_authInfo WHERE domain_id = ?',
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

                if (strpos($domain['name'], 'xn--') === 0) {
                    $domain['name_o'] = $domain['name'];
                    $domain['name'] = idn_to_utf8($domain['name'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                } else {
                    $domain['name_o'] = $domain['name'];
                }

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
                // Domain does not exist, redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

        } else {
            // Redirect to the domains view
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

    }

    public function historyDomain(Request $request, Response $response, $args) 
    {
        $db = $this->container->get('db');
        $db_audit = $this->container->get('db_audit');
        // Get the current URI
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = strtolower(trim($args));

            if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

            $domain = $db->selectRow('SELECT id, name, clid FROM domain WHERE name = ?',
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

                $auditSchema = envi('DB_DRIVER') === 'pgsql' ? "'public'" : 'DATABASE()';
                $auditEnabled = (int) $db_audit->selectValue(
                    "SELECT COUNT(*)
                     FROM information_schema.tables
                     WHERE table_schema = $auditSchema
                       AND table_name = 'domain'"
                ) > 0;

                if (!$auditEnabled) {
                    $this->container->get('flash')->addMessage(
                        'error',
                        'Audit database is not configured. See the documentation to enable it.'
                    );

                    return $response
                        ->withHeader('Location', '/domain/view/'.$domain['name'])
                        ->withStatus(302);
                }

                try {
                    $exists = $db_audit->selectValue('SELECT 1 FROM domain LIMIT 1');
                } catch (\PDOException $e) {
                    throw new \RuntimeException('Audit table is empty or not configured');
                }

                $history = $db_audit->select(
                    'SELECT * FROM domain WHERE name = ? ORDER BY audit_timestamp DESC, audit_rownum ASC',
                    [$args]
                );
                $users = $db->select('SELECT id, username FROM users');

                $userMap = array_column($users, 'username', 'id');

                if (!empty($history)) {
                    foreach ($history as &$row) {
                        if (isset($userMap[$row['audit_usr_id']])) {
                            $row['audit_usr_id'] = $userMap[$row['audit_usr_id']];
                        }
                    }
                    unset($row);
                }

                if (strpos($domain['name'], 'xn--') === 0) {
                    $domain['name_o'] = $domain['name'];
                    $domain['name'] = idn_to_utf8($domain['name'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                } else {
                    $domain['name_o'] = $domain['name'];
                }

                return view($response,'admin/domains/historyDomain.twig', [
                    'domain' => $domain,
                    'history' => $history,
                    'currentUri' => $uri
                ]);
            } else {
                // Domain does not exist, redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

        } else {
            // Redirect to the domains view
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

    }

    public function updateDomain(Request $request, Response $response, $args)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        $db = $this->container->get('db');
        $registrars_list = $db->select("SELECT id, clid, name FROM registrar");
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
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

            $domain = $db->selectRow('SELECT id, name, registrant, crdate, exdate, lastupdate, clid, idnlang, rgpstatus FROM domain WHERE name = ?',
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
                $secureAuthInfoTransfer = isSecureAuthInfoTransferEnabled($db);
                $domainAuth = $secureAuthInfoTransfer ? null : $db->selectRow('SELECT authinfo FROM domain_authInfo WHERE domain_id = ?',
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
                
                $csrfTokenName = $this->container->get('csrf')->getTokenName();
                $csrfTokenValue = $this->container->get('csrf')->getTokenValue();

                if (strpos($domain['name'], 'xn--') === 0) {
                    $domain['punycode'] = $domain['name'];
                    $domain['name'] = idn_to_utf8($domain['name'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                } else {
                    $domain['punycode'] = $domain['name'];
                }
                $_SESSION['domains_to_update'] = [$domain['punycode']];
                
                $iso3166 = new ISO3166();
                $countries = $iso3166->all();

                return view($response,'admin/domains/updateDomain.twig', [
                    'domain' => $domain,
                    'domainStatus' => $domainStatus,
                    'domainAuth' => $domainAuth,
                    'domainRegistrant' => $domainRegistrant,
                    'domainSecdns' => $domainSecdns,
                    'domainHosts' => $domainHosts,
                    'domainContacts' => $domainContacts,
                    'registrar' => $registrar,
                    'registrar_details' => $registrars,
                    'registrars_list' => $registrars_list,
                    'currentUri' => $uri,
                    'countries' => $countries,
                    'csrfTokenName' => $csrfTokenName,
                    'csrfTokenValue' => $csrfTokenValue,
                    'secureAuthInfoTransfer' => $secureAuthInfoTransfer
               ]);
            } else {
                // Domain does not exist, redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

        } else {
            // Redirect to the domains view
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }
    }

    public function changeDomainRegistrar(Request $request, Response $response)
    {
        // Registry administrators only
        if (!isset($_SESSION['auth_roles']) || (int) $_SESSION['auth_roles'] !== 0) {
            $this->container->get('flash')->addMessage('error', 'Access denied');
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        $data = $request->getParsedBody();
        $domainId = filter_var($data['domain_id'] ?? null, FILTER_VALIDATE_INT);
        $newRegistrarId = filter_var($data['registrar_id'] ?? null, FILTER_VALIDATE_INT);
        $db = $this->container->get('db');

        $domain = $db->selectRow('SELECT id, name, clid FROM domain WHERE id = ?', [$domainId]);

        $newRegistrar = $db->selectRow('SELECT id, name FROM registrar WHERE id = ?', [$newRegistrarId]);

        if (!$domain || !$newRegistrar || (int) $domain['clid'] === (int) $newRegistrarId) {
            $this->container->get('flash')->addMessage('error', 'Invalid registrar change');
            return $response
                ->withHeader('Location', $domain ? '/domain/update/'.$domain['name'] : '/domains')
                ->withStatus(302);
        }

        $db->update('domain', ['clid' => (int) $newRegistrarId], ['id' => (int) $domainId]);

        $this->container->get('flash')->addMessage('success', 'Domain ' . $domain['name'] . ' moved to ' . $newRegistrar['name']);

        return $response->withHeader('Location', '/domain/update/'.$domain['name'])->withStatus(302);
    }

    public function updateDomainProcess(Request $request, Response $response)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $secureAuthInfoTransfer = isSecureAuthInfoTransferEnabled($db);
            if (!empty($_SESSION['domains_to_update'])) {
                $domainName = $_SESSION['domains_to_update'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No domain specified for update');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
            $domain_id = $db->selectValue('SELECT id FROM domain WHERE name = ?', [$domainName]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                $domain_clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
                if ($domain_clid != $clid) {
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
            } else {
                $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
            }
            
            $results = $db->select(
                'SELECT status FROM domain_status WHERE domain_id = ?',
                [ $domain_id ]
            ) ?? [];

            foreach ($results as $row) {
                $status = $row['status'];
                if (preg_match('/.*(serverUpdateProhibited)$/', $status) || preg_match('/^pendingTransfer/', $status)) {
                    $this->container->get('flash')->addMessage('error', 'It has a status that does not allow update, first change the status');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            }
            
            $contactRegistrant = $data['contactRegistrant'] ?? null;
            $contactAdmin = $data['contactAdmin'] ?? null;
            $contactTech = $data['contactTech'] ?? null;
            $contactBilling = $data['contactBilling'] ?? null;
            
            $nameservers = $data['nameserver'] ?? [];

            $dsKeyTag = isset($data['dsKeyTag']) ? (int)$data['dsKeyTag'] : null;
            $dsAlg = $data['dsAlg'] ?? null;
            $dsDigestType = isset($data['dsDigestType']) ? (int)$data['dsDigestType'] : null;
            $dsDigest = $data['dsDigest'] ?? null;
            
            $dnskeyFlags = $data['dnskeyFlags'] ?? null;
            $dnskeyProtocol = $data['dnskeyProtocol'] ?? null;
            $dnskeyAlg = $data['dnskeyAlg'] ?? null;
            $dnskeyPubKey = $data['dnskeyPubKey'] ?? null;
            
            $authInfo = isset($data['authInfo']) && is_string($data['authInfo']) ? $data['authInfo'] : null;
            
            if ($contactRegistrant) {
                $validRegistrant = validate_identifier($contactRegistrant);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactRegistrant]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Registrant does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } else {
                if (envi('MINIMUM_DATA') === 'false') {
                    $this->container->get('flash')->addMessage('error', 'Please provide registrant identifier');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            }
            
            if ($contactAdmin) {
                $validAdmin = validate_identifier($contactAdmin);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactAdmin]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Admin contact does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } else {
                if (envi('MINIMUM_DATA') === 'false') {
                    $this->container->get('flash')->addMessage('error', 'Please provide admin contact identifier');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            }
            
            if ($contactTech) {
                $validTech = validate_identifier($contactTech);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactTech]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Tech contact does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } else {
                if (envi('MINIMUM_DATA') === 'false') {
                    $this->container->get('flash')->addMessage('error', 'Please provide tech contact identifier');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            }
            
            if ($contactBilling) {
                $validBilling = validate_identifier($contactBilling);
                $row = $db->selectRow('SELECT id, clid FROM contact WHERE identifier = ?', [$contactBilling]);

                if (!$row) {
                    $this->container->get('flash')->addMessage('error', 'Billing contact does not exist');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if ($clid != $row['clid']) {
                    $this->container->get('flash')->addMessage('error', 'The contact requested in the command does NOT belong to the current registrar');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            }
            
            if (!$secureAuthInfoTransfer) {
                if (!$authInfo) {
                    $this->container->get('flash')->addMessage('error', 'Domain ' . $domainName . ' can not be updated: Missing domain authinfo');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if (strlen($authInfo) < 6 || strlen($authInfo) > 16) {
                    $this->container->get('flash')->addMessage('error', 'Password needs to be at least 6 and up to 16 characters long');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if (!preg_match('/[A-Z]/', $authInfo)) {
                    $this->container->get('flash')->addMessage('error', 'Password should have both upper and lower case characters');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
            } elseif ($authInfo !== null && $authInfo !== '' && !isSecureAuthInfo($authInfo)) {
                $this->container->get('flash')->addMessage('error', 'Non-empty authInfo must be 20 to 64 printable ASCII characters (25 if alphanumeric only)');
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }
            
            $registrant_id = $db->selectValue(
                'SELECT id FROM contact WHERE identifier = ? LIMIT 1',
                [$contactRegistrant]
            );
            
            try {
                $db->beginTransaction();
                
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                $db->update('domain', [
                    'registrant' => $registrant_id,
                    'lastupdate' => $update,
                    'upid' => $clid
                ],
                [
                    'name' => $domainName
                ]
                );
                $domain_id = $db->selectValue(
                    'SELECT id FROM domain WHERE name = ?',
                    [$domainName]
                );

                if ($secureAuthInfoTransfer) {
                    if ($authInfo !== null) {
                        storeAuthInfo($db, 'domain', (int)$domain_id, $authInfo);
                    }
                } else {
                    $db->update(
                        envi('DB_DRIVER') === 'pgsql' ? 'domain_authinfo' : 'domain_authInfo',
                        [
                            'authinfo' => $authInfo
                        ],
                        [
                            'domain_id' => $domain_id,
                            'authtype' => 'pw'
                        ]
                    );
                }

                if ($_SESSION["auth_roles"] != 0) {
                    $clientStatuses = $data['clientStatuses'] ?? [];
                    
                    $db->delete(
                        'domain_status',
                        [
                            'domain_id' => $domain_id
                        ]
                    );
                    
                    foreach ($clientStatuses as $status => $value) {                            
                        if ($value === 'on') {
                            // Insert or update the status in the database
                            $db->exec(
                                envi('DB_DRIVER') === 'pgsql'
                                    ? 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON CONFLICT (domain_id, status) DO NOTHING'
                                    : 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)',
                                [
                                    $domain_id,
                                    $status
                                ]
                            );
                        }
                    }

                } else {
                    $clientStatuses = $data['clientStatuses'] ?? [];
                    $serverStatuses = $data['serverStatuses'] ?? [];

                    $db->delete(
                        'domain_status',
                        [
                            'domain_id' => $domain_id
                        ]
                    );
                    
                    foreach ($clientStatuses as $status => $value) {                       
                        if ($value === 'on') {
                            // Insert or update the status in the database
                            $db->exec(
                                envi('DB_DRIVER') === 'pgsql'
                                    ? 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON CONFLICT (domain_id, status) DO NOTHING'
                                    : 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)',
                                [
                                    $domain_id,
                                    $status
                                ]
                            );
                        }
                    }
                                  
                    foreach ($serverStatuses as $status => $value) {                   
                        if ($value === 'on') {
                            // Insert or update the status in the database
                            $db->exec(
                                envi('DB_DRIVER') === 'pgsql'
                                    ? 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON CONFLICT (domain_id, status) DO NOTHING'
                                    : 'INSERT INTO domain_status (domain_id, status) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)',
                                [
                                    $domain_id,
                                    $status
                                ]
                            );
                        }
                    }
                }
                
                // Data sanity checks
                // Validate keyTag
                if (!empty($dsKeyTag)) {
                    if (!is_int($dsKeyTag)) {
                        $this->container->get('flash')->addMessage('error', 'Incomplete key tag provided');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                
                    if ($dsKeyTag < 0 || $dsKeyTag > 65535) {
                        $this->container->get('flash')->addMessage('error', 'Incomplete key tag provided');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                }

                // Validate alg
                $validAlgorithms = [8, 13, 14, 15, 16];
                if (!empty($dsAlg) && !in_array($dsAlg, $validAlgorithms)) {
                    $this->container->get('flash')->addMessage('error', 'Incomplete algorithm provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate digestType and digest
                if (!empty($dsDigestType) && !is_int($dsDigestType)) {
                    $this->container->get('flash')->addMessage('error', 'Incomplete digest type provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }
                $validDigests = [
                    2 => 64,  // SHA-256
                    4 => 96   // SHA-384
                ];
                if (!empty($dsDigest)) {
                    if (strlen($dsDigest) != $validDigests[$dsDigestType] || !ctype_xdigit($dsDigest)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid digest length or format');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                }

                // Data sanity checks for keyData
                // Validate flags
                $validFlags = [256, 257];
                if (!empty($dnskeyFlags) && !in_array($dnskeyFlags, $validFlags)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid flags provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate protocol
                if (!empty($dnskeyProtocol) && $dnskeyProtocol != 3) {
                    $this->container->get('flash')->addMessage('error', 'Invalid protocol provided');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate algKeyData
                if (!empty($dnskeyAlg)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid algorithm encoding');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                // Validate pubKey
                if (!empty($dnskeyPubKey) && base64_encode(base64_decode($dnskeyPubKey, true)) !== $dnskeyPubKey) {
                    $this->container->get('flash')->addMessage('error', 'Invalid public key encoding');
                    return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                }

                if (!empty($dsKeyTag)) {
                    // Base data for the insert
                    $insertData = [
                        'domain_id' => $domain_id,
                        'maxsiglife' => $maxSigLife,
                        'interface' => 'dsData',
                        'keytag' => $dsKeyTag,
                        'alg' => $dsAlg,
                        'digesttype' => $dsDigestType,
                        'digest' => $dsDigest,
                        'flags' => null,
                        'protocol' => null,
                        'keydata_alg' => null,
                        'pubkey' => null
                    ];

                    // Check additional conditions for dnskeyFlags
                    if (isset($dnskeyFlags) && $dnskeyFlags !== "") {
                        $insertData['flags'] = $dnskeyFlags;
                        $insertData['protocol'] = $dnskeyProtocol;
                        $insertData['keydata_alg'] = $dnskeyAlg;
                        $insertData['pubkey'] = $dnskeyPubKey;
                    }

                    // Perform the insert
                    $db->insert('secdns', $insertData);
                }
   
                foreach ($nameservers as $index => $nameserver) {
                    if (preg_match("/^-|^\.-|-\.$|^\.$/", $nameserver)) {
                        $this->container->get('flash')->addMessage('error', 'Invalid hostName');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                    
                    if (!preg_match('/^([A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9]){0,1}\.){1,125}[A-Z0-9]([A-Z0-9-]{0,61}[A-Z0-9])$/i', $nameserver) && strlen($nameserver) < 254) {
                        $this->container->get('flash')->addMessage('error', 'Invalid hostName');
                        return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
                    }
                
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
                            $host_map_id = $db->selectValue(
                                'SELECT id FROM domain_host_map WHERE domain_id = ? AND host_id = ? LIMIT 1',
                                [$domain_id, $hostName_already_exist]
                            );
                            
                            $db->update(
                                'domain_host_map',
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
                        $host_id = $db->getlastInsertId(envi('DB_DRIVER') === 'pgsql' ? 'host_id_seq' : null);

                        $db->insert(
                            'domain_host_map',
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

                        $contact_map_id = $db->selectRow(
                            'SELECT * FROM domain_contact_map WHERE domain_id = ? AND type = ?',
                            [$domain_id, $type]
                        );

                        // Check if $contact_id is not null before update
                        if ($contact_id !== null) {
                            if ($contact_map_id !== null) {
                                $db->update(
                                    'domain_contact_map',
                                    [
                                        'contact_id' => $contact_id,
                                    ],
                                    [
                                        'id' => $contact_map_id['id']
                                    ]
                                );
                            } else {
                                $db->insert(
                                    'domain_contact_map',
                                    [
                                        'contact_id' => $contact_id,
                                        'domain_id' => $domain_id,
                                        'type' => $type
                                    ]
                                );
                            }
                        }
                    }
                }
           
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            } catch (\Pinga\Db\Throwable\IntegrityConstraintViolationException $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
            }

            unset($_SESSION['domains_to_update']);
            $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' has been updated successfully on ' . $update);
            return $response->withHeader('Location', '/domain/update/'.$domainName)->withStatus(302);
        }
    }
    
    public function domainDeleteHost(Request $request, Response $response)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        $db = $this->container->get('db');
        $data = $request->getParsedBody();
        $uri = $request->getUri()->getPath();

        if ($data['nameserver']) {
            $host_id = $db->selectValue('SELECT id FROM host WHERE name = ?',
                    [ $data['nameserver'] ]);
            $domain_id = $data['domain_id'];
            $domainName = $db->selectValue('SELECT name FROM domain WHERE id = ?',
                    [ $domain_id ]);
            $db->delete(
                'domain_host_map',
                [
                    'host_id' => $host_id,
                    'domain_id' => $domain_id
                ]
            );
            
            $this->container->get('flash')->addMessage('success', 'Host ' . $data['nameserver'] . ' has been removed from domain successfully');

            $jsonData = json_encode([
                'success' => true,
                'redirect' => '/domain/update/'.$domainName
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
    
    public function domainDeleteSecdns(Request $request, Response $response)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        $db = $this->container->get('db');
        $data = $request->getParsedBody();
        $uri = $request->getUri()->getPath();

        if ($data['record']) {
            $record = filter_var($data['record'], FILTER_SANITIZE_NUMBER_INT);
            $domain_id = filter_var($data['domain_id'], FILTER_SANITIZE_NUMBER_INT);
            
            $domainName = $db->selectValue('SELECT name FROM domain WHERE id = ?',
                    [ $domain_id ]);
            $db->delete(
                'secdns',
                [
                    'id' => $record,
                    'domain_id' => $domain_id
                ]
            );
            
            $this->container->get('flash')->addMessage('success', 'Record has been removed from domain successfully');

            $jsonData = json_encode([
                'success' => true,
                'redirect' => '/domain/update/'.$domainName
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
    
    public function renewDomain(Request $request, Response $response, $args)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            if (!empty($_SESSION['domains_to_renew'])) {
                $domainName = $_SESSION['domains_to_renew'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No domain specified for renewal');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

            $renewalYears = $data['renewalYears'] ?? null;
            
            $parts = extractDomainAndTLD($domainName, $db);
            $label = $parts['domain'];
            $domain_extension = '.' . strtoupper($parts['tld']);

            $tld_id = $db->selectValue(
                "SELECT id FROM domain_tld WHERE UPPER(tld) = ?",
                [$domain_extension]
            );

            if (!$tld_id) {
                $this->container->get('flash')->addMessage('error', 'Error creating domain: Invalid domain extension');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
            }

            $date_add = 0;
            $date_add = ($renewalYears * 12);
            
            $result = $db->selectRow('SELECT accountBalance AS "accountBalance", creditLimit AS "creditLimit", currency FROM registrar WHERE id = ?', [$clid]);

            $registrar_balance = $result['accountBalance'];
            $creditLimit = $result['creditLimit'];
            $currency = $result['currency'];
            
            $returnValue = getDomainPrice($db, $domainName, $tld_id, $date_add, 'renew', $clid, $currency);
            $price = $returnValue['price'];

            if (!isset($price) || ($returnValue['type'] ?? 'not_found') === 'not_found') {
                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                return $response->withHeader('Location', '/domain/renew/'.$domainName)->withStatus(302);
            }

            if (($registrar_balance + $creditLimit) < $price) {
                $this->container->get('flash')->addMessage('error', 'Low credit: minimum threshold reached');
                return $response->withHeader('Location', '/domain/renew/'.$domainName)->withStatus(302);
            }

            $domain_query = $db->selectRow(
                'SELECT id, clid FROM domain WHERE name = ?',
                [$domainName]
            );
            $domain_id = $domain_query['id'];
            $domain_clid = $domain_query['clid'];
            if ($domain_clid != $clid) {
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

            $results = $db->select(
                'SELECT status FROM domain_status WHERE domain_id = ?',
                [ $domain_id ]
            ) ?? [];

            foreach ($results as $row) {
                $status = $row['status'];
                if (preg_match('/.*(RenewProhibited)$/', $status) || preg_match('/^pending/', $status)) {
                    $this->container->get('flash')->addMessage('error', 'It has a status that does not allow renew, first change the status');
                    return $response->withHeader('Location', '/domain/renew/'.$domainName)->withStatus(302);
                }
            }
            
            try {
                $db->beginTransaction();

                $lockedDomain = $db->selectRow(
                    'SELECT id, name, tldid, exdate, clid
                     FROM domain
                     WHERE id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$domain_id]
                );
                if (!$lockedDomain || (int)$lockedDomain['clid'] !== (int)$clid) {
                    throw new \RuntimeException('Domain ownership changed before renewal');
                }

                $lockedStatuses = $db->select(
                    'SELECT status FROM domain_status WHERE domain_id = ? FOR UPDATE',
                    [$domain_id]
                ) ?? [];
                foreach ($lockedStatuses as $statusRow) {
                    $status = $statusRow['status'];
                    if (preg_match('/RenewProhibited$/', $status) || preg_match('/^pending/', $status)) {
                        throw new \RuntimeException('The domain status no longer allows renewal');
                    }
                }

                $account = $db->selectRow(
                    'SELECT accountBalance AS "accountBalance", creditLimit AS "creditLimit", currency
                     FROM registrar
                     WHERE id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$clid]
                );
                if (!$account) {
                    throw new \RuntimeException('Registrar account does not exist');
                }

                $returnValue = getDomainPrice(
                    $db,
                    $lockedDomain['name'],
                    $lockedDomain['tldid'],
                    $date_add,
                    'renew',
                    $clid,
                    $account['currency']
                );
                $price = $returnValue['price'] ?? null;
                if (!isset($price) || ($returnValue['type'] ?? 'not_found') === 'not_found') {
                    throw new \RuntimeException('The renewal price is not configured');
                }
                if (($account['accountBalance'] + $account['creditLimit']) < $price) {
                    throw new \RuntimeException('Low credit: minimum threshold reached');
                }

                $from = $lockedDomain['exdate'];
                $rgpstatus = 'renewPeriod';

                $renewedExdate = (new \DateTime($from))->modify("+$date_add months")->format('Y-m-d H:i:s.v');
                $db->exec(
                    'UPDATE domain SET exdate = ?, rgpstatus = ?, renewPeriod = ?, renewedDate = CURRENT_TIMESTAMP(3) WHERE id = ?',
                    [
                        $renewedExdate,
                        $rgpstatus,
                        $date_add,
                        $domain_id
                    ]
                );

                if (!debitRegistrarBalance($db, (int)$clid, $price)) {
                    throw new \RuntimeException('Low credit: minimum threshold reached');
                }
                
                $db->exec(
                    'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                    [$clid, "renew domain $domainName for period $date_add MONTH", "-$price"]
                );

                $to = $renewedExdate;

                $currentDateTime = new \DateTime();
                $stdate = $currentDateTime->format('Y-m-d H:i:s.v');
                $db->insert(
                    'statement',
                    [
                        'registrar_id' => $clid,
                        'date' => $stdate,
                        'command' => 'renew',
                        'domain_name' => $domainName,
                        'length_in_months' => $date_add,
                        'fromS' => $from,
                        'toS' => $to,
                        'amount' => $price
                    ]
                  );

                $db->exec(envi('DB_DRIVER') === 'pgsql'
                    ? 'INSERT INTO statistics (date) VALUES(CURRENT_DATE) ON CONFLICT (date) DO NOTHING'
                    : 'INSERT IGNORE INTO statistics (date) VALUES(CURRENT_DATE)');

                $db->exec(
                    'UPDATE statistics SET renewed_domains = renewed_domains + 1 WHERE date = CURRENT_DATE'
                );
                 
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
                $this->container->get('flash')->addMessage('error', 'Database failure during renew: ' . $e->getMessage());
                return $response->withHeader('Location', '/domain/renew/'.$domainName)->withStatus(302);
            }

            unset($_SESSION['domains_to_renew']);
            $this->container->get('flash')->addMessage('success','Domain ' . $domainName . ' has been renewed for ' . $renewalYears . ' ' . ($renewalYears > 1 ? 'years' : 'year'));
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
            $currency = $_SESSION['_currency'] ?? 'EUR';
            if (!empty($_SESSION['auth_registrar_id'])) {
                $currency = $db->selectValue(
                    'SELECT currency FROM registrar WHERE id = ?',
                    [$_SESSION['auth_registrar_id']]
                ) ?? 'EUR'; // Default to EUR if no result
            }
        } else {
            $registrar = null;
            $currency = $_SESSION['_currency'] ?? 'EUR';
        }
        $registry_currency = $_SESSION['registry_currency'] ?? 'EUR';

        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = strtolower(trim($args));

            if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
            
            $domain = $db->selectRow('SELECT id, name, registrant, crdate, exdate, lastupdate, clid, idnlang, rgpstatus FROM domain WHERE name = ?',
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
                
                $domainStatus = $db->select('SELECT status FROM domain_status WHERE domain_id = ?',
                [ $domain['id'] ]);

                $expirationDate = new \DateTime($domain['exdate']);
                $currentYear = (int)date("Y");
                $expirationYear = (int)$expirationDate->format("Y");
                $yearsUntilExpiration = $expirationYear - $currentYear;
                $maxYears = 10 - $yearsUntilExpiration;
                
                $locale = (isset($_SESSION['_lang']) && !empty($_SESSION['_lang'])) ? $_SESSION['_lang'] : 'en_US';

                $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
                $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currency);

                $symbol = $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
                $pattern = $formatter->getPattern();

                // Determine currency position (before or after)
                $position = (strpos($pattern, '¤') < strpos($pattern, '#')) ? 'before' : 'after';

                if (strpos($domain['name'], 'xn--') === 0) {
                    $domain['punycode'] = $domain['name'];
                    $domain['name'] = idn_to_utf8($domain['name'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                } else {
                    $domain['punycode'] = $domain['name'];
                }
                $_SESSION['domains_to_renew'] = [$domain['punycode']];

                return view($response,'admin/domains/renewDomain.twig', [
                    'domain' => $domain,
                    'domainStatus' => $domainStatus,
                    'registrar' => $registrars,
                    'maxYears' => $maxYears,
                    'currentUri' => $uri,
                    'currencySymbol' => $symbol,
                    'currencyPosition' => $position,
                    'currency' => $currency,
                    'registry_currency' => $registry_currency
               ]);
            } else {
                // Domain does not exist, redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

        } else {
            // Redirect to the domains view
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }
    }
    
    public function deleteDomain(Request $request, Response $response, $args)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

       // if ($request->getMethod() === 'POST') {
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();
        
            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }

                $db->beginTransaction();
                try {
                $domain = $db->selectRow('SELECT id, name, tldid, registrant, crdate, exdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, rgpstatus, addPeriod AS "addPeriod", autoRenewPeriod AS "autoRenewPeriod", renewPeriod AS "renewPeriod", renewedDate AS "renewedDate", transferPeriod AS "transferPeriod" FROM domain WHERE name = ? LIMIT 1 FOR UPDATE',
                [ $args ]);

                if (!$domain) {
                    throw new \RuntimeException('Domain does not exist');
                }
            
                $domainName = $domain['name'];
                $domain_id = $domain['id'];
                $tldid = $domain['tldid'];
                $registrant = $domain['registrant'];
                $crdate = $domain['crdate'];
                $exdate = $domain['exdate'];
                $registrar_id_domain = $domain['clid'];
                $crid = $domain['crid'];
                $upid = $domain['upid'];
                $trdate = $domain['trdate'];
                $trstatus = $domain['trstatus'];
                $reid = $domain['reid'];
                $redate = $domain['redate'];
                $acid = $domain['acid'];
                $acdate = $domain['acdate'];
                $rgpstatus = $domain['rgpstatus'];
                $addPeriod = $domain['addPeriod'];
                $autoRenewPeriod = $domain['autoRenewPeriod'];
                $renewPeriod = $domain['renewPeriod'];
                $renewedDate = $domain['renewedDate'];
                $transferPeriod = $domain['transferPeriod'];

                $parts = extractDomainAndTLD($domainName, $db);
                $label = $parts['domain'];
                $domain_extension = '.' . strtoupper($parts['tld']);

                $tld_id = $db->selectValue(
                    "SELECT id FROM domain_tld WHERE UPPER(tld) = ?",
                    [$domain_extension]
                );

                if ($_SESSION["auth_roles"] != 0) {
                    $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                    if ($registrar_id_domain != $clid) {
                        return $response->withHeader('Location', '/domains')->withStatus(302);
                    }
                } else {
                    $clid = $registrar_id_domain;
                }

                $results = $db->select(
                    'SELECT status FROM domain_status WHERE domain_id = ? FOR UPDATE',
                    [ $domain_id ]
                ) ?? [];

                foreach ($results as $row) {
                    $status = $row['status'];
                    if (preg_match('/.*(UpdateProhibited|DeleteProhibited)$/', $status) || preg_match('/^pending/', $status)) {
                        $this->container->get('flash')->addMessage('error', 'It has a status that does not allow deletion, first change the status');
                        return $response->withHeader('Location', '/domains')->withStatus(302);
                    }
                }

                $grace_period = 30;
                
                $db->delete(
                    'domain_status',
                    [
                        'domain_id' => $domain_id
                    ]
                );

                $deleteTime = (new \DateTime("+$grace_period days"))->format('Y-m-d H:i:s.v');
                $db->exec(
                    'UPDATE domain SET rgpstatus = ?, delTime = ? WHERE id = ?',
                    ['redemptionPeriod', $deleteTime, $domain_id]
                );

                $db->insert(
                    'domain_status',
                    [
                        'domain_id' => $domain_id,
                        'status' => 'pendingDelete'
                    ]
                );

                $isImmediateDeletion = false;
                if ($rgpstatus) {
                    if ($rgpstatus === 'addPeriod') {
                        $graceEnd = (new \DateTime($crdate))->modify('+5 days');
                        $addPeriod_id = new \DateTime() < $graceEnd ? $domain_id : false;
                        if ($addPeriod_id) {
                            $currency = $db->selectValue('SELECT currency FROM registrar WHERE id = ?', [$clid]);
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $addPeriod, 'create', $clid, $currency);
                            $price = $returnValue['price'];
            
                            if (!isset($price) || ($returnValue['type'] ?? 'not_found') === 'not_found') {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }
                            
                            if (!creditRegistrarBalance($db, (int)$clid, $price)) {
                                throw new \RuntimeException('Registrar account does not exist');
                            }
                                
                                $description = "domain name is deleted by the registrar during grace addPeriod, the registry provides a credit for the cost of the registration domain $domainName for period $addPeriod MONTH";
                                $db->exec(
                                    'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                    [$clid, $description, $price]
                                );
                                
                                $hostIds = $db->select(
                                    'SELECT id FROM host WHERE domain_id = ?',
                                    [$domain_id]
                                );

                                if (is_array($hostIds) && !empty($hostIds)) {
                                    foreach ($hostIds as $host) {
                                        $host_id = $host['id'];

                                        // Delete operations
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
                                            'domain_host_map',
                                            [
                                                'host_id' => $host_id
                                            ]
                                        );
                                    }
                                }

                                // Delete domain related records
                                $db->delete(
                                    'domain_contact_map',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'domain_host_map',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    envi('DB_DRIVER') === 'pgsql' ? 'domain_authinfo' : 'domain_authInfo',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'domain_status',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'host',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'secdns',
                                    [
                                        'domain_id' => $domain_id
                                    ]
                                );
                                $db->delete(
                                    'domain',
                                    [
                                        'id' => $domain_id
                                    ]
                                );
                                
                                $db->exec(envi('DB_DRIVER') === 'pgsql'
                                    ? 'INSERT INTO statistics (date) VALUES(CURRENT_DATE) ON CONFLICT (date) DO NOTHING'
                                    : 'INSERT IGNORE INTO statistics (date) VALUES(CURRENT_DATE)');

                                $db->exec(
                                    'UPDATE statistics SET deleted_domains = deleted_domains + 1 WHERE date = CURRENT_DATE'
                                );
                            
                            $isImmediateDeletion = true;
                        }
                    } elseif ($rgpstatus === 'autoRenewPeriod') {
                        $graceEnd = (new \DateTime($renewedDate))->modify('+45 days');
                        $autoRenewPeriod_id = new \DateTime() < $graceEnd ? $domain_id : false;
                        if ($autoRenewPeriod_id) {
                            $currency = $db->selectValue('SELECT currency FROM registrar WHERE id = ?', [$clid]);
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $autoRenewPeriod, 'renew', $clid, $currency);
                            $price = $returnValue['price'];
                            
                            if (!isset($price) || ($returnValue['type'] ?? 'not_found') === 'not_found') {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }

                            if (!creditRegistrarBalance($db, (int)$clid, $price)) {
                                throw new \RuntimeException('Registrar account does not exist');
                            }
                            
                            $description = "domain name is deleted by the registrar during grace autoRenewPeriod, the registry provides a credit for the cost of the renewal domain $domainName for period $autoRenewPeriod MONTH";
                            $db->exec(
                                'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                [$clid, $description, $price]
                            );
                        }
                    } elseif ($rgpstatus === 'renewPeriod') {
                        $graceEnd = (new \DateTime($renewedDate))->modify('+5 days');
                        $renewPeriod_id = new \DateTime() < $graceEnd ? $domain_id : false;
                        if ($renewPeriod_id) {
                            $currency = $db->selectValue('SELECT currency FROM registrar WHERE id = ?', [$clid]);
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $renewPeriod, 'renew', $clid, $currency);
                            $price = $returnValue['price'];

                            if (!isset($price) || ($returnValue['type'] ?? 'not_found') === 'not_found') {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }

                            if (!creditRegistrarBalance($db, (int)$clid, $price)) {
                                throw new \RuntimeException('Registrar account does not exist');
                            }
                            
                            $description = "domain name is deleted by the registrar during grace renewPeriod, the registry provides a credit for the cost of the renewal domain $domainName for period $renewPeriod MONTH";
                            $db->exec(
                                'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                [$clid, $description, $price]
                            );
                        }
                    } elseif ($rgpstatus === 'transferPeriod') {
                        $graceEnd = (new \DateTime($trdate))->modify('+5 days');
                        $transferPeriod_id = new \DateTime() < $graceEnd ? $domain_id : false;
                        if ($transferPeriod_id) {
                            $currency = $db->selectValue('SELECT currency FROM registrar WHERE id = ?', [$clid]);
                            $returnValue = getDomainPrice($db, $domainName, $tld_id, $transferPeriod, 'renew', $clid, $currency);
                            $price = $returnValue['price'];
                            
                            if (!isset($price) || ($returnValue['type'] ?? 'not_found') === 'not_found') {
                                $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                                return $response->withHeader('Location', '/domains')->withStatus(302);
                            }

                            if (!creditRegistrarBalance($db, (int)$clid, $price)) {
                                throw new \RuntimeException('Registrar account does not exist');
                            }
                            
                            $description = "domain name is deleted by the registrar during grace transferPeriod, the registry provides a credit for the cost of the transfer domain $domainName for period $transferPeriod MONTH";
                            $db->exec(
                                'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES(?, CURRENT_TIMESTAMP(3), ?, ?)',
                                [$clid, $description, $price]
                            );
                        }
                    }
                }
                    
                $db->commit();

                if ($isImmediateDeletion) {
                    $this->container->get('flash')->addMessage('success', 'Domain ' . $domainName . ' deleted successfully');
                } else {
                    $this->container->get('flash')->addMessage('info', 'Deletion process for domain ' . $domainName . ' has been initiated');
                }
                return $response->withHeader('Location', '/domains')->withStatus(302);
                } catch (\Throwable $e) {
                    if ($db->isTransactionActive()) {
                        $db->rollBack();
                    }
                    $this->container->get('flash')->addMessage('error', 'Database failure during deletion: ' . $e->getMessage());
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                } finally {
                    if ($db->isTransactionActive()) {
                        $db->rollBack();
                    }
                }
            } else {
                // Redirect to the domains view
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
        
        //}
    }

    public function listTransfers(Request $request, Response $response)
    {
        $db = $this->container->get('db');
        $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

        if ($_SESSION["auth_roles"] != 0) {
            $clid = $result['registrar_id'];
        } else {
            $clid = 0;
        }

        return view($response,'admin/domains/listTransfers.twig', [
            'clid' => base64_encode($clid)
        ]);
    }
    
    public function requestTransfer(Request $request, Response $response)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $secureAuthInfoTransfer = isSecureAuthInfoTransferEnabled($db);
            $domainName = $data['domainName'] ?? null;
            // Convert to Punycode if the domain is not in ASCII
            if (!mb_detect_encoding($domainName, 'ASCII', true)) {
                $convertedDomain = idn_to_ascii($domainName, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                if ($convertedDomain === false) {
                    $this->container->get('flash')->addMessage('error', 'Domain conversion to Punycode failed');
                    return $response->withHeader('Location', '/transfers')->withStatus(302);
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
            $authInfo = isset($data['authInfo']) && is_string($data['authInfo']) ? $data['authInfo'] : null;
            $transferYears = $data['transferYears'] ?? null;

            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $domain = $db->selectRow('SELECT id, tldid, clid, crdate, trdate, exdate FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);
            
            $domain_id = $domain['id'];
            $tldid = $domain['tldid'];
            $registrar_id_domain = $domain['clid'];
            $token = $data['token'] ?? null;
            
            if (!$domain_id) {
                $this->container->get('flash')->addMessage('error', 'Domain does not exist in registry');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $registrar_id;
            }
            
            $days_from_registration = (new \DateTime($domain['crdate']))->diff(new \DateTime())->days;
            if ($days_from_registration < 60) {
                $this->container->get('flash')->addMessage('error', 'The domain name must not be within 60 days of its initial registration');
                return $response->withHeader('Location', '/transfer/request')->withStatus(302);
            }
            
            $last_trdate = $domain['trdate'];
            $days_from_last_transfer = $last_trdate ? (new \DateTime($last_trdate))->diff(new \DateTime())->days : null;        
            if ($last_trdate && $days_from_last_transfer < 60) {
                $this->container->get('flash')->addMessage('error', 'The domain name must not be within 60 days of its last transfer from another registrar');
                return $response->withHeader('Location', '/transfer/request')->withStatus(302);
            }

            $expiryDate = new \DateTime($domain['exdate']);
            $days_from_expiry_date = $expiryDate < new \DateTime() ? $expiryDate->diff(new \DateTime())->days : -1;           
            if ($days_from_expiry_date > 30) {
                $this->container->get('flash')->addMessage('error', 'The domain name must not be more than 30 days past its expiry date');
                return $response->withHeader('Location', '/transfer/request')->withStatus(302);
            }

            if ($secureAuthInfoTransfer) {
                $domain_authinfo_id = authInfoMatches($db, 'domain', (int)$domain_id, $authInfo);
            } else {
                $domain_authinfo_id = $db->selectValue(
                     'SELECT id FROM domain_authInfo WHERE domain_id = ? AND authtype = \'pw\' AND authinfo = ? LIMIT 1',
                    [
                        $domain_id, $authInfo
                    ]
                );
            }
            
            if (!$domain_authinfo_id) {
                $this->container->get('flash')->addMessage('error', 'auth Info pw is not correct');
                return $response->withHeader('Location', '/transfer/request')->withStatus(302);
            }
            
            $results = $db->select(
                'SELECT status FROM domain_status WHERE domain_id = ?',
                [ $domain_id ]
            ) ?? [];

            foreach ($results as $row) {
                $status = $row['status'];
                if (preg_match('/.*(TransferProhibited)$/', $status) || preg_match('/^pending/', $status)) {
                    $this->container->get('flash')->addMessage('error', 'It has a status that does not allow the transfer');
                    return $response->withHeader('Location', '/transfer/request')->withStatus(302);
                }
            }

            if ($clid == $registrar_id_domain) {
                $this->container->get('flash')->addMessage('error', 'Destination client of the transfer operation is the domain sponsoring client');
                return $response->withHeader('Location', '/transfer/request')->withStatus(302);
            }
            
            if ($token !== null && $token !== '') {
                $allocation_token = $db->selectValue('SELECT token FROM allocation_tokens WHERE domain_name = ? AND token = ?',[$domainName,$token]);
                                
                if (!$allocation_token) {
                    $this->container->get('flash')->addMessage('error', 'Domain ' . $domainName . ' can not be transferred: Allocation Token mismatch');
                    return $response->withHeader('Location', '/transfer/request')->withStatus(302);
                }
            }
            
            $domain = $db->selectRow('SELECT id, registrant, crdate, exdate, lastupdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);
            
            $registrant = $domain['registrant'];
            $crdate = $domain['crdate'];
            $exdate = $domain['exdate'];
            $update = $domain['lastupdate'];
            $crid = $domain['crid'];
            $upid = $domain['upid'];
            $trdate = $domain['trdate'];
            $trstatus = $domain['trstatus'];
            $reid = $domain['reid'];
            $redate = $domain['redate'];
            $acid = $domain['acid'];
            $acdate = $domain['acdate'];
            
            if (!$trstatus || $trstatus !== 'pending') {
                
                if (!$transferYears) {
                    $this->container->get('flash')->addMessage('error', 'Please provide a year with the domain transfer');
                    return $response->withHeader('Location', '/transfer/request')->withStatus(302);
                }
                
                $date_add = 0;
                $date_add = $transferYears * 12;

                if ($date_add > 0) {
                    $result = $db->selectRow('SELECT accountBalance AS "accountBalance", creditLimit AS "creditLimit", currency FROM registrar WHERE id = ?', [$clid]);
                    $registrar_balance = $result['accountBalance'];
                    $creditLimit = $result['creditLimit'];
                    $currency = $result['currency'];
                    
                    $returnValue = getDomainPrice($db, $domainName, $tldid, $date_add, 'transfer', $clid, $currency);
                    $price = $returnValue['price'] ?? null;

                    if (!isset($price) || ($returnValue['type'] ?? 'not_found') === 'not_found') {
                        $this->container->get('flash')->addMessage('error', 'The price, period and currency for such TLD are not declared');
                        return $response->withHeader('Location', '/transfer/request')->withStatus(302);
                    }

                    if (($registrar_balance + $creditLimit) < $price) {
                        $this->container->get('flash')->addMessage('error', 'The registrar who wants to take over this domain has no money');
                        return $response->withHeader('Location', '/transfer/request')->withStatus(302);
                    }

                    try {
                        $db->beginTransaction();
                        
                        $waiting_period = 5;
                        $acdate = (new \DateTime("+$waiting_period days"))->format('Y-m-d H:i:s.v');
                        $transferExdate = (new \DateTime($domain['exdate']))->modify("+$date_add months")->format('Y-m-d H:i:s.v');
                        $db->exec(
                            'UPDATE domain SET trstatus = ?, reid = ?, redate = CURRENT_TIMESTAMP(3), acid = ?, acdate = ?, transfer_exdate = ? WHERE id = ?',
                            ['pending', $clid, $registrar_id_domain, $acdate, $transferExdate, $domain_id]
                        );

                        $existingStatus = $db->selectValue(
                            'SELECT status FROM domain_status WHERE domain_id = ? AND status = ? LIMIT 1',
                            [$domain_id, 'ok']
                        );

                        if ($existingStatus === 'ok') {
                            $db->delete(
                                'domain_status',
                                [
                                    'domain_id' => $domain_id,
                                    'status' => 'ok'
                                ]
                            );
                        }

                        $db->insert(
                            'domain_status',
                            [
                                'domain_id' => $domain_id,
                                'status' => 'pendingTransfer'
                            ]
                        );

                        $result = $db->selectRow('SELECT id, registrant, crdate, exdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = ? LIMIT 1',
                        [ $domainName ]);
                        
                        list($domain_id, $registrant, $crdate, $exdate, $registrar_id_domain, $crid, $upid, $trdate, $trstatus, $reid, $redate, $acid, $acdate, $transfer_exdate) = array_values($result);

                        $reid_identifier = $db->selectValue(
                            'SELECT clid FROM registrar WHERE id = ? LIMIT 1',
                            [$reid]
                        );
                        
                        $acid_identifier = $db->selectValue(
                            'SELECT clid FROM registrar WHERE id = ? LIMIT 1',
                            [$acid]
                        );
                        
                        $currentDateTime = new \DateTime();
                        $qdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
                        
                        // The current sponsoring registrar will receive a notification of a pending transfer
                        $db->insert('poll', [
                            'registrar_id' => $registrar_id_domain,
                            'qdate' => $qdate,
                            'msg' => 'Transfer requested.',
                            'msg_type' => 'domainTransfer',
                            'obj_name_or_id' => $domainName,
                            'obj_trstatus' => 'pending',
                            'obj_reid' => $reid_identifier,
                            'obj_redate' => $redate,
                            'obj_acid' => $acid_identifier,
                            'obj_acdate' => $acdate,
                            'obj_exdate' => $transfer_exdate
                        ]);
                    
                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                        return $response->withHeader('Location', '/transfer/request/')->withStatus(302);
                    }
                                      
                    $this->container->get('flash')->addMessage('info', 'Transfer for ' . $domainName . ' has been started successfully on ' . $qdate . ' An action is pending');
                    return $response->withHeader('Location', '/transfers')->withStatus(302);
                } else {
                    try {
                        $db->beginTransaction();
                        
                        $waiting_period = 5;
                        $acdate = (new \DateTime("+$waiting_period days"))->format('Y-m-d H:i:s.v');
                        $db->exec(
                            'UPDATE domain SET trstatus = ?, reid = ?, redate = CURRENT_TIMESTAMP(3), acid = ?, acdate = ?, transfer_exdate = NULL WHERE id = ?',
                            ['pending', $clid, $registrar_id_domain, $acdate, $domain_id]
                        );

                        $existingStatus = $db->selectValue(
                            'SELECT status FROM domain_status WHERE domain_id = ? AND status = ? LIMIT 1',
                            [$domain_id, 'ok']
                        );

                        if ($existingStatus === 'ok') {
                            $db->delete(
                                'domain_status',
                                [
                                    'domain_id' => $domain_id,
                                    'status' => 'ok'
                                ]
                            );
                        }

                        $db->insert(
                            'domain_status',
                            [
                                'domain_id' => $domain_id,
                                'status' => 'pendingTransfer'
                            ]
                        );

                        $result = $db->selectRow('SELECT id, registrant, crdate, exdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, transfer_exdate FROM domain WHERE name = ? LIMIT 1',
                        [ $domainName ]);
                        
                        list($domain_id, $registrant, $crdate, $exdate, $registrar_id_domain, $crid, $upid, $trdate, $trstatus, $reid, $redate, $acid, $acdate, $transfer_exdate) = array_values($result);

                        $reid_identifier = $db->selectValue(
                            'SELECT clid FROM registrar WHERE id = ? LIMIT 1',
                            [$reid]
                        );
                        
                        $acid_identifier = $db->selectValue(
                            'SELECT clid FROM registrar WHERE id = ? LIMIT 1',
                            [$acid]
                        );
                        
                        $currentDateTime = new \DateTime();
                        $qdate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp

                        // The current sponsoring registrar will receive a notification of a pending transfer
                        $db->insert('poll', [
                            'registrar_id' => $registrar_id_domain,
                            'qdate' => $qdate,
                            'msg' => 'Transfer requested.',
                            'msg_type' => 'domainTransfer',
                            'obj_name_or_id' => $domainName,
                            'obj_trstatus' => 'pending',
                            'obj_reid' => $reid_identifier,
                            'obj_redate' => $redate,
                            'obj_acid' => $acid_identifier,
                            'obj_acdate' => $acdate,
                            'obj_exdate' => $transfer_exdate
                        ]);
                    
                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                        return $response->withHeader('Location', '/transfer/request/')->withStatus(302);
                    }
                                      
                    $this->container->get('flash')->addMessage('info', 'Transfer for ' . $domainName . ' has been started successfully on ' . $qdate . ' An action is pending');
                    return $response->withHeader('Location', '/transfers')->withStatus(302);
                }
            } elseif ($trstatus === 'pending') {
                $this->container->get('flash')->addMessage('error', 'Command failed as the domain is pending transfer');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
        }
        
        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        if ($_SESSION["auth_roles"] != 0) {
            $registrar = true;
            $currency = $_SESSION['_currency'] ?? 'EUR';
            if (!empty($_SESSION['auth_registrar_id'])) {
                $currency = $db->selectValue(
                    'SELECT currency FROM registrar WHERE id = ?',
                    [$_SESSION['auth_registrar_id']]
                ) ?? 'EUR'; // Default to EUR if no result
            }
        } else {
            $registrar = null;
            $currency = $_SESSION['_currency'] ?? 'EUR';
        }
        $registry_currency = $_SESSION['registry_currency'] ?? 'EUR';

        $locale = (isset($_SESSION['_lang']) && !empty($_SESSION['_lang'])) ? $_SESSION['_lang'] : 'en_US';

        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currency);

        $symbol = $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
        $pattern = $formatter->getPattern();

        // Determine currency position (before or after)
        $position = (strpos($pattern, '¤') < strpos($pattern, '#')) ? 'before' : 'after';

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/domains/requestTransfer.twig', [
            'registrars' => $registrars,
            'registrar' => $registrar,
            'currencySymbol' => $symbol,
            'currencyPosition' => $position,
            'currency' => $currency,
            'registry_currency' => $registry_currency
        ]);
    }
    
    public function approveTransfer(Request $request, Response $response, $args)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

       //if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $secureAuthInfoTransfer = isSecureAuthInfoTransferEnabled($db);

            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $domainName = $args ?? null;
            }

            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $domain = $db->selectRow('SELECT id, tldid, clid FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);
            
            $domain_id = $domain['id'];
            $tldid = $domain['tldid'];
            $registrar_id_domain = $domain['clid'];
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
            }

            if ($clid !== $registrar_id_domain) {
                $this->container->get('flash')->addMessage('error', 'Only LOSING REGISTRAR can approve');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
        
            $domain = $db->selectRow('SELECT id, registrant, crdate, exdate, clid, crid, upid, trdate, trstatus, reid, redate, acid, acdate, rgpstatus, addPeriod AS "addPeriod", autoRenewPeriod AS "autoRenewPeriod", renewPeriod AS "renewPeriod", renewedDate AS "renewedDate", transferPeriod AS "transferPeriod", transfer_exdate FROM domain WHERE name = ?',
            [ $domainName ]);
            
            $domain_id = $domain['id'];
            $registrant = $domain['registrant'];
            $crdate = $domain['crdate'];
            $exdate = $domain['exdate'];
            $registrar_id_domain = $domain['clid'];
            $crid = $domain['crid'];
            $upid = $domain['upid'];
            $trdate = $domain['trdate'];
            $trstatus = $domain['trstatus'];
            $reid = $domain['reid'];
            $redate = $domain['redate'];
            $acid = $domain['acid'];
            $acdate = $domain['acdate'];
            $rgpstatus = $domain['rgpstatus'];
            $addPeriod = $domain['addPeriod'];
            $autoRenewPeriod = $domain['autoRenewPeriod'];
            $renewPeriod = $domain['renewPeriod'];
            $renewedDate = $domain['renewedDate'];
            $transferPeriod = $domain['transferPeriod'];
            $transfer_exdate = $domain['transfer_exdate'];
            
            if ($domain && $trstatus === 'pending') {
                $date_add = 0;
                $price = 0;
                
                $result = $db->selectRow('SELECT accountBalance AS "accountBalance", creditLimit AS "creditLimit", currency FROM registrar WHERE id = ?', [$reid]);
                $registrar_balance = $result['accountBalance'];
                $creditLimit = $result['creditLimit'];
                $currency = $result['currency'];
                
                if ($transfer_exdate) {
                    $transferDate = new \DateTime($transfer_exdate);
                    $expiryDate = new \DateTime($exdate);
                    $date_add = ((int) $transferDate->format('Y') - (int) $expiryDate->format('Y')) * 12
                        + (int) $transferDate->format('m') - (int) $expiryDate->format('m');
                    
                    $returnValue = getDomainPrice($db, $domainName, $tldid, $date_add, 'transfer', $reid, $currency);
                    $price = $returnValue['price'] ?? null;

                    if (!isset($price) || ($returnValue['type'] ?? 'not_found') === 'not_found') {
                        $this->container->get('flash')->addMessage('error', 'The transfer price is not configured');
                        return $response->withHeader('Location', '/transfers')->withStatus(302);
                    }
                    
                    if (($registrar_balance + $creditLimit) < $price) {
                        $this->container->get('flash')->addMessage('error', 'The registrar who took over this domain has no money to pay the renewal period that resulted from the transfer request');
                        return $response->withHeader('Location', '/transfers')->withStatus(302);
                    }
                }

                try {
                    $db->beginTransaction();

                    $lockedDomain = $db->selectRow(
                        'SELECT id, registrant, exdate, clid, reid, trstatus, transfer_exdate, tldid
                         FROM domain
                         WHERE id = ?
                         LIMIT 1
                         FOR UPDATE',
                        [$domain_id]
                    );
                    if (
                        !$lockedDomain
                        || $lockedDomain['trstatus'] !== 'pending'
                        || (int)$lockedDomain['clid'] !== (int)$clid
                    ) {
                        throw new \RuntimeException('The transfer state changed before approval');
                    }

                    $registrant = $lockedDomain['registrant'];
                    $exdate = $lockedDomain['exdate'];
                    $reid = $lockedDomain['reid'];
                    $transfer_exdate = $lockedDomain['transfer_exdate'];
                    $tldid = $lockedDomain['tldid'];

                    $account = $db->selectRow(
                        'SELECT accountBalance AS "accountBalance", creditLimit AS "creditLimit", currency
                         FROM registrar
                         WHERE id = ?
                         LIMIT 1
                         FOR UPDATE',
                        [$reid]
                    );
                    if (!$account) {
                        throw new \RuntimeException('Gaining registrar account does not exist');
                    }

                    $date_add = 0;
                    $price = 0;
                    if ($transfer_exdate) {
                        $transferDate = new \DateTime($transfer_exdate);
                        $expiryDate = new \DateTime($exdate);
                        $date_add = ((int)$transferDate->format('Y') - (int)$expiryDate->format('Y')) * 12
                            + (int)$transferDate->format('m') - (int)$expiryDate->format('m');
                        $returnValue = getDomainPrice(
                            $db,
                            $domainName,
                            $tldid,
                            $date_add,
                            'transfer',
                            $reid,
                            $account['currency']
                        );
                        $price = $returnValue['price'] ?? null;
                        if (!isset($price) || ($returnValue['type'] ?? 'not_found') === 'not_found') {
                            throw new \RuntimeException('The transfer price is not configured');
                        }
                        if (($account['accountBalance'] + $account['creditLimit']) < $price) {
                            throw new \RuntimeException('The gaining registrar has insufficient funds');
                        }
                    }

                    $contactMap = $db->select('SELECT contact_id, type FROM domain_contact_map WHERE domain_id = ?', [$domain_id]);

                    // Prepare an array to hold new contact IDs to prevent duplicating contacts
                    $newContactIds = [];
                    
                    $registrantData = $db->selectRow('SELECT * FROM contact WHERE id = ?', [$registrant]);
                    unset($registrantData['id']); // Remove the ID to ensure a new record is created
                    $registrantData['identifier'] = generateAuthInfo();
                    $registrantData['clid'] = $reid;
                    $db->insert('contact', $registrantData);
                    $newRegistrantId = $db->getlastInsertId(envi('DB_DRIVER') === 'pgsql' ? 'contact_id_seq' : null);
                    $newContactIds[$registrant] = $newRegistrantId;

                    // Fetch associated contact_postalInfo records
                    $postalInfos = $db->select('SELECT * FROM contact_postalInfo WHERE contact_id = ?', [$registrant]);

                    foreach ($postalInfos as $postalInfo) {
                        unset($postalInfo['id']); // Remove the ID to ensure a new record is created
                        $postalInfo['contact_id'] = $newRegistrantId; // Replace with new contact ID

                        // Insert new contact_postalInfo record
                        $db->insert(envi('DB_DRIVER') === 'pgsql' ? 'contact_postalinfo' : 'contact_postalInfo', $postalInfo);
                    }

                    if (!$secureAuthInfoTransfer) {
                        $new_authinfo = generateAuthInfo();
                        $db->insert(
                            envi('DB_DRIVER') === 'pgsql' ? 'contact_authinfo' : 'contact_authInfo',
                            [
                                'contact_id' => $newRegistrantId,
                                'authtype' => 'pw',
                                'authinfo' => $new_authinfo
                            ]
                        );
                    }

                    $db->insert(
                        'contact_status',
                        [
                            'contact_id' => $newRegistrantId,
                            'status' => 'ok'
                        ]
                    );
                    
                    foreach ($contactMap as $contact) {
                        if (!array_key_exists($contact['contact_id'], $newContactIds)) { // Check if not already copied
                            $contactData = $db->selectRow('SELECT * FROM contact WHERE id = ?', [$contact['contact_id']]);
                            unset($contactData['id']); // Remove the ID to ensure a new record is created
                            $contactData['identifier'] = generateAuthInfo();
                            $contactData['clid'] = $reid;
                            $db->insert('contact', $contactData);
                            $newContactId = $db->getlastInsertId(envi('DB_DRIVER') === 'pgsql' ? 'contact_id_seq' : null);
                            $newContactIds[$contact['contact_id']] = $newContactId;

                            // Fetch and copy associated contact_postalInfo records
                            $postalInfos = $db->select('SELECT * FROM contact_postalInfo WHERE contact_id = ?', [$contact['contact_id']]);
                            foreach ($postalInfos as $postalInfo) {
                                unset($postalInfo['id']); // Ensure a new record is created
                                $postalInfo['contact_id'] = $newContactId; // Assign to new contact ID

                                // Insert new contact_postalInfo record
                                $db->insert(envi('DB_DRIVER') === 'pgsql' ? 'contact_postalinfo' : 'contact_postalInfo', $postalInfo);
                            }

                            if (!$secureAuthInfoTransfer) {
                                $new_authinfo = generateAuthInfo();
                                $db->insert(
                                    envi('DB_DRIVER') === 'pgsql' ? 'contact_authinfo' : 'contact_authInfo',
                                    [
                                        'contact_id' => $newContactId,
                                        'authtype' => 'pw',
                                        'authinfo' => $new_authinfo
                                    ]
                                );
                            }

                            $db->insert(
                                'contact_status',
                                [
                                    'contact_id' => $newContactId,
                                    'status' => 'ok'
                                ]
                            );
                        }
                    }
                    
                    $from = $exdate;

                    $newExdate = (new \DateTime($from))->modify("+$date_add months")->format('Y-m-d H:i:s.v');
                    $db->exec(
                        'UPDATE domain SET exdate = ?, lastupdate = CURRENT_TIMESTAMP(3), clid = ?, upid = ?, registrant = ?, trdate = CURRENT_TIMESTAMP(3), trstatus = ?, acdate = CURRENT_TIMESTAMP(3), transfer_exdate = NULL, rgpstatus = ?, transferPeriod = ? WHERE id = ?',
                        [$newExdate, $reid, $clid, $newRegistrantId, 'clientApproved', 'transferPeriod', $date_add, $domain_id]
                    );

                    if ($secureAuthInfoTransfer) {
                        storeAuthInfo($db, 'domain', (int)$domain_id, null);
                    } else {
                        $new_authinfo = generateAuthInfo();
                        $db->exec(
                            'UPDATE domain_authInfo SET authinfo = ? WHERE domain_id = ?',
                            [$new_authinfo, $domain_id]
                        );
                    }

                    $existingStatus = $db->selectValue(
                        'SELECT status FROM domain_status WHERE domain_id = ? AND status = ? LIMIT 1',
                        [$domain_id, 'pendingTransfer']
                    );

                    if ($existingStatus === 'pendingTransfer') {
                        $db->delete(
                            'domain_status',
                            [
                                'domain_id' => $domain_id,
                                'status' => 'pendingTransfer'
                            ]
                        );
                    }

                    $db->insert(
                        'domain_status',
                        [
                            'domain_id' => $domain_id,
                            'status' => 'ok'
                        ]
                    );

                    foreach ($contactMap as $contact) {
                        $db->update('domain_contact_map', [
                            'contact_id' => $newContactIds[$contact['contact_id']],
                        ], [
                            'domain_id' => $domain_id,
                            'type' => $contact['type'],
                            'contact_id' => $contact['contact_id'] // Ensure we're updating the correct existing record
                        ]);
                    }

                    $db->exec(
                        'UPDATE host SET clid = ?, upid = ?, lastupdate = CURRENT_TIMESTAMP(3), trdate = CURRENT_TIMESTAMP(3) WHERE domain_id = ?',
                        [$reid, $clid, $domain_id]
                    );

                    if (!debitRegistrarBalance($db, (int)$reid, $price)) {
                        throw new \RuntimeException('The gaining registrar has insufficient funds');
                    }
                    
                    $db->exec(
                        'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                        [$reid, "transfer domain $domainName for period $date_add MONTH", "-$price"]
                    );

                    $row = $db->selectRow(
                        'SELECT exdate FROM domain WHERE name = ? LIMIT 1',
                        [$domainName]
                    );
                    $to = $row['exdate'];

                    $currentDateTime = new \DateTime();
                    $stdate = $currentDateTime->format('Y-m-d H:i:s.v');
                    $db->insert(
                        'statement',
                        [
                            'registrar_id' => $reid,
                            'date' => $stdate,
                            'command' => 'transfer',
                            'domain_name' => $domainName,
                            'length_in_months' => $date_add,
                            'fromS' => $from,
                            'toS' => $to,
                            'amount' => $price
                        ]
                      );

                    $db->exec(envi('DB_DRIVER') === 'pgsql'
                        ? 'INSERT INTO statistics (date) VALUES(CURRENT_DATE) ON CONFLICT (date) DO NOTHING'
                        : 'INSERT IGNORE INTO statistics (date) VALUES(CURRENT_DATE)');

                    $db->exec(
                        'UPDATE statistics SET transfered_domains = transfered_domains + 1 WHERE date = CURRENT_DATE'
                    );

                    $stmt_log = $db->exec(
                        'INSERT INTO error_log (channel, level, level_name, message, context, extra) VALUES (?, ?, ?, ?, ?, ?)',
                        [
                            'manual_transfer',
                            250,
                            'NOTICE',
                            "Manual domain transfer approved: $domainName (New registrant: $newRegistrantId, Registrar: $reid)",
                            json_encode([
                                'domain_id' => $domain_id,
                                'new_registrant' => $newRegistrantId,
                                'registrar' => $reid,
                                'performed_by' => $clid
                            ]),
                            json_encode([
                                'received_on' => date('Y-m-d H:i:s'),
                                'read_on' => null,
                                'is_read' => false,
                                'message_type' => 'manual_transfer_approval'
                            ])
                        ]
                    );

                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->isTransactionActive()) {
                        $db->rollBack();
                    }
                    $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                    return $response->withHeader('Location', '/transfers')->withStatus(302);
                }

                $this->container->get('flash')->addMessage('success', 'Transfer for ' . $domainName . ' has been completed');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'The domain is NOT pending transfer');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
        //}
    }
    
    public function rejectTransfer(Request $request, Response $response, $args)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        //if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            
            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $domainName = $args ?? null;
            }

            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $domain = $db->selectRow('SELECT id, tldid, clid FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);
            
            $domain_id = $domain['id'];
            $tldid = $domain['tldid'];
            $registrar_id_domain = $domain['clid'];
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
            }

            if ($clid !== $registrar_id_domain) {
                $this->container->get('flash')->addMessage('error', 'Only LOSING REGISTRAR can reject');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
          
            $domain = $db->selectRow('SELECT id, trstatus FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);

            $trstatus = $domain['trstatus'];
            
            if ($trstatus === 'pending') {
                $db->update('domain', [
                    'trstatus' => 'clientRejected'
                ],
                [
                    'name' => $domainName
                ]
                );
                
                $existingStatus = $db->selectValue(
                    'SELECT status FROM domain_status WHERE domain_id = ? AND status = ? LIMIT 1',
                    [$domain_id, 'pendingTransfer']
                );

                if ($existingStatus === 'pendingTransfer') {
                    $db->delete(
                        'domain_status',
                        [
                            'domain_id' => $domain_id,
                            'status' => 'pendingTransfer'
                        ]
                    );
                }

                $db->insert(
                    'domain_status',
                    [
                        'domain_id' => $domain_id,
                        'status' => 'ok'
                    ]
                );

                $db->exec(
                    'INSERT INTO error_log (channel, level, level_name, message, context, extra) VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        'manual_transfer',
                        250, // NOTICE level
                        'NOTICE',
                        "Manual domain transfer rejected: $domainName (Losing Registrar: $clid)",
                        json_encode([
                            'domain_id' => $domain_id,
                            'losing_registrar' => $clid,
                            'status' => 'clientRejected',
                            'performed_by' => $clid
                        ]),
                        json_encode([
                            'received_on' => date('Y-m-d H:i:s'),
                            'read_on' => null,
                            'is_read' => false,
                            'message_type' => 'manual_transfer_rejection'
                        ])
                    ]
                );

                $this->container->get('flash')->addMessage('success', 'Transfer for ' . $domainName . ' has been rejected successfully');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'The domain is NOT pending transfer');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
        //}
    }
    
    public function cancelTransfer(Request $request, Response $response, $args)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        //if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $db = $this->container->get('db');

            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $domainName = $args ?? null;
            }

            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
            
            $domain = $db->selectRow('SELECT id, tldid, clid FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);
            
            $domain_id = $domain['id'];
            $tldid = $domain['tldid'];
            $registrar_id_domain = $domain['clid'];
            
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $result['registrar_id'];
            } else {
                $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
            }

            if ($clid === $registrar_id_domain) {
                $this->container->get('flash')->addMessage('error', 'Only the APPLICANT can cancel');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
         
            $domain = $db->selectRow('SELECT id, trstatus FROM domain WHERE name = ? LIMIT 1',
            [ $domainName ]);

            $trstatus = $domain['trstatus'];
            
            if ($trstatus === 'pending') {
                $db->update('domain', [
                    'trstatus' => 'clientCancelled'
                ],
                [
                    'name' => $domainName
                ]
                );

                $existingStatus = $db->selectValue(
                    'SELECT status FROM domain_status WHERE domain_id = ? AND status = ? LIMIT 1',
                    [$domain_id, 'pendingTransfer']
                );

                if ($existingStatus === 'pendingTransfer') {
                    $db->delete(
                        'domain_status',
                        [
                            'domain_id' => $domain_id,
                            'status' => 'pendingTransfer'
                        ]
                    );
                }

                $db->insert(
                    'domain_status',
                    [
                        'domain_id' => $domain_id,
                        'status' => 'ok'
                    ]
                );

                $db->exec(
                    'INSERT INTO error_log (channel, level, level_name, message, context, extra) VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        'manual_transfer',
                        250, // NOTICE level
                        'NOTICE',
                        "Manual domain transfer canceled: $domainName (Applicant: $clid)",
                        json_encode([
                            'domain_id' => $domain_id,
                            'applicant' => $clid,
                            'status' => 'clientCancelled',
                            'performed_by' => $clid
                        ]),
                        json_encode([
                            'received_on' => date('Y-m-d H:i:s'),
                            'read_on' => null,
                            'is_read' => false,
                            'message_type' => 'manual_transfer_cancellation'
                        ])
                    ]
                );

                $this->container->get('flash')->addMessage('success', 'Transfer for ' . $domainName . ' has been cancelled successfully');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'The domain is NOT pending transfer');
                return $response->withHeader('Location', '/transfers')->withStatus(302);
            }
        //}
    }
    
    public function restoreDomain(Request $request, Response $response, $args)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        //if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $db = $this->container->get('db');

            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $domainName = $args ?? null;
            }

            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                $registrar_id_domain = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
                if ($registrar_id_domain != $clid) {
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
            }
            
            $temp_id_rgpstatus = $db->selectValue(
                'SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = ? AND name = ? LIMIT 1',
                ['redemptionPeriod', $domainName]
            );

            if ($temp_id_rgpstatus == 0) {
                $this->container->get('flash')->addMessage('error', 'pendingRestore can only be done if the domain is now in redemptionPeriod rgpStatus');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
            
            $domain_id = $db->selectValue(
                'SELECT id FROM domain WHERE name = ?',
                [$domainName]
            );
            
            $temp_id_status = $db->selectValue(
                'SELECT COUNT(id) AS ids FROM domain_status WHERE status = ? AND domain_id = ? LIMIT 1',
                ['pendingDelete', $domain_id]
            );
            
            if ($temp_id_status == 0) {
                $this->container->get('flash')->addMessage('error', 'pendingRestore can only be done if the domain is now in pendingDelete status');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }

            $temp_id = $db->selectValue(
                'SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = ? AND id = ?',
                ['redemptionPeriod', $domain_id]
            );
            
            if ($temp_id == 1) {
                $currentDateTime = new \DateTime();
                $date = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
                
                $db->update('domain', [
                    'rgpstatus' => 'pendingRestore',
                    'restime' => $date,
                    'lastupdate' => $date
                ],
                [
                    'id' => $domain_id
                ]
                );
                
                $this->container->get('flash')->addMessage('info', 'Restore process for ' . $domainName . ' has started successfully');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'pendingRestore can only be done if the domain is now in redemptionPeriod');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
        //}
    }
    
    public function reportDomain(Request $request, Response $response, $args)
    {
        if (!currentUserHasAnyRole([0, 4])) {
            return $response->withHeader('Location', '/domains')->withStatus(302);
        }

        //if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $db = $this->container->get('db');

            if ($args) {
                $args = strtolower(trim($args));

                if (!preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)*[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $args)) {
                    $this->container->get('flash')->addMessage('error', 'Invalid domain name format');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $domainName = $args ?? null;
            }
            
            if (!$domainName) {
                $this->container->get('flash')->addMessage('error', 'Please provide the domain name');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
            
            if ($_SESSION["auth_roles"] != 0) {
                $clid = $db->selectValue('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
                $registrar_id_domain = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
                if ($registrar_id_domain != $clid) {
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
            }
            
            $temp_id = $db->selectValue(
                'SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = ? AND name = ? LIMIT 1',
                ['pendingRestore', $domainName]
            );
            
            if ($temp_id == 0) {
                $this->container->get('flash')->addMessage('error', 'report can only be sent if the domain is in pendingRestore status');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
            
            $temp_id = $db->selectValue(
                'SELECT COUNT(id) AS ids FROM domain WHERE rgpstatus = ? AND name = ?',
                ['pendingRestore', $domainName]
            );
            
            if ($temp_id == 1) {
                $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);

                if ($_SESSION["auth_roles"] != 0) {
                    $clid = $result['registrar_id'];
                } else {
                    $clid = $db->selectValue('SELECT clid FROM domain WHERE name = ?', [$domainName]);
                }
                
                $domain = $db->selectRow('SELECT tldid, exdate FROM domain WHERE name = ? LIMIT 1',
                [ $domainName ]);
                $tldid = $domain['tldid'];

                $result = $db->selectRow('SELECT accountBalance AS "accountBalance", creditLimit AS "creditLimit", currency FROM registrar WHERE id = ?', [$clid]);

                $registrar_balance = $result['accountBalance'];
                $creditLimit = $result['creditLimit'];
                $currency = $result['currency'];

                $returnValue = getDomainPrice($db, $domainName, $tldid, 12, 'renew', $clid, $currency);
                $renew_price = $returnValue['price'];

                $restore_price = getDomainRestorePrice($db, $tldid, $clid, $currency);

                if (($registrar_balance + $creditLimit) < ($renew_price + $restore_price)) {
                    $this->container->get('flash')->addMessage('error', 'There is no money on the account for restore and renew');
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
                
                $from = $domain['exdate'];
                
                try {
                    $db->beginTransaction();

                    $lockedDomain = $db->selectRow(
                        'SELECT id, tldid, exdate, clid, rgpstatus
                         FROM domain
                         WHERE name = ?
                         LIMIT 1
                         FOR UPDATE',
                        [$domainName]
                    );
                    if (
                        !$lockedDomain
                        || $lockedDomain['rgpstatus'] !== 'pendingRestore'
                        || (int)$lockedDomain['clid'] !== (int)$clid
                    ) {
                        throw new \RuntimeException('The domain is no longer eligible for restore');
                    }

                    $db->select(
                        'SELECT status FROM domain_status WHERE domain_id = ? FOR UPDATE',
                        [$lockedDomain['id']]
                    );

                    $account = $db->selectRow(
                        'SELECT accountBalance AS "accountBalance", creditLimit AS "creditLimit", currency
                         FROM registrar
                         WHERE id = ?
                         LIMIT 1
                         FOR UPDATE',
                        [$clid]
                    );
                    if (!$account) {
                        throw new \RuntimeException('Registrar account does not exist');
                    }

                    $returnValue = getDomainPrice(
                        $db,
                        $domainName,
                        $lockedDomain['tldid'],
                        12,
                        'renew',
                        $clid,
                        $account['currency']
                    );
                    $renew_price = $returnValue['price'] ?? null;
                    $restore_price = getDomainRestorePrice(
                        $db,
                        $lockedDomain['tldid'],
                        $clid,
                        $account['currency']
                    );
                    if (
                        !isset($renew_price)
                        || !isset($restore_price)
                        || ($returnValue['type'] ?? 'not_found') === 'not_found'
                    ) {
                        throw new \RuntimeException('Restore or renewal price is not configured');
                    }

                    $total_price = number_format((float)$renew_price + (float)$restore_price, 2, '.', '');
                    if (($account['accountBalance'] + $account['creditLimit']) < $total_price) {
                        throw new \RuntimeException('There is no money on the account for restore and renew');
                    }

                    $domain_id = $lockedDomain['id'];
                    $from = $lockedDomain['exdate'];
                    $restoredExdate = (new \DateTime($from))->modify('+12 months')->format('Y-m-d H:i:s.v');
                    $db->exec(
                        'UPDATE domain SET exdate = ?, rgpstatus = NULL, rgpresTime = CURRENT_TIMESTAMP(3), lastupdate = CURRENT_TIMESTAMP(3) WHERE id = ?',
                        [
                            $restoredExdate,
                            $domain_id
                        ]
                    );

                    $db->delete(
                        'domain_status',
                        [
                            'domain_id' => $domain_id,
                            'status' => 'pendingDelete'
                        ]
                    );

                    if (!debitRegistrarBalance($db, (int)$clid, $total_price)) {
                        throw new \RuntimeException('There is no money on the account for restore and renew');
                    }
                    
                    $db->exec(
                        'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                        [$clid, "restore domain $domainName", "-$restore_price"]
                    );
                    
                    $db->exec(
                        'INSERT INTO payment_history (registrar_id, date, description, amount) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?)',
                        [$clid, "renew domain $domainName for period 12 MONTH", "-$renew_price"]
                    );

                    $to = $restoredExdate;

                    $currentDateTime = new \DateTime();
                    $stdate = $currentDateTime->format('Y-m-d H:i:s.v');
                    $db->insert(
                        'statement',
                        [
                            'registrar_id' => $clid,
                            'date' => $stdate,
                            'command' => 'restore',
                            'domain_name' => $domainName,
                            'length_in_months' => 0,
                            'fromS' => $from,
                            'toS' => $from,
                            'amount' => $restore_price
                        ]
                      );
                      
                    $db->insert(
                        'statement',
                        [
                            'registrar_id' => $clid,
                            'date' => $stdate,
                            'command' => 'renew',
                            'domain_name' => $domainName,
                            'length_in_months' => 12,
                            'fromS' => $from,
                            'toS' => $to,
                            'amount' => $renew_price
                        ]
                      );

                    $db->exec(envi('DB_DRIVER') === 'pgsql'
                        ? 'INSERT INTO statistics (date) VALUES(CURRENT_DATE) ON CONFLICT (date) DO NOTHING'
                        : 'INSERT IGNORE INTO statistics (date) VALUES(CURRENT_DATE)');

                    $db->exec(
                        'UPDATE statistics SET restored_domains = restored_domains + 1 WHERE date = CURRENT_DATE'
                    );
                    
                    $db->exec(
                        'UPDATE statistics SET renewed_domains = renewed_domains + 1 WHERE date = CURRENT_DATE'
                    );
                     
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->isTransactionActive()) {
                        $db->rollBack();
                    }
                    $this->container->get('flash')->addMessage('error', 'Database failure during restore: ' . $e->getMessage());
                    return $response->withHeader('Location', '/domains')->withStatus(302);
                }
               
                $this->container->get('flash')->addMessage('success','Domain ' . $domainName . ' has been restored successfully');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'report can only be sent if the domain is in pendingRestore status');
                return $response->withHeader('Location', '/domains')->withStatus(302);
            }
        }

}
