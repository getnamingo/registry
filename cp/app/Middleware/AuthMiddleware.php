<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * AuthMiddleware
 *
 * @author    Hezekiah O. <support@hezecom.com>
 */
class AuthMiddleware extends Middleware
{

	public function __invoke(Request $request, RequestHandler $handler)
	{
		if(! $this->container->get('auth')->isLogin()) {
            return redirect()->route('login')->with('error', 'Access denied, you need to login.');
		}
		$response = $handler->handle($request);
		return $response;
	}
}
