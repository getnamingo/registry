<?php
/**
 * msg_worker.php
 *
 * A worker script that continuously pulls messages from a Redis queue and processes them.
 * Uses Swoole's coroutine runtime and Coroutine Redis client.
 */

// Enable strict types if desired
declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Redis;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Adapters\Email\SendGrid;
use Utopia\Messaging\Adapters\Email\Mailgun;
use Utopia\Messaging\Messages\SMS;
use Utopia\Messaging\Adapters\SMS\Twilio;
use Utopia\Messaging\Adapters\SMS\Telesign;
use Utopia\Messaging\Adapters\SMS\Plivo;
use Utopia\Messaging\Adapters\SMS\Vonage;
use Utopia\Messaging\Adapters\SMS\Clickatell;

// Autoload Composer dependencies
require __DIR__ . '/vendor/autoload.php';

// Load configuration and helper functions (assumed to be provided)
$c = require_once 'config.php';
require_once 'helpers.php';

// Set up logger for the worker
$logFilePath = '/var/log/namingo/msg_worker.log';
$logger = setupLogger($logFilePath, 'Msg_Worker');

// Maximum number of retries for a message
$maxRetries    = 3;
$retryQueueKey = 'message_queue_retry';

/**
 * Creates and returns a new Coroutine Redis connection.
 *
 * @return Redis
 * @throws Exception if connection fails.
 */
function connectRedis(): Redis {
    $redis = new Redis();
    $ret = $redis->connect('127.0.0.1', 6379);
    if (!$ret) {
        throw new Exception("Failed to connect to Redis");
    }
    return $redis;
}

// Run the worker inside Swoole's coroutine runtime.
Swoole\Coroutine\run(function() use ($c, $logger, $maxRetries, $retryQueueKey) {
    $redis = connectRedis();
    $logger->info("Worker started, waiting for messages...");

    while (true) {
        try {
            // brPop blocks until a message is available.
            // It returns an array: [queueKey, messageData]
            $result = $redis->brPop(['message_queue', $retryQueueKey], 0);
        } catch (Exception $e) {
            $logger->error("Redis error", ['error' => $e->getMessage()]);
            // Wait before trying to reconnect
            Coroutine::sleep(5);
            try {
                $redis = connectRedis();
            } catch (Exception $ex) {
                $logger->error("Redis reconnection failed", ['error' => $ex->getMessage()]);
                continue;
            }
            continue;
        }

        if (!$result) {
            continue;
        }

        // Decode the message data
        $queueKey = $result[0];
        $data     = json_decode($result[1], true);
        if (!$data) {
            $logger->warning("Received invalid message from Redis", ['raw' => $result[1]]);
            continue;
        }

        try {
            switch ($data['type']) {
                case 'sendmail':
                    if ($c['mailer'] === 'phpmailer') {
                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host       = $c['mailer_smtp_host'];
                            $mail->SMTPAuth   = true;
                            $mail->Username   = $c['mailer_smtp_username'];
                            $mail->Password   = $c['mailer_smtp_password'];
                            $mail->SMTPSecure = 'tls';
                            $mail->Port       = $c['mailer_smtp_port'];
                            $mail->setFrom($c['mailer_from']);
                            $mail->addAddress($data['toEmail']);
                            $mail->Subject  = $data['subject'];
                            $mail->Body     = $data['body'];
                            if (substr($data['body'], 0, 2) === "<!") {
                                $mail->isHTML(true);
                                $mail->AltBody = strip_tags($data['body']);
                            }
                            $mail->send();
                        } catch (PHPMailerException $e) {
                            throw new Exception("PHPMailer error: " . $e->getMessage());
                        }
                    } elseif ($c['mailer'] === 'sendgrid') {
                        $message = new Email(
                            from: [$c['mailer_from']],
                            to:   [$data['toEmail']],
                            subject: $data['subject'],
                            content: $data['body']
                        );
                        $messaging = new SendGrid($c['mailer_api_key']);
                        $messaging->send($message);
                    } elseif ($c['mailer'] === 'mailgun') {
                        $message = new Email(
                            from: [$c['mailer_from']],
                            to:   [$data['toEmail']],
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
                    if ($c['mailer_sms'] === 'twilio') {
                        $message = new SMS(
                            to:      [$data['toSMS']],
                            content: $data['contentSMS']
                        );
                        $messaging = new Twilio($c['mailer_sms_account'], $c['mailer_sms_auth']);
                        $messaging->send($message);
                    } elseif ($c['mailer_sms'] === 'telesign') {
                        $message = new SMS(
                            to:      [$data['toSMS']],
                            content: $data['contentSMS']
                        );
                        $messaging = new Telesign($c['mailer_sms_account'], $c['mailer_sms_auth']);
                        $messaging->send($message);
                    } elseif ($c['mailer_sms'] === 'plivo') {
                        $message = new SMS(
                            to:      [$data['toSMS']],
                            content: $data['contentSMS']
                        );
                        $messaging = new Plivo($c['mailer_sms_account'], $c['mailer_sms_auth']);
                        $messaging->send($message);
                    } elseif ($c['mailer_sms'] === 'vonage') {
                        $message = new SMS(
                            to:      [$data['toSMS']],
                            content: $data['contentSMS']
                        );
                        $messaging = new Vonage($c['mailer_sms_account'], $c['mailer_sms_auth']);
                        $messaging->send($message);
                    } elseif ($c['mailer_sms'] === 'clickatell') {
                        $message = new SMS(
                            to:      [$data['toSMS']],
                            content: $data['contentSMS']
                        );
                        $messaging = new Clickatell($c['mailer_sms_account']);
                        $messaging->send($message);
                    } else {
                        throw new Exception("Invalid SMS provider specified");
                    }
                    break;

                default:
                    throw new Exception("Unknown message type: " . $data['type']);
            }

            $logger->info("Processed message successfully", ['type' => $data['type']]);
        } catch (Exception $e) {
            // Increment the retry counter
            if (!isset($data['retries'])) {
                $data['retries'] = 0;
            }
            $data['retries']++;

            if ($data['retries'] > $maxRetries) {
                $logger->error("Message processing failed after maximum retries", [
                    'type'  => $data['type'],
                    'error' => $e->getMessage()
                ]);
            } else {
                // Requeue the message for a retry
                $redis->lPush($retryQueueKey, json_encode($data));
                $logger->warning("Message processing failed; requeued for retry", [
                    'type'   => $data['type'],
                    'retry'  => $data['retries'],
                    'error'  => $e->getMessage()
                ]);
            }
        }
    }
});
