<?php

namespace App\Controllers;

use App\Security\PasswordHasher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Public applications and authenticated registry-admin approval. */
final class OnboardingController extends Controller
{
    private function page(Response $response, string $name, array $vars = []): Response
    {
        $vars['registryName'] = (string) envi('APP_NAME', 'Registry');
        return $this->container->get('view')->render($response, 'onboarding/' . $name . '.twig', $vars);
    }

    private function redirect(Response $response, string $url): Response
    {
        return $response->withHeader('Location', $url)->withStatus(303);
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
            'name', 'ianaId', 'email', 'url', 'whoisServer', 'rdapServer',
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
        $required = [
            'name', 'email', 'url', 'whoisServer', 'rdapServer', 'abuseEmail',
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
        if (!preg_match('/^[A-Z]{2}$/D', $data['ownerCc'])) {
            $errors[] = 'Country must be a two-letter ISO code.';
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
        $agreement = $this->container->get('view')->getEnvironment()->render(
            'onboarding/terms.twig', ['d' => $data, 'registryName' => (string) envi('APP_NAME', 'Registry')]
        );
        $stmt = $pdo->prepare(
            "INSERT INTO registrar_onboarding (public_token, application_data, agreement_html, status, created_at)
             VALUES (?, ?, ?, 'pendingAgreement', CURRENT_TIMESTAMP)"
        );
        $stmt->execute([$token, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $agreement]);
        return $this->redirect($response, '/onboarding/application/' . $token);
    }

    public function status(Request $request, Response $response, string $token): Response
    {
        $application = $this->application($token);
        if (!$application) {
            return $this->redirect($response, '/onboarding');
        }
        return $this->page($response->withHeader('Cache-Control', 'no-store'), 'status', ['application' => $application]);
    }

    public function contract(Request $request, Response $response, string $token): Response
    {
        $application = $this->application($token);
        if (!$application) {
            return $this->redirect($response, '/onboarding');
        }
        return $this->page($response->withHeader('Cache-Control', 'no-store'), 'contract', ['application' => $application]);
    }

    public function sign(Request $request, Response $response, string $token): Response
    {
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
            if ($action === 'reject') {
                $pdo->prepare("UPDATE registrar_onboarding SET status = 'rejected' WHERE id = ?")->execute([$id]);
                $pdo->commit();
                return $this->redirect($response, '/onboarding/admin');
            }
            $d = json_decode($application['application_data'], true, 512, JSON_THROW_ON_ERROR);
            $panelPassword = bin2hex(random_bytes(16)) . 'aA1!';
            $eppPassword = bin2hex(random_bytes(8));
            $now = date('Y-m-d H:i:s');
            $currency = $pdo->query("SELECT value FROM settings WHERE name = 'currency'")->fetchColumn() ?: 'EUR';
            $stmt = $pdo->prepare(
                'INSERT INTO registrar (name, iana_id, clid, pw, prefix, email, url, whois_server,
                 rdap_server, abuse_email, abuse_phone, accountbalance, creditlimit, creditthreshold,
                 thresholdtype, currency, crdate, lastupdate)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 100, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $d['name'], $d['ianaId'] === '' ? null : (int) $d['ianaId'], $d['user_name'],
                PasswordHasher::hash($eppPassword), 'T' . strtoupper(bin2hex(random_bytes(2))),
                $d['email'], $d['url'], $d['whoisServer'], $d['rdapServer'],
                $d['abuseEmail'], $d['abusePhone'], 'fixed', $currency, $now, $now,
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
