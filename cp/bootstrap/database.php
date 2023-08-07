<?php

$config = config('connections');
// MySQL Connection
if (config('default') == 'mysql') {
    $pdo = new \PDO($config['mysql']['driver'].':dbname='.$config['mysql']['database'].';host='.$config['mysql']['host'].';charset='.$config['mysql']['charset'].'', $config['mysql']['username'], $config['mysql']['password']);
	$db = \Pinga\Db\PdoDatabase::fromPdo($pdo);
}
// SQLite Connection
elseif (config('default') == 'sqlite') {
    $pdo = new PDO($config['sqlite']['driver'].":".$config['sqlite']['driver']);
	$db = \Pinga\Db\PdoDatabase::fromPdo($pdo);
}
// PostgreSQL Connection
elseif (config('default') == 'pgsql') {
    $pdo = new \PDO($config['pgsql']['driver'].':dbname='.$config['pgsql']['database'].';host='.$config['pgsql']['host'].';charset='.$config['pgsql']['charset'].'', $config['pgsql']['username'], $config['pgsql']['password']);
	$db = \Pinga\Db\PdoDatabase::fromPdo($pdo);
}
// SQL Server Connection
elseif (config('default') == 'sqlsrv') {
    $pdo = new PDO($config['sqlsrv']['driver']."sqlsrv:server=".$config['sqlsrv']['host'].";".$config['sqlsrv']['database'], $config['sqlsrv']['username'], $config['sqlsrv']['password']);
	$db = \Pinga\Db\PdoDatabase::fromPdo($pdo);
}
// MySQL Connection as default
else{
    $pdo = new \PDO($config['mysql']['driver'].':dbname='.$config['mysql']['database'].';host='.$config['mysql']['host'].';charset='.$config['mysql']['charset'].'', $config['mysql']['username'], $config['mysql']['password']);
	$db = \Pinga\Db\PdoDatabase::fromPdo($pdo);
}