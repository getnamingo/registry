<?php

namespace App\Auth;

use App\Lib\Mail;
use Pinga\Auth\ConfirmationRequestNotFound;
use Pinga\Auth\EmailNotVerifiedException;
use Pinga\Auth\InvalidEmailException;
use Pinga\Auth\InvalidPasswordException;
use Pinga\Auth\InvalidSelectorTokenPairException;
use Pinga\Auth\NotLoggedInException;
use Pinga\Auth\ResetDisabledException;
use Pinga\Auth\TokenExpiredException;
use Pinga\Auth\TooManyRequestsException;
use Pinga\Auth\UserAlreadyExistsException;

/**
 * Auth
 *
 * @author    Hezekiah O. <support@hezecom.com>
 */
class Auth
{
    static protected $auth;

    /**
     * Auth constructor.
     */
    public function __construct()
    {
        self::$auth = auth();
    }

    /**
     * @param $email
     * @param $username
     * @param $password
     * @param array $info
     * @return int
     * @throws \Pinga\Auth\AuthError
     */
    public static function create($email, $username, $password, $info=[]){
        $auth = self::$auth;
        try {
            $userId = $auth->register($email, $username, $password, function ($selector, $token) use ($email, $username) {
                $link = url('verify.email',[],['selector'=>urlencode($selector),'token'=>urlencode($token)]);
                $message = file_get_contents(__DIR__.'/../../resources/views/auth/mail/confirm-email.html');
                $message = str_replace(['{link}','{app_name}'],[$link,envi('APP_NAME')],$message);
                $subject = 'Email Verification';
                $from = ['email'=>envi('MAIL_FROM_ADDRESS'), 'name'=>envi('APP_NAME')];
                $to = ['email'=>$email, 'name'=>$username];
                // send message
                Mail::send($subject, $message, $from, $to);
            });
            //$auth->admin()->addRoleForUserById($userId, Role::ADMIN);
            return $userId;
        }
        catch (InvalidEmailException $e) {
            redirect()->route('register')->with('error','Invalid email address');
        }
        catch (InvalidPasswordException $e) {
            redirect()->route('register')->with('error','Invalid password');
        }
        catch (UserAlreadyExistsException $e) {
            redirect()->route('register')->with('error','User already exists test');
        }
        catch (TooManyRequestsException $e) {
            redirect()->route('register')->with('error','Too many requests, try again later');
        }
    }

    /**
     * @param $selector
     * @param $token
     * @throws \Pinga\Auth\AuthError
     */
    public static function verifyEmail($selector, $token){
        $auth = self::$auth;
        try {
            $auth->confirmEmail($selector, $token);
            //echo 'Email address has been verified';
            redirect()->route('login')->with('success','Email address has been verified');
        }
        catch (InvalidSelectorTokenPairException $e) {
            redirect()->route('login')->with('error','Invalid token');
        }
        catch (TokenExpiredException $e) {
            redirect()->route('login')->with('error','Token expired');
        }
        catch (UserAlreadyExistsException $e) {
            redirect()->route('login')->with('error','Email address already exists');
        }
        catch (TooManyRequestsException $e) {
            redirect()->route('login')->with('error','Too many requests, try again later.');
        }
    }

