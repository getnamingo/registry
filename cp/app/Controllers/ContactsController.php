<?php

namespace App\Controllers;

use App\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class ContactsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        $userModel = new User($this->container->get('db'));
        $users = $userModel->getAllUsers();
        return view($response,'admin/contacts/index.twig', compact('users'));
    }
	
}
