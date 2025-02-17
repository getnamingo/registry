<?php

// ========================== Instructions ==========================
// To customize the cron job settings without modifying this script,
// create a file at the following path:
// 
// /opt/registry/automation/cron_config.php
//
// The file should return an array with your configuration values.
// Example content for cron_config.php:
//
// <?php
// return [
//     'accounting' => false,  // Enable or disable accounting
//     'backup' => false,      // Enable or disable backup
//     'backup_upload' => false, // Enable or disable backup upload
//     'gtld_mode' => false,   // Enable or disable gTLD mode
//     'spec11' => false,      // Enable or disable Spec 11 checks
//     'dnssec' => false,     // Enable or disable DNSSEC
//     'exchange_rates' => false,     // Enable or disable exchange rate download
// ];
//
// Any keys omitted in cron_config.php will fall back to the defaults
// defined in this script.
// ==================================================================

// Default Configuration
$defaultConfig = [
    'accounting' => false,    // Set to true to enable
    'backup' => false,    // Set to true to enable
    'backup_upload' => false, // Set to true to enable
    'gtld_mode' => false,    // Set to true to enable
    'spec11' => false,    // Set to true to enable
    'dnssec' => false,    // Set to true to enable
    'exchange_rates' => false,    // Set to true to enable
];

// Load External Config if Exists
$configFilePath = '/opt/registry/automation/cron_config.php';
if (file_exists($configFilePath)) {
    $externalConfig = require $configFilePath;
    $cronJobConfig = array_merge($defaultConfig, $externalConfig);
} else {
    $cronJobConfig = $defaultConfig;
}

require __DIR__ . '/vendor/autoload.php';

use GO\Scheduler;
$scheduler = new Scheduler();

$scheduler->php('/opt/registry/automation/write-zone.php')->at('*/15 * * * *');
$scheduler->php('/opt/registry/automation/registrar.php')->at('35 * * * *');
$scheduler->php('/opt/registry/automation/statistics.php')->at('59 * * * *');
$scheduler->php('/opt/registry/automation/rdap-urls.php')->at('50 1 * * *');
$scheduler->php('/var/www/cp/bin/file_cache.php')->at('0 0 * * 1,4');

$scheduler->php('/opt/registry/automation/domain-lifecycle-manager.php')->at('*/5 * * * *');
$scheduler->php('/opt/registry/automation/auto-approve-transfer.php')->at('*/30 * * * *');
$scheduler->php('/opt/registry/automation/auto-clean-unused-contact-and-host.php')->at('5 0 * * *');

$scheduler->php('/opt/registry/automation/archive-logs.php')->at('0 1 1 * *');

// Conditional Cron Jobs
if ($cronJobConfig['accounting']) {
    $scheduler->php('/opt/registry/automation/send-invoice.php')->at('1 0 1 * *');
}

if ($cronJobConfig['backup']) {
    $scheduler->raw('/opt/registry/automation/vendor/bin/phpbu --configuration=/opt/registry/automation/backup.json')->at('15 * * * *');
}

if ($cronJobConfig['backup_upload']) {
    $scheduler->php('/opt/registry/automation/backup-upload.php')->at('30 * * * *');
}

if ($cronJobConfig['dnssec']) {
    $scheduler->php('/opt/registry/automation/dnssec-ds-rotator.php')->at('0 0 * * *');
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

if ($cronJobConfig['exchange_rates']) {
    $scheduler->php('/opt/registry/automation/exchange-rates.php')->at('0 1 * * *');
}

// Run Scheduled Tasks
$scheduler->run();