    /**
     * Re-sending confirmation requests
     * @param $email
     */
    public static function ResendVerification($email){
        $auth = self::$auth;
        try {
            $auth->resendConfirmationForEmail($email, function ($selector, $token) use ($email) {
                $link = url('verify.email',[],['selector'=>urlencode($selector),'token'=>urlencode($token)]);
                $message = file_get_contents(__DIR__.'/../../resources/views/auth/mail/confirm-email.html');
                $message = str_replace(['{link}','{app_name}'],[$link,envi('APP_NAME')],$message);
                $subject = 'Email Verification';
                $from = ['email'=>envi('MAIL_FROM_ADDRESS'), 'name'=>envi('MAIL_FROM_NAME')];
                $to = ['email'=>$email, 'name'=>''];
                // send message
                Mail::send($subject, $message, $from, $to);
            });
            redirect()->route('login')->with('success','We have sent you another email. Please follow the link to verify your email.');
        }
        catch (ConfirmationRequestNotFound $e) {
            redirect()->route('login')->with('error','No earlier request found that could be re-sent.');
        }
        catch (TooManyRequestsException $e) {
            redirect()->route('login')->with('error','Too many requests, try again later');
        }
    }
    /**
     * @param $email
     * @param $password
     * @param null $remember
     * @throws \Pinga\Auth\AttemptCancelledException
     * @throws \Pinga\Auth\AuthError
     */
    public static function login($email, $password, $remember=null, $code=null){
        $auth = self::$auth;
        try {
            if ($remember !='') {
                // keep logged in for one year
                $rememberDuration = (int) (60 * 60 * 24 * 365.25);
            }
            else {
                // do not keep logged in after session ends
                $rememberDuration = null;
            }

            $auth->login($email, $password, $rememberDuration);

            // check if a valid code is provided
            global $container;
            $db = $container->get('db');
            $tfa_secret = $db->selectValue('SELECT tfa_secret FROM users WHERE id = ?', [$auth->getUserId()]);

            if (!is_null($tfa_secret)) {
                if (!is_null($code) && $code !== "" && preg_match('/^\d{6}$/', $code)) {
                // If tfa_secret exists and is not empty, verify the 2FA code
                $tfaService = new \RobThree\Auth\TwoFactorAuth('Namingo');
                    if ($tfaService->verifyCode($tfa_secret, $code) === true) {
                        // 2FA verification successful
                        return true;
                    } else {
                        // 2FA verification failed
                        self::$auth->logOut();
                        redirect()->route('login')->with('error','Incorrect 2FA Code. Please check and enter the correct code. 2FA codes are time-sensitive. For continuous issues, contact support.');
                        //return false; // Ensure to return false or handle accordingly
                    }
                } else {
                    self::$auth->logOut();
                    redirect()->route('login')->with('error','2FA Code Required. Please enter your 6-digit 2FA code to proceed with the login.');
                    //return false;
                }
            } else {
                return true;
            }
        }
        catch (InvalidEmailException $e) {
            redirect()->route('login')->with('error','Wrong email address');
        }
        catch (InvalidPasswordException $e) {
            redirect()->route('login')->with('error','Wrong password');
        }
        catch (EmailNotVerifiedException $e) {
            redirect()->route('login')->with('error','Email not verified');
            die('Email not verified');
        }
        catch (TooManyRequestsException $e) {
            redirect()->route('login')->with('error','Too many requests');
        }
    }

    /**
     * Reset Password 1 of 3
     * @param $email
     * @throws \Pinga\Auth\AuthError
     */
    public static function forgotPassword($email,$username){
        $auth = self::$auth;
        try {
            $auth->forgotPassword($email, function ($selector, $token) use ($email,$username) {
                $link = url('reset.password',[],['selector'=>urlencode($selector),'token'=>urlencode($token)]);
                $message = file_get_contents(__DIR__.'/../../resources/views/auth/mail/reset-password.html');
                $placeholders = ['{user_first_name}', '{link}', '{app_name}'];
                $replacements = [ucfirst($username), $link, envi('APP_NAME')];
                $message = str_replace($placeholders, $replacements, $message);            
                $subject = '[' . envi('APP_NAME') . '] Action Required: Reset Your Password';
                $from = ['email'=>envi('MAIL_FROM_ADDRESS'), 'name'=>envi('MAIL_FROM_NAME')];
                $to = ['email'=>$email, 'name'=>''];
                // send message
                Mail::send($subject, $message, $from, $to);
            });
            redirect()->route('forgot.password')->with('success','A password reset link has been sent to your email.');
        }
        catch (InvalidEmailException $e) {
            redirect()->route('forgot.password')->with('error','Invalid email address');
        }
        catch (EmailNotVerifiedException $e) {
            redirect()->route('forgot.password')->with('error','Email not verified');
        }
        catch (ResetDisabledException $e) {
            redirect()->route('forgot.password')->with('error','Password reset is disabled');
        }
        catch (TooManyRequestsException $e) {
            redirect()->route('forgot.password')->with('error','Too many requests, try again later');
        }
    }

