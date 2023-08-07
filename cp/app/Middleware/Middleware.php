<?php

namespace App\Middleware;

use DI\Container;

/**
 * Middleware
 *
 * @author    Hezekiah O. <support@hezecom.com>
 */
class Middleware
{
	protected $container;

	public function __construct(Container $container)
	{
		$this->container = $container;
	}
}
