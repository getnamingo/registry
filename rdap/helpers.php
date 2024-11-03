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
    // Determine which type of disclosure to use
    $disclose_name = ($contactDetails['type'] == 'loc') ? $contactDetails['disclose_name_loc'] : $contactDetails['disclose_name_int'];
    $disclose_org = ($contactDetails['type'] == 'loc') ? $contactDetails['disclose_org_loc'] : $contactDetails['disclose_org_int'];
    $disclose_addr = ($contactDetails['type'] == 'loc') ? $contactDetails['disclose_addr_loc'] : $contactDetails['disclose_addr_int'];
    $disclose_voice = $contactDetails['disclose_voice'];
    $disclose_fax = $contactDetails['disclose_fax'];
    $disclose_email = $contactDetails['disclose_email'];

    return [
        'objectClassName' => 'entity',
        'handle' => 'C' . $contactDetails['id'] . '-' . $c['roid'],
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
                ["fn", new stdClass(), 'text', $disclose_name ? $contactDetails['name'] : "REDACTED FOR PRIVACY"],
                ["org", new stdClass(), 'text', $disclose_org ? $contactDetails['org'] : "REDACTED FOR PRIVACY"],
                ["adr", ["CC" => strtoupper($contactDetails['cc'])], 'text', [ // specify "CC" parameter for country code
                    $disclose_addr ? $contactDetails['street1'] : "REDACTED FOR PRIVACY", // Extended address
                    $disclose_addr ? $contactDetails['street2'] : "REDACTED FOR PRIVACY", // Street address
                    $disclose_addr ? $contactDetails['street3'] : "REDACTED FOR PRIVACY", // Additional street address
                    $disclose_addr ? $contactDetails['city'] : "REDACTED FOR PRIVACY", // Locality
                    $disclose_addr ? $contactDetails['sp'] : "REDACTED FOR PRIVACY", // Region
                    $disclose_addr ? $contactDetails['pc'] : "REDACTED FOR PRIVACY", // Postal code
                    ""  // Add empty last element as required for ADR structure
                ]],
                ["tel", ["type" => "voice"], 'uri', $disclose_voice ? "tel:" . $contactDetails['voice'] : "REDACTED FOR PRIVACY"],
                ["tel", ["type" => "fax"], 'uri', $disclose_fax ? "tel:" . $contactDetails['fax'] : "REDACTED FOR PRIVACY"],
                ["email", new stdClass(), 'text', $disclose_email ? $contactDetails['email'] : "REDACTED FOR PRIVACY"],
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

// Function to update the permitted IPs from the database
function updatePermittedIPs($pool, $permittedIPsTable) {
    $pdo = $pool->get();
    $query = "SELECT addr FROM registrar_whitelist";
    $stmt = $pdo->query($query);
    $permittedIPs = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $pool->put($pdo);

    // Manually clear the table by removing each entry
    foreach ($permittedIPsTable as $key => $value) {
        $permittedIPsTable->del($key);
    }

    // Insert new values
    foreach ($permittedIPs as $ip) {
        $permittedIPsTable->set($ip, ['addr' => $ip]);
    }
}