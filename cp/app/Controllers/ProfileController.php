<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class ProfileController extends Controller
{
    private $webAuthn;

    public function __construct() {
        $rpName = 'Namingo';
        $rpId = envi('APP_DOMAIN');
        $this->webAuthn = new \lbuchs\WebAuthn\WebAuthn($rpName, $rpId, ['none']);
    }

    public function profile(Request $request, Response $response)
    {
        global $container;

        $username = $_SESSION['auth_username'];
        $email = $_SESSION['auth_email'];
        $userId = $_SESSION['auth_user_id'];
        $status = $_SESSION['auth_status'];

        $db = $container->get('db');
        $tfa = new \RobThree\Auth\TwoFactorAuth('Namingo');
        $secret = $tfa->createSecret();
        $qrcodeDataUri = $tfa->getQRCodeImageAsDataUri($email, $secret);
        
        if ($status == 0) {
            $status = "Confirmed";
        } else {
            $status = "Unknown";
        }
        $roles = $_SESSION['auth_roles'];
        if ($roles == 0) {
            $role = "Admin";
        } else {
            $role = "Unknown";
        }

        $csrfName = $container->get('csrf')->getTokenName();
        $csrfValue = $container->get('csrf')->getTokenValue();
        
        $_SESSION['2fa_secret'] = $secret;
        
        $is_2fa_activated = $db->selectValue(
            'SELECT tfa_enabled FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );
        $is_weba_activated = $db->select(
            'SELECT * FROM users_webauthn WHERE user_id = ?',
            [$userId]
        );
        $user_audit = $db->select(
            'SELECT * FROM users_audit WHERE user_id = ? ORDER BY event_time DESC',
            [$userId]
        );
        if ($is_2fa_activated) {
            return view($response,'admin/profile/profile.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role, 'csrf_name' => $csrfName, 'csrf_value' => $csrfValue, 'userAudit' => $user_audit]);
        } else if ($is_weba_activated) {
            return view($response,'admin/profile/profile.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role, 'qrcodeDataUri' => $qrcodeDataUri, 'secret' => $secret, 'csrf_name' => $csrfName, 'csrf_value' => $csrfValue, 'weba' => $is_weba_activated, 'userAudit' => $user_audit]);
        } else {
            return view($response,'admin/profile/profile.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role, 'qrcodeDataUri' => $qrcodeDataUri, 'secret' => $secret, 'csrf_name' => $csrfName, 'csrf_value' => $csrfValue, 'userAudit' => $user_audit]);
        }

    }
    
    public function activate2fa(Request $request, Response $response)
    {
        global $container;
        
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $container->get('db');
            $verificationCode = $data['verificationCode'] ?? null;
            $userId = $_SESSION['auth_user_id'];
            $secret = $_SESSION['2fa_secret'];

            $csrfName = $container->get('csrf')->getTokenName();
            $csrfValue = $container->get('csrf')->getTokenValue();
            $username = $_SESSION['auth_username'];
            $email = $_SESSION['auth_email'];
            $status = $_SESSION['auth_status'];

            if ($status == 0) {
                $status = "Confirmed";
            } else {
                $status = "Unknown";
            }
            $roles = $_SESSION['auth_roles'];
            if ($roles == 0) {
                $role = "Admin";
            } else {
                $role = "Unknown";
            }
            
            try {
                $currentDateTime = new \DateTime();
                $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
                $db->insert(
                    'users_audit',
                    [
                        'user_id' => $_SESSION['auth_user_id'],
                        'user_event' => 'user.enable.2fa',
                        'user_resource' => 'control.panel',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                        'user_ip' => get_client_ip(),
                        'user_location' => get_client_location(),
                        'event_time' => $currentDate,
                        'user_data' => null
                    ]
                );
                $db->update(
                    'users',
                    [
                        'tfa_secret' => $secret,
                        'tfa_enabled' => 1,
                        'auth_method' => '2fa',
                        'backup_codes' => null
                    ],
                    [
                        'id' => $userId
                    ]
                );
            } catch (Exception $e) {
                return view($response,'admin/profile/profile.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role, 'csrf_name' => $csrfName, 'csrf_value' => $csrfValue]);
            }

            return view($response,'admin/profile/profile.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role, 'csrf_name' => $csrfName, 'csrf_value' => $csrfValue]);
        }
    }
    
    public function getRegistrationChallenge(Request $request, Response $response)
    {
        $userName = $_SESSION['auth_username'];
        $userEmail = $_SESSION['auth_email'];
        $userId = $_SESSION['auth_user_id'];
        $hexUserId = dechex($userId);
        // Ensure even length for the hexadecimal string
        if(strlen($hexUserId) % 2 != 0){
            $hexUserId = '0' . $hexUserId;
        }
        $createArgs = $this->webAuthn->getCreateArgs(\hex2bin($hexUserId), $userEmail, $userName, 60*4, null, 'required', null);

        $response->getBody()->write(json_encode($createArgs));
        $_SESSION['challenge'] = $this->webAuthn->getChallenge();
        
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    public function verifyRegistration(Request $request, Response $response)
    {
        global $container;
        $data = json_decode($request->getBody()->getContents(), null, 512, JSON_THROW_ON_ERROR);
        $userName = $_SESSION['auth_username'];
        $userEmail = $_SESSION['auth_email'];
        $userId = $_SESSION['auth_user_id'];

        try {
            // Decode the incoming data
            $clientDataJSON = base64_decode($data->clientDataJSON);
            $attestationObject = base64_decode($data->attestationObject);

            // Retrieve the challenge from the session
            $challenge = $_SESSION['challenge'];

            // Process the WebAuthn response
            $credential = $this->webAuthn->processCreate($clientDataJSON, $attestationObject, $challenge, 'required', true, false);
            
            // add user infos
            $credential->userId = $userId;
            $credential->userName = $userEmail;
            $credential->userDisplayName = $userName;

            // Store the credential data in the database
            $db = $container->get('db');
            $counter = is_null($credential->signatureCounter) ? 0 : $credential->signatureCounter;
            $db->insert(
                'users_webauthn',
                [
                    'user_id' => $_SESSION['auth_user_id'],
                    'credential_id' => base64_encode($credential->credentialId),
                    'public_key' => $credential->credentialPublicKey,
                    'attestation_object' => base64_encode($attestationObject),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                    'sign_count' => $counter
                ]
            );
            
            $msg = 'registration success.';
            if ($credential->rootValid === false) {
                $msg = 'registration ok, but certificate does not match any of the selected root ca.';
            }

            $return = new \stdClass();
            $return->success = true;
            $return->msg = $msg;

            // Send success response
            $response->getBody()->write(json_encode($return));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            // Handle error, return an appropriate response
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }

}