<?php

namespace App\Lib;

use Monolog\ErrorHandler;
use Monolog\Handler\RotatingFileHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

/**
 * Logger
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

        // Set log path and maximum number of files to retain
        $LOG_PATH = '/var/log/namingo';
        $maxFiles = 30; // Number of days to keep logs

        if (empty($config)) {
            $config = [
                'logFile' => "{$LOG_PATH}/cp.log", // Base log name
                'logLevel' => \Monolog\Logger::DEBUG,
                'maxFiles' => $maxFiles,
            ];
        }

        // Use RotatingFileHandler for automatic rotation
        $this->pushHandler(new RotatingFileHandler($config['logFile'], $config['maxFiles'], $config['logLevel']));
    }

    /**
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
            // Output pretty HTML error
            self::htmlError();
        } else {
            // Register system errors to rotating log file
            $logger = new Logger('errors'); // Key 'errors' remains for compatibility
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
}