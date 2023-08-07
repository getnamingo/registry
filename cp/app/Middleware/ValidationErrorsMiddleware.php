<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
/**
 * ValidationErrorsMiddleware
 *
 * @author    Hezekiah O. <support@hezecom.com>
 */
class ValidationErrorsMiddleware extends Middleware
{

	public function __invoke(Request $request, RequestHandler $handler)
	{
		$this->container->get('view')->getEnvironment()->addGlobal('errors', isset($_SESSION['errors']) ? $_SESSION['errors'] : '');
		unset($_SESSION['errors']);
		$response = $handler->handle($request);
		return $response;
	}
}
