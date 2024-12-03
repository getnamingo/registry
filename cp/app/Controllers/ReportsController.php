<?php

namespace App\Controllers;

use App\Models\RegistryTransaction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Nyholm\Psr7\Stream;

class ReportsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        $stats = [];
        $currency = $_SESSION['_currency'] ?? 'USD';
        $db = $this->container->get('db');
        $totalDomains = $db->select('SELECT COUNT(name) as total FROM domain');
        $numT = $totalDomains[0]['total'] ?? 1;

        $registrars = $db->select('SELECT id, name FROM registrar');
        foreach ($registrars as $registrar) {
            $domainCount = $db->select(
                'SELECT COUNT(name) as count FROM domain WHERE clid = ?',
                [$registrar['id']]
            );

            $earnings = $db->select(
                'SELECT SUM(amount) as amt FROM statement WHERE registrar_id = ? AND command <> "deposit"',
                [$registrar['id']]
            );

            $stats[] = [
                'id' => $registrar['id'],
                'registrar' => $registrar['name'],
                'currency' => $currency,
                'number' => $domainCount[0]['count'] ?? 0,
                'share' => $numT > 0 
                    ? number_format(($domainCount[0]['count'] ?? 0) / $numT * 100, 2) 
                    : '0.00',
                'earnings' => $earnings[0]['amt'] ?? 0
            ];
        }

        usort($stats, function ($a, $b) {
            return $b['share'] <=> $a['share'];
        });

        return view($response,'admin/reports/index.twig', [
            'stats' => $stats
        ]);
    }
    
    public function exportDomains(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        
        // Fetch domain data from the database
        $db = $this->container->get('db');
        $domains = $db->select("SELECT name, crdate, exdate FROM domain");

        // Create a temporary memory file for the CSV data
        $csvFile = fopen('php://memory', 'w');
        
        // Define CSV headers
        $headers = ['Domain Name', 'Creation Date', 'Expiration Date'];
        
        // Write the headers to the CSV file
        fputcsv($csvFile, $headers);

        // Write the domain data to the CSV file
        if (!empty($domains) && is_iterable($domains)) {
            foreach ($domains as $domain) {
                fputcsv($csvFile, [
                    $domain['name'], 
                    $domain['crdate'], 
                    $domain['exdate']
                ]);
            }
        }

        // Rewind the file pointer to the beginning of the file
        fseek($csvFile, 0);
        
        // Create a stream from the in-memory file
        $stream = Stream::create($csvFile);
        
        // Prepare the response headers for CSV file download
        $response = $response->withHeader('Content-Type', 'text/csv')
                             ->withHeader('Content-Disposition', 'attachment; filename="domains_export.csv"')
                             ->withHeader('Pragma', 'no-cache')
                             ->withHeader('Expires', '0');

        // Output the CSV content to the response body
        return $response->withBody($stream);
    }
}