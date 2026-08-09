<?php
/**
 * msg_worker.php
 *
 * A worker script that continuously pulls messages from a Redis queue and processes them.
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Adapter\Email\Sendgrid;
use Utopia\Messaging\Adapter\Email\Mailgun;
use Utopia\Messaging\Messages\SMS;
use Utopia\Messaging\Adapter\SMS\Twilio;
use Utopia\Messaging\Adapter\SMS\Telesign;
use Utopia\Messaging\Adapter\SMS\Plivo;
use Utopia\Messaging\Adapter\SMS\Vonage;
use Utopia\Messaging\Adapter\SMS\Clickatell;

require __DIR__ . '/vendor/autoload.php';
$c = require __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// Set up logger for the worker
$logFilePath = '/var/log/namingo/msg_worker.log';
$logger = setupLogger($logFilePath, 'Msg_Worker');

// Maximum number of retries for a message
$maxRetries    = 3;
$queueKey = 'message_queue';
$processingQueueKey = 'message_queue_processing';
$deadQueueKey = 'message_queue_dead';
$deadQueueMax = 250;

/**
 * Creates and returns a new Redis connection.
 *
 * @return Redis
 * @throws Exception if connection fails.
 */
function connectRedis(): Redis {
    $redis = new Redis();
    $ret = $redis->connect('127.0.0.1', 6379, 1.0);

    if (!$ret) {
        throw new RuntimeException("Failed to connect to Redis");
    }
    $redis->setOption(Redis::OPT_READ_TIMEOUT, 10.0);
    return $redis;
}

function recoverProcessing(
    Redis $redis,
    string $processingQueue,
    string $queue
): int {
    $count = 0;

    while ($redis->rPopLPush($processingQueue, $queue) !== false) {
        $count++;
    }

    return $count;
}

function transitionMessage(
    Redis $redis,
    string $processingQueue,
    string $destination,
    string $oldPayload,
    string $newPayload,
    ?int $destinationMax = null
): void {
    try {
        $redis->multi();
        $redis->lPush($destination, $newPayload);

        if ($destinationMax !== null) {
            $redis->lTrim($destination, 0, $destinationMax - 1);
        }

        $redis->lRem($processingQueue, $oldPayload, 1);

        if ($redis->exec() === false) {
            throw new RedisException('Queue transaction failed');
        }
    } catch (Throwable $e) {
        try {
            $redis->discard();
        } catch (Throwable) {
        }

        throw $e;
    }
}

function assertDelivered(array $result): void
{
    if (($result['deliveredTo'] ?? 0) > 0) {
        return;
    }

    $error = $result['results'][0]['error'] ?? 'provider rejected message';
    throw new RuntimeException((string)$error);
}

// Run the worker
$redis = connectRedis();
$recovered = recoverProcessing($redis, $processingQueueKey, $queueKey);

$recovered += recoverProcessing(
    $redis,
    'message_queue_retry',
    $queueKey
);

if ($recovered > 0) {
    $logger->warning('Recovered interrupted messages', ['count' => $recovered]);
}

$mail = null;
$emailAdapter = null;
$logger->info("Worker started, waiting for messages...");

