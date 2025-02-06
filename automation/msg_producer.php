<?php
/**
 * msg_producer.php
 *
 * A Swoole HTTP server that accepts API calls and pushes messages into a Redis queue.
 * Uses Swoole’s Coroutine Redis client with a simple connection pool.
 */

// Enable strict types if you wish
declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Coroutine\Redis;

// Autoload Composer dependencies
require __DIR__ . '/vendor/autoload.php';

// Load configuration and helper functions (assumed to be provided)
$c = require_once 'config.php';
require_once 'helpers.php';

// Set up logger for the producer (adjust log file paths as needed)
$logFilePath = '/var/log/namingo/msg_producer.log';
$logger = setupLogger($logFilePath, 'Msg_Producer');

/**
 * A simple Redis connection pool using Swoole's Coroutine Channel.
 */
class RedisPool {
    private $pool;
    private $host;
    private $port;

    /**
     * Constructor.
     *
     * @param string $host
     * @param int    $port
     * @param int    $size Number of connections to create
     */
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
            Swoole\Coroutine::create(function () {
                $redis = new Redis();
                if (!$redis->connect($this->host, $this->port)) {
                    throw new Exception("Failed to connect to Redis at {$this->host}:{$this->port}");
                }
                $this->pool->push($redis);
            });
        }
    }

    /**
     * Get a Redis connection from the pool.
     */
    public function get(): Redis {
        return $this->pool->pop();
    }

    /**
     * Return a Redis connection back to the pool.
     */
    public function put(Redis $redis): void {
        $this->pool->push($redis);
    }
}

// Create the Swoole HTTP server
$server = new Server("127.0.0.1", 8250);

// Swoole server settings (adjust daemonize to true when running in production)
$server->set([
    'daemonize'  => true, // set to true for daemon mode
    'log_file'   => '/var/log/namingo/msg_producer.log',
    'log_level'  => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file'   => '/var/run/msg_producer.pid'
]);

// Initialize the Redis pool inside a coroutine-friendly context
$server->on("start", function () use (&$redisPool) {
    $redisPool = new RedisPool('127.0.0.1', 6379, 10);
    $redisPool->initialize(10);
});

$server->on("request", function (Request $request, Response $response) use (&$redisPool, $logger) {
    // Handle HTTP request and push messages to Redis
    if (strtoupper($request->server['request_method'] ?? '') !== 'POST') {
        $response->status(405);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'status'  => 'error',
            'message' => 'Method Not Allowed'
        ]));
        return;
    }

    // Decode the incoming JSON data
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

    // Push the message onto the Redis queue
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