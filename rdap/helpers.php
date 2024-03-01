<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Sets up and returns a Logger instance.
 * 
 * @param string $logFilePath Full path to the log file.
 * @param string $channelName Name of the log channel (optional).
 * @return Logger
 */
function setupLogger($logFilePath, $channelName = 'app') {
    // Create a log channel
    $log = new Logger($channelName);

    // Set up the console handler
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u", // Date format
        true, // Allow inline line breaks
        true  // Ignore empty context and extra
    );
    $consoleHandler->setFormatter($consoleFormatter);
    $log->pushHandler($consoleHandler);

    // Set up the file handler
    $fileHandler = new RotatingFileHandler($logFilePath, 0, Logger::DEBUG);
    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u" // Date format
    );
    $fileHandler->setFormatter($fileFormatter);
    $log->pushHandler($fileHandler);

    return $log;
}

function mapContactToVCard($contactDetails, $role, $c) {
    return [
        'objectClassName' => 'entity',
        'handle' => ['C' . $contactDetails['identifier'] . '-' . $c['roid']],
        'roles' => [$role],
        'remarks' => [
            [
                "description" => [
                    "This object's data has been partially omitted for privacy.",
                    "Only the registrar managing the record can view personal contact data."
                ],
                "links" => [
                    [
                        "href" => "https://namingo.org",
                        "rel" => "alternate",
                        "type" => "text/html"
                    ]
                ],
                "title" => "REDACTED FOR PRIVACY",
                "type" => "Details are withheld due to privacy restrictions."
            ],
            [
                "description" => [
                    "To obtain contact information for the domain registrant, please refer to the Registrar of Record's RDDS service as indicated in this report."
                ],
                "title" => "EMAIL REDACTED FOR PRIVACY",
                "type" => "Details are withheld due to privacy restrictions."
            ],
        ],
        'vcardArray' => [
            "vcard",
            [
                ['version', new stdClass(), 'text', '4.0'],
                ["fn", new stdClass(), 'text', $contactDetails['name']],
                ["org", $contactDetails['org']],
                ["adr", [
                    "", // Post office box
                    $contactDetails['street1'], // Extended address
                    $contactDetails['street2'], // Street address
                    $contactDetails['city'], // Locality
                    $contactDetails['sp'], // Region
                    $contactDetails['pc'], // Postal code
                    $contactDetails['cc']  // Country name
                ]],
                ["tel", $contactDetails['voice'], ["type" => "voice"]],
                ["tel", $contactDetails['fax'], ["type" => "fax"]],
                ["email", $contactDetails['email']],
            ]
        ],
    ];
}

function isIpWhitelisted($ip, $pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM registrar_whitelist WHERE addr = ?");
    $stmt->execute([$ip]);
    $count = $stmt->fetchColumn();
    return $count > 0;
}