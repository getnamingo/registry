#!/usr/bin/env php
<?php
/**
 * msg_producer.php
 *
 * A Swoole HTTP server that accepts API calls and pushes messages into a Redis queue.
 * Uses Swoole’s Coroutine Redis client with a simple connection pool.
 */

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

require __DIR__ . '/vendor/autoload.php';

$c = require __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$logFilePath = '/var/log/namingo/msg_producer.log';
$logger = setupLogger($logFilePath, 'Msg_Producer');

function normalizeMessage(array $data): array
{
    $type = $data['type'] ?? null;

    if (!is_string($type) || !in_array($type, ['sendmail', 'sendsms'], true)) {
        throw new InvalidArgumentException('Unsupported message type');
    }

    if ($type === 'sendmail') {
        $to = $data['toEmail'] ?? null;
        $subject = $data['subject'] ?? null;
        $body = $data['body'] ?? null;

        if (!is_string($to) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email recipient');
        }

        if (!is_string($subject)
            || strlen($subject) > 998
            || preg_match('/[\r\n]/', $subject)
        ) {
            throw new InvalidArgumentException('Invalid email subject');
        }

        if (!is_string($body) || strlen($body) > 524288) {
            throw new InvalidArgumentException('Invalid email body');
        }

        return [
            'type' => 'sendmail',
            'toEmail' => $to,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    $to = $data['toSMS'] ?? null;
    $content = $data['contentSMS'] ?? null;

    if (!is_string($to) || !preg_match('/^\+[1-9][0-9]{7,14}$/D', $to)) {
        throw new InvalidArgumentException('Invalid SMS recipient');
    }

    if (!is_string($content) || $content === '' || strlen($content) > 4096) {
        throw new InvalidArgumentException('Invalid SMS content');
    }

    return [
        'type' => 'sendsms',
        'toSMS' => $to,
        'contentSMS' => $content,
    ];
}

function jsonResponse(Response $response, int $status, array $body): void
{
    $response->status($status);
    $response->header('Content-Type', 'application/json; charset=utf-8');
    $response->end(json_encode($body, JSON_THROW_ON_ERROR));
}

final class RedisPool
{
    private Swoole\Coroutine\Channel $pool;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $size = 4,
    ) {
        $this->pool = new Swoole\Coroutine\Channel($size);
    }

    /**
     * Initialize pool inside a coroutine context.
     */
    public function initialize(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->pool->push($this->createConnection());
        }
    }

    private function createConnection(): Redis
    {
        $redis = new Redis();

        if (!$redis->connect($this->host, $this->port, 1.0)) {
            throw new RuntimeException(
                "Failed to connect to Redis at {$this->host}:{$this->port}"
            );
        }

        return $redis;
    }

    public function get(float $timeout = 1.0): Redis
    {
        $conn = $this->pool->pop($timeout);

        if (!$conn instanceof Redis) {
            throw new RuntimeException('Redis pool exhausted');
        }

        if (!$conn->isConnected()) {
            return $this->createConnection();
        }

        return $conn;
    }

    public function put(?Redis $redis): void
    {
        if ($redis && $redis->isConnected()) {
            if (!$this->pool->push($redis, 0.05)) {
                $redis->close();
            }
        }
    }

    public function discard(?Redis $redis): void
    {
        if ($redis && $redis->isConnected()) {
            $redis->close();
        }
    }

}

// Create the Swoole HTTP server
$server = new Server("127.0.0.1", 8250);

// Swoole server settings
$server->set([
    'daemonize'  => true,
    'log_file'   => '/var/log/namingo/msg_producer.log',
    'log_level'  => SWOOLE_LOG_INFO,
    'worker_num' => max(1, min(4, swoole_cpu_num())),
    'pid_file'   => '/var/run/msg_producer.pid',
    'enable_coroutine' => true,
    'hook_flags' => SWOOLE_HOOK_ALL,
    'package_max_length' => 1048576,
]);

