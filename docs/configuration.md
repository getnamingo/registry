# Configuration Guide

This document provides detailed instructions on configuring Namingo, the domain registry management tool, after installation. Each configuration file and its respective settings are explained for easy setup and customization.

## Automation Configuration (`/opt/registry/automation/config.php`)

This configuration file is essential for setting up the automation scripts for the registry tool.

```php
<?php

return [
    // Database Configuration
    'db_type' => 'mysql', // Type of the database (e.g., 'mysql', 'pgsql')
    'db_host' => 'localhost', // Database server host
    'db_port' => 3306, // Database server port
    'db_database' => 'registry', // Name of the database
    'db_username' => 'your_username', // Database username
    'db_password' => 'your_password', // Database password
    
    // Escrow Configuration
    'escrow_deposit_path' => '/opt/escrow', // Path for escrow deposits
    'escrow_deleteXML' => false, // Whether to delete XML files after processing
    'escrow_RDEupload' => false, // Enable/disable RDE upload
    'escrow_BRDAupload' => false, // Enable/disable BRDA upload
    'escrow_BRDAday' => 'Tuesday', // Day for BRDA uploads
    'escrow_keyPath' => '/opt/escrow/escrowKey.asc', // Path to the escrow key
    'escrow_keyPath_brda' => '/opt/escrow/icann-brda-gpg.pub', // Path to the BRDA escrow key
    'escrow_privateKey' => '/opt/escrow/privatekey.asc', // Path to the private key for escrow
    'escrow_sftp_host' => 'your.sftp.server.com', // Host for escrow SFTP server
    'escrow_sftp_username' => 'your_username', // Username for escrow SFTP server
    'escrow_sftp_password' => 'your_password', // Password for escrow SFTP server
    'escrow_sftp_remotepath' => '/path/on/sftp/server/', // Remote path on the escrow SFTP server
    'brda_sftp_host' => 'your.sftp.server.com', // Host for BRDA SFTP server
    'brda_sftp_username' => 'your_username', // Username for BRDA SFTP server
    'brda_sftp_password' => 'your_password', // Password for BRDA SFTP server
    'brda_sftp_remotepath' => '/path/on/sftp/server/', // Remote path on the BRDA SFTP server
    'escrow_report_url' => 'https://ry-api.icann.org/report/', // URL for escrow reporting
    'escrow_report_username' => 'your_username', // Username for escrow reporting
    'escrow_report_password' => 'your_password', // Password for escrow reporting

    // Reporting Configuration
    'reporting_path' => '/opt/reporting', // Path for reporting
    'reporting_upload' => false, // Enable/disable reporting upload
    'reporting_username' => 'your_username', // Username for reporting
    'reporting_password' => 'your_password', // Password for reporting
    
    // Zone Writer Configuration
    'dns_server' => 'bind', // DNS server type (e.g., 'bind', 'nsd')
    'ns' => [
        'ns1' => 'ns1.namingo.org', // Primary name server
        'ns2' => 'ns2.namingo.org', // Secondary name server
        // ... more name servers as needed ...
    ],
    'dns_soa' => 'hostmaster.example.com', // SOA email address
    
    // URS Configuration
    'urs_imap_host' => '{your_imap_server:993/imap/ssl}INBOX', // IMAP host for URS
    'urs_imap_username' => 'your_username', // IMAP username for URS
    'urs_imap_password' => 'your_password', // IMAP password for URS
    
    // Message Broker Configuration
    'mailer' => 'phpmailer', // Mailer type ('phpmailer', 'sendgrid', 'mailgun')
    'mailer_api_key' => 'YOUR_API_KEY', // API key for sendgrid/mailgun
    'mailer_domain' => 'example.com', // Domain for sendgrid/mailgun
    'mailer_from' => 'from@example.com', // From email address for mailer
    'mailer_smtp_host' => 'smtp.example.com', // SMTP host for mailer
    'mailer_smtp_username' => 'your_email@example.com', // SMTP username for mailer
    'mailer_smtp_password' => 'your_password', // SMTP password for mailer
    'mailer_smtp_port' => 587, // SMTP port for mailer
    
    'mailer_sms' => 'twilio', // SMS provider ('twilio', 'telesign', 'plivo', 'vonage', 'clickatell')
    'mailer_sms_account' => 'YOUR_ACCOUNT_SID/USERNAME', // Account SID/username for SMS
    'mailer_sms_auth' => 'YOUR_AUTH_TOKEN/PASSWORD', // Auth token/password for SMS
    
    // TMCH Configuration
    'tmch_path' => '/tmp/', // Path for TMCH files
    'tmch_smdrl_user' => 'your_username', // Username for TMCH SMDRL
    'tmch_smdrl_pass' => 'your_password', // Password for TMCH SMDRL
    'tmch_dnl_user' => 'your_username', // Username for TMCH DNL
    'tmch_dnl_pass' => 'your_password', // Password for TMCH DNL
    
    // LORDN Configuration
    'lordn_user' => 'your_username', // Username for LORDN
    'lordn_pass' => 'your_password', // Password for LORDN
];
```

