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
    'roid' => 'XX', // ROID value in escrow

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
    'zone_mode' => 'default', // How the BIND zone is generated, 'nice' is also available

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
	
    // Minimum Data Set
    'minimum_data' => false, // Set to true to enable minimum data set support
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
MINIMUM_DATA=false

DB_DRIVER=mysql # Type of the database (e.g., 'mysql', 'pgsql')
DB_HOST=localhost # Database server host
DB_DATABASE=registry # Name of the database
DB_USERNAME=root # Database username
DB_PASSWORD= # Database password
DB_PORT=3306 # Database server port

# Mailer settings (Driver = smtp, utopia or msg [for local message broker]; Api Provder = sendgrid or mailgun)
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

ADYEN_API_KEY='adyen-api-key'
ADYEN_MERCHANT_ID='adyen-merchant-id'
ADYEN_THEME_ID='adyen-theme-id'
ADYEN_BASE_URI='https://checkout-test.adyen.com/v70/'
ADYEN_BASIC_AUTH_USER='adyen-basic-auth-user'
ADYEN_BASIC_AUTH_PASS='adyen-basic-auth-pass'
ADYEN_HMAC_KEY='adyen-hmac-key'

NOW_API_KEY='now-api-key'

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
    'das_ipv4' => '0.0.0.0',
    'das_ipv6' => '::', // Set to false if no IPv6 support
    'rately' => false, // Enable rate limit
    'limit' => 1000, // Request limit per period below
    'period' => 60, // 60 Seconds
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
    'rately' => false, // Enable rate limit
    'limit' => 1000, // Request limit per period below
    'period' => 60, // 60 Seconds
    'minimum_data' => false, // Set to true to enable minimum data set support
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
    'rately' => false, // Enable rate limit
    'limit' => 1000, // Request limit per period below
    'period' => 60, // 60 Seconds
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
    'whois_ipv4' => '0.0.0.0',
    'whois_ipv6' => '::', // Set to false if no IPv6 support
    'privacy' => false, // Toggle for privacy mode
    'minimum_data' => false, // Set to true to enable minimum data set support
    'roid' => 'XX', // Registry Object ID
    'rately' => false, // Enable rate limit
    'limit' => 1000, // Request limit per period below
    'period' => 60, // 60 Seconds
];
```

## Adminer Security settings

To enhance the security of your Adminer installation, we recommend the following settings for Caddy, Apache2, and Nginx:

1. **Rename Adminer File:** Change `adminer.php` to `dbtool.php` to make it less predictable.

2. **Restrict Access by IP:** Only allow access from specific IP addresses.

Below are example configurations for each web server:

### Caddy

```bash
# Adminer Configuration
route /dbtool.php* {
    root * /usr/share/adminer
    php_fastcgi unix//run/php/php8.2-fpm.sock

    # Define the allowed IP address
    @allowed {
        remote_ip your.ip.address.here
    }

    # Route for allowed IP addresses
    handle @allowed {
        file_server
    }

    # Respond with 403 for any IP address not allowed
    respond "Access Denied" 403
}
```

### Apache .htaccess

```bash
<Files "dbtool.php">
    Order Deny,Allow
    Deny from all
    Allow from your.ip.address.here
</Files>
```

### Nginx

```bash
location /dbtool.php {
    allow your.ip.address.here;
    deny all;
}
```

In conclusion, this detailed configuration guide aims to streamline the setup process of the Namingo system for users of all expertise levels. The guide meticulously details each configuration file, providing clear explanations and guidance for customization to suit your specific needs. This approach ensures that you can configure Namingo with confidence, optimizing it for your registry management requirements. We are committed to making the configuration process as straightforward as possible, and we welcome any questions or requests for further assistance. Your successful deployment and efficient management of Namingo is our top priority.

After finalizing the configuration of Namingo, the next step is to consult the [Initial Operation Guide](iog.md). This guide provides comprehensive details on configuring your registry, adding registrars, and much more, to ensure a smooth start with your system.