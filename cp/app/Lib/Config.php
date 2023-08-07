<?php namespace App\Lib;
/**
 * Config
 *
 * @author    Hezekiah O. <support@hezecom.com>
 */
class Config
{
    private static $config;

    public static function get($key, $default = null)
    {
        if (is_null(self::$config)) {
            self::$config = require_once(__DIR__ . '/../../config/app.php');
        }
        return !empty(self::$config[$key]) ? self::$config[$key] : $default;
    }
}
