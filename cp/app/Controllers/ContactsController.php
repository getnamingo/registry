<?php

namespace App\Controllers;

use App\Models\Contact;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class ContactsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        $contactModel = new Contact($this->container->get('db'));
        $contacts = $contactModel->getAllContact();
        return view($response,'admin/contacts/index.twig', compact('contacts'));
    }
}