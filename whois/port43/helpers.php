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

function parseQuery($data) {
    $data = trim($data);

    if (strpos($data, 'nameserver ') === 0) {
        return ['type' => 'nameserver', 'data' => substr($data, 11)];
    } elseif (strpos($data, 'registrar ') === 0) {
        return ['type' => 'registrar', 'data' => substr($data, 10)];
    } else {
        return ['type' => 'domain', 'data' => $data];
    }
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