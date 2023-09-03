<?php

namespace App\Controllers;

use App\Models\Contact;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use League\ISO3166\ISO3166;

class ContactsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        return view($response,'admin/contacts/view.twig');
    }

    public function create(Request $request, Response $response)
    {
        $iso3166 = new ISO3166();
        $db = $this->container->get('db');
        $countries = $iso3166->all();
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        
        // Default view for GET requests or if POST data is not set
        return view($response,'admin/contacts/create.twig', [
            'registrars' => $registrars,
            'countries' => $countries,
        ]);
    }
}