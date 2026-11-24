<?php

namespace App\Controllers;

use App\Security\PasswordHasher;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\PageMargins;
use League\ISO3166\ISO3166;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Public applications and authenticated registry-admin approval. */
final class OnboardingController extends Controller
{
    private function page(Response $response, string $name, array $vars = []): Response
    {
        $vars['registryName'] = (string) envi('APP_NAME', 'Registry');
        if ($name === 'apply') {
            $vars['countries'] = (new ISO3166())->all();
            usort($vars['countries'], static fn (array $a, array $b): int =>
                strcasecmp($a['name'], $b['name']));
            $currencies = [];
            foreach ($vars['countries'] as $country) {
                foreach ($country['currency'] as $currency) {
                    $currencies[$currency] = $currency;
                }
            }
            ksort($currencies);
            $vars['currencies'] = $currencies;
        }
        return $this->container->get('view')->render($response, 'onboarding/' . $name . '.twig', $vars);
    }

    private function redirect(Response $response, string $url): Response
    {
        return $response->withHeader('Location', $url)->withStatus(303);
    }

    private function privateResponse(Response $response): Response
    {
        return $response->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function application(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) {
            return null;
        }
        $query = $this->container->get('pdo')->prepare('SELECT * FROM registrar_onboarding WHERE public_token = ?');
        $query->execute([$token]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $row['details'] = json_decode($row['application_data'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $row ?: null;
    }

    private function onboardingFee(): ?array
    {
        $secretKey = trim((string) envi('STRIPE_SECRET_KEY', ''));
        $fee = trim((string) envi('ONBOARDING_FEE', envi('onboarding_fee', '0')));
        if (!preg_match('/^(?:sk|rk)_(?:test|live)_\S+$/', $secretKey)
            || $fee === '' || $fee === '0') {
            return null;
        }
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $fee)) {
            throw new \RuntimeException('Invalid ONBOARDING_FEE amount.');
        }
        $currency = strtoupper((string) $this->container->get('db')->selectValue(
            "SELECT value FROM settings WHERE name = 'currency'"
        ));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \RuntimeException('Invalid registry currency for onboarding payment.');
        }
        $zeroDecimal = in_array($currency, [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
            'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ], true);
        if (($zeroDecimal || in_array($currency, ['ISK', 'UGX'], true))
            && str_contains($fee, '.') && (int) explode('.', $fee)[1] !== 0) {
            throw new \RuntimeException('Onboarding fee must be a whole amount for this currency.');
        }
        [$whole, $fraction] = array_pad(explode('.', $fee, 2), 2, '');
        $minor = (int) $whole * ($zeroDecimal ? 1 : 100)
            + ($zeroDecimal ? 0 : (int) str_pad($fraction, 2, '0'));
        return $minor > 0 ? ['amount_minor' => $minor, 'currency' => $currency, 'paid' => false] : null;
    }

    private function confirmOnboardingPayment(array $application, array $payment): bool
    {
        if (empty($payment['session_id'])) {
            return false;
        }
        $stripe = new \Stripe\StripeClient((string) envi('STRIPE_SECRET_KEY'));
        $session = $stripe->checkout->sessions->retrieve($payment['session_id'], []);
        return $session->payment_status === 'paid'
            && (int) $session->amount_total === (int) $payment['amount_minor']
            && strtoupper((string) $session->currency) === $payment['currency']
            && (string) ($session->metadata->onboarding_id ?? '') === (string) $application['id'];
    }

    private function forbidden(Response $response): Response
    {
        if (!$this->container->get('auth')->isLogin()) {
            return $this->redirect($response, '/login');
        }
        return $response->withStatus(403);
    }

    private function isAdmin(): bool
    {
        return $this->container->get('auth')->isLogin()
            && isset($_SESSION['auth_roles'])
            && (int) $_SESSION['auth_roles'] === 0
            && empty($_SESSION['impersonator']);
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->page($response, 'apply');
    }

    public function submit(Request $request, Response $response): Response
    {
        $input = (array) ($request->getParsedBody() ?? []);
        $fields = [
            'name', 'companyNumber', 'currency', 'ianaId', 'email', 'url', 'whoisServer', 'rdapServer',
            'abuseEmail', 'abusePhone', 'ownerFirstName', 'ownerLastName',
            'ownerOrg', 'ownerStreet1', 'ownerCity', 'ownerSp', 'ownerPc',
            'ownerCc', 'ownerVoice', 'ownerEmail', 'user_email', 'user_name', 'ipAddress',
        ];
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
        }
        $data['user_name'] = strtolower($data['user_name']);
        $data['ownerCc'] = strtoupper($data['ownerCc']);
        $data['currency'] = strtoupper($data['currency']);
        $required = [
            'name', 'companyNumber', 'currency', 'email', 'url', 'whoisServer', 'rdapServer', 'abuseEmail',
            'abusePhone', 'ownerFirstName', 'ownerLastName', 'ownerCity',
            'ownerCc', 'ownerEmail', 'user_email', 'user_name', 'ipAddress',
        ];
        $errors = [];
        foreach ($required as $field) {
            if ($data[$field] === '') {
                $errors[] = "{$field} is required.";
            }
        }
        foreach ($data as $field => $value) {
            if (mb_strlen($value) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
                $errors[] = "{$field} is invalid or too long.";
            }
        }
        foreach (['email', 'abuseEmail', 'ownerEmail', 'user_email'] as $field) {
            if (!filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "{$field} must be a valid email address.";
            }
        }
        if (!filter_var($data['url'], FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($data['url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $errors[] = 'Website URL must be http(s).';
        }
        if (!filter_var($data['rdapServer'], FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($data['rdapServer'], PHP_URL_SCHEME)) !== 'https') {
            $errors[] = 'RDAP server must be an HTTPS URL.';
        }
        if (!preg_match('/^(?=.{1,253}$)[a-z0-9.-]+$/iD', $data['whoisServer'])) {
            $errors[] = 'WHOIS server must be a hostname.';
        }
        if (!preg_match('/^[a-z0-9-]{1,16}$/D', $data['user_name'])) {
            $errors[] = 'EPP client ID must be 1–16 lowercase letters, numbers or hyphens.';
        }
        try {
            (new ISO3166())->alpha2($data['ownerCc']);
        } catch (\Throwable $e) {
            $errors[] = 'Select a valid country.';
        }
        if (mb_strlen($data['companyNumber']) > 30) {
            $errors[] = 'Company number must not exceed 30 characters.';
        }
        $currencies = [];
        foreach ((new ISO3166())->all() as $country) {
            foreach ($country['currency'] as $code) {
                $currencies[$code] = true;
            }
        }
        if (!isset($currencies[$data['currency']])) {
            $errors[] = 'Select a valid currency.';
        }
        if ($data['ianaId'] !== '' && (!ctype_digit($data['ianaId']) || (int) $data['ianaId'] < 1 || (int) $data['ianaId'] > 99999)) {
            $errors[] = 'IANA ID must be a positive integer, or left blank.';
        }
        if (!filter_var($data['ipAddress'], FILTER_VALIDATE_IP)) {
            $errors[] = 'Enter a valid IPv4 or IPv6 address.';
        }
        foreach (['abusePhone', 'ownerVoice'] as $field) {
            if ($data[$field] !== '' && !preg_match('/^\+[0-9]{1,3}\.[0-9]{2,12}$/D', $data[$field])) {
                $errors[] = "{$field} must be in +123.456789 format.";
            }
        }
        if ($errors) {
            return $this->page($response->withStatus(422), 'apply', ['errors' => $errors, 'values' => $data]);
        }

        $pdo = $this->container->get('pdo');
        $exists = $pdo->prepare('SELECT 1 FROM registrar WHERE clid = ? OR email = ?');
        $exists->execute([$data['user_name'], $data['email']]);
        $userExists = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $userExists->execute([$data['user_email']]);
        if ($exists->fetchColumn() || $userExists->fetchColumn()) {
            return $this->page($response->withStatus(409), 'apply', [
                'errors' => ['A registrar or portal account with these details already exists.'],
                'values' => $data,
            ]);
        }

        $token = bin2hex(random_bytes(24));
        if ($payment = $this->onboardingFee()) {
            $data['_onboarding_fee'] = $payment;
        }
        $twig = $this->container->get('view')->getEnvironment();
        $termsTemplate = $twig->getLoader()->exists('onboarding/terms.twig.custom')
            ? 'onboarding/terms.twig.custom' : 'onboarding/terms.twig';
        $agreement = $twig->render(
            $termsTemplate, ['d' => $data, 'registryName' => (string) envi('APP_NAME', 'Registry')]
        );
        $stmt = $pdo->prepare(
            "INSERT INTO registrar_onboarding (public_token, application_data, agreement_html, status, created_at)
             VALUES (?, ?, ?, 'pendingAgreement', CURRENT_TIMESTAMP)"
        );
        $stmt->execute([$token, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $agreement]);
        return $this->redirect($this->privateResponse($response), '/onboarding/application/' . $token);
    }

    public function status(Request $request, Response $response, string $token): Response
    {
        $response = $this->privateResponse($response);
        $application = $this->application($token);
        if (!$application) {
            return $this->redirect($response, '/onboarding');
        }
        return $this->page($response, 'status', ['application' => $application]);
    }

    public function pay(Request $request, Response $response, string $token): Response
    {
        $response = $this->privateResponse($response);
        $application = $this->application($token);
        if (!$application || $application['status'] !== 'signed') {
            return $this->redirect($response, '/onboarding');
        }
        $data = $application['details'];
        $payment = $data['_onboarding_fee'] ?? null;
        if (!$payment || $payment['paid']) {
            return $this->redirect($response, '/onboarding/application/' . $token);
        }
        $stripe = new \Stripe\StripeClient((string) envi('STRIPE_SECRET_KEY'));
        try {
            if (!empty($payment['session_id'])) {
                $previous = $stripe->checkout->sessions->retrieve($payment['session_id'], []);
                if ($this->confirmOnboardingPayment($application, $payment)) {
                    $data['_onboarding_fee']['paid'] = true;
                    $this->saveApplicationData($application['id'], $data);
                    return $this->redirect($response, '/onboarding/application/' . $token);
                }
                if ($previous->status === 'open' && !empty($previous->url)) {
                    return $this->redirect($response, (string) $previous->url);
                }
                if ($previous->status === 'complete') {
                    // A completed Checkout may still have a delayed payment processing.
                    // Only replace it once Stripe confirms the PaymentIntent failed.
                    $intentId = (string) ($previous->payment_intent ?? '');
                    if ($intentId === '') {
                        return $this->redirect($response, '/onboarding/application/' . $token);
                    }
                    $intent = $stripe->paymentIntents->retrieve($intentId, []);
                    if (!in_array($intent->status, ['requires_payment_method', 'canceled'], true)) {
                        return $this->redirect($response, '/onboarding/application/' . $token);
                    }
                }
            }
            $session = $stripe->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($payment['currency']),
                        'product_data' => [
                            'name' => 'Registrar onboarding fee',
                            'description' => 'EPP client ID: ' . $data['user_name'],
                        ],
                        'unit_amount' => (int) $payment['amount_minor'],
                    ],
                    'quantity' => 1,
                ]],
                'client_reference_id' => (string) $application['id'],
                'metadata' => ['onboarding_id' => (string) $application['id']],
                'success_url' => rtrim((string) envi('APP_URL'), '/')
                    . '/onboarding/payment/' . $token . '/return?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => rtrim((string) envi('APP_URL'), '/')
                    . '/onboarding/application/' . $token,
            ], ['idempotency_key' => 'onboarding-' . $application['id'] . '-'
                . ($payment['session_id'] ?? 'initial')]);
            if (empty($session->id) || empty($session->url)) {
                throw new \RuntimeException('Stripe did not return a checkout session.');
            }
            $data['_onboarding_fee']['session_id'] = (string) $session->id;
            $this->saveApplicationData($application['id'], $data);
            return $this->redirect($response, (string) $session->url);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('Onboarding Stripe checkout: ' . $e->getMessage());
            return $this->page($response->withStatus(503), 'payment_error', ['token' => $token]);
        }
    }

    private function saveApplicationData(int|string $id, array $data): void
    {
        $stmt = $this->container->get('pdo')->prepare(
            'UPDATE registrar_onboarding SET application_data = ? WHERE id = ?'
        );
        $stmt->execute([json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id]);
    }

    public function paymentReturn(Request $request, Response $response, string $token): Response
    {
        $response = $this->privateResponse($response);
        $application = $this->application($token);
        $sessionId = $request->getQueryParams()['session_id'] ?? null;
        if (!$application || !is_string($sessionId)) {
            return $this->redirect($response, '/onboarding');
        }
        $data = $application['details'];
        $payment = $data['_onboarding_fee'] ?? null;
        if ($payment && hash_equals((string) ($payment['session_id'] ?? ''), $sessionId)) {
            try {
                if ($this->confirmOnboardingPayment($application, $payment)) {
                    $data['_onboarding_fee']['paid'] = true;
                    $this->saveApplicationData($application['id'], $data);
                }
            } catch (\Stripe\Exception\ApiErrorException $e) {
                error_log('Onboarding Stripe confirmation: ' . $e->getMessage());
            }
        }
        return $this->redirect($response, '/onboarding/application/' . $token);
    }

    public function contract(Request $request, Response $response, string $token): Response
    {
        $response = $this->privateResponse($response);
        $application = $this->application($token);
        if (!$application) {
            return $this->redirect($response, '/onboarding');
        }
        return $this->page($response, 'contract', ['application' => $application]);
    }

    /** Download the snapshotted agreement with its captured electronic signature. */
    public function signedPdf(Request $request, Response $response, string $token): Response
    {
        $response = $this->privateResponse($response);
        $application = $this->application($token);
        if (!$application || !$application['signature'] || !$application['signed_at']) {
            return $response->withStatus(404);
        }

        // phppdf does not render HTML. Convert the stored (not regenerated)
        // agreement's text sections to flowing PDF Markdown.
        $html = new \DOMDocument();
        $oldErrors = libxml_use_internal_errors(true);
        try {
            if (!$html->loadHTML('<?xml encoding="UTF-8"?>' . $application['agreement_html'], LIBXML_NONET)) {
                throw new \RuntimeException('Cannot read the stored agreement');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($oldErrors);
        }
        $sections = [];
        $xpath = new \DOMXPath($html);
        foreach ($xpath->query('//body//*[self::h1 or self::h2 or self::h3 or self::p or self::li]') as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            if ($text === '') {
                continue;
            }
            $prefix = match ($node->nodeName) {
                'h1' => '# ', 'h2' => '## ', 'h3' => '### ', 'li' => '- ', default => '',
            };
            // Escape all CommonMark punctuation from the signed HTML snapshot.
            // Only our intentional heading/list prefix may introduce formatting.
            $literal = preg_replace_callback('/[[:punct:]]/u',
                static fn (array $match): string => chr(92) . $match[0], $text);
            $sections[] = $prefix . $literal;
        }
        if (!$sections) {
            throw new \RuntimeException('The stored agreement has no printable content');
        }

        $pdf = new Document();
        $pdf->metadata()->title('Signed registrar accreditation agreement');
        $pdf->setMargins(new PageMargins(top: 20, right: 20, bottom: 20, left: 20));
        $pdf->setAutoPageBreak(true);
        // Use an installed Unicode font where available for international names.
        $fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $font = Font::helvetica();
        if (is_readable($fontPath)) {
            $pdf->registerFontFamily('registry', regular: $fontPath);
            $font = Font::custom('registry');
        }
        $page = $pdf->addPage();
        $page->setFont($font, 11);
        $page->markdown(implode("\n\n", $sections), width: 170);

        $page = $pdf->getCurrentPage();
        if ($page->getY() > 220) {
            $page = $pdf->addPage();
        }
        $page->setFont($font, 10);
        $y = $page->getY() + 8;
        $details = $application['details'];
        $signer = trim(($details['ownerFirstName'] ?? '') . ' ' . ($details['ownerLastName'] ?? ''));
        $page->text(20, $y, 'Electronically signed by: ' . $signer);
        $page->text(20, $y + 7, 'Signed at: ' . $application['signed_at'] . ' (registry database time)');
        $page->text(20, $y + 14, 'Recorded IP: ' . (string)$application['signed_ip']);
        $page->image(Image::fromBase64($application['signature']), x: 20, y: $y + 20, w: 90, h: 27);

        $bytes = $pdf->output();
        $response->getBody()->write($bytes);
        return $response->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="registrar-agreement-' . (int)$application['id'] . '.pdf"')
            ->withHeader('Content-Length', (string)strlen($bytes));
    }

    /** Revoke the public bearer URL without changing the application or its admin status. */
    public function hide(Request $request, Response $response, string $token): Response
    {
        $response = $this->privateResponse($response);
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) {
            return $this->redirect($response, '/onboarding');
        }
        $stmt = $this->container->get('pdo')->prepare(
            'UPDATE registrar_onboarding SET public_token = ? WHERE public_token = ?'
        );
        $stmt->execute([bin2hex(random_bytes(24)), $token]);
        return $this->redirect($response, '/onboarding');
    }

    public function sign(Request $request, Response $response, string $token): Response
    {
        $response = $this->privateResponse($response);
        $application = $this->application($token);
        if (!$application || $application['status'] !== 'pendingAgreement') {
            return $this->redirect($response, '/onboarding');
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $signature = $body['signature'] ?? null;
        if (!is_string($signature) || strlen($signature) > 400000
            || !preg_match('#^data:image/png;base64,([a-zA-Z0-9+/=]+)$#D', $signature, $match)
            || ($png = base64_decode($match[1], true)) === false
            || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n"
            || !($size = @getimagesizefromstring($png))
            || $size[2] !== IMAGETYPE_PNG
            || $size[0] < 80 || $size[0] > 2000 || $size[1] < 40 || $size[1] > 1000) {
            return $this->page($response->withStatus(422), 'contract', [
                'application' => $application, 'error' => 'Please draw a valid signature.',
            ]);
        }
        $stmt = $this->container->get('pdo')->prepare(
            "UPDATE registrar_onboarding SET status = 'signed', signature = ?,
                signed_at = CURRENT_TIMESTAMP, signed_ip = ?
             WHERE id = ? AND status = 'pendingAgreement'"
        );
        $stmt->execute([$signature, $request->getServerParams()['REMOTE_ADDR'] ?? '', $application['id']]);
        return $this->redirect($response, '/onboarding/application/' . $token);
    }

    public function admin(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->forbidden($response);
        }
        $applications = $this->container->get('pdo')->query(
            "SELECT id, public_token, application_data, status, created_at, signed_at
             FROM registrar_onboarding ORDER BY id DESC LIMIT 200"
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($applications as &$application) {
            $application['details'] = json_decode($application['application_data'], true);
        }
        unset($application);
        return $this->page($response, 'admin', ['applications' => $applications]);
    }

    public function review(Request $request, Response $response, string $id): Response
    {
        if (!$this->isAdmin()) {
            return $this->forbidden($response);
        }
        if (!ctype_digit($id)) {
            return $this->redirect($response, '/onboarding/admin');
        }
        $stmt = $this->container->get('pdo')->prepare('SELECT * FROM registrar_onboarding WHERE id = ?');
        $stmt->execute([$id]);
        $application = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$application) {
            return $this->redirect($response, '/onboarding/admin');
        }
        $application['details'] = json_decode($application['application_data'], true, 512, JSON_THROW_ON_ERROR);
        return $this->page($response->withHeader('Cache-Control', 'no-store'), 'contract', [
            'application' => $application, 'admin' => true,
        ]);
    }

    public function action(Request $request, Response $response, string $id, string $action): Response
    {
        if (!$this->isAdmin()) {
            return $this->forbidden($response);
        }
        if (!ctype_digit($id)) {
            return $this->redirect($response, '/onboarding/admin');
        }
        $pdo = $this->container->get('pdo');
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT * FROM registrar_onboarding WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $application = $lock->fetch(\PDO::FETCH_ASSOC);
            if (!$application || $application['status'] !== 'signed') {
                throw new \RuntimeException('Only signed, pending applications can be processed.');
            }
            $data = json_decode($application['application_data'], true, 512, JSON_THROW_ON_ERROR);
            if ($action === 'approve' && !empty($data['_onboarding_fee']) && !$data['_onboarding_fee']['paid']) {
                if (!$this->confirmOnboardingPayment($application, $data['_onboarding_fee'])) {
                    throw new \RuntimeException('Onboarding fee has not been paid.');
                }
                $data['_onboarding_fee']['paid'] = true;
                $this->saveApplicationData($id, $data);
            }
            if ($action === 'reject') {
                $pdo->prepare("UPDATE registrar_onboarding SET status = 'rejected' WHERE id = ?")->execute([$id]);
                $pdo->commit();
                return $this->redirect($response, '/onboarding/admin');
            }
            $d = $data;
            $panelPassword = bin2hex(random_bytes(16)) . 'aA1!';
            $eppPassword = bin2hex(random_bytes(8));
            $now = date('Y-m-d H:i:s');
            $currency = $d['currency'] ?? ($pdo->query("SELECT value FROM settings WHERE name = 'currency'")->fetchColumn() ?: 'EUR');
            $companyNumberColumn = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql'
                ? '"companyNumber"' : 'companyNumber';
            $stmt = $pdo->prepare(
                'INSERT INTO registrar (name, iana_id, clid, pw, prefix, email, url, whois_server,
                 rdap_server, abuse_email, abuse_phone, accountbalance, creditlimit, creditthreshold,
                 thresholdtype, currency, ' . $companyNumberColumn . ', crdate, lastupdate)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 100, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $d['name'], $d['ianaId'] === '' ? null : (int) $d['ianaId'], $d['user_name'],
                PasswordHasher::hash($eppPassword), 'T' . strtoupper(bin2hex(random_bytes(2))),
                $d['email'], $d['url'], $d['whoisServer'], $d['rdapServer'],
                $d['abuseEmail'], $d['abusePhone'], 'fixed', $currency, $d['companyNumber'] ?? null, $now, $now,
            ]);
            $registrarId = (int) $pdo->lastInsertId($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'registrar_id_seq' : null);
            $prefix = 'R' . str_pad((string) $registrarId, 4, '0', STR_PAD_LEFT);
            if (strlen($prefix) > 5) {
                throw new \RuntimeException('Registry prefix exhausted; create this registrar manually.');
            }
            $pdo->prepare('UPDATE registrar SET prefix = ? WHERE id = ?')->execute([$prefix, $registrarId]);
            $contact = $pdo->prepare(
                'INSERT INTO registrar_contact (registrar_id, type, first_name, last_name, org, street1,
                 city, sp, pc, cc, voice, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach (['owner', 'billing', 'tech', 'abuse'] as $type) {
                $contact->execute([
                    $registrarId, $type, $d['ownerFirstName'], $d['ownerLastName'], $d['ownerOrg'] ?: null,
                    $d['ownerStreet1'] ?: null, $d['ownerCity'], $d['ownerSp'] ?: null,
                    $d['ownerPc'] ?: null, strtolower($d['ownerCc']), $d['ownerVoice'] ?: null,
                    $type === 'abuse' ? $d['abuseEmail'] : $d['ownerEmail'],
                ]);
            }
            $pdo->prepare('INSERT INTO registrar_whitelist (registrar_id, addr) VALUES (?, ?)')
                ->execute([$registrarId, $d['ipAddress']]);
            $stmt = $pdo->prepare(
                'INSERT INTO users (email, password, username, verified, roles_mask, registered, password_last_updated)
                 VALUES (?, ?, ?, 1, 4, ?, ?)'
            );
            $stmt->execute([$d['user_email'], PasswordHasher::hash($panelPassword), $d['user_name'], time(), $now]);
            $userId = (int) $pdo->lastInsertId($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'users_id_seq' : null);
            $pdo->prepare('INSERT INTO registrar_users (registrar_id, user_id) VALUES (?, ?)')->execute([$registrarId, $userId]);
            $ote = $pdo->prepare('INSERT INTO registrar_ote (registrar_id, command, result) VALUES (?, ?, 9)');
            foreach (['contact:create', 'domain:check', 'domain:info', 'domain:renew',
                      'domain:transfer', 'host:create', 'host:info', 'contact:update',
                      'domain:delete', 'poll:request'] as $command) {
                $ote->execute([$registrarId, $command]);
            }
            $pdo->prepare("UPDATE registrar_onboarding SET status = 'approved', registrar_id = ? WHERE id = ?")
                ->execute([$registrarId, $id]);
            $pdo->commit();
            return $this->page($response->withHeader('Cache-Control', 'no-store'), 'credentials', [
                'registrar' => $d['name'], 'email' => $d['user_email'], 'clid' => $d['user_name'],
                'panelPassword' => $panelPassword, 'eppPassword' => $eppPassword,
            ]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Registrar onboarding approval: ' . $e->getMessage());
            return $this->page($response->withStatus(409), 'admin_error', [
                'error' => 'Action failed. Check duplicates and database logs; no registrar was created.',
            ]);
        }
    }
}
