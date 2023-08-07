<?php namespace App\Lib;

use Monolog\ErrorHandler;
use Monolog\Handler\StreamHandler;

use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;
/**
 * Logger
 *
 * @author    Hezekiah O. <support@hezecom.com>
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

        if (empty($config)) {
            $LOG_PATH = '/tmp/slim';
            $config = [
                'logFile' => "{$LOG_PATH}/{$key}.log",
                'logLevel' => \Monolog\Logger::DEBUG
            ];
        }
        $this->pushHandler(new StreamHandler($config['logFile'], $config['logLevel']));
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
     * Output error bate on environment
     */
    public static function systemLogs($enable = true)
    {

        $LOG_PATH = '/tmp/slim';
        $appEnv = envi('APP_ENV') ?? 'local';

        if($enable) {
            // output pretty html error
            self::htmlError();
        }else {
            // Error Log to file
            self::$loggers['error'] = new Logger('errors');
            self::$loggers['error']->pushHandler(new StreamHandler("{$LOG_PATH}/errors.log"));
            ErrorHandler::register(self::$loggers['error']);
        }
    }

    /**
     * Display pretty html formatted errors during development
     */
    public static function htmlError(){
        $run = new Run;
        $run->pushHandler(new PrettyPageHandler);
        $run->register();
    }
}