## Control Panel Configuration (`/var/www/cp/.env`)

This file configures the environment for the control panel of Namingo.

```plaintext
APP_NAME='CP'
APP_ENV=public
APP_URL=https://cp.example.com
APP_DOMAIN=example.com
APP_ROOT=/var/www/cp

DB_DRIVER=mysql # Type of the database (e.g., 'mysql', 'pgsql')
DB_HOST=localhost # Database server host
DB_DATABASE=registry # Name of the database
DB_USERNAME=root # Database username
DB_PASSWORD= # Database password
DB_PORT=3306 # Database server port

# Mailer settings (Driver = smtp or utopia, API Provider = sendgrid or mailgun)
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=username
MAIL_PASSWORD=password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS='example@domain.com'
MAIL_FROM_NAME='Example'
MAIL_API_KEY='test-api-key'
MAIL_API_PROVIDER='sendgrid'

STRIPE_SECRET_KEY='stripe-secret-key'
STRIPE_PUBLISHABLE_KEY='stripe-publishable-key'

TEST_TLDS=.test,.com.test
```

## DAS Server Configuration (`/opt/registry/das/config.php`)

Configurations for the Domain Availability Service (DAS) server.

```php
<?php

return [
    'db_type' => 'mysql', // Type of the database (e.g., 'mysql', 'pgsql')
    'db_host' => 'localhost', // Database server host
    'db_port' => 3306, // Database server port
    'db_database' => 'registry', // Name of the database
    'db_username' => 'your_username', // Database username
    'db_password' => 'your_password' // Database password
];
```

## EPP Server Configuration (`/opt/registry/epp/config.php`)

Settings for the Extensible Provisioning Protocol (EPP) server.

```php
<?php

return [
    'db_type' => 'mysql', // Type of the database (e.g., 'mysql', 'pgsql')
    'db_host' => 'localhost', // Database server host
    'db_port' => 3306, // Database server port
    'db_database' => 'registry', // Name of the database
    'db_username' => 'your_username', // Database username
    'db_password' => 'your_password', // Database password
    'epp_host' => '0.0.0.0', // IP that the server will bind to, leave as is if no specific need
    'epp_port' => 700, // Port that the server will use
    'epp_pid' => '/var/run/epp.pid', // PID file of the server (do not change)
    'epp_greeting' => 'Namingo EPP Server 1.0', // EPP server prefix
    'epp_prefix' => 'namingo', // EPP server prefix
    'ssl_cert' => '', // Path to the SSL certificate that will be used by the server
    'ssl_key' => '', // Path to the SSL keyfile that will be used by the server
    'test_tlds' => '.test,.com.test', // Test TLDs for debugging purposes
];
```

## RDAP Server Configuration (`/opt/registry/rdap/config.php`)

Configuration for the Registration Data Access Protocol (RDAP) server.

```php
<?php

return [
    'db_type' => 'mysql', // Type of the database (e.g., 'mysql', 'pgsql')
    'db_host' => 'localhost', // Database server host
    'db_port' => 3306, // Database server port
    'db_database' => 'registry', // Name of the database
    'db_username' => 'your_username', // Database username
    'db_password' => 'your_password', // Database password
    'roid' => 'XX', // Registry Object ID
    'registry_url' => 'https://example.com/rdap-terms', // URL of registry website
    'rdap_url' => 'https://rdap.example.com', // URL of RDAP server
];
```

## WHOIS Server Configuration (`/opt/registry/whois/port43/config.php`)

Settings for the WHOIS server running on port 43.

```php
<?php

return [
    'db_type' => 'mysql', // Type of the database (e.g., 'mysql', 'pgsql')
    'db_host' => 'localhost', // Database server host
    'db_port' => 3306, // Database server port
    'db_database' => 'registry', // Name of the database
    'db_username' => 'your_username', // Database username
    'db_password' => 'your_password', // Database password
    'privacy' => false, // Toggle for privacy mode
    'roid' => 'XX', // Registry Object ID
];
```

In conclusion, this detailed configuration guide aims to streamline the setup process of the Namingo system for users of all expertise levels. The guide meticulously details each configuration file, providing clear explanations and guidance for customization to suit your specific needs. This approach ensures that you can configure Namingo with confidence, optimizing it for your registry management requirements. We are committed to making the configuration process as straightforward as possible, and we welcome any questions or requests for further assistance. Your successful deployment and efficient management of Namingo is our top priority.

After finalizing the configuration of Namingo, the next step is to consult the [Initial Operation Guide](docs/iog.md). This guide provides comprehensive details on configuring your registry, adding registrars, and much more, to ensure a smooth start with your system.