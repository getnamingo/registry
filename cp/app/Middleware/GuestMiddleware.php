<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
/**
 * GuestMiddleware
 *
 * @author    Hezekiah O. <support@hezecom.com>
 */
class GuestMiddleware extends Middleware
{
	public function __invoke(Request $request, RequestHandler $handler)
	{
        $response = $handler->handle($request);
		if($this->container->get('auth')->isLogin()) {
		    return redirect()->route('home');
		}
        return $response;
	}
}
