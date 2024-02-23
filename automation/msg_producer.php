<?php

require __DIR__ . '/vendor/autoload.php';

use Predis\Client as RedisClient;
use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

$c = require_once 'config.php';
require_once 'helpers.php';

$redis = new RedisClient([
    'scheme' => 'tcp',
    'host'   => '127.0.0.1',
    'port'   => 6379,
]);

$server = new Server("127.0.0.1", 8250);
$server->set([
    'daemonize' => false,
    'log_file' => '/var/log/namingo/msg_producer_app.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/run/msg_producer.pid'
]);
$logFilePath = '/var/log/namingo/msg_producer.log';
$log = setupLogger($logFilePath, 'Msg_Producer');
$log->info('job started.');

$server->on("request", function (Request $request, Response $response) use ($redis) {
    $data = json_decode($request->rawContent(), true);

    if (!$data || !isset($data['type'])) {
        $response->end(json_encode(['status' => 'error', 'message' => 'Invalid request']));
        return;
    }

    // Enqueue the message
    $redis->lpush('message_queue', json_encode($data));
    
    $response->end(json_encode(['status' => 'success', 'message' => 'Message queued for delivery']));
});

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