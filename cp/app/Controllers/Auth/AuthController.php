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
    /**
     * @param Request $request
     * @param Response $response
     * @return mixed
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function createRegister(Request $request, Response $response){
        return view($response,'auth/register.twig');
    }

    /**
     * @param Request $request
     * @param Response $response
     * @return Response
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \Pinga\Auth\AuthError
     */
    public function register(Request $request, Response $response){

        $validation = $this->validator->validate($request, [
            'email' => v::noWhitespace()->notEmpty()->email(),
            'username' => v::noWhitespace()->notEmpty()->alnum(),
            'password' => v::notEmpty()->stringType()->length(8),
        ]);

        if ($validation->failed()) {
            redirect()->route('register');
            //or
            //return $response->withHeader('Location', route('register'));
        }
        $data = $request->getParsedBody();
        $auth =Auth::create($data['email'],$data['password'],$data['username']);
        if($auth) {
            $msg = '<a href="'.route('verify.email.resend',[],['email'=>$data['email']]).'">Resend email</a>';
            flash('success', 'We have send you a verification link to '.$data['email'].' <br>'.$msg);
            return $response->withHeader('Location', route('login'));
        }

    }

    /**
     * @param Request $request
     * @param Response $response
     */
    public function verifyEmailResend(Request $request, Response $response){
        $data = $request->getQueryParams();
        Auth::ResendVerification($data['email']);
    }

    /**
     * @param Request $request
     * @param Response $response
     * @throws \Pinga\Auth\AuthError
     */
    public function verifyEmail(Request $request, Response $response){
        //confirm email
        $data = $request->getQueryParams();
        Auth::verifyEmail($data['selector'], $data['token']);
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
        $login = Auth::login($data['email'], $data['password'], $remember);
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
}
