<?php

namespace App\Controllers;

use App\Models\RegistryTransaction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use League\ISO3166\ISO3166;

class RegistrarsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        return view($response,'admin/registrars/index.twig');
    }
    
    public function create(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        $iso3166 = new ISO3166();
        $countries = $iso3166->all();
        
        // Default view for GET requests or if POST data is not set
        return view($response,'admin/registrars/create.twig', [
            'countries' => $countries,
        ]);
    }
}