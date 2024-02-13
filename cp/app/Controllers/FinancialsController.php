<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Mpociot\VatCalculator\VatCalculator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

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
        $args = trim($args);

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
        $billing_vat = $db->selectValue('SELECT vat_number FROM registrar WHERE id = ?',
        [ $invoice_details['registrar_id'] ]
        );
        $company_name = $db->selectValue("SELECT value FROM settings WHERE name = 'company_name'");
        $address = $db->selectValue("SELECT value FROM settings WHERE name = 'address'");
        $address2 = $db->selectValue("SELECT value FROM settings WHERE name = 'address2'");
        $cc = $db->selectValue("SELECT value FROM settings WHERE name = 'cc'");
        $vat_number = $db->selectValue("SELECT value FROM settings WHERE name = 'vat_number'");
        $phone = $db->selectValue("SELECT value FROM settings WHERE name = 'phone'");
        $email = $db->selectValue("SELECT value FROM settings WHERE name = 'email'");
        
        $issueDate = new \DateTime($invoice_details['issue_date']);
        $firstDayPrevMonth = (clone $issueDate)->modify('first day of last month')->format('Y-m-d');
        $lastDayPrevMonth = (clone $issueDate)->modify('last day of last month')->format('Y-m-d');
        $statement = $db->select('SELECT * FROM statement WHERE date BETWEEN ? AND ? AND registrar_id = ?',
        [ $firstDayPrevMonth, $lastDayPrevMonth, $invoice_details['registrar_id'] ]
        );
        
        $vatCalculator = new VatCalculator();
        $vatCalculator->setBusinessCountryCode(strtoupper($cc));
        $grossPrice = $vatCalculator->calculate($invoice_details['total_amount'], strtoupper($billing['cc']));
        $taxRate = $vatCalculator->getTaxRate();
        $netPrice = $vatCalculator->getNetPrice(); 
        $taxValue = $vatCalculator->getTaxValue(); 
        if ($vatCalculator->shouldCollectVAT(strtoupper($billing['cc']))) {
            $validVAT = $vatCalculator->isValidVatNumberFormat($vat_number);
        } else {
            $validVAT = null;
        }
        $totalAmount = $grossPrice + $taxValue;

        return view($response,'admin/financials/viewInvoice.twig', [
            'invoice_details' => $invoice_details,
            'billing' => $billing,
            'billing_vat' => $billing_vat,
            'statement' => $statement,
            'company_name' => $company_name,
            'address' => $address,
            'address2' => $address2,
            'cc' => $cc,
            'vat_number' => $vat_number,
            'phone' => $phone,
            'email' => $email,
            'vatRate' => ($taxRate * 100) . "%",
            'vatAmount' => $taxValue,
            'validVAT' => $validVAT,
            'netPrice' => $netPrice,
            'total' => $totalAmount,
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
            $currency = $_SESSION['_currency'];
            $stripe_key = envi('STRIPE_PUBLISHABLE_KEY');

            return view($response,'admin/financials/deposit-registrar.twig', [
                'balance' => $balance,
                'currency' => $currency,
                'stripe_key' => $stripe_key
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
                    $this->container->get('flash')->addMessage('error', 'Database failure: '.$e->getMessage());
                    return $response->withHeader('Location', '/deposit')->withStatus(302);
                }
                
                $this->container->get('flash')->addMessage('success', 'Deposit successfully added. The registrar\'s account balance has been updated.');
                return $response->withHeader('Location', '/deposit')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'Invalid entry: Deposit amount must be positive. Please enter a valid amount.');
                return $response->withHeader('Location', '/deposit')->withStatus(302);
            }
        }
            
        $db = $this->container->get('db');
        $registrars = $db->select("SELECT id, clid, name FROM registrar");
        $currency = $_SESSION['_currency'];
    
        return view($response,'admin/financials/deposit.twig', [
            'registrars' => $registrars,
            'currency' => $currency
        ]);
    }
    
    public function createStripePayment(Request $request, Response $response)
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
    
    public function createAdyenPayment(Request $request, Response $response)
    {
        $postData = $request->getParsedBody();
        $amount = $postData['amount']; // Make sure to validate and sanitize this amount

        // Convert amount to cents
        $amountInCents = $amount * 100;

        $client = new \Adyen\Client();
        $client->setApplicationName('Namingo');
        $client->setEnvironment(\Adyen\Environment::TEST);
        $client->setXApiKey(envi('ADYEN_API_KEY'));
        $service = new \Adyen\Service\Checkout($client);
        $params = array(
           'amount' => array(
               'currency' => $_SESSION['_currency'],
               'value' => $amountInCents
           ),
           'merchantAccount' => envi('ADYEN_MERCHANT_ID'),
           'reference' => 'Registrar Balance Deposit',
           'returnUrl' => envi('APP_URL').'/payment-success-adyen',
           'mode' => 'hosted',
           'themeId' => envi('ADYEN_THEME_ID')
        );
        $result = $service->sessions($params);
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    public function successStripe(Request $request, Response $response)
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
                                'description' => 'registrar balance deposit via Stripe ('.$paymentIntentId.')',
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
                        $this->container->get('flash')->addMessage('error', 'Failure: '.$e->getMessage());
                        return $response->withHeader('Location', '/deposit')->withStatus(302);
                    }
                    
                    $this->container->get('flash')->addMessage('success', 'Deposit successfully added. The registrar\'s account balance has been updated.');
                    return $response->withHeader('Location', '/deposit')->withStatus(302);
                } else {
                    $this->container->get('flash')->addMessage('error', 'Invalid entry: Deposit amount must be positive. Please enter a valid amount.');
                    return $response->withHeader('Location', '/deposit')->withStatus(302);
                }
            } catch (\Exception $e) {
                $this->container->get('flash')->addMessage('error', 'We encountered an issue while processing your payment. Please check your payment details and try again.');
                return $response->withHeader('Location', '/deposit')->withStatus(302);
            }
        }
    }
    
    public function successAdyen(Request $request, Response $response)
    {
        $sessionId = $request->getQueryParams()['sessionId'] ?? null;
        $sessionResult = $request->getQueryParams()['sessionResult'] ?? null;
        $db = $this->container->get('db');

        $client = new Client([
            'base_uri' => envi('ADYEN_BASE_URI'),
            'timeout'  => 2.0,
        ]);

        try {
            $apicall = $client->request('GET', "sessions/$sessionId", [
                'query' => ['sessionResult' => $sessionResult],
                'headers' => [
                    'X-API-Key' => envi('ADYEN_API_KEY'),
                    'Content-Type' => 'application/json',
                ],
            ]);

            $data = json_decode($apicall->getBody(), true);

            $status = $data['status'] ?? 'unknown';
            if ($status == 'completed') {
                echo $status;
                $this->container->get('flash')->addMessage('success', 'Deposit successfully added. The registrar\'s account balance has been updated.');
                return $response->withHeader('Location', '/deposit')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'We encountered an issue while processing your payment. Please check your payment details and try again.');
                return $response->withHeader('Location', '/deposit')->withStatus(302);
            }

        } catch (RequestException $e) {
            $this->container->get('flash')->addMessage('error', 'Failure: '.$e->getMessage());
            return $response->withHeader('Location', '/deposit')->withStatus(302);
        }

    }
    
    public function cancel(Request $request, Response $response)
    {
        return view($response,'admin/financials/cancel.twig');
    }
}