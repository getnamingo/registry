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
        $formats = [
            'android-key',
            'android-safetynet',
            'apple',
            'fido-u2f',
            'none',
            'packed',
            'tpm'
        ];

        $this->webAuthn = new \lbuchs\WebAuthn\WebAuthn($rpName, $rpId, $formats);
        $this->webAuthn->addRootCertificates(envi('APP_ROOT').'/vendor/lbuchs/webauthn/_test/rootCertificates/solo.pem');
        $this->webAuthn->addRootCertificates(envi('APP_ROOT').'/vendor/lbuchs/webauthn/_test/rootCertificates/apple.pem');
        $this->webAuthn->addRootCertificates(envi('APP_ROOT').'/vendor/lbuchs/webauthn/_test/rootCertificates/yubico.pem');
        $this->webAuthn->addRootCertificates(envi('APP_ROOT').'/vendor/lbuchs/webauthn/_test/rootCertificates/hypersecu.pem');
        $this->webAuthn->addRootCertificates(envi('APP_ROOT').'/vendor/lbuchs/webauthn/_test/rootCertificates/globalSign.pem');
        $this->webAuthn->addRootCertificates(envi('APP_ROOT').'/vendor/lbuchs/webauthn/_test/rootCertificates/googleHardware.pem');
        $this->webAuthn->addRootCertificates(envi('APP_ROOT').'/vendor/lbuchs/webauthn/_test/rootCertificates/microsoftTpmCollection.pem');
        $this->webAuthn->addRootCertificates(envi('APP_ROOT').'/vendor/lbuchs/webauthn/_test/rootCertificates/mds');
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
        if($login===true)
            redirect()->route('home');
    }

    /**
     * @throws \Pinga\Auth\AuthError
     */
    public function logout()
    {
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
            $user = $db->selectValue('SELECT id FROM users WHERE email = ?', [$data['email']]);

            if ($user) {
                // User found, get the user ID
                $userId = $user;
                $registrations = $db->select('SELECT id FROM users_webauthn WHERE user_id = ?', [$user]);
            
                if ($registrations) {
                    foreach ($registrations as $reg) {
                        $ids[] = $reg['credentialId'];
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
        $challenge = $this->webAuthn->getChallenge();
        $_SESSION['challenge_data'] = $challenge->getBinaryString();

        return $response->withHeader('Content-Type', 'application/json');
    }
    
    public function verifyLogin(Request $request, Response $response)
    {
        global $container;
        
        $challengeData = $_SESSION['challenge_data'];
        $challenge = new \lbuchs\WebAuthn\Binary\ByteBuffer($challengeData);
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
            $this->webAuthn->processGet($clientDataJSON, $authenticatorData, $signature, $credentialPublicKey, $challenge, null, $userVerification === 'required');       

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