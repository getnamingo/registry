<?php

require __DIR__ . '/vendor/autoload.php';

$config = require '/opt/registry/automation/config.php';

$cronJobConfig = [
    'accounting' => $config['cron_accounting'] ?? false,
    'backup' => $config['cron_backup'] ?? false,
    'backup_upload' => $config['cron_backup_upload'] ?? false,
    'gtld_mode' => $config['cron_gtld_mode'] ?? false,
    'spec11' => $config['cron_spec11'] ?? false,
    'spec11_iq' => $config['cron_spec11_iq'] ?? false,
    'exchange_rates' => $config['cron_exchange_rates'] ?? false,
    'cds_scanner' => $config['cron_cds_scanner'] ?? false,
];

use GO\Scheduler;
$scheduler = new Scheduler();

$scheduler->php('/opt/registry/automation/write-zone.php')->at('*/15 * * * *');
$scheduler->php('/opt/registry/automation/registrar.php')->at('35 * * * *');
$scheduler->php('/opt/registry/automation/statistics.php')->at('59 * * * *');
$scheduler->php('/opt/registry/automation/rdap-urls.php')->at('50 1 * * *');
$scheduler->php('/var/www/cp/bin/file_cache.php')->at('0 0 * * *');

$scheduler->php('/opt/registry/automation/domain-lifecycle-manager.php')->at('*/5 * * * *');
$scheduler->php('/opt/registry/automation/auto-approve-transfer.php')->at('*/30 * * * *');
$scheduler->php('/opt/registry/automation/auto-clean-unused-contact-and-host.php')->at('5 0 * * *');

$scheduler->php('/opt/registry/automation/archive-logs.php')->at('0 1 1 * *');

if ($cronJobConfig['accounting']) {
    $scheduler->php('/opt/registry/automation/send-invoice.php')->at('1 0 1 * *');
}

if ($cronJobConfig['backup']) {
    $scheduler->raw('/opt/registry/automation/vendor/bin/phpbu --configuration=/opt/registry/automation/backup.json')->at('15 * * * *');
}

if ($cronJobConfig['backup_upload']) {
    $scheduler->php('/opt/registry/automation/backup-upload.php')->at('30 * * * *');
}

if ($cronJobConfig['spec11']) {
    $scheduler->php('/opt/registry/automation/abusemonitor.php')->at('30 * * * *');
    $scheduler->php('/opt/registry/automation/abusereport.php')->at('5 0 * * *');
}

if ($cronJobConfig['spec11_iq']) {
    $scheduler->php('/opt/registry/automation/abuse_iq.php')->at('0 * * * *');
}

if ($cronJobConfig['gtld_mode']) {
    $scheduler->php('/opt/registry/automation/lordn.php')->at('10 0 * * *');
    $scheduler->php('/opt/registry/automation/tmch.php')->at('0 0,12 * * *');
    $scheduler->php('/opt/registry/automation/urs.php')->at('45 * * * *');
    $scheduler->php('/opt/registry/automation/escrow.php')->at('5 0 * * *');
    $scheduler->php('/opt/registry/automation/reporting.php')->at('1 0 1 * *');
}

if ($cronJobConfig['exchange_rates']) {
    $scheduler->php('/opt/registry/automation/exchange-rates.php')->at('0 1 * * *');
}

if ($cronJobConfig['cds_scanner']) { 
    $scheduler->php('/opt/registry/automation/cds_scanner.php')->at('0 */6 * * *');
}

$scheduler->run();