while (true) {
    try {
        $raw = $redis->brPopLPush(
            $queueKey,
            $processingQueueKey,
            5
        );
    } catch (Throwable $e) {
        $logger->error("Redis error", ['error' => $e->getMessage()]);
        sleep(1);

        try {
            $redis = connectRedis();

            $recovered = recoverProcessing(
                $redis,
                $processingQueueKey,
                $queueKey
            );

            if ($recovered > 0) {
                $logger->warning('Recovered interrupted messages', [
                    'count' => $recovered,
                ]);
            }
        } catch (Throwable $ex) {
            $logger->error("Redis reconnection failed", ['error' => $ex->getMessage()]);
            continue;
        }

        continue;
    }

    if ($raw === false) {
        continue;
    }

    // Decode the message data
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (
            !is_array($data)
            || !isset($data['type'])
            || !is_string($data['type'])
        ) {
            throw new UnexpectedValueException(
                'Queued message must contain a string type'
            );
        }
    } catch (JsonException|UnexpectedValueException $e) {
        $dead = json_encode([
            'failedAt' => gmdate(DATE_ATOM),
            'error' => $e->getMessage(),
            'rawSha256' => hash('sha256', $raw),
            'rawBase64' => base64_encode($raw),
        ], JSON_THROW_ON_ERROR);

        transitionMessage(
            $redis,
            $processingQueueKey,
            $deadQueueKey,
            $raw,
            $dead,
            $deadQueueMax
        );

        $logger->warning('Invalid queued message moved to DLQ', [
            'sha256' => hash('sha256', $raw),
        ]);
        continue;
    }

    try {
        switch ($data['type']) {
            case 'sendmail':
                if ($c['mailer'] === 'phpmailer') {
                    if (!$mail instanceof PHPMailer) {
                        $mail = new PHPMailer(true);
                        $mail->SMTPDebug = 0;
                        $mail->isSMTP();
                        $mail->Host = $c['mailer_smtp_host'];
                        $mail->SMTPAuth = true;
                        $mail->Username = $c['mailer_smtp_username'];
                        $mail->Password = $c['mailer_smtp_password'];
                        $mail->SMTPSecure = $c['mailer_smtp_encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port = $c['mailer_smtp_port'];
                        $mail->Timeout = (int)($c['mailer_smtp_timeout'] ?? 10);
                        $mail->SMTPKeepAlive = true;
                        $mail->CharSet = 'UTF-8';
                        $mail->setFrom(
                            $c['mailer_from'],
                            $c['mailer_from_name'] ?? 'Registry'
                        );
                    }
                    try {
                        $mail->clearAllRecipients();
                        $mail->addAddress($data['toEmail']);
                        $mail->Subject  = $data['subject'];
                        $mail->Body     = $data['body'];

                        $prefix = strtolower(substr(ltrim($data['body']), 0, 16));
                        $html = str_starts_with($prefix, '<!doctype')
                            || str_starts_with($prefix, '<html');

                        $mail->isHTML($html);
                        $mail->AltBody = '';

                        if ($html) {
                            $mail->AltBody = strip_tags($data['body']);
                        }

                        $mail->send();
                    } catch (Throwable $e) {
                        $mail->smtpClose();
                        throw $e;
                    }
                } elseif (in_array(
                    $c['mailer'],
                    ['sendgrid', 'mailgun'],
                    true
                )) {
                    $message = new Email(
                        to: [$data['toEmail']],
                        subject: $data['subject'],
                        content: $data['body'],
                        fromName: $c['mailer_from_name'] ?? 'Registry',
                        fromEmail: $c['mailer_from'],
                        html: str_starts_with(
                            strtolower(ltrim($data['body'])),
                            '<'
                        )
                    );
                    $emailAdapter ??= match ($c['mailer']) {
                        'sendgrid' => new Sendgrid($c['mailer_api_key']),
                        'mailgun' => new Mailgun(
                            $c['mailer_api_key'],
                            $c['mailer_domain']
                        ),
                        default => throw new RuntimeException('Invalid mailer'),
                    };

                    assertDelivered($emailAdapter->send($message));
                } else {
                    throw new RuntimeException('Invalid mailer specified');
                }
                break;

            case 'sendsms':
                if ($c['mailer_sms'] === 'twilio') {
                    $message = new SMS(
                        to: [$data['toSMS']],
                        content: $data['contentSMS'],
                        from: ($c['mailer_sms_from'] ?? '') ?: null,
                    );
                    $messaging = new Twilio($c['mailer_sms_account'], $c['mailer_sms_auth']);
                    assertDelivered($messaging->send($message));
                } elseif ($c['mailer_sms'] === 'telesign') {
                    $message = new SMS(
                        to: [$data['toSMS']],
                        content: $data['contentSMS'],
                        from: ($c['mailer_sms_from'] ?? '') ?: null,
                    );
                    $messaging = new Telesign($c['mailer_sms_account'], $c['mailer_sms_auth']);
                    assertDelivered($messaging->send($message));
                } elseif ($c['mailer_sms'] === 'plivo') {
                    $message = new SMS(
                        to: [$data['toSMS']],
                        content: $data['contentSMS'],
                        from: ($c['mailer_sms_from'] ?? '') ?: null,
                    );
                    $messaging = new Plivo($c['mailer_sms_account'], $c['mailer_sms_auth']);
                    $messaging->send($message);
                } elseif ($c['mailer_sms'] === 'vonage') {
                    $message = new SMS(
                        to: [$data['toSMS']],
                        content: $data['contentSMS'],
                        from: ($c['mailer_sms_from'] ?? '') ?: null,
                    );
                    $messaging = new Vonage($c['mailer_sms_account'], $c['mailer_sms_auth']);
                    assertDelivered($messaging->send($message));
                } elseif ($c['mailer_sms'] === 'clickatell') {
                    $message = new SMS(
                        to: [$data['toSMS']],
                        content: $data['contentSMS'],
                        from: ($c['mailer_sms_from'] ?? '') ?: null,
                    );
                    $messaging = new Clickatell($c['mailer_sms_account']);
                    assertDelivered($messaging->send($message));
                } else {
                    throw new RuntimeException('Invalid SMS provider specified');
                }
                break;

            default:
                throw new UnexpectedValueException(
                    "Unknown message type: {$data['type']}"
                );
        }

        $removed = $redis->lRem(
            $processingQueueKey,
            $raw,
            1
        );

        if ($removed !== 1) {
            $logger->warning('Message ACK was already absent', [
                'id' => $data['id'] ?? null,
            ]);
        }
 
        $logger->info("Processed message successfully", [
            'id' => $data['id'] ?? null,
            'type' => $data['type'],
        ]);

    } catch (RedisException $e) {
        /*
         * A Redis ACK failure is not a provider delivery failure. Do not
         * increment delivery retries here. Reconnect and recover the item.
         *
         * This can produce a duplicate if the provider accepted the message
         * immediately before Redis failed, which is normal at-least-once
         * behaviour.
         */
        $logger->error('Redis error while acknowledging message', [
            'id' => $data['id'] ?? null,
            'error' => $e->getMessage(),
        ]);

        sleep(1);

        try {
            $redis = connectRedis();
            $recovered = recoverProcessing(
                $redis,
                $processingQueueKey,
                $queueKey
            );

            if ($recovered > 0) {
                $logger->warning('Recovered interrupted messages', [
                    'count' => $recovered,
                ]);
            }
        } catch (Throwable $reconnectError) {
            $logger->error('Redis reconnection failed', [
                'error' => $reconnectError->getMessage(),
            ]);
        }
    } catch (Throwable $e) {
        if (!isset($data['retries'])) {
            $data['retries'] = 0;
        }

        $data['retries']++;
        $error = substr($e->getMessage(), 0, 512);

        if ($data['retries'] > $maxRetries) {
            $dead = json_encode([
                'failedAt' => gmdate(DATE_ATOM),
                'error' => $error,
                'message' => $data,
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

            transitionMessage(
                $redis,
                $processingQueueKey,
                $deadQueueKey,
                $raw,
                $dead,
                $deadQueueMax
            );

            $logger->error("Message processing failed after maximum retries", [
                'id' => $data['id'] ?? null,
                'type' => $data['type'],
                'error' => $error,
            ]);
        } else {
            $retryPayload = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
            );

            // LPUSH + worker BRPOP preserves FIFO and avoids retry-queue
            // starvation.
            transitionMessage(
                $redis,
                $processingQueueKey,
                $queueKey,
                $raw,
                $retryPayload
            );

            $logger->warning("Message processing failed; requeued for retry", [
                'id' => $data['id'] ?? null,
                'type' => $data['type'],
                'retry' => $data['retries'],
                'error' => $error,
            ]);
        }
    }
}