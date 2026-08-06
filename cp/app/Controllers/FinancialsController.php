<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Mpociot\VatCalculator\VatCalculator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Ramsey\Uuid\Uuid;
use League\ISO3166\ISO3166;

class FinancialsController extends Controller
{
    private const PAYMENT_GATEWAYS = [
        'stripe' => [
            'create' => 'createStripePayment',
            'verify' => 'verifyStripePayment',
        ],
        'liqpay' => [
            'create' => 'createLiqPayPayment',
            'verify' => 'verifyLiqPayPayment',
        ],
        'plata' => [
            'create' => 'createPlataPayment',
            'verify' => 'verifyPlataPayment',
        ],
        'adyen' => [
            'create' => 'createAdyenPayment',
            'verify' => 'verifyAdyenPayment',
        ],
        'now' => [
            'create' => 'createNowPayment',
            'verify' => 'verifyNowPayment',
        ],
        'nicky' => [
            'create' => 'createNickyPayment',
            'verify' => 'verifyNickyPayment',
        ],
    ];

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

        $iso3166 = new ISO3166();
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();
        $invoice_details = $db->selectRow('SELECT * FROM invoices WHERE invoice_number = ?',
        [ $invoiceNumber ]
        );

        if (!$invoice_details) {
            $this->container->get('flash')->addMessage('error', 'Invoice not found');
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $billing = $db->selectRow('SELECT * FROM registrar_contact WHERE id = ?',
        [ $invoice_details['billing_contact_id'] ]
        );
        $companyNumberColumn = envi('DB_DRIVER') === 'pgsql' ? '"companyNumber"' : 'companyNumber';
        $vatNumberColumn = envi('DB_DRIVER') === 'pgsql' ? '"vatNumber"' : 'vatNumber';
        $billing_company = $db->selectValue("SELECT $companyNumberColumn FROM registrar WHERE id = ?",
        [ $invoice_details['registrar_id'] ]
        );
        $currency = $db->selectValue('SELECT currency FROM registrar WHERE id = ?',
        [ $invoice_details['registrar_id'] ]
        );
        $billing_vat = $db->selectValue("SELECT $vatNumberColumn FROM registrar WHERE id = ?",
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
        
        $refunds = $db->select("
            SELECT 
                date,
                description,
                amount * -1 AS amount -- negate the refund to show as negative
            FROM payment_history
            WHERE registrar_id = ?
              AND date BETWEEN ? AND ?
              AND description LIKE '%provides a credit%'
        ", [
            $invoice_details['registrar_id'],
            $firstDayPrevMonth,
            $lastDayPrevMonth
        ]);

        foreach (($refunds ?? []) as &$r) {
            $r['domain_name'] = '(refund)';
            $r['command'] = 'REFUND';
            $r['type'] = 'credit';

            if (preg_match('/domain ([a-z0-9.-]+\.[a-z]{2,})/i', $r['description'], $matchDomain)) {
                $r['domain_name'] = $matchDomain[1];
            }

            if (preg_match('/provides a credit (.*)$/i', $r['description'], $matchReason)) {
                $r['reason'] = trim($matchReason[1]);
            } else {
                $r['reason'] = $r['description']; // fallback
            }
        }
        unset($r);

        $allTransactions = array_merge((array) $statement, (array) $refunds);
        usort($allTransactions, fn($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));

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
        $billing_country = $iso3166->alpha2($billing['cc']);
        $billing_country = $billing_country['name'];

        $locale = $_SESSION['_lang'] ?? 'en_US'; // Fallback to 'en_US' if no locale is set

        // Initialize the number formatter for the locale
        $numberFormatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $currencyFormatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);

        // Format values explicitly with the session currency
        $formattedVatRate = $numberFormatter->format($taxRate * 100) . "%";
        $formattedVatAmount = $currencyFormatter->formatCurrency($taxValue, $currency);
        $formattedNetPrice = $currencyFormatter->formatCurrency($netPrice, $currency);
        $formattedTotalAmount = $currencyFormatter->formatCurrency($totalAmount, $currency);

