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

$c = require_once 'config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/msg_producer.log';
$logger = setupLogger($logFilePath, 'Msg_Producer');

class RedisPool {
    private $pool;
    private $host;
    private $port;

    public function __construct(string $host, int $port, int $size = 10) {
        $this->pool = new Swoole\Coroutine\Channel($size);
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Initialize pool inside a coroutine context.
     */
    public function initialize(int $size = 10): void {
        for ($i = 0; $i < $size; $i++) {
            go(function () {
                $redis = new Redis();
                if (!$redis->connect($this->host, $this->port)) {
                    throw new Exception("Failed to connect to Redis at {$this->host}:{$this->port}");
                }
                $this->pool->push($redis);
                echo "Added Redis connection to pool\n"; // Debugging log
            });
        }
    }

    /**
     * Get a Redis connection from the pool.
     * Optionally, you can add a timeout to avoid indefinite blocking.
     */
    public function get(float $timeout = 2.0): Redis {
        $conn = $this->pool->pop($timeout);
        if (!$conn) {
            throw new Exception("No available Redis connection in pool");
        }
        return $conn;
    }

    /**
     * Return a Redis connection back to the pool.
     */
    public function put(?Redis $redis): void {
        if ($redis && $redis->isConnected()) {
            $this->pool->push($redis);
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
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file'   => '/var/run/msg_producer.pid',
    'enable_coroutine' => true
]);

/**
 * Instead of initializing the Redis pool in the "start" event (which runs in the master process),
 * we initialize it in the "workerStart" event so that it runs in a coroutine-enabled worker process.
 */
$server->on("workerStart", function ($server, $workerId) use (&$logger) {
    try {
        $server->redisPool = new RedisPool('127.0.0.1', 6379, 10); // Store in server object
        $server->redisPool->initialize(10);
        $logger->info("Redis pool initialized in worker process {$workerId}");
    } catch (Exception $e) {
        $logger->error("Worker {$workerId}: Failed to initialize Redis pool - " . $e->getMessage());
    }
});

// Handle incoming requests
$server->on("request", function (Request $request, Response $response) use ($server, $logger) {
    $redisPool = $server->redisPool ?? null;

    if (!$redisPool) {
        $logger->error("Redis pool not initialized");
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'status'  => 'error',
            'message' => 'Redis pool not initialized'
        ]));
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

    $data = json_decode($request->rawContent(), true);
    if (!$data || empty($data['type'])) {
        $response->status(400);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'status'  => 'error',
            'message' => 'Invalid request: missing JSON data or "type" field'
        ]));
        return;
    }

    try {
        $redis = $redisPool->get();
        $redis->lPush('message_queue', json_encode($data));
        $redisPool->put($redis);

        $logger->info("Message queued", ['data' => $data]);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'status'  => 'success',
            'message' => 'Message queued for delivery'
        ]));
    } catch (Exception $e) {
        $logger->error("Failed to queue message", ['error' => $e->getMessage()]);
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'status'  => 'error',
            'message' => 'Internal Server Error'
        ]));
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