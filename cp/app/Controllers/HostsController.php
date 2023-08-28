<?php

namespace App\Controllers;

use App\Models\Host;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class HostsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        $hostsModel = new Host($this->container->get('db'));
        $hosts = $hostsModel->getAllHost();
        return view($response,'admin/hosts/index.twig', compact('hosts'));
    }	
}