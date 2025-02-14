<?php

namespace App\Lib;

use Monolog\ErrorHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
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

        // Archive Old Logs (Move older than 14 days to ZIP)
        $this->archiveOldLogs($config['logFile']);

        // Pushover Alerts (For CRITICAL, ALERT, EMERGENCY)
        if (!empty($_ENV['PUSHOVER_KEY'])) {
            try {
                $pushoverHandler = new PushoverHandler($_ENV['PUSHOVER_KEY'], \Monolog\Logger::ALERT);
                $this->pushHandler($pushoverHandler);
            } catch (\Exception $e) {
                error_log("PushoverHandler failed: " . $e->getMessage());
            }
        }

        // Email Alerts (For CRITICAL, ALERT, EMERGENCY)
        if (!empty($_ENV['MAIL_HOST'])) {
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $_ENV['MAIL_HOST'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $_ENV['MAIL_USERNAME'];
                $mail->Password   = $_ENV['MAIL_PASSWORD'];
                $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'];
                $mail->Port       = $_ENV['MAIL_PORT'];
                $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
                $mail->addAddress($_ENV['MAIL_FROM_ADDRESS']); // Send to admin email

                // Attach PHPMailer to Monolog
                $mailerHandler = new PHPMailerHandler($mail);
                $mailerHandler->setFormatter(new LineFormatter());

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

    /**
     * Archive Old Logs (Older than 14 Days)
     */
    private function archiveOldLogs($logFilePath)
    {
        $logDir = dirname($logFilePath);
        $backupDir = '/opt/backup';
        $lockFile = $backupDir . '/log_archive.lock';

        // Prevent multiple processes from running archive at the same time
        if (file_exists($lockFile)) {
            return; // Another process is already archiving
        }
        touch($lockFile); // Create lock file

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $logFiles = glob($logDir . '/*.log'); // Get all log files
        $thresholdDate = strtotime('-14 days'); // Logs older than 14 days

        foreach ($logFiles as $file) {
            if (filemtime($file) < $thresholdDate) {
                $filename = basename($file);
                $monthYear = date('F-Y', filemtime($file));
                $zipPath = $backupDir . "/logs-{$monthYear}.zip";

                // Open or create ZIP archive
                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                    if (!$zip->locateName($filename)) { // Prevent duplicate addition
                        $zip->addFile($file, $filename);
                        unlink($file); // Delete original log after archiving
                    }
                    $zip->close();
                }
            }
        }

        unlink($lockFile); // Remove lock when done
    }

}