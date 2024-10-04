# Configuration Guide

This document provides instructions for configuring Namingo, the domain registry management tool, after installation. It covers configuring the system, adding additional components, and customizing various configuration files for optimal setup.

## 1. Post-Installation Configuration

This section provides instructions for configuring your system after installing Namingo, including setting up additional components and customizing configuration files.

### 1.1. Launching WHOIS, RDAP, and DAS Servers

To start the WHOIS, RDAP, and DAS servers, use the following commands:

```bash
systemctl start whois
systemctl start rdap
systemctl start das
```

Ensure each service is properly configured before starting. You can verify the status of each server with:

```bash
systemctl status whois
systemctl status rdap
systemctl status das
```

### 1.2. Launching EPP Server

Before launching the EPP server, edit `/opt/registry/epp/config.php` to set the paths to your certificates and configure other options as needed.

To create test certificates (`cert.pem` and `key.pem`), execute the following commands:

```bash
cd /opt/registry/epp/
openssl genrsa -out key.pem 2048
openssl req -new -x509 -key key.pem -out cert.pem -days 365
```

Once configured, you can launch the EPP server in the same way as the others:

```bash
systemctl start epp
```

### 1.3. Additional Control Panel Setup

#### 1.3.1. Install Optional Dependencies

To enhance the functionality of your control panel, install optional dependencies by executing the following command:

```bash
cd /var/www/cp
composer require phpmailer/phpmailer
```

#### 1.3.2. Setting Up Redis Session Storage

To utilize Redis for session storage, you need to install the necessary packages and configure your environment accordingly. Follow these steps to set up Redis session storage:

```bash
cd /var/www/cp
composer require predis/predis pinga/session-redis
```

After installation, log out of your application if you are currently logged in. This ensures that the session starts afresh with the new configuration.

Clear your browser cookies related to the application. This step is crucial as it removes any existing session cookies that were set using the previous session storage mechanism.

Upon your next login, Redis will be used for storing session data. The new sessions will be created and managed through Redis, providing a more scalable and efficient session management system.

**Note**: Ensure that your Redis server is properly configured and running before proceeding with these steps. If in doubt, check with:

```bash
systemctl status redis-server
```

### 1.4. Setting Up the Automation System

Follow these steps to configure the automation system for your registry:

#### 1.4.1. Configuration

Move to the automation directory with the following command:

```bash
cd /opt/registry/automation
```

Open `config.php` and adjust all necessary settings to suit your system's requirements. Make sure to review and fine-tune each option for optimal performance.

#### 1.4.2. Install Optional Dependencies

Execute one of the following commands to install the optional dependencies:

```bash
composer require utopia-php/messaging
```

or

```bash
composer require phpmailer/phpmailer
```

This command will install one of the packages which are essential for the message broker script to function correctly.

#### 1.4.3. Configuring the Message Broker

You can easily configure the message broker for email delivery in ```config.php```. It is compatible with SendGrid, Mailgun API, and PHPMailer for those opting to use their own SMTP server. All necessary settings are conveniently located under the mailer_ lines within the file.

