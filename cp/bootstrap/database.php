<?php

use Pinga\Db\PdoDatabase;
use App\Lib\Logger;

$log = Logger::getInstance('CP');

// Load database config
$config = config('connections');
$defaultDriver = config('default') ?? 'mysql';

$supportedDrivers = [
    'mysql' => "{$config['mysql']['driver']}:dbname={$config['mysql']['database']};host={$config['mysql']['host']};charset={$config['mysql']['charset']}",
    'sqlite' => "{$config['sqlite']['driver']}:{$config['sqlite']['driver']}",
    'pgsql' => "{$config['pgsql']['driver']}:dbname={$config['pgsql']['database']};host={$config['pgsql']['host']}"
];

$pdo = null;
$db = null;

try {
    // Select the correct database driver (fallback to MySQL)
    $driver = $supportedDrivers[$defaultDriver] ?? $supportedDrivers['mysql'];

    // Create PDO connection
    $pdo = new \PDO($driver, $config[$defaultDriver]['username'], $config[$defaultDriver]['password']);
    $db = PdoDatabase::fromPdo($pdo);

} catch (PDOException $e) {
    $log->alert("Database connection failed: " . $e->getMessage(), ['driver' => $defaultDriver]);
}