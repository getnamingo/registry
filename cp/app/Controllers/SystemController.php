<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class SystemController extends Controller
{
    public function registry(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        $db = $this->container->get('db');
        $tlds = $db->select("SELECT id, tld, idn_table, secure FROM domain_tld");

        foreach ($tlds as $key => $tld) {
            // Count the domains for each TLD
            $domainCount = $db->select("SELECT COUNT(name) FROM domain WHERE tldid = ?", [$tld['id']]);
            
            // Add the domain count to the TLD array
            $tlds[$key]['domain_count'] = $domainCount[0]['COUNT(name)'];
        }

        return view($response,'admin/system/registry.twig', [
            'tlds' => $tlds,
        ]);
    }   
}