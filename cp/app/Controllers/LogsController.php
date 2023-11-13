<?php

namespace App\Controllers;

use App\Models\RegistryTransaction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class LogsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        return view($response,'admin/logs/index.twig');
    }
	
    public function poll(Request $request, Response $response)
    {
        return view($response,'admin/logs/poll.twig');
    }
	
    public function log(Request $request, Response $response)
    {
        return view($response,'admin/logs/log.twig');
    }
}