<?php

declare(strict_types=1);

/**
 * Register and maintain Revolut Card and Revolut Pay webhooks.
 *
 * No database gateway IDs are used. Namingo identifies the integrations by
 * their fixed gateway names: revolut and revolutpay.
 *
 * Usage:
 *   php bin/revolut-webhooks.php webhook:list
 *   php bin/revolut-webhooks.php webhook:register [all|revolut|revolutpay]
 *   php bin/revolut-webhooks.php webhook:delete <webhook-id>
 */

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false || !is_file($projectRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Namingo vendor/autoload.php was not found. Run composer install first.\n");
    exit(1);
}

require $projectRoot . '/vendor/autoload.php';

try {
    Dotenv::createImmutable($projectRoot)->load();

    $command = trim((string)($argv[1] ?? ''));
    $appUrl = rtrim(trim((string)($_ENV['APP_URL'] ?? '')), '/');
    $apiKey = trim((string)($_ENV['REVOLUT_SECRET_KEY'] ?? ''));
    $apiVersion = trim((string)($_ENV['REVOLUT_API_VERSION'] ?? '')) ?: '2024-09-01';
    $sandboxValue = strtolower(trim((string)($_ENV['REVOLUT_SANDBOX'] ?? '')));
    $sandbox = in_array($sandboxValue, ['1', 'true', 'yes', 'on'], true);

    if ($command === '') {
        usage(1);
    }
    if ($apiKey === '') {
        throw new RuntimeException('REVOLUT_SECRET_KEY is not configured in .env.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $apiVersion)) {
        throw new RuntimeException('REVOLUT_API_VERSION must use YYYY-MM-DD format.');
    }

    $baseUri = $sandbox
        ? 'https://sandbox-merchant.revolut.com/'
        : 'https://merchant.revolut.com/';
    $client = new Client([
        'base_uri' => $baseUri,
        'timeout' => 30,
        'allow_redirects' => false,
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
            'Revolut-Api-Version' => $apiVersion,
            'User-Agent' => 'Namingo-Revolut-Webhooks/1.0',
        ],
    ]);

    switch ($command) {
        case 'webhook:list':
            $webhooks = listWebhooks($client);
            if ($webhooks === []) {
                echo "No webhooks are registered in the selected Revolut environment.\n";
                break;
            }

            foreach ($webhooks as $webhook) {
                printf(
                    "%s\t%s\t%s\n",
                    (string)($webhook['id'] ?? '?'),
                    (string)($webhook['url'] ?? '?'),
                    implode(',', webhookEvents($webhook))
                );
            }
            break;

        case 'webhook:register':
            validatePublicAppUrl($appUrl);
            $target = strtolower(trim((string)($argv[2] ?? 'all')));
            $definitions = webhookDefinitions($appUrl);
            if ($target !== 'all' && !isset($definitions[$target])) {
                usage(1);
            }

            $selected = $target === 'all'
                ? $definitions
                : [$target => $definitions[$target]];
            $registered = listWebhooks($client);

            foreach ($selected as $gateway => $definition) {
                $matches = array_values(array_filter(
                    $registered,
                    static fn (array $webhook): bool => rtrim(
                        (string)($webhook['url'] ?? ''),
                        '/'
                    ) === rtrim($definition['url'], '/')
                ));

                if (count($matches) > 1) {
                    $ids = array_map(
                        static fn (array $webhook): string => (string)($webhook['id'] ?? '?'),
                        $matches
                    );
                    throw new RuntimeException(sprintf(
                        'Multiple webhooks already use %s (%s). Delete the duplicates first.',
                        $definition['url'],
                        implode(', ', $ids)
                    ));
                }

                if ($matches === []) {
                    $webhook = revolutRequest($client, 'POST', 'api/webhooks', [
                        'json' => [
                            'url' => $definition['url'],
                            'events' => ['ORDER_COMPLETED'],
                        ],
                    ]);
                    $action = 'Registered';
                    $registered[] = $webhook;
                } else {
                    $webhookId = trim((string)($matches[0]['id'] ?? ''));
                    if (!validWebhookId($webhookId)) {
                        throw new RuntimeException('Revolut returned an invalid existing webhook ID.');
                    }

                    $webhook = revolutRequest(
                        $client,
                        'GET',
                        'api/webhooks/' . rawurlencode($webhookId)
                    );
                    if (!in_array('ORDER_COMPLETED', webhookEvents($webhook), true)) {
                        $events = array_values(array_unique([
                            ...webhookEvents($webhook),
                            'ORDER_COMPLETED',
                        ]));
                        $webhook = revolutRequest(
                            $client,
                            'PATCH',
                            'api/webhooks/' . rawurlencode($webhookId),
                            ['json' => ['events' => $events]]
                        );
                    }
                    $action = 'Reused';
                }

                $webhookId = trim((string)($webhook['id'] ?? ''));
                $signingSecret = trim((string)($webhook['signing_secret'] ?? ''));
                if (!validWebhookId($webhookId) || $signingSecret === '') {
                    throw new RuntimeException(sprintf(
                        'Revolut did not return complete details for the %s webhook.',
                        $gateway
                    ));
                }

                updateEnvValue($projectRoot . '/.env', $definition['env'], $signingSecret);
                printf(
                    "%s %s webhook %s for %s; saved %s to .env.\n",
                    $action,
                    $gateway,
                    $webhookId,
                    $definition['url'],
                    $definition['env']
                );
            }

            echo "Restart PHP-FPM or clear your deployment's environment cache before testing.\n";
            break;

        case 'webhook:delete':
            $webhookId = trim((string)($argv[2] ?? ''));
            if (!validWebhookId($webhookId)) {
                usage(1);
            }

            $webhook = revolutRequest(
                $client,
                'GET',
                'api/webhooks/' . rawurlencode($webhookId)
            );
            revolutRequest(
                $client,
                'DELETE',
                'api/webhooks/' . rawurlencode($webhookId)
            );

            $deletedUrl = rtrim(trim((string)($webhook['url'] ?? '')), '/');
            if ($appUrl !== '') {
                foreach (webhookDefinitions($appUrl) as $definition) {
                    if ($deletedUrl === rtrim($definition['url'], '/')) {
                        updateEnvValue($projectRoot . '/.env', $definition['env'], '');
                        printf("Cleared %s in .env.\n", $definition['env']);
                    }
                }
            }
            printf("Deleted Revolut webhook %s.\n", $webhookId);
            break;

        default:
            usage(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @return array<string, array{url: string, env: string}>
 */
function webhookDefinitions(string $appUrl): array
{
    return [
        'revolut' => [
            'url' => $appUrl . '/payment/revolut/webhook',
            'env' => 'REVOLUT_CARD_WEBHOOK_SECRET',
        ],
        'revolutpay' => [
            'url' => $appUrl . '/payment/revolutpay/webhook',
            'env' => 'REVOLUT_PAY_WEBHOOK_SECRET',
        ],
    ];
}

/** @return list<array<string, mixed>> */
function listWebhooks(Client $client): array
{
    $result = revolutRequest($client, 'GET', 'api/webhooks');
    $webhooks = is_array($result['webhooks'] ?? null)
        ? $result['webhooks']
        : (array_is_list($result) ? $result : []);

    return array_values(array_filter($webhooks, 'is_array'));
}

/** @return list<string> */
function webhookEvents(array $webhook): array
{
    if (!is_array($webhook['events'] ?? null)) {
        return [];
    }

    return array_values(array_filter(
        array_map(
            static fn (mixed $event): string => strtoupper(trim((string)$event)),
            $webhook['events']
        ),
        static fn (string $event): bool => $event !== ''
    ));
}

/** @return array<string, mixed> */
function revolutRequest(
    Client $client,
    string $method,
    string $path,
    array $options = []
): array {
    try {
        $response = $client->request($method, ltrim($path, '/'), $options);
    } catch (GuzzleException $exception) {
        $message = 'Revolut API request failed.';
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $body = (string)$exception->getResponse()->getBody();
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                foreach (['message', 'error_description', 'error'] as $key) {
                    if (is_string($decoded[$key] ?? null) && trim($decoded[$key]) !== '') {
                        $message .= ' ' . trim($decoded[$key]);
                        break;
                    }
                }
            }
            if ($message === 'Revolut API request failed.') {
                $message .= ' HTTP ' . $exception->getResponse()->getStatusCode() . '.';
            }
        }
        throw new RuntimeException($message, 0, $exception);
    }

    $raw = $response->getBody()->getContents();
    if (trim($raw) === '') {
        return [];
    }

    try {
        $result = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Revolut returned invalid JSON.', 0, $exception);
    }
    if (!is_array($result)) {
        throw new RuntimeException('Revolut returned an invalid response.');
    }

    return $result;
}

function validatePublicAppUrl(string $appUrl): void
{
    if (filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('APP_URL is missing or invalid in .env.');
    }

    $parts = parse_url($appUrl);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || $host === 'localhost') {
        throw new RuntimeException('APP_URL must be a public HTTPS URL for Revolut webhooks.');
    }
}

