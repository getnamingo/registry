<?php

namespace App\Lib;

use Monolog\ErrorHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\HtmlFormatter;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use MonologPHPMailer\PHPMailerHandler;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;
use Dotenv\Dotenv;
use ZipArchive;

/**
 * Namingo CP Logger
 */
class Logger extends \Monolog\Logger
{
    private static $loggers = [];

    /**
     * Logger constructor.
     * @param string $key
     * @param null $config
     * @throws \Exception
     */
    public function __construct($key = "app", $config = null)
    {
        parent::__construct($key);

        $LOG_PATH = '/var/log/namingo';
        $maxFiles = 30; // Number of days to keep logs

        if (empty($config)) {
            $config = [
                'logFile' => "{$LOG_PATH}/cp.log", // Base log name
                'logLevel' => \Monolog\Logger::DEBUG,
                'maxFiles' => $maxFiles,
            ];
        }

        // Load Environment Variables from .env
        $dotenv = Dotenv::createImmutable('/var/www/cp/');
        $dotenv->load();

        // Console Logging (For Real-Time Debugging)
        $consoleHandler = new StreamHandler('php://stdout', \Monolog\Logger::DEBUG);
        $consoleFormatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            "Y-m-d H:i:s.u", // Date format
            true, // Allow inline line breaks
            true  // Ignore empty context and extra
        );
        $consoleHandler->setFormatter($consoleFormatter);
        $this->pushHandler($consoleHandler);

        // File Logging (Rotating Handler - Keeps 30 Days)
        $fileHandler = new RotatingFileHandler($config['logFile'], $config['maxFiles'], $config['logLevel']);
        $fileFormatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            "Y-m-d H:i:s.u"
        );
        $fileHandler->setFormatter($fileFormatter);
        $this->pushHandler($fileHandler);

        // Email Alerts (For CRITICAL, ALERT, EMERGENCY)
        if (!empty($_ENV['MAIL_HOST'])) {
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mailToAddress = $_ENV['MAIL_TO_ADDRESS'] ?? null;

                $mail->Host       = $_ENV['MAIL_HOST'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $_ENV['MAIL_USERNAME'];
                $mail->Password   = $_ENV['MAIL_PASSWORD'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = $_ENV['MAIL_PORT'];
                $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
                if (!$mailToAddress) {
                    error_log("MAIL_TO_ADDRESS is missing, skipping recipient.");
                } else {
                    $mail->addAddress($mailToAddress);
                }

                // Attach PHPMailer to Monolog
                $mailerHandler = new PHPMailerHandler($mail);
                $mailerHandler->setFormatter(new HtmlFormatter());

                // Filter Emails to ALERT, CRITICAL, EMERGENCY Only
                $filteredMailHandler = new FilterHandler($mailerHandler, \Monolog\Logger::ALERT, \Monolog\Logger::EMERGENCY);
                $safeMailHandler = new WhatFailureGroupHandler([$filteredMailHandler]);
                $this->pushHandler($safeMailHandler);
            } catch (Exception $e) {
                error_log("Failed to initialize PHPMailer: " . $e->getMessage());
            }
        }
    }

    /**
     * Get an instance of the logger
     * @param string $key
     * @param null $config
     * @return mixed
     */
    public static function getInstance($key = "app", $config = null)
    {
        if (empty(self::$loggers[$key])) {
            self::$loggers[$key] = new Logger($key, $config);
        }

        return self::$loggers[$key];
    }

    /**
     * Output error based on environment
     */
    public static function systemLogs($enable = true)
    {
        if ($enable) {
            self::htmlError();
        } else {
            $logger = new Logger('errors');
            ErrorHandler::register($logger);

            set_exception_handler(function ($e) use ($logger) {
                http_response_code(500);

                $logger->error("Unhandled exception", [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]);

                include '/var/www/cp/resources/error.html';
            });

            register_shutdown_function(function () use ($logger) {
                $error = error_get_last();
                if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                    http_response_code(500);

                    $logger->error("Fatal error", [
                        'message' => $error['message'],
                        'file' => $error['file'],
                        'line' => $error['line'],
                        'type' => $error['type']
                    ]);

                    include '/var/www/cp/resources/error.html';
                }
            });
        }
    }

    /**
     * Display pretty HTML formatted errors during development
     */
    public static function htmlError()
    {
        $run = new Run();
        $run->pushHandler(new PrettyPageHandler());
        $run->register();
    }

}