        // Pass formatted values to Twig
        return view($response, 'admin/financials/viewInvoice.twig', [
            'invoice_details' => $invoice_details,
            'billing' => $billing,
            'billing_company' => $billing_company,
            'billing_vat' => $billing_vat,
            'statement' => $allTransactions,
            'company_name' => $company_name,
            'address' => $address,
            'address2' => $address2,
            'cc' => $cc,
            'vat_number' => $vat_number,
            'phone' => $phone,
            'email' => $email,
            'vatRate' => $formattedVatRate,
            'vatAmount' => $formattedVatAmount,
            'validVAT' => $validVAT,
            'netPrice' => $formattedNetPrice,
            'total' => $formattedTotalAmount,
            'currentUri' => $uri,
            'billing_country' => $billing_country,
        ]);

    }
    
    public function deposit(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            $db = $this->container->get('db');
            $balance = $db->selectRow('SELECT name, accountBalance AS "accountBalance", creditLimit AS "creditLimit" FROM registrar WHERE id = ?',
            [ $_SESSION["auth_registrar_id"] ]
            );
            $currency = $_SESSION['_currency'];
            $enabledGateways = array_map('trim', explode(',', envi('ENABLED_GATEWAYS')));

            return view($response,'admin/financials/deposit-registrar.twig', [
                'balance' => $balance,
                'currency' => $currency,
                'enabledGateways' => $enabledGateways,
            ]);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $registrar_id = $data['registrar'];
            $amount = $data['amount'];
            $description = "funds added to account balance";
            if (!empty($data['description'])) {
                $description .= " (" . $data['description'] . ")";
            }

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

        return view($response,'admin/financials/deposit.twig', [
            'registrars' => $registrars
        ]);
    }

    public function createPayment(Request $request,    Response $response,    string $gateway): Response {
        $gateway = strtolower(trim($gateway));
        $definition = self::PAYMENT_GATEWAYS[$gateway] ?? null;

        if (!$definition || !$definition['create']) {
            $response->getBody()->write(json_encode([
                'error' => 'Unknown payment gateway.',
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $enabledGateways = array_values(array_filter(array_map(
            static fn(string $value): string => strtolower(trim($value)),
            explode(',', (string) envi('ENABLED_GATEWAYS'))
        )));

        if (!in_array($gateway, $enabledGateways, true)) {
            $response->getBody()->write(json_encode([
                'error' => 'This payment gateway is not enabled.',
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $handler = $definition['create'];

        return $this->{$handler}($request, $response);
    }

    public function paymentReturn(Request $request,    Response $response,    string $gateway): Response {
        $gateway = strtolower(trim($gateway));
        $definition = self::PAYMENT_GATEWAYS[$gateway] ?? null;
        $flash = $this->container->get('flash');
        $redirectUrl = '/deposit';
        $stage = 'verification';

        if (!$definition || !$definition['verify']) {
            $flash->addMessage('error', 'Unknown payment gateway.');

            return $response->withHeader('Location', '/deposit')->withStatus(302);
        }

        try {
            $handler = $definition['verify'];

            /*
             * The provider method verifies the returned data with the provider
             * and returns the normalized payment array documented below.
             */
            $payment = $this->{$handler}($request, 'return');

            if (!is_array($payment) || empty($payment['status'])) {
                throw new \RuntimeException(
                    'The payment gateway returned an invalid result.'
                );
            }

            // Never trust a gateway name returned by request/provider data.
            $payment['gateway'] = $gateway;

            $invoiceId = isset($payment['invoice_id'])
                ? (int) $payment['invoice_id']
                : null;

            $redirectUrl = $payment['redirect_url']
                ?? ($invoiceId ? "/invoice/{$invoiceId}" : '/deposit');

            // Prevent a provider result from becoming an open redirect.
            if (!is_string($redirectUrl) || !str_starts_with($redirectUrl, '/')) {
                $redirectUrl = '/deposit';
            }

            switch ($payment['status']) {
                case 'paid':
                    /*
                     * This must be idempotent because both the browser return
                     * and provider webhook may report the same payment.
                     */
                    $stage = 'settlement';
                    $this->processPaidPayment($payment);

                    $message = ($payment['type'] ?? null) === 'invoice'
                        ? 'Invoice payment received successfully.'
                        : 'Deposit received successfully.';

                    $flash->addMessage('success', $message);
                    break;

                case 'pending':
                    $flash->addMessage('warning', 'Your payment is still processing.');
                    break;

                case 'failed':
                case 'cancelled':
                    $flash->addMessage('error',    'The payment was not completed.');
                    break;

                default:
                    $flash->addMessage('warning', 'The payment status could not be determined.');
                    break;
            }

            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        } catch (\Throwable $e) {
            $db = $this->container->get('db');
            $db->insert('error_log', [
                'channel' => 'payment',
                'level' => 400,
                'level_name' => 'ERROR',
                'message' => "Payment return {$stage} failed for {$gateway}",
                'context' => json_encode([
                    'stage' => $stage,
                    'gateway' => $gateway,
                    'exception' => [
                        'class' => get_class($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'extra' => json_encode([], JSON_FORCE_OBJECT),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $flash->addMessage('error',    'We could not verify your payment. Please contact support if the payment was charged.');

            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        }
    }

    public function paymentWebhook(Request $request, Response $response, string $gateway): Response {
        $gateway = strtolower(trim($gateway));
        $definition = self::PAYMENT_GATEWAYS[$gateway] ?? null;

        if (!$definition || !$definition['verify']) {
            $response->getBody()->write(json_encode([
                'error' => 'Unknown payment gateway.',
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        try {
            $handler = $definition['verify'];

            /*
             * The verifier must validate the provider signature/HMAC before
             * returning any payment data.
             */
            $payment = $this->{$handler}($request, 'webhook');

            /*
             * Null means a valid but irrelevant event, such as a notification
             * unrelated to a successful payment.
             */
            if ($payment === null) {
                $response->getBody()->write(json_encode([
                    'received' => true,
                    'ignored' => true,
                ]));

                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            }

            if (!is_array($payment) || empty($payment['status'])) {
                throw new \RuntimeException(
                    'The payment gateway returned an invalid webhook result.'
                );
            }

            $payment['gateway'] = $gateway;

            if ($payment['status'] === 'paid') {
                // Safe even if called earlier by paymentReturn().
                $this->processPaidPayment($payment);
            }

            $response->getBody()->write(json_encode([
                'received' => true,
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\InvalidArgumentException $e) {
            // Used by provider verifiers for an invalid signature or payload.
            $response->getBody()->write(json_encode([
                'error' => 'Invalid webhook.',
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        } catch (\Throwable $e) {
            $db = $this->container->get('db');
            $db->insert('error_log', [
                'channel' => 'payment',
                'level' => 400,
                'level_name' => 'ERROR',
                'message' => "Payment webhook processing failed for {$gateway}",
                'context' => json_encode([
                    'gateway' => $gateway,
                    'exception' => [
                        'class' => get_class($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'extra' => json_encode([], JSON_FORCE_OBJECT),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Non-2xx response tells the gateway to retry the webhook.
            $response->getBody()->write(json_encode([
                'error' => 'Webhook processing failed.',
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    private function createStripePayment(Request $request, Response $response): Response
    {
        $context = $this->paymentContextFromRequest($request, 'stripe');
        $stripe = new \Stripe\StripeClient((string)envi('STRIPE_SECRET_KEY'));

        $session = $stripe->checkout->sessions->create([
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($context['currency']),
                    'product_data' => ['name' => $context['description']],
                    'unit_amount' => $context['amount_minor'],
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $this->appUrl(
                '/payment/stripe/return?session_id={CHECKOUT_SESSION_ID}'
            ),
            'cancel_url' => $this->cancelUrl($context),
            'metadata' => ['payment_context' => $context['token']],
            'payment_intent_data' => [
                'metadata' => ['payment_context' => $context['token']],
            ],
        ]);

        if (empty($session->url)) {
            throw new \RuntimeException('Stripe did not return a checkout URL.');
        }

        $this->clearPendingInvoice($context);

        return $this->jsonResponse($response, ['url' => (string)$session->url]);
    }

    private function verifyStripePayment(Request $request, string $channel): array
    {
        if ($channel === 'webhook') {
            $secret = trim((string)envi('STRIPE_WEBHOOK_SECRET'));
            $signature = $request->getHeaderLine('Stripe-Signature');
            if ($secret === '' || $signature === '') {
                throw new \InvalidArgumentException('Stripe webhook signing is not configured.');
            }

            try {
                $event = \Stripe\Webhook::constructEvent(
                    (string)$request->getBody(),
                    $signature,
                    $secret
                );
            } catch (\UnexpectedValueException | \Stripe\Exception\SignatureVerificationException $exception) {
                throw new \InvalidArgumentException('Invalid Stripe signature.', 0, $exception);
            }

            if (!in_array($event->type, [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.async_payment_failed',
                'checkout.session.expired',
            ], true)) {
                return [];
            }

            $session = $event->data->object;
            $status = match ($event->type) {
                'checkout.session.async_payment_failed' => 'failed',
                'checkout.session.expired' => 'cancelled',
                default => in_array(
                    (string)($session->payment_status ?? ''),
                    ['paid', 'no_payment_required'],
                    true
                ) ? 'paid' : 'pending',
            };
        } else {
            $sessionId = trim((string)($request->getQueryParams()['session_id'] ?? ''));
            if ($sessionId === '') {
                throw new \InvalidArgumentException('Missing Stripe session ID.');
            }

            $stripe = new \Stripe\StripeClient((string)envi('STRIPE_SECRET_KEY'));
            $session = $stripe->checkout->sessions->retrieve($sessionId, []);
            $status = in_array(
                (string)($session->payment_status ?? ''),
                ['paid', 'no_payment_required'],
                true
            ) ? 'paid' : 'pending';
        }

        $token = trim((string)($session->metadata->payment_context ?? ''));
        $reference = trim((string)($session->payment_intent ?? $session->id ?? ''));
        $amountMinor = $session->amount_total ?? null;
        $currency = strtoupper((string)($session->currency ?? ''));

        if ($status !== 'paid' && ($amountMinor === null || $currency === '')) {
            return $this->pendingPayment('stripe', $token, $reference, $status);
        }

        return $this->verifiedPayment(
            'stripe',
            $reference,
            $status,
            $this->amountFromMinor($amountMinor),
            $currency,
            $token
        );
    }

    private function createLiqPayPayment(Request $request, Response $response): Response
    {
        $context = $this->paymentContextFromRequest($request, 'liqpay');
        $liqpay = new LiqPay(
            (string)envi('LIQPAY_PUBLIC_KEY'),
            (string)envi('LIQPAY_PRIVATE_KEY')
        );
        $language = strtolower(substr((string)($_SESSION['_lang'] ?? 'en'), 0, 2));

        $raw = $liqpay->cnb_form_raw([
            'version' => 3,
            'action' => 'pay',
            'amount' => (float)$context['amount'],
            'currency' => $context['currency'],
            'description' => $context['description'],
            'language' => in_array($language, ['uk', 'en'], true) ? $language : 'en',
            'order_id' => $context['token'],
            'result_url' => $this->appUrl(
                '/payment/liqpay/return?order_id=' . rawurlencode($context['token'])
            ),
            'server_url' => $this->appUrl('/payment/liqpay/webhook'),
        ]);

        if (!is_array($raw) || empty($raw['url']) || empty($raw['data']) || empty($raw['signature'])) {
            throw new \RuntimeException('LiqPay did not return checkout data.');
        }

        $this->clearPendingInvoice($context);

        return $this->view->render(
            $response,
            'admin/financials/liqpay_post_bridge.twig',
            ['raw' => $raw]
        );
    }

    private function verifyLiqPayPayment(Request $request, string $channel): array
    {
        if ($channel === 'webhook') {
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                parse_str((string)$request->getBody(), $body);
            }

            $encoded = trim((string)($body['data'] ?? ''));
            $signature = trim((string)($body['signature'] ?? ''));
            $privateKey = (string)envi('LIQPAY_PRIVATE_KEY');
            $expected = base64_encode(sha1($privateKey . $encoded . $privateKey, true));

            if ($encoded === '' || $signature === '' || !hash_equals($expected, $signature)) {
                throw new \InvalidArgumentException('Invalid LiqPay signature.');
            }

            $decoded = base64_decode($encoded, true);
            $data = $decoded === false ? null : json_decode($decoded, true);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Invalid LiqPay payload.');
            }
        } else {
            $orderId = trim((string)($request->getQueryParams()['order_id'] ?? ''));
            if ($orderId === '') {
                throw new \InvalidArgumentException('Missing LiqPay order ID.');
            }

            $liqpay = new LiqPay(
                (string)envi('LIQPAY_PUBLIC_KEY'),
                (string)envi('LIQPAY_PRIVATE_KEY')
            );
            $result = $liqpay->api('payment/status', [
                'version' => 3,
                'action' => 'status',
                'order_id' => $orderId,
            ]);
            $data = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }

        $providerStatus = strtolower((string)($data['status'] ?? ''));
        $status = match ($providerStatus) {
            'success', 'sandbox' => 'paid',
            'failure', 'error' => 'failed',
            'reversed', 'unsubscribed' => 'cancelled',
            default => 'pending',
        };
        $token = trim((string)($data['order_id'] ?? ''));
        $reference = trim((string)(
            $data['payment_id']
            ?? $data['transaction_id']
            ?? $data['liqpay_order_id']
            ?? $token
        ));

        return $this->verifiedPayment(
            'liqpay',
            $reference,
            $status,
            $data['amount'] ?? '',
            (string)($data['currency'] ?? ''),
            $token
        );
    }

    private function createPlataPayment(Request $request, Response $response): Response
    {
        $context = $this->paymentContextFromRequest($request, 'plata');
        if ($context['currency'] !== 'UAH') {
            throw new \InvalidArgumentException('Plata supports UAH payments only.');
        }

        $payload = [
            'amount' => $context['amount_minor'],
            'ccy' => 980,
            'merchantPaymInfo' => [
                'reference' => $context['token'],
                'destination' => $context['description'],
                'comment' => $context['description'],
                'basketOrder' => [[
                    'name' => $context['description'],
                    'qty' => 1,
                    'sum' => $context['amount_minor'],
                    'total' => $context['amount_minor'],
                    'unit' => 'шт.',
                    'code' => (string)($context['invoice_id'] ?? 0),
                ]],
            ],
            'redirectUrl' => $this->appUrl(
                '/payment/plata/return?context=' . rawurlencode($context['token'])
            ),
            'webHookUrl' => $this->appUrl('/payment/plata/webhook'),
            'validity' => 86400,
            'paymentType' => 'debit',
        ];

        $apiResponse = (new Client(['timeout' => 15]))->request(
            'POST',
            'https://api.monobank.ua/api/merchant/invoice/create',
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Token' => (string)envi('PLATA_TOKEN'),
                ],
                'json' => $payload,
            ]
        );
        $data = json_decode(
            $apiResponse->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (empty($data['invoiceId']) || empty($data['pageUrl'])) {
            throw new \RuntimeException('Plata did not return an invoice ID and checkout URL.');
        }

        $this->clearPendingInvoice($context);

        return $this->jsonResponse($response, [
            'url' => (string)$data['pageUrl'],
            'invoice_id' => (string)$data['invoiceId'],
        ]);
    }

    private function verifyPlataPayment(Request $request, string $channel): array
    {
        $query = $request->getQueryParams();
        $contextToken = trim((string)($query['context'] ?? ''));
        $invoiceId = trim((string)(
            $query['invoiceId']
            ?? $query['invoice_id']
            ?? ''
        ));

        if ($channel === 'webhook') {
            $payload = json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \InvalidArgumentException('Invalid Plata webhook payload.');
            }
            $invoiceId = trim((string)($payload['invoiceId'] ?? ''));
        }

        if ($invoiceId === '') {
            if ($contextToken !== '') {
                return $this->pendingPayment('plata', $contextToken);
            }
            throw new \InvalidArgumentException('Missing Plata invoice ID.');
        }

        $data = $this->plataFetchStatus($invoiceId);
        $token = trim((string)(
            $data['reference']
            ?? $data['merchantPaymInfo']['reference']
            ?? ''
        ));
        if ($channel === 'return' && $contextToken !== '' && !hash_equals($contextToken, $token)) {
            throw new \InvalidArgumentException('Plata payment context does not match.');
        }
        $providerStatus = strtolower((string)($data['status'] ?? ''));
        $status = match ($providerStatus) {
            'success' => 'paid',
            'failure' => 'failed',
            'expired', 'reversed' => 'cancelled',
            default => 'pending',
        };

        $currency = match ((int)($data['ccy'] ?? 0)) {
            980 => 'UAH',
            default => throw new \InvalidArgumentException('Unsupported Plata currency.'),
        };

        return $this->verifiedPayment(
            'plata',
            (string)($data['invoiceId'] ?? $invoiceId),
            $status,
            $this->amountFromMinor($data['amount'] ?? ''),
            $currency,
            $token
        );
    }

    private function plataFetchStatus(string $invoiceId): array
    {
        $apiResponse = (new Client(['timeout' => 15]))->request(
            'GET',
            'https://api.monobank.ua/api/merchant/invoice/status',
            [
                'headers' => ['X-Token' => (string)envi('PLATA_TOKEN')],
                'query' => ['invoiceId' => $invoiceId],
            ]
        );
        $data = json_decode(
            $apiResponse->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($data) || empty($data['invoiceId'])) {
            throw new \RuntimeException('Plata returned an invalid status response.');
        }

        return $data;
    }

    private function createAdyenPayment(Request $request, Response $response): Response
    {
        $context = $this->paymentContextFromRequest($request, 'adyen');
        $apiResponse = (new Client([
            'base_uri' => rtrim((string)envi('ADYEN_BASE_URI'), '/') . '/',
            'timeout' => 15,
        ]))->request('POST', 'sessions', [
            'headers' => [
                'X-API-Key' => (string)envi('ADYEN_API_KEY'),
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'amount' => [
                    'currency' => $context['currency'],
                    'value' => $context['amount_minor'],
                ],
                'merchantAccount' => (string)envi('ADYEN_MERCHANT_ID'),
                'reference' => $context['token'],
                'returnUrl' => $this->appUrl('/payment/adyen/return'),
                'mode' => 'hosted',
                'themeId' => (string)envi('ADYEN_THEME_ID'),
            ],
        ]);
        $data = json_decode(
            $apiResponse->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (empty($data['url'])) {
            throw new \RuntimeException('Adyen did not return a checkout URL.');
        }

        $this->clearPendingInvoice($context);

        return $this->jsonResponse($response, ['url' => (string)$data['url']]);
    }

    private function verifyAdyenPayment(Request $request, string $channel): array
    {
        if ($channel === 'webhook') {
            $this->verifyAdyenBasicAuth($request);
            $data = json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Invalid Adyen webhook payload.');
            }
            $hmac = new \Adyen\Util\HmacSignature();
            $hmacKey = trim((string)envi('ADYEN_HMAC_KEY'));
            $payments = [];
            $items = $data['notificationItems'] ?? null;
            if (!is_array($items) || $items === []) {
                throw new \InvalidArgumentException('Invalid Adyen webhook payload.');
            }

            foreach ($items as $wrapper) {
                $item = $wrapper['NotificationRequestItem'] ?? null;
                if (
                    !is_array($item)
                    || empty($item['additionalData']['hmacSignature'])
                    || !$hmac->isValidNotificationHMAC($hmacKey, $item)
                ) {
                    throw new \InvalidArgumentException('Invalid Adyen HMAC signature.');
                }
                if (($item['eventCode'] ?? '') !== 'AUTHORISATION') {
                    continue;
                }

                $payments[] = $this->verifiedPayment(
                    'adyen',
                    (string)($item['pspReference'] ?? ''),
                    ($item['success'] ?? 'false') === 'true' ? 'paid' : 'failed',
                    $this->amountFromMinor($item['amount']['value'] ?? ''),
                    (string)($item['amount']['currency'] ?? ''),
                    (string)($item['merchantReference'] ?? '')
                );
            }

            return $payments;
        }

        $query = $request->getQueryParams();
        $sessionId = trim((string)($query['sessionId'] ?? ''));
        $sessionResult = trim((string)($query['sessionResult'] ?? ''));
        if ($sessionId === '' || $sessionResult === '') {
            throw new \InvalidArgumentException('Missing Adyen session result.');
        }

        $apiResponse = (new Client([
            'base_uri' => rtrim((string)envi('ADYEN_BASE_URI'), '/') . '/',
            'timeout' => 15,
        ]))->request('GET', 'sessions/' . rawurlencode($sessionId), [
            'query' => ['sessionResult' => $sessionResult],
            'headers' => ['X-API-Key' => (string)envi('ADYEN_API_KEY')],
        ]);
        $data = json_decode(
            $apiResponse->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $providerStatus = strtolower((string)($data['status'] ?? ''));
        $payment = $data['payments'][0] ?? [];
        $resultCode = strtolower((string)($payment['resultCode'] ?? ''));
        $status = match (true) {
            $providerStatus === 'completed' && $resultCode === 'authorised' => 'paid',
            $providerStatus === 'completed' => 'failed',
            $providerStatus === 'refused' => 'failed',
            in_array($providerStatus, ['canceled', 'expired'], true) => 'cancelled',
            default => 'pending',
        };
        $token = (string)($data['reference'] ?? $payment['reference'] ?? '');
        $reference = (string)($payment['pspReference'] ?? $sessionId);

        if ($status !== 'paid' && empty($payment['amount'])) {
            return $this->pendingPayment('adyen', $token, $reference, $status);
        }

        return $this->verifiedPayment(
            'adyen',
            $reference,
            $status,
            $this->amountFromMinor($payment['amount']['value'] ?? ''),
            (string)($payment['amount']['currency'] ?? ''),
            $token
        );
    }

    private function verifyAdyenBasicAuth(Request $request): void
    {
        $username = trim((string)envi('ADYEN_BASIC_AUTH_USER'));
        $password = (string)envi('ADYEN_BASIC_AUTH_PASS');
        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException('Adyen webhook authentication is not configured.');
        }

        $authorization = $request->getHeaderLine('Authorization');
        $expected = 'Basic ' . base64_encode($username . ':' . $password);

        if ($authorization === '' || !hash_equals($expected, $authorization)) {
            throw new \InvalidArgumentException('Invalid Adyen webhook credentials.');
        }
    }

    private function createNowPayment(Request $request, Response $response): Response
    {
        $context = $this->paymentContextFromRequest($request, 'now');
        $data = [
            'price_amount' => (float)$context['amount'],
            'price_currency' => strtolower($context['currency']),
            'order_id' => $context['token'],
            'order_description' => $context['description'],
            'ipn_callback_url' => $this->appUrl('/payment/now/webhook'),
            'success_url' => $this->appUrl(
                '/payment/now/return?context=' . rawurlencode($context['token'])
            ),
            'cancel_url' => $this->cancelUrl($context),
        ];

        $apiResponse = (new Client(['timeout' => 15]))->request(
            'POST',
            'https://api.nowpayments.io/v1/invoice',
            [
                'headers' => [
                    'x-api-key' => (string)envi('NOW_API_KEY'),
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
            ]
        );
        $result = json_decode(
            $apiResponse->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $url = trim((string)($result['invoice_url'] ?? ''));

        if ($url === '') {
            throw new \RuntimeException('NOWPayments did not return a checkout URL.');
        }

        $this->clearPendingInvoice($context);

        return $this->jsonResponse($response, ['url' => $url]);
    }

    private function verifyNowPayment(Request $request, string $channel): array
    {
        if ($channel === 'webhook') {
            $raw = (string)$request->getBody();
            $signature = strtolower(trim($request->getHeaderLine('x-nowpayments-sig')));
            $secret = trim((string)envi('NOW_IPN_SECRET'));
            if ($secret === '' || $signature === '') {
                throw new \InvalidArgumentException('NOWPayments IPN signing is not configured.');
            }

            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Invalid NOWPayments payload.');
            }
            $signedData = $this->sortKeysRecursively($data);
            $canonical = json_encode(
                $signedData,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $expected = hash_hmac('sha512', $canonical, $secret);
            if (!hash_equals($expected, $signature)) {
                throw new \InvalidArgumentException('Invalid NOWPayments signature.');
            }
        } else {
            $query = $request->getQueryParams();
            $paymentId = trim((string)($query['paymentId'] ?? $query['payment_id'] ?? ''));
            $contextToken = trim((string)($query['context'] ?? ''));

            if ($paymentId === '') {
                if ($contextToken !== '') {
                    return $this->pendingPayment('now', $contextToken);
                }
                throw new \InvalidArgumentException('Missing NOWPayments payment ID.');
            }

            $apiResponse = (new Client(['timeout' => 15]))->request(
                'GET',
                'https://api.nowpayments.io/v1/payment/' . rawurlencode($paymentId),
                ['headers' => ['x-api-key' => (string)envi('NOW_API_KEY')]]
            );
            $data = json_decode(
                $apiResponse->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        $providerStatus = strtolower((string)($data['payment_status'] ?? ''));
        $status = match ($providerStatus) {
            'finished' => 'paid',
            'failed', 'refunded' => 'failed',
            'expired' => 'cancelled',
            default => 'pending',
        };

        return $this->verifiedPayment(
            'now',
            (string)($data['payment_id'] ?? ''),
            $status,
            $data['price_amount'] ?? '',
            (string)($data['price_currency'] ?? ''),
            (string)($data['order_id'] ?? '')
        );
    }

    private function createNickyPayment(Request $request, Response $response): Response
    {
        $context = $this->paymentContextFromRequest($request, 'nicky');
        $asset = match ($context['currency']) {
            'USD' => 'USD.USD',
            'EUR' => 'EUR.EUR',
            default => throw new \InvalidArgumentException(
                'Nicky supports USD and EUR payments only.'
            ),
        };

        $data = [
            'blockchainAssetId' => $asset,
            'amountExpectedNative' => (float)$context['amount'],
            'billDetails' => [
                'invoiceReference' => strtoupper(bin2hex(random_bytes(5))),
                'description' => $context['description'] . ' [ctx:' . $context['token'] . ']',
            ],
            'requester' => [
                'email' => $context['email'],
                'name' => $context['username'],
            ],
            'sendNotification' => true,
            'successUrl' => $this->appUrl(
                '/payment/nicky/return?context=' . rawurlencode($context['token'])
            ),
            'cancelUrl' => $this->cancelUrl($context),
        ];

        $apiResponse = (new Client(['timeout' => 15]))->request(
            'POST',
            'https://api-public.pay.nicky.me/api/public/PaymentRequestPublicApi/create',
            [
                'headers' => [
                    'x-api-key' => (string)envi('NICKY_API_KEY'),
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
            ]
        );
        $result = json_decode(
            $apiResponse->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $shortId = trim((string)($result['bill']['shortId'] ?? ''));

        if ($shortId === '') {
            throw new \RuntimeException('Nicky did not return a payment ID.');
        }

        $_SESSION['nicky_short_id'] = $shortId;
        $this->clearPendingInvoice($context);

        return $this->jsonResponse($response, [
            'url' => 'https://pay.nicky.me/home?paymentId=' . rawurlencode($shortId),
        ]);
    }

    private function verifyNickyPayment(Request $request, string $channel): array
    {
        if ($channel === 'webhook') {
            throw new \InvalidArgumentException(
                'Nicky webhook verification is unavailable for this API integration.'
            );
        }

        $query = $request->getQueryParams();
        $queryToken = trim((string)($query['context'] ?? ''));
        $shortId = trim((string)(
            $query['shortId']
            ?? $query['paymentId']
            ?? $_SESSION['nicky_short_id']
            ?? ''
        ));
        unset($_SESSION['nicky_short_id']);

        if ($shortId === '') {
            if ($queryToken !== '') {
                return $this->pendingPayment('nicky', $queryToken);
            }
            throw new \InvalidArgumentException('Missing Nicky payment ID.');
        }

        $apiResponse = (new Client(['timeout' => 15]))->request(
            'GET',
            'https://api-public.pay.nicky.me/api/public/PaymentRequestPublicApi/get-by-short-id',
            [
                'headers' => [
                    'x-api-key' => (string)envi('NICKY_API_KEY'),
                    'Content-Type' => 'application/json',
                ],
                'query' => ['shortId' => $shortId],
            ]
        );
        $data = json_decode(
            $apiResponse->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $description = (string)($data['bill']['description'] ?? '');
        preg_match('/\[ctx:([A-Za-z0-9._-]+)\]/', $description, $matches);
        $token = (string)($matches[1] ?? '');
        if ($queryToken !== '' && $token !== '' && !hash_equals($queryToken, $token)) {
            throw new \InvalidArgumentException('Nicky payment context does not match.');
        }
        $providerStatus = strtolower((string)($data['status'] ?? ''));
        $status = match ($providerStatus) {
            'finished' => 'paid',
            'none', 'paymentvalidationrequired', 'paymentpending' => 'pending',
            default => 'failed',
        };

        if ($status !== 'paid' && $token === '') {
            return $this->pendingPayment(
                'nicky',
                $queryToken,
                (string)($data['id'] ?? $shortId),
                $status
            );
        }

        $asset = (string)(
            $data['blockchainAssetId']
            ?? $data['bill']['blockchainAssetId']
            ?? ''
        );
        $currency = $asset !== '' ? strtoupper(explode('.', $asset)[0]) : '';
        if ($currency === '') {
            $currency = $this->decodePaymentContext('nicky', $token)['currency'];
        }

        return $this->verifiedPayment(
            'nicky',
            (string)($data['id'] ?? $shortId),
            $status,
            $data['amountNative'] ?? '',
            $currency,
            $token
        );
    }

    private function paymentContextFromRequest(Request $request, string $gateway): array
    {
        $registrarId = filter_var(
            $_SESSION['auth_registrar_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($registrarId === false) {
            throw new \InvalidArgumentException('You must be signed in to create a payment.');
        }

        $db = $this->container->get('db');
        $user = $db->selectRow(
            'SELECT id, email, username FROM users WHERE id = ? LIMIT 1',
            [$registrarId]
        );
        if (!$user) {
            throw new \InvalidArgumentException('Payment user was not found.');
        }

        $currency = strtoupper(trim((string)$_SESSION['_currency']));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('The account currency is invalid.');
        }

        $post = $request->getParsedBody();
        $post = is_array($post) ? $post : [];
        $postedAmount = trim((string)($post['amount'] ?? ''));

        $type = 'deposit';
        $invoiceId = null;
        $amount = $this->normalizeAmount($postedAmount);
        $description = 'Account balance deposit';

        $amountMinor = $this->amountToMinor($amount);
        $token = $this->encodePaymentContext($gateway, [
            'user_id' => $registrarId,
            'type' => $type,
            'invoice_id' => $invoiceId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
        ]);

        return [
            'token' => $token,
            'user_id' => $registrarId,
            'type' => $type,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'description' => $description,
            'email' => (string)($user['email'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
        ];
    }

    private function encodePaymentContext(string $gateway, array $context): string
    {
        $payload = implode('.', [
            '1',
            $this->base36Encode((int)$context['user_id']),
            $context['type'] === 'invoice' ? 'i' : 'd',
            $this->base36Encode((int)($context['invoice_id'] ?? 0)),
            $this->base36Encode((int)$context['amount_minor']),
            strtoupper((string)$context['currency']),
            $this->base64UrlEncode(random_bytes(8)),
        ]);
        $signature = $this->base64UrlEncode(substr(
            hash_hmac('sha256', $payload, $this->paymentContextKey($gateway), true),
            0,
            12
        ));

        return $payload . '.' . $signature;
    }

    private function decodePaymentContext(string $gateway, string $token): array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 8) {
            throw new \InvalidArgumentException('Invalid payment context.');
        }

        $signature = array_pop($parts);
        $payload = implode('.', $parts);
        $expected = $this->base64UrlEncode(substr(
            hash_hmac('sha256', $payload, $this->paymentContextKey($gateway), true),
            0,
            12
        ));
        if (!hash_equals($expected, $signature) || $parts[0] !== '1') {
            throw new \InvalidArgumentException('Invalid payment context signature.');
        }

        $type = match ($parts[2]) {
            'i' => 'invoice',
            'd' => 'deposit',
            default => throw new \InvalidArgumentException('Invalid payment type.'),
        };
        $registrarId = $this->base36Decode($parts[1]);
        $invoiceId = $this->base36Decode($parts[3]);
        $amountMinor = $this->base36Decode($parts[4]);
        $currency = strtoupper($parts[5]);

        if (
            $registrarId < 1
            || $amountMinor < 1
            || !preg_match('/^[A-Z]{3}$/', $currency)
            || ($type === 'invoice' && $invoiceId < 1)
            || ($type === 'deposit' && $invoiceId !== 0)
        ) {
            throw new \InvalidArgumentException('Invalid payment context values.');
        }

        return [
            'user_id' => $registrarId,
            'type' => $type,
            'invoice_id' => $type === 'invoice' ? $invoiceId : null,
            'amount' => $this->amountFromMinor($amountMinor),
            'currency' => $currency,
        ];
    }

    private function verifiedPayment(
        string $gateway,
        string $reference,
        string $status,
        mixed $amount,
        string $currency,
        string $token
    ): array {
        $context = $this->decodePaymentContext($gateway, $token);
        $amount = $this->normalizeAmount($amount);
        $currency = strtoupper(trim($currency));

        if ($amount !== $context['amount'] || $currency !== $context['currency']) {
            throw new \RuntimeException('Gateway amount or currency does not match the payment context.');
        }
        if (!in_array($status, ['paid', 'pending', 'failed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Invalid gateway payment status.');
        }
        if ($reference === '') {
            throw new \InvalidArgumentException('Missing gateway payment reference.');
        }

        return [
            'gateway' => $gateway,
            'gateway_reference' => $reference,
            'status' => $status,
            'user_id' => $context['user_id'],
            'invoice_id' => $context['invoice_id'],
            'type' => $context['type'],
            'amount' => $context['amount'],
            'currency' => $context['currency'],
            'redirect_url' => $this->paymentRedirect($context),
        ];
    }

    private function pendingPayment(
        string $gateway,
        string $token,
        string $reference = '',
        string $status = 'pending'
    ): array {
        $context = $this->decodePaymentContext($gateway, $token);

        return [
            'gateway' => $gateway,
            'gateway_reference' => $reference !== ''
                ? $reference
                : 'pending-' . substr(hash('sha256', $token), 0, 32),
            'status' => $status,
            'user_id' => $context['user_id'],
            'invoice_id' => $context['invoice_id'],
            'type' => $context['type'],
            'amount' => $context['amount'],
            'currency' => $context['currency'],
            'redirect_url' => $this->paymentRedirect($context),
        ];
    }

    private function paymentRedirect(array $context): string
    {
        if ($context['type'] !== 'invoice') {
            return '/deposit';
        }

        $invoice = $this->container->get('db')->selectRow(
            'SELECT invoice_number
             FROM invoices
             WHERE id = ? AND user_id = ?
             LIMIT 1',
            [$context['invoice_id'], $context['user_id']]
        );

        return $invoice
            ? '/invoice/' . rawurlencode((string)$invoice['invoice_number'])
            : '/invoices';
    }

    private function resolvePaymentGateway(string $gateway, bool $mustBeEnabled = false): string
    {
        $gateway = strtolower(trim($gateway));
        if (!isset(self::PAYMENT_GATEWAYS[$gateway])) {
            throw new \InvalidArgumentException('Unsupported payment gateway.');
        }

        $enabled = array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', (string)envi('ENABLED_GATEWAYS'))
        ));
        if ($mustBeEnabled && !in_array($gateway, $enabled, true)) {
            throw new \InvalidArgumentException('This payment gateway is disabled.');
        }

        return $gateway;
    }

    private function paymentResults(array $result): array
    {
        if ($result === []) {
            return [];
        }

        return array_is_list($result) ? $result : [$result];
    }

    private function paymentContextKey(string $gateway): string
    {
        $secret = match ($gateway) {
            'stripe' => envi('STRIPE_SECRET_KEY'),
            'liqpay' => envi('LIQPAY_PRIVATE_KEY'),
            'plata' => envi('PLATA_TOKEN'),
            'adyen' => envi('ADYEN_HMAC_KEY'),
            'now' => envi('NOW_API_KEY'),
            'nicky' => envi('NICKY_API_KEY'),
            default => null,
        };
        $secret = trim((string)$secret);

        if ($secret === '') {
            throw new \RuntimeException('Payment gateway secret is not configured.');
        }

        return hash('sha256', 'foundry-payment-context:' . $gateway . ':' . $secret, true);
    }

    private function normalizeAmount(mixed $value): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d{1,10})(?:\.(\d{1,8}))?$/', $value, $parts)) {
            throw new \InvalidArgumentException('Invalid payment amount.');
        }

        $fraction = $parts[2] ?? '';
        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            throw new \InvalidArgumentException('Payment amount has more than two decimal places.');
        }

        $whole = ltrim($parts[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $amount = $whole . '.' . str_pad(substr($fraction, 0, 2), 2, '0');

        if ($amount === '0.00') {
            throw new \InvalidArgumentException('Payment amount must be positive.');
        }

        return $amount;
    }

    private function amountToMinor(string $amount): int
    {
        [$whole, $fraction] = explode('.', $amount);

        return ((int)$whole * 100) + (int)$fraction;
    }

    private function amountFromMinor(mixed $minor): string
    {
        $minor = trim((string)$minor);
        if (!preg_match('/^\d{1,12}$/', $minor) || (int)$minor < 1) {
            throw new \InvalidArgumentException('Invalid gateway amount.');
        }

        $value = (int)$minor;

        return intdiv($value, 100) . '.' . str_pad((string)($value % 100), 2, '0');
    }

    private function base36Encode(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Invalid payment context number.');
        }
        if ($value === 0) {
            return '0';
        }

        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
        $encoded = '';
        while ($value > 0) {
            $encoded = $alphabet[$value % 36] . $encoded;
            $value = intdiv($value, 36);
        }

        return $encoded;
    }

    private function base36Decode(string $value): int
    {
        if ($value === '' || !preg_match('/^[0-9a-z]+$/', $value)) {
            throw new \InvalidArgumentException('Invalid payment context number.');
        }

        $decoded = 0;
        foreach (str_split($value) as $character) {
            $digit = strpos('0123456789abcdefghijklmnopqrstuvwxyz', $character);
            if ($digit === false || $decoded > intdiv(PHP_INT_MAX - $digit, 36)) {
                throw new \InvalidArgumentException('Payment context number is too large.');
            }
            $decoded = ($decoded * 36) + $digit;
        }

        return $decoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function sortKeysRecursively(array $data): array
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->sortKeysRecursively($value);
            }
        }
        unset($value);

        if (!array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }

    private function clearPendingInvoice(array $context): void
    {
        if ($context['type'] === 'invoice') {
            unset($_SESSION['pending_invoice_amount'], $_SESSION['pending_invoice_id']);
        }
    }

    private function appUrl(string $path): string
    {
        return rtrim((string)envi('APP_URL'), '/') . $path;
    }

    private function cancelUrl(array $context): string
    {
        return $this->appUrl('/payment-cancel?type=' . $context['type']);
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    private function paymentErrorResponse(Response $response, \Throwable $exception): Response
    {
        $message = envi('APP_ENV') === 'local'
            ? $exception->getMessage()
            : 'We encountered an issue while creating your payment.';

        return $this->jsonResponse($response, ['error' => $message], 502);
    }

    /**
     * Settle first, then provision outside the payment transaction.
     *
     * This may run more than once because both the browser return and webhook can
     * report the same payment. provisionService() must therefore be idempotent.
     */
    private function processPaidPayment(array $payment): void
    {
        $this->settlePayment($payment);
    }

    /**
     * Persist a payment that has already been verified by a gateway adapter.
     *
     * Required keys: gateway, gateway_reference, status, user_id, type,
     * amount and currency. invoice_id is also required when type is invoice.
     */
    private function settlePayment(array $payment): void
    {
        $gateway = strtolower(trim((string)($payment['gateway'] ?? '')));
        $reference = trim((string)($payment['gateway_reference'] ?? ''));
        $status = strtolower(trim((string)($payment['status'] ?? '')));
        $paymentType = strtolower(trim((string)($payment['type'] ?? '')));
        $currency = strtoupper(trim((string)($payment['currency'] ?? '')));
        $amountInput = trim((string)($payment['amount'] ?? ''));
        $registrarId = filter_var(
            $payment['user_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $gateway)) {
            throw new \InvalidArgumentException('Invalid payment gateway.');
        }
        if ($reference === '' || strlen($reference) > 128 || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
            throw new \InvalidArgumentException('Invalid gateway payment reference.');
        }
        if ($status !== 'paid') {
            throw new \LogicException('Only verified paid payments can be settled.');
        }
        if (!in_array($paymentType, ['invoice', 'deposit'], true)) {
            throw new \InvalidArgumentException('Invalid payment type.');
        }
        if ($registrarId === false) {
            throw new \InvalidArgumentException('Invalid payment user.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Invalid payment currency.');
        }
        if (!preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $amountInput, $amountParts)) {
            throw new \InvalidArgumentException('Invalid payment amount.');
        }

        $wholeAmount = ltrim($amountParts[1], '0');
        $wholeAmount = $wholeAmount === '' ? '0' : $wholeAmount;
        $fractionAmount = str_pad($amountParts[2] ?? '', 2, '0');
        $amount = $wholeAmount . '.' . $fractionAmount;
        if ($amount === '0.00') {
            throw new \InvalidArgumentException('Payment amount must be positive.');
        }

        $invoiceId = 0;
        if ($paymentType === 'invoice') {
            $invoiceId = filter_var(
                $payment['invoice_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($invoiceId === false) {
                throw new \InvalidArgumentException('Invoice payment requires a valid invoice ID.');
            }
        }

        $transactionType = $paymentType === 'deposit' ? 'credit' : 'debit';
        $description = $paymentType === 'invoice'
            ? "Payment for Invoice #{$invoiceId}"
            : 'Account balance deposit';

        $matchesPayment = static function ($transaction) use (
            $registrarId,
            $paymentType,
            $invoiceId,
            $transactionType,
            $amount,
            $currency
        ): bool {
            return is_array($transaction)
                && (int)$transaction['user_id'] === $registrarId
                && $transaction['related_entity_type'] === $paymentType
                && (int)$transaction['related_entity_id'] === $invoiceId
                && $transaction['type'] === $transactionType
                && number_format((float)$transaction['amount'], 2, '.', '') === $amount
                && strtoupper((string)$transaction['currency']) === $currency
                && $transaction['status'] === 'completed';
        };

        $db = $this->container->get('db');

        try {
            $db->beginTransaction();

            $existing = $db->selectRow(
                'SELECT *
                 FROM payment_history
                 WHERE gateway = ? AND gateway_reference = ?
                 LIMIT 1',
                [$gateway, $reference]
            );

            if ($existing) {
                if (!$matchesPayment($existing)) {
                    throw new \RuntimeException('Gateway reference was already used for another payment.');
                }

                $db->commit();
                return;
            }

            $user = $db->selectRow(
                'SELECT id FROM users WHERE id = ? LIMIT 1',
                [$registrarId]
            );
            if (!$user) {
                throw new \RuntimeException('Payment user was not found.');
            }
            if (strtoupper((string)$_SESSION['_currency']) !== $currency) {
                throw new \RuntimeException('Payment currency does not match the user currency.');
            }

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

            // Insert first: the unique gateway/reference pair is the idempotency lock.
            $db->insert(
                'statement',
                [
                    'registrar_id' => $registrarId,
                    'date' => $now,
                    'command' => 'create',
                    'domain_name' => 'deposit',
                    'length_in_months' => 0,
                    'fromS' => $now,
                    'toS' => $now,
                    'amount' => $amount
                ]
            );

            $db->insert(
                'payment_history',
                [
                    'registrar_id' => $registrarId,
                    'date' => $now,
                    'description' => 'registrar balance deposit via ' . ucfirst($gateway) . ' (' . $reference . ')',
                    'amount' => $amount,
                    'gateway' => $gateway,
                    'gateway_reference' => $reference
                ]
            );
                        
            $updated = $db->exec(
                'UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?',
                [$amount, $registrarId]
            );
            if ($updated !== 1) {
                throw new \RuntimeException('Payment balance update failed.');
            }

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }

            // A concurrent webhook may have won the unique-key race.
            $existing = $db->selectRow(
                'SELECT *
                 FROM payment_history
                 WHERE gateway = ? AND gateway_reference = ?
                 LIMIT 1',
                [$gateway, $reference]
            );
            if ($matchesPayment($existing)) {
                return;
            }

            throw $exception;
        }
    }
    
    public function cancel(Request $request, Response $response)
    {
        return view($response,'admin/financials/cancel.twig');
    }
}