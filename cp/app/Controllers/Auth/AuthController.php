<?php

namespace App\Controllers\Auth;

use App\Auth\Auth;
use App\Controllers\Controller;
use Respect\Validation\Validator as v;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Pinga\Session;

/**
 * AuthController
 *
 * @author    Hezekiah O. <support@hezecom.com>
 */
class AuthController extends Controller
{
    private $webAuthn;

    public function __construct() {
        $rpName = 'Namingo';
        $rpId = envi('APP_DOMAIN');
        $this->webAuthn = new \lbuchs\WebAuthn\WebAuthn($rpName, $rpId, ['android-key', 'android-safetynet', 'apple', 'fido-u2f', 'packed', 'tpm']);
    }

    /**
     * @param Request $request
     * @param Response $response
     * @return mixed
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function createLogin(Request $request, Response $response){
        return view($response,'auth/login.twig');
    }
    
    /**
     * Show 2FA verification form.
     *
     * @param Request $request
     * @param Response $response
     * @return mixed
     */
    public function verify2FA(Request $request, Response $response){
        if (isset($_SESSION['is2FAEnabled']) && $_SESSION['is2FAEnabled'] === true) {
            return view($response, 'auth/verify2fa.twig');
        } else {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
    }

    /**
     * @param Request $request
     * @param Response $response
     * @throws \Pinga\Auth\AttemptCancelledException
     * @throws \Pinga\Auth\AuthError
     */
    public function login(Request $request, Response $response){
        global $container;
        $data = $request->getParsedBody();
        $db = $container->get('db');
        $is2FAEnabled = $db->selectValue('SELECT tfa_enabled FROM users WHERE email = ?', [$data['email']]);
        $isWebaEnabled = $db->selectValue('SELECT auth_method FROM users WHERE email = ?', [$data['email']]);

        if ($isWebaEnabled == 'webauthn') {
            $container->get('flash')->addMessage('error', 'WebAuthn enabled for this account');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // If 2FA is enabled and no code is provided, redirect to 2FA code entry
        if($is2FAEnabled && !isset($data['code'])) {
            $_SESSION['2fa_email'] = $data['email'];
            $_SESSION['2fa_password'] = $data['password'];
            $_SESSION['is2FAEnabled'] = true;
            return $response->withHeader('Location', '/login/verify')->withStatus(302);
        } else {
            $email = $data['email'];
            $password = $data['password'];
            $_SESSION['is2FAEnabled'] = false;
        }

        // If the 2FA code is present, this might be a 2FA verification attempt
        if (isset($data['code']) && isset($_SESSION['2fa_email']) && isset($_SESSION['2fa_password'])) {
            $email = $_SESSION['2fa_email'];
            $password = $_SESSION['2fa_password'];
            // Clear the session variables immediately after use
            unset($_SESSION['2fa_email'], $_SESSION['2fa_password'], $_SESSION['is2FAEnabled']);
        }

        $login = Auth::login($email, $password, $data['remember'] ?? null, $data['code'] ?? null);
        unset($_SESSION['2fa_email'], $_SESSION['2fa_password'], $_SESSION['is2FAEnabled']);

        if ($login===true) {
            $db = $container->get('db');
            $currentDateTime = new \DateTime();
            $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
            $db->insert(
                'users_audit',
                [
                    'user_id' => $_SESSION['auth_user_id'],
                    'user_event' => 'user.login',
                    'user_resource' => 'control.panel',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                    'user_ip' => get_client_ip(),
                    'user_location' => get_client_location(),
                    'event_time' => $currentDate,
                    'user_data' => null
                ]
            );
            redirect()->route('home');
        }
    }

    /**
     * @throws \Pinga\Auth\AuthError
     */
    public function logout()
    {
        global $container;
        $db = $container->get('db');
        $currentDateTime = new \DateTime();
        $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
        $db->insert(
            'users_audit',
            [
                'user_id' => $_SESSION['auth_user_id'],
                'user_event' => 'user.logout',
                'user_resource' => 'control.panel',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'user_ip' => get_client_ip(),
                'user_location' => get_client_location(),
                'event_time' => $currentDate,
                'user_data' => null
            ]
        );
        Auth::logout();
        redirect()->route('login');
    }
    
    public function getLoginChallenge(Request $request, Response $response)
    {
        global $container;

        $ids = [];
        $rawData = $request->getBody();
        $data = json_decode($rawData, true);

        try {
            $db = $container->get('db');
            $userId = $db->selectValue('SELECT id FROM users WHERE email = ?', [$data['email']]);

            if ($userId) {
                // User found, get the user ID
                $registrations = $db->select('SELECT id, credential_id FROM users_webauthn WHERE user_id = ?', [$userId]);
            
                if ($registrations) {
                    foreach ($registrations as $reg) {
                        $ids[] = base64_decode($reg['credential_id']);
                    }
                }

                if (count($ids) === 0) {
                    $response->getBody()->write(json_encode(['error' => 'no registrations in session for userId ' . $userId]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
            } else {
                $response->getBody()->write(json_encode(['error' => 'No user found with the provided email.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $getArgs = $this->webAuthn->getGetArgs($ids[0], 60*4, true, true, true, true, true, 'discouraged');

        $response->getBody()->write(json_encode($getArgs));
        $_SESSION['challenge'] = ($this->webAuthn->getChallenge())->getBinaryString();

        return $response->withHeader('Content-Type', 'application/json');
    }
    
    public function verifyLogin(Request $request, Response $response)
    {
        global $container;
        
        $challenge = $_SESSION['challenge'];
        $credentialPublicKey = null;
        
        $data = json_decode($request->getBody()->getContents(), null, 512, JSON_THROW_ON_ERROR);

        try {
            // Decode the incoming data
            $clientDataJSON = base64_decode($data->clientDataJSON);
            $authenticatorData = base64_decode($data->authenticatorData);
            $signature = base64_decode($data->signature);
            $userHandle = base64_decode($data->userHandle);
            $id = $data->id;

            $db = $container->get('db');
            $credentials = $db->select('SELECT * FROM users_webauthn WHERE credential_id = ?', [$id]);

            if ($credentials) {
                foreach ($credentials as $reg) {
                    if ($reg['credential_id'] === $id) {
                        $credentialPublicKey = $reg['public_key'];
                        $user_id = $reg['user_id'];
                        break;
                    }
                }
            }

            if ($credentialPublicKey === null) {
                throw new \Exception('Public Key for credential ID not found!');
            }

            // process the get request. throws WebAuthnException if it fails
            $this->webAuthn->processGet($clientDataJSON, $authenticatorData, $signature, $credentialPublicKey, $challenge, null, 'discouraged');       

            $return = array();
            $return['success'] = true;
            $return['msg'] = "Authentication successful.";

            if($return['success']===true) {
                // Send success response
                $user = $db->selectRow('SELECT * FROM users WHERE id = ?', [$user_id]);

                session_regenerate_id();
                $_SESSION['auth_logged_in'] = true;
                $_SESSION['auth_user_id'] = $user['id'];
                $_SESSION['auth_email'] = $user['email'];
                $_SESSION['auth_username'] = $user['username'];
                $_SESSION['auth_status'] = $user['status'];
                $_SESSION['auth_roles'] = $user['roles_mask'];
                $_SESSION['auth_force_logout'] = $user['force_logout'];
                $_SESSION['auth_remembered'] = 0;
                $_SESSION['auth_last_resync'] = \time();

                $db = $container->get('db');
                $currentDateTime = new \DateTime();
                $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
                $db->insert(
                    'users_audit',
                    [
                        'user_id' => $_SESSION['auth_user_id'],
                        'user_event' => 'user.login.webauthn',
                        'user_resource' => 'control.panel',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                        'user_ip' => get_client_ip(),
                        'user_location' => get_client_location(),
                        'event_time' => $currentDate,
                        'user_data' => null
                    ]
                );
                $response->getBody()->write(json_encode($return));
                return $response->withHeader('Content-Type', 'application/json');
            } else {
                $response->getBody()->write(json_encode($return));
                return $response->withHeader('Content-Type', 'application/json');
            }
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['msg' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        } catch (WebAuthnException $e) {
            $return = array();
            $return['success'] = false;
            $response->getBody()->write(json_encode(['msg' => "Authentication failed: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }
}