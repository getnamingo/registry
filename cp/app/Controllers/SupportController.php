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
        return view($response,'admin/support/view.twig');
    }

    public function newticket(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $categories = $db->select("SELECT * FROM ticket_categories");
            
            $category = $data['category'] ?? null;
            $subject = $data['subject'] ?? null;
            $message = $data['message'] ?? null;
            
            if (!$subject) {
                return view($response, 'admin/support/newticket.twig', [
                    'error' => 'Please enter a subject',
                    'categories' => $categories,
                ]);
            }
            
            if (!$message) {
                return view($response, 'admin/support/newticket.twig', [
                    'error' => 'Please enter a message',
                    'categories' => $categories,
                ]);
            }
            
            try {    
                $currentDateTime = new \DateTime();
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v');
                $db->insert(
                    'support_tickets',
                    [
                        'user_id' => $_SESSION['auth_user_id'],
                        'category_id' => $category,
                        'subject' => $subject,
                        'message' => $message,
                        'status' => 'Open',
                        'priority' => 'Medium',
                        'reported_domain' => null,
                        'nature_of_abuse' => null,
                        'evidence' => null,
                        'relevant_urls' => null,
                        'date_of_incident' => null,
                        'date_created' => $crdate,
                        'last_updated' => null,
                    ]
                );
                $ticket_id = $db->getLastInsertId();
                
                $db->insert(
                    'ticket_responses',
                    [
                        'ticket_id' => $ticket_id,
                        'responder_id' => $_SESSION['auth_user_id'],
                        'response' => $message,
                        'date_created' => $crdate,
                    ]
                );

            } catch (Exception $e) {
                $db->rollBack();
                return view($response, 'admin/support/newticket.twig', [
                    'error' => $e->getMessage(),
                    'categories' => $categories
                ]);
            }
            
            return view($response, 'admin/support/view.twig', [
                'categories' => $categories,
                'subject' => $subject,
            ]);
            
        }
        
        $db = $this->container->get('db');
        $categories = $db->select("SELECT * FROM ticket_categories");
        
        // Default view for GET requests or if POST data is not set
        return view($response,'admin/support/newticket.twig', [
            'categories' => $categories,
        ]);
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