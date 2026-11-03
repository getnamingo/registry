<?php

/*
 * Namingo Registry
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

namespace App\Controllers;

use Firebase\JWT\JWT;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RegistryApiController extends Controller
{
    private function json(Response $response, array $body, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')->withStatus($status);
    }

    // One shared key per registrar; only role 0 or that registrar's role 4 may rotate it.
    public function key(Request $request, Response $response, ?string $registrar = null): Response
    {
        $role = (int) ($_SESSION['auth_roles'] ?? -1);
        $admin = $role === 0;
        if (!$admin && ($role & 4) !== 4) {
            return $response->withStatus(403);
        }
        $secret = (string) envi('REGISTRY_API_JWT_SECRET', '');
        if (strlen($secret) < 32) {
            $this->container->get('flash')->addMessage(
                'error',
                'Registry API is not configured. Set REGISTRY_API_JWT_SECRET in cp/.env to at least 32 random characters, then clear cache.'
            );

            return $response->withHeader('Location', '/dashboard')->withStatus(303);
        }

        $pdo = $this->container->get('pdo');
        if ($admin) {
            if ($registrar === null) {
                return $response->withStatus(400);
            }
            $stmt = $pdo->prepare('SELECT id, clid, name, api_key_id FROM registrar WHERE clid = ?');
            $stmt->execute([$registrar]);
        } else {
            if ($registrar !== null) {
                return $response->withStatus(403);
            }
            $stmt = $pdo->prepare('SELECT r.id, r.clid, r.name, r.api_key_id FROM registrar r JOIN registrar_users ru ON ru.registrar_id = r.id WHERE ru.user_id = ? LIMIT 1');
            $stmt->execute([$_SESSION['auth_user_id']]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return $response->withStatus(404);
        }

        if ($request->getMethod() === 'POST') {
            $jti = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare('UPDATE registrar SET api_key_id = ? WHERE id = ?');
            $stmt->execute([$jti, $row['id']]);
            $this->container->get('flash')->addMessage('success', 'API key regenerated. The previous key is now invalid.');
            return $response->withHeader('Location', '/registrar/api-key' . ($admin ? '/' . rawurlencode($row['clid']) : ''))
                ->withStatus(303);
        }

        $token = $row['api_key_id'] ? JWT::encode([
            'aud' => 'registry-api',
            'sub' => (string) $row['id'],
            'jti' => $row['api_key_id'],
        ], $secret, 'HS256') : null;

        $uri = $request->getUri()->getPath();

        return view($response, 'admin/registrars/apiKey.twig', [
            'registrar' => $row, 'apiKey' => $token, 'isAdmin' => $admin, 'currentUri' => $uri,
        ])->withHeader('Cache-Control', 'no-store')
          ->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function availability(Request $request, Response $response): Response
    {
        $raw = $request->getQueryParams()['domain'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return $this->json($response, ['status' => 'error', 'message' => 'Domain parameter is missing.'], 400);
        }
        $domain = idn_to_ascii(strtolower(trim($raw)), IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($domain === false || strlen($domain) > 68 ||
            !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain)) {
            return $this->json($response, ['status' => 'error', 'message' => 'Domain name invalid format.'], 400);
        }
        $pdo = $this->container->get('pdo');
        $tlds = $pdo->query('SELECT tld, idn_table FROM domain_tld ORDER BY LENGTH(tld) DESC');
        $tldRow = null;
        $label = '';
        foreach ($tlds as $tld) {
            if (str_ends_with($domain, strtolower($tld['tld']))) {
                $label = substr($domain, 0, -strlen($tld['tld']));
                if ($label !== '' && !str_contains($label, '.')) {
                    $tldRow = $tld;
                    break;
                }
            }
        }
        if ($tldRow === null) {
            return $this->json($response, ['status' => 'error', 'message' => 'Invalid TLD. Please search only allowed TLDs.'], 400);
        }

        $stmt = $pdo->prepare('SELECT 1 FROM reserved_domain_names WHERE name = ? OR name = ? LIMIT 1');
        $stmt->execute([$label, $domain]);
        if ($stmt->fetchColumn()) {
            return $this->json($response, ['status' => 'error', 'message' => 'Domain name is reserved or restricted.'], 400);
        }
        $unicodeLabel = idn_to_utf8($label, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);
        if ($unicodeLabel === false || preg_match($tldRow['idn_table'], $unicodeLabel) !== 1) {
            return $this->json($response, ['status' => 'error', 'message' => 'Domain name invalid IDN characters.'], 400);
        }

        $stmt = $pdo->prepare('SELECT 1 FROM domain WHERE name = ? LIMIT 1');
        $stmt->execute([$domain]);
        return $stmt->fetchColumn()
            ? $this->json($response, ['status' => 'info', 'message' => 'Domain is not available', 'domain' => $domain])
            : $this->json($response, ['status' => 'success', 'message' => 'Domain is available', 'domain' => $domain]);
    }

    public function droplist(Request $request, Response $response): Response
    {
        $until = (new \DateTimeImmutable('+90 days'))->format('Y-m-d H:i:s');
        $stmt = $this->container->get('pdo')->prepare(
            'SELECT name, crdate, exdate, delTime AS deletion_time, resTime AS restoration_time FROM domain WHERE exdate <= ? ORDER BY exdate ASC'
        );
        $stmt->execute([$until]);
        $rows = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rows[] = [
                'domain' => $row['name'],
                'created' => $row['crdate'],
                'expires' => $row['exdate'],
                'deletion' => $row['deletion_time'],
                'restored' => $row['restoration_time'],
                'drops' => $row['deletion_time'] ? (new \DateTimeImmutable($row['deletion_time']))->modify('+35 days')->format('Y-m-d H:i:s') : null,
            ];
        }
        return $this->json($response, $rows);
    }

    public function registrars(Request $request, Response $response): Response
    {
        $stmt = $this->container->get('pdo')->query('SELECT name, url, abuse_email, iana_id FROM registrar');
        $rows = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rows[] = [
                'name' => $row['name'] ?: 'N/A',
                'url' => $row['url'] ?: 'N/A',
                'abuse_email' => $row['abuse_email'] ?: 'N/A',
                'iana_id' => $row['iana_id'] ?: 'N/A',
            ];
        }
        return $this->json($response, $rows);
    }
}