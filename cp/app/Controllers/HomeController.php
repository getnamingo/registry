<?php

namespace App\Controllers;

use App\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class HomeController extends Controller
{
    public function index(Request $request, Response $response)
    {
        $db = $this->container->get('db');
        $whois_server = $db->selectValue("SELECT value FROM settings WHERE name = 'whois_server'");
        $rdap_server = $db->selectValue("SELECT value FROM settings WHERE name = 'rdap_server'");
        $company_name = $db->selectValue("SELECT value FROM settings WHERE name = 'company_name'");
        $email = $db->selectValue("SELECT value FROM settings WHERE name = 'email'");
        
        return view($response, 'index.twig', [
            'whois_server' => $whois_server,
            'rdap_server' => $rdap_server,
            'company_name' => $company_name,
            'email' => $email
        ]);
    }

    public function dashboard(Request $request, Response $response)
    {
        $db = $this->container->get('db');

        if ($_SESSION['auth_roles'] === 0) {
            $clid = null;
        } else {
            $result = $db->selectRow('SELECT registrar_id FROM registrar_users WHERE user_id = ?', [$_SESSION['auth_user_id']]);
            if (is_array($result)) {
                $clid = $result['registrar_id'];
            } else if (is_object($result) && method_exists($result, 'fetch')) {
                $clid = $result->fetch();
            } else {
                $clid = null;
            }
        }

        if ($clid !== null) {
            $domains = $db->selectValue('SELECT count(id) as domains FROM domain WHERE clid = ?', [$clid]);
            $latest_domains = $db->select('SELECT name, crdate FROM domain WHERE clid = ? ORDER BY crdate DESC LIMIT 10', [$clid]);
            $tickets = $db->select('SELECT id, subject, status, priority FROM support_tickets WHERE user_id = ? ORDER BY date_created DESC LIMIT 10', [$clid]);
            $hosts = $db->selectValue('SELECT count(id) as hosts FROM host WHERE clid = ?', [$clid]);
            $contacts = $db->selectValue('SELECT count(id) as contacts FROM contact WHERE clid = ?', [$clid]);
            
            return view($response, 'admin/dashboard/index.twig', [
                'domains' => $domains,
                'hosts' => $hosts,
                'contacts' => $contacts,
                'latest_domains' => $latest_domains,
                'tickets' => $tickets,
            ]);
        } else {
            $domains = $db->selectValue('SELECT count(id) as domains FROM domain');
            $latest_domains = $db->select('SELECT name, crdate FROM domain ORDER BY crdate DESC LIMIT 10');
            $tickets = $db->select('SELECT id, subject, status, priority FROM support_tickets ORDER BY date_created DESC LIMIT 10');
            $hosts = $db->selectValue('SELECT count(id) as hosts FROM host');
            $contacts = $db->selectValue('SELECT count(id) as contacts FROM contact');
            $registrars = $db->selectValue('SELECT count(id) as registrars FROM registrar');
            
            return view($response, 'admin/dashboard/index.twig', [
                'domains' => $domains,
                'hosts' => $hosts,
                'contacts' => $contacts,
                'registrars' => $registrars,
                'latest_domains' => $latest_domains,
                'tickets' => $tickets,
            ]);
        }
    }
    
    public function mode(Request $request, Response $response)
    {
        if ($_SESSION['_screen_mode'] == 'dark') {
            $_SESSION['_screen_mode'] = 'light';
        } else {
            $_SESSION['_screen_mode'] = 'dark';
        }
        $referer = $request->getHeaderLine('Referer');
        if (!empty($referer)) {
            return $response->withHeader('Location', $referer)->withStatus(302);
        }
        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }
    
    public function avatar(Request $request, Response $response)
    {
        $avatar = new \LasseRafn\InitialAvatarGenerator\InitialAvatar();
        $stream = $avatar->name($_SESSION['auth_username'])->length(2)->fontSize(0.5)->size(96)->background('#206bc4')->color('#fff')->generate()->stream('png', 100);
        $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $psrResponse = $psr17Factory->createResponse(200)->withBody($stream);

        return $psrResponse;
    }
    
    public function lang(Request $request, Response $response)
    {
        $data = $request->getQueryParams();
        if (!empty($data)) {
            $_SESSION['_lang'] = array_key_first($data);
        } else {
            unset($_SESSION['_lang']);
        }
        $referer = $request->getHeaderLine('Referer');
        if (!empty($referer)) {
            return $response->withHeader('Location', $referer)->withStatus(302);
        }
        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }
}