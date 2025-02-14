<?php

$c = require_once 'config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/archive-logs.log';
$log = setupLogger($logFilePath, 'ARCHIVE_LOGS');
$log->info('job started.');

archiveOldLogs($logFilePath);

$log->info('job finished successfully.');