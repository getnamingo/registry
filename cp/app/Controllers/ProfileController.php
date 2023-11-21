<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use lbuchs\WebAuthn\WebAuthn;

class ProfileController extends Controller
{
    private $webAuthn;

    public function __construct() {
        $rpName = 'Namingo';
        $rpId = envi('APP_DOMAIN');

        $this->webAuthn = new Webauthn($rpName, $rpId);

        // Additional configuration for Webauthn can go here
        // Example: setting the public key credential parameters, user verification level, etc.
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
        if ($is_2fa_activated) {
            return view($response,'admin/profile/profile.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role, 'csrf_name' => $csrfName, 'csrf_value' => $csrfValue]);
        } else {
            return view($response,'admin/profile/profile.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role, 'qrcodeDataUri' => $qrcodeDataUri, 'secret' => $secret, 'csrf_name' => $csrfName, 'csrf_value' => $csrfValue]);
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
        global $container;
        $userName = $_SESSION['auth_username'];
        $userEmail = $_SESSION['auth_email'];
        $userId = $_SESSION['auth_user_id'];
        
        // Convert the user ID to a hexadecimal string
        $userIdHex = dechex($userId);
        // Pad with a leading zero if the length is odd
        if (strlen($userIdHex) % 2 !== 0) {
            $userIdHex = '0' . $userIdHex;
        }

        // Convert the padded hexadecimal string to binary, then encode in Base64
        $userIdBin = hex2bin($userIdHex);
        $userIdBase64 = base64_encode($userIdBin);
        
        // Generate the create arguments using the WebAuthn library
        $createArgs = $this->webAuthn->getCreateArgs($userIdBase64, $userName, $userEmail, 60 * 4, 0, 'required', null);

        // Encode the challenge in Base64
        $base64Challenge = base64_encode($this->webAuthn->getChallenge());

        // Set the challenge and user ID in the createArgs object
        $createArgs->publicKey->challenge = $base64Challenge;
        $createArgs->publicKey->user->id = $userIdBase64;

        // Store the challenge in the session
        $_SESSION['webauthn_challenge'] = $base64Challenge;

        // Send the modified $createArgs to the client
        $response->getBody()->write(json_encode($createArgs));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    public function verifyRegistration(Request $request, Response $response)
    {
        global $container;
        $data = json_decode($request->getBody()->getContents());

        try {
            // Decode the incoming data
            $clientDataJSON = base64_decode($data->response->clientDataJSON);
            $attestationObject = base64_decode($data->response->attestationObject);

            // Retrieve the challenge from the session
            $challenge = $_SESSION['webauthn_challenge'];

            // Process the WebAuthn response
            $credential = $this->webAuthn->processCreate($clientDataJSON, $attestationObject, $challenge, true, true, false);

            // Store the credential data in the database
            $db = $container->get('db');
            $db->insert(
                'users_webauthn',
                [
                    'user_id' => $_SESSION['auth_user_id'],
                    'credential_id' => base64_encode($credential->credentialId), // Binary data encoded in Base64
                    'public_key' => $credential->publicKey, // Text data
                    'attestation_object' => base64_encode($credential->attestationObject), // Binary data encoded in Base64
                    'sign_count' => $credential->signCount // Integer
                ]
            );

            // Send success response
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            // Handle error, return an appropriate response
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }

}