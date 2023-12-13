<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class FinancialsController extends Controller
{
    public function transactions(Request $request, Response $response)
    {
        return view($response,'admin/financials/transactions.twig');
    }
    
    public function overview(Request $request, Response $response)
    {
        return view($response,'admin/financials/overview.twig');
    }
    
    public function invoices(Request $request, Response $response)
    {
        return view($response,'admin/financials/invoices.twig');
    }
    
    public function viewInvoice(Request $request, Response $response, $args)
    {
        $invoiceNumberPattern = '/^[A-Za-z]+\d+-?\d+$/';

        if (preg_match($invoiceNumberPattern, $args)) {
            $invoiceNumber = $args; // valid format
        } else {
            $this->container->get('flash')->addMessage('error', 'Invalid invoice number');
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();
        $invoice_details = $db->selectRow('SELECT * FROM invoices WHERE invoice_number = ?',
        [ $invoiceNumber ]
        );
        $billing = $db->selectRow('SELECT * FROM registrar_contact WHERE id = ?',
        [ $invoice_details['billing_contact_id'] ]
        );
        $company_name = $db->selectValue("SELECT value FROM settings WHERE name = 'company_name'");
        $address = $db->selectValue("SELECT value FROM settings WHERE name = 'address'");
        $address2 = $db->selectValue("SELECT value FROM settings WHERE name = 'address2'");
        $phone = $db->selectValue("SELECT value FROM settings WHERE name = 'phone'");
        $email = $db->selectValue("SELECT value FROM settings WHERE name = 'email'");

        $issueDate = new \DateTime($invoice_details['issue_date']);
        $firstDayPrevMonth = (clone $issueDate)->modify('first day of last month')->format('Y-m-d');
        $lastDayPrevMonth = (clone $issueDate)->modify('last day of last month')->format('Y-m-d');
        $statement = $db->select('SELECT * FROM statement WHERE date BETWEEN ? AND ? AND registrar_id = ?',
        [ $firstDayPrevMonth, $lastDayPrevMonth, $invoice_details['registrar_id'] ]
        );

        return view($response,'admin/financials/viewInvoice.twig', [
            'invoice_details' => $invoice_details,
            'billing' => $billing,
            'statement' => $statement,
            'company_name' => $company_name,
            'address' => $address,
            'address2' => $address2,
            'phone' => $phone,
            'email' => $email,
            'currentUri' => $uri
        ]);

    }
    
    public function deposit(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            $db = $this->container->get('db');
            $balance = $db->selectRow('SELECT name, accountBalance, creditLimit FROM registrar WHERE id = ?',
            [ $_SESSION["auth_registrar_id"] ]
            );

            return view($response,'admin/financials/deposit-registrar.twig', [
                'balance' => $balance
            ]);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $registrar_id = $data['registrar'];
            $registrars = $db->select("SELECT id, clid, name FROM registrar");
            $amount = $data['amount'];
            $description = empty($data['description']) ? "Funds Added to Account Balance" : $data['description'];
            
            $isPositiveNumberWithTwoDecimals = filter_var($amount, FILTER_VALIDATE_FLOAT) !== false && preg_match('/^\d+(\.\d{1,2})?$/', $amount);

            if ($isPositiveNumberWithTwoDecimals) {
                $db->beginTransaction();

                try {
                    $currentDateTime = new \DateTime();
                    $date = $currentDateTime->format('Y-m-d H:i:s.v');
                    $db->insert(
                        'statement',
                        [
                            'registrar_id' => $registrar_id,
                            'date' => $date,
                            'command' => 'create',
                            'domain_name' => 'deposit',
                            'length_in_months' => 0,
                            'fromS' => $date,
                            'toS' => $date,
                            'amount' => $amount
                        ]
                    );

                    $db->insert(
                        'payment_history',
                        [
                            'registrar_id' => $registrar_id,
                            'date' => $date,
                            'description' => $description,
                            'amount' => $amount
                        ]
                    );
                    
                    $db->exec(
                        'UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?',
                        [
                            $amount,
                            $registrar_id
                        ]
                    );
                    
                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    return view($response, 'admin/financials/deposit.twig', [
                        'error' => $e->getMessage(),
                        'registrars' => $registrars
                    ]);
                }
                
                return view($response, 'admin/financials/deposit.twig', [
                    'deposit' => $amount,
                    'registrars' => $registrars
                ]);
            } else {
                return view($response, 'admin/financials/deposit.twig', [
                    'error' => 'Invalid entry: Deposit amount must be positive. Please enter a valid amount.',
                    'registrars' => $registrars
                ]);
            }
        }
            
        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
    
        return view($response,'admin/financials/deposit.twig', [
            'registrars' => $registrars
        ]);
    }
    
    public function createPayment(Request $request, Response $response)
    {
        $postData = $request->getParsedBody();
        $amount = $postData['amount']; // Make sure to validate and sanitize this amount

        // Set Stripe's secret key
        \Stripe\Stripe::setApiKey(envi('STRIPE_SECRET_KEY'));

        // Convert amount to cents (Stripe expects the amount in the smallest currency unit)
        $amountInCents = $amount * 100;

        // Create Stripe Checkout session
        $checkout_session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card', 'paypal'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $_SESSION['_currency'],
                    'product_data' => [
                        'name' => 'Registrar Balance Deposit',
                    ],
                    'unit_amount' => $amountInCents,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => envi('APP_URL').'/payment-success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => envi('APP_URL').'/payment-cancel',
        ]);

        // Return session ID to the frontend
        $response->getBody()->write(json_encode(['id' => $checkout_session->id]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    public function success(Request $request, Response $response)
    {
        $session_id = $request->getQueryParams()['session_id'] ?? null;
        $db = $this->container->get('db');
            
        if ($session_id) {
            \Stripe\Stripe::setApiKey(envi('STRIPE_SECRET_KEY'));

            try {
                $session = \Stripe\Checkout\Session::retrieve($session_id);
                $amountPaid = $session->amount_total; // Amount paid, in cents
                $amount = $amountPaid / 100;
                $amountPaidFormatted = number_format($amount, 2, '.', '');
                $paymentIntentId = $session->payment_intent;

                $isPositiveNumberWithTwoDecimals = filter_var($amount, FILTER_VALIDATE_FLOAT) !== false && preg_match('/^\d+(\.\d{1,2})?$/', $amount);

                if ($isPositiveNumberWithTwoDecimals) {
                    $db->beginTransaction();

                    try {
                        $currentDateTime = new \DateTime();
                        $date = $currentDateTime->format('Y-m-d H:i:s.v');
                        $db->insert(
                            'statement',
                            [
                                'registrar_id' => $_SESSION['auth_registrar_id'],
                                'date' => $date,
                                'command' => 'create',
                                'domain_name' => 'deposit',
                                'length_in_months' => 0,
                                'fromS' => $date,
                                'toS' => $date,
                                'amount' => $amount
                            ]
                        );

                        $db->insert(
                            'payment_history',
                            [
                                'registrar_id' => $_SESSION['auth_registrar_id'],
                                'date' => $date,
                                'description' => 'Registrar Balance Deposit via Stripe ('.$paymentIntentId.')',
                                'amount' => $amount
                            ]
                        );
                        
                        $db->exec(
                            'UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?',
                            [
                                $amount,
                                $_SESSION['auth_registrar_id'],
                            ]
                        );
                        
                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                        $balance = $db->selectRow('SELECT name, accountBalance, creditLimit FROM registrar WHERE id = ?',
                            [ $_SESSION["auth_registrar_id"] ]
                        );
                        
                        return view($response, 'admin/financials/deposit-registrar.twig', [
                            'error' => $e->getMessage(),
                            'balance' => $balance
                        ]);
                    }
                    
                    $balance = $db->selectRow('SELECT name, accountBalance, creditLimit FROM registrar WHERE id = ?',
                        [ $_SESSION["auth_registrar_id"] ]
                    );

                    return view($response, 'admin/financials/deposit-registrar.twig', [
                        'deposit' => $amount,
                        'balance' => $balance
                    ]);
                } else {
                    $balance = $db->selectRow('SELECT name, accountBalance, creditLimit FROM registrar WHERE id = ?',
                        [ $_SESSION["auth_registrar_id"] ]
                    );
                    
                    return view($response, 'admin/financials/deposit-registrar.twig', [
                        'error' => 'Invalid entry: Deposit amount must be positive. Please enter a valid amount.',
                        'balance' => $balance
                    ]);
                }
            } catch (\Exception $e) {
                $balance = $db->selectRow('SELECT name, accountBalance, creditLimit FROM registrar WHERE id = ?',
                    [ $_SESSION["auth_registrar_id"] ]
                );
                
                return view($response, 'admin/financials/deposit-registrar.twig', [
                    'error' => 'We encountered an issue while processing your payment. Please check your payment details and try again.',
                     'balance' => $balance
                ]);
            }
        }
    }
    
    public function cancel(Request $request, Response $response)
    {
        return view($response,'admin/financials/cancel.twig');
    }
}