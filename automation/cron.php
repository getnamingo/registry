<?php
use Workerman\Worker;
use Workerman\Events\Swoole;
use Pinga\Crontab\Crontab;

require __DIR__ . '/vendor/autoload.php';

Worker::$eventLoopClass = Swoole::class;
Worker::$daemonize = true;

// Configuration
$cronJobConfig = [
    'accounting' => false,    // Set to true to enable
    'backup' => false,    // Set to true to enable
    'gtld_mode' => false,    // Set to true to enable
    'spec11' => false,    // Set to true to enable
];

// Zone Generator
$writezone = new Worker();
$writezone->name = 'Zone Generator';
$writezone->onWorkerStart = function () {
    new Crontab('*/15 * * * *', function(){
        exec('/usr/bin/php8.2 /opt/registry/automation/write-zone.php > /dev/null 2>&1');
    });
};

// System Maintenance
$maintenance = new Worker();
$maintenance->name = 'System Maintenance';
$maintenance->onWorkerStart = function () {
    new Crontab('35 * * * *', function(){
        exec('/usr/bin/php8.2 /opt/registry/automation/registrar.php > /dev/null 2>&1');
    });
    
    new Crontab('59 * * * *', function(){
        exec('/usr/bin/php8.2 /opt/registry/automation/statistics.php > /dev/null 2>&1');
    });
    
    new Crontab('50 1 * * *', function(){
        exec('/usr/bin/php8.2 /opt/registry/automation/rdap-urls.php > /dev/null 2>&1');
    });
    
    new Crontab('0 0 * * 1', function(){
        exec('/usr/bin/php8.2 /var/www/cp/bin/file_cache.php > /dev/null 2>&1');
    });
};

// Domain Maintenance
$domainmaint = new Worker();
$domainmaint->name = 'Domain Maintenance';
$domainmaint->onWorkerStart = function () {
    new Crontab('30 * * * *', function(){
        exec('/usr/bin/php8.2 /opt/registry/automation/change-domain-status.php > /dev/null 2>&1');
    });
    
    new Crontab('45 * * * *', function(){
        exec('/usr/bin/php8.2 /opt/registry/automation/auto-approve-transfer.php > /dev/null 2>&1');
    });
    
    new Crontab('5 0 * * *', function(){
        exec('/usr/bin/php8.2 /opt/registry/automation/auto-clean-unused-contact-and-host.php > /dev/null 2>&1');
    });
};

// Accounting
if ($cronJobConfig['accounting']) {
    $accounting = new Worker();
    $accounting->name = 'Accounting';
    $accounting->onWorkerStart = function () {
        new Crontab('1 0 1 * *', function(){
            exec('/usr/bin/php8.2 /opt/registry/automation/send-invoice.php > /dev/null 2>&1');
        });
    };
}

// Backup
if ($cronJobConfig['backup']) {
    $backup = new Worker();
    $backup->name = 'Backup';
    $backup->onWorkerStart = function () {
        new Crontab('15 * * * *', function(){
            exec('/opt/registry/automation/vendor/bin/phpbu --configuration=/opt/registry/automation/backup.json > /dev/null 2>&1');
        });
            
        new Crontab('30 * * * *', function(){
            exec('/usr/bin/php8.2 /opt/registry/automation/backup-upload.php > /dev/null 2>&1');
        });
    };
}

// Spec11 Reports
if ($cronJobConfig['spec11']) {
    $spec11 = new Worker();
    $spec11->name = 'Spec11 Reports';
    $spec11->onWorkerStart = function () {
        new Crontab('30 * * * *', function(){
            exec('/usr/bin/php8.2 /opt/registry/automation/abusemonitor.php > /dev/null 2>&1');
        });
        
        new Crontab('5 0 * * *', function(){
            exec('/usr/bin/php8.2 /opt/registry/automation/abusereport.php > /dev/null 2>&1');
        });
    };
}

// TMCH
if ($cronJobConfig['gtld_mode']) {
    $tmch = new Worker();
    $tmch->name = 'TMCH';
    $tmch->onWorkerStart = function () {
        new Crontab('10 0 * * *', function(){
            exec('/usr/bin/php8.2 /opt/registry/automation/lordn.php > /dev/null 2>&1');
        });
        
        new Crontab('0 0,12 * * *', function(){
            exec('/usr/bin/php8.2 /opt/registry/automation/tmch.php > /dev/null 2>&1');
        });
    };
}

// URS
if ($cronJobConfig['gtld_mode']) {
    $urs = new Worker();
    $urs->name = 'URS';
    $urs->onWorkerStart = function () {
        new Crontab('45 * * * *', function(){
            exec('/usr/bin/php8.2 /opt/registry/automation/urs.php > /dev/null 2>&1');
        });
    };
}

// Escrow
if ($cronJobConfig['gtld_mode']) {
    $escrow = new Worker();
    $escrow->name = 'Escrow';
    $escrow->onWorkerStart = function () {
        new Crontab('5 0 * * *', function(){
            exec('/usr/bin/php8.2 /opt/registry/automation/escrow.php > /dev/null 2>&1');
        });
    };
}

// Reporting
if ($cronJobConfig['gtld_mode']) {
    $reporting = new Worker();
    $reporting->name = 'Reporting';
    $reporting->onWorkerStart = function () {
        new Crontab('1 0 1 * *', function(){
            exec('/usr/bin/php8.2 /opt/registry/automation/reporting.php > /dev/null 2>&1');
        });
    };
}

Worker::runAll();