    /**
     * Reset Password 2 of 3
     * @param $selector
     * @param $token
     * @throws \Pinga\Auth\AuthError
     */
    public static function resetPasswordVerify($selector, $token){
        $auth = self::$auth;
        try {
            $auth->canResetPasswordOrThrow($selector, $token);
            redirect()->route('update.password',[],['selector'=>urlencode($selector),'token'=>urlencode($token)]);
        }
        catch (InvalidSelectorTokenPairException $e) {
            redirect()->route('forgot.password')->with('error','Invalid token');
        }
        catch (TokenExpiredException $e) {
            redirect()->route('forgot.password')->with('error','Token expired');
        }
        catch (ResetDisabledException $e) {
            redirect()->route('forgot.password')->with('error','Password reset is disabled');
        }
        catch (TooManyRequestsException $e) {
            redirect()->route('forgot.password')->with('error','Too many requests, try again later');
        }
    }

    /**
     * Reset Password 3 of 3
     * @param $selector
     * @param $token
     * @param $password
     * @throws \Pinga\Auth\AuthError
     */
    public static function resetPasswordUpdate($selector, $token, $password){
        $auth = self::$auth;
        try {
            $auth->resetPassword($selector, $token, $password);
            redirect()->route('login')->with('success','Password has been reset');
        }
        catch (InvalidSelectorTokenPairException $e) {
            redirect()->route('update.password',[],['selector'=>urlencode($selector),'token'=>urlencode($token)])->with('error','Invalid token');
        }
        catch (TokenExpiredException $e) {
            redirect()->route('update.password',[],['selector'=>urlencode($selector),'token'=>urlencode($token)])->with('error','Token expired');
        }
        catch (ResetDisabledException $e) {
            redirect()->route('update.password',[],['selector'=>urlencode($selector),'token'=>urlencode($token)])->with('error','Password reset is disabled');
        }
        catch (InvalidPasswordException $e) {
            redirect()->route('update.password',[],['selector'=>urlencode($selector),'token'=>urlencode($token)])->with('error','Invalid password');
        }
        catch (TooManyRequestsException $e) {
            redirect()->route('login')->with('error','Too many requests, try again later');
        }
    }

    /**
     * Changing the current user’s password when logged in only
     * @param $oldPassword
     * @param $newPassword
     * @throws \Pinga\Auth\AuthError
     */
    public static function changeCurrentPassword($oldPassword, $newPassword){
        $auth = self::$auth;
        try {
            global $container;
            $db = $container->get('db');
            $currentDateTime = new \DateTime();
            $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
            $db->insert(
                'users_audit',
                [
                    'user_id' => $_SESSION['auth_user_id'],
                    'user_event' => 'user.update.password',
                    'user_resource' => 'control.panel',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                    'user_ip' => get_client_ip(),
                    'user_location' => get_client_location(),
                    'event_time' => $currentDate,
                    'user_data' => null
                ]
            );
            $auth->changePassword($oldPassword, $newPassword);
            redirect()->route('profile')->with('success','Password has been changed');
        }
        catch (NotLoggedInException $e) {
            redirect()->route('profile')->with('error','You are not logged in');
        }
        catch (InvalidPasswordException $e) {
            redirect()->route('profile')->with('error','Your old password do not match');
        }
        catch (TooManyRequestsException $e) {
            redirect()->route('profile')->with('error','Too many requests, try again later');
        }
    }

    /**
     * @throws \Pinga\Auth\AuthError
     */
    public static function logout(){
        return self::$auth->logOut();
    }

    /**
     * @return bool
     */
    public function isLogin(){
        if (self::$auth->isLoggedIn()) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * @return array
     */
    public function user(){
        $auth = self::$auth;
        $info = [
            'id' => $auth->getUserId(),
            'email' => $auth->getEmail(),
            'username' => $auth->getUsername(),
            'ip' => $auth->getIpAddress()
        ];
        return $info;
    }
}