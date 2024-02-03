<?php

namespace App\Controllers\Auth;

use App\Auth\Auth;
use App\Controllers\Controller;
use Respect\Validation\Validator as v;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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
        $this->webAuthn = new \lbuchs\WebAuthn\WebAuthn($rpName, $rpId, ['none']);
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
     * @param Request $request
     * @param Response $response
     * @throws \Pinga\Auth\AttemptCancelledException
     * @throws \Pinga\Auth\AuthError
     */
    public function login(Request $request, Response $response){
        global $container;

        $data = $request->getParsedBody();
        if(isset($data['remember'])){
            $remember = $data['remember'];
        }else{
            $remember = null;
        }
        if(isset($data['code'])){
            $code = $data['code'];
        }else{
            $code = null;
        }
        $login = Auth::login($data['email'], $data['password'], $remember, $code);
        if($login===true) {
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
                $registrations = $db->select('SELECT id,credential_id FROM users_webauthn WHERE user_id = ?', [$userId]);
            
                if ($registrations) {
                    foreach ($registrations as $reg) {
                        $ids[] = $reg['credential_id'];
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

        $getArgs = $this->webAuthn->getGetArgs($ids, 60*4, true, true, true, true, true, 'required');

        $response->getBody()->write(json_encode($getArgs));
        $_SESSION['challenge'] = $this->webAuthn->getChallenge();

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
            $id = base64_decode($data->id);
            
            $db = $container->get('db');
            $credential = $db->select('SELECT public_key FROM users_webauthn WHERE user_id = ?', [$id]);
            
            if ($credential) {
                foreach ($registrations as $reg) {
                    $credentialPublicKey = $reg['public_key'];
                    break;
                }
            }

            if ($credentialPublicKey === null) {
                throw new Exception('Public Key for credential ID not found!');
            }

            // if we have resident key, we have to verify that the userHandle is the provided userId at registration
            if ($requireResidentKey && $userHandle !== hex2bin($reg->userId)) {
                throw new \Exception('userId doesnt match (is ' . bin2hex($userHandle) . ' but expect ' . $reg->userId . ')');
            }

            // process the get request. throws WebAuthnException if it fails
            $this->webAuthn->processGet($clientDataJSON, $authenticatorData, $signature, $credentialPublicKey, $challenge, null, 'required');       

            $return = new \stdClass();
            $return->success = true;
            $return->msg = $msg;

            // Send success response
            $response->getBody()->write(json_encode($return));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }
}