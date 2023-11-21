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
