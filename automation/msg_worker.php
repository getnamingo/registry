<?php

require __DIR__ . '/vendor/autoload.php';

use Predis\Client as RedisClient;
use Predis\Connection\ConnectionException;

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

$c = require_once 'config.php';
require_once 'helpers.php';

// Setup initial Redis client
function setupRedisClient() {
    return new RedisClient([
        'scheme' => 'tcp',
        'host'   => '127.0.0.1',
        'port'   => 6379,
        'persistent' => true,
    ]);
}

$redis = setupRedisClient();

$logFilePath = '/var/log/namingo/msg_worker.log';
$log = setupLogger($logFilePath, 'Msg_Worker');
$log->info('job started.');

// Maximum number of retries for a message
$maxRetries = 3;
// Key for retry messages to avoid infinite loops
$retryQueueKey = 'message_queue_retry';

while (true) {
    try {
        $rawData = $redis->brpop(['message_queue', $retryQueueKey], 0);
    } catch (ConnectionException $e) {
        //$log->error("Redis connection lost. Attempting to reconnect.", ['error' => $e->getMessage()]);
        sleep(5); // Pause before retrying to avoid flooding logs and giving time to recover
        $redis = setupRedisClient(); // Attempt to reconnect
        continue; // Skip the current iteration and try again
    }

    if (!$rawData) {
        continue; // In case of an empty or failed read, continue to the next iteration
    }

    $queueKey = $rawData[0];
    $data = json_decode($rawData[1], true);

    try {
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
                        if (substr($data['body'], 0, 2) === "<!") {
                            $mail->isHTML(true);
                        }
                        $mail->Port = $c['mailer_smtp_port'];
                        $mail->setFrom($c['mailer_from']);
                        $mail->addAddress($data['toEmail']);
                        $mail->Subject = $data['subject'];
                        $mail->Body    = $data['body'];
                        if (substr($data['body'], 0, 2) === "<!") {
                            $mail->AltBody = $data['body'];
                        }
                        $mail->send();
                    } catch (Exception $e) {
                        throw new Exception("Failed to send email");
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
                    throw new Exception("Invalid mailer specified");
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
                    throw new Exception("Invalid SMS provider specified");
                }
                break;

            default:
                throw new Exception("Unknown action");
        }
        
        $log->info("Processed message successfully", ['type' => $data['type']]);
    } catch (Exception $e) {
        // Check if this message has been retried too many times
        if (!isset($data['retries'])) {
            $data['retries'] = 0;
        }
        
        $data['retries']++;
        
        if ($data['retries'] > $maxRetries) {
            // Log failure after exceeding retries
            $log->error("Message processing failed after retries", ['type' => $data['type'], 'error' => $e->getMessage()]);
        } else {
            // Re-queue the message for retry
            $redis->lpush($retryQueueKey, json_encode($data));
            $log->warning("Message processing failed, retrying", ['type' => $data['type'], 'retry' => $data['retries'], 'error' => $e->getMessage()]);
        }
    }
}