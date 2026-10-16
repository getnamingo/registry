<?php

/*
 * Namingo Registry
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

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