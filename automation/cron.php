<?php

// Configuration
$cronJobConfig = [
    'accounting' => false,    // Set to true to enable
    'backup' => false,    // Set to true to enable
    'gtld_mode' => false,    // Set to true to enable
    'spec11' => false,    // Set to true to enable
];

require __DIR__ . '/vendor/autoload.php';

use GO\Scheduler;
$scheduler = new Scheduler();

$scheduler->php('/opt/registry/automation/write-zone.php')->at('*/15 * * * *');
$scheduler->php('/opt/registry/automation/registrar.php')->at('35 * * * *');
$scheduler->php('/opt/registry/automation/statistics.php')->at('59 * * * *');
$scheduler->php('/opt/registry/automation/rdap-urls.php')->at('50 1 * * *');
$scheduler->php('/var/www/cp/bin/file_cache.php')->at('0 0 * * 1');

$scheduler->php('/opt/registry/automation/domain-lifecycle-manager.php')->at('*/5 * * * *');
$scheduler->php('/opt/registry/automation/auto-approve-transfer.php')->at('*/30 * * * *');
$scheduler->php('/opt/registry/automation/auto-clean-unused-contact-and-host.php')->at('5 0 * * *');

if ($cronJobConfig['accounting']) {
    $scheduler->php('/opt/registry/automation/send-invoice.php')->at('1 0 1 * *');
}

if ($cronJobConfig['backup']) {
    $scheduler->raw('/opt/registry/automation/vendor/bin/phpbu --configuration=/opt/registry/automation/backup.json')->at('15 * * * *');
    $scheduler->php('/opt/registry/automation/backup-upload.php')->at('30 * * * *');
}

if ($cronJobConfig['spec11']) {
    $scheduler->php('/opt/registry/automation/abusemonitor.php')->at('30 * * * *');
    $scheduler->php('/opt/registry/automation/abusereport.php')->at('5 0 * * *');
}

if ($cronJobConfig['gtld_mode']) {
    $scheduler->php('/opt/registry/automation/lordn.php')->at('10 0 * * *');
    $scheduler->php('/opt/registry/automation/tmch.php')->at('0 0,12 * * *');
    $scheduler->php('/opt/registry/automation/urs.php')->at('45 * * * *');
    $scheduler->php('/opt/registry/automation/escrow.php')->at('5 0 * * *');
    $scheduler->php('/opt/registry/automation/reporting.php')->at('1 0 1 * *');
}

$scheduler->run();
