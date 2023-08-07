<?php

/* USAGE

$url = 'http://127.0.0.1:9501';
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

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

$server = new Server("127.0.0.1", 9501);

$server->on("request", function (Request $request, Response $response) {
    // Parse the received data
    $data = json_decode($request->rawContent(), true);

    if (!$data || !isset($data['type'])) {
        $response->end(json_encode(['status' => 'error', 'message' => 'Invalid request']));
        return;
    }

    switch ($data['type']) {
        case 'sendmail':
            if (isset($data['mailer']) && $data['mailer'] == 'phpmailer') {
                // Use PHPMailer
                $mail = new PHPMailer(true);

                try {
                    $mail->setFrom($data['fromEmail'], $data['fromName']);
                    $mail->addAddress($data['toEmail'], $data['toName']);
                    $mail->Subject = $data['subject'];
                    $mail->Body    = $data['body'];
    
                    $mail->send();
                } catch (Exception $e) {
                    $response->end(json_encode(['status' => 'error', 'message' => 'Mail could not be sent. PHPMailer Error: ' . $mail->ErrorInfo]));
                    return;
                }

            } elseif (isset($data['mailer']) && $data['mailer'] == 'utopia') {
                // Use utopia-php/messaging for email
                // You'd have to set up Utopia's configurations accordingly

            } elseif (isset($data['mailer']) && $data['mailer'] == 'sendmail') {
                // Use Sendmail or another MIT licensed PHP mailer
                // Implement the logic accordingly

            } else {
                $response->end(json_encode(['status' => 'error', 'message' => 'Invalid mailer specified']));
                return;
            }
            break;
    
        case 'sendsms':
            if (isset($data['smsProvider']) && $data['smsProvider'] == 'twilio') {
                // Use Twilio API
                $twilio = new \Twilio\Rest\Client($twilio_sid, $twilio_token);
                $message = $twilio->messages->create(
                    $data['toPhone'], 
                    ['from' => $twilio_phone_number, 'body' => $data['body']]
                );

            } elseif (isset($data['smsProvider']) && $data['smsProvider'] == 'utopia') {
                // Use utopia-php/messaging for SMS
                // You'd have to set up Utopia's configurations and use its API

            } else {
                $response->end(json_encode(['status' => 'error', 'message' => 'Invalid SMS provider specified']));
                return;
            }
            break;

        default:
            $response->end(json_encode(['status' => 'error', 'message' => 'Unknown action']));
            return;
    }

    $response->end(json_encode(['status' => 'success']));
});

$server->start();