function validWebhookId(string $webhookId): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $webhookId
    ) === 1;
}

function updateEnvValue(string $envFile, string $name, string $value): void
{
    if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
        throw new InvalidArgumentException('Invalid environment variable name.');
    }
    if (str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new RuntimeException('Refusing to write a multiline environment value.');
    }

    $handle = fopen($envFile, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open Namingo .env for writing.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock Namingo .env.');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        if ($contents === false) {
            throw new RuntimeException('Unable to read Namingo .env.');
        }

        $encoded = "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        $line = $name . '=' . $encoded;
        $pattern = '/^(?:export\s+)?' . preg_quote($name, '/') . '=.*$/m';
        if (preg_match($pattern, $contents) === 1) {
            $updated = preg_replace($pattern, $line, $contents, 1);
            if ($updated === null) {
                throw new RuntimeException('Unable to update Namingo .env.');
            }
        } else {
            $updated = rtrim($contents, "\r\n") . PHP_EOL . $line . PHP_EOL;
        }

        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $updated) === false || !fflush($handle)) {
            throw new RuntimeException('Unable to save Namingo .env.');
        }
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function usage(int $status = 0): never
{
    $stream = $status === 0 ? STDOUT : STDERR;
    fwrite($stream, <<<TEXT
Namingo Revolut webhook maintenance

Usage:
  php bin/revolut-webhooks.php webhook:list
  php bin/revolut-webhooks.php webhook:register [all|revolut|revolutpay]
  php bin/revolut-webhooks.php webhook:delete <webhook-id>

The register command defaults to "all" and saves each signing secret to .env.

TEXT);
    exit($status);
}