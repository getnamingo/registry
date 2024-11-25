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

        $basePath = '/var/www/cp/resources/views/';
        $template = file_exists($basePath . 'index.custom.twig') 
                    ? 'index.custom.twig' 
                    : 'index.twig';
        return view($response, $template, [
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
            $startDate = (new \DateTime())->modify('-6 days');
            $startDateFormatted = $startDate->format('Y-m-d');

            $query = "SELECT DATE(crdate) as date, COUNT(id) as count
                      FROM domain
                      WHERE crdate >= :startDate
                      GROUP BY DATE(crdate)
                      ORDER BY DATE(crdate) ASC";

            $params = [
                ':startDate' => $startDateFormatted,
            ];

            $domainsCount = $db->select($query, $params);
            
            $dates = [];
            $counts = [];

            if (is_array($domainsCount) || is_object($domainsCount)) {
                foreach ($domainsCount as $row) {
                    // Extract just the date part from the datetime string
                    $date = (new \DateTime($row['date']))->format('Y-m-d');
                    $count = (int)$row['count']; // Ensure count is an integer

                    $dates[] = $date;
                    $counts[] = $count;
                }
            } else {
                $dates[] = 'No data';
                $counts[] = 0;
            }

            $query = "
            SELECT 
                r.id, 
                r.name, 
                COUNT(d.id) AS domain_count
            FROM 
                registrar r
            JOIN 
                domain d ON r.id = d.clid
            GROUP BY 
                r.id
            ORDER BY 
                domain_count DESC
            LIMIT 10;
            ";

            // Execute the query
            $results = $db->select($query);

            // Prepare data for the chart
            $labels = [];
            $series = [];

            if (is_array($results) || is_object($results)) {
                foreach ($results as $row) {
                    $labels[] = $row['name']; // Registrar names for chart labels
                    $series[] = (int)$row['domain_count']; // Domain counts for chart data
                }
            } else {
                $labels[] = 0;
                $series[] = 0;
            }

            $query = "
                SELECT 
                    DATE(date_created) as ticket_date, 
                    SUM(CASE WHEN status IN ('Open', 'In Progress') THEN 1 ELSE 0 END) AS unanswered,
                    SUM(CASE WHEN status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS answered
                FROM 
                    support_tickets
                WHERE 
                    date_created >= CURRENT_DATE - INTERVAL 7 DAY
                GROUP BY 
                    DATE(date_created)
                ORDER BY 
                    DATE(date_created) ASC;
            ";

            // Execute the query
            $results = $db->select($query);

            // Prepare data for ApexCharts
            $labels3 = [];
            $answeredData = [];
            $unansweredData = [];

            if (is_array($results) || is_object($results)) {
                foreach ($results as $row) {
                    $labels3[] = $row['ticket_date'];
                    $answeredData[] = (int) $row['answered']; // Cast to int for ApexCharts
                    $unansweredData[] = (int) $row['unanswered']; // Cast to int for ApexCharts
                }
            } else {
                $labels3[] = 0;
                $answeredData[] = 0;
                $unansweredData[] = 0;
            }

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
                'dates' => json_encode($dates),
                'counts' => json_encode($counts),
                'labels' => json_encode($labels),
                'series' => json_encode($series),
                'labels3' => json_encode($labels3),
                'answeredData' => json_encode($answeredData),
                'unansweredData' => json_encode($unansweredData),
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