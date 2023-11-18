<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class ProfileController extends Controller
{
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
        $user = $request->getAttribute('user'); // Assuming you have the user info
        $username = $user->getUsername(); // Replace with your method to get the username
        $userEmail = $user->getEmail(); // Replace with your method to get the user's email

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

            // Store the credential data in the database
            // $user->addWebAuthnCredential($credential);

            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            // Handle error, return an appropriate response
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }

}