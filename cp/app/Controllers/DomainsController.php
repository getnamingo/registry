<?php

namespace App\Controllers;

use App\Models\Domain;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class DomainsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        return view($response,'admin/domains/view.twig');
    }
    
    public function check(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $domainName = $data['domain_name'] ?? null;

            if ($domainName) {
                $domainModel = new Domain($this->container->get('db'));
                $availability = $domainModel->getDomainByName($domainName);

                // Convert the DB result into a boolean '0' or '1'
                $availability = $availability ? '0' : '1';
                
                $invalid_label = validate_label($domainName, $this->container->get('db'));
                
                // Check if the domain is Invalid
                if ($invalid_label) {
                    $status = $invalid_label;
                    $isAvailable = 0;
                } else {
                    $isAvailable = $availability;
                    $status = null; 

                    // Check if the domain is unavailable
                    if ($availability === '0') {
                        $status = 'In use';
                    }
                }

                return view($response, 'admin/domains/check.twig', [
                    'isAvailable' => $isAvailable,
                    'domainName' => $domainName,
                    'status' => $status,
                ]);
            }
        }

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/domains/check.twig');
    }
    
    public function create(Request $request, Response $response)
    {
        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");

        // Default view for GET requests or if POST data is not set
        return view($response,'admin/domains/create.twig', [
            'registrars' => $registrars,
        ]);
    }
    
    public function transfers(Request $request, Response $response)
    {
        return view($response,'admin/domains/transfers.twig');
    }
}