For establishing your own mail server, both [Mox](https://github.com/mjl-/mox) and [Stalwart](https://stalw.art/) offer comprehensive solutions. You can install Mox by following its GitHub instructions, or Stalwart by referring to its official site. Once installed, enter the required details in the ```config.php``` file to complete the setup.

To run the Message Broker, execute the following commands:

```bash
/usr/bin/php /opt/registry/automation/msg_producer.php &
/usr/bin/php /opt/registry/automation/msg_worker.php &
```

This will start the system and place it in the background, allowing it to run independently of your current terminal session.

#### 1.4.4. Setting Up an Audit Trail Database for Namingo

To create an audit trail database for Namingo, start by editing the configuration file located at `/opt/registry/automation/audit.json` with the correct database details. This includes specifying the database connection parameters such as host, username, and password. Once your configuration is set up, run the command:

```bash
/opt/registry/automation/vendor/bin/audit -v audit /opt/registry/automation/audit.json
```

This will initialize and configure the audit trail functionality. This process ensures that all necessary tables and structures are set up in the registryAudit database, enabling comprehensive auditing of Namingo's operations.

**Currently, the audit trail setup for Namingo is supported only with MySQL or MariaDB databases. If you're using PostgreSQL, you'll need to utilize an external tool for audit logging, such as [pgAudit](https://minervadb.com/index.php/pgaudit-open-source-postgresql-audit-logging/), which provides detailed audit logging capabilities tailored for PostgreSQL environments.**

#### 1.4.5. Setup Backup

To ensure the safety and availability of your data in Namingo, it's crucial to set up and verify automated backups. Begin by editing the ```backup.json``` file in the automation directory, where you'll input your database details. Ensure that the details for the database are accurately entered in two specified locations within the ```backup.json``` file.

Additionally, check that the cronjob for PHPBU is correctly scheduled on your server, as this automates the backup process. You can verify this by reviewing your server's cronjob list. These steps are vital to maintain regular, secure backups of your system, safeguarding against data loss and ensuring business continuity. 

#### 1.4.6. RDE (Registry data escrow) configuration

**1.4.6.1. Generate the Key Pair**: Create a configuration file, say key-config, with the following content:

```yaml
%echo Generating a default key
Key-Type: RSA
Key-Length: 2048
Subkey-Type: RSA
Subkey-Length: 2048
Name-Real: Your Name
Name-Comment: Your Comment
Name-Email: your.email@example.com
Expire-Date: 0
%no-protection
%commit
%echo done
```

Replace "Your Name", "Your Comment", and "your.email@example.com" with your details.

Use the following command to generate the key:

```bash
gpg2 --batch --generate-key key-config
```

Your GPG key pair will now be generated.

**1.4.6.2. Exporting Your Keys**:

Public key:

```bash
gpg2 --armor --export your.email@example.com > publickey.asc
```

Replace `your-email@example.com` with the email address you used when generating the key.

Private key:

```bash
gpg2 --armor --export-secret-keys your.email@example.com > privatekey.asc
```

**1.4.6.3. Secure Your Private Key**: Always keep your private key secure. Do not share it. If someone gains access to your private key, they can impersonate you in cryptographic operations.

**1.4.6.4. Use in RDE deposit generation**: Please send the exported `publickey.asc` to your RDE provider, and also place the path to `privatekey.asc` in the escrow.php system as required.

#### 1.4.7. Running the Automation System

Once you have successfully configured all automation scripts, you are ready to initiate the automation system. Please review ```/opt/registry/automation/cron.php``` and enable all services if you are running a gTLD. Then proceed by adding the following cron job to the system crontab using ```crontab -e```:

```bash
* * * * * /usr/bin/php /opt/registry/automation/cron.php 1>> /dev/null 2>&1
```

## 2. Recommended Components and Integrations

This section outlines recommended components to enhance the functionality and reliability of your Namingo setup. These include essential services like DNS servers, monitoring tools, and other integrations that can help maintain a robust registry environment.

### 2.1. Setup Hidden Master DNS with BIND

Although Namingo is equipped with BIND by default for this purpose, you can opt for NSD, or Knot DNS if you are more comfortable with those systems.

#### Install BIND9 and its utilities with

```bash
apt install bind9 bind9-utils bind9-doc
```

#### Generate a TSIG key

Generate a TSIG key which will be used to authenticate DNS updates between the master and slave servers. **Note: replace ```test``` with your TLD.**

```bash
cd /etc/bind
tsig-keygen -a HMAC-SHA256 test.key
```

The output will be in the format that can be directly included in your BIND configuration files. It looks something like this:

```bash
key "test.key" {
    algorithm hmac-sha256;
    secret "base64-encoded-secret==";
};
```

Copy this output for use in the configuration files of both the master and slave DNS servers. (```/etc/bind/named.conf.local```)

#### Configure the Named Configuration File (Please Choose One)

1. Without DNSSEC:

Edit the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Add the following zone definition:

```bash
zone "test." {
    type master;
    file "/var/lib/bind/test.zone";
    allow-transfer { key "test.key"; };
    also-notify { <slave-server-IP>; };
};
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Use rndc to reload BIND:

```bash
systemctl restart bind9
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

2. Using DNSSEC with BIND9:

Edit the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Add the following DNSSEC policy:

```bash
dnssec-policy "namingo-policy" {
    keys {
        ksk lifetime P3M algorithm ed25519;
        zsk lifetime P1M algorithm ed25519;
    };
    max-zone-ttl 86400;
    dnskey-ttl 3600;
    zone-propagation-delay 3600;
    parent-propagation-delay 7200;
    parent-ds-ttl 86400;
};
```

Add the following zone definition:

```bash
zone "test." {
    type master;
    file "/var/lib/bind/test.zone";
    dnssec-policy "namingo-policy";
    key-directory "/var/lib/bind";
    inline-signing yes;
    allow-transfer { key "test.key"; };
    also-notify { <slave-server-IP>; };
};
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Initially, you will need to generate the DNSSEC ZSK and KSK manually:

```bash
dnssec-keygen -a Ed25519 -n ZONE test.
dnssec-keygen -a Ed25519 -n ZONE -f KSK test.
```

After generating the keys, place them in ```/var/lib/bind```. Run ```dnssec-dsfromkey Ktest.EXAMPLE.key``` on the KSK key you just generated, and the DS record must be submitted to IANA once setup is complete.

Use rndc to tell BIND to load and use the new keys:

```bash
systemctl restart bind9
rndc loadkeys test.
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

3. Using DNSSEC with OpenDNSSEC:

Edit the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Add the following zone definition:

```bash
zone "test." {
    type master;
    file "/var/lib/bind/test.zone.signed";
    allow-transfer { key "test.key"; };
    also-notify { <slave-server-IP>; };
};
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Install OpenDNSSEC:

```bash
apt install opendnssec opendnssec-enforcer-sqlite3 opendnssec-signer softhsm2
```

Update files in `/etc/opendnssec` to match your registry policy. As minimum, please enable at least Signer Threads in `/etc/opendnssec/conf.xml`, but we recommend to fully review [all the files](https://wiki.opendnssec.org/configuration/confxml/). Then run the following commands:

```bash
softhsm2-util --init-token --slot 0 --label OpenDNSSEC --pin 1234 --so-pin 1234
ods-enforcer-db-setup
rm /etc/opendnssec/prevent-startup
ods-control start
ods-enforcer policy import
ods-enforcer zone add -z test -p default -i /var/lib/bind/test.zone
```

Use rndc to reload BIND:

```bash
systemctl restart bind9
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

#### Check BIND9 Configuration

```bash
named-checkconf
named-checkzone test /var/lib/bind/test.zone
```

#### Restart BIND9 Service

```bash
systemctl restart bind9
```

#### Verify Zone Loading

Check the BIND9 logs to ensure that the .test zone is loaded without errors:

```bash
grep named /var/log/syslog
```

### 2.2. Regular DNS Server Setup

Before editing the configuration files, you need to copy the TSIG key from your hidden master server. The TSIG key configuration should look like this:

```bash
key "test.key" {
    algorithm hmac-sha256;
    secret "base64-encoded-secret==";
};
```

#### Installation of BIND9

```bash
apt update
apt install bind9 bind9-utils bind9-doc
```

#### Add the TSIG key to the BIND Configuration

Create a directory to store zone files:

```bash
mkdir /var/cache/bind/zones
```

Edit the `named.conf.local` file:

```bash
nano /etc/bind/named.conf.local
```

First, define the TSIG key at the top of the file:

```bash
key "test.key" {
    algorithm hmac-sha256;
    secret "base64-encoded-secret=="; // Replace with your actual base64-encoded key
};
```

Then, add the slave zone configuration:

```bash
zone "test." {
    type slave;
    file "/var/cache/bind/zones/test.zone";
    masters { 192.0.2.1 key "test.key"; }; // IP of the hidden master and TSIG key reference
    allow-query { any; }; // Allow queries from all IPs
    allow-transfer { none; }; // Disable zone transfers (AXFR) to others
};
```

Make sure to replace `192.0.2.1` with the IP address of your hidden master server and `base64-encoded-secret==` with the actual secret from your TSIG key.

#### Adjusting Permissions and Ownership

Ensure BIND has permission to write to the zone file and that the files are owned by the BIND user:

```bash
chown bind:bind /var/cache/bind/zones
chmod 755 /var/cache/bind/zones
```

#### Restart BIND9 Service

After making these changes, restart the BIND9 service to apply them:

```bash
systemctl restart bind9
```

#### Verify Configuration and Zone Transfer

```bash
named-checkconf
grep 'transfer of "test."' /var/log/syslog
```

### 2.3. Setup Monitoring

For effective monitoring of your registry system, we highly recommend utilizing Prometheus.

```bash
wget https://github.com/prometheus/prometheus/releases/download/v2.48.1/prometheus-2.48.1.linux-amd64.tar.gz
tar xvfz prometheus-2.48.1.linux-amd64.tar.gz
cp prometheus-2.48.1.linux-amd64/prometheus /usr/local/bin/
cp prometheus-2.48.1.linux-amd64/promtool /usr/local/bin/
useradd --no-create-home --shell /bin/false prometheus
mkdir /etc/prometheus
mkdir /var/lib/prometheus
cp -r prometheus-2.48.1.linux-amd64/consoles /etc/prometheus
cp -r prometheus-2.48.1.linux-amd64/console_libraries /etc/prometheus
chown -R prometheus:prometheus /etc/prometheus
chown -R prometheus:prometheus /var/lib/prometheus
```

Place the following in the ```/etc/prometheus/prometheus.yml``` and customize as needed:

```
# Global settings and defaults.
global:
  scrape_interval: 15s  # By default, scrape targets every 15 seconds.
  evaluation_interval: 15s  # Evaluate rules every 15 seconds.

# Alertmanager configuration (commented out by default).
# alerting:
#   alertmanagers:
#   - static_configs:
#     - targets:
#       - localhost:9093

# Load and evaluate rules in this file.
# rule_files:
#   - "first_rules.yml"
#   - "second_rules.yml"

# Scrape configuration for running Prometheus on the same machine.
scrape_configs:
  # The job name is added as a label `job=<job_name>` to any timeseries scraped from this config.
  - job_name: 'prometheus'
    # metrics_path defaults to '/metrics'
    # scheme defaults to 'http'.
    static_configs:
      - targets: ['localhost:9090']

  # Example job for scraping an HTTP service.
  - job_name: 'http_service'
    static_configs:
      - targets: ['<your_http_service>:80']

  # Example job for scraping an HTTPS service.
  - job_name: 'https_service'
    static_configs:
      - targets: ['<your_https_service>:443']

  # Example job for scraping a DNS server.
  - job_name: 'dns_monitoring'
    static_configs:
      - targets: ['<your_dns_server>:53']

# Add additional jobs as needed for your services.
```

Run the monitoring tool using:

```bash
prometheus --config.file=/etc/prometheus/prometheus.yml
```

The tool will be available at ```http://<your_server_ip>:9090```

### 2.4. Recommended Help Desk Solution

If you're in need of an effective help desk solution to complement your experience with Namingo, we recommend considering [FreeScout](https://freescout.net/), an AGPL-3.0 licensed, free and open-source software. FreeScout is known for its user-friendly interface and robust features, making it an excellent choice for managing customer queries and support tickets.

#### Please Note:

- FreeScout is an independent software and is not a part of Namingo. It is licensed under the AGPL-3.0, which is different from Namingo's MIT license.
- The recommendation to use FreeScout is entirely optional and for the convenience of Namingo users. Namingo functions independently of FreeScout and does not require FreeScout for its operation.
- Ensure to comply with the AGPL-3.0 license terms if you choose to use FreeScout alongside Namingo.

### 2.5. Adminer Security settings

To enhance the security of your Adminer installation, we recommend the following settings for Caddy, Apache2, and Nginx:

1. **Rename Adminer File:** Change `adminer.php` to `dbtool.php` to make it less predictable.

2. **Restrict Access by IP:** Only allow access from specific IP addresses.

Below are example configurations for each web server:

#### Caddy

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

#### Apache .htaccess

```bash
<Files "dbtool.php">
    Order Deny,Allow
    Deny from all
    Allow from your.ip.address.here
</Files>
```

#### Nginx

```bash
location /dbtool.php {
    allow your.ip.address.here;
    deny all;
}
```

## 3. In-Depth Configuration File Overview

In this section, we provide a detailed overview of each configuration file used in the Namingo domain registry platform. Understanding these files is essential for customizing and optimizing your system according to your specific needs. We will walk you through the purpose of each file, key settings, and recommended configurations to ensure smooth operation and integration with other components of your setup.

### Automation Configuration (`/opt/registry/automation/config.php`)

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

### Control Panel Configuration (`/var/www/cp/.env`)

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

### DAS Server Configuration (`/opt/registry/das/config.php`)

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

### EPP Server Configuration (`/opt/registry/epp/config.php`)

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

### RDAP Server Configuration (`/opt/registry/rdap/config.php`)

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

### WHOIS Server Configuration (`/opt/registry/whois/port43/config.php`)

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

In conclusion, this detailed configuration guide aims to streamline the setup process of the Namingo system for users of all expertise levels. The guide meticulously details each configuration file, providing clear explanations and guidance for customization to suit your specific needs. This approach ensures that you can configure Namingo with confidence, optimizing it for your registry management requirements. We are committed to making the configuration process as straightforward as possible, and we welcome any questions or requests for further assistance. Your successful deployment and efficient management of Namingo is our top priority.

After finalizing the configuration of Namingo, the next step is to consult the [Initial Operation Guide](iog.md). This guide provides comprehensive details on configuring your registry, adding registrars, and much more, to ensure a smooth start with your system.