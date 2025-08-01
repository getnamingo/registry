<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use MonologPHPMailer\PHPMailerHandler;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sets up and returns a Logger instance.
 * 
 * @param string $logFilePath Full path to the log file.
 * @param string $channelName Name of the log channel (optional).
 * @return Logger
 */
function setupLogger($logFilePath, $channelName = 'app') {
    $log = new Logger($channelName);
    
    // Load email & pushover configuration
    $config = include('/opt/registry/automation/config.php');

    // Console handler (for real-time debugging)
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u",
        true,
        true
    );
    $consoleHandler->setFormatter($consoleFormatter);
    $log->pushHandler($consoleHandler);

    // File handler - Rotates daily, keeps logs for 14 days
    $fileHandler = new RotatingFileHandler($logFilePath, 14, Logger::DEBUG);
    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u"
    );
    $fileHandler->setFormatter($fileFormatter);
    $log->pushHandler($fileHandler);

    // Email Handler (For CRITICAL, ALERT, EMERGENCY)
    if (!empty($config['mailer_smtp_host'])) {
        // Create a PHPMailer instance
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $config['mailer_smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['mailer_smtp_username'];
            $mail->Password   = $config['mailer_smtp_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $config['mailer_smtp_port'];
            $mail->setFrom($config['mailer_from'], 'Registry System');
            $mail->addAddress($config['admin_email']);

            // Attach PHPMailer to Monolog
            $mailerHandler = new PHPMailerHandler($mail);
            $mailerHandler->setFormatter(new HtmlFormatter);

            $filteredMailHandler = new FilterHandler($mailerHandler, Logger::ALERT, Logger::EMERGENCY);
            $safeMailHandler = new WhatFailureGroupHandler([$filteredMailHandler]);
            $log->pushHandler($safeMailHandler);
        } catch (Exception $e) {
            error_log("Failed to initialize PHPMailer: " . $e->getMessage());
        }
    }

    return $log;
}

function mapContactToVCard($contactDetails, $role, $roid) {
    // Determine which type of disclosure to use
    $disclose_name = ($contactDetails['type'] == 'loc') ? $contactDetails['disclose_name_loc'] : $contactDetails['disclose_name_int'];
    $disclose_org = ($contactDetails['type'] == 'loc') ? $contactDetails['disclose_org_loc'] : $contactDetails['disclose_org_int'];
    $disclose_addr = ($contactDetails['type'] == 'loc') ? $contactDetails['disclose_addr_loc'] : $contactDetails['disclose_addr_int'];
    $disclose_voice = $contactDetails['disclose_voice'];
    $disclose_fax = $contactDetails['disclose_fax'];
    $disclose_email = $contactDetails['disclose_email'];

    return [
        'objectClassName' => 'entity',
        'handle' => 'C' . $contactDetails['id'] . '-' . $roid,
        'roles' => [$role],
        'remarks' => [
            [
                "description" => [
                    "REDACTED FOR PRIVACY",
                    "Visit www.icann.org/privacy for details."
                ],
                "title" => "REDACTED FOR PRIVACY",
                "type" => "object redacted due to authorization"
            ],
            [
                "description" => [
                    "To obtain contact information for the domain registrant, please refer to the Registrar of Record's RDDS service as indicated in this report."
                ],
                "title" => "EMAIL REDACTED FOR PRIVACY",
                "type" => "object truncated due to authorization"
            ],
        ],
        'vcardArray' => [
            "vcard",
            [
                ['version', new stdClass(), 'text', '4.0'],
                ["fn", new stdClass(), 'text', $disclose_name ? $contactDetails['name'] : "REDACTED FOR PRIVACY"],
                ["org", new stdClass(), 'text', $disclose_org ? $contactDetails['org'] : "REDACTED FOR PRIVACY"],
                ["adr", ["cc" => strtoupper($contactDetails['cc'])], 'text', [ // specify "cc" parameter for country code
                    $disclose_addr ? $contactDetails['street1'] : "REDACTED FOR PRIVACY", // Extended address
                    $disclose_addr ? $contactDetails['street2'] : "REDACTED FOR PRIVACY", // Street address
                    $disclose_addr ? $contactDetails['street3'] : "REDACTED FOR PRIVACY", // Additional street address
                    $disclose_addr ? $contactDetails['city'] : "REDACTED FOR PRIVACY", // Locality
                    $disclose_addr ? $contactDetails['sp'] : "REDACTED FOR PRIVACY", // Region
                    $disclose_addr ? $contactDetails['pc'] : "REDACTED FOR PRIVACY", // Postal code
                    ""  // Add empty last element as required for ADR structure
                ]],
                ["tel", ["type" => "voice"], $disclose_voice ? 'uri' : 'text', $disclose_voice ? "tel:" . $contactDetails['voice'] : "REDACTED FOR PRIVACY"],
                ["tel", ["type" => "fax"], $disclose_fax ? 'uri' : 'text', $disclose_fax ? "tel:" . $contactDetails['fax'] : "REDACTED FOR PRIVACY"],
                ["email", new stdClass(), 'text', $disclose_email ? $contactDetails['email'] : "REDACTED FOR PRIVACY"],
            ]
        ],
    ];
}

