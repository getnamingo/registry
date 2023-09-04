<?php

namespace App\Controllers;

use App\Models\Tickets;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class SupportController extends Controller
{
    public function view(Request $request, Response $response)
    {
        $ticketModel = new Tickets($this->container->get('db'));
        $tickets = $ticketModel->getAllTickets();
        return view($response,'admin/support/view.twig', compact('tickets'));
    }

    public function newticket(Request $request, Response $response)
    {
        return view($response,'admin/support/newticket.twig');
    }

    public function docs(Request $request, Response $response)
    {
        return view($response,'admin/support/docs.twig');
    }

    public function mediakit(Request $request, Response $response)
    {
        return view($response,'admin/support/mediakit.twig');
    }
}