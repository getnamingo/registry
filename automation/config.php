<?php

return [
    // Database Configuration
    'db_type' => 'mysql',
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_database' => 'your_database_name',
    'db_username' => 'your_username',
    'db_password' => 'your_password',
	
	// Escrow Configuration
    'escrow_deposit_path' => '/opt/escrow',
    'escrow_deleteXML' => false,
    'escrow_RDEupload' => false,
    'escrow_keyPath' => '/opt/escrow/escrowKey.asc',
    'escrow_privateKey' => '/opt/escrow/privatekey.asc',
    'escrow_sftp_host' => 'your.sftp.server.com',
    'escrow_sftp_username' => 'your_username',
    'escrow_sftp_password' => 'your_password',
    'escrow_sftp_remotepath' => '/path/on/sftp/server/',
    'escrow_report_url' => 'https://ry-api.icann.org/report/',
    'escrow_report_username' => 'your_username',
    'escrow_report_password' => 'your_password',
];