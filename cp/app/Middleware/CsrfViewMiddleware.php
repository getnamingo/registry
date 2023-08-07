<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * CsrfViewMiddleware
 *
 * @author    Hezekiah O. <support@hezecom.com>
 */
class CsrfViewMiddleware extends Middleware
{

	public function __invoke(Request $request, RequestHandler $handler)
	{
		$this->container->get('view')->getEnvironment()->addGlobal('csrf', [
			'field' => '
				<input type="hidden" name="'. $this->container->get('csrf')->getTokenNameKey() .'"
				 value="'. $this->container->get('csrf')->getTokenName() .'">
				<input type="hidden" name="'. $this->container->get('csrf')->getTokenValueKey() .'"
				 value="'. $this->container->get('csrf')->getTokenValue() .'">
			',
		]);
		$response = $handler->handle($request);
		return $response;
	}
}
