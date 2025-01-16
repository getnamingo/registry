<?php

namespace App\Controllers;

use App\Models\Tickets;
use App\Lib\Mail;
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
            $subject = htmlspecialchars($data['subject'], ENT_QUOTES, 'UTF-8') ?? null;
            $message = $data['message'] ?? null;

            if (!$subject) {
                $this->container->get('flash')->addMessage('error', 'Please enter a subject');
                return $response->withHeader('Location', '/support/new')->withStatus(302);
            }
            
            if (!$message) {
                $this->container->get('flash')->addMessage('error', 'Please enter a message');
                return $response->withHeader('Location', '/support/new')->withStatus(302);
            }
            
            if (mb_strlen($message, 'UTF-8') > 5000) {
                $this->container->get('flash')->addMessage('error', 'The provided message exceeds the 5,000 character limit');
                return $response->withHeader('Location', '/support/new')->withStatus(302);
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
                
                $link = envi('APP_URL').'/ticket/'.$ticket_id;
                $email = $db->selectValue('SELECT email FROM users WHERE id = ?', [$_SESSION['auth_user_id']]);
                $registry = $db->selectValue('SELECT value FROM settings WHERE name = ?', ['company_name']);
                $crdate = $currentDateTime->format('Y-m-d H:i:s.v');
                $message = file_get_contents(__DIR__.'/../../resources/views/mail/ticket.html');
                $placeholders = ['{registry}', '{link}', '{app_name}', '{app_url}', '{crdate}', '{ticket_id}', '{subject}'];
                $replacements = [$registry, $link, envi('APP_NAME'), envi('APP_URL'), $crdate, $ticket_id, $subject];
                $message = str_replace($placeholders, $replacements, $message);            
                $mailsubject = '[' . envi('APP_NAME') . '] New Support Ticket Created';
                $from = ['email'=>envi('MAIL_FROM_ADDRESS'), 'name'=>envi('MAIL_FROM_NAME')];
                $to = ['email'=>$email, 'name'=>''];
                // send message
                Mail::send($mailsubject, $message, $from, $to);
            } catch (Exception $e) {
                $db->rollBack();

                $this->container->get('flash')->addMessage('error', 'Database error: ' . $e->getMessage());
                return $response->withHeader('Location', '/support/new')->withStatus(302);
            }
            
            $this->container->get('flash')->addMessage('success', 'Support ticket ' . $subject . ' has been created successfully!');
            return $response->withHeader('Location', '/support')->withStatus(302);          
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
        $ticketNumber = filter_var($args, FILTER_VALIDATE_INT);
        $db = $this->container->get('db');

        if ($ticketNumber === false) {
            $this->container->get('flash')->addMessage('error', 'Invalid ticket number');
            return $response->withHeader('Location', '/support')->withStatus(302);
        }
        
        $ticket_owner = $db->selectValue('SELECT user_id FROM support_tickets WHERE id = ?', [$ticketNumber]);
            
        if ($ticket_owner != $_SESSION['auth_user_id'] && $_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/support')->withStatus(302);
        }

        // Get the current URI
        $uri = $request->getUri()->getPath();
        
        $ticket = $db->selectRow('SELECT st.*, u.username AS ticket_creator, st.user_id 
                FROM support_tickets AS st 
                JOIN users AS u ON st.user_id = u.id 
                WHERE st.id = ?', [$ticketNumber]);

        if ($ticket) {
            $replies = $db->select('SELECT tr.*, u.username AS responder_name, tr.responder_id 
                FROM ticket_responses AS tr 
                JOIN users AS u ON tr.responder_id = u.id 
                WHERE tr.ticket_id = ?
                ORDER BY tr.date_created ASC', [$ticketNumber]);
            $category = $db->selectValue('SELECT name FROM ticket_categories WHERE id = ?', [$ticket['category_id']]);

            $_SESSION['current_ticket'] = [$ticket['id']];
            return view($response,'admin/support/viewTicket.twig', [
                'ticket' => $ticket,
                'replies' => $replies,
                'category' => $category,
                'currentUri' => $uri,
                'user_id' => $_SESSION['auth_user_id']
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
            
            if (!empty($_SESSION['current_ticket'])) {
                $ticket_id = $_SESSION['current_ticket'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No ticket selected');
                return $response->withHeader('Location', '/support')->withStatus(302);
            }
            $responseText = $data['responseText'] ?? null;

            if (mb_strlen($responseText, 'UTF-8') > 5000) {
                $this->container->get('flash')->addMessage('error', 'The provided message exceeds the 5,000 character limit');
                return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
            }

            $ticket_owner = $db->selectValue('SELECT user_id FROM support_tickets WHERE id = ?', [$ticket_id]);
            
            if ($ticket_owner != $_SESSION['auth_user_id'] && $_SESSION["auth_roles"] != 0) {
                $this->container->get('flash')->addMessage('error', 'You do not have permission to perform this action');
                return $response->withHeader('Location', '/support')->withStatus(302);
            }

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
                
                $db->update(
                    'support_tickets',
                    [
                        'status' => 'In Progress',
                        'last_updated' => $crdate
                    ],
                    [
                        'id' => $ticket_id
                    ]
                );

                $link = envi('APP_URL').'/ticket/'.$ticket_id;
                $email = $db->selectValue('SELECT email FROM users WHERE id = ?', [$_SESSION['auth_user_id']]);
                $registry = $db->selectValue('SELECT value FROM settings WHERE name = ?', ['company_name']);
                $responseBrief = mb_substr($responseText, 0, 100);
                if (mb_strlen($responseText) > 100) {
                    $responseBrief .= "...";
                }
                $message = file_get_contents(__DIR__.'/../../resources/views/mail/ticket-reply.html');
                $placeholders = ['{registry}', '{link}', '{app_name}', '{app_url}', '{latest}', '{ticket_id}'];
                $replacements = [$registry, $link, envi('APP_NAME'), envi('APP_URL'), $responseBrief, $ticket_id];
                $message = str_replace($placeholders, $replacements, $message);            
                $mailsubject = '[' . envi('APP_NAME') . '] Update on Your Support Ticket';
                $from = ['email'=>envi('MAIL_FROM_ADDRESS'), 'name'=>envi('MAIL_FROM_NAME')];
                $to = ['email'=>$email, 'name'=>''];
                // send message
                Mail::send($mailsubject, $message, $from, $to);

                unset($_SESSION['current_ticket']);
                $this->container->get('flash')->addMessage('success', 'Reply has been posted successfully on ' . $crdate);
                return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
            } catch (Exception $e) {
                $this->container->get('flash')->addMessage('error', 'Database error: '.$e->getMessage());
                return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
            }
        }
    }
    
    public function statusTicket(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            // Get the current URI
            $uri = $request->getUri()->getPath();
            $categories = $db->select("SELECT * FROM ticket_categories");
            
            if (!empty($_SESSION['current_ticket'])) {
                $ticket_id = $_SESSION['current_ticket'][0];
            } else {
                $this->container->get('flash')->addMessage('error', 'No ticket selected');
                return $response->withHeader('Location', '/support')->withStatus(302);
            }
            $action = $data['action'] ?? null;
            
            $ticket_owner = $db->selectValue('SELECT user_id FROM support_tickets WHERE id = ?', [$ticket_id]);
            
            if ($ticket_owner != $_SESSION['auth_user_id'] && $_SESSION["auth_roles"] != 0) {
                $this->container->get('flash')->addMessage('error', 'You do not have permission to perform this action');
                return $response->withHeader('Location', '/support')->withStatus(302);
            }
            
            if (!$action) {
                $this->container->get('flash')->addMessage('error', 'Please select an action');
                return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
            }

            try {    
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v');
                
                if ($action === 'close') {
                    $db->update(
                        'support_tickets',
                        [
                            'status' => 'Closed',
                            'last_updated' => $update
                        ],
                        [
                            'id' => $ticket_id
                        ]
                    );
                    $this->container->get('flash')->addMessage('success', 'Ticket has been closed successfully');
                    return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
                } else if ($action === 'escalate') {            
                    $db->update(
                        'support_tickets',
                        [
                            'priority' => 'High',
                            'last_updated' => $update
                        ],
                        [
                            'id' => $ticket_id
                        ]
                    );
                    $this->container->get('flash')->addMessage('success', 'Ticket has been escalated successfully');
                    return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
                } else if ($action === 'reopen') {
                    $db->update(
                        'support_tickets',
                        [
                            'status' => 'In Progress',
                            'last_updated' => $update
                        ],
                        [
                            'id' => $ticket_id
                        ]
                    );
                    unset($_SESSION['current_ticket']);
                    $this->container->get('flash')->addMessage('success', 'Ticket has been reopened successfully');
                    return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
                } else {
                    $this->container->get('flash')->addMessage('error', 'Incorrect action specified');
                    return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
                }

            } catch (Exception $e) {
                $this->container->get('flash')->addMessage('error', 'Database error: '.$e->getMessage());
                return $response->withHeader('Location', '/ticket/'.$ticket_id)->withStatus(302);
            }
        }
    }

    public function docs(Request $request, Response $response)
    {
        $basePath = '/var/www/cp/resources/views/';
        $template = file_exists($basePath . 'admin/support/docs.custom.twig') 
                    ? 'admin/support/docs.custom.twig' 
                    : 'admin/support/docs.twig';
        return view($response, $template);
    }

    public function mediakit(Request $request, Response $response)
    {
        $basePath = '/var/www/cp/resources/views/';
        $template = file_exists($basePath . 'admin/support/mediakit.custom.twig') 
                    ? 'admin/support/mediakit.custom.twig' 
                    : 'admin/support/mediakit.twig';
        return view($response, $template);
    }
}