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
        $logModel = new RegistryTransaction($this->container->get('db'));
        $logs = $logModel->getAllRegistryTransaction();
        return view($response,'admin/logs/index.twig', compact('logs'));
    }
}