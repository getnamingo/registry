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
        
        return view($response,'admin/profile/profile.twig',['email' => $email, 'username' => $username, 'status' => $status, 'role' => $role]);
    }
    
    public function getRegistrationChallenge(Request $request, Response $response)
    {
        $username = $_SESSION['auth_username'];
        $userEmail = $_SESSION['auth_email'];

        $challenge = $this->webAuthn->prepareChallengeForRegistration($username, $userEmail);
        $_SESSION['webauthn_challenge'] = $challenge; // Store the challenge in the session

        $response->getBody()->write(json_encode($challenge));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    public function verifyRegistration(Request $request, Response $response)
    {
        $data = json_decode($request->getBody()->getContents(), true);

        try {
            $credential = $this->webAuthn->processCreate($data, $_SESSION['webauthn_challenge']);
            unset($_SESSION['webauthn_challenge']);
            
            $db = $this->container->get('db');
            
            try {
                $db->insert(
                    'users_webauthn',
                    [
                        'user_id' => $_SESSION['auth_user_id'],
                        'credential_id' => $credential->getCredentialId(), // Binary data
                        'public_key' => $credential->getPublicKey(), // Text data
                        'attestation_object' => $credential->getAttestationObject(), // Binary data
                        'sign_count' => $credential->getSignCount() // Integer
                    ]
                );
            } catch (IntegrityConstraintViolationException $e) {
                // Handle the case where the insert operation violates a constraint
                // For example, a duplicate credential_id
                throw new \Exception('Could not store WebAuthn credentials: ' . $e->getMessage());
            } catch (Error $e) {
                // Handle other database errors
                throw new \Exception('Database error: ' . $e->getMessage());
            }

            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            // Handle error, return an appropriate response
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }

}