$redisPool = null;

$server->on("workerStart", function ($server, $workerId) use (&$redisPool, $logger) {
    try {
        $redisPool = new RedisPool('127.0.0.1', 6379, 4);
        $redisPool->initialize();
        $logger->info("Redis pool initialized in worker process {$workerId}");
    } catch (Throwable $e) {
        $redisPool = null;
        $logger->error("Worker {$workerId}: Failed to initialize Redis pool - " . $e->getMessage());
    }
});

// Handle incoming requests
$server->on("request", function (Request $request, Response $response) use (&$redisPool, $logger, $c) {
    if (!$redisPool) {
        $logger->error("Redis pool not initialized");
        jsonResponse($response, 503, ['status' => 'error']);
        return;
    }

    if (strtoupper($request->server['request_method'] ?? '') !== 'POST') {
        $response->status(405);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'status'  => 'error',
            'message' => 'Method Not Allowed'
        ]));
        return;
    }

    $contentType = strtolower((string)($request->header['content-type'] ?? ''));
    if (!str_starts_with($contentType, 'application/json')) {
        jsonResponse($response, 415, ['status' => 'error']);
        return;
    }

    // Optional local bearer authentication. Strongly recommend enabling it.
    $apiToken = (string)($c['msg_api_token'] ?? '');
    if ($apiToken !== '') {
        $authorization = (string)($request->header['authorization'] ?? '');
        $matches = [];

        if (
            preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1
            || !hash_equals($apiToken, trim($matches[1]))
        ) {
            jsonResponse($response, 401, ['status' => 'error']);
            return;
        }
    }

    $raw = $request->rawContent();
    if (!is_string($raw) || $raw === '' || strlen($raw) > 1048576) {
        jsonResponse($response, 413, ['status' => 'error']);
        return;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('JSON object required');
        }

        $data = normalizeMessage($decoded);
    } catch (JsonException|InvalidArgumentException $e) {
        jsonResponse($response, 400, [
            'status' => 'error',
            'message' => $e->getMessage(),
        ]);
        return;
    }

    $data['id'] = bin2hex(random_bytes(16));
    $data['retries'] = 0;
    $data['queuedAt'] = time();
    $payload = json_encode($data, JSON_THROW_ON_ERROR);

    $redis = null;

    try {
        $redis = $redisPool->get();
        $maxDepth = max(100, (int)($c['msg_queue_max_depth'] ?? 50000));
        if ($redis->lLen('message_queue') >= $maxDepth) {
            jsonResponse($response, 503, [
                'status' => 'error',
                'message' => 'Message queue is full',
            ]);
            return;
        }

        if ($redis->lPush('message_queue', $payload) === false) {
            throw new RuntimeException('Redis LPUSH failed');
        }

        // Do not log email/SMS bodies.
        $logger->info('Message queued', [
            'id' => $data['id'],
            'type' => $data['type'],
        ]);

        jsonResponse($response, 202, [
            'status' => 'success',
            'id' => $data['id'],
        ]);
    } catch (Throwable $e) {
        $redisPool->discard($redis);
        $redis = null;

        $logger->error('Failed to queue message', [
            'id' => $data['id'],
            'error' => $e->getMessage(),
        ]);

        jsonResponse($response, 503, ['status' => 'error']);
    } finally {
        $redisPool->put($redis);
    }
});

// Start the server
$logger->info("Starting msg_producer server on 127.0.0.1:8250");
$server->start();

/* USAGE

$url = 'http://127.0.0.1:8250';
$data = ['type' => 'sendmail', 'other_params' => '...'];

$options = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => json_encode($data),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen(json_encode($data))
    ],
];

$curl = curl_init($url);
curl_setopt_array($curl, $options);

$response = curl_exec($curl);

if ($response === false) {
    throw new Exception(curl_error($curl), curl_errno($curl));
}

curl_close($curl);

print_r($response);*/