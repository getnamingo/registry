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
    
    public function deposit(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
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
                            'from' => $date,
                            'to' => $date,
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
}