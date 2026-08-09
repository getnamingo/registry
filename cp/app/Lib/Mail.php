<?php
/**
 * Voras Foundry
 *
 * A modular PHP boilerplate for building SaaS applications, admin panels, and control systems.
 *
 * @package    App
 * @author     Voras Team <help@namingo.org>
 * @copyright  Copyright (c) 2026 Voras
 * @license    MIT License
 * @link       https://github.com/atriohq/foundry
 */

namespace App\Lib;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Adapters\Email\SendGrid;
use Utopia\Messaging\Adapters\Email\Mailgun;

class Mail
{
    public static function send($subject, $body, $from=[], $to=[], $info=[])
    {
        if (envi('MAIL_DRIVER') == 'utopia') {
            try {
                $message = new Email(
                    from: [$from['email']],
                    to: [$to['email']],
                    subject: $subject,
                    content: $body
                );

                // Send email
                if (envi('MAIL_API_PROVIDER') == 'sendgrid') {
                    $messaging = new Sendgrid(envi('MAIL_API_KEY'));
                    $messaging->send($message);
                    return true;
                } else {
                    $messaging = new Mailgun(envi('MAIL_API_KEY'), envi('APP_DOMAIN'));
                    $messaging->send($message);
                    return true;
                }
            } catch (\Exception $e) {
                echo "Message could not be sent. Error: {$e->getMessage()}";
                return false;
            }
        } else if (envi('MAIL_DRIVER') == 'smtp') {
            $mail = new PHPMailer(true);
            try {
                $mail->SMTPDebug = 0;
                $mail->isSMTP();
                $mail->Host = envi('MAIL_HOST');
                $mail->SMTPAuth = true;
                $mail->Username = envi('MAIL_USERNAME');
                $mail->Password = envi('MAIL_PASSWORD');
                $mail->SMTPSecure = envi('MAIL_ENCRYPTION');
                $mail->Port = envi('MAIL_PORT');

                $mail->setFrom($from['email'], $from['name']);
                $mail->addAddress($to['email'], $to['name']);
                //$mail->addAttachment('path/to/invoice1.pdf', 'invoice1.pdf');

                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->send();
                return true;
            } catch (Exception $e) {
                echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
                return false;
            }
        } else if (envi('MAIL_DRIVER') == 'msg') {
            $url = 'http://127.0.0.1:8250';
            $data = [
                'type' => 'sendmail',
                'toEmail' => $to['email'],
                'subject' => $subject,
                'body' => $body,
            ];

            $payload = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );

            $headers = [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ];

            // Empty token preserves backward compatibility during rollout.
            $token = trim((string)envi('MSG_API_TOKEN'));

            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 5,
            ];

            $curl = curl_init($url);
            curl_setopt_array($curl, $options);

            $response = curl_exec($curl);
            $httpStatus = (int)curl_getinfo(
                $curl,
                CURLINFO_RESPONSE_CODE
            );
            $curlError = curl_error($curl);
            $curlErrno = curl_errno($curl);

            curl_close($curl);

            if ($response === false) {
                throw new \RuntimeException(
                    'Message producer connection failed: ' . $curlError,
                    $curlErrno
                );
            }

            if ($httpStatus < 200 || $httpStatus >= 300) {
                $details = trim(substr((string)$response, 0, 500));

                throw new \RuntimeException(
                    sprintf(
                        'Message producer returned HTTP %d%s',
                        $httpStatus,
                        $details !== '' ? ': ' . $details : ''
                    )
                );
            }

            return true;
        } else {
            return true;
        }
    }
}