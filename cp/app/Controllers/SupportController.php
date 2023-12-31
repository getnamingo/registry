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
                $db->beginTransaction();
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

                $db->commit();
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
    
    public function viewTicket(Request $request, Response $response, $args)
    {
        $rawNumber = $args; 
        $ticketNumber = filter_var($rawNumber, FILTER_VALIDATE_INT);

        if ($ticketNumber === false) {
            $this->container->get('flash')->addMessage('error', 'Invalid ticket number');
            return $response->withHeader('Location', '/support')->withStatus(302);
        }
      
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();
        
        $ticket = $db->selectRow('SELECT st.*, u.username AS ticket_creator 
                FROM support_tickets AS st 
                JOIN users AS u ON st.user_id = u.id 
                WHERE st.id = ?', [$ticketNumber]);

        if ($ticket) {
            $replies = $db->select('SELECT tr.*, u.username AS responder_name 
                FROM ticket_responses AS tr 
                JOIN users AS u ON tr.responder_id = u.id 
                WHERE tr.ticket_id = ?
                ORDER BY tr.date_created DESC', [$ticketNumber]);
            $category = $db->selectValue('SELECT name FROM ticket_categories WHERE id = ?', [$ticket['category_id']]);
            
            // Default view for GET requests or if POST data is not set
            return view($response,'admin/support/viewTicket.twig', [
                'ticket' => $ticket,
                'replies' => $replies,
                'category' => $category,
                'currentUri' => $uri
            ]);
        } else {
            $this->container->get('flash')->addMessage('error', 'Invalid ticket number');
            return $response->withHeader('Location', '/support')->withStatus(302);
        }
    }
    
    public function replyTicket(Request $request, Response $response)
    {      
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();
            $categories = $db->select("SELECT * FROM ticket_categories");
            
            $ticket_id = $data['ticket_id'] ?? null;
            $responseText = $data['responseText'] ?? null;

            if (!$responseText) {
                $this->container->get('flash')->addMessage('error', 'Please enter a reply');
                return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
            }
 
            try {    
                $currentDateTime = new \DateTime();
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v');
                
                $db->insert(
                    'ticket_responses',
                    [
                        'ticket_id' => $ticket_id,
                        'responder_id' => $_SESSION['auth_user_id'],
                        'response' => $responseText,
                        'date_created' => $crdate,
                    ]
                );

                $this->container->get('flash')->addMessage('success', 'Reply has been created successfully on ' . $crdate);
                return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
            } catch (Exception $e) {
                $this->container->get('flash')->addMessage('error', 'Database error: '.$e->getMessage());
                return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
            }
        }
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