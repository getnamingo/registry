<?php

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

use \Utopia\Messaging\Messages\Email;
use \Utopia\Messaging\Adapters\Email\SendGrid;
use \Utopia\Messaging\Adapters\Email\Mailgun;

use \Utopia\Messaging\Messages\SMS;
use \Utopia\Messaging\Adapters\SMS\Twilio;
use \Utopia\Messaging\Adapters\SMS\Telesign;
use \Utopia\Messaging\Adapters\SMS\Plivo;
use \Utopia\Messaging\Adapters\SMS\Vonage;
use \Utopia\Messaging\Adapters\SMS\Clickatell;

require __DIR__ . '/vendor/autoload.php';

$c = require_once 'config.php';
require_once 'helpers.php';

$server = new Server("127.0.0.1", 8250);
$server->set([
    'daemonize' => false,
    'log_file' => '/var/log/namingo/messagebroker_application.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/run/messagebroker.pid'
]);
$logFilePath = '/var/log/namingo/messagebroker.log';
$log = setupLogger($logFilePath, 'Message_Broker');
$log->info('job started.');

$server->on("request", function (Request $request, Response $response) use ($c) {
    // Parse the received data
    $data = json_decode($request->rawContent(), true);

    if (!$data || !isset($data['type'])) {
        $response->end(json_encode(['status' => 'error', 'message' => 'Invalid request']));
        return;
    }

    switch ($data['type']) {
        case 'sendmail':
            if ($c['mailer'] == 'phpmailer') {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = $c['mailer_smtp_host'];
                    $mail->SMTPAuth = true;
                    $mail->Username = $c['mailer_smtp_username'];
                    $mail->Password = $c['mailer_smtp_password'];
                    $mail->SMTPSecure = 'tls';
                    $mail->Port = $c['mailer_smtp_port'];
                    $mail->setFrom($c['mailer_from']);
                    $mail->addAddress($data['toEmail']);
                    $mail->Subject = $data['subject'];
                    $mail->Body    = $data['body'];
                    $mail->send();
                } catch (Exception $e) {
                    $response->end(json_encode(['status' => 'error', 'message' => 'Mail could not be sent. PHPMailer Error: ' . $mail->ErrorInfo]));
                    return;
                }
            } elseif ($c['mailer'] == 'sendgrid') {
                $message = new Email(
                    from: [$c['mailer_from']],
                    to: [$data['toEmail']],
                    subject: $data['subject'],
                    content: $data['body']
                );
                $messaging = new Sendgrid($c['mailer_api_key']);
                $messaging->send($message);
            } elseif ($c['mailer'] == 'mailgun') {
                $message = new Email(
                    from: [$c['mailer_from']],
                    to: [$data['toEmail']],
                    subject: $data['subject'],
                    content: $data['body']
                );
                $messaging = new Mailgun($c['mailer_api_key'], $c['mailer_domain']);
                $messaging->send($message);
            } else {
                $response->end(json_encode(['status' => 'error', 'message' => 'Invalid mailer specified']));
                return;
            }
            break;
    
        case 'sendsms':
            if ($c['mailer_sms'] == 'twilio') {
                $message = new SMS(
                    to: [$data['toSMS']],
                    content: $data['contentSMS']
                );
                $messaging = new Twilio($c['mailer_sms_account'], $c['mailer_sms_auth']);
                $messaging->send($message);
            } elseif ($c['mailer_sms'] == 'telesign') {
                $message = new SMS(
                    to: [$data['toSMS']],
                    content: $data['contentSMS']
                );
                $messaging = new Telesign($c['mailer_sms_account'], $c['mailer_sms_auth']);
                $messaging->send($message);
            } elseif ($c['mailer_sms'] == 'plivo') {
                $message = new SMS(
                    to: [$data['toSMS']],
                    content: $data['contentSMS']
                );
                $messaging = new Plivo($c['mailer_sms_account'], $c['mailer_sms_auth']);
                $messaging->send($message);
            } elseif ($c['mailer_sms'] == 'vonage') {
                $message = new SMS(
                    to: [$data['toSMS']],
                    content: $data['contentSMS']
                );
                $messaging = new Vonage($c['mailer_sms_account'], $c['mailer_sms_auth']);
                $messaging->send($message);
            } elseif ($c['mailer_sms'] == 'clickatell') {
                $message = new SMS(
                    to: [$data['toSMS']],
                    content: $data['contentSMS']
                );
                $messaging = new Clickatell($c['mailer_sms_account']);
                $messaging->send($message);
            } else {
                $response->end(json_encode(['status' => 'error', 'message' => 'Invalid SMS provider specified']));
                return;
            }
            break;

        default:
            $response->end(json_encode(['status' => 'error', 'message' => 'Unknown action']));
            return;
    }
    
    $log->info('job finished successfully.');
    $response->end(json_encode(['status' => 'success']));
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