<?php

namespace App\Controllers;

use App\Models\Tickets;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class SystemController extends Controller
{
    public function settings(Request $request, Response $response)
    {
        return view($response,'admin/system/settings.twig');
    }
}