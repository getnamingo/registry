<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
/**
 * OldInputMiddleware
 *
 * @author    Hezekiah O. <support@hezecom.com>
 */
class OldInputMiddleware extends Middleware
{
	public function __invoke(Request $request, RequestHandler $handler)
	{
		$this->container->get('view')->getEnvironment()->addGlobal('old', isset($_SESSION['old']) ? $_SESSION['old'] : '');
		$_SESSION['old'] = $request->getParsedBody();
		$response = $handler->handle($request);
		return $response;
	}
}
