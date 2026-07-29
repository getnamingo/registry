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
        $this->webAuthn = null;
        if (envi('WEB_AUTHN_ENABLED') === 'true') {
            $this->webAuthn = new \lbuchs\WebAuthn\WebAuthn('Namingo', envi('APP_DOMAIN'));
        }
    }

    /**
     * @param Request $request
     * @param Response $response
     * @return mixed
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function createLogin(Request $request, Response $response){
        $isWebAuthnEnabled = (envi('WEB_AUTHN_ENABLED') === 'true') ? true : false;
        return view($response, 'auth/login.twig', ['isWebaEnabled' => $isWebAuthnEnabled]);
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
        $email = $data['email'] ?? $_SESSION['2fa_email'] ?? null;
        $password = $data['password'] ?? $_SESSION['2fa_password'] ?? null;

        if ($email === null || $password === null) {
            $container->get('flash')->addMessage('error', 'Please log in again');
            unset($_SESSION['2fa_email'], $_SESSION['2fa_password'], $_SESSION['is2FAEnabled']);
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $is2FAEnabled = $db->selectValue('SELECT tfa_enabled FROM users WHERE email = ?', [$email]);
        $isWebaEnabled = $db->selectValue('SELECT auth_method FROM users WHERE email = ?', [$email]);

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
            $_SESSION['is2FAEnabled'] = false;
        }

        // If the 2FA code is present, this might be a 2FA verification attempt
        if (isset($data['code']) && isset($_SESSION['2fa_email']) && isset($_SESSION['2fa_password'])) {
            $email = $_SESSION['2fa_email'];
            $password = $_SESSION['2fa_password'];
        }

        try {
            $login = Auth::login($email, $password, $data['remember'] ?? null, $data['code'] ?? null);
        } finally {
            unset($_SESSION['2fa_email'], $_SESSION['2fa_password'], $_SESSION['is2FAEnabled']);
        }

        if ($login===true) {
            $db = $container->get('db');

            // Check if password renewal is needed
            $passwordLastUpdated = $db->selectValue('SELECT password_last_updated FROM users WHERE id = ?', [$_SESSION['auth_user_id']]);
            if (checkPasswordRenewal($passwordLastUpdated)) {
                Auth::logout();
                redirect()->route('forgot.password')->with('error','Your password is expired. Please change it');
            }

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
        redirect()->route('/');
    }
    
    public function getLoginChallenge(Request $request, Response $response)
    {
        global $container;

        try {
            if ($this->webAuthn === null) {
                throw new \RuntimeException('WebAuthn is disabled.');
            }

            $data = json_decode($request->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $email = trim((string) ($data['email'] ?? ''));
            if ($email === '') {
                throw new \InvalidArgumentException('Enter your email address first.');
            }

            $db = $container->get('db');
            $userId = $db->selectValue(
                'SELECT id FROM users WHERE email = ? AND status = 0 AND verified = 1 AND auth_method = ?',
                [$email, 'webauthn']
            );

            $registrations = $userId
                ? ($db->select(
                    'SELECT credential_id FROM users_webauthn WHERE user_id = ?',
                    [$userId]
                ) ?: [])
                : [];

            $ids = [];
            $encodedIds = [];

            foreach ($registrations as $registration) {
                $id = base64_decode($registration['credential_id'], true);

                if ($id !== false && strlen($id) <= 1023) {
                    $ids[] = $id;
                    $encodedIds[] = $registration['credential_id'];
                }
            }

            /*
             * Return a plausible imaginary credential when the account or its
             * WebAuthn registration does not exist.
             */
            if (count($ids) === 0) {
                $dummySecret = (string) envi('WEBAUTHN_DUMMY_SECRET');

                if (strlen($dummySecret) < 32) {
                    throw new \RuntimeException('WebAuthn dummy secret is not configured.');
                }

                $dummyId = hash_hmac(
                    'sha512',
                    strtolower($email) . "\0" . envi('APP_DOMAIN'),
                    $dummySecret,
                    true
                );

                $ids = [$dummyId];
                $encodedIds = [base64_encode($dummyId)];
                $userId = 0;
            }

            $getArgs = $this->webAuthn->getGetArgs($ids, 60*5, true, true, true, true, true, 'required');

            $_SESSION['webauthn_login'] = [
                'challenge' => ($this->webAuthn->getChallenge())->getBinaryString(),
                'user_id' => $userId,
                'credential_ids' => $encodedIds,
                'expires_at' => time() + 300
            ];

            $response->getBody()->write(json_encode($getArgs));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            error_log('WebAuthn challenge failed: ' . $e->getMessage());

            $response->getBody()->write(json_encode([
                'success' => false,
                'msg' => 'Unable to start WebAuthn login.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }

    public function verifyLogin(Request $request, Response $response)
    {
        global $container;

        try {
            if ($this->webAuthn === null) {
                throw new \RuntimeException('WebAuthn is disabled.');
            }

            $ceremony = $_SESSION['webauthn_login'] ?? null;
            unset($_SESSION['webauthn_login']);
            if (
                !is_array($ceremony)
                || empty($ceremony['challenge'])
                || (int) ($ceremony['expires_at'] ?? 0) < time()
            ) {
                throw new \RuntimeException('WebAuthn login challenge is invalid or expired.');
            }

            $data = json_decode($request->getBody()->getContents(), null, 512, JSON_THROW_ON_ERROR);

            // Decode the incoming data
            $clientDataJSON = validateWebAuthnClientData((string) ($data->clientDataJSON ?? ''));
            $authenticatorData = base64_decode((string) ($data->authenticatorData ?? ''), true);
            $signature = base64_decode((string) ($data->signature ?? ''), true);
            $id = (string) ($data->id ?? '');
            $rawId = base64_decode($id, true);
            if ($authenticatorData === false || $signature === false || $rawId === false || strlen($rawId) > 1023) {
                throw new \InvalidArgumentException('Invalid WebAuthn assertion data.');
            }
            if (!in_array($id, $ceremony['credential_ids'] ?? [], true)) {
                throw new \RuntimeException('Credential was not allowed for this login.');
            }

            $db = $container->get('db');
            $credential = $db->selectRow(
                'SELECT * FROM users_webauthn WHERE credential_id = ? AND user_id = ?',
                [$id, $ceremony['user_id']]
            );
            if (!$credential) {
                throw new \RuntimeException('Public key for credential ID not found.');
            }

            if (isset($data->userHandle) && $data->userHandle !== null) {
                $userHandle = base64_decode((string) $data->userHandle, true);
                $hexUserId = dechex((int) $ceremony['user_id']);
                if (strlen($hexUserId) % 2 !== 0) {
                    $hexUserId = '0' . $hexUserId;
                }
                if ($userHandle === false || !hash_equals(hex2bin($hexUserId), $userHandle)) {
                    throw new \RuntimeException('WebAuthn user handle does not match.');
                }
            }

            // process the get request. throws WebAuthnException if it fails
            $this->webAuthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $credential['public_key'],
                $ceremony['challenge'],
                (int) $credential['sign_count'],
                true
            );

            $return = array();
            $return['success'] = true;
            $return['msg'] = "Authentication successful.";
            $return['redirect'] = '/dashboard';

            if($return['success']===true) {
                // Send success response
                $user = $db->selectRow(
                    'SELECT * FROM users WHERE id = ? AND status = 0 AND verified = 1 AND auth_method = ?',
                    [$ceremony['user_id'], 'webauthn']
                );
                if (!$user) {
                    throw new \RuntimeException('WebAuthn is unavailable for this account.');
                }

                $currentDateTime = new \DateTime();
                $currentDate = $currentDateTime->format('Y-m-d H:i:s.v');
                $credentialUpdate = ['last_used_at' => $currentDate];
                $signatureCounter = $this->webAuthn->getSignatureCounter();
                if ($signatureCounter !== null) {
                    $credentialUpdate['sign_count'] = $signatureCounter;
                }

                $db->beginTransaction();
                try {
                    $db->update('users_webauthn', $credentialUpdate, ['id' => $credential['id']]);
                    $db->update('users', ['last_login' => time()], ['id' => $user['id']]);
                    $db->insert(
                        'users_audit',
                        [
                            'user_id' => $user['id'],
                            'user_event' => 'user.login.webauthn',
                            'user_resource' => 'control.panel',
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                            'user_ip' => get_client_ip(),
                            'user_location' => get_client_location(),
                            'event_time' => $currentDate,
                            'user_data' => null
                        ]
                    );
                    $db->commit();
                } catch (\Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }

                session_regenerate_id(true);
                $_SESSION['auth_logged_in'] = true;
                $_SESSION['auth_user_id'] = $user['id'];
                $_SESSION['auth_email'] = $user['email'];
                $_SESSION['auth_username'] = $user['username'];
                $_SESSION['auth_status'] = $user['status'];
                $_SESSION['auth_roles'] = $user['roles_mask'];
                $_SESSION['auth_force_logout'] = $user['force_logout'];
                $_SESSION['auth_remembered'] = 0;
                $_SESSION['auth_last_resync'] = \time();

                $response->getBody()->write(json_encode($return));
                return $response->withHeader('Content-Type', 'application/json');
            } else {
                $response->getBody()->write(json_encode($return));
                return $response->withHeader('Content-Type', 'application/json');
            }
        } catch (\Throwable $e) {
            error_log('WebAuthn authentication failed: ' . $e->getMessage());

            $response->getBody()->write(json_encode([
                'success' => false,
                'msg' => 'Authentication failed.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }
}