function isIpWhitelisted($ip, $pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM registrar_whitelist WHERE addr = :ip");
    $stmt->execute(['ip' => $ip]);
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

function mapStatuses(array $statuses): array {
    $statusMap = [
        "addPeriod" => "add period",
        "autoRenewPeriod" => "auto renew period",
        "clientDeleteProhibited" => "client delete prohibited",
        "clientHold" => "client hold",
        "clientRenewProhibited" => "client renew prohibited",
        "clientTransferProhibited" => "client transfer prohibited",
        "clientUpdateProhibited" => "client update prohibited",
        "inactive" => "inactive",
        "linked" => "associated",
        "ok" => "active",
        "pendingCreate" => "pending create",
        "pendingDelete" => "pending delete",
        "pendingRenew" => "pending renew",
        "pendingRestore" => "pending restore",
        "pendingTransfer" => "pending transfer",
        "pendingUpdate" => "pending update",
        "redemptionPeriod" => "redemption period",
        "renewPeriod" => "renew period",
        "serverDeleteProhibited" => "server delete prohibited",
        "serverRenewProhibited" => "server renew prohibited",
        "serverTransferProhibited" => "server transfer prohibited",
        "serverUpdateProhibited" => "server update prohibited",
        "serverHold" => "server hold",
        "transferPeriod" => "transfer period"
    ];

    return array_map(function ($status) use ($statusMap) {
        return $statusMap[$status] ?? $status; // Return mapped value or original if not found
    }, $statuses);
}

function isValidHostname($hostname) {
    $hostname = trim($hostname);

    // Convert IDN (Unicode) to ASCII if necessary
    if (mb_detect_encoding($hostname, 'ASCII', true) === false) {
        $hostname = idn_to_ascii($hostname, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($hostname === false) {
            return false; // Invalid IDN conversion
        }
    }

    // Ensure there is at least **one dot** (to prevent single-segment hostnames)
    if (substr_count($hostname, '.') < 1) {
        return false;
    }

    // Regular expression for validating a hostname
    $pattern = '/^((xn--[a-zA-Z0-9-]{1,63}|[a-zA-Z0-9-]{1,63})\.)*([a-zA-Z0-9-]{1,63}|xn--[a-zA-Z0-9-]{2,63})$/';

    // Ensure it matches the hostname pattern
    if (!preg_match($pattern, $hostname)) {
        return false;
    }

    // Ensure no label exceeds 63 characters
    $labels = explode('.', $hostname);
    foreach ($labels as $label) {
        if (strlen($label) > 63) {
            return false;
        }
    }

    // Ensure full hostname is not longer than 255 characters
    if (strlen($hostname) > 255) {
        return false;
    }

